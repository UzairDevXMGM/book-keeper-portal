<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Ledger2026check';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);
    }

    public function test_registration_creates_the_user_their_company_and_its_chart_of_accounts(): void
    {
        $this->post('/register', $this->registration())->assertRedirect(route('billing.show'));

        $this->assertAuthenticated();
        $user = User::where('email', 'owner@acme.test')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertSame('Acme Supplies LLC', $user->company->name);
        $this->assertSame('single_member_llc', $user->company->business_type);
        $this->assertSame('Restaurant - Full Service', $user->company->industry);
        $this->assertSame('TX', $user->company->state);
        $this->assertSame('(713) 555-0100', $user->company->phone);
        $this->assertSame(7, $user->company->fiscal_year_start_month);

        // The master chart (332 accounts + 3 the app adds) plus the restaurant's own 16 accounts
        $accounts = $user->company->accounts();
        $this->assertSame(351, $accounts->count());
        $this->assertSame('Inventory - Food', $user->company->accounts()->where('code', '1305')->value('name'));
        $this->assertFalse($user->company->accounts()->where('name', 'Fuel - Trucks')->exists(), 'other industries\' accounts are not added');
        $this->assertNotNull($user->company->systemAccount('suspense_expense'));
        $this->assertSame('1030', $user->company->systemAccount('default_bank')->code);
    }

    public function test_registration_cannot_make_an_admin_or_a_disabled_user(): void
    {
        $this->post('/register', $this->registration(['role' => 'admin', 'is_active' => 0]));

        $user = User::where('email', 'owner@acme.test')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_registration_requires_the_business_name_and_the_terms(): void
    {
        $this->post('/register', $this->registration(['company_name' => '', 'terms' => null]))
            ->assertSessionHasErrors(['company_name', 'terms']);

        $this->assertGuest();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_registration_only_accepts_listed_business_details(): void
    {
        $this->post('/register', $this->registration([
            'business_type' => 'llp',
            'industry' => 'Space Mining',
            'state' => 'XX',
            'fiscal_year_start_month' => 13,
            'phone' => 'call me',
        ]))->assertSessionHasErrors(['business_type', 'industry', 'state', 'fiscal_year_start_month', 'phone']);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_phone_is_optional(): void
    {
        $this->post('/register', $this->registration(['phone' => '']))->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'owner@acme.test')->firstOrFail()->company->phone);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->post('/register', $this->registration(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_checkout_without_stripe_keys_returns_to_the_plan_page(): void
    {
        $this->post('/register', $this->registration());

        // The old hosted-checkout link now opens our own payment page
        $this->get(route('billing.checkout'))->assertRedirect(route('billing.show'));
        $this->get(route('billing.show'))->assertOk()->assertSee('Basic')->assertSee('$25')
            ->assertSee('Online payment is not set up yet')->assertDontSee('js.stripe.com');
    }

    public function test_subscribing_without_stripe_keys_is_refused_without_charging(): void
    {
        $this->post('/register', $this->registration());

        $this->postJson(route('billing.subscribe'), ['payment_method' => 'pm_card_visa'])
            ->assertStatus(503)->assertJsonStructure(['message']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_paying_customer_posting_to_subscribe_is_sent_to_the_dashboard(): void
    {
        $user = $this->customer();
        $user->company->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test',
        ]);

        $this->actingAs($user)->postJson(route('billing.subscribe'), [])
            ->assertOk()->assertJson(['redirect' => route('dashboard')]);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_a_customer_without_a_paid_plan_is_sent_to_the_plan_page(): void
    {
        $this->actingAs($this->customer())->get('/dashboard')->assertRedirect(route('billing.show'));
    }

    public function test_a_paying_customer_reaches_the_dashboard(): void
    {
        $user = $this->customer();
        $user->company->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Acme Supplies LLC');
    }

    public function test_an_admin_lands_on_the_admin_overview_without_a_plan(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.overview'));
        $this->actingAs($admin)->get(route('admin.overview'))->assertOk()->assertSee('Monthly recurring revenue');
    }

    public function test_returning_from_stripe_without_a_session_does_not_unlock_the_dashboard(): void
    {
        $this->actingAs($this->customer())->get(route('billing.success'))->assertRedirect(route('billing.show'));
    }

    public function test_login_rejects_a_wrong_password_and_accepts_the_right_one(): void
    {
        $user = $this->customer();

        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-password-1'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => strtoupper($user->email), 'password' => self::PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $user = $this->customer();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'This account has been disabled. Please contact support.']);
        $this->assertGuest();
    }

    public function test_forgot_password_answers_the_same_for_unknown_emails(): void
    {
        Notification::fake();
        $user = $this->customer();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_the_emailed_token_resets_the_password(): void
    {
        Notification::fake();
        $user = $this->customer();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewLedger2026',
                'password_confirmation' => 'NewLedger2026',
            ])->assertRedirect('/login')->assertSessionHas('status');

            return true;
        });

        $this->assertTrue(Hash::check('NewLedger2026', $user->fresh()->password));
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Owner',
            'company_name' => 'Acme Supplies LLC',
            'email' => 'owner@acme.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'phone' => '(713) 555-0100',
            'business_type' => 'single_member_llc',
            'industry' => 'Restaurant - Full Service',
            'state' => 'TX',
            'fiscal_year_start_month' => '7',
            'terms' => '1',
        ], $overrides);
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => Hash::make(self::PASSWORD)]);
        app(CompanyProvisioner::class)->create($user, ['name' => 'Acme Supplies LLC']);

        return $user->fresh();
    }
}
