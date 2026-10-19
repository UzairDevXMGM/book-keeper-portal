<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Financial alerts worked out from the dashboard metrics each time they are shown (nothing to schedule or store).
 * Thresholds follow the alert matrix of the client's design document.
 */
class AlertCenter
{
    public const SEVERITY_ORDER = ['high' => 0, 'warning' => 1, 'info' => 2];

    /**
     * Number of open alerts for the header bell. Cached; the key changes whenever the books change
     * (new journal entry or upload line), and otherwise expires after an hour so date-based alerts refresh.
     */
    public static function countFor(Company $company): int
    {
        $version = md5(implode('|', [
            $company->journalEntries()->max('id'),
            ImportRow::where('company_id', $company->id)->max('updated_at'),
            CarbonImmutable::today()->toDateString(),
        ]));

        $ratios = ReportAccess::hasAny($company, ReportAccess::RATIOS);

        return Cache::remember("alerts-count:{$company->id}:{$version}:".(int) $ratios, 3600, fn () => count(
            self::from(Metrics::compute($company, Period::resolve($company, ['preset' => 'ytd'])), $ratios)
        ));
    }

    /**
     * @param  bool  $ratios  also give the alerts built on financial ratios (cash runway, current ratio, days to
     *                        collect, leverage). Those belong to the Financial Ratios / KPI Dashboard add-ons.
     * @return list<array{severity: string, title: string, message: string, action: ?array{label: string, url: string}}>
     */
    public static function from(array $m, bool $ratios = false): array
    {
        $alerts = [];
        $add = function (string $severity, string $title, string $message, ?string $actionLabel = null, ?string $url = null) use (&$alerts) {
            $alerts[] = [
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'action' => $actionLabel ? ['label' => $actionLabel, 'url' => $url] : null,
            ];
        };

        if ($m['last_entry_date'] === null) {
            $add('info', 'No transactions yet', 'Upload your bank or card statements to build your books.', 'Dump your data', route('uploads.index'));

            return $alerts;
        }

        if ($m['suspense'] > 0) {
            $add('warning', 'Transactions without a category', money($m['suspense']).' of transactions are in your books as “Uncategorized”. Pick a category for them so your reports are right.', 'Check categories', route('review.index'));
        }
        if ($m['pending_review'] > 0) {
            $add('warning', 'Categories to check', "{$m['pending_review']} uploaded transaction(s) are waiting for you to check their category before they go into your books.", 'Check categories', route('review.index'));
        }
        if ($m['cash'] < 0) {
            $add('high', 'Negative cash balance', 'Your books show '.money($m['cash']).' in cash. Check for missing deposits or an unrecorded transfer.', 'View cash ledger', route('reports.show', 'ACC-22'));
        } elseif ($ratios && $m['runway'] !== null && $m['runway'] < 1) {
            $add('high', 'Less than a month of cash', 'At the current spending rate cash lasts about '.number_format($m['runway'], 1).' month(s).');
        } elseif ($ratios && $m['runway'] !== null && $m['runway'] < 3) {
            $add('warning', 'Cash runway under 3 months', 'At the current spending rate cash lasts about '.number_format($m['runway'], 1).' months.');
        }
        if ($ratios && $m['current_ratio'] !== null && $m['current_ratio'] < 1) {
            $add('high', 'Current liabilities exceed current assets', 'Current ratio is '.ratio($m['current_ratio']).'. Short-term bills are larger than short-term resources.', 'View balance sheet', route('reports.show', 'ACC-27'));
        }
        if ($m['net_income'] < 0) {
            $add('warning', 'Net loss this fiscal year', 'Expenses are ahead of revenue by '.money(abs($m['net_income'])).' so far this year.', 'View profit & loss', route('reports.show', 'ACC-26'));
        }
        if ($ratios && $m['dso'] !== null && $m['dso'] > 60) {
            $add('warning', 'Slow customer payments', 'Customers take about '.number_format($m['dso']).' days to pay (DSO).');
        }
        if ($m['bs']['equity'] < 0) {
            $add('high', 'Negative equity', 'Total liabilities are larger than total assets.', 'View balance sheet', route('reports.show', 'ACC-27'));
        } elseif ($ratios && $m['debt_to_equity'] !== null && $m['debt_to_equity'] > 3) {
            $add('warning', 'High leverage', 'Debt-to-equity is '.ratio($m['debt_to_equity']).'.');
        }
        if (CarbonImmutable::parse($m['last_entry_date'])->lt(CarbonImmutable::today()->subDays(30))) {
            $add('info', 'Books are out of date', 'The latest transaction is from '.CarbonImmutable::parse($m['last_entry_date'])->format('M j, Y').'. Upload your recent statements.', 'Dump your data', route('uploads.index'));
        }

        usort($alerts, fn ($a, $b) => self::SEVERITY_ORDER[$a['severity']] <=> self::SEVERITY_ORDER[$b['severity']]);

        return $alerts;
    }
}
