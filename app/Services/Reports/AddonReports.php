<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The add-on reports. All of them read the same ledger as the core statements. */
class AddonReports
{
    private FinancialStatements $statements;

    /**
     * @param  array{through?: ?int, q?: ?string, category?: ?int, group?: ?string}  $filters  report filters picked on screen
     */
    public function __construct(private Company $company, private array $filters = [])
    {
        $this->statements = (new FinancialStatements($company))
            ->through($filters['through'] ?? null)
            ->search($filters['q'] ?? null);
    }

    // ---------------------------------------------------------------- ACC-28 Statement of Cash Flows (indirect method)

    public function cashFlow(Period $period): ReportDocument
    {
        $before = $this->statements->totals(null, $period->start->subDay());
        $after = $this->statements->totals(null, $period->end);
        $pl = $this->statements->plSummary($this->statements->totals($period->start, $period->end));
        $change = fn (Account $a) => round(FinancialStatements::value($a->type, $after[$a->id] ?? null) - FinancialStatements::value($a->type, $before[$a->id] ?? null), 2);
        $isCash = fn (Account $a) => $a->report_line === 'Cash & Cash Equivalents';

        // Every balance sheet account except cash lands in one section, so the statement ties to the change in cash
        // by construction (debits always equal credits in the ledger).
        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        $depreciation = 0.0;
        foreach ($this->statements->accounts() as $account) {
            if (FinancialStatements::isProfitAndLoss($account) || $isCash($account)) {
                continue;
            }
            $delta = $change($account);
            if (abs($delta) < 0.005) {
                continue;
            }
            if ($account->type === 'asset' && $account->subtype === 'Contra' && $account->section === 'Fixed Assets') {
                $depreciation += -$delta;   // accumulated depreciation grows (more negative) as depreciation is booked
                continue;
            }
            [$section, $effect] = match (true) {
                $account->type === 'asset' && $account->section === 'Current Assets' => ['operating', -$delta],
                $account->type === 'asset' => ['investing', -$delta],
                FinancialStatements::isDebt($account) => ['financing', $delta],
                $account->type === 'liability' => ['operating', $delta],
                default => ['financing', $delta],   // owner contributions, draws and equity adjustments
            };
            $sections[$section][] = ['label' => "{$account->code} · {$account->name}", 'amount' => round($effect, 2)];
        }
        $depreciation = round($depreciation, 2);

        $rows = [];
        $line = fn (string $label, ?float $amount, string $style = 'line', int $indent = 0) => ReportDocument::row(['label' => $label, 'amount' => $amount], $style, $indent);

        $rows[] = $line('Operating activities', null, 'heading');
        $rows[] = $line('Net income', $pl['net_income'], 'line', 1);
        if (abs($depreciation) >= 0.005) {
            $rows[] = $line('Depreciation & amortization', $depreciation, 'line', 1);
        }
        foreach ($sections['operating'] as $item) {
            $rows[] = $line($item['label'], $item['amount'], 'line', 1);
        }
        $operating = round($pl['net_income'] + $depreciation + array_sum(array_column($sections['operating'], 'amount')), 2);
        $rows[] = $line('Net cash from operating activities', $operating, 'subtotal');

        $totals = ['operating' => $operating];
        foreach (['investing' => 'Investing activities', 'financing' => 'Financing activities'] as $key => $title) {
            $rows[] = $line($title, null, 'heading');
            foreach ($sections[$key] as $item) {
                $rows[] = $line($item['label'], $item['amount'], 'line', 1);
            }
            $totals[$key] = round(array_sum(array_column($sections[$key], 'amount')), 2);
            $rows[] = $line("Net cash from {$key} activities", $totals[$key], 'subtotal');
        }

        $netChange = round(array_sum($totals), 2);
        $cashAccounts = $this->statements->accounts()->filter($isCash);
        $cashStart = round($cashAccounts->sum(fn ($a) => FinancialStatements::value($a->type, $before[$a->id] ?? null)), 2);
        $cashEnd = round($cashAccounts->sum(fn ($a) => FinancialStatements::value($a->type, $after[$a->id] ?? null)), 2);

        $rows[] = $line('Net change in cash', $netChange, 'total');
        $rows[] = $line('Cash at the beginning of the period', $cashStart);
        $rows[] = $line('Cash at the end of the period', $cashEnd, 'grand');

        $ties = abs(round($cashStart + $netChange - $cashEnd, 2)) < 0.005;

        return (new ReportDocument('Statement of Cash Flows', $this->company->name.' · '.$period->label()))
            ->kpi('Operating cash flow', $totals['operating'], 'money', $totals['operating'] >= 0 ? 'good' : 'bad')
            ->kpi('Investing', $totals['investing'])
            ->kpi('Financing', $totals['financing'])
            ->kpi('Net change in cash', $netChange)
            ->table([ReportDocument::column('label', 'Indirect method'), ReportDocument::column('amount', $period->shortLabel(), 'money')], $rows)
            ->check($ties ? 'Cash flow ties to the change in cash on the balance sheet' : 'Does not tie to the cash balance by '.money($cashStart + $netChange - $cashEnd), $ties);
    }

