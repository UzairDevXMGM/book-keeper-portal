<?php

namespace Tests\Feature;

use App\Mail\Welcome;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SignupBilling;
use App\Services\CompanyProvisioner;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Ledger2026check';

    /** What the fake Stripe answers next: paid, action (bank approval) or declined. */
    private string $stripeAnswer = 'paid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);

        // Sign-up pays before the account exists; a fake stands in for Stripe so tests never call it
        config(['cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        Plan::default()->update(['stripe_price_id' => 'price_basic_test']);
        $test = $this;
        $this->app->instance(SignupBilling::class, new class($test) extends SignupBilling
        {
            public array $abandoned = [];

            public function __construct(private AuthenticationTest $test)
            {
            }

            public function start(string $priceId, string $paymentMethod, array $customer): array
            {
                return $this->answer($this->test->nextStripeAnswer());
            }

            public function check(string $subscriptionId, string $paymentMethod): array
            {
                return $this->answer($this->test->nextStripeAnswer());
            }

            public function abandon(?string $subscriptionId, ?string $customerId): void
            {
                $this->abandoned[] = $subscriptionId;
            }

            private function answer(string $status): array
            {
                return [
                    'status' => $status, 'sub_status' => $status === 'paid' ? 'active' : 'incomplete',
                    'customer' => 'cus_fake', 'subscription' => 'sub_fake', 'invoice' => null,
                    'secret' => $status === 'action' ? 'pi_fake_secret_x' : null,
                    'message' => $status === 'declined' ? 'Your card was declined.' : null,
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                    'items' => [['id' => 'si_fake', 'product' => 'prod_fake', 'price' => 'price_basic_test', 'quantity' => 1]],
                ];
            }
        });
    }

    public function nextStripeAnswer(): string
    {
        return $this->stripeAnswer;
    }

    public function test_registration_creates_the_user_their_company_and_its_chart_of_accounts(): void
    {
        $this->postJson('/register', $this->registration())->assertOk()->assertJson(['redirect' => route('dashboard')]);

        $this->assertAuthenticated();
        $user = User::where('email', 'owner@acme.test')->firstOrFail();
        // Paid before the account was made: it is the Stripe customer, with an active plan and the card on file
        $this->assertSame('cus_fake', $user->company->stripe_id);
        $this->assertSame('4242', $user->company->pm_last_four);
        $this->assertSame('Houston', $user->company->city);
        $this->assertTrue($user->company->hasActivePlan());
        $this->get('/dashboard')->assertOk();
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

    public function test_a_declined_card_leaves_no_account_behind(): void
    {
        $this->stripeAnswer = 'declined';

        $this->postJson('/register', $this->registration())->assertStatus(422)->assertJson(['message' => 'Your card was declined.']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertSame(['sub_fake'], app(SignupBilling::class)->abandoned);
    }

    public function test_a_bank_approval_finishes_the_sign_up_only_once_paid(): void
    {
        $this->stripeAnswer = 'action';
        $this->postJson('/register', $this->registration())->assertOk()->assertJson(['confirm' => 'pi_fake_secret_x']);
        $this->assertDatabaseCount('users', 0);   // waiting for the bank: still no account

        $this->stripeAnswer = 'paid';
        $this->postJson(route('register.complete'))->assertOk()->assertJson(['redirect' => route('dashboard')]);

        $this->assertAuthenticated();
        $this->assertTrue(User::where('email', 'owner@acme.test')->firstOrFail()->company->hasActivePlan());
        // The password was kept only as a hash while waiting, and still logs in
        auth()->logout();
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => self::PASSWORD])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_sign_up_is_paused_without_stripe_keys(): void
    {
        config(['cashier.key' => '', 'cashier.secret' => '']);

        $this->get('/register')->assertOk()->assertSee('new sign-ups are paused')->assertDontSee('js.stripe.com');
        $this->postJson('/register', $this->registration())->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_unpaid_customer_sees_our_payment_page(): void
    {
        config(['cashier.key' => '', 'cashier.secret' => '']);

        // The old hosted-checkout link now opens our own payment page
        $this->actingAs($this->customer())->get(route('billing.checkout'))->assertRedirect(route('billing.show'));
        $this->get(route('billing.show'))->assertOk()->assertSee('Basic')->assertSee('$25')
            ->assertSee('Online payment is not set up yet')->assertDontSee('js.stripe.com');
    }

    public function test_a_new_account_gets_the_welcome_email(): void
    {
        Mail::fake();
        $this->withoutDefer();   // the email normally goes out after the page answered

        $this->postJson('/register', $this->registration())->assertOk();

        Mail::assertSent(Welcome::class, fn (Welcome $mail) => $mail->hasTo('owner@acme.test'));
    }

    public function test_billing_pages_stay_on_our_site(): void
    {
        $user = $this->customer();
        $user->company->subscriptions()->create([
            'type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test',
        ]);

        // The old "Manage billing" link opens our own billing section, never Stripe
        $this->actingAs($user)->get(route('billing.portal'))->assertRedirect(route('profile.show').'#billing');
        $this->get(route('profile.show'))->assertOk()->assertSee('Invoices')->assertSee('Cancel plan')
            ->assertDontSee('billing.stripe.com')->assertDontSee('Managed in Stripe');
    }

    public function test_subscribing_without_stripe_keys_is_refused_without_charging(): void
    {
        config(['cashier.key' => '', 'cashier.secret' => '']);

        $this->actingAs($this->customer())->postJson(route('billing.subscribe'), ['payment_method' => 'pm_card_visa'])
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
            'payment_method' => 'pm_card_visa',
            'billing' => ['name' => 'Acme Supplies LLC', 'address' => [
                'line1' => '100 Main St', 'city' => 'Houston', 'state' => 'TX', 'postal_code' => '77002', 'country' => 'US',
            ]],
        ], $overrides);
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => Hash::make(self::PASSWORD)]);
        app(CompanyProvisioner::class)->create($user, ['name' => 'Acme Supplies LLC']);

        return $user->fresh();
    }
}
