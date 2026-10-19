<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Report;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Creates (or re-links) the Stripe monthly prices for active plans and add-on reports that have no stripe_price_id.
 * Every price carries a lookup key (plan_<slug> / addon_<code>), so after a database reset the existing Stripe price
 * is found again, and a changed amount moves the key to a new price.
 */
class StripePriceSync
{
    /** @return list<string> one line per price created or linked */
    public function sync(): array
    {
        $log = [];
        foreach (Plan::where('is_active', true)->whereNull('stripe_price_id')->get() as $plan) {
            $price = $this->price("plan_{$plan->slug}", config('app.name')." - {$plan->name} plan", (string) $plan->price, $plan->billing_interval, ['plan_slug' => $plan->slug]);
            $plan->update(['stripe_price_id' => $price['id']]);
            $log[] = "{$plan->name} plan: {$price['action']} {$price['id']}";
        }

        $included = Plan::with('reports:id')->get()->flatMap(fn ($p) => $p->reports->pluck('id'))->unique();
        foreach (Report::where('is_active', true)->whereNull('stripe_price_id')->where('addon_price', '>', 0)->whereNotIn('id', $included)->get() as $report) {
            $price = $this->price('addon_'.strtolower($report->code), "Add-on report: {$report->name}", (string) $report->addon_price, 'month', ['report_code' => $report->code]);
            $report->update(['stripe_price_id' => $price['id']]);
            $log[] = "{$report->code} {$report->name}: {$price['action']} {$price['id']}";
        }

        return $log;
    }

    /**
     * Runs sync() right after an admin changes a price, so the new amount is on sale at once. Never throws: returns
     * the sentence to add to the admin's "saved" message.
     */
    public function afterPriceChange(): string
    {
        if (blank(config('cashier.secret'))) {
            return ' Stripe keys are not set in .env, so the new price is not on sale yet.';
        }
        try {
            $log = $this->sync();
        } catch (Throwable $e) {
            Log::error('Stripe price sync failed', ['error' => $e->getMessage()]);

            return ' Stripe could not be updated ('.$e->getMessage().'). Press "Sync with Stripe" on Admin → Plans to try again.';
        }
        Audit::log('admin.stripe_synced', null, ['changes' => $log]);

        return ' The new price is live in Stripe for new purchases; current subscribers keep the price they have.';
    }

    /** @return array{id: string, action: string} */
    private function price(string $lookupKey, string $productName, string $amount, string $interval, array $metadata): array
    {
        $stripe = Cashier::stripe();
        $cents = (int) bcmul($amount, '100');
        $currency = strtolower(config('cashier.currency'));

        $existing = $stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1])->data[0] ?? null;
        if ($existing && $existing->unit_amount === $cents && $existing->currency === $currency && $existing->recurring?->interval === $interval) {
            return ['id' => $existing->id, 'action' => 'linked existing'];
        }

        $product = $stripe->products->create(['name' => $productName, 'metadata' => $metadata]);
        $price = $stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $cents,
            'currency' => $currency,
            'recurring' => ['interval' => $interval],
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => true,
        ]);

        return ['id' => $price->id, 'action' => 'created'];
    }
}
