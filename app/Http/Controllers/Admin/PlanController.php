<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Report;
use App\Services\Billing\StripePriceSync;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/** Admin → Plans: price, description and the reports each plan includes. */
class PlanController extends Controller
{
    public function index(): View
    {
        $subscribers = DB::table('subscriptions')->whereIn('stripe_status', ['active', 'trialing'])
            ->select('stripe_price', DB::raw('COUNT(*) AS n'))->groupBy('stripe_price')->pluck('n', 'stripe_price');

        return view('admin.plans.index', [
            'plans' => Plan::withCount('reports')->orderBy('sort_order')->get(),
            'subscribers' => $subscribers,
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', [
            'plan' => $plan->load('reports:id'),
            'reports' => Report::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan, StripePriceSync $sync): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'is_active' => ['nullable', 'boolean'],
            'reports' => ['nullable', 'array'],
            'reports.*' => ['integer', 'exists:reports,id'],
        ]);

        $priceChanged = bccomp((string) $plan->price, number_format((float) $data['price'], 2, '.', ''), 2) !== 0;
        $plan->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'is_active' => $request->boolean('is_active'),
            // A new amount needs a new Stripe price; existing subscribers keep the price they signed up with
            'stripe_price_id' => $priceChanged ? null : $plan->stripe_price_id,
        ]);
        $plan->reports()->sync($data['reports'] ?? []);
        Audit::log('admin.plan_updated', $plan, ['price' => $data['price'], 'reports' => count($data['reports'] ?? [])]);

        return redirect()->route('admin.plans.index')->with('status', "{$plan->name} plan saved.".($priceChanged ? $sync->afterPriceChange() : ''));
    }

    public function syncStripe(StripePriceSync $sync): RedirectResponse
    {
        if (blank(config('cashier.secret'))) {
            return back()->with('error', 'Stripe keys are not set in .env.');
        }
        try {
            $log = $sync->sync();
        } catch (Throwable $e) {
            Log::error('Stripe price sync failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Stripe sync failed: '.$e->getMessage());
        }
        Audit::log('admin.stripe_synced', null, ['changes' => $log]);

        return back()->with('status', $log ? 'Stripe updated: '.implode('; ', $log) : 'Everything is already in sync with Stripe.');
    }
}
