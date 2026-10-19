<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Report;
use App\Services\Reports\AddonReports;
use App\Support\Audit;
use App\Services\Reports\Period;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportRunner;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Rows of a report table shown per page on screen. */
    public const ROWS_PER_PAGE = 100;

    /** Report library: plan reports first, then add-ons. */
    public function index(Request $request): View
    {
        $company = $this->company($request);

        return view('reports.index', [
            'company' => $company,
            'plan' => ReportAccess::planFor($company),
            'reports' => ReportAccess::for($company)->groupBy(fn ($item) => $item['report']->category ?: 'Other reports'),
        ]);
    }

    public function show(Request $request, Report $report): View|StreamedResponse
    {
        $company = $this->company($request);
        $access = ReportAccess::for($company)->first(fn ($item) => $item['report']->is($report));
        abort_if($access === null, 404);

        if (! $access['has_access']) {
            return view('reports.locked', ['report' => $report, 'company' => $company]);
        }
        if (! $access['ready']) {
            return view('reports.coming-soon', ['report' => $report, 'company' => $company]);
        }

        $query = $request->only(['preset', 'start', 'end', 'compare']);
        // Comparing P&L periods is what the Comparative Profit & Loss add-on sells, so the basic P&L only compares
        // once that add-on is on the account
        $compareAddon = $report->code === 'ACC-26' && ! ReportAccess::hasAny($company, ['PRT-01'])
            ? Report::where('code', 'PRT-01')->where('is_active', true)->first() : null;
        if (! in_array($report->code, ReportRunner::COMPARABLE, true) || ($report->code === 'ACC-26' && $compareAddon)) {
            unset($query['compare']);
        }
        // The comparative P&L always compares something; it opens against the same period last year
        if ($report->code === 'PRT-01' && ($query['compare'] ?? 'none') === 'none') {
            $query['compare'] = 'prior_year';
        }
        $period = Period::resolve($company, $query);

        $accountFilter = in_array($report->code, ReportRunner::ACCOUNT_FILTER, true);
        $accountId = $accountFilter ? ($request->integer('account') ?: null) : null;
        if ($accountId && ! $company->accounts()->whereKey($accountId)->exists()) {
            $accountId = null;
        }
        if ($report->code === 'BKT-07' && ! $accountId) {
            $accountId = AddonReports::defaultAccountId($company, $period);
        }

        // Extra filters this report offers; anything that is not this company's is ignored
        $offered = ReportRunner::FILTERS[$report->code] ?? [];
        $statementAccounts = in_array('through', $offered, true)
            ? $company->accounts()->where(fn ($q) => $q->where('is_bank_account', true)->orWhere('is_credit_card', true))->orderBy('code')->get(['id', 'code', 'name'])
            : collect();
        $costAccounts = in_array('category', $offered, true)
            ? $company->accounts()->whereIn('type', array_keys(ReportRunner::GROUPS))->orderBy('code')->get(['id', 'code', 'name'])
            : collect();
        $filters = array_filter([
            'through' => $statementAccounts->firstWhere('id', $request->integer('through'))?->id,
            'category' => $costAccounts->firstWhere('id', $request->integer('category'))?->id,
            'group' => in_array('group', $offered, true) && array_key_exists((string) $request->query('group'), ReportRunner::GROUPS) ? $request->query('group') : null,
            'q' => in_array('q', $offered, true) ? Str::limit(trim((string) $request->query('q')), 100, '') : null,
        ], fn ($v) => $v !== null && $v !== '');

        $document = ReportRunner::run($company, $report, $period, ['account_id' => $accountId] + $filters);

        // The "Get started" checklist ticks "See your reports" after the first report a person opens
        if (! AuditLog::where('user_id', $request->user()->id)->where('action', 'report.first_view')->exists()) {
            Audit::log('report.first_view', $report, ['code' => $report->code], $company);
        }

        // Say which filters are on, on screen and in the printed / exported report
        $active = array_filter([
            'through' => isset($filters['through']) ? 'Bank / card: '.$statementAccounts->firstWhere('id', $filters['through'])->name : null,
            'category' => isset($filters['category']) ? 'Category: '.$costAccounts->firstWhere('id', $filters['category'])->name : null,
            'group' => isset($filters['group']) ? 'Type: '.ReportRunner::GROUPS[$filters['group']] : null,
            'q' => isset($filters['q']) ? 'Search: “'.$filters['q'].'”' : null,
        ]);
        if ($active) {
            $document->subtitle .= ' · '.implode(' · ', $active);
        }

        if ($request->query('export') === 'csv') {
            $filename = Str::slug("{$report->name} {$company->name} {$period->end->toDateString()}").'.csv';

            return response()->streamDownload(function () use ($document) {
                $out = fopen('php://output', 'w');
                foreach ($document->toCsvRows() as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // On screen long reports (the General Ledger) are split into pages; the print view keeps every line
        $printing = $request->boolean('print');
        $paginator = $printing ? null : $this->paginateFirstTable($document, $request);

        return view('reports.show', [
            'company' => $company,
            'report' => $report,
            'document' => $document,
            'period' => $period,
            'paginator' => $paginator,
            'printing' => $printing,
            'comparable' => in_array($report->code, ReportRunner::COMPARABLE, true) && ! $compareAddon,
            'compareAddon' => $compareAddon,
            'asOf' => in_array($report->code, ReportRunner::AS_OF, true),
            'accounts' => $accountFilter ? $company->accounts()->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'accountId' => $accountId,
            'allAccounts' => $report->code !== 'BKT-07',
            'offered' => $offered,
            'filters' => $filters,
            'activeFilters' => $active,
            'statementAccounts' => $statementAccounts,
            'costAccounts' => $costAccounts,
            'groups' => ReportRunner::GROUPS,
        ]);
    }

    /**
     * Shows one page of the report's main table. The document is built in full first, so running balances stay
     * right; a page that starts inside an account gets that account's heading again, marked "(continued)".
     */
    private function paginateFirstTable(ReportDocument $document, Request $request): ?LengthAwarePaginator
    {
        $rows = $document->tables[0]['rows'] ?? [];
        if (count($rows) <= self::ROWS_PER_PAGE) {
            return null;
        }

        $page = min(max(1, $request->integer('page', 1)), (int) ceil(count($rows) / self::ROWS_PER_PAGE));
        $offset = ($page - 1) * self::ROWS_PER_PAGE;
        $slice = array_slice($rows, $offset, self::ROWS_PER_PAGE);

        $paginator = new LengthAwarePaginator($slice, count($rows), self::ROWS_PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->except('page'),
        ]);

        if ($offset > 0 && $slice[0]['style'] !== 'heading') {
            for ($i = $offset - 1; $i >= 0; $i--) {
                if ($rows[$i]['style'] === 'heading') {
                    $firstKey = $document->tables[0]['columns'][0]['key'];
                    $continued = $rows[$i];
                    $continued['cells'][$firstKey] = ($continued['cells'][$firstKey] ?? '').' (continued)';
                    array_unshift($slice, $continued);
                    break;
                }
            }
        }
        $document->tables[0]['rows'] = $slice;

        return $paginator;
    }
}