    // ---------------------------------------------------------------- PRT-01 / PRT-02 / PRT-03

    public function comparativeProfitAndLoss(Period $period): ReportDocument
    {
        if (! $period->compare) {
            $period = new Period($period->start, $period->end, $period->preset,
                new Period($period->start->subYearNoOverflow(), $period->end->subYearNoOverflow()), 'prior_year');
        }
        $document = $this->statements->profitAndLoss($period);
        $document->title = 'Comparative Profit & Loss';
        $document->subtitle .= ' vs '.$period->compare->label();

        return $document;
    }

    public function commonSizeProfitAndLoss(Period $period): ReportDocument
    {
        $plain = new Period($period->start, $period->end, $period->preset);
        $document = $this->statements->profitAndLoss($plain);
        $revenue = $this->statements->plSummary($this->statements->totals($plain->start, $plain->end))['revenue'];

        return $this->withShare($document, 'Common-Size Profit & Loss', '% of revenue', $revenue);
    }

    public function commonSizeBalanceSheet(Period $period): ReportDocument
    {
        $document = $this->statements->balanceSheet(new Period($period->start, $period->end, $period->preset));
        $assets = $this->statements->bsModel($period->end)['assets'];

        return $this->withShare($document, 'Common-Size Balance Sheet', '% of total assets', $assets);
    }

    private function withShare(ReportDocument $document, string $title, string $label, float $base): ReportDocument
    {
        $document->title = $title;
        $document->tables[0]['columns'][] = ReportDocument::column('share', $label, 'percent');
        foreach ($document->tables[0]['rows'] as &$row) {
            $value = $row['cells']['cur'] ?? null;
            if (is_numeric($value) && abs($base) >= 0.005) {
                $row['cells']['share'] = round($value / $base * 100, 1);
            }
        }

        return $document->note('Each line is shown as a share of '.($label === '% of revenue' ? 'revenue' : 'total assets').' ('.money($base).').');
    }

    // ---------------------------------------------------------------- BKT-11 Monthly Trend

