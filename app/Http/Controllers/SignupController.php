<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SignupBilling;
use App\Services\InvoiceMailer;
use App\Support\Audit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * One-page sign-up: account details and card go in together. The details are checked first, then the plan is paid,
 * and only a paid sign-up becomes an account. An unpaid try leaves nothing behind (no user, no business).
 */
class SignupController extends Controller
{
    private const PENDING = 'signup.pending';

    public function __construct(private CreateNewUser $accounts, private SignupBilling $billing)
    {
    }

    /** The sign-up form posts here with fetch (JSON in, JSON out). */
    public function start(Request $request): JsonResponse
    {
        // Details first: nothing is charged for a form with mistakes
        $data = $this->accounts->validate($request->except(['payment_method', 'billing']));
        $billing = $request->validate([
            'payment_method' => ['required', 'string', 'max:100', 'regex:/^pm_[A-Za-z0-9_]+$/'],
            'billing.name' => ['required', 'string', 'max:200'],
            'billing.address.line1' => ['required', 'string', 'max:200'],
            'billing.address.line2' => ['nullable', 'string', 'max:200'],
            'billing.address.city' => ['required', 'string', 'max:100'],
            'billing.address.state' => ['nullable', 'string', 'max:100'],
            'billing.address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing.address.country' => ['required', 'string', 'size:2'],
        ], [], ['billing.address.line1' => 'billing address', 'billing.address.city' => 'city', 'payment_method' => 'card']);

        $plan = Plan::default();
        if (! $plan?->stripe_price_id || ! $this->stripeReady()) {
            return response()->json(['message' => 'Online payment is not set up yet, so sign-ups are paused. Please try again later.'], 503);
        }

        // A second try in the same browser replaces an unpaid first one
        $this->dropPending();

        try {
            $result = $this->billing->start($plan->stripe_price_id, $billing['payment_method'], [
                'email' => $data['email'],
                'name' => $billing['billing']['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $billing['billing']['address'],
            ]);
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Your card was declined. Please try another card.'], 422);
        } catch (ApiErrorException $e) {
            Log::error('Sign-up payment failed', ['email' => $data['email'], 'error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not reach the payment provider. Please try again in a moment.'], 502);
        }

        // Only the hash of the password is kept while the bank approval (if any) is pending
        $pending = [
            'account' => array_diff_key($data, array_flip(['password', 'password_confirmation', 'terms'])) + ['password_hash' => Hash::make($data['password'])],
            'city' => $billing['billing']['address']['city'] ?? null,
            'payment_method' => $billing['payment_method'],
        ] + $result;

        return $this->answer($request, $pending);
    }

    /** After the bank's approval window: checks the payment again and finishes the sign-up when it went through. */
    public function complete(Request $request): JsonResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! $pending) {
            return response()->json(['message' => 'This sign-up has expired. Please fill in the form again.'], 422);
        }

        try {
            $result = $this->billing->check($pending['subscription'], $pending['payment_method']);
        } catch (ApiErrorException $e) {
            Log::error('Sign-up payment check failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not reach the payment provider. Please try again in a moment.'], 502);
        }

        return $this->answer($request, $result + $pending);
    }

    private function answer(Request $request, array $pending): JsonResponse
    {
        if ($pending['status'] === 'action') {
            $request->session()->put(self::PENDING, $pending);

            return response()->json(['confirm' => $pending['secret']]);
        }
        if ($pending['status'] !== 'paid') {
            $request->session()->forget(self::PENDING);
            $this->abandon($pending);

            return response()->json(['message' => $pending['message'] ?? 'Your card was declined. Please try another card.'], 422);
        }

        $request->session()->forget(self::PENDING);
        try {
            $user = $this->createAccount($pending);
        } catch (Throwable $e) {
            // Paid but the account could not be saved (e.g. the same email signed up a second ago): a person sorts it out
            Log::critical('Paid sign-up could not be saved', ['email' => $pending['account']['email'], 'subscription' => $pending['subscription'], 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Your payment went through but we could not finish the account. Please contact us at '.config('mail.from.address').' and we will sort it out right away.'], 500);
        }

        Auth::login($user);
        $request->session()->regenerate();
        session()->flash('status', 'Welcome to BookKeepingLLC! Your plan is active and your invoice is on its way to '.$user->email.'.');

        return response()->json(['redirect' => route('dashboard')]);
    }

    private function createAccount(array $pending): User
    {
        $user = $this->accounts->createFromValidated($pending['account'], array_filter(['city' => $pending['city']]));
        $company = $user->company;

        // The business is now the Stripe customer that just paid (these fields are not mass assignable)
        $company->forceFill([
            'stripe_id' => $pending['customer'],
            'pm_type' => $pending['card']['brand'],
            'pm_last_four' => $pending['card']['last4'],
        ])->save();

        $single = count($pending['items']) === 1;
        $subscription = $company->subscriptions()->create([
            'type' => Company::SUBSCRIPTION,
            'stripe_id' => $pending['subscription'],
            'stripe_status' => $pending['sub_status'],
            'stripe_price' => $single ? $pending['items'][0]['price'] : null,
            'quantity' => $single ? $pending['items'][0]['quantity'] : null,
        ]);
        foreach ($pending['items'] as $item) {
            $subscription->items()->create([
                'stripe_id' => $item['id'],
                'stripe_product' => $item['product'],
                'stripe_price' => $item['price'],
                'quantity' => $item['quantity'],
            ]);
        }
        Audit::log('billing.subscribed', $company, ['subscription' => $pending['subscription']], $company);

        // Welcome and invoice emails go out after the page has answered, so the sign-up never waits for the mail server
        event(new Registered($user));
        if ($invoice = $pending['invoice']) {
            defer(function () use ($company, $invoice) {
                try {
                    app(InvoiceMailer::class)->sendById($company, $invoice);
                } catch (Throwable $e) {
                    Log::warning('First invoice email skipped', ['company_id' => $company->id, 'error' => $e->getMessage()]);
                }
            });
        }

        return $user;
    }

    private function dropPending(): void
    {
        if ($pending = session()->pull(self::PENDING)) {
            $this->abandon($pending);
        }
    }

    private function abandon(array $pending): void
    {
        try {
            $this->billing->abandon($pending['subscription'] ?? null, $pending['customer'] ?? null);
        } catch (Throwable $e) {
            Log::warning('Unpaid sign-up cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    private function stripeReady(): bool
    {
        return filled(config('cashier.key')) && filled(config('cashier.secret'));
    }
}
