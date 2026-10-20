<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A kind of business ("Restaurant", "Roofing contractor") recognised from words in a merchant's name. */
class BusinessType extends Model
{
    protected $fillable = ['name', 'group', 'keywords', 'category_type_id', 'direction', 'confidence', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'confidence' => 'integer', 'sort_order' => 'integer', 'seeded_at' => 'datetime'];
    }

    public function categoryType(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class);
    }

    /** @return list<string> the keywords in the normalized form descriptions are matched on */
    public function keywordList(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($k) => \App\Services\Import\Categorizer::normalize($k),
            preg_split('/[\r\n,]+/', (string) $this->keywords)
        ))));
    }
}
