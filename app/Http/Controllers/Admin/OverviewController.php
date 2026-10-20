<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\User;
use App\Services\Reports\ReportAccess;
use Illuminate\View\View;

/** Platform admin landing page: customers, active plans and monthly recurring revenue. */
class OverviewController extends Controller
{
    public function __invoke(): View
    {
        // Each paying business counts its plan once. With add-ons the Stripe subscription has several prices (and no
        // single stripe_price), so the plan is found the same way the rest of the app finds it.
        $paying = Company::whereHas('subscriptions', fn ($q) => $q->where('type', Company::SUBSCRIPTION)->whereIn('stripe_status', ['active', 'trialing']))
            ->with('subscriptions.items')->get();
        $planRevenue = $paying->sum(fn (Company $company) => (float) (ReportAccess::planFor($company)?->price ?? 0));
        $addonRevenue = (float) CompanyAddon::renewing()->whereIn('company_id', $paying->pluck('id'))->sum('price');

        return view('admin.overview', [
            'customers' => User::where('role', User::ROLE_CUSTOMER)->count(),
            'paying' => $paying->count(),
            'mrr' => round($planRevenue + $addonRevenue, 2),
            'unpaid' => Company::whereDoesntHave('subscriptions', fn ($q) => $q->whereIn('stripe_status', ['active', 'trialing']))->count(),
            'recent' => Company::with(['owner', 'subscriptions'])->latest()->latest('id')->paginate(10)->withQueryString(),
        ]);
    }
}
