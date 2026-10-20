<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\CategoryType;
use Illuminate\Database\Seeder;

/**
 * Business types from database/data/business_types.json. Missing ones are added; ones nobody edited since they were
 * seeded take the file's latest keywords. Anything an admin changed under Admin → Business types is left alone.
 */
class BusinessTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = CategoryType::pluck('id', 'slug');
        $data = json_decode(file_get_contents(database_path('data/business_types.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data as $i => $row) {
            $values = [
                'group' => $row['group'] ?? null,
                'keywords' => implode("\n", array_values(array_unique($row['keywords']))),
                'category_type_id' => $types[$row['category']] ?? null,
                'direction' => $row['direction'] ?? 'out',
                'confidence' => $row['confidence'] ?? 72,
                'sort_order' => ($i + 1) * 10,
            ];
            $existing = BusinessType::where('name', $row['name'])->first();
            if (! $existing) {
                $existing = new BusinessType(['name' => $row['name'], 'is_active' => true]);
            } elseif (! $existing->seeded_at || $existing->updated_at?->gt($existing->seeded_at)) {
                continue;   // changed by an admin
            }
            $existing->fill($values);
            $existing->seeded_at = now();
            $existing->updated_at = $existing->seeded_at;
            $existing->save();
        }
    }
}
