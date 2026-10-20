<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\Report;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Add-on reports are extra prices on the company's existing Stripe subscription, so there is one bill a month.
 * Adding one charges the prorated amount right away. Payments are non-refundable: removing one stops the next
 * charges with no credit, and the report stays open until the end of the month already paid for.
 * (Tests swap this class for a fake so they never call Stripe.)
 */
class AddonBilling
{
    /**
     * @return string the Stripe subscription item id
     *
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment when the bank asks the customer to confirm (3-D Secure)
     */
    public function add(Company $company, Report $report): string
    {
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        if (! $subscription?->valid()) {
            throw new RuntimeException('Your plan is not active, so add-ons cannot be added.');
        }

        $subscription->addPriceAndInvoice($report->stripe_price_id);

        return $subscription->findItemOrFail($report->stripe_price_id)->stripe_id;
    }

    /**
     * Stops charging for the add-on, without a refund or credit.
     *
     * @return CarbonImmutable|null the end of the month already paid for (the report stays open until then)
     */
    public function remove(Company $company, CompanyAddon $addon): ?CarbonImmutable
    {
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        [, $paidUntil] = AddonQuote::billingPeriod($company);

        // The price the customer actually bought (the catalog price may have changed since)
        $price = $subscription?->items()->where('stripe_id', $addon->stripe_subscription_item_id)->value('stripe_price');

        if ($subscription && $price && $subscription->hasPrice($price)) {
            $subscription->noProrate()->removePrice($price);
        }

        return $paidUntil;
    }
}
