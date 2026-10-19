<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of spending or income ("Fuel", "Meals & Entertainment"). Merchant keywords point at a type; the type says
 * where it posts: a master chart code, a system account, or an industry's own account.
 */
class CategoryType extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'direction',
        'account_code',
        'system_key',
        'industry_hint',
        'needs_review',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CategorizationRule::class);
    }

    public function industryAccounts(): HasMany
    {
        return $this->hasMany(CategoryTypeIndustryAccount::class);
    }

    /** The chart code this type posts to for a company in $industry (null when it posts to a system account). */
    public function accountCodeFor(?string $industry): ?string
    {
        if ($this->system_key) {
            return null;
        }
        $override = $industry ? $this->industryAccounts->firstWhere('industry', $industry) : null;

        return $override?->account_code ?? $this->account_code;
    }
}
