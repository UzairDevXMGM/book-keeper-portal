<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id',
        'company_id',
        'account_id',
        'entry_date',
        'description',
        'debit',
        'credit',
        'line_no',
        'reconciliation_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => DateOnly::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'line_no' => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
