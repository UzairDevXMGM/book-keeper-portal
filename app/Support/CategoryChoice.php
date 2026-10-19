<?php

namespace App\Support;

use App\Models\Company;
use App\Services\Import\Categorizer;

/**
 * What a category picker sends: "type:12" (a category, posted to the account it uses for this company) or an
 * account id. Used by "Check categories" and by changing a category on the Transactions page.
 */
class CategoryChoice
{
    public const RULE = 'regex:/^(type:)?\d{1,10}$/';

    /** @var array<int, array<int, array>> category choices per company for this request */
    private static array $choices = [];

    /** @return array{0: int, 1: ?int}|null [account id, category type id or null]; null when it is not one of the company's */
    public static function resolve(Company $company, string $value): ?array
    {
        if (preg_match('/^type:(\d+)$/', $value, $m)) {
            self::$choices[$company->id] ??= (new Categorizer($company))->categoryChoices();
            $type = self::$choices[$company->id][(int) $m[1]] ?? null;

            return $type ? [$type['account_id'], $type['id']] : null;
        }
        $accountId = ctype_digit($value) ? (int) $value : 0;

        return $company->accounts()->where('is_active', true)->whereKey($accountId)->exists() ? [$accountId, null] : null;
    }

    /** Category picker options: plain categories first, then every account (sorted by name). */
    public static function choices(Company $company): array
    {
        return collect((new Categorizer($company))->categoryChoices())->sortBy('name')->values()->all();
    }
}
