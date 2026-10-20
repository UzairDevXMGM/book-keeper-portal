<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One transaction line read from an upload, staged before posting to the ledger. */
class ImportRow extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_POSTED = 'posted';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_ERROR = 'error';
    /** Dated in a different year than the one picked for the upload; kept out of the books. */
    public const STATUS_OTHER_YEAR = 'other_year';

    /** How a line got its category, in words a business owner understands. */
    public const METHOD_LABELS = [
        'user' => 'Your rule',
        'bank_rule' => 'Bank rule',
        'vendor' => 'Known merchant',
        'bank_category' => "Bank's category",
        'history' => 'Learned from your past choices',
        'smart' => 'Smart Assist',
        'suspense' => 'No match',
    ];

    protected $fillable = [
        'upload_id',
        'company_id',
        'row_number',
        'txn_date',
        'description',
        'amount',
        'direction',
        'bank_category',
        'raw',
        'fingerprint',
        'suggested_account_id',
        'account_id',
        'category_type_id',
        'business_type',
        'rule_id',
        'match_method',
        'confidence',
        'status',
        'error_message',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => DateOnly::class,
            'amount' => 'decimal:2',
            'raw' => 'array',
            'confidence' => 'integer',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function suggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'suggested_account_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CategorizationRule::class, 'rule_id');
    }

    public function categoryType(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class);
    }

    /**
     * The category shown next to the line: its category type ("Fuel"), or the account it was put in when someone
     * picked an account rather than a category. Lines in a suspense account still need a category (null).
     */
    public function categoryLabel(): ?string
    {
        if (in_array($this->account?->system_key, ['suspense_expense', 'suspense_income'], true)) {
            return null;
        }

        return $this->categoryType?->name ?? $this->account?->name;
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
