<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit & Loss, Balance Sheet, Trial Balance and General Ledger, all read straight from journal_lines.
 *
 * Income and expense accounts are never closed out: the Balance Sheet adds every earlier fiscal year's net income to
 * retained earnings and shows this fiscal year's net income on its own line, so the books balance on any date.
 */
class FinancialStatements
{
    /** P&L account types in statement order. */
    public const PL_GROUPS = [
        'income' => 'Revenue',
        'cost_of_sales' => 'Cost of sales',
        'expense' => 'Operating expenses',
        'other_income' => 'Other income',
        'other_expense' => 'Other expenses',
    ];

    private const PL_KEYS = [
        'income' => 'revenue',
        'cost_of_sales' => 'cogs',
        'expense' => 'opex',
        'other_income' => 'other_income',
        'other_expense' => 'other_expense',
    ];

    /** Types that grow on the credit side. */
    private const CREDIT_SIDE = ['income', 'other_income', 'liability', 'equity'];

    private const ASSET_SECTIONS = ['Current Assets', 'Fixed Assets', 'Other Assets'];
    private const LIABILITY_SECTIONS = ['Current Liabilities', 'Long-Term Liabilities'];

    /** @var Collection<int, Account> keyed by id, ordered by code */
    private Collection $accounts;

    /** Report filter: only entries that went through this bank / card account (null = all). */
    private ?int $through = null;
    /** Report filter: General Ledger lines whose description contains this text (null = all). */
    private ?string $search = null;

    public function __construct(private Company $company)
    {
        $this->accounts = $company->accounts()->orderBy('code')->get()->keyBy('id');
    }

    /** Limits every total and ledger line to entries that touch this bank or card account. */
    public function through(?int $accountId): static
    {
        $this->through = $accountId ?: null;

        return $this;
    }

    public function search(?string $text): static
    {
        $this->search = filled($text) ? trim($text) : null;

        return $this;
    }

