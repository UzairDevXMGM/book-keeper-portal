<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Demo\DemoBooks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates (or tops up) a demo business owner with an active Basic plan, so the customer dashboard can be tried
 * without going through sign-up. The plan is a real Stripe test-mode subscription paid with Stripe's test card,
 * which keeps the Billing portal and invoices working. Never runs in production or with live Stripe keys.
 */
class CreateDemoCustomer extends Command
{
    /** The demo business is a gift shop that sells in store, online and wholesale. */
    private const INDUSTRY = 'Retail (General Merchandise)';

    protected $signature = 'demo:customer {--fresh : Rebuild the demo company\'s chart of accounts and books (keeps the user and the Stripe plan)}';

    protected $description = 'Create a demo customer with an active Basic plan and two years of books (Stripe test mode only)';

    public function handle(CompanyProvisioner $provisioner, DemoBooks $books): int
    {
        if (app()->isProduction() || ! Str::startsWith((string) config('cashier.secret'), 'sk_test_')) {
            $this->error('Only available outside production and with a Stripe test key (sk_test_...).');

            return self::FAILURE;
        }

        $email = config('bookkeeper.demo.email');
        $password = config('bookkeeper.demo.password');
        if (! $email || ! $password) {
            $this->error('Set DEMO_CUSTOMER_EMAIL and DEMO_CUSTOMER_PASSWORD in .env first.');

            return self::FAILURE;
        }

        $plan = Plan::default();
        if (! $plan?->stripe_price_id) {
            $this->error('The plan has no Stripe price yet. Run `php artisan billing:sync-stripe` first.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->fill(['name' => 'Demo Owner', 'password' => $password]);
        $user->forceFill(['role' => User::ROLE_CUSTOMER, 'is_active' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        $company = $user->company ?? $provisioner->create($user, [
            'name' => 'Demo Trading LLC',
            'business_type' => 'single_member_llc',
            'industry' => self::INDUSTRY,
            'city' => 'Houston',
            'state' => 'TX',
            'fiscal_year_start_month' => 1,
        ]);

        if ($this->option('fresh') && ! $company->wasRecentlyCreated) {
            $this->rebuild($company, $provisioner);
            $this->line('Rebuilt the demo chart of accounts ('.$company->accounts()->count().' accounts); the books are posted again below.');
        }

        if ($company->hasActivePlan()) {
            $this->line("Demo customer {$email} already has an active plan.");
        } else {
            // pm_card_visa is Stripe's built-in test card (4242 4242 4242 4242)
            $company->newSubscription(Company::SUBSCRIPTION, $plan->stripe_price_id)->create('pm_card_visa', [
                'address' => ['line1' => '100 Demo Street', 'city' => 'Houston', 'state' => 'TX', 'postal_code' => '77002', 'country' => 'US'],
            ]);
            $this->line("Subscribed {$company->name} to the {$plan->name} plan with Stripe's test card.");
        }

        if ($company->journalEntries()->doesntExist()) {
            $entries = $books->generate($company);
            $this->line("Posted {$entries} demo journal entries (".DemoBooks::MONTHS." months).");
        }

        $this->info("Demo customer ready: {$email} (password: DEMO_CUSTOMER_PASSWORD in .env)");

        return self::SUCCESS;
    }

    /** Empties the demo company's books and gives it the current chart of accounts. */
    private function rebuild(Company $company, CompanyProvisioner $provisioner): void
    {
        DB::transaction(function () use ($company, $provisioner) {
            $id = $company->id;
            DB::table('journal_lines')->where('company_id', $id)->delete();
            DB::table('import_rows')->where('company_id', $id)->delete();
            DB::table('journal_entries')->where('company_id', $id)->update(['reverses_entry_id' => null]);
            DB::table('journal_entries')->where('company_id', $id)->delete();
            DB::table('reconciliations')->where('company_id', $id)->delete();
            foreach ($company->uploads as $upload) {
                Storage::disk($upload->disk)->delete($upload->file_path);
            }
            DB::table('uploads')->where('company_id', $id)->delete();
            DB::table('categorization_rules')->where('company_id', $id)->delete();
            DB::table('accounts')->where('company_id', $id)->delete();

            $company->update(['industry' => self::INDUSTRY, 'locked_through' => null]);
            $provisioner->addAccounts($company->fresh());
        });
    }
}
