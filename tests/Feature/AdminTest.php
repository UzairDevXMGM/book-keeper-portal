<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Report;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Reports\ReportAccess;
use App\Support\Settings;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);
        $this->admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->owner = User::factory()->create(['email' => 'owner@acme.test', 'password' => Hash::make('Ledger2026check')]);
        $this->company = app(CompanyProvisioner::class)->create($this->owner, ['name' => 'Acme Supplies LLC', 'industry' => 'Restaurant - Full Service']);
        $this->company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
    }

    public function test_every_admin_page_opens_for_admins_only(): void
    {
        $pages = [
            route('admin.overview'), route('admin.customers.index'), route('admin.customers.show', $this->company),
            route('admin.plans.index'), route('admin.plans.edit', Plan::first()), route('admin.reports.index'),
            route('admin.reports.edit', Report::first()), route('admin.users.index'), route('admin.audit.index'), route('admin.settings.show'), route('admin.guide'),
        ];

        foreach ($pages as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
            $this->actingAs($this->owner)->get($url)->assertForbidden();
        }
    }

    public function test_customers_can_be_searched_and_filtered(): void
    {
        $other = User::factory()->create(['email' => 'unpaid@else.test']);
        app(CompanyProvisioner::class)->create($other, ['name' => 'Unpaid Bakery']);
        $this->actingAs($this->admin);

        $this->get(route('admin.customers.index', ['q' => 'acme']))->assertSee('Acme Supplies LLC')->assertDontSee('Unpaid Bakery');
        $this->get(route('admin.customers.index', ['status' => 'unpaid']))->assertSee('Unpaid Bakery')->assertDontSee('Acme Supplies LLC');
        $this->get(route('admin.customers.index', ['status' => 'paying']))->assertSee('Acme Supplies LLC')->assertDontSee('Unpaid Bakery');
    }

    public function test_a_customer_login_can_be_disabled_and_enabled(): void
    {
        $this->actingAs($this->admin)->post(route('admin.customers.access', $this->company))->assertSessionHas('status');
        $this->assertFalse($this->owner->fresh()->is_active);

        auth()->logout();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Ledger2026check'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($this->admin)->post(route('admin.customers.access', $this->company));
        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_admins_can_give_and_take_back_a_free_add_on(): void
    {
        $ratios = Report::where('code', 'ACC-30')->first();
        $access = fn () => ReportAccess::for($this->company->fresh())->first(fn ($r) => $r['report']->is($ratios))['has_access'];
        $this->assertFalse($access());

        $this->actingAs($this->admin)->post(route('admin.customers.grant', $this->company), ['report_id' => $ratios->id])->assertSessionHas('status');
        $this->assertTrue($access());

        $addon = $this->company->addons()->firstOrFail();
        $this->delete(route('admin.customers.revoke', [$this->company, $addon]))->assertSessionHas('status');
        $this->assertFalse($access());
    }

    public function test_changing_a_plan_price_drops_the_old_stripe_price_and_saves_included_reports(): void
    {
        $plan = Plan::first();
        $plan->update(['stripe_price_id' => 'price_old']);
        $reports = Report::whereIn('code', ['ACC-22', 'ACC-26', 'ACC-28'])->pluck('id')->all();

        $this->actingAs($this->admin)->patch(route('admin.plans.update', $plan), [
            'name' => 'Basic', 'description' => 'Updated', 'price' => '29.00', 'is_active' => 1, 'reports' => $reports,
        ])->assertRedirect(route('admin.plans.index'));

        $plan->refresh();
        $this->assertSame('29.00', $plan->price);
        $this->assertNull($plan->stripe_price_id);
        $this->assertEqualsCanonicalizing($reports, $plan->reports()->pluck('reports.id')->all());

        // Same price again keeps the Stripe price
        $plan->update(['stripe_price_id' => 'price_new']);
        $this->patch(route('admin.plans.update', $plan), ['name' => 'Basic', 'price' => '29', 'is_active' => 1, 'reports' => $reports]);
        $this->assertSame('price_new', $plan->fresh()->stripe_price_id);
    }

    public function test_monthly_revenue_counts_the_plan_of_a_subscription_that_also_has_add_ons(): void
    {
        // With an add-on the Stripe subscription has two prices and no single stripe_price
        $plan = Plan::first();
        $plan->update(['stripe_price_id' => 'price_basic']);
        $subscription = $this->company->subscriptions()->first();
        $subscription->update(['stripe_price' => null]);
        $subscription->items()->create(['stripe_id' => 'si_plan', 'stripe_product' => 'prod_plan', 'stripe_price' => 'price_basic', 'quantity' => 1]);
        $this->company->addons()->create(['report_id' => Report::where('code', 'ACC-28')->value('id'), 'price' => '10.00', 'status' => 'active', 'starts_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.overview'))->assertOk()
            ->assertViewHas('paying', 1)
            ->assertViewHas('mrr', round((float) $plan->price + 10, 2));
    }

    public function test_report_catalog_entries_can_be_edited(): void
    {
        $report = Report::where('code', 'ACC-30')->first();

        $this->actingAs($this->admin)->patch(route('admin.reports.update', $report), [
            'name' => 'Financial Ratios & Benchmarks', 'description' => 'x', 'category' => 'Analysis', 'addon_price' => '15', 'sort_order' => 5, 'is_active' => 0,
        ])->assertRedirect(route('admin.reports.index'));

        $report->refresh();
        $this->assertSame('Financial Ratios & Benchmarks', $report->name);
        $this->assertSame('15.00', $report->addon_price);
        $this->assertFalse($report->is_active);
    }

    public function test_users_can_be_disabled_and_sent_a_reset_link_but_admins_cannot_disable_themselves(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->post(route('admin.users.toggle', $this->owner))->assertSessionHas('status');
        $this->assertFalse($this->owner->fresh()->is_active);

        $this->post(route('admin.users.toggle', $this->admin))->assertSessionHas('error');
        $this->assertTrue($this->admin->fresh()->is_active);

        $this->post(route('admin.users.reset', $this->owner))->assertSessionHas('status');
        Notification::assertSentTo($this->owner, ResetPassword::class);
    }

    public function test_actions_are_written_to_the_audit_log(): void
    {
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Wrong-password-1']);
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Ledger2026check']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $this->owner->id, 'company_id' => $this->company->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['action' => 'auth']))->assertSee('auth.login')->assertSee('owner@acme.test');
        $this->get(route('admin.audit.index', ['company' => $this->company->id]))->assertSee('auth.login');
        $this->assertFalse(AuditLog::where('details', 'like', '%Ledger2026check%')->exists(), 'passwords are never logged');
    }

    public function test_settings_change_the_review_threshold_and_can_pause_sign_ups(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.settings.update'), [
            'review_threshold' => 85, 'upload_max_mb' => 5, 'registration_open' => 0,
        ])->assertSessionHas('status');

        $this->assertSame(85, Settings::get('review_threshold'));
        $this->assertSame(5, Settings::get('upload_max_mb'));
        $this->assertFalse(Settings::get('registration_open'));

        auth()->logout();
        $this->get('/register')->assertOk()->assertSee('Sign-ups are paused');
        $this->post('/register', [
            'name' => 'New', 'company_name' => 'New Co', 'email' => 'new@co.test', 'password' => 'Ledger2026check',
            'password_confirmation' => 'Ledger2026check', 'business_type' => 'single_member_llc', 'industry' => 'Restaurant - Full Service',
            'state' => 'TX', 'fiscal_year_start_month' => 1, 'terms' => 1,
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'new@co.test']);

        $this->actingAs($this->admin)->patch(route('admin.settings.update'), ['review_threshold' => 40, 'upload_max_mb' => 5, 'registration_open' => 1])
            ->assertSessionHasErrors('review_threshold');
    }

    public function test_stripe_sync_explains_missing_keys(): void
    {
        $this->actingAs($this->admin)->post(route('admin.plans.sync'))->assertSessionHas('error');
    }
}
