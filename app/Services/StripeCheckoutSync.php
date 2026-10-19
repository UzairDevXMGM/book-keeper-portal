<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Stripe\Checkout\Session;

/**
 * Saves the subscription from a completed Stripe Checkout session as soon as the customer returns, so access does
 * not wait for the webhook (and works locally without one). Cashier's webhook does the same upsert later, keyed on
 * the Stripe subscription id, so running both is safe.
 */
class StripeCheckoutSync
{
    /** Returns the checkout session when it gave the company a valid subscription, otherwise null. */
    public function sync(Company $company, string $sessionId): ?Session
    {
        $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);

        // Only accept a finished subscription checkout that belongs to this company's Stripe customer
        if ($session->mode !== 'subscription' || $session->status !== 'complete' || ! $session->subscription
            || ! $company->stripe_id || $session->customer !== $company->stripe_id) {
            return null;
        }

        $stripeSubscription = $session->subscription;
        $items = $stripeSubscription->items->data;
        $singlePrice = count($items) === 1;

        $subscription = $company->subscriptions()->updateOrCreate(['stripe_id' => $stripeSubscription->id], [
            'type' => $stripeSubscription->metadata['type'] ?? Company::SUBSCRIPTION,
            'stripe_status' => $stripeSubscription->status,
            'stripe_price' => $singlePrice ? $items[0]->price->id : null,
            'quantity' => $singlePrice ? ($items[0]->quantity ?? null) : null,
            'trial_ends_at' => $stripeSubscription->trial_end ? Carbon::createFromTimestamp($stripeSubscription->trial_end) : null,
            'ends_at' => null,
        ]);

        foreach ($items as $item) {
            $subscription->items()->updateOrCreate(['stripe_id' => $item->id], [
                'stripe_product' => $item->price->product,
                'stripe_price' => $item->price->id,
                'quantity' => $item->quantity ?? null,
            ]);
        }

        // Keep the city from the billing address the customer just entered on Stripe
        if (! $company->city && ($city = $session->customer_details->address->city ?? null)) {
            $company->update(['city' => $city]);
        }

        return $subscription->valid() ? $session : null;
    }

    /** The first invoice the checkout produced. */
    public static function invoiceId(Session $session): ?string
    {
        $invoice = $session->invoice ?? $session->subscription->latest_invoice ?? null;

        return is_string($invoice) ? $invoice : $invoice?->id;
    }
}