    public function monthlyTrend(Period $period): ReportDocument
    {
        $months = [];
        $cursor = $period->start->startOfMonth();
        while ($cursor->lte($period->end)) {
            $months[] = $cursor;
            $cursor = $cursor->addMonthNoOverflow();
        }
        $months = array_slice($months, -12);

        $totals = [];
        foreach ($months as $month) {
            $totals[$month->format('Y-m')] = $this->statements->totals($month->max($period->start), $month->endOfMonth()->min($period->end));
        }
        $summaries = array_map(fn ($t) => $this->statements->plSummary($t), $totals);

        $columns = [ReportDocument::column('account', 'Account')];
        foreach ($months as $month) {
            $columns[] = ReportDocument::column($month->format('Y-m'), $month->format('M y'), 'money');
        }
        $columns[] = ReportDocument::column('total', 'Total', 'money');

        $keys = ['income' => 'revenue', 'cost_of_sales' => 'cogs', 'expense' => 'opex', 'other_income' => 'other_income', 'other_expense' => 'other_expense'];
        $rows = [];
        foreach (FinancialStatements::PL_GROUPS as $type => $label) {
            $accounts = $this->statements->accounts()->filter(fn (Account $a) => $a->type === $type
                && collect($totals)->contains(fn ($t) => abs(FinancialStatements::value($type, $t[$a->id] ?? null)) >= 0.005));
            if ($accounts->isEmpty()) {
                continue;
            }
            $rows[] = ReportDocument::row(['account' => $label], 'heading');
            foreach ($accounts as $account) {
                $cells = ['account' => "{$account->code} · {$account->name}"];
                foreach ($totals as $key => $t) {
                    $cells[$key] = FinancialStatements::value($type, $t[$account->id] ?? null);
                }
                $cells['total'] = round(array_sum(array_slice($cells, 1)), 2);
                $rows[] = ReportDocument::row($cells, 'line', 1);
            }
            $sum = ['account' => 'Total '.strtolower($label)];
            foreach ($summaries as $key => $s) {
                $sum[$key] = $s[$keys[$type]];
            }
            $sum['total'] = round(array_sum(array_slice($sum, 1)), 2);
            $rows[] = ReportDocument::row($sum, 'subtotal');
        }
        $net = ['account' => 'Net income'];
        foreach ($summaries as $key => $s) {
            $net[$key] = $s['net_income'];
        }
        $net['total'] = round(array_sum(array_slice($net, 1)), 2);
        $rows[] = ReportDocument::row($net, 'grand');

        $best = collect($summaries)->sortByDesc('net_income')->keys()->first();

        return (new ReportDocument('Monthly Trend', $this->company->name.' · '.$period->label()))
            ->kpi('Average monthly revenue', round(collect($summaries)->avg('revenue'), 2))
            ->kpi('Average monthly net income', round(collect($summaries)->avg('net_income'), 2))
            ->kpi('Best month', $best ? CarbonImmutable::parse("{$best}-01")->format('M Y') : '—', 'text')
            ->table($columns, $rows)
            ->note(count($months) === 12 && $period->start->lt($months[0]) ? 'Showing the last 12 months of the period.' : 'One column per month of the period.');
    }

    // ---------------------------------------------------------------- BKT-09 Category Spending

    public function categorySpending(Period $period): ReportDocument
    {
        // Every cost account with spending in the period (filtered by bank / card and type when picked), biggest first
        $types = filled($this->filters['group'] ?? null) ? [$this->filters['group']] : ['cost_of_sales', 'expense', 'other_expense'];
        $totals = $this->statements->totals($period->start, $period->end);
        $costs = $this->statements->accounts()
            ->filter(fn ($a) => in_array($a->type, $types, true))
            ->map(fn ($a) => ['account' => $a, 'amount' => FinancialStatements::value($a->type, $totals[$a->id] ?? null)])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->sortByDesc('amount')->values();
        $sum = $costs->sum('amount');
        $top = $costs->map(fn ($row) => $row + ['share' => $sum > 0 ? round($row['amount'] / $sum * 100, 1) : 0.0])->all();
        $total = round(array_sum(array_column($top, 'amount')), 2);
        $months = max(1, $period->days() / 30.4375);

        $rows = array_map(fn ($r) => ReportDocument::row([
            'account' => "{$r['account']->code} · {$r['account']->name}",
            'type' => FinancialStatements::PL_GROUPS[$r['account']->type],
            'amount' => $r['amount'],
            'monthly' => round($r['amount'] / $months, 2),
            'share' => $r['share'],
        ]), $top);
        $rows[] = ReportDocument::row(['account' => 'Total spending', 'amount' => $total, 'monthly' => round($total / $months, 2), 'share' => $total ? 100.0 : null], 'grand');

        return (new ReportDocument('Category Spending', $this->company->name.' · '.$period->label()))
            ->kpi('Total spending', $total)
            ->kpi('Average per month', round($total / $months, 2))
            ->kpi('Largest category', $top[0]['account']->name ?? '—', 'text', null, isset($top[0]) ? pct($top[0]['share']).' of spending' : null)
            ->table([
                ReportDocument::column('account', 'Category'),
                ReportDocument::column('type', 'Type'),
                ReportDocument::column('amount', 'Spent', 'money'),
                ReportDocument::column('monthly', 'Per month', 'money'),
                ReportDocument::column('share', 'Share', 'percent'),
            ], $rows, null, 'No spending in this period.');
    }

