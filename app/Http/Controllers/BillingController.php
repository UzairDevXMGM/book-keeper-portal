<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Services\InvoiceMailer;
use App\Services\StripeCheckoutSync;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

class BillingController extends Controller
{
    /**
     * Payment page shown to signed-in users who have not paid yet (or whose plan lapsed). The card is typed into
     * Stripe's Payment Element on this page: the card number goes straight from the browser to Stripe, never here.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.overview');   // the platform admin has no plan to pay for
        }
        $company = $this->company($request);
        if ($company->hasActivePlan()) {
            // Already paying: plan, card, add-ons and invoices live under My profile → Plan & billing
            return redirect()->to(route('profile.show').'#billing');
        }

        $plan = Plan::default()?->load('reports');
        $clientSecret = null;
        $error = null;
        if ($this->stripeReady() && $plan?->stripe_price_id) {
            try {
                // A SetupIntent lets Stripe check the card (and run the bank's 3-D Secure step) before it is charged
                $company->createOrGetStripeCustomer();
                $clientSecret = $company->createSetupIntent(['payment_method_types' => ['card'], 'usage' => 'off_session'])->client_secret;
            } catch (ApiErrorException $e) {
                Log::error('Stripe setup intent failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                $error = 'We could not reach the payment provider. Please refresh the page in a moment.';
            }
        }

        return view('billing.show', [
            'company' => $company,
            'plan' => $plan,
            'stripeKey' => config('cashier.key'),
            'clientSecret' => $clientSecret,
            'paymentError' => $error,
        ]);
    }

    /**
     * Called by the payment page once Stripe has accepted the card: saves it as the company's card and starts the
     * subscription. Answers JSON: a redirect when paid, a client secret when the bank wants one more confirmation.
     */
    public function subscribe(Request $request, InvoiceMailer $invoices): JsonResponse
    {
        $company = $this->company($request);
        if ($company->hasActivePlan()) {
            return response()->json(['redirect' => route('dashboard')]);
        }
        $plan = Plan::default();
        if (! $this->stripeReady() || ! $plan?->stripe_price_id) {
            return response()->json(['message' => 'Online payment is not set up yet. Please try again later.'], 503);
        }

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:100', 'regex:/^pm_[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:200'],
            'address.line1' => ['required', 'string', 'max:200'],
            'address.line2' => ['nullable', 'string', 'max:200'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.state' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country' => ['required', 'string', 'size:2'],
        ]);

        try {
            // An earlier try that stopped half way (declined card, closed 3-D Secure window) is replaced, not kept
            $this->cancelIncomplete($company);

            // The billing address goes on the Stripe customer, so every invoice carries it
            $company->createOrGetStripeCustomer();
            $company->updateStripeCustomer(['name' => $data['name'], 'address' => array_filter($data['address'])]);
            $company->updateDefaultPaymentMethod($data['payment_method']);

            $subscription = $company->newSubscription(Company::SUBSCRIPTION, $plan->stripe_price_id)->create($data['payment_method']);
        } catch (IncompletePayment $e) {
            if ($e->payment->requiresAction()) {
                // The bank wants the customer to approve the charge (3-D Secure): the page opens Stripe's window
                return response()->json(['confirm' => $e->payment->clientSecret()]);
            }
            $this->cancelIncomplete($company);

            return response()->json(['message' => 'Your card was declined. Please check the details or try another card.'], 422);
        } catch (CardException $e) {
            $this->cancelIncomplete($company);

            return response()->json(['message' => $e->getMessage() ?: 'Your card was declined. Please try another card.'], 422);
        } catch (ApiErrorException $e) {
            Log::error('Stripe subscription failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not reach the payment provider. Please try again in a moment.'], 502);
        }

        $this->started($company, $subscription, $data['address']['city'] ?? null, $invoices);

