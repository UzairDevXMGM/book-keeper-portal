<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\ImportRow;
use App\Models\Report;
use App\Services\Reports\ReportAccess;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin → Customers: every business, its owner, plan, add-ons and activity. */
class CustomerController extends Controller
{
    public const FILTERS = ['all' => 'All', 'paying' => 'Paying', 'unpaid' => 'Not paid', 'disabled' => 'Login disabled'];

    public function index(Request $request): View
    {
        $filter = array_key_exists($request->query('status'), self::FILTERS) ? $request->query('status') : 'all';
        $search = trim((string) $request->query('q'));
        $active = fn ($q) => $q->whereIn('stripe_status', ['active', 'trialing']);

        $companies = Company::query()
            ->with(['owner', 'subscriptions'])
            ->withCount(['journalEntries', 'uploads'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->when($filter === 'paying', fn ($q) => $q->whereHas('subscriptions', $active))
            ->when($filter === 'unpaid', fn ($q) => $q->whereDoesntHave('subscriptions', $active))
            ->when($filter === 'disabled', fn ($q) => $q->whereHas('owner', fn ($o) => $o->where('is_active', false)))
            ->latest()->latest('id')
            ->paginate(15)->withQueryString();

        return view('admin.customers.index', compact('companies', 'filter', 'search') + ['filters' => self::FILTERS]);
    }

    public function show(Company $company): View
    {
        $company->load(['owner', 'subscriptions']);

        return view('admin.customers.show', [
            'company' => $company,
            'plan' => ReportAccess::planFor($company),
            'subscription' => $company->subscription(Company::SUBSCRIPTION),
            'reports' => ReportAccess::for($company),
            'addons' => $company->addons()->with('report')->latest()->get(),
            'stats' => [
                'entries' => $company->journalEntries()->count(),
                'uploads' => $company->uploads()->count(),
                'review' => ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count(),
                'last_entry' => $company->journalLines()->max('entry_date'),
            ],
            'activity' => AuditLog::where('company_id', $company->id)->with('user')->latest('id')->limit(10)->get(),
        ]);
    }

    /** Turns the owner's login off or on (the books are kept either way). */
    public function toggleAccess(Company $company): RedirectResponse
    {
        $owner = $company->owner;
        $owner->forceFill(['is_active' => ! $owner->is_active])->save();
        if (! $owner->is_active) {
            // End their sessions so the change takes effect immediately
            DB::table('sessions')->where('user_id', $owner->id)->delete();
        }
        Audit::log($owner->is_active ? 'admin.customer_enabled' : 'admin.customer_disabled', $company);

        return back()->with('status', $owner->is_active ? "{$owner->email} can log in again." : "{$owner->email} can no longer log in.");
    }

    /** Gives an add-on report free of charge (no Stripe billing). */
    public function grantAddon(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['report_id' => ['required', 'integer', Rule::exists('reports', 'id')->where('is_active', true)]]);
        $report = Report::findOrFail($data['report_id']);

        $hasAccess = ReportAccess::for($company)->first(fn ($r) => $r['report']->is($report))['has_access'] ?? false;
        if ($hasAccess) {
            return back()->with('error', "{$company->name} can already open {$report->name}.");
        }

        $company->addons()->create(['report_id' => $report->id, 'price' => 0, 'status' => CompanyAddon::STATUS_ACTIVE, 'starts_at' => now()]);
        Audit::log('admin.addon_granted', $company, ['report' => $report->code]);

        return back()->with('status', "{$report->name} added for free.");
    }

    /** Removes a complimentary add-on. Paid add-ons are cancelled by the customer or in Stripe. */
    public function revokeAddon(Company $company, CompanyAddon $addon): RedirectResponse
    {
        abort_unless($addon->company_id === $company->id && $addon->stripe_subscription_item_id === null, 404);
        $addon->update(['status' => CompanyAddon::STATUS_CANCELLED, 'cancelled_at' => now(), 'ends_at' => now()]);
        Audit::log('admin.addon_revoked', $company, ['report' => $addon->report->code]);

        return back()->with('status', "{$addon->report->name} removed.");
    }
}
