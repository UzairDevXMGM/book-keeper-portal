<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\Plan;
use App\Models\Report;
use Illuminate\Support\Collection;

/** Which catalog reports a company can open: the ones in its plan plus add-ons it has bought. */
class ReportAccess
{
    /** @return Collection<int, array{report: Report, included: bool, purchased: bool, has_access: bool, ready: bool}> */
    public static function for(Company $company): Collection
    {
        $plan = self::planFor($company);
        $included = $plan ? $plan->reports()->pluck('reports.id')->all() : [];
        $purchased = $company->addons()
            ->where('status', CompanyAddon::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('report_id')->all();

        return Report::where('is_active', true)->orderBy('sort_order')->get()->map(fn (Report $report) => [
            'report' => $report,
            'included' => in_array($report->id, $included, true),
            'purchased' => in_array($report->id, $purchased, true),
            'has_access' => in_array($report->id, $included, true) || in_array($report->id, $purchased, true),
            'ready' => ReportRunner::supports($report->code),
        ]);
    }

    /** Report codes that also unlock pieces outside the reports pages (dashboard cards, ratio alerts). */
    public const RATIOS = ['ACC-30', 'ACC-31'];     // Financial Ratios, KPI Dashboard
    public const HEALTH = ['PRT-07'];               // Financial Health Score

    /** True when the company can open at least one of these reports (in its plan or bought as an add-on). */
    public static function hasAny(Company $company, array $codes): bool
    {
        return self::for($company)->contains(fn (array $item) => $item['has_access'] && in_array($item['report']->code, $codes, true));
    }

    public static function allows(Company $company, Report $report): bool
    {
        $entry = self::for($company)->first(fn (array $item) => $item['report']->is($report));

        return $entry['has_access'] ?? false;
    }

    /**
     * The plan behind the company's active subscription (falls back to the default plan). Once add-ons are added the
     * subscription has several prices, so the plan is found among the subscription's items.
     */
    public static function planFor(Company $company): ?Plan
    {
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        $prices = $subscription
            ? collect([$subscription->stripe_price])->merge($subscription->items->pluck('stripe_price'))->filter()->unique()
            : collect();

        return ($prices->isNotEmpty() ? Plan::whereIn('stripe_price_id', $prices)->first() : null) ?? Plan::default();
    }
}
