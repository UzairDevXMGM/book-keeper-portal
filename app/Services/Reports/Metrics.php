<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Owner-dashboard numbers: KPIs, the financial health score and the 12-month trend. */
class Metrics
{
    public static function compute(Company $company, Period $period): array
    {
        $statements = new FinancialStatements($company);
        $periodTotals = $statements->totals($period->start, $period->end);
        $pl = $statements->plSummary($periodTotals);
        $bs = $statements->bsModel($period->end);

        $days = $period->days();
        $months = max(1, $days / 30.4375);

        // Money paid on loans in the period: principal (debits to debt accounts) + interest
        $principalPaid = $statements->accounts()
            ->filter(fn ($a) => FinancialStatements::isDebt($a))
            ->sum(fn ($a) => $periodTotals[$a->id]['debit'] ?? 0);
        $debtService = round($principalPaid + $pl['interest'], 2);

        // Net burn: cash costs minus income. A profitable business is not burning cash, so it has no runway limit.
        $monthlyBurn = round(($pl['cogs'] + $pl['opex'] + $pl['other_expense'] - $pl['da'] - $pl['revenue'] - $pl['other_income']) / $months, 2);
        $purchases = $pl['cogs'] + $pl['opex'] - $pl['da'];

        $suspense = $statements->accounts()
            ->filter(fn ($a) => in_array($a->system_key, ['suspense_expense', 'suspense_income'], true))
            ->sum(fn ($a) => abs(FinancialStatements::value($a->type, $periodTotals[$a->id] ?? null)));

        return [
            'period' => $period,
            'pl' => $pl,
            'bs' => $bs,
            'revenue' => $pl['revenue'],
            'net_income' => $pl['net_income'],
            'ebitda' => $pl['ebitda'],
            'gross_margin' => FinancialStatements::percent($pl['gross_profit'], $pl['revenue']),
            'net_margin' => FinancialStatements::percent($pl['net_income'], $pl['revenue']),
            'cash' => $bs['cash'],
            'working_capital' => round($bs['current_assets'] - $bs['current_liabilities'], 2),
            'current_ratio' => $bs['current_liabilities'] > 0 ? round($bs['current_assets'] / $bs['current_liabilities'], 2) : null,
            'ar' => $bs['ar'],
            'ap' => $bs['ap'],
            'debt' => $bs['debt'],
            'dso' => $pl['revenue'] > 0 ? round($bs['ar'] / $pl['revenue'] * $days, 1) : null,
            'dpo' => $purchases > 0 ? round($bs['ap'] / $purchases * $days, 1) : null,
            'monthly_burn' => $monthlyBurn,
            'runway' => $monthlyBurn > 0 ? round(max(0, $bs['cash']) / $monthlyBurn, 1) : null,
            'dscr' => $debtService > 0 ? round($pl['ebitda'] / $debtService, 2) : null,
            'debt_to_equity' => $bs['equity'] > 0 ? round($bs['liabilities'] / $bs['equity'], 2) : null,
            'suspense' => round($suspense, 2),
            'pending_review' => ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count(),
            'last_entry_date' => $company->journalLines()->max('entry_date'),
        ];
    }

    /** 0-100 score from liquidity, profitability, leverage, collections, runway and data quality. */
    public static function healthScore(array $m): array
    {
        $clamp = fn (float $x) => max(0.0, min(1.0, $x));
        $hasActivity = $m['last_entry_date'] !== null;

        $parts = [
            ['Liquidity (current ratio)', 20, $m['current_ratio'] === null ? ($hasActivity ? 1.0 : 0.0) : $clamp(($m['current_ratio'] - 0.5) / 1.5)],
            ['Profitability (net margin)', 20, $m['net_margin'] === null ? 0.0 : $clamp(($m['net_margin'] + 5) / 20)],
            ['Leverage (debt / equity)', 20, $m['debt_to_equity'] === null ? ($m['bs']['equity'] < 0 ? 0.0 : 0.2) : $clamp((3 - $m['debt_to_equity']) / 2.5)],
            ['Collections (DSO)', 15, $m['dso'] === null ? 0.7 : $clamp((75 - $m['dso']) / 45)],
            ['Cash runway', 15, $m['runway'] === null ? ($hasActivity ? 1.0 : 0.0) : $clamp($m['runway'] / 3)],
            ['Data quality', 10, $hasActivity ? $clamp(1 - ($m['pending_review'] * 0.05 + ($m['suspense'] != 0 ? 0.2 : 0))) : 0.0],
        ];

        // Books with nothing in them earn no points anywhere (an empty ledger is not "low leverage")
        $components = array_map(fn ($p) => ['name' => $p[0], 'weight' => $p[1], 'points' => $hasActivity ? round($p[1] * $p[2], 1) : 0.0], $parts);
        $score = (int) round(array_sum(array_column($components, 'points')));

        return [
            'score' => $hasActivity ? $score : null,
            'grade' => ! $hasActivity ? 'No data yet' : ($score >= 80 ? 'Strong' : ($score >= 65 ? 'Healthy' : ($score >= 50 ? 'Watch' : 'At risk'))),
            'components' => $components,
        ];
    }

