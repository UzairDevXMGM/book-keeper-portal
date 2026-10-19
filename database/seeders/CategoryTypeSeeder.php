<?php

namespace Database\Seeders;

use App\Models\CategoryType;
use App\Models\CategoryTypeIndustryAccount;
use Illuminate\Database\Seeder;

/**
 * Categorization types (database/data/category_types.json): Fuel, Meals & Entertainment, Software ... and where each
 * posts. For every industry the library has, a type with an industry hint is pointed at that industry's own account
 * when one fits (Trucking: Fuel -> 5215 Fuel - Trucks, Tolls -> 5235 Tolls & Scales; sales -> the industry's first
 * revenue line).
 *
 * Safe to run again: only types and industry links that do not exist yet are added, so edits made in the admin
 * Categories screen are kept.
 */
class CategoryTypeSeeder extends Seeder
{
    /** Revenue lines that are never where ordinary customer payments go. */
    private const SIDE_INCOME = '/breakage|warranty|restocking|layaway|\batm\b|vending|lottery|money order|prepaid card|check cashing|surcharge|bonus|discount|markdown|rebate/i';

    public function run(): void
    {
        $types = json_decode(file_get_contents(database_path('data/category_types.json')), true, flags: JSON_THROW_ON_ERROR);
        $industryAccounts = collect(json_decode(file_get_contents(database_path('data/coa/industry_accounts.json')), true, flags: JSON_THROW_ON_ERROR))
            ->sortBy(fn ($a) => (int) $a['code'])
            ->groupBy('industry');

        $now = now();
        $existingTypes = CategoryType::all()->keyBy('slug');
        $existingLinks = CategoryTypeIndustryAccount::get(['category_type_id', 'industry'])
            ->map(fn ($l) => "{$l->category_type_id}|{$l->industry}")->flip();
        $links = [];

        foreach ($types as $i => $data) {
            // The hint belongs to this data file (the admin screen does not edit it), so it is kept in step
            if (isset($existingTypes[$data['slug']]) && $existingTypes[$data['slug']]->industry_hint !== ($data['industry_hint'] ?? null)) {
                $existingTypes[$data['slug']]->update(['industry_hint' => $data['industry_hint'] ?? null]);
            }
            $type = $existingTypes[$data['slug']] ?? CategoryType::create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'direction' => $data['direction'] ?? 'out',
                'account_code' => $data['account'] ?? null,
                'system_key' => $data['system'] ?? null,
                'industry_hint' => $data['industry_hint'] ?? null,
                'needs_review' => $data['review'] ?? false,
                'is_active' => true,
                'sort_order' => ($i + 1) * 10,
            ]);

            if (! $type->industry_hint || $type->system_key) {
                continue;
            }
            foreach ($industryAccounts as $industry => $accounts) {
                if (isset($existingLinks["{$type->id}|{$industry}"]) || ! ($match = $this->match($type, $accounts))) {
                    continue;
                }
                $links[] = ['category_type_id' => $type->id, 'industry' => $industry, 'account_code' => (string) $match['code'], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($links, 250) as $chunk) {
            CategoryTypeIndustryAccount::insert($chunk);
        }
    }

    /** The industry account a type's hint points at, if any. Money-in types look at revenue, the rest at costs. */
    private function match(CategoryType $type, $accounts): ?array
    {
        $income = $type->direction === 'in';
        $candidates = $accounts->filter(fn ($a) => $income
            ? $a['class'] === 'Revenue' && ! $a['contra']
            : in_array($a['class'], ['Cost of Sales', 'Operating Expense'], true));

        // The industry's main sales line: its first revenue account that is not a side income
        // (Retail: 4015 Sales - In-store POS, not 4205 Gift Card Breakage Revenue)
        if ($type->industry_hint === '@first-revenue') {
            return $candidates->first(fn ($a) => ! preg_match(self::SIDE_INCOME, $a['name']));
        }

        return $candidates->first(fn ($a) => preg_match('/'.str_replace('/', '\/', $type->industry_hint).'/i', $a['name']));
    }
}
