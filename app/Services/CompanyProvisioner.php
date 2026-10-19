<?php

namespace App\Services;

use App\Models\AccountTemplate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Creates a new business for a user and gives it the master chart of accounts plus its industry's own accounts. */
class CompanyProvisioner
{
    private const TEMPLATE_FIELDS = [
        'code', 'name', 'type', 'subtype', 'section', 'report_line', 'normal_balance',
        'is_bank_account', 'is_credit_card', 'system_key', 'description',
    ];

    public function create(User $owner, array $attributes): Company
    {
        return DB::transaction(function () use ($owner, $attributes) {
            $company = $owner->companies()->create($attributes);
            $this->addAccounts($company);

            return $company;
        });
    }

    /**
     * Gives a company the master chart plus its industry's accounts. Accounts it already has (same code) are left
     * as they are, so this also tops up a company whose industry changed.
     */
    public function addAccounts(Company $company): int
    {
        $now = now();
        $existing = $company->accounts()->pluck('code')->flip();
        $accounts = AccountTemplate::forIndustry($company->industry)
            ->get()
            ->reject(fn (AccountTemplate $t) => isset($existing[$t->code]))
            ->sortBy(fn (AccountTemplate $t) => (int) $t->code)   // industry accounts slot between master codes
            ->map(fn (AccountTemplate $t) => [
                'company_id' => $company->id,
                'canonical_code' => $t->code,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $t->only(self::TEMPLATE_FIELDS))
            ->values();

        foreach ($accounts->chunk(100) as $chunk) {
            $company->accounts()->insert($chunk->values()->all());
        }

        return $accounts->count();
    }
}
