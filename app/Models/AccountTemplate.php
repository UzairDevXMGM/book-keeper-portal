<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the chart of accounts library copied to new companies: master rows (industry = null) go to every
 * company, industry rows only to companies in that industry.
 */
class AccountTemplate extends Model
{
    protected $fillable = [
        'industry',
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
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_bank_account' => 'boolean',
            'is_credit_card' => 'boolean',
        ];
    }

    /** The master chart plus the accounts of one industry. */
    public function scopeForIndustry(Builder $query, ?string $industry): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('industry')->when($industry, fn ($q) => $q->orWhere('industry', $industry)));
    }
}
