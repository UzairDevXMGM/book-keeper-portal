<?php

namespace Database\Seeders;

use App\Models\Report;
use Illuminate\Database\Seeder;

/**
 * Report catalog. Codes match the client's Master Consolidated Accounting Workbook.
 * Only reports that can be built from the ledger alone are listed for now.
 * Add-on prices are placeholders until the client confirms pricing.
 */
class ReportSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            // Included in the Basic plan (see PlanSeeder)
            ['ACC-22', 'General Ledger', 'Core financial statements', 0, 'Every posted transaction grouped by account, with a running balance, for the dates you pick.'],
            ['ACC-21', 'Trial Balance', 'Core financial statements', 0, 'Debit and credit balance of every account on a date; proves the books are in balance.'],
            ['ACC-26', 'Profit & Loss', 'Core financial statements', 0, 'Income minus cost of sales and expenses for the period.'],
            ['ACC-27', 'Balance Sheet', 'Core financial statements', 0, 'Assets, liabilities and equity on a date.'],

            // Paid add-ons
            ['ACC-28', 'Statement of Cash Flows', 'Financial statements', 10, 'Where cash came from and went: operating, investing and financing.'],
            ['PRT-01', 'Comparative Profit & Loss', 'Financial statements', 20, 'P&L against the prior period and prior year, with $ and % change.'],
            ['PRT-02', 'Common-Size Profit & Loss', 'Financial statements', 20, 'Every P&L line as a percentage of revenue.'],
            ['PRT-03', 'Common-Size Balance Sheet', 'Financial statements', 20, 'Every balance sheet line as a percentage of total assets.'],
            ['BKT-11', 'Monthly Trend', 'Spending analysis', 10, 'Month-by-month totals by category to spot trends and seasonality.'],
            ['BKT-09', 'Category Spending', 'Spending analysis', 10, 'Total spend by expense category.'],
            ['BKT-10', 'Vendor Spending', 'Spending analysis', 10, 'Total spend by vendor.'],
            ['BKT-07', 'Account Activity', 'Spending analysis', 10, 'Every transaction that hit a selected account.'],
            ['ACC-30', 'Financial Ratios', 'Analysis & KPIs', 20, 'Liquidity, leverage and profitability ratios.'],
            ['ACC-31', 'KPI Dashboard', 'Analysis & KPIs', 20, 'One-page summary of revenue, expenses, cash and key ratios.'],
            ['PRT-07', 'Financial Health Score', 'Analysis & KPIs', 20, 'Score from 0 to 100 built from liquidity, profitability, leverage and data quality.'],
        ];

        foreach ($reports as $i => [$code, $name, $category, $price, $description]) {
            Report::updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'addon_price' => $price,
                'description' => $description,
                'is_active' => true,
                'sort_order' => ($i + 1) * 10,
            ]);
        }
    }
}
