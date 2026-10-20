<?php

namespace App\Services\Billing;

use Laravel\Cashier\Cashier;
use Stripe\Exception\CardException;
use Stripe\Subscription as StripeSubscription;

/**
 * Takes the first payment on the sign-up page before any account exists: creates the Stripe customer, saves the
 * card and starts the subscription. The account is created afterwards, only when this reports "paid".
 * (Tests swap this class for a fake so they never call Stripe.)
 *
 * Every method answers the same plain array:
 *   status        paid | action (the bank wants the customer to approve) | declined
 *   customer, subscription, invoice   Stripe ids
 *   secret        client secret for the bank's approval window (status "action")
 *   message       why the card was declined (status "declined")
 *   card          ['brand' => 'visa', 'last4' => '4242']
 *   items         [['id', 'product', 'price', 'quantity']] of the subscription
 *   sub_status    Stripe's own subscription status
 */
class SignupBilling
{
    /**
     * @param  array{email: string, name: string, phone: ?string, address: array<string, ?string>}  $customer
     */
    public function start(string $priceId, string $paymentMethod, array $customer): array
    {
        $stripe = Cashier::stripe();

        $stripeCustomer = $stripe->customers->create(array_filter([
            'email' => $customer['email'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? null,
            'address' => array_filter($customer['address']),
        ]));

        try {
            $stripe->paymentMethods->attach($paymentMethod, ['customer' => $stripeCustomer->id]);
        } catch (CardException $e) {
            $stripe->customers->delete($stripeCustomer->id);

            return ['status' => 'declined', 'message' => $e->getMessage() ?: 'Your card was declined.'];
        }
        $stripe->customers->update($stripeCustomer->id, ['invoice_settings' => ['default_payment_method' => $paymentMethod]]);

        // allow_incomplete charges the card right away; when the bank wants an approval the subscription waits
        $subscription = $stripe->subscriptions->create([
            'customer' => $stripeCustomer->id,
            'items' => [['price' => $priceId]],
            'default_payment_method' => $paymentMethod,
            'payment_behavior' => 'allow_incomplete',
            'metadata' => ['type' => 'default', 'name' => 'default'],
            'expand' => ['latest_invoice.confirmation_secret'],
        ]);

        return $this->describe($subscription, $paymentMethod);
    }

    /** Looks at the subscription again, after the customer answered the bank's approval window. */
    public function check(string $subscriptionId, string $paymentMethod): array
    {
        $subscription = Cashier::stripe()->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice.confirmation_secret']]);

        return $this->describe($subscription, $paymentMethod);
    }

    /** Gives up on a sign-up that was not paid: no charge is left behind and the customer is removed. */
    public function abandon(?string $subscriptionId, ?string $customerId): void
    {
        $stripe = Cashier::stripe();
        if ($subscriptionId) {
            $stripe->subscriptions->cancel($subscriptionId);
        }
        if ($customerId) {
            $stripe->customers->delete($customerId);
        }
    }

    private function describe(StripeSubscription $subscription, string $paymentMethod): array
    {
        $invoice = $subscription->latest_invoice;
        $secret = is_object($invoice) ? ($invoice->confirmation_secret->client_secret ?? null) : null;

        $status = in_array($subscription->status, ['active', 'trialing'], true) ? 'paid' : 'declined';
        $message = null;
        if ($status !== 'paid' && $secret) {
            // The client secret is "pi_..._secret_...": the payment behind the first invoice
            $intent = Cashier::stripe()->paymentIntents->retrieve(strstr($secret, '_secret_', true));
            if (in_array($intent->status, ['requires_action', 'requires_confirmation'], true)) {
                $status = 'action';
            } else {
                $message = $intent->last_payment_error->message ?? null;
            }
        }

        $card = Cashier::stripe()->paymentMethods->retrieve($paymentMethod)->card ?? null;

        return [
            'status' => $status,
            'sub_status' => $subscription->status,
            'customer' => is_string($subscription->customer) ? $subscription->customer : $subscription->customer->id,
            'subscription' => $subscription->id,
            'invoice' => is_object($invoice) ? $invoice->id : $invoice,
            'secret' => $status === 'action' ? $secret : null,
            'message' => $message ?? ($status === 'declined' ? 'Your card was declined. Please check the details or try another card.' : null),
            'card' => ['brand' => $card->brand ?? 'card', 'last4' => $card->last4 ?? null],
            'items' => array_map(fn ($item) => [
                'id' => $item->id,
                'product' => is_string($item->price->product) ? $item->price->product : $item->price->product->id,
                'price' => $item->price->id,
                'quantity' => $item->quantity ?? 1,
            ], $subscription->items->data),
        ];
    }
}
