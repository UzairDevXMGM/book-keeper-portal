<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** For one industry, a category type posts to that industry's own account (e.g. Trucking: Fuel -> 5215 Fuel - Trucks). */
class CategoryTypeIndustryAccount extends Model
{
    protected $fillable = ['category_type_id', 'industry', 'account_code'];

    public function categoryType(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class);
    }
}
