<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\Report;
use App\Services\Billing\AddonBilling;
use App\Services\Reports\ReportAccess;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Buying and cancelling add-on reports (billed on the company's existing Stripe subscription). Everything happens on
 * our own pages: the purchase window posts here with fetch, and a bank approval (3-D Secure) opens inside that window.
 */
class AddonController extends Controller
{
    public function store(Request $request, Report $report, AddonBilling $billing): RedirectResponse|JsonResponse
    {
        $company = $this->company($request);
        $access = ReportAccess::for($company)->first(fn ($r) => $r['report']->is($report));
        abort_if($access === null, 404);

        if ($access['has_access']) {
            return $this->reply($request, "{$report->name} is already part of your plan.", route('reports.show', $report->code));
        }
        if (! $report->stripe_price_id) {
            return $this->fail($request, 'This add-on is not on sale yet. Please try again later.');
        }

        try {
            $itemId = $billing->add($company, $report);
        } catch (IncompletePayment $e) {
            if ($e->payment->requiresAction()) {
                // The bank wants the customer to approve the charge: the purchase window opens the bank's check
                return $request->expectsJson()
                    ? response()->json(['confirm' => $e->payment->clientSecret(), 'payment' => $e->payment->id])
                    : $this->fail($request, 'Your bank asked to approve this payment. Please try again from the report page.');
            }
            $this->undo($company, $report);

            return $this->fail($request, 'Your card was declined, so the add-on was not added. Please update your card on My profile and try again.');
        } catch (ApiErrorException $e) {
            Log::error('Add-on purchase failed', ['company_id' => $company->id, 'report' => $report->code, 'error' => $e->getMessage()]);

            return $this->fail($request, 'The payment provider could not add this report: '.$e->getMessage());
        } catch (Throwable $e) {
            return $this->fail($request, $e->getMessage());
        }

        $this->activate($company, $report, $itemId);

        return $this->reply($request, $this->addedMessage($report), route('reports.show', $report->code));
    }

    /** After the bank approved the charge in the purchase window: checks the payment with Stripe and opens the report. */
    public function confirm(Request $request, Report $report): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['payment' => ['required', 'string', 'max:100', 'regex:/^pi_[A-Za-z0-9]+$/']]);

        if ($company->addons()->where('report_id', $report->id)->where('status', CompanyAddon::STATUS_ACTIVE)->exists()) {
            return response()->json(['redirect' => route('reports.show', $report->code)]);
        }
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        $item = $subscription?->items()->where('stripe_price', $report->stripe_price_id)->first();

        try {
            $intent = Cashier::stripe()->paymentIntents->retrieve($data['payment']);
        } catch (ApiErrorException $e) {
            Log::error('Add-on confirm failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not reach the payment provider. Please refresh in a moment.'], 502);
        }

        if (! $item || $intent->customer !== $company->stripe_id || $intent->status !== 'succeeded') {
            $this->undo($company, $report);

            return response()->json(['message' => 'The payment was not approved, so the add-on was not added. Please try again.'], 422);
        }

        try {
            $subscription->syncStripeStatus();
        } catch (ApiErrorException) {
            // The webhook brings the status up to date as well
        }
        $this->activate($company, $report, $item->stripe_id);
        session()->flash('status', $this->addedMessage($report));

        return response()->json(['redirect' => route('reports.show', $report->code)]);
    }

    public function destroy(Request $request, CompanyAddon $addon, AddonBilling $billing): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $addon->company_id);
        abort_unless($addon->status === CompanyAddon::STATUS_ACTIVE, 404);

        if ($addon->stripe_subscription_item_id) {
            try {
                $billing->remove($company, $addon);
            } catch (Throwable $e) {
                Log::error('Add-on cancel failed', ['addon' => $addon->id, 'error' => $e->getMessage()]);

                return back()->with('error', 'The add-on could not be removed right now. Please try again.');
            }
        }

        $addon->update(['status' => CompanyAddon::STATUS_CANCELLED, 'cancelled_at' => now(), 'ends_at' => now()]);
        Audit::log('addon.cancelled', $addon, ['report' => $addon->report->code]);

        return back()->with('status', "{$addon->report->name} removed. Unused time is credited on your next invoice.");
    }

    private function activate(Company $company, Report $report, string $itemId): void
    {
        $addon = $company->addons()->create([
            'report_id' => $report->id,
            'price' => $report->addon_price,
            'status' => CompanyAddon::STATUS_ACTIVE,
            'starts_at' => now(),
            'stripe_subscription_item_id' => $itemId,
        ]);
        Audit::log('addon.purchased', $addon, ['report' => $report->code, 'price' => $report->addon_price]);
    }

    /** A charge that was declined or not approved: take the price off again and cancel its unpaid invoice. */
    private function undo(Company $company, Report $report): void
    {
        $subscription = $company->subscription(Company::SUBSCRIPTION);
        if (! $subscription || ! $subscription->hasPrice($report->stripe_price_id)) {
            return;
        }

        try {
            $latest = $subscription->asStripeSubscription()->latest_invoice;
            $subscription->noProrate()->removePrice($report->stripe_price_id);
            $invoice = $latest ? Cashier::stripe()->invoices->retrieve(is_string($latest) ? $latest : $latest->id) : null;
            if ($invoice && $invoice->status === 'open') {
                Cashier::stripe()->invoices->voidInvoice($invoice->id);
            }
            $subscription->syncStripeStatus();
        } catch (Throwable $e) {
            Log::error('Add-on undo failed', ['company_id' => $company->id, 'report' => $report->code, 'error' => $e->getMessage()]);
        }
    }

    private function addedMessage(Report $report): string
    {
        return "{$report->name} added for ".money($report->addon_price).'/month. Today\'s charge is prorated to your billing date.';
    }

    private function reply(Request $request, string $message, string $to): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            session()->flash('status', $message);

            return response()->json(['redirect' => $to]);
        }

        return redirect()->to($to)->with('status', $message);
    }

    private function fail(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->with('error', $message);
    }
}
