<?php

namespace App\Http\Controllers;

use App\Models\CompanyAddon;
use App\Models\Report;
use App\Services\Billing\AddonBilling;
use App\Services\Reports\ReportAccess;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiErrorException;
use Throwable;

/** Buying and cancelling add-on reports (billed on the company's existing Stripe subscription). */
class AddonController extends Controller
{
    public function store(Request $request, Report $report, AddonBilling $billing): RedirectResponse
    {
        $company = $this->company($request);
        $access = ReportAccess::for($company)->first(fn ($r) => $r['report']->is($report));
        abort_if($access === null, 404);

        if ($access['has_access']) {
            return redirect()->route('reports.show', $report->code)->with('status', "{$report->name} is already part of your plan.");
        }
        if (! $report->stripe_price_id) {
            return back()->with('error', 'This add-on is not on sale yet. Please try again later.');
        }

        try {
            $itemId = $billing->add($company, $report);
        } catch (IncompletePayment $e) {
            // The bank wants the customer to confirm the payment (3-D Secure); Cashier's page handles it
            return redirect()->route('cashier.payment', [$e->payment->id, 'redirect' => route('reports.show', $report->code)]);
        } catch (ApiErrorException $e) {
            Log::error('Add-on purchase failed', ['company_id' => $company->id, 'report' => $report->code, 'error' => $e->getMessage()]);

            return back()->with('error', 'The payment provider could not add this report: '.$e->getMessage());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $addon = $company->addons()->create([
            'report_id' => $report->id,
            'price' => $report->addon_price,
            'status' => CompanyAddon::STATUS_ACTIVE,
            'starts_at' => now(),
            'stripe_subscription_item_id' => $itemId,
        ]);
        Audit::log('addon.purchased', $addon, ['report' => $report->code, 'price' => $report->addon_price]);

        return redirect()->route('reports.show', $report->code)
            ->with('status', "{$report->name} added for ".money($report->addon_price).'/month. Today\'s charge is prorated to your billing date.');
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
}
