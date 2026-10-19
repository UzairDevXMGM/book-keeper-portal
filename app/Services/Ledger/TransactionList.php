<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

/**
 * Journal entries the way a business owner reads a bank statement: one row per transaction with its category, the
 * bank or card it went through and the money in or out. Used by the Transactions page and the dashboard.
 */
class TransactionList
{
    /** Relations describe() needs; eager-load them on the entries query. */
    public const WITH = [
        'lines.account:id,code,name,type,is_credit_card,system_key',
        'importRow:id,journal_entry_id,category_type_id,account_id',
        'importRow.categoryType:id,name',
        'importRow.account:id,name,system_key',
    ];

    /** @var list<int> bank, card and cash accounts */
    public readonly array $statementIds;
    /** @var list<int> */
    private array $suspenseIds;

    public function __construct(private Company $company)
    {
        $this->statementIds = $company->accounts()
            ->where(fn ($q) => $q->where('is_bank_account', true)->orWhere('is_credit_card', true)->orWhere('report_line', 'Cash & Cash Equivalents'))
            ->pluck('id')->all();
        $this->suspenseIds = $company->accounts()->whereIn('system_key', ['suspense_expense', 'suspense_income'])->pluck('id')->all();
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries  loaded with self::WITH
     * @return Collection<int, array{entry: JournalEntry, category: ?string, through: ?string, in: float, out: float, amount: float, note: ?string}>
     */
    public function describe(Collection $entries): Collection
    {
        // A category picked later ("Check categories", or "Change category" here) is a correcting entry with the
        // reference "reclass:<line id>": the line's category is where the latest correction moved it
        $lines = $entries->flatMap->lines->reject(fn ($l) => in_array($l->account_id, $this->statementIds, true))->keyBy('id');
        $movedTo = $lines->isEmpty() ? collect() : JournalEntry::where('company_id', $this->company->id)
            ->whereIn('reference', $lines->keys()->map(fn ($id) => "reclass:{$id}"))
            ->with('lines.account:id,name,system_key')->orderBy('id')->get()
            ->groupBy(fn ($e) => (int) substr($e->reference, 8))
            ->map(function ($corrections, $lineId) use ($lines) {
                // The target is the correcting line on the same side (debit / credit) as the original line
                $side = $lines[$lineId]->debit > 0 ? 'debit' : 'credit';

                return $corrections->last()->lines->first(fn ($l) => $l->{$side} > 0)?->account;
            });

        return $entries->map(fn (JournalEntry $entry) => $this->row($entry, $movedTo));
    }

    private function row(JournalEntry $entry, Collection $movedTo): array
    {
        $onStatement = $entry->lines->filter(fn (JournalLine $l) => in_array($l->account_id, $this->statementIds, true))->values();
        $amount = (float) $entry->lines->sum('debit');

        // Straight from one bank / card account to another
        if ($onStatement->count() >= 2) {
            $from = $onStatement->first(fn ($l) => $l->credit > 0);
            $to = $onStatement->first(fn ($l) => $l->debit > 0);

            return ['entry' => $entry, 'category' => 'Transfer between your accounts', 'through' => $from && $to ? "{$from->account->name} → {$to->account->name}" : null,
                'in' => 0.0, 'out' => 0.0, 'amount' => (float) $onStatement->sum('debit'), 'note' => 'moved between your accounts', 'categoryLineId' => null];
        }

        $statementLine = $onStatement->first();
        $other = $entry->lines->reject(fn (JournalLine $l) => $statementLine && $l->is($statementLine))->values();
        $single = $other->count() === 1 ? $other->first() : null;
        $suspenseLine = $other->first(fn (JournalLine $l) => in_array($l->account_id, $this->suspenseIds, true));
        // With no bank or card line (depreciation, accruals), the category is the income or expense side
        $main = $single ?? ($statementLine ? null : ($other->first(fn ($l) => in_array($l->account->type, ['expense', 'cost_of_sales', 'other_expense', 'income', 'other_income'], true))
            ?? ($other->count() === 2 ? $other->first(fn ($l) => $l->debit > 0) : null)));

        // The line that carries the category; set when the transaction is a simple one (bank / card + one category)
        $categoryLine = $statementLine && $single ? $single : null;
        $moved = $categoryLine ? ($movedTo[$categoryLine->id] ?? null) : ($suspenseLine ? ($movedTo[$suspenseLine->id] ?? null) : null);

        // Category: a later correction, the one the upload gave it, else the other account; several = biggest + n more
        $category = match (true) {
            // An uploaded line that was changed keeps the category name it was given ("Fuel" rather than the account)
            $moved !== null => $entry->importRow && $entry->importRow->account_id === $moved->id && $entry->importRow->categoryLabel()
                ? $entry->importRow->categoryLabel() : self::accountLabel($moved),
            $suspenseLine !== null => null,   // still needs a category
            $entry->importRow !== null => $entry->importRow->categoryLabel(),
            $main !== null => self::accountLabel($main->account),
            $other->count() > 1 => self::accountLabel($other->sortByDesc(fn ($l) => max($l->debit, $l->credit))->first()->account)
                .' + '.($other->count() - 1).' more',
            default => null,
        };
        if (str_starts_with((string) $entry->reference, 'reclass:') && $other->count() === 2) {
            $target = $other->first(fn (JournalLine $l) => ! in_array($l->account_id, $this->suspenseIds, true));
            $category = $target ? 'Category set: '.$target->account->name : $category;
        }

        // Bank: a debit is money in. Card: a credit is money spent (out), a debit is a payment or refund (in).
        return [
            'entry' => $entry,
            'category' => $category,
            'through' => $statementLine?->account->name,
            'in' => (float) ($statementLine?->debit ?? 0),
            'out' => (float) ($statementLine?->credit ?? 0),
            'amount' => $amount,
            // The category of a plain bank / card transaction can be changed on the Transactions page
            'categoryLineId' => $categoryLine && ! in_array($entry->source, ['reversal', 'opening_balance'], true)
                && ! str_starts_with((string) $entry->reference, 'reclass:') ? $categoryLine->id : null,
            // Entries that never touch a bank or card move money only inside the books
            'note' => match (true) {
                $entry->source === 'opening_balance' => 'starting balance',
                $statementLine !== null => null,
                $other->contains(fn ($l) => $l->account->system_key === 'accounts_payable' && $l->credit > 0) => 'bill recorded, not paid yet',
                $other->contains(fn ($l) => $l->account->system_key === 'accounts_receivable' && $l->debit > 0) => 'invoice recorded, not paid yet',
                default => 'moved inside your books',
            },
        ];
    }

    public static function accountLabel(Account $account): string
    {
        return match ($account->system_key) {
            'transfers_clearing' => 'Transfer between your accounts',
            'owner_draws' => 'Owner draw (personal)',
            'opening_balance_equity' => 'Starting balance',
            default => $account->name,
        };
    }
}
