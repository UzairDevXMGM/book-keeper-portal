<?php

namespace App\Console\Commands;

use App\Services\Billing\StripePriceSync;
use Illuminate\Console\Command;

/** Creates or links the Stripe prices for plans and add-on reports (see StripePriceSync). */
class SyncStripePrices extends Command
{
    protected $signature = 'billing:sync-stripe';

    protected $description = 'Create or link the Stripe prices for the subscription plans and add-on reports';

    public function handle(StripePriceSync $sync): int
    {
        if (blank(config('cashier.secret'))) {
            $this->error('STRIPE_SECRET is not set in .env.');

            return self::FAILURE;
        }

        foreach ($sync->sync() as $line) {
            $this->line($line);
        }
        $this->info('Stripe prices are in sync.');

        return self::SUCCESS;
    }
}
