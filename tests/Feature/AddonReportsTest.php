<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyAddon;
use App\Models\Report;
use App\Models\User;
use App\Services\Billing\AddonBilling;
use App\Services\CompanyProvisioner;
use App\Services\Ledger\LedgerService;
use App\Services\Reports\AddonReports;
use App\Services\Reports\AlertCenter;
use App\Services\Reports\Metrics;
use App\Services\Reports\Period;
use App\Services\Reports\ReportRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddonReportsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDONS = ['ACC-28', 'PRT-01', 'PRT-02', 'PRT-03', 'BKT-11', 'BKT-09', 'BKT-10', 'BKT-07', 'ACC-30', 'ACC-31', 'PRT-07'];

    private User $owner;
    private Company $company;

    /** Records calls instead of talking to Stripe. */
    private AddonBilling $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);
        [$this->owner, $this->company] = $this->payingCustomer('owner@acme.test');

        $this->billing = new class extends AddonBilling
        {
            public array $added = [];
            public array $removed = [];

            public function add(Company $company, Report $report): string
            {
                $this->added[] = $report->code;

                return 'si_'.strtolower(str_replace('-', '', $report->code));
            }

            public ?\Carbon\CarbonImmutable $paidUntil = null;

            public function remove(Company $company, CompanyAddon $addon): ?\Carbon\CarbonImmutable
            {
                $this->removed[] = $addon->stripe_subscription_item_id;

                return $this->paidUntil;
            }
        };
        $this->app->instance(AddonBilling::class, $this->billing);
    }

    // ---------------------------------------------------------------- buying and removing

    public function test_buying_an_add_on_bills_it_once_and_opens_the_report(): void
    {
        Report::where('code', 'ACC-30')->update(['stripe_price_id' => 'price_addon_acc30']);
        $this->actingAs($this->owner);

        $this->get(route('reports.show', 'ACC-30'))->assertOk()->assertSee('Add to my plan');

        $this->post(route('addons.store', 'ACC-30'))->assertRedirect(route('reports.show', 'ACC-30'));
        $addon = $this->company->addons()->firstOrFail();
        $this->assertSame(CompanyAddon::STATUS_ACTIVE, $addon->status);
        $this->assertSame('si_acc30', $addon->stripe_subscription_item_id);

        $this->get(route('reports.show', 'ACC-30'))->assertOk()->assertSee('Current ratio')->assertDontSee('Add to my plan');
        $this->get(route('profile.show'))->assertSee('Financial Ratios');

        // A second click does not charge again
        $this->post(route('addons.store', 'ACC-30'))->assertRedirect(route('reports.show', 'ACC-30'));
        $this->assertSame(['ACC-30'], $this->billing->added);
        $this->assertSame(1, $this->company->addons()->count());
    }

    public function test_an_add_on_without_a_stripe_price_is_not_sold(): void
    {
        $this->actingAs($this->owner)->get(route('reports.show', 'ACC-28'))->assertSee('not on sale yet');

        $this->post(route('addons.store', 'ACC-28'))->assertSessionHas('error');
        $this->assertSame([], $this->billing->added);
        $this->assertSame(0, $this->company->addons()->count());
    }

    public function test_the_add_on_page_previews_the_report_without_giving_away_its_numbers(): void
    {
        $this->sampleBooks();
        $this->actingAs($this->owner);
        $report = Report::where('code', 'ACC-28')->firstOrFail();
        $report->update(['stripe_price_id' => 'price_cash_flow']);

        $page = $this->get(route('reports.show', 'ACC-28'))->assertOk()
            ->assertSee(['Preview with your books', 'New monthly total', 'Add to my plan', 'Confirm your add-on', 'Secure payment by Stripe'])
            ->assertSee(money($report->addon_price).'/month', false);

        // None of the report's own figures are in the page source
        $document = ReportRunner::run($this->company, $report, Period::resolve($this->company, ['preset' => 'ytd']));
        foreach ($document->kpis as $kpi) {
            if ($kpi['format'] === 'money' && abs((float) $kpi['value']) >= 1) {
                $page->assertDontSee(money($kpi['value']), false);
            }
        }
        // Buying goes through the app's own dialog, not the browser's confirm box
        $page->assertDontSee('data-confirm="Add', false);
    }

    public function test_removing_an_add_on_locks_the_report_again(): void
    {
        $addon = $this->grant('ACC-31', 'si_existing');
        $this->actingAs($this->owner)->get(route('reports.show', 'ACC-31'))->assertOk()->assertSee('KPI Dashboard');

        $this->delete(route('addons.destroy', $addon))->assertSessionHas('status');

        $this->assertSame(['si_existing'], $this->billing->removed);
        $this->assertSame(CompanyAddon::STATUS_CANCELLED, $addon->fresh()->status);
        $this->get(route('reports.show', 'ACC-31'))->assertOk()->assertSee(['New monthly total', 'not on sale yet'])->assertDontSee('Open report');
    }

    public function test_a_removed_add_on_stays_open_until_the_paid_month_ends_without_a_refund(): void
    {
        $addon = $this->grant('ACC-31', 'si_existing');
        $this->billing->paidUntil = \Carbon\CarbonImmutable::now()->addDays(12);

        $this->actingAs($this->owner)->delete(route('addons.destroy', $addon))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'non-refundable') && str_contains($s, 'stays open until'));

        // Still open for the month already paid, no longer renewing (and it cannot be removed twice)
        $this->get(route('reports.show', 'ACC-31'))->assertOk()->assertDontSee('Add to my plan');
        $this->assertNotNull($addon->fresh()->ends_at);
        $this->assertSame(0, $this->company->addons()->renewing()->count());
        $this->delete(route('addons.destroy', $addon))->assertNotFound();

        // Once the month is over the report locks again
        $this->travel(13)->days();
        $this->get(route('reports.show', 'ACC-31'))->assertOk()->assertSee('New monthly total');
    }

    public function test_another_companys_add_on_cannot_be_removed(): void
    {
        $addon = $this->grant('ACC-31', 'si_existing');
        [$other] = $this->payingCustomer('other@acme.test');

        $this->actingAs($other)->delete(route('addons.destroy', $addon))->assertNotFound();
        $this->assertSame([], $this->billing->removed);
        $this->assertSame(CompanyAddon::STATUS_ACTIVE, $addon->fresh()->status);
    }

    // ---------------------------------------------------------------- the reports

    public function test_every_add_on_report_opens_prints_and_exports(): void
    {
        $this->sampleBooks();
        foreach (self::ADDONS as $code) {
            $this->grant($code);
        }
        $this->actingAs($this->owner);
        $query = ['preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-03-31'];

        foreach (self::ADDONS as $code) {
            $this->assertTrue(ReportRunner::supports($code), $code);
            $this->get(route('reports.show', ['report' => $code] + $query))->assertOk()->assertDontSee('Coming soon');
            $this->get(route('reports.show', ['report' => $code, 'print' => 1] + $query))->assertOk();
            $this->get(route('reports.show', ['report' => $code, 'export' => 'csv'] + $query))->assertOk()->assertDownload();
        }
    }

    public function test_cash_flow_ties_to_the_change_in_cash(): void
    {
        $this->sampleBooks();
        $document = ReportRunner::run($this->company, Report::where('code', 'ACC-28')->first(), $this->quarter());

        $kpis = array_column($document->kpis, 'value', 'label');
        $this->assertEqualsWithDelta(3800.00, $kpis['Operating cash flow'], 0.001);   // 4,500 profit + 100 depreciation - 800 receivable
        $this->assertEqualsWithDelta(-6000.00, $kpis['Investing'], 0.001);
        $this->assertEqualsWithDelta(9500.00, $kpis['Financing'], 0.001);             // 10,000 loan - 500 draw
        $this->assertEqualsWithDelta(7300.00, $kpis['Net change in cash'], 0.001);
        $this->assertTrue($document->checks[0]['ok']);
    }

    public function test_common_size_and_vendor_reports(): void
    {
        $this->sampleBooks();
        $period = $this->quarter();

        $pl = ReportRunner::run($this->company, Report::where('code', 'PRT-02')->first(), $period);
        $net = collect($pl->tables[0]['rows'])->firstWhere('cells.account', 'Net income');
        $this->assertEqualsWithDelta(77.6, $net['cells']['share'], 0.001);             // 4,500 of 5,800 revenue

        $bs = ReportRunner::run($this->company, Report::where('code', 'PRT-03')->first(), $period);
        $total = collect($bs->tables[0]['rows'])->firstWhere('cells.account', 'Total assets');
        $this->assertEqualsWithDelta(100.0, $total['cells']['share'], 0.001);

        $vendors = ReportRunner::run($this->company, Report::where('code', 'BKT-10')->first(), $period);
        $names = collect($vendors->tables[0]['rows'])->pluck('cells.vendor')->all();
        $this->assertContains('Westheimer Retail Plaza', $names);
        $this->assertNotContains('Monthly depreciation', $names);                       // non-cash, not a vendor

        $this->assertSame('Gulf Coast Packaging', AddonReports::payee('Bill - Gulf Coast Packaging'));
        $this->assertSame('Sq Blue Bottle', AddonReports::payee('SQ *BLUE BOTTLE 0423'));
    }

    public function test_comparative_pl_defaults_to_last_year_and_account_activity_to_the_biggest_cost(): void
    {
        $this->sampleBooks();
        $this->grant('PRT-01');
        $this->grant('BKT-07');
        $this->actingAs($this->owner);
        $query = ['preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-03-31'];

        $this->get(route('reports.show', ['report' => 'PRT-01'] + $query))->assertOk()->assertSee('Mar 31, 2025');
        $this->get(route('reports.show', ['report' => 'BKT-07'] + $query))->assertOk()
            ->assertSee('Account Activity · 6200')->assertDontSee('All accounts');
    }

    public function test_basic_keeps_the_add_on_pieces_of_the_dashboard_and_alerts_locked(): void
    {
        $this->sampleBooks();
        $this->actingAs($this->owner);

        // Basic: the health score and the ratio metrics are locked cards that lead to the add-on
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(['Financial Health Score · $20.00/mo', 'Financial Ratios · $20.00/mo', 'KPI Dashboard · $20.00/mo'], false)
            ->assertDontSee(['Score out of 100, from six checks', 'Lenders look for 1.25x', 'Earnings before interest, tax and depreciation']);

        // Ratio alerts only come with the ratio add-ons; the bookkeeping ones are always there
        $m = Metrics::compute($this->company, $this->quarter());
        $m = ['runway' => 0.5, 'current_ratio' => 0.8, 'dso' => 75, 'cash' => 100, 'suspense' => 50] + $m;
        $titles = fn (bool $ratios) => array_column(AlertCenter::from($m, $ratios), 'title');
        $this->assertNotContains('Less than a month of cash', $titles(false));
        $this->assertNotContains('Current liabilities exceed current assets', $titles(false));
        $this->assertNotContains('Slow customer payments', $titles(false));
        $this->assertContains('Transactions without a category', $titles(false));
        $this->assertContains('Less than a month of cash', $titles(true));
        $this->assertContains('Current liabilities exceed current assets', $titles(true));

        // Once added, the dashboard shows them
        $this->grant('ACC-30');
        $this->grant('PRT-07');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(['Score out of 100, from six checks', 'Lenders look for 1.25x'])
            ->assertDontSee('Financial Ratios · $20.00/mo');
    }

    public function test_reports_filter_by_bank_or_card_search_category_and_type(): void
    {
        $this->sampleBooks();
        $card = $this->company->accounts()->where('canonical_code', '2020')->value('id');
        $meals = $this->company->accounts()->where('canonical_code', '6550')->value('id');
        app(LedgerService::class)->post($this->company, ['entry_date' => '2026-03-10', 'description' => 'Cafe Uno lunch'],
            [['account_id' => $meals, 'debit' => '45.00'], ['account_id' => $card, 'credit' => '45.00']], $this->owner);
        $this->grant('BKT-09');
        $this->grant('BKT-10');
        $this->actingAs($this->owner);
        $q1 = ['preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-03-31'];

        // P&L through the card: only what was paid by card
        $this->get(route('reports.show', ['report' => 'ACC-26', 'through' => $card] + $q1))->assertOk()
            ->assertSee('Meals - Business')->assertDontSee('Rent Expense - Building')->assertSee('Bank / card: Credit Cards Payable');

        // General Ledger search
        $this->get(route('reports.show', ['report' => 'ACC-22', 'q' => 'Westheimer'] + $q1))->assertOk()
            ->assertSee('Rent - Westheimer Retail Plaza')->assertDontSee('Square payout')->assertSee('Search: “Westheimer”', false);

        // Vendor spending by category and search; category spending by type
        $this->get(route('reports.show', ['report' => 'BKT-10', 'category' => $meals] + $q1))->assertOk()
            ->assertSee('Cafe Uno')->assertDontSee('Westheimer');
        $this->get(route('reports.show', ['report' => 'BKT-10', 'q' => 'westheimer'] + $q1))->assertOk()
            ->assertSee('Westheimer')->assertDontSee('Cafe Uno');
        $this->get(route('reports.show', ['report' => 'BKT-09', 'group' => 'expense'] + $q1))->assertOk()
            ->assertSee('Rent Expense - Building')->assertSee('Type: Operating expenses');

        // Another company's account or a filter the report does not offer is ignored
        $other = app(CompanyProvisioner::class)->create(User::factory()->create(), ['name' => 'Other Co', 'industry' => 'General Business']);
        $this->get(route('reports.show', ['report' => 'ACC-26', 'through' => $other->accounts()->where('is_bank_account', true)->value('id')] + $q1))->assertOk()
            ->assertSee('Rent Expense - Building')->assertDontSee('Bank / card:');
        $this->get(route('reports.show', ['report' => 'ACC-27', 'through' => $card]))->assertOk()->assertDontSee('name="through"', false);
    }

    public function test_comparing_profit_and_loss_periods_comes_with_the_comparative_add_on(): void
    {
        $this->sampleBooks();
        $this->actingAs($this->owner);
        $query = ['report' => 'ACC-26', 'preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-03-31', 'compare' => 'prior_year'];

        // Basic: the comparison is ignored and offered as the add-on; the balance sheet still compares
        $this->get(route('reports.show', $query))->assertOk()->assertSee('Last year · add-on')
            ->assertDontSee('name="compare"', false)->assertDontSee('Mar 31, 2025');
        $this->get(route('reports.show', ['report' => 'ACC-27', 'compare' => 'prior_year']))->assertOk()->assertSee('name="compare"', false);

        $this->grant('PRT-01');
        $this->get(route('reports.show', $query))->assertOk()->assertSee('name="compare"', false)->assertSee('Mar 31, 2025');
    }

    // ---------------------------------------------------------------- helpers

    /** Q1 2026: loan, equipment, cash and credit sales, rent, depreciation and an owner draw. */
    private function sampleBooks(): void
    {
        $ledger = app(LedgerService::class);
        $id = fn (string $code) => $this->company->accounts()->where('canonical_code', $code)->value('id');
        $post = fn (string $date, string $description, string $debit, string $credit, string $amount) => $ledger->post(
            $this->company,
            ['entry_date' => $date, 'description' => $description],
            [['account_id' => $id($debit), 'debit' => $amount], ['account_id' => $id($credit), 'credit' => $amount]],
            $this->owner,
        );

        $post('2026-01-05', 'SBA loan proceeds', '1030', '2700', '10000');
        $post('2026-01-10', 'Display shelving', '1550', '1030', '6000');
        $post('2026-02-01', 'Square payout', '1030', '4000', '5000');
        $post('2026-02-15', 'Rent - Westheimer Retail Plaza', '6200', '1030', '1200');
        $post('2026-02-28', 'Monthly depreciation', '7100', '1640', '100');
        $post('2026-03-01', 'Owner draw', '3110', '1030', '500');
        $post('2026-03-05', 'Invoice - Bayou Gift Co.', '1100', '4000', '800');
    }

    private function quarter(): Period
    {
        return new Period(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));
    }

    private function grant(string $code, ?string $itemId = null): CompanyAddon
    {
        $report = Report::where('code', $code)->firstOrFail();

        return $this->company->addons()->create([
            'report_id' => $report->id,
            'price' => $report->addon_price,
            'status' => CompanyAddon::STATUS_ACTIVE,
            'starts_at' => now(),
            'stripe_subscription_item_id' => $itemId,
        ]);
    }

    private function payingCustomer(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $company = app(CompanyProvisioner::class)->create($user, ['name' => "Business of {$email}"]);
        $company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.md5($email), 'stripe_status' => 'active', 'stripe_price' => 'price_test']);

        return [$user->fresh(), $company];
    }
}
