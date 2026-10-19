<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date stored as "Y-m-d" on every database. Laravel's `date` cast writes "Y-m-d 00:00:00", which breaks
 * string comparisons such as entry_date <= '2026-01-31' on databases without a real DATE type (SQLite in tests).
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
