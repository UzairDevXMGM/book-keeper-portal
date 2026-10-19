<?php

namespace App\Services\Ledger;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way money gets into the ledger. Every entry must balance (debits = credits, checked with exact decimal
 * math), may only use the company's own active accounts, and cannot be dated inside a closed period.
 * Saved entries are never edited: mistakes are fixed with reverse().
 */
class LedgerService
{
    /** Column sizes of journal_entries / journal_lines. */
    private const DESCRIPTION_LENGTH = 500;
    private const REFERENCE_LENGTH = 100;

    /**
     * @param  array{entry_date: string, description?: ?string, reference?: ?string, source?: string, upload_id?: ?int, reverses_entry_id?: ?int}  $attributes
     * @param  array<int, array{account_id: int|string, debit?: mixed, credit?: mixed, description?: ?string}>  $lines
     */
    public function post(Company $company, array $attributes, array $lines, ?User $user = null): JournalEntry
    {
        $date = Carbon::parse($attributes['entry_date'])->toDateString();
        $source = $attributes['source'] ?? 'manual';

        if ($company->locked_through && $date <= $company->locked_through->toDateString()) {
            throw new LedgerException('The books are closed through '.$company->locked_through->format('M j, Y').'. Use a later date.');
        }

        $lines = $this->checkLines($company, $lines, allowInactive: $source === 'reversal');

        return DB::transaction(function () use ($company, $attributes, $lines, $user, $date, $source) {
            // Lock the company row so two postings cannot take the same entry number
            Company::whereKey($company->id)->lockForUpdate()->first();
            $number = (int) JournalEntry::where('company_id', $company->id)->max('entry_number') + 1;

            $entry = JournalEntry::create([
                'company_id' => $company->id,
                'entry_number' => $number,
                'entry_date' => $date,
                'description' => self::clip($attributes['description'] ?? null, self::DESCRIPTION_LENGTH),
                'reference' => self::clip($attributes['reference'] ?? null, self::REFERENCE_LENGTH),
                'source' => $source,
                'upload_id' => $attributes['upload_id'] ?? null,
                'reverses_entry_id' => $attributes['reverses_entry_id'] ?? null,
                'created_by' => $user?->id,
            ]);

            $created = [];
            foreach ($lines as $i => $line) {
                $created[] = $entry->lines()->create([
                    'company_id' => $company->id,
                    'account_id' => $line['account_id'],
                    'entry_date' => $date,
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'line_no' => $i + 1,
                ]);
            }

            // The lines just saved, without reading them back (an upload posts thousands of entries in one go)
            return $entry->setRelation('lines', $entry->lines()->getRelated()->newCollection($created));
        });
    }

    /** Posts the mirror image of an entry, which cancels it without deleting history. */
    public function reverse(JournalEntry $entry, ?User $user = null, ?string $date = null): JournalEntry
    {
        if ($entry->source === 'reversal') {
            throw new LedgerException('A reversing entry cannot itself be reversed. Post a new entry instead.');
        }
        if (JournalEntry::where('reverses_entry_id', $entry->id)->exists()) {
            throw new LedgerException("Entry #{$entry->entry_number} has already been reversed.");
        }

        $lines = $entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'description' => $line->description,
        ])->all();

        return $this->post($entry->company, [
            'entry_date' => $date ?? now()->toDateString(),
            'description' => "Reversal of entry #{$entry->entry_number}".($entry->description ? ": {$entry->description}" : ''),
            'reference' => $entry->reference,
            'source' => 'reversal',
            'reverses_entry_id' => $entry->id,
        ], $lines, $user);
    }

    /** @return list<array{account_id: int, debit: string, credit: string, description: ?string}> */
    private function checkLines(Company $company, array $lines, bool $allowInactive): array
    {
        $clean = [];
        foreach ($lines as $line) {
            $debit = self::amount($line['debit'] ?? null);
            $credit = self::amount($line['credit'] ?? null);
            if (bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;   // blank row left in the form
            }
            if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
                throw new LedgerException('A line can have a debit or a credit, not both.');
            }
            $clean[] = [
                'account_id' => (int) ($line['account_id'] ?? 0),
                'debit' => $debit,
                'credit' => $credit,
                'description' => self::clip($line['description'] ?? null, self::DESCRIPTION_LENGTH),
            ];
        }

        if (count($clean) < 2) {
            throw new LedgerException('An entry needs at least two lines.');
        }

        $debits = array_reduce($clean, fn ($sum, $l) => bcadd($sum, $l['debit'], 2), '0');
        $credits = array_reduce($clean, fn ($sum, $l) => bcadd($sum, $l['credit'], 2), '0');
        if (bccomp($debits, $credits, 2) !== 0) {
            throw new LedgerException('Debits ('.money($debits).') must equal credits ('.money($credits).').');
        }

        $ids = array_unique(array_column($clean, 'account_id'));
        $valid = Account::where('company_id', $company->id)->whereIn('id', $ids)
            ->when(! $allowInactive, fn ($q) => $q->where('is_active', true))
            ->count();
        if ($valid !== count($ids)) {
            throw new LedgerException('Every line needs one of your active accounts.');
        }

        return $clean;
    }

    /** Text cut to fit its column; empty text is stored as null. */
    private static function clip(?string $text, int $length): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /** "1,234.5" -> "1234.50". Amounts are positive with at most two decimals. */
    public static function amount(mixed $value): string
    {
        $value = str_replace([',', '$', ' '], '', trim((string) ($value ?? '')));
        if ($value === '') {
            return '0.00';
        }
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value)) {
            throw new LedgerException("\"{$value}\" is not a valid amount. Use a positive number with up to two decimals.");
        }

        return bcadd($value, '0', 2);
    }
}
