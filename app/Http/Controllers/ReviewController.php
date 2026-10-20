<?php

namespace App\Http\Controllers;

use App\Models\CategorizationRule;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\JournalLine;
use App\Services\Import\Categorizer;
use App\Services\Import\ImportException;
use App\Services\Import\UploadProcessor;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * "Check categories" (the review queue): uploaded lines the categorizer was not sure about, and money already posted to the suspense
 * accounts. Approving can also save a rule so the same payee is categorized automatically next time.
 */
class ReviewController extends Controller
{
    /** What a category picker sends: a category ("type:12") or an account id. */
    private const CHOICE = 'regex:/^(type:)?\d{1,10}$/';

    public function index(Request $request): View
    {
        $company = $this->company($request);
        $uploadId = $request->integer('upload') ?: null;
        $byMerchant = $request->query('sort') === 'merchant';
        $waiting = ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)
            ->when($uploadId, fn ($q) => $q->where('upload_id', $uploadId));

        // How many waiting lines share each merchant: one "Always" answers all of them
        $similar = (clone $waiting)->get(['description', 'direction'])
            ->countBy(fn ($r) => Categorizer::keywordFor((string) $r->description).'|'.$r->direction);

        return view('review.index', [
            'company' => $company,
            'rows' => $waiting
                ->with(['upload:id,original_name,account_id', 'upload.account:id,code,name', 'rule:id,name,keyword,source,company_id', 'categoryType:id,name'])
                ->when($byMerchant, fn ($q) => $q->orderBy('description'))
                ->orderBy('txn_date')->orderBy('id')
                ->paginate(20, ['*'], 'page')->withQueryString(),
            'similar' => $similar,
            'byMerchant' => $byMerchant,
            'suspense' => $this->suspenseLines($company)->paginate(10, ['*'], 'suspense_page')->withQueryString(),
            'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'system_key']),
            // Plain category names first in the pickers ("Fuel" rather than "6800 · Vehicle Fuel")
            'choices' => collect((new Categorizer($company))->categoryChoices())->sortBy('name')->values(),
            'uploadId' => $uploadId,
        ]);
    }

    /** Approve one line with the chosen category; optionally remember the choice as a rule. */
    public function approve(Request $request, ImportRow $row, UploadProcessor $processor): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $row->company_id);
        $data = $request->validate([
            'account_id' => ['nullable', self::CHOICE],   // a category ("type:12") or an account id
            'remember' => ['nullable', 'boolean'],
            'keyword' => ['nullable', 'string', 'max:150'],
        ]);
        $choice = $this->resolveChoice($company, (string) ($data['account_id'] ?? ''));
        if (! $choice) {
            return back()->with('error', 'Pick a category for this transaction.');
        }
        [$accountId, $typeId] = $choice;

        try {
            $processor->postRow($row, $accountId, $request->user());
            $row->update(['category_type_id' => $typeId]);
        } catch (ImportException|LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = 'Done: the transaction is in your books.';
        if ($request->boolean('remember')) {
            $keyword = mb_strtoupper(trim($data['keyword'] ?? '')) ?: Categorizer::keywordFor((string) $row->description);
            $rule = CategorizationRule::updateOrCreate(
                ['company_id' => $company->id, 'source' => 'user', 'keyword' => $keyword, 'direction' => $row->direction],
                ['name' => $keyword, 'match_type' => 'contains', 'applies_to' => 'any', 'account_id' => $accountId, 'category_type_id' => $typeId,
                    'priority' => Categorizer::USER_RULE_PRIORITY, 'confidence' => 97, 'needs_review' => false, 'is_active' => true],
            );
            $applied = $this->applyRule($company, $rule, $processor, $request);
            $message .= " From now on “{$keyword}” gets this category automatically".($applied ? " (and {$applied} more like it were done just now)." : '.');
            Audit::log('rule.created', $rule, ['keyword' => $keyword, 'account_id' => $accountId, 'applied' => $applied]);
        }
        Audit::log('review.approved', $row, ['account_id' => $accountId, 'category_type_id' => $typeId]);
        $processor->refresh($row->upload);

        return back()->with('status', $message);
    }

    /**
     * What a category picker sent: "type:12" (a category, posted to the account it uses for this company) or an
     * account id. Returns [account id, category type id or null], or null when it is not one of the company's.
     */
    private function resolveChoice(Company $company, string $value): ?array
    {
        if (preg_match('/^type:(\d+)$/', $value, $m)) {
            $this->choices ??= (new Categorizer($company))->categoryChoices();
            $type = $this->choices[(int) $m[1]] ?? null;

            return $type ? [$type['account_id'], $type['id']] : null;
        }
        $accountId = ctype_digit($value) ? (int) $value : 0;

        return $company->accounts()->where('is_active', true)->whereKey($accountId)->exists() ? [$accountId, null] : null;
    }

    /** @var array<int, array>|null category choices for this request */
    private ?array $choices = null;

    /** Approve (with the suggested accounts) or ignore several lines at once. */
    public function bulk(Request $request, UploadProcessor $processor): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'action' => ['required', 'in:approve,ignore'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*' => ['integer'],
            'accounts' => ['nullable', 'array'],     // row id => category chosen in that row's dropdown
            'accounts.*' => ['nullable', self::CHOICE],   // empty: keep the row's own guess
        ], ['rows.required' => 'Tick at least one line first.']);

        $rows = ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->whereIn('id', $data['rows'])->get();
        $done = 0;
        $skipped = 0;
        $uncategorized = 0;
        $suspenseIds = $company->accounts()->whereIn('system_key', ['suspense_expense', 'suspense_income'])->pluck('id')->all();
        foreach ($rows as $row) {
            if ($data['action'] === 'ignore') {
                $row->update(['status' => ImportRow::STATUS_IGNORED]);
                $done++;

                continue;
            }
            $choice = isset($data['accounts'][$row->id]) ? $this->resolveChoice($company, (string) $data['accounts'][$row->id]) : null;
            [$accountId, $typeId] = $choice ?? [$row->account_id, $row->category_type_id];
            try {
                $processor->postRow($row, $accountId, $request->user());
                $row->update(['category_type_id' => $typeId]);
                $done++;
                $uncategorized += in_array((int) $accountId, $suspenseIds, true) ? 1 : 0;
            } catch (ImportException|LedgerException) {
                $skipped++;
            } catch (Throwable $e) {
                report($e);   // one bad line must not stop the rest of the batch
                $skipped++;
            }
        }
        $rows->pluck('upload')->unique('id')->filter()->each(fn ($upload) => $processor->refresh($upload));
        Audit::log('review.bulk_'.$data['action'], null, ['rows' => $done, 'skipped' => $skipped], $company);

        $message = $data['action'] === 'ignore'
            ? "{$done} transaction(s) skipped."
            : "{$done} transaction(s) added to your books."
                .($uncategorized ? " {$uncategorized} of them had no category picked and went in as “Uncategorized”: give them one below." : '');

        return back()->with('status', $message.($skipped ? " {$skipped} could not be added and are still here." : ''));
    }

    /** Moves an amount already posted to suspense onto the right account with a reclassifying entry. */
    public function reclassify(Request $request, JournalLine $line, LedgerService $ledger): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $line->company_id);
        $data = $request->validate([
            'account_id' => ['nullable', self::CHOICE],   // a category ("type:12") or an account id
        ]);
        abort_unless($this->suspenseLines($company)->whereKey($line->id)->exists(), 404);
        $choice = $this->resolveChoice($company, (string) ($data['account_id'] ?? ''));
        if (! $choice) {
            return back()->with('error', 'Pick a category to move it to.');
        }
        $data['account_id'] = $choice[0];

        $amount = $line->debit > 0 ? $line->debit : $line->credit;
        $suspense = ['account_id' => $line->account_id];
        $target = ['account_id' => (int) $data['account_id']];
        $lines = $line->debit > 0
            ? [$target + ['debit' => $amount], $suspense + ['credit' => $amount]]
            : [$suspense + ['debit' => $amount], $target + ['credit' => $amount]];

        try {
            $entry = $ledger->post($company, [
                'entry_date' => now()->toDateString(),
                'description' => 'Recategorized: '.($line->description ?: $line->journalEntry->description),
                'reference' => "reclass:{$line->id}",
                'source' => 'adjustment',
            ], $lines, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }
        Audit::log('review.reclassified', $entry, ['line' => $line->id, 'account_id' => $data['account_id']]);

        return back()->with('status', 'Moved '.money($amount)." to its category (entry #{$entry->entry_number}).");
    }

    /** Lines on the suspense accounts that have not been recategorized yet. */
    private function suspenseLines(Company $company)
    {
        $suspenseIds = $company->accounts()->whereIn('system_key', ['suspense_expense', 'suspense_income'])->pluck('id');
        $done = $company->journalEntries()->where('reference', 'like', 'reclass:%')->pluck('reference')
            ->map(fn ($ref) => (int) substr($ref, 8));

        return JournalLine::where('company_id', $company->id)
            ->whereIn('account_id', $suspenseIds)
            ->whereNotIn('id', $done)
            ->whereHas('journalEntry', fn ($q) => $q->where('reference', 'not like', 'reclass:%')->orWhereNull('reference'))
            ->with(['journalEntry:id,entry_number,description,source', 'account:id,code,name'])
            ->orderByDesc('entry_date');
    }

    /** Posts the other waiting lines the new rule matches. */
    private function applyRule(Company $company, CategorizationRule $rule, UploadProcessor $processor, Request $request): int
    {
        $applied = 0;
        $candidates = ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)
            ->where('direction', $rule->direction)->get();
        foreach ($candidates as $candidate) {
            if (! Categorizer::containsKeyword((string) $candidate->description, $rule->keyword)) {
                continue;
            }
            try {
                $processor->postRow($candidate, $rule->account_id, $request->user());
                $candidate->update(['rule_id' => $rule->id, 'match_method' => 'user', 'category_type_id' => $rule->category_type_id]);
                $applied++;
            } catch (ImportException|LedgerException) {
                // stays in the queue
            } catch (Throwable $e) {
                report($e);
            }
        }
        $candidates->pluck('upload')->unique('id')->filter()->each(fn ($upload) => $processor->refresh($upload));

        return $applied;
    }
}
