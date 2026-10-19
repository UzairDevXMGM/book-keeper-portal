<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategorizationRule extends Model
{
    protected $fillable = [
        'company_id',
        'source',
        'name',
        'keyword',
        'match_type',
        'direction',
        'applies_to',
        'account_code',
        'account_id',
        'category_type_id',
        'category',
        'priority',
        'confidence',
        'needs_review',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'confidence' => 'integer',
            'needs_review' => 'boolean',
            'is_active' => 'boolean',
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

    public function categoryType(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class);
    }
}
