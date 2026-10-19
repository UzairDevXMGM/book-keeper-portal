<?php

namespace App\Services\Import;

use App\Models\ImportRow;
use App\Models\JournalEntry;
use App\Models\Upload;
use App\Models\User;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Dump your data": reads an uploaded statement, stages every line as an ImportRow with a suggested account,
 * marks lines already imported as duplicates, and posts lines to the ledger (one journal entry per line).
 */
class UploadProcessor
{
    public function __construct(private StatementParser $parser, private LedgerService $ledger)
    {
    }

    public function process(Upload $upload, string $signConvention, bool $autoPost, ?User $user = null): void
    {
        $upload->update(['status' => Upload::STATUS_PROCESSING, 'error_message' => null]);
        $account = $upload->account;

        try {
            $parsed = $this->parser->parse(Storage::disk($upload->disk)->path($upload->file_path), $upload->file_type, $signConvention,
                (bool) $account->is_credit_card, $upload->year);
        } catch (ImportException $e) {
            $upload->update(['status' => Upload::STATUS_FAILED, 'error_message' => $e->getMessage()]);

            return;
        } catch (Throwable $e) {
            report($e);
            $upload->update(['status' => Upload::STATUS_FAILED, 'error_message' => 'We could not read this file. Try the CSV export from your bank.']);

            return;
        }

        $kind = $account->is_credit_card ? 'credit_card' : 'bank';
        $categorizer = new Categorizer($upload->company);

        // Fingerprints of lines already in the books (or waiting) for this company, to spot re-uploads
        $known = ImportRow::where('company_id', $upload->company_id)
            ->whereIn('status', [ImportRow::STATUS_PENDING, ImportRow::STATUS_NEEDS_REVIEW, ImportRow::STATUS_POSTED])
            ->whereNotNull('fingerprint')->pluck('fingerprint')->flip();

        DB::transaction(function () use ($upload, $parsed, $account, $kind, $categorizer, $known) {
            $occurrences = [];
            foreach ($parsed['lines'] as $line) {
                $row = [
                    'upload_id' => $upload->id,
                    'company_id' => $upload->company_id,
                    'row_number' => $line['row_number'],
                    'txn_date' => $line['txn_date'],
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                    'direction' => $line['direction'],
                    'bank_category' => $line['bank_category'],
                    'raw' => $line['raw'],
                ];

                if ($line['error']) {
                    ImportRow::create($row + ['status' => ImportRow::STATUS_ERROR, 'error_message' => $line['error']]);

                    continue;
                }

                // Two identical coffees on the same day are two lines; the same statement uploaded twice is not
                $base = implode('|', [$account->id, $line['txn_date'], $line['amount'], $line['direction'], mb_strtoupper($line['description'])]);
                $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
                $fingerprint = hash('sha256', $base.'|'.$occurrences[$base]);

                // The user picked a year: lines from another year stay out of this year's books (uploading the same
                // file for that other year adds them there)
                $lineYear = (int) substr((string) $line['txn_date'], 0, 4);
                if ($upload->year && $lineYear !== (int) $upload->year) {
                    ImportRow::create($row + ['fingerprint' => $fingerprint, 'status' => ImportRow::STATUS_OTHER_YEAR,
                        'error_message' => "Dated in {$lineYear}. You picked {$upload->year}, so it is not added to your books."]);

                    continue;
                }

                if (isset($known[$fingerprint])) {
                    ImportRow::create($row + ['fingerprint' => $fingerprint, 'status' => ImportRow::STATUS_DUPLICATE]);

                    continue;
                }

                $suggestion = $categorizer->suggest($line['description'], $line['direction'], $kind, $line['bank_category'], $account->id);
                ImportRow::create($row + [
                    'fingerprint' => $fingerprint,
                    'suggested_account_id' => $suggestion['account_id'],
                    'account_id' => $suggestion['account_id'],
                    'category_type_id' => $suggestion['category_type_id'],
                    'rule_id' => $suggestion['rule_id'],
                    'match_method' => $suggestion['method'],
                    'confidence' => $suggestion['confidence'],
                    'status' => $suggestion['needs_review'] ? ImportRow::STATUS_NEEDS_REVIEW : ImportRow::STATUS_PENDING,
                ]);
            }

            $dates = collect($parsed['lines'])->pluck('txn_date')->filter();
            $upload->update([
                'statement_start_date' => $upload->statement_start_date ?? $dates->min(),
                'statement_end_date' => $upload->statement_end_date ?? $dates->max(),
                'opening_balance' => $upload->opening_balance ?? $parsed['opening'],
                'closing_balance' => $upload->closing_balance ?? $parsed['closing'],
                'processed_at' => now(),
            ]);
        });

        if ($autoPost) {
            $this->postReady($upload, $user);
        }
        $this->refresh($upload);
    }

