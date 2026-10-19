<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Report;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /** Reports included in the Basic plan. Every other report is a paid add-on. */
    public const BASIC_REPORTS = ['ACC-22', 'ACC-21', 'ACC-26', 'ACC-27'];

    public function run(): void
    {
        $basic = Plan::updateOrCreate(['slug' => 'basic'], [
            'name' => 'Basic',
            'description' => 'General Ledger, Trial Balance, Profit & Loss and Balance Sheet from your uploaded data. Other reports are available as add-ons.',
            'price' => 25,
            'billing_interval' => 'month',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $basic->reports()->sync(Report::whereIn('code', self::BASIC_REPORTS)->pluck('id'));
    }
}
