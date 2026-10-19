<?php

use App\Models\Company;
use App\Services\Import\Categorizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lines imported before categories were stored on them: take the category of the rule that matched, as long as the
 * line still sits in the account that category uses for its company (a line changed by hand keeps no category).
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyIds = DB::table('import_rows')->whereNull('category_type_id')->whereNotNull('rule_id')->distinct()->pluck('company_id');
        foreach ($companyIds as $companyId) {
            $company = Company::find($companyId);
            if (! $company) {
                continue;
            }
            $typeAccounts = collect((new Categorizer($company))->categoryChoices())->pluck('account_id', 'id');
            $rows = DB::table('import_rows as r')
                ->join('categorization_rules as c', 'c.id', '=', 'r.rule_id')
                ->where('r.company_id', $companyId)->whereNull('r.category_type_id')->whereNotNull('c.category_type_id')
                ->get(['r.id', 'r.account_id', 'c.category_type_id']);
            foreach ($rows as $row) {
                if ((int) ($typeAccounts[$row->category_type_id] ?? 0) === (int) $row->account_id) {
                    DB::table('import_rows')->where('id', $row->id)->update(['category_type_id' => $row->category_type_id]);
                }
            }
        }
    }

    public function down(): void
    {
        // categories stay; the column itself is dropped by the previous migration's rollback
    }
};