        return response()->json(['redirect' => route('dashboard')]);
    }

    /** After the customer approved the charge in the bank's window, checks with Stripe that it was paid. */
    public function confirm(Request $request, InvoiceMailer $invoices): JsonResponse
    {
        $company = $this->company($request);
        if ($company->hasActivePlan()) {
            return response()->json(['redirect' => route('dashboard')]);
        }

        $subscription = $company->subscriptions()->where('type', Company::SUBSCRIPTION)
            ->where('stripe_status', 'incomplete')->latest()->first();
        if (! $subscription) {
            return response()->json(['message' => 'We could not find this payment. Please try again.'], 422);
        }

        try {
            $subscription->syncStripeStatus();
        } catch (ApiErrorException $e) {
            Log::error('Stripe confirm failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not reach the payment provider. Please refresh in a moment.'], 502);
        }

        if (! $subscription->valid()) {
            $this->cancelIncomplete($company);

            return response()->json(['message' => 'The payment was not approved. Please try again or use another card.'], 422);
        }

        $this->started($company, $subscription, null, $invoices);

        return response()->json(['redirect' => route('dashboard')]);
    }

    /** Old link from before the card form moved onto our own page. */
    public function checkout(): RedirectResponse
    {
        return redirect()->route('billing.show');
    }

    /** Stripe Checkout return page, kept for anyone who started a hosted checkout before the change. */
    public function success(Request $request, StripeCheckoutSync $sync, InvoiceMailer $invoices): RedirectResponse
    {
        $company = $this->company($request);
        $sessionId = (string) $request->query('session_id', '');
        $session = null;

        try {
            $session = $sessionId !== '' ? $sync->sync($company, $sessionId) : null;

            // Email the first invoice now; the webhook would also send it, but only one of the two will
            if ($session) {
                Audit::log('billing.subscribed', $company, ['checkout_session' => $session->id]);
            }
            if ($session && ($invoiceId = StripeCheckoutSync::invoiceId($session))) {
                $invoices->sendById($company, $invoiceId);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout sync failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
        }

        if ($session || $company->fresh()->hasActivePlan()) {
            return redirect()->route('dashboard')->with('status', 'Payment received. Your invoice is on its way to '.$company->owner->email.'.');
        }

        return redirect()->route('billing.show')
            ->with('error', 'We have not received the payment confirmation yet. If you were charged, refresh this page in a minute.');
    }

    /** Stripe's hosted page to update the card, download invoices or cancel. */
    public function portal(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        abort_unless($company->hasStripeId(), 404);

        return $company->redirectToBillingPortal(route('dashboard'));
    }

    /** The plan is paid: log it, keep the city, email the first invoice and greet the customer on the dashboard. */
    private function started(Company $company, Subscription $subscription, ?string $city, InvoiceMailer $invoices): void
    {
        Audit::log('billing.subscribed', $company, ['subscription' => $subscription->stripe_id]);
        if ($city && ! $company->city) {
            $company->update(['city' => $city]);
        }

        try {
            $invoice = $subscription->asStripeSubscription()->latest_invoice;
            $invoiceId = is_string($invoice) ? $invoice : $invoice?->id;
            if ($invoiceId) {
                $invoices->sendById($company, $invoiceId);
            }
        } catch (ApiErrorException $e) {
            // The webhook sends the invoice email as well, so a miss here is not fatal
            Log::warning('First invoice email skipped', ['company_id' => $company->id, 'error' => $e->getMessage()]);
        }

        session()->flash('status', 'Payment received. Your invoice is on its way to '.$company->owner->email.'.');
    }

    /** Drops a subscription attempt that never got paid (declined card or a closed bank window). */
    private function cancelIncomplete(Company $company): void
    {
        $company->subscriptions()->where('type', Company::SUBSCRIPTION)->where('stripe_status', 'incomplete')->get()
            ->each(function (Subscription $subscription) {
                try {
                    $subscription->cancelNow();
                } catch (ApiErrorException) {
                    $subscription->markAsCanceled();   // already gone on Stripe's side
                }
            });
    }

    private function stripeReady(): bool
    {
        return filled(config('cashier.key')) && filled(config('cashier.secret'));
    }
}
