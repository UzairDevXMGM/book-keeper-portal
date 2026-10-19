<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Report;

/** Maps catalog codes to the engine that builds them. Reports without an engine show as "coming soon". */
class ReportRunner
{
    /** Core statements (FinancialStatements). */
    private const ENGINES = [
        'ACC-22' => 'generalLedger',
        'ACC-21' => 'trialBalance',
        'ACC-26' => 'profitAndLoss',
        'ACC-27' => 'balanceSheet',
    ];

    /** Add-on reports (AddonReports). */
    private const ADDON_ENGINES = [
        'ACC-28' => 'cashFlow',
        'PRT-01' => 'comparativeProfitAndLoss',
        'PRT-02' => 'commonSizeProfitAndLoss',
        'PRT-03' => 'commonSizeBalanceSheet',
        'BKT-11' => 'monthlyTrend',
        'BKT-09' => 'categorySpending',
        'BKT-10' => 'vendorSpending',
        'BKT-07' => 'accountActivity',
        'ACC-30' => 'financialRatios',
        'ACC-31' => 'kpiDashboard',
        'PRT-07' => 'healthScore',
    ];

    /** Reports that offer a comparison column. */
    public const COMPARABLE = ['ACC-26', 'ACC-27', 'PRT-01'];

    /** Reports that are a snapshot on one date rather than activity over a range. */
    public const AS_OF = ['ACC-21', 'ACC-27', 'PRT-03'];

    /** Reports with an account picker. */
    public const ACCOUNT_FILTER = ['ACC-22', 'BKT-07'];

    /**
     * Extra filters per report: through = bank / card the money went through, q = search text,
     * category = one cost account, group = cost type. Snapshot and whole-business reports have none.
     */
    public const FILTERS = [
        'ACC-22' => ['through', 'q'],
        'BKT-07' => ['through', 'q'],
        'ACC-26' => ['through'],
        'PRT-01' => ['through'],
        'PRT-02' => ['through'],
        'BKT-09' => ['through', 'group'],
        'BKT-10' => ['through', 'category', 'q'],
        'BKT-11' => ['through'],
    ];

    /** Cost types offered by the "Type" filter. */
    public const GROUPS = ['cost_of_sales' => 'Cost of sales', 'expense' => 'Operating expenses', 'other_expense' => 'Other expenses'];

    public static function supports(string $code): bool
    {
        return array_key_exists($code, self::ENGINES) || array_key_exists($code, self::ADDON_ENGINES);
    }

    public static function run(Company $company, Report $report, Period $period, array $options = []): ReportDocument
    {
        $accountId = $options['account_id'] ?? null;
        // Only the filters this report offers are applied
        $filters = array_intersect_key($options, array_flip(self::FILTERS[$report->code] ?? []));

        if ($method = self::ADDON_ENGINES[$report->code] ?? null) {
            $reports = new AddonReports($company, $filters);

            return $method === 'accountActivity' ? $reports->accountActivity($period, $accountId) : $reports->{$method}($period);
        }

        $statements = (new FinancialStatements($company))->through($filters['through'] ?? null)->search($filters['q'] ?? null);
        $method = self::ENGINES[$report->code];

        return $method === 'generalLedger' ? $statements->generalLedger($period, $accountId) : $statements->{$method}($period);
    }
}
