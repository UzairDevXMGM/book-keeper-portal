<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'cost_of_sales', 'expense', 'other_income', 'other_expense'];

    protected $fillable = [
        'company_id',
        'canonical_code',
        'code',
        'name',
        'type',
        'subtype',
        'section',
        'report_line',
        'normal_balance',
        'is_bank_account',
        'is_credit_card',
        'system_key',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_bank_account' => 'boolean',
            'is_credit_card' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Balance Sheet accounts carry their balance forward; P&L accounts reset each fiscal year. */
    public function isBalanceSheet(): bool
    {
        return in_array($this->type, ['asset', 'liability', 'equity'], true);
    }
}
