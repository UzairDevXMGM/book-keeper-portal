<?php

namespace Database\Seeders;

use App\Models\AccountTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts library from the client's "Chart of Accounts Library - Pawn Shop & 120+ Industries" workbook
 * (database/data/coa/): the master chart (4-digit codes) every company gets, plus each industry's own accounts.
 * Three accounts the app needs and the workbook does not have are added: transfers clearing and the two
 * suspense accounts that hold lines waiting for review.
 *
 * The workbook's Account Class and Category Head become the type, section and report line the reports use.
 * Safe to run again: the library is replaced as a whole. Existing companies keep the accounts they have.
 */
class AccountTemplateSeeder extends Seeder
{
    /** Accounts the application looks up by role instead of by number. */
    private const SYSTEM_KEYS = [
        '1030' => 'default_bank',
        '1100' => 'accounts_receivable',
        '2000' => 'accounts_payable',
        '2020' => 'credit_card_payable',
        '3110' => 'owner_draws',
        '3200' => 'retained_earnings',
        '3900' => 'opening_balance_equity',
        '1495' => 'transfers_clearing',
        '6599' => 'suspense_expense',
        '8099' => 'suspense_income',
    ];

    /** Accounts that take bank / card statement uploads. */
    private const BANK_ACCOUNTS = ['1030', '1040', '1050'];
    private const CREDIT_CARDS = ['2020'];

    /** Accounts added by the app (codes the library leaves free). */
    private const APP_ACCOUNTS = [
        ['code' => '1495', 'name' => 'Transfers Clearing (In Transit)', 'class' => 'Current Asset', 'head' => 'Transfers Clearing', 'normal_balance' => 'debit', 'contra' => false,
            'description' => 'Money moving between the business\'s own bank and card accounts. Both sides of a transfer post here, so it returns to zero.'],
        ['code' => '6599', 'name' => 'Uncategorized Expense (Suspense - Review)', 'class' => 'Operating Expense', 'head' => 'Uncategorized', 'normal_balance' => 'debit', 'contra' => false,
            'description' => 'Money out that has not been categorized yet. Clear it from the review queue.'],
        ['code' => '8099', 'name' => 'Uncategorized Income (Suspense - Review)', 'class' => 'Other Income', 'head' => 'Uncategorized', 'normal_balance' => 'credit', 'contra' => false,
            'description' => 'Money in that has not been categorized yet. Clear it from the review queue.'],
    ];

    public function run(): void
    {
        $master = array_merge($this->load('master.json'), self::APP_ACCOUNTS);
        usort($master, fn ($a, $b) => (int) $a['code'] <=> (int) $b['code']);
        $industry = $this->load('industry_accounts.json');

        $now = now();
        $rows = [];
        foreach ($master as $i => $account) {
            $rows[] = $this->row($account, null, $i) + ['created_at' => $now, 'updated_at' => $now];
        }
        foreach ($industry as $i => $account) {
            $rows[] = $this->row($account, $account['industry'], 10000 + $i) + ['created_at' => $now, 'updated_at' => $now];
        }

        // Templates are only copied when a company is created (accounts keep the code in canonical_code, nothing
        // links back by id), so the library is simply replaced. Master rows have a null industry, which a unique
        // index does not compare, so an upsert would duplicate them.
        DB::transaction(function () use ($rows) {
            AccountTemplate::query()->delete();
            foreach (array_chunk($rows, 250) as $chunk) {
                AccountTemplate::insert($chunk);
            }
        });
    }

    private function row(array $account, ?string $industry, int $position): array
    {
        $class = $account['class'];
        $head = $account['head'];
        [$type, $section] = $this->typeAndSection($class, $head);
        $code = (string) $account['code'];
        $isMaster = $industry === null;

        // The report line is the workbook's category head, except where the reports need a finer line
        $reportLine = match (true) {
            $isMaster && in_array($code, self::CREDIT_CARDS, true) => 'Credit Cards Payable',
            (bool) preg_match('/^interest expense\b/i', $account['name']) => 'Interest Expense',
            default => $head,
        };

        return [
            'industry' => $industry,
            'code' => $code,
            'name' => $account['name'],
            'type' => $type,
            'subtype' => $account['contra'] ? 'Contra' : null,
            'section' => $section,
            'report_line' => mb_substr($reportLine, 0, 120),
            'normal_balance' => $account['normal_balance'] === 'credit' ? 'credit' : 'debit',
            'is_bank_account' => $isMaster && in_array($code, self::BANK_ACCOUNTS, true),
            'is_credit_card' => $isMaster && in_array($code, self::CREDIT_CARDS, true),
            'system_key' => $isMaster ? (self::SYSTEM_KEYS[$code] ?? null) : null,
            'description' => $account['description'] ?? null,
            'sort_order' => $position * 10,
        ];
    }

    /** Workbook Account Class (+ Category Head) -> [type, section] used by the statements. */
    private function typeAndSection(string $class, string $head): array
    {
        return match ($class) {
            'Current Asset' => ['asset', 'Current Assets'],
            'Non-current Asset' => ['asset', str_starts_with($head, 'Property, Plant') || str_starts_with($head, 'Accumulated Depreciation') ? 'Fixed Assets' : 'Other Assets'],
            'Current Liability' => ['liability', 'Current Liabilities'],
            'Non-current Liability' => ['liability', 'Long-Term Liabilities'],
            'Equity' => ['equity', 'Equity'],
            'Revenue' => ['income', 'Revenue'],
            'Cost of Sales' => ['cost_of_sales', 'Cost of Sales'],
            'Operating Expense' => ['expense', str_starts_with($head, 'Depreciation') ? 'Depreciation & Amortization' : 'Operating Expenses'],
            'Other Income' => ['other_income', 'Other Income'],
            'Other Expense' => ['other_expense', 'Other Expenses'],
            'Income Tax' => ['other_expense', 'Income Taxes'],
        };
    }

    private function load(string $file): array
    {
        return json_decode(file_get_contents(database_path("data/coa/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