    /** whereExists clause for the "through" filter on a journal_lines query aliased $alias. */
    private function throughScope(string $alias): \Closure
    {
        return fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('journal_lines as via')
            ->whereColumn('via.journal_entry_id', "{$alias}.journal_entry_id")
            ->where('via.account_id', $this->through));
    }

    public function accounts(): Collection
    {
        return $this->accounts;
    }

    // ---------------------------------------------------------------- ledger sums

    /**
     * Debit and credit totals per account id between two dates (inclusive). A null start means "since the beginning".
     *
     * @return array<int, array{debit: float, credit: float}>
     */
    public function totals(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('journal_lines')
            ->where('company_id', $this->company->id)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from->toDateString()))
            ->where('entry_date', '<=', $to->toDateString())
            ->when($this->through, $this->throughScope('journal_lines'))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) AS debit, SUM(credit) AS credit')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->account_id => ['debit' => (float) $r->debit, 'credit' => (float) $r->credit]])
            ->all();
    }

    /** Statement amount: positive when the account grows. Contra accounts come out negative (they reduce their group). */
    public static function value(string $type, ?array $totals): float
    {
        if (! $totals) {
            return 0.0;
        }

        return round(in_array($type, self::CREDIT_SIDE, true)
            ? $totals['credit'] - $totals['debit']
            : $totals['debit'] - $totals['credit'], 2);
    }

    /** Balance on the account's own normal side (a contra account reads positive), used for running balances. */
    public static function natural(Account $account, ?array $totals): float
    {
        if (! $totals) {
            return 0.0;
        }

        return round($account->normal_balance === 'credit'
            ? $totals['credit'] - $totals['debit']
            : $totals['debit'] - $totals['credit'], 2);
    }

    public static function isProfitAndLoss(Account $account): bool
    {
        return array_key_exists($account->type, self::PL_GROUPS);
    }

    /** Borrowed money: long-term liabilities, plus short-term notes and lines of credit. */
    public static function isDebt(Account $account): bool
    {
        return $account->type === 'liability'
            && ($account->section === 'Long-Term Liabilities' || in_array($account->report_line, ['Short-term Debt', 'Line of Credit'], true));
    }

    /** Profit & Loss totals for a set of account sums. */
    public function plSummary(array $totals): array
    {
        $s = array_fill_keys(['revenue', 'cogs', 'opex', 'other_income', 'other_expense', 'da', 'interest'], 0.0);

        foreach ($this->accounts as $id => $account) {
            if (! isset($totals[$id]) || ! self::isProfitAndLoss($account)) {
                continue;
            }
            $value = self::value($account->type, $totals[$id]);
            $s[self::PL_KEYS[$account->type]] += $value;
            if ($account->section === 'Depreciation & Amortization') {
                $s['da'] += $value;
            }
            if ($account->report_line === 'Interest Expense') {
                $s['interest'] += $value;
            }
        }

        $s['gross_profit'] = $s['revenue'] - $s['cogs'];
        $s['operating_income'] = $s['gross_profit'] - $s['opex'];
        $s['net_income'] = $s['operating_income'] + $s['other_income'] - $s['other_expense'];
        $s['ebitda'] = $s['net_income'] + $s['interest'] + $s['da'];

        return array_map(fn ($v) => round($v, 2), $s);
    }

    /** Balance sheet figures on a date. */
    public function bsModel(CarbonImmutable $asOf): array
    {
        $fyStart = Period::fiscalYearStart($asOf, $this->company->fiscal_year_start_month ?: 1);
        $cumulative = $this->totals(null, $asOf);

        $values = [];
        $sum = array_fill_keys(['assets', 'liabilities', 'equity_accounts', 'cash', 'current_assets', 'current_liabilities',
            'ar', 'ap', 'inventory', 'debt'], 0.0);

        foreach ($this->accounts as $id => $account) {
            if (self::isProfitAndLoss($account) || ! isset($cumulative[$id])) {
                continue;
            }
            $value = $values[$id] = self::value($account->type, $cumulative[$id]);

            match ($account->type) {
                'asset' => $sum['assets'] += $value,
                'liability' => $sum['liabilities'] += $value,
                'equity' => $sum['equity_accounts'] += $value,
            };
            if ($account->section === 'Current Assets') {
                $sum['current_assets'] += $value;
            }
            if ($account->section === 'Current Liabilities') {
                $sum['current_liabilities'] += $value;
            }
            if ($account->report_line === 'Cash & Cash Equivalents') {
                $sum['cash'] += $value;
            }
            if ($account->report_line === 'Accounts Receivable') {
                $sum['ar'] += $value;
            }
            if ($account->report_line === 'Accounts Payable') {
                $sum['ap'] += $value;
            }
            if ($account->report_line === 'Inventory') {
                $sum['inventory'] += $value;
            }
            if (self::isDebt($account)) {
                $sum['debt'] += $value;
            }
        }

        $priorEarnings = $this->plSummary($this->totals(null, $fyStart->subDay()))['net_income'];
        $currentEarnings = $this->plSummary($this->totals($fyStart, $asOf))['net_income'];
        $sum['equity'] = $sum['equity_accounts'] + $priorEarnings + $currentEarnings;

        return [
            'values' => $values,
            'prior_earnings' => $priorEarnings,
            'current_earnings' => $currentEarnings,
            'fiscal_year_start' => $fyStart,
        ] + array_map(fn ($v) => round($v, 2), $sum);
    }

    // ---------------------------------------------------------------- Profit & Loss

    public function profitAndLoss(Period $period): ReportDocument
    {
        $columns = ['cur' => $period];
        if ($period->compare) {
            $columns['cmp'] = $period->compare;
        }
        $totals = array_map(fn (Period $p) => $this->totals($p->start, $p->end), $columns);
        $summary = array_map(fn ($t) => $this->plSummary($t), $totals);

        $rows = [];
        $amounts = fn (callable $pick) => array_map($pick, array_keys($columns), $columns);
        $line = function (string $label, array $values, string $style, int $indent = 0, ?string $href = null) use ($columns) {
            $cells = ['account' => $label];
            foreach (array_keys($columns) as $i => $key) {
                $cells[$key] = $values[$i];
            }
            if (isset($columns['cmp'])) {
                $cells['chg'] = round($cells['cur'] - $cells['cmp'], 2);
                $cells['chg_pct'] = self::growth($cells['cur'], $cells['cmp']);
            }

            return ReportDocument::row($cells, $style, $indent, $href);
        };

        foreach (self::PL_GROUPS as $type => $label) {
            $groupAccounts = $this->accounts->filter(fn (Account $a) => $a->type === $type
                && collect($totals)->contains(fn ($t) => abs(self::value($type, $t[$a->id] ?? null)) >= 0.005));

            if ($groupAccounts->isNotEmpty()) {
                $rows[] = ReportDocument::row(['account' => $label], 'heading');
                foreach ($groupAccounts as $account) {
                    $rows[] = $line(
                        "{$account->code} · {$account->name}",
                        $amounts(fn ($key) => self::value($type, $totals[$key][$account->id] ?? null)),
                        'line', 1, $this->ledgerLink($account, $period),
                    );
                }
                $rows[] = $line('Total '.strtolower($label), $amounts(fn ($key) => $summary[$key][self::PL_KEYS[$type]]), 'subtotal');
            }

            if ($type === 'cost_of_sales') {
                $rows[] = $line('Gross profit', $amounts(fn ($key) => $summary[$key]['gross_profit']), 'total');
            }
            if ($type === 'expense') {
                $rows[] = $line('Operating income', $amounts(fn ($key) => $summary[$key]['operating_income']), 'total');
            }
        }
        $rows[] = $line('Net income', $amounts(fn ($key) => $summary[$key]['net_income']), 'grand');

        $tableColumns = [ReportDocument::column('account', 'Account'), ReportDocument::column('cur', $period->shortLabel(), 'money')];
        if ($period->compare) {
            $tableColumns[] = ReportDocument::column('cmp', $period->compare->shortLabel(), 'money');
            $tableColumns[] = ReportDocument::column('chg', '$ Change', 'money');
            $tableColumns[] = ReportDocument::column('chg_pct', '% Change', 'pct');
        }

        $cur = $summary['cur'];

        return (new ReportDocument('Profit & Loss', $this->company->name.' · '.$period->label()))
            ->kpi('Revenue', $cur['revenue'])
            ->kpi('Gross margin', self::percent($cur['gross_profit'], $cur['revenue']), 'pct')
            ->kpi('Net income', $cur['net_income'], 'money', $cur['net_income'] >= 0 ? 'good' : 'bad')
            ->kpi('EBITDA', $cur['ebitda'])
            ->table($tableColumns, $rows);
    }

    // ---------------------------------------------------------------- Balance Sheet

    public function balanceSheet(Period $period): ReportDocument
    {
        $dates = ['cur' => $period->end];
        if ($period->compare) {
            $dates['cmp'] = $period->compare->end;
        }
        $models = array_map(fn (CarbonImmutable $d) => $this->bsModel($d), $dates);
        $keys = array_keys($dates);

        $cells = function (string $label, callable $pick) use ($keys) {
            $cells = ['account' => $label];
            foreach ($keys as $key) {
                $cells[$key] = $pick($key);
            }

            return $cells;
        };
        $hasValue = fn (Account $a) => collect($keys)->contains(fn ($key) => abs($models[$key]['values'][$a->id] ?? 0) >= 0.005);

        $rows = [];
        $sectionRows = function (string $type, array $knownSections) use (&$rows, $cells, $hasValue, $models, $period) {
            $accounts = $this->accounts->filter(fn (Account $a) => $a->type === $type && $hasValue($a));
            $sections = array_values(array_unique(array_merge($knownSections, $accounts->pluck('section')->filter()->all())));

            foreach ($sections as $section) {
                $inSection = $accounts->filter(fn (Account $a) => ($a->section ?: $knownSections[0]) === $section);
                if ($inSection->isEmpty()) {
                    continue;
                }
                $rows[] = ReportDocument::row(['account' => $section], 'section');
                foreach ($inSection as $account) {
                    $rows[] = ReportDocument::row(
                        $cells("{$account->code} · {$account->name}", fn ($key) => $models[$key]['values'][$account->id] ?? 0.0),
                        'line', 1, $this->ledgerLink($account, $period),
                    );
                }
                $rows[] = ReportDocument::row(
                    $cells("Total {$section}", fn ($key) => round($inSection->sum(fn (Account $a) => $models[$key]['values'][$a->id] ?? 0), 2)),
                    'subtotal',
                );
            }
        };

        $rows[] = ReportDocument::row(['account' => 'Assets'], 'heading');
        $sectionRows('asset', self::ASSET_SECTIONS);
        $rows[] = ReportDocument::row($cells('Total assets', fn ($key) => $models[$key]['assets']), 'total');

        $rows[] = ReportDocument::row(['account' => 'Liabilities'], 'heading');
        $sectionRows('liability', self::LIABILITY_SECTIONS);
        $rows[] = ReportDocument::row($cells('Total liabilities', fn ($key) => $models[$key]['liabilities']), 'total');

        $rows[] = ReportDocument::row(['account' => 'Equity'], 'heading');
        $retainedAccount = $this->accounts->firstWhere('system_key', 'retained_earnings');
        foreach ($this->accounts->filter(fn (Account $a) => $a->type === 'equity') as $account) {
            $isRetained = $retainedAccount && $account->id === $retainedAccount->id;
            $value = fn ($key) => round(($models[$key]['values'][$account->id] ?? 0) + ($isRetained ? $models[$key]['prior_earnings'] : 0), 2);
            if (collect($keys)->every(fn ($key) => abs($value($key)) < 0.005)) {
                continue;
            }
            $rows[] = ReportDocument::row($cells("{$account->code} · {$account->name}", $value), 'line', 1, $this->ledgerLink($account, $period));
        }
        $rows[] = ReportDocument::row($cells('Net income (this fiscal year)', fn ($key) => $models[$key]['current_earnings']), 'line', 1);
        $rows[] = ReportDocument::row($cells('Total equity', fn ($key) => $models[$key]['equity']), 'total');
        $rows[] = ReportDocument::row(
            $cells('Total liabilities & equity', fn ($key) => round($models[$key]['liabilities'] + $models[$key]['equity'], 2)),
            'grand',
        );

        $columns = [ReportDocument::column('account', 'Account'), ReportDocument::column('cur', 'As of '.$period->end->format('M j, Y'), 'money')];
        if ($period->compare) {
            $columns[] = ReportDocument::column('cmp', 'As of '.$period->compare->end->format('M j, Y'), 'money');
        }

        $cur = $models['cur'];
        $difference = round($cur['assets'] - $cur['liabilities'] - $cur['equity'], 2);
        $balanced = abs($difference) < 0.005;

        $document = (new ReportDocument('Balance Sheet', $this->company->name.' · as of '.$period->end->format('M j, Y')))
            ->kpi('Total assets', $cur['assets'])
            ->kpi('Total liabilities', $cur['liabilities'])
            ->kpi('Equity', $cur['equity'], 'money', $cur['equity'] >= 0 ? null : 'bad')
            ->kpi('Cash', $cur['cash'])
            ->table($columns, $rows)
            ->check($balanced ? 'Assets equal liabilities plus equity' : 'Out of balance by '.money($difference), $balanced);

        if ($retainedAccount && $cur['prior_earnings'] != 0.0) {
            $document->note('Retained earnings include net income from fiscal years before '.$cur['fiscal_year_start']->format('M j, Y').'.');
        }

        return $document;
    }

    // ---------------------------------------------------------------- Trial Balance

    public function trialBalance(Period $period): ReportDocument
    {
        $asOf = $period->end;
        $fyStart = Period::fiscalYearStart($asOf, $this->company->fiscal_year_start_month ?: 1);
        $cumulative = $this->totals(null, $asOf);
        $yearToDate = $this->totals($fyStart, $asOf);
        $priorEarnings = $this->plSummary($this->totals(null, $fyStart->subDay()))['net_income'];

        $rows = [];
        $debits = 0.0;
        $credits = 0.0;
        $add = function (string $label, float $net, ?string $href = null) use (&$rows, &$debits, &$credits) {
            if (abs($net) < 0.005) {
                return;
            }
            $debit = $net > 0 ? $net : null;
            $credit = $net < 0 ? -$net : null;
            $debits += $debit ?? 0;
            $credits += $credit ?? 0;
            $rows[] = ReportDocument::row(['account' => $label, 'debit' => $debit, 'credit' => $credit], 'line', 0, $href);
        };

        foreach ($this->accounts as $id => $account) {
            $t = self::isProfitAndLoss($account) ? ($yearToDate[$id] ?? null) : ($cumulative[$id] ?? null);
            $net = $t ? round($t['debit'] - $t['credit'], 2) : 0.0;
            if ($account->system_key === 'retained_earnings') {
                $net = round($net - $priorEarnings, 2);   // earlier years' profit is a credit to retained earnings
            }
            $add("{$account->code} · {$account->name}", $net, $this->ledgerLink($account, $period));
        }

        $debits = round($debits, 2);
        $credits = round($credits, 2);
        $balanced = abs($debits - $credits) < 0.005;
        $rows[] = ReportDocument::row(['account' => 'Totals', 'debit' => $debits, 'credit' => $credits], 'grand');

        return (new ReportDocument('Trial Balance', $this->company->name.' · as of '.$asOf->format('M j, Y')))
            ->kpi('Total debits', $debits)
            ->kpi('Total credits', $credits)
            ->table([
                ReportDocument::column('account', 'Account'),
                ReportDocument::column('debit', 'Debit', 'money'),
                ReportDocument::column('credit', 'Credit', 'money'),
            ], $rows)
            ->check($balanced ? 'Debits equal credits' : 'Debits and credits differ by '.money($debits - $credits), $balanced)
            ->note('Income and expense accounts show activity since the fiscal year started on '.$fyStart->format('M j, Y').'.');
    }

    // ---------------------------------------------------------------- General Ledger

    public function generalLedger(Period $period, ?int $accountId = null): ReportDocument
    {
        $fyStart = Period::fiscalYearStart($period->start, $this->company->fiscal_year_start_month ?: 1);
        $dayBefore = $period->start->subDay();
        $openingBalanceSheet = $this->totals(null, $dayBefore);
        $openingProfitAndLoss = $period->start->gt($fyStart) ? $this->totals($fyStart, $dayBefore) : [];

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->company->id)
            ->whereBetween('l.entry_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->when($accountId, fn ($q) => $q->where('l.account_id', $accountId))
            ->when($this->through, $this->throughScope('l'))
            ->when($this->search, fn ($q, $text) => $q->where(fn ($w) => $w->where('l.description', 'like', "%{$text}%")
                ->orWhere('e.description', 'like', "%{$text}%")->orWhere('e.reference', 'like', "%{$text}%")))
            ->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
            ->get(['l.account_id', 'l.entry_date', 'l.debit', 'l.credit', 'l.description as line_description',
                'e.id as entry_id', 'e.entry_number', 'e.description'])
            ->groupBy('account_id');

        $rows = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;
        // filter() keeps the id keys; Eloquent's only() would re-index them from 0
        $accounts = $accountId ? $this->accounts->filter(fn (Account $a) => $a->id === $accountId) : $this->accounts;

        foreach ($accounts as $id => $account) {
            $opening = self::natural($account, self::isProfitAndLoss($account) ? ($openingProfitAndLoss[$id] ?? null) : ($openingBalanceSheet[$id] ?? null));
            $accountLines = $lines->get($id, collect());
            // A search shows only the accounts with matching lines
            if ($accountLines->isEmpty() && (abs($opening) < 0.005 || $this->search !== null)) {
                continue;
            }

            $rows[] = ReportDocument::row(['date' => "{$account->code} · {$account->name}"], 'heading');
            // Searching shows only some lines, so a running balance from the real opening balance would mislead:
            // the balance column then adds up the matching lines only
            if ($this->search !== null) {
                $opening = 0.0;
            } else {
                $rows[] = ReportDocument::row(['description' => 'Opening balance', 'balance' => $opening], 'line', 1);
            }

            $balance = $opening;
            $debits = 0.0;
            $credits = 0.0;
            foreach ($accountLines as $l) {
                $debit = (float) $l->debit;
                $credit = (float) $l->credit;
                $balance = round($balance + ($account->normal_balance === 'credit' ? $credit - $debit : $debit - $credit), 2);
                $debits += $debit;
                $credits += $credit;
                $rows[] = ReportDocument::row([
                    'date' => CarbonImmutable::parse($l->entry_date)->format('M j, Y'),
                    'entry' => '#'.$l->entry_number,
                    'description' => $l->line_description ?: $l->description,
                    'debit' => $debit ?: null,
                    'credit' => $credit ?: null,
                    'balance' => $balance,
                ], 'line', 1, route('journal.show', $l->entry_id));
            }
            $totalDebits += $debits;
            $totalCredits += $credits;
            $rows[] = ReportDocument::row([
                'description' => $this->search !== null ? 'Total of matching lines' : 'Closing balance',
                'debit' => round($debits, 2),
                'credit' => round($credits, 2),
                'balance' => $balance,
            ], 'subtotal');
        }

        $title = $accountId && $accounts->isNotEmpty()
            ? 'General Ledger · '.$accounts->first()->code.' '.$accounts->first()->name
            : 'General Ledger';

        return (new ReportDocument($title, $this->company->name.' · '.$period->label()))
            ->kpi('Total debits', round($totalDebits, 2))
            ->kpi('Total credits', round($totalCredits, 2))
            // With a filter, count the entries actually listed
            ->kpi('Entries', ($this->search !== null || $this->through !== null || $accountId)
                ? $lines->flatten(1)->pluck('entry_id')->unique()->count()
                : DB::table('journal_entries')->where('company_id', $this->company->id)
                    ->whereBetween('entry_date', [$period->start->toDateString(), $period->end->toDateString()])->count(), 'number')
            ->table([
                ReportDocument::column('date', 'Date'),
                ReportDocument::column('entry', 'Entry'),
                ReportDocument::column('description', 'Description'),
                ReportDocument::column('debit', 'Debit', 'money'),
                ReportDocument::column('credit', 'Credit', 'money'),
                ReportDocument::column('balance', 'Balance', 'money'),
            ], $rows)
            ->note('Balances are shown on each account\'s normal side. Income and expense accounts start at zero each fiscal year.');
    }

    // ---------------------------------------------------------------- helpers

    public static function percent(float $part, float $whole): ?float
    {
        return abs($whole) < 0.005 ? null : round($part / $whole * 100, 1);
    }

    public static function growth(float $current, float $previous): ?float
    {
        return abs($previous) < 0.005 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }

    private function ledgerLink(Account $account, Period $period): string
    {
        return route('reports.show', [
            'report' => 'ACC-22',
            'account' => $account->id,
            'preset' => 'custom',
            'start' => (self::isProfitAndLoss($account) ? $period->start : $period->fiscalYearStartFor($this->company))->toDateString(),
            'end' => $period->end->toDateString(),
        ]);
    }
}
