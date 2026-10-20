<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Report;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Services\Reports\FinancialStatements;
use App\Services\Reports\Period;
use App\Services\Reports\ReportRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;
    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);
        $this->ledger = app(LedgerService::class);
        [$this->owner, $this->company] = $this->payingCustomer('owner@acme.test');
    }

    // ---------------------------------------------------------------- posting

    public function test_a_balanced_entry_is_posted_with_the_next_entry_number(): void
    {
        $first = $this->post2('2026-02-01', '1030', '1,500.00', '3100');
        $second = $this->post2('2026-02-02', '6200', '100', '1030');

        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
        $this->assertSame('1500.00', $first->lines[0]->debit);
        $this->assertSame('1500.00', $first->lines[1]->credit);
    }

    public function test_unbalanced_one_sided_and_foreign_lines_are_refused(): void
    {
        $refused = function (array $lines, string $expected) {
            try {
                $this->ledger->post($this->company, ['entry_date' => '2026-02-01'], $lines);
                $this->fail('Entry should have been refused');
            } catch (LedgerException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        };

        $refused([$this->line('6200', '100'), $this->line('1030', null, '99.99')], 'must equal credits');
        $refused([$this->line('6200', '100', '100'), $this->line('1030', null, '100')], 'not both');
        $refused([$this->line('6200', '100')], 'at least two lines');
        $refused([$this->line('6200', '-5'), $this->line('1030', null, '-5')], 'not a valid amount');

        [, $other] = $this->payingCustomer('other@acme.test');
        $foreign = $other->accounts()->where('canonical_code', '1030')->value('id');
        $refused([$this->line('6200', '100'), ['account_id' => $foreign, 'credit' => '100']], 'active accounts');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_closed_periods_refuse_postings(): void
    {
        $this->company->update(['locked_through' => '2026-03-31']);

        $this->expectException(LedgerException::class);
        $this->post2('2026-03-15', '6200', '100', '1030');
    }

    public function test_reversal_mirrors_the_entry_and_happens_only_once(): void
    {
        $entry = $this->post2('2026-02-01', '6200', '250', '1030');
        $reversal = $this->ledger->reverse($entry, $this->owner, '2026-02-05');

        $this->assertSame('reversal', $reversal->source);
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame('250.00', $reversal->lines->firstWhere('account_id', $entry->lines[0]->account_id)->credit);

        $this->expectException(LedgerException::class);
        $this->ledger->reverse($entry->fresh());
    }

    // ---------------------------------------------------------------- statements

    public function test_statements_add_up_and_balance(): void
    {
        $this->post2('2026-01-01', '1030', '5000', '3100');      // owner puts in capital
        $this->post2('2026-01-10', '1030', '1200', '4100');      // service revenue
        $this->post2('2026-01-15', '6200', '400', '1030');       // rent
        $this->post2('2026-01-20', '1550', '1000', '2020');      // equipment on the credit card
        $this->post2('2026-01-31', '7100', '50', '1640');        // depreciation
        $this->post2('2025-06-01', '1030', '300', '4100');       // last fiscal year's revenue -> retained earnings

        $period = new Period(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
        $statements = new FinancialStatements($this->company);

        $pl = $statements->plSummary($statements->totals($period->start, $period->end));
        $this->assertSame(1200.0, $pl['revenue']);
        $this->assertSame(450.0, $pl['opex']);
        $this->assertSame(750.0, $pl['net_income']);
        $this->assertSame(800.0, $pl['ebitda']);

        $bs = $statements->balanceSheet($period);
        $this->assertTrue($bs->checks[0]['ok'], $bs->checks[0]['label']);
        $model = $statements->bsModel($period->end);
        $this->assertSame(7050.0, $model['assets']);          // 6100 cash + 1000 equipment - 50 depreciation
        $this->assertSame(1000.0, $model['liabilities']);
        $this->assertSame(300.0, $model['prior_earnings']);
        $this->assertSame(750.0, $model['current_earnings']);

        $tb = $statements->trialBalance($period);
        $this->assertTrue($tb->checks[0]['ok'], $tb->checks[0]['label']);

        $gl = $statements->generalLedger($period, $this->accountId('1030'));
        $rows = collect($gl->tables[0]['rows']);
        $this->assertSame(300.0, $rows->firstWhere('cells.description', 'Opening balance')['cells']['balance']);
        $closing = $rows->last()['cells']['balance'];
        $this->assertSame(6100.0, $closing);
    }

    public function test_comparison_column_and_csv_export(): void
    {
        $this->post2('2026-01-10', '1030', '1000', '4100');
        $this->post2('2025-01-10', '1030', '800', '4100');
        $period = Period::resolve($this->company, ['preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31', 'compare' => 'prior_year']);

        $doc = ReportRunner::run($this->company, Report::where('code', 'ACC-26')->first(), $period);
        $net = collect($doc->tables[0]['rows'])->last()['cells'];
        $this->assertSame(1000.0, $net['cur']);
        $this->assertSame(800.0, $net['cmp']);
        $this->assertSame(25.0, $net['chg_pct']);

        $this->actingAs($this->owner)
            ->get(route('reports.show', ['report' => 'ACC-26', 'preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-01-31', 'export' => 'csv']))
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ---------------------------------------------------------------- pages

    public function test_every_workspace_page_opens_for_a_paying_customer(): void
    {
        $entry = $this->post2(now()->toDateString(), '6200', '100', '1030');
        $this->actingAs($this->owner);

        foreach ([route('dashboard'), route('reports.index'), route('transactions.index', ['preset' => 'all']), route('accounts.index'),
            route('journal.create'), route('journal.show', $entry), route('alerts.index'),
            route('uploads.index'), route('review.index'), route('reconcile.index')] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['ACC-22', 'ACC-21', 'ACC-26', 'ACC-27'] as $code) {
            $this->get(route('reports.show', $code))->assertOk()->assertSee(Report::where('code', $code)->value('name'));
        }
    }

    public function test_dashboard_compares_with_last_year_and_lists_activity(): void
    {
        $this->post2(now()->subYear()->toDateString(), '1030', '800', '4100');
        $this->post2(now()->toDateString(), '1030', '1000', '4100');
        $this->post2(now()->toDateString(), '6200', '300', '1030');

        $this->actingAs($this->owner)->get(route('dashboard', ['preset' => 'ttm']))
            ->assertOk()
            ->assertSee(['vs last year', 'Top expenses', 'Recent transactions', 'Key metrics'])
            ->assertSee('Rent Expense - Building');
    }

    public function test_transactions_are_paginated_with_a_page_size_choice(): void
    {
        foreach (range(1, 30) as $day) {
            $this->post2(sprintf('2026-03-%02d', $day), '6200', '10', '1030');   // 30 transactions, 60 lines
        }
        $this->actingAs($this->owner);
        $category = $this->company->accounts()->whereKey($this->accountId('6200'))->value('name');
        $bank = $this->company->accounts()->whereKey($this->accountId('1030'))->value('name');

        // Simple view: one row per transaction with its category, the bank it went through and money out
        $this->get(route('transactions.index', ['preset' => 'all', 'per_page' => 25]))->assertOk()
            ->assertSee('of <strong>30</strong> transactions', false)
            ->assertSee(['Category', 'Money out', $category, $bank, '−$10.00', '$300.00']);
        $this->get(route('transactions.index', ['preset' => 'all', 'per_page' => 25, 'page' => 2]))->assertOk()->assertSee('26–30', false);
        // Accountant view: every debit and credit line
        $this->get(route('transactions.index', ['preset' => 'all', 'view' => 'accountant', 'per_page' => 25]))->assertOk()
            ->assertSee('of <strong>60</strong> lines', false)->assertSee('Total debits');
        $this->get(route('transactions.index', ['preset' => 'all', 'per_page' => 999]))->assertSessionHasErrors('per_page');
    }

    public function test_long_general_ledger_is_paged_on_screen_but_printed_in_full(): void
    {
        foreach (range(1, 60) as $i) {
            $this->post2(CarbonImmutable::parse('2026-01-01')->addDays($i)->toDateString(), '6200', '10', '1030');
        }
        $query = ['report' => 'ACC-22', 'preset' => 'custom', 'start' => '2026-01-01', 'end' => '2026-12-31'];
        $this->actingAs($this->owner);

        $print = $this->get(route('reports.show', $query + ['print' => 1]))->assertOk();
        $this->assertNull($print->viewData('paginator'));
        $allRows = count($print->viewData('document')->tables[0]['rows']);
        $this->assertGreaterThan(ReportController::ROWS_PER_PAGE, $allRows);

        // Page 2 = the rest of the rows, plus the repeated "(continued)" account heading on top
        $page2 = $this->get(route('reports.show', $query + ['page' => 2]))->assertOk();
        $page2->assertSee('(continued)')->assertSee('Showing <strong>101–', false);
        $rows = $page2->viewData('document')->tables[0]['rows'];
        $this->assertCount($allRows - ReportController::ROWS_PER_PAGE + 1, $rows);
        $this->assertSame('heading', $rows[0]['style']);
    }

    public function test_add_on_reports_stay_locked_until_bought(): void
    {
        $this->actingAs($this->owner)->get(route('reports.show', 'ACC-30'))->assertOk()->assertSee('add-on');
    }

    public function test_journal_entries_of_another_company_are_not_found(): void
    {
        [$other] = $this->payingCustomer('other@acme.test');
        $entry = $this->post2('2026-02-01', '6200', '100', '1030');

        $this->actingAs($other)->get(route('journal.show', $entry))->assertNotFound();
        $this->actingAs($other)->post(route('journal.reverse', $entry))->assertNotFound();
    }

    public function test_journal_entry_form_posts_and_rejects_unbalanced_input(): void
    {
        $this->actingAs($this->owner);
        $lines = [['account_id' => $this->accountId('6200'), 'debit' => '75.50'], ['account_id' => $this->accountId('1030'), 'credit' => '75.50']];

        $this->post(route('journal.store'), ['entry_date' => '2026-03-01', 'description' => 'Parking', 'lines' => $lines])
            ->assertRedirect();
        $this->assertSame(1, JournalEntry::count());

        $lines[1]['credit'] = '70.00';
        $this->post(route('journal.store'), ['entry_date' => '2026-03-01', 'description' => 'Parking', 'lines' => $lines])
            ->assertSessionHas('error');
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_accounts_can_be_added_renamed_and_hidden_but_system_accounts_stay(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('accounts.store'), ['code' => '62950', 'name' => 'Parking', 'type' => 'expense', 'section' => 'Operating Expenses'])
            ->assertSessionHasNoErrors();
        $parking = $this->company->accounts()->where('code', '62950')->firstOrFail();
        $this->assertSame('debit', $parking->normal_balance);

        $this->patch(route('accounts.update', $parking), ['name' => 'Parking & tolls', 'is_active' => '0']);
        $this->assertFalse($parking->fresh()->is_active);
        $this->assertSame('Parking & tolls', $parking->fresh()->name);

        $suspense = $this->company->systemAccount('suspense_expense');
        $this->patch(route('accounts.update', $suspense), ['is_active' => '0'])->assertSessionHas('error');
        $this->assertTrue($suspense->fresh()->is_active);
    }

    public function test_admins_are_kept_out_of_the_customer_workspace(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->actingAs($admin)->get(route('reports.index'))->assertRedirect(route('admin.overview'));
        $this->actingAs($this->owner)->get(route('admin.overview'))->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: User, 1: Company} */
    private function payingCustomer(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $company = app(CompanyProvisioner::class)->create($user, ['name' => "Business of {$email}"]);
        $company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.md5($email), 'stripe_status' => 'active', 'stripe_price' => 'price_test']);

        return [$user->fresh(), $company];
    }

    private function accountId(string $code): int
    {
        return $this->company->accounts()->where('canonical_code', $code)->value('id');
    }

    private function line(string $code, ?string $debit = null, ?string $credit = null): array
    {
        return ['account_id' => $this->accountId($code), 'debit' => $debit, 'credit' => $credit];
    }

    /** Two-line entry: debit one account, credit another. */
    private function post2(string $date, string $debitCode, string $amount, string $creditCode): JournalEntry
    {
        return $this->ledger->post($this->company, ['entry_date' => $date, 'description' => 'Test'],
            [$this->line($debitCode, $amount), $this->line($creditCode, null, $amount)], $this->owner);
    }
}
