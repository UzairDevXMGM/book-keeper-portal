<?php

namespace App\Http\Controllers;

use App\Models\CategorizationRule;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Import\Categorizer;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\TransactionList;
use App\Support\Audit;
use App\Support\CategoryChoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Transactions, two ways:
 *  - simple (default): one row per transaction with its category, the bank or card it went through, and money in
 *    or out — the way a business owner reads a statement;
 *  - accountant: every ledger line with debits and credits.
 * Posted entries are never edited: changing a category posts a correcting entry.
 */
class TransactionController extends Controller
{
    public const PER_PAGE = [25, 50, 100];

    /**
     * "Change category" on a plain bank / card transaction: a correcting entry on the transaction's date moves the
     * amount from its current category to the new one (reference "reclass:<line id>", so the list shows the new one).
     */
    public function recategorize(Request $request, JournalLine $line, LedgerService $ledger): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $line->company_id);
        $data = $request->validate([
            'category' => ['required', CategoryChoice::RULE],
            'remember' => ['nullable', 'boolean'],
        ], ['category.required' => 'Pick a category.']);

        $entry = $line->journalEntry()->with(['lines.account', 'importRow'])->firstOrFail();
        $list = new TransactionList($company);
        $row = $list->describe(collect([$entry]))->first();
        if (($row['categoryLineId'] ?? null) !== $line->id || JournalEntry::where('reverses_entry_id', $entry->id)->exists()) {
            return back()->with('error', 'This transaction has several parts, so its category is changed with a journal entry.');
        }
        $choice = CategoryChoice::resolve($company, (string) $data['category']);
        if (! $choice) {
            return back()->with('error', 'Pick a category.');
        }
        [$newAccountId, $typeId] = $choice;

        // Where the amount sits now: the latest correction for this line, else the line's own account
        $side = $line->debit > 0 ? 'debit' : 'credit';
        $latest = JournalEntry::where('company_id', $company->id)->where('reference', "reclass:{$line->id}")->with('lines')->latest('id')->first();
        $currentAccountId = $latest?->lines->first(fn ($l) => $l->{$side} > 0)?->account_id ?? $line->account_id;

        if ($currentAccountId !== $newAccountId) {
            $amount = $line->debit > 0 ? $line->debit : $line->credit;
            $to = ['account_id' => $newAccountId];
            $from = ['account_id' => $currentAccountId];
            $lines = $side === 'debit' ? [$to + ['debit' => $amount], $from + ['credit' => $amount]] : [$from + ['debit' => $amount], $to + ['credit' => $amount]];
            try {
                $correction = $ledger->post($company, [
                    'entry_date' => $entry->entry_date->toDateString(),
                    'description' => 'Category changed: '.($line->description ?: $entry->description),
                    'reference' => "reclass:{$line->id}",
                    'source' => 'adjustment',
                ], $lines, $request->user());
            } catch (LedgerException $e) {
                return back()->with('error', $e->getMessage());
            }
            Audit::log('transaction.recategorized', $correction, ['line' => $line->id, 'from' => $currentAccountId, 'to' => $newAccountId]);
        }

        // An uploaded line remembers its new category, so Smart Assist learns from it
        $entry->importRow?->update(['account_id' => $newAccountId, 'category_type_id' => $typeId, 'match_method' => 'user']);
        $name = $typeId ? collect(CategoryChoice::choices($company))->firstWhere('id', $typeId)['name'] ?? '' : $company->accounts()->whereKey($newAccountId)->value('name');

        $message = "Category changed to {$name}.";
        if ($request->boolean('remember')) {
            $keyword = Categorizer::keywordFor((string) ($entry->importRow->description ?? $entry->description));
            CategorizationRule::updateOrCreate(
                ['company_id' => $company->id, 'source' => 'user', 'keyword' => $keyword, 'direction' => $entry->importRow->direction ?? ($side === 'debit' ? 'out' : 'in')],
                ['name' => $keyword, 'match_type' => 'contains', 'applies_to' => 'any', 'account_id' => $newAccountId, 'category_type_id' => $typeId,
                    'priority' => Categorizer::USER_RULE_PRIORITY, 'confidence' => 97, 'needs_review' => false, 'is_active' => true],
            );
            $message .= " From now on “{$keyword}” gets this category automatically.";
        }

        return back()->with('status', $message);
    }

    public function index(Request $request): View
    {
        $company = $this->company($request);
        $filters = $request->validate([
            'view' => ['nullable', 'in:simple,accountant'],
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
            'account' => ['nullable', 'regex:/^(type:)?\d{1,10}$/'],   // an account id or a category ("type:12")
            'source' => ['nullable', 'in:upload,manual,opening_balance,adjustment,reversal'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:'.implode(',', self::PER_PAGE)],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $view = $filters['view'] ?? 'simple';
        unset($filters['per_page'], $filters['view']);

        // A category filters on the account it uses for this company
        $categories = collect((new Categorizer($company))->categoryChoices())->sortBy('name');
        $account = (string) ($filters['account'] ?? '');
        $filters['account_id'] = match (true) {
            $account === '' => null,
            str_starts_with($account, 'type:') => (int) ($categories[(int) substr($account, 5)]['account_id'] ?? 0),
            default => (int) $account,
        };

        $accounts = $company->accounts()->orderBy('code')->get(['id', 'code', 'name', 'is_bank_account', 'is_credit_card']);
        $latest = $company->journalLines()->max('entry_date');
        $shared = [
            'company' => $company,
            'view' => $view,
            'perPageOptions' => self::PER_PAGE,
            'filters' => collect($filters)->except('account_id')->all(),
            'accounts' => $accounts,
            'statementAccounts' => $accounts->filter(fn ($a) => $a->is_bank_account || $a->is_credit_card),
            'categories' => $categories,
            'latest' => $latest ? CarbonImmutable::parse($latest) : null,
        ];

        return $view === 'accountant'
            ? view('transactions.index', $shared + $this->accountantView($company, $filters, $perPage))
            : view('transactions.index', $shared + $this->simpleView($company, $filters, $perPage));
    }

    /** One row per transaction: category, the bank / card account, money in or out. */
    private function simpleView($company, array $filters, int $perPage): array
    {
        $list = new TransactionList($company);
        $statementIds = $list->statementIds;
        $transfers = $company->systemAccount('transfers_clearing')?->id;

        $entries = JournalEntry::query()
            ->where('company_id', $company->id)
            ->with(TransactionList::WITH)
            ->when($filters['start'] ?? null, fn ($q, $d) => $q->where('entry_date', '>=', $d))
            ->when($filters['end'] ?? null, fn ($q, $d) => $q->where('entry_date', '<=', $d))
            ->when($filters['account_id'] !== null, fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $filters['account_id'])))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('description', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%")))
            ->orderByDesc('entry_date')->orderByDesc('entry_number');

        // Money in / out through the bank and card accounts, leaving out starting balances and moves between them
        // (transfers, card payments: through the transfers clearing account or straight from one to the other)
        $totals = DB::table('journal_lines as l')
            ->joinSub((clone $entries)->reorder()->where('source', '!=', 'opening_balance')->select('journal_entries.id'), 'e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $statementIds ?: [0])
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('journal_lines as t')
                ->whereColumn('t.journal_entry_id', 'l.journal_entry_id')->whereColumn('t.id', '!=', 'l.id')
                ->where(fn ($w) => $w->whereIn('t.account_id', $statementIds ?: [0])->when($transfers, fn ($o) => $o->orWhere('t.account_id', $transfers))))
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->first();
        $count = (clone $entries)->reorder()->count();
        $page = $entries->paginate($perPage)->withQueryString();
        $page->setCollection($list->describe($page->getCollection()));

        return [
            'rows' => $page,
            'totals' => ['count' => $count, 'in' => (float) $totals->debit, 'out' => (float) $totals->credit],
        ];
    }

    /** Every ledger line with debits and credits. */
    private function accountantView($company, array $filters, int $perPage): array
    {
        $lines = JournalLine::query()
            ->with(['account:id,code,name', 'journalEntry:id,entry_number,description,reference,source'])
            ->where('company_id', $company->id)
            ->when($filters['start'] ?? null, fn ($q, $d) => $q->where('entry_date', '>=', $d))
            ->when($filters['end'] ?? null, fn ($q, $d) => $q->where('entry_date', '<=', $d))
            ->when($filters['account_id'] !== null, fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->whereHas('journalEntry', fn ($e) => $e->where('source', $s)))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$term}%")
                ->orWhereHas('journalEntry', fn ($e) => $e->where('description', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%"))))
            ->orderByDesc('entry_date')->orderByDesc('journal_entry_id')->orderBy('line_no');

        $totals = (clone $lines)->reorder()->selectRaw('SUM(debit) AS debit, SUM(credit) AS credit, COUNT(*) AS n')->first();

        return ['rows' => $lines->paginate($perPage)->withQueryString(), 'totals' => $totals];
    }
}