    // ---------------------------------------------------------------- BKT-10 Vendor Spending

    public function vendorSpending(Period $period): ReportDocument
    {
        $costAccounts = $this->statements->accounts()->filter(fn ($a) => in_array($a->type, ['cost_of_sales', 'expense', 'other_expense'], true))->keys();
        if ($category = $this->filters['category'] ?? null) {
            $costAccounts = $costAccounts->intersect([$category])->values();
        }
        $through = $this->filters['through'] ?? null;
        $search = filled($this->filters['q'] ?? null) ? mb_strtolower(trim($this->filters['q'])) : null;

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('import_rows as r', 'r.journal_entry_id', '=', 'e.id')
            ->leftJoin('categorization_rules as rule', 'rule.id', '=', 'r.rule_id')
            ->where('l.company_id', $this->company->id)
            ->whereIn('l.account_id', $costAccounts)
            ->whereBetween('l.entry_date', [$period->start->toDateString(), $period->end->toDateString()])
            // Only costs that were paid or billed: the entry also touches cash, a credit card or accounts payable.
            // That leaves out non-cash entries such as depreciation.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as paid')
                ->join('accounts as pa', 'pa.id', '=', 'paid.account_id')
                ->whereColumn('paid.journal_entry_id', 'e.id')
                ->where(fn ($w) => $w->where('pa.report_line', 'Cash & Cash Equivalents')
                    ->orWhere('pa.is_credit_card', true)
                    ->orWhere('pa.system_key', 'accounts_payable')))
            ->when($through, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('journal_lines as via')
                ->whereColumn('via.journal_entry_id', 'e.id')->where('via.account_id', $through)))
            ->get(['l.debit', 'l.credit', 'l.entry_date', 'e.description', 'rule.name as vendor', 'rule.source']);

        $vendors = [];
        foreach ($lines as $line) {
            $name = ($line->source === 'vendor' && $line->vendor) ? $line->vendor : self::payee((string) $line->description);
            if ($search !== null && ! str_contains(mb_strtolower($name.' '.$line->description), $search)) {
                continue;
            }
            $vendors[$name] ??= ['amount' => 0.0, 'count' => 0, 'last' => $line->entry_date];
            $vendors[$name]['amount'] += (float) $line->debit - (float) $line->credit;
            $vendors[$name]['count']++;
            $vendors[$name]['last'] = max($vendors[$name]['last'], $line->entry_date);
        }
        uasort($vendors, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $total = round(array_sum(array_column($vendors, 'amount')), 2);

        $rows = [];
        foreach (array_slice($vendors, 0, 50, true) as $name => $v) {
            $rows[] = ReportDocument::row([
                'vendor' => $name,
                'count' => $v['count'],
                'last' => CarbonImmutable::parse($v['last'])->format('M j, Y'),
                'amount' => round($v['amount'], 2),
                'share' => $total ? round($v['amount'] / $total * 100, 1) : null,
            ]);
        }
        $rows[] = ReportDocument::row(['vendor' => 'Total', 'count' => array_sum(array_column($vendors, 'count')), 'amount' => $total], 'grand');

        return (new ReportDocument('Vendor Spending', $this->company->name.' · '.$period->label()))
            ->kpi('Vendors paid', count($vendors), 'number')
            ->kpi('Total spending', $total)
            ->kpi('Largest vendor', array_key_first($vendors) ?? '—', 'text')
            ->table([
                ReportDocument::column('vendor', 'Vendor'),
                ReportDocument::column('count', 'Payments', 'number'),
                ReportDocument::column('last', 'Last paid'),
                ReportDocument::column('amount', 'Spent', 'money'),
                ReportDocument::column('share', 'Share', 'percent'),
            ], $rows, null, 'No spending in this period.')
            ->note('Vendors come from the matched vendor rule on uploaded lines, or from the transaction description. Top 50 shown.')
            ->note('Only costs paid in cash, by card or billed to accounts payable are counted; non-cash entries such as depreciation are left out.');
    }

    /**
     * "Lone Star Wholesale Supply - inventory" -> "Lone Star Wholesale Supply"; "Bill - Gulf Coast Packaging" ->
     * "Gulf Coast Packaging"; "SQ *BLUE BOTTLE 0423" -> "Sq Blue Bottle".
     */
    public static function payee(string $description): string
    {
        $parts = array_map('trim', explode(' - ', $description, 2));
        $name = isset($parts[1]) && preg_match('/^(bill|payment|pmt|rent|purchase|order|invoice)$/i', $parts[0]) ? $parts[1] : $parts[0];
        if (strtoupper($name) === $name) {
            $words = array_slice(array_filter(explode(' ', preg_replace('/[^A-Za-z&\' ]+/', ' ', $name)), fn ($w) => strlen($w) > 1), 0, 3);
            $name = ucwords(strtolower(implode(' ', $words)));
        }

        return $name !== '' ? mb_substr($name, 0, 60) : 'Unknown';
    }

    // ---------------------------------------------------------------- BKT-07 Account Activity

    public function accountActivity(Period $period, ?int $accountId): ReportDocument
    {
        $document = $this->statements->generalLedger($period, $accountId ?? self::defaultAccountId($this->company, $period));
        $document->title = str_replace('General Ledger', 'Account Activity', $document->title);

        return $document->note('Pick another account with the Account filter above.');
    }

    /** Account Activity opens on the largest cost account of the period, so it is never empty. */
    public static function defaultAccountId(Company $company, Period $period): ?int
    {
        return Metrics::topExpenses($company, $period, 1)[0]['account']->id
            ?? $company->accounts()->where('system_key', 'default_bank')->value('id');
    }

    // ---------------------------------------------------------------- ACC-30 Financial Ratios

    public function financialRatios(Period $period): ReportDocument
    {
        $now = $this->ratios(Metrics::compute($this->company, $period));
        $previousPeriod = new Period($period->start->subYearNoOverflow(), $period->end->subYearNoOverflow());
        $before = $this->ratios(Metrics::compute($this->company, $previousPeriod));

        $rows = [];
        foreach ($now as $group => $items) {
            $rows[] = ReportDocument::row(['ratio' => $group], 'heading');
            foreach ($items as $key => [$label, $value, $format, $guide]) {
                $rows[] = ReportDocument::row([
                    'ratio' => $label,
                    'now' => self::show($value, $format),
                    'before' => self::show($before[$group][$key][1], $format),
                    'guide' => $guide,
                ], 'line', 1);
            }
        }

        return (new ReportDocument('Financial Ratios', $this->company->name.' · '.$period->label()))
            ->kpi('Current ratio', ratio($now['Liquidity']['current'][1]), 'text')
            ->kpi('Net margin', $now['Profitability']['net'][1], 'pct')
            ->kpi('Debt to equity', ratio($now['Leverage']['de'][1]), 'text')
            ->kpi('Days to collect', $now['Efficiency']['dso'][1] === null ? '—' : number_format($now['Efficiency']['dso'][1]).' days', 'text')
            ->table([
                ReportDocument::column('ratio', 'Ratio'),
                ReportDocument::column('now', $period->shortLabel(), 'plain'),
                ReportDocument::column('before', 'Same period last year', 'plain'),
                ReportDocument::column('guide', 'What to look for'),
            ], $rows);
    }

    private function ratios(array $m): array
    {
        $pl = $m['pl'];
        $bs = $m['bs'];
        $ebit = $pl['ebitda'] - $pl['da'];

        return [
            'Liquidity' => [
                'current' => ['Current ratio', $m['current_ratio'], 'ratio', 'Above 1.5x is comfortable; below 1.0x is tight'],
                'quick' => ['Quick ratio', $bs['current_liabilities'] > 0 ? round(($bs['cash'] + $bs['ar']) / $bs['current_liabilities'], 2) : null, 'ratio', 'Cash and receivables against short-term bills; 1.0x or more'],
                'wc' => ['Working capital', $m['working_capital'], 'money', 'Positive means short-term bills are covered'],
            ],
            'Profitability' => [
                'gross' => ['Gross margin', $m['gross_margin'], 'pct', 'Higher is better; compare with your industry'],
                'net' => ['Net margin', $m['net_margin'], 'pct', 'Share of revenue kept as profit'],
                'ebitda' => ['EBITDA margin', FinancialStatements::percent($pl['ebitda'], $pl['revenue']), 'pct', 'Operating profit before interest, tax and depreciation'],
                'roa' => ['Return on assets', FinancialStatements::percent($pl['net_income'], $bs['assets']), 'pct', 'Profit per dollar of assets'],
                'roe' => ['Return on equity', $bs['equity'] > 0 ? FinancialStatements::percent($pl['net_income'], $bs['equity']) : null, 'pct', 'Profit per dollar the owners have in the business'],
            ],
            'Leverage' => [
                'de' => ['Debt to equity', $m['debt_to_equity'], 'ratio', 'Under 2.0x is common for small businesses'],
                'da' => ['Debt to assets', $bs['assets'] > 0 ? round($bs['liabilities'] / $bs['assets'], 2) : null, 'ratio', 'Share of assets financed by creditors'],
                'coverage' => ['Interest coverage', $pl['interest'] > 0 ? round($ebit / $pl['interest'], 2) : null, 'ratio', 'Operating profit over interest; above 3x is healthy'],
                'dscr' => ['Debt service coverage', $m['dscr'], 'ratio', 'Lenders usually want 1.25x or more'],
            ],
            'Efficiency' => [
                'dso' => ['Days sales outstanding', $m['dso'], 'days', 'How long customers take to pay'],
                'dpo' => ['Days payables outstanding', $m['dpo'], 'days', 'How long you take to pay suppliers'],
                'opex' => ['Operating expenses / revenue', FinancialStatements::percent($pl['opex'], $pl['revenue']), 'pct', 'Overhead as a share of sales'],
            ],
        ];
    }

    private static function show(float|int|null $value, string $format): string
    {
        return match (true) {
            $value === null => '—',
            $format === 'ratio' => ratio($value),
            $format === 'pct' => pct($value),
            $format === 'days' => number_format($value).' days',
            default => money($value),
        };
    }

    // ---------------------------------------------------------------- ACC-31 KPI Dashboard

    public function kpiDashboard(Period $period): ReportDocument
    {
        $m = Metrics::compute($this->company, $period);
        $trend = Metrics::trend($this->company);
        $cash = Metrics::cashTrend($this->company);

        $rows = [];
        foreach ($trend['labels'] as $i => $label) {
            $rows[] = ReportDocument::row([
                'month' => $label,
                'revenue' => $trend['revenue'][$i],
                'costs' => $trend['costs'][$i],
                'net' => $trend['net'][$i],
                'margin' => FinancialStatements::percent($trend['net'][$i], $trend['revenue'][$i]),
                'cash' => $cash[$i] ?? null,
            ]);
        }

        return (new ReportDocument('KPI Dashboard', $this->company->name.' · '.$period->label()))
            ->kpi('Revenue', $m['revenue'])
            ->kpi('Net income', $m['net_income'], 'money', $m['net_income'] >= 0 ? 'good' : 'bad')
            ->kpi('Gross margin', $m['gross_margin'], 'pct')
            ->kpi('Cash', $m['cash'])
            ->kpi('Working capital', $m['working_capital'])
            ->kpi('Current ratio', ratio($m['current_ratio']), 'text')
            ->kpi('Receivables', $m['ar'], 'money', null, $m['dso'] !== null ? number_format($m['dso']).' days to collect' : null)
            ->kpi('Debt', $m['debt'])
            ->table([
                ReportDocument::column('month', 'Month'),
                ReportDocument::column('revenue', 'Revenue', 'money'),
                ReportDocument::column('costs', 'Costs', 'money'),
                ReportDocument::column('net', 'Net income', 'money'),
                ReportDocument::column('margin', 'Net margin', 'percent'),
                ReportDocument::column('cash', 'Cash at month end', 'money'),
            ], $rows, 'Last 12 months');
    }

    // ---------------------------------------------------------------- PRT-07 Financial Health Score

    public function healthScore(Period $period): ReportDocument
    {
        $m = Metrics::compute($this->company, $period);
        $health = Metrics::healthScore($m);

        $explain = [
            'Liquidity (current ratio)' => ['Current ratio '.ratio($m['current_ratio']), 'Keep current assets at 1.5x+ of bills due within a year.'],
            'Profitability (net margin)' => ['Net margin '.pct($m['net_margin']), 'Full points at a 15% net margin.'],
            'Leverage (debt / equity)' => ['Debt to equity '.ratio($m['debt_to_equity']), 'Full points at 0.5x or less.'],
            'Collections (DSO)' => [$m['dso'] === null ? 'No receivables' : number_format($m['dso']).' days to collect', 'Full points at 30 days or faster.'],
            'Cash runway' => [$m['runway'] === null ? 'Cash positive' : number_format($m['runway'], 1).' months', 'Full points at 3+ months, or when income covers costs.'],
            'Data quality' => [$m['pending_review'].' to check, '.money($m['suspense']).' without a category', 'Give every transaction a category for full points.'],
        ];

        $rows = array_map(fn ($c) => ReportDocument::row([
            'component' => $c['name'],
            'measure' => $explain[$c['name']][0] ?? '',
            'points' => $c['points'],
            'weight' => $c['weight'],
            'how' => $explain[$c['name']][1] ?? '',
        ]), $health['components']);
        $rows[] = ReportDocument::row(['component' => 'Total score', 'points' => $health['score'], 'weight' => 100], 'grand');

        $weakest = collect($health['components'])->sortBy(fn ($c) => $c['weight'] ? $c['points'] / $c['weight'] : 1)->first();
        $document = (new ReportDocument('Financial Health Score', $this->company->name.' · '.$period->label()))
            ->kpi('Score', $health['score'] === null ? '—' : $health['score'].' / 100', 'text', $health['score'] >= 65 ? 'good' : ($health['score'] === null ? null : 'bad'))
            ->kpi('Grade', $health['grade'], 'text')
            ->kpi('Biggest opportunity', $weakest['name'] ?? '—', 'text')
            ->table([
                ReportDocument::column('component', 'Check'),
                ReportDocument::column('measure', 'Your number'),
                ReportDocument::column('points', 'Points', 'number'),
                ReportDocument::column('weight', 'Out of', 'number'),
                ReportDocument::column('how', 'How to score more'),
            ], $rows);

        return $document->note('80+ Strong · 65–79 Healthy · 50–64 Watch · below 50 At risk.');
    }
}