    /** Revenue, total costs and net income for each of the last 12 months (ending with the current month). */
    public static function trend(Company $company, int $months = 12): array
    {
        $first = CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $month = self::monthExpression();

        $rows = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $company->id)
            ->where('l.entry_date', '>=', $first->toDateString())
            ->whereIn('a.type', array_keys(FinancialStatements::PL_GROUPS))
            ->groupByRaw("{$month}, a.type")
            ->selectRaw("{$month} AS ym, a.type, SUM(l.debit) AS debit, SUM(l.credit) AS credit")
            ->get();

        $labels = [];
        $revenue = [];
        $costs = [];
        foreach (range(0, $months - 1) as $i) {
            $m = $first->addMonthsNoOverflow($i);
            $labels[$m->format('Y-m')] = $m->format('M y');
            $revenue[$m->format('Y-m')] = 0.0;
            $costs[$m->format('Y-m')] = 0.0;
        }
        foreach ($rows as $r) {
            if (! isset($labels[$r->ym])) {
                continue;
            }
            $value = FinancialStatements::value($r->type, ['debit' => (float) $r->debit, 'credit' => (float) $r->credit]);
            match ($r->type) {
                'income', 'other_income' => $revenue[$r->ym] += $value,
                default => $costs[$r->ym] += $value,
            };
        }

        return [
            'labels' => array_values($labels),
            'revenue' => array_map(fn ($v) => round($v, 2), array_values($revenue)),
            'costs' => array_map(fn ($v) => round($v, 2), array_values($costs)),
            'net' => array_map(fn ($r, $c) => round($r - $c, 2), array_values($revenue), array_values($costs)),
        ];
    }

    /** Cash balance at the end of each of the last $months months (for the cash sparkline). */
    public static function cashTrend(Company $company, int $months = 12): array
    {
        $first = CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $cashAccounts = $company->accounts()->where('report_line', 'Cash & Cash Equivalents')->pluck('id');

        $lines = DB::table('journal_lines as l')->where('l.company_id', $company->id)->whereIn('l.account_id', $cashAccounts);
        $balance = (float) (clone $lines)->where('l.entry_date', '<', $first->toDateString())->sum(DB::raw('l.debit - l.credit'));
        $monthly = (clone $lines)->where('l.entry_date', '>=', $first->toDateString())
            ->groupByRaw(self::monthExpression())
            ->selectRaw(self::monthExpression().' AS ym, SUM(l.debit - l.credit) AS net')
            ->pluck('net', 'ym');

        $series = [];
        foreach (range(0, $months - 1) as $i) {
            $balance += (float) ($monthly[$first->addMonthsNoOverflow($i)->format('Y-m')] ?? 0);
            $series[] = round($balance, 2);
        }

        return $series;
    }

    /** Largest cost accounts in the period, with their share of all costs. */
    public static function topExpenses(Company $company, Period $period, int $limit = 6): array
    {
        $statements = new FinancialStatements($company);
        $totals = $statements->totals($period->start, $period->end);

        $costs = $statements->accounts()
            ->filter(fn ($a) => in_array($a->type, ['cost_of_sales', 'expense', 'other_expense'], true))
            ->map(fn ($a) => ['account' => $a, 'amount' => FinancialStatements::value($a->type, $totals[$a->id] ?? null)])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        $total = $costs->sum('amount');

        return $costs->take($limit)->map(fn ($row) => $row + ['share' => $total > 0 ? round($row['amount'] / $total * 100, 1) : 0.0])->all();
    }

    /** "YYYY-MM" of journal_lines.entry_date, on MySQL/MariaDB and on SQLite (tests). */
    private static function monthExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', l.entry_date)" : "DATE_FORMAT(l.entry_date, '%Y-%m')";
    }
}
