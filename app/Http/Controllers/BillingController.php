<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Services\InvoiceMailer;
use App\Services\StripeCheckoutSync;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Stripe\Exception\ApiErrorException;

class BillingController extends Controller
{
    /** Plan page shown to signed-in users who have not paid yet (or whose plan lapsed). */
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

        return view('billing.show', [
            'company' => $company,
            'plan' => Plan::default()?->load('reports'),
            'stripeReady' => $this->stripeReady(),
        ]);
    }

    /** Sends the user to Stripe Checkout to subscribe the company to the default plan. */
    public function checkout(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        if ($company->hasActivePlan()) {
            return redirect()->route('dashboard');
        }

        $plan = Plan::default();
        if (! $this->stripeReady() || ! $plan?->stripe_price_id) {
            return redirect()->route('billing.show')
                ->with('error', 'Online payment is not set up yet. Please try again later.');
        }

        try {
            return $company->newSubscription(Company::SUBSCRIPTION, $plan->stripe_price_id)->checkout([
                // {CHECKOUT_SESSION_ID} is filled in by Stripe, so it is appended by hand rather than url-encoded
                'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('billing.show', ['cancelled' => 1]),
                'client_reference_id' => (string) $company->id,
                // Stripe asks for the full billing address and saves it on the customer, so invoices carry it
                'billing_address_collection' => 'required',
                'customer_update' => ['address' => 'auto'],
            ])->redirect();
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return redirect()->route('billing.show')
                ->with('error', 'We could not reach the payment provider. Please try again in a moment.');
        }
    }

    /** Stripe sends the user back here after paying. */
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

    private function stripeReady(): bool
    {
        return filled(config('cashier.key')) && filled(config('cashier.secret'));
    }
}
