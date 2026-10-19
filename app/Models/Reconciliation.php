<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reconciliation extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'company_id',
        'account_id',
        'statement_date',
        'statement_balance',
        'opening_balance',
        'cleared_balance',
        'status',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => DateOnly::class,
            'statement_balance' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'cleared_balance' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