    /** Posts every line that is ready (confident category, not a duplicate). Returns how many were posted. */
    public function postReady(Upload $upload, ?User $user = null): int
    {
        // One transaction for the whole file: a single commit instead of one per line (a 3,000-line file posts in a
        // few seconds), and a file that is cut off half way leaves nothing half posted. Each line still has its own
        // savepoint inside postRow(), so one bad line is rolled back alone.
        $upload->loadMissing('company');
        $posted = DB::transaction(function () use ($upload, $user) {
            $posted = 0;
            foreach ($upload->rows()->where('status', ImportRow::STATUS_PENDING)->orderBy('txn_date')->orderBy('row_number')->get() as $row) {
                $row->setRelation('upload', $upload);   // saves two lookups per line
                try {
                    $this->postRow($row, $row->account_id, $user);
                    $posted++;
                } catch (LedgerException|ImportException $e) {
                    $row->update(['status' => ImportRow::STATUS_NEEDS_REVIEW, 'error_message' => $e->getMessage()]);
                } catch (Throwable $e) {
                    // One bad line must not stop the rest of the file; it waits in review and the error is logged
                    report($e);
                    $row->update(['status' => ImportRow::STATUS_NEEDS_REVIEW, 'error_message' => 'This line could not be posted automatically.']);
                }
            }

            return $posted;
        });
        $this->refresh($upload);

        return $posted;
    }

    /**
     * Money in:  debit the statement account, credit the category.
     * Money out: debit the category, credit the statement account.
     * (On a credit card account a purchase is "out": the card liability goes up; a payment is "in".)
     */
    public function postRow(ImportRow $row, ?int $accountId, ?User $user = null): JournalEntry
    {
        $upload = $row->upload;
        if (! $accountId) {
            throw new ImportException('Choose an account for this line.');
        }
        if ($accountId === $upload->account_id) {
            throw new ImportException('A line cannot be posted to the statement account itself. Use Transfers clearing for transfers.');
        }
        if (! in_array($row->status, [ImportRow::STATUS_PENDING, ImportRow::STATUS_NEEDS_REVIEW], true)) {
            throw new ImportException('This line has already been handled.');
        }

        $statement = ['account_id' => $upload->account_id];
        $category = ['account_id' => $accountId];
        $lines = $row->direction === 'in'
            ? [$statement + ['debit' => $row->amount], $category + ['credit' => $row->amount]]
            : [$category + ['debit' => $row->amount], $statement + ['credit' => $row->amount]];

        return DB::transaction(function () use ($row, $upload, $lines, $accountId, $user) {
            $entry = $this->ledger->post($upload->company, [
                'entry_date' => $row->txn_date->toDateString(),
                'description' => $row->description,
                'source' => 'upload',
                'upload_id' => $upload->id,
            ], $lines, $user);

            $row->update(['status' => ImportRow::STATUS_POSTED, 'account_id' => $accountId, 'journal_entry_id' => $entry->id, 'error_message' => null]);

            return $entry;
        });
    }

    /** Counters and status: needs_review while anything is waiting, completed when nothing is. */
    public function refresh(Upload $upload): void
    {
        if ($upload->status === Upload::STATUS_FAILED) {
            return;
        }
        $counts = $upload->rows()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $waiting = ($counts[ImportRow::STATUS_PENDING] ?? 0) + ($counts[ImportRow::STATUS_NEEDS_REVIEW] ?? 0);

        $upload->update([
            'rows_total' => $counts->sum(),
            'rows_posted' => $counts[ImportRow::STATUS_POSTED] ?? 0,
            'rows_duplicate' => $counts[ImportRow::STATUS_DUPLICATE] ?? 0,
            'rows_failed' => $counts[ImportRow::STATUS_ERROR] ?? 0,
            'status' => $waiting > 0 ? Upload::STATUS_NEEDS_REVIEW : Upload::STATUS_COMPLETED,
        ]);
    }
}
