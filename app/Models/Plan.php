<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_interval',
        'stripe_price_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Reports included in the plan at no extra cost. */
    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class);
    }

    /** The plan every new sign-up subscribes to. */
    public static function default(): ?self
    {
        return static::where('is_active', true)->orderBy('sort_order')->first();
    }
}
