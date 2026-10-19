<?php

namespace Database\Seeders;

use App\Models\CategorizationRule;
use App\Models\CategoryType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Global categorization rules: Bank_Rules (48), Vendor_Universe (1,005) and Category_Map (32) from the client's first
 * workbook, plus rules_added.json (added after testing the client's sample statements). Every rule points at a
 * categorization type (CategoryTypeSeeder) instead of an account number, so it follows the chart of accounts:
 *
 *   rule keyword "LOVE'S"  ->  type "Fuel"  ->  6800 Vehicle Fuel (or 5215 Fuel - Trucks for a trucking company)
 *
 * The type of a rule is found from, in order: its vendor keyword, its bank keyword, its vendor / bank category, then
 * the account code it had in the old 5-digit chart (see category_types.json). Rules with no type (payment apps such as
 * Venmo, which always need a person) are left out.
 *
 * Lower priority is checked first: bank rules, then named vendors, then generic patterns, then the bank's own category.
 * Confidence values follow the original matcher; anything under 70 goes to review.
 *
 * Safe to run again: missing rules are added and existing global rules without a type get one.
 */
class CategorizationRuleSeeder extends Seeder
{
    /** @var array<string, array<string, int>> lookup tables: kind => text => category_type_id */
    private array $map = [];

    /** @var array<int, bool> category_type_id => needs review */
    private array $review = [];

    /** @var array<string, int> slug => category_type_id */
    private array $slugs = [];

    public function run(): void
    {
        if (! CategoryType::exists()) {
            $this->call(CategoryTypeSeeder::class);   // rules point at types, so the types come first
        }
        $this->buildMap();
        $rows = [];
        $skipped = 0;

        foreach ($this->load('bank_rules.json') as $r) {
            $rows[] = $this->rule('bank_rule', null, $r['keyword'], strlen($r['keyword']) <= 4 ? 'word' : 'contains',
                $r['direction'] === 'both' ? 'any' : $r['direction'], ['bank' => 'bank', 'card' => 'credit_card'][$r['applies_to']] ?? 'any',
                $this->typeFor(keyword: $r['keyword'], oldCode: $r['coa_code']), null, 20, 95, $r['notes'] ?? null);
        }

        foreach ($this->load('vendor_library.json') as $v) {
            $named = $v['tier'] === 'named';
            $rows[] = $this->rule('vendor', $v['name'], $v['keyword'], (strlen($v['keyword']) <= 4 || $v['boundary_hint']) ? 'word' : 'contains',
                'any', 'any', $this->typeFor(keyword: $v['keyword'], category: $v['category'], oldCode: $v['coa_code']), $v['category'],
                $named ? 30 : 40, $named ? (strlen($v['keyword']) >= 6 ? 90 : 84) : 72, $v['rationale'] ?? null);
        }

        foreach ($this->load('bank_category_map.json') as $c) {
            $rows[] = $this->rule('bank_category', null, $c['bank_category'], 'exact', 'any', 'any',
                $this->typeFor(category: $c['bank_category'], oldCode: $c['coa_code']), $c['bank_category'], 50, 65, $c['notes'] ?? null);
        }

        // Newer entries name their type directly ("type": "fuel"); older ones carry a code from the 5-digit chart
        foreach ($this->load('rules_added.json') as $r) {
            $bankRule = $r['source'] === 'bank_rule';
            $typeId = isset($r['type']) ? ($this->slugs[$r['type']] ?? null) : $this->typeFor(keyword: $r['keyword'], oldCode: $r['coa_code'] ?? null);
            $rows[] = $this->rule($r['source'], $r['name'] ?? null, $r['keyword'], (strlen($r['keyword']) <= 4 || ($r['boundary'] ?? false)) ? 'word' : 'contains',
                $r['direction'] ?? 'any', ['bank' => 'bank', 'card' => 'credit_card'][$r['applies_to'] ?? 'any'] ?? 'any',
                $typeId, null, $bankRule ? 20 : 30, $r['confidence'] ?? ($bankRule ? 95 : 90), $r['notes'] ?? null);
        }

        $rows = array_values(array_filter($rows, function ($row) use (&$skipped) {
            $skipped += $row['category_type_id'] ? 0 : 1;

            return (bool) $row['category_type_id'];
        }));

        // Existing global rules (same source, keyword, direction, statement kind and vendor name: one keyword can be both
        // a named vendor and a generic pattern) are not added again; ones from before categorization types get their type now
        $key = fn ($r) => implode('|', [$r['source'], mb_strtoupper($r['keyword']), $r['direction'], $r['applies_to'], (string) ($r['name'] ?? '')]);
        $existing = CategorizationRule::whereNull('company_id')->get(['id', 'source', 'name', 'keyword', 'direction', 'applies_to', 'category_type_id'])
            ->keyBy(fn ($r) => $key($r->toArray()));

        $now = now();
        $new = [];
        DB::transaction(function () use ($rows, $existing, $key, $now, &$new) {
            foreach ($rows as $row) {
                $current = $existing[$key($row)] ?? null;
                if (! $current) {
                    $new[] = $row + ['company_id' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
                } elseif (! $current->category_type_id) {
                    $current->update(['category_type_id' => $row['category_type_id'], 'account_code' => null, 'needs_review' => $row['needs_review']]);
                }
            }
            foreach (array_chunk($new, 250) as $chunk) {
                CategorizationRule::insert($chunk);
            }
        });

        $this->command?->info(count($new).' global rules added'.($skipped ? ", {$skipped} without a category type left out" : '').'.');
    }

    private function rule(string $source, ?string $name, string $keyword, string $matchType, string $direction, string $appliesTo,
        ?int $typeId, ?string $category, int $priority, int $confidence, ?string $notes): array
    {
        return [
            'source' => $source,
            'name' => $name,
            'keyword' => $keyword,
            'match_type' => $matchType,
            'direction' => $direction,
            'applies_to' => $appliesTo,
            'account_code' => null,
            'category_type_id' => $typeId,
            'category' => $category,
            'priority' => $priority,
            'confidence' => $confidence,
            'needs_review' => $typeId ? ($this->review[$typeId] ?? false) : false,
            'notes' => $notes,
        ];
    }

    private function typeFor(?string $keyword = null, ?string $category = null, ?string $oldCode = null): ?int
    {
        $upper = $keyword !== null ? mb_strtoupper($keyword) : null;

        return ($upper !== null ? ($this->map['vendor_keywords'][$upper] ?? $this->map['bank_keywords'][$upper] ?? null) : null)
            ?? ($category !== null ? ($this->map['vendor_categories'][$category] ?? $this->map['bank_categories'][$category] ?? null) : null)
            ?? ($oldCode !== null ? ($this->map['old_codes'][$oldCode] ?? null) : null);
    }

    /** category_types.json lists, keyed to the ids of the types CategoryTypeSeeder created. */
    private function buildMap(): void
    {
        $ids = CategoryType::pluck('id', 'slug');
        $this->slugs = $ids->all();
        $this->review = CategoryType::pluck('needs_review', 'id')->map(fn ($v) => (bool) $v)->all();
        foreach (json_decode(file_get_contents(database_path('data/category_types.json')), true, flags: JSON_THROW_ON_ERROR) as $type) {
            $id = $ids[$type['slug']] ?? null;
            if (! $id) {
                continue;
            }
            foreach (['vendor_keywords', 'bank_keywords'] as $list) {
                foreach ($type[$list] ?? [] as $text) {
                    $this->map[$list][mb_strtoupper($text)] = $id;
                }
            }
            foreach (['vendor_categories', 'bank_categories', 'old_codes'] as $list) {
                foreach ($type[$list] ?? [] as $text) {
                    $this->map[$list][$text] ??= $id;
                }
            }
        }
    }

    private function load(string $file): array
    {
        return json_decode(file_get_contents(database_path("data/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
