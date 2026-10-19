<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A file dropped on the "Dump your data" screen. */
class Upload extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'user_id',
        'account_id',
        'year',
        'original_name',
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'file_type',
        'file_hash',
        'status',
        'statement_start_date',
        'statement_end_date',
        'opening_balance',
        'closing_balance',
        'rows_total',
        'rows_posted',
        'rows_duplicate',
        'rows_failed',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'statement_start_date' => DateOnly::class,
            'statement_end_date' => DateOnly::class,
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
