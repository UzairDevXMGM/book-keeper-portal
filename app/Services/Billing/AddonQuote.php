<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Report;
use App\Services\Reports\ReportAccess;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What adding an add-on costs this business: the monthly price, the plan's price and the new monthly total, an
 * estimate of today's prorated charge and the next billing date (read from Stripe when it answers; without Stripe the
 * page still shows the monthly prices), and the card on file.
 */
class AddonQuote
{
    /**
     * @return array{price: float, plan_name: ?string, plan_price: float, addons_price: float, new_monthly: float,
     *               due_today: ?float, next_billing: ?CarbonImmutable, card: ?string}
     */
    public static function for(Company $company, Report $report): array
    {
        $plan = ReportAccess::planFor($company);
        $price = (float) $report->addon_price;
        $addons = (float) $company->addons()->renewing()->sum('price');
        [$start, $end] = self::billingPeriod($company);

        $dueToday = null;
        if ($start && $end && $end->gt($start)) {
            $left = max(0, now()->diffInSeconds($end, false));
            $dueToday = round($price * $left / $start->diffInSeconds($end), 2);
        }

        return [
            'price' => $price,
            'plan_name' => $plan?->name,
            'plan_price' => (float) ($plan?->price ?? 0),
            'addons_price' => $addons,
            'new_monthly' => round((float) ($plan?->price ?? 0) + $addons + $price, 2),
            'due_today' => $dueToday,
            'next_billing' => $end,
            'card' => $company->pm_last_four ? ucfirst((string) $company->pm_type).' •••• '.$company->pm_last_four : null,
        ];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} the current billing period from Stripe, or nulls */
    /** Start and end of the billing month the company is in now (nulls when Stripe cannot tell). */
    public static function billingPeriod(Company $company): array
    {
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        if (! $subscription || blank(config('cashier.secret')) || ! str_starts_with((string) $subscription->stripe_id, 'sub_')) {
            return [null, null];
        }
        try {
            $stripe = $subscription->asStripeSubscription();
            // Newer Stripe API versions keep the period on the subscription items
            $start = $stripe->current_period_start ?? ($stripe->items->data[0]->current_period_start ?? null);
            $end = $stripe->current_period_end ?? ($stripe->items->data[0]->current_period_end ?? null);

            return [$start ? CarbonImmutable::createFromTimestamp($start) : null, $end ? CarbonImmutable::createFromTimestamp($end) : null];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
