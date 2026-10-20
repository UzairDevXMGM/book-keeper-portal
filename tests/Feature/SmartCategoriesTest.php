<?php

namespace Tests\Feature;

use App\Models\CategoryType;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\JournalEntry;
use App\Models\Upload;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\CategorizationRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The year picked before a dump, the category shown on every line, and Smart Assist for merchants no rule knows. */
class SmartCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class, CategorizationRuleSeeder::class, BusinessTypeSeeder::class]);
        $this->owner = User::factory()->create(['email' => 'owner@maple.test']);
        $this->company = app(CompanyProvisioner::class)->create($this->owner, ['name' => 'Maple Test Co', 'industry' => 'General Business']);
        $this->company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_maple', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->owner = $this->owner->fresh();
    }

    // ---------------------------------------------------------------- year

    public function test_a_year_must_be_picked_before_the_data_is_dumped(): void
    {
        $csv = "Date,Description,Amount\n03/02/2026,SOMETHING,-5.00";

        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form('a.csv', $csv, null))->assertSessionHasErrors('year');
        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form('a.csv', $csv, 1990))->assertSessionHasErrors('year');
        $this->assertSame(0, Upload::count());

        $this->get(route('uploads.index'))->assertOk()->assertSee('Which year are these statements for?')->assertSee('name="year"', false);
    }

    public function test_lines_from_another_year_are_kept_out_of_the_books_and_shown_clearly(): void
    {
        $csv = "Date,Description,Amount\n12/30/2025,MAPLEWOOD DINER,-20.00\n01/04/2026,KESTREL TRAVEL CENTER #88,-61.00";
        $upload = $this->upload('mixed.csv', $csv, 2026);

        $old = $upload->rows()->where('description', 'MAPLEWOOD DINER')->firstOrFail();
        $this->assertSame(ImportRow::STATUS_OTHER_YEAR, $old->status);
        $this->assertNull($old->journal_entry_id);
        $this->assertStringContainsString('Dated in 2025. You picked 2026', $old->error_message);
        $this->assertSame(1, $upload->rows_posted);
        $this->assertSame(1, JournalEntry::where('company_id', $this->company->id)->count());

        $this->actingAs($this->owner)->get(route('uploads.show', $upload))->assertOk()->assertSee('Different year');
        $this->get(route('uploads.index', ['year' => 2026]))->assertOk()->assertSee('mixed.csv');
    }

    public function test_the_same_file_can_be_dumped_once_per_year(): void
    {
        $csv = "Date,Description,Amount\n03/02/2026,MAPLEWOOD DINER,-20.00";
        $this->upload('same.csv', $csv, 2026);

        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form('same.csv', $csv, 2026))->assertSessionHas('error');
        $second = $this->upload('same.csv', $csv, 2025);
        $this->assertSame(ImportRow::STATUS_OTHER_YEAR, $second->rows()->firstOrFail()->status, 'a 2026 line in a 2025 dump stays out');
        $this->assertSame(1, JournalEntry::where('company_id', $this->company->id)->count());
    }

    // ---------------------------------------------------------------- Smart Assist

    public function test_smart_assist_categorizes_merchants_no_rule_knows_from_their_words(): void
    {
        $csv = "Date,Description,Amount\n"
            ."03/02/2026,SQ *MAPLEWOOD SANDWICHES 0412,-20.00\n"
            ."03/03/2026,KESTREL DIESEL #88,-61.00\n"
            ."03/04/2026,BRIGHTPATH CLOUD SUBSCRIPTION,-12.00\n"
            ."03/05/2026,QWXZ HOLDINGS 7781,-40.00";
        $upload = $this->upload('smart.csv', $csv, 2026);
        $row = fn (string $text) => $upload->rows()->with('categoryType', 'account')->where('description', 'like', "%{$text}%")->firstOrFail();

        $diner = $row('MAPLEWOOD');
        $this->assertSame(['smart', ImportRow::STATUS_POSTED, 'meals'], [$diner->match_method, $diner->status, $diner->categoryType?->slug]);
        $fuel = $row('KESTREL');
        $this->assertSame(['smart', ImportRow::STATUS_POSTED, 'Fuel'], [$fuel->match_method, $fuel->status, $fuel->categoryLabel()]);

        // A weaker clue: guessed, but waits on "Check categories" with the guess already picked
        $cloud = $row('BRIGHTPATH');
        $this->assertSame(['smart', ImportRow::STATUS_NEEDS_REVIEW, 'software'], [$cloud->match_method, $cloud->status, $cloud->categoryType?->slug]);
        $this->assertLessThan(70, $cloud->confidence);

        // Nothing to go on: no category yet
        $unknown = $row('QWXZ');
        $this->assertNull($unknown->categoryLabel());

        // The category is written next to every line on the results and transactions pages
        $this->actingAs($this->owner)->get(route('uploads.show', $upload))->assertOk()
            ->assertSee(['Meals &amp; Entertainment', 'Fuel', 'Smart Assist', 'Needs a category'], false);
        $this->get(route('transactions.index', ['preset' => 'all']))->assertOk()
            ->assertSee(['data-current="Meals &amp; Entertainment"', 'data-current="Fuel"'], false);
        $fuelType = CategoryType::where('slug', 'fuel')->value('id');
        $this->get(route('transactions.index', ['preset' => 'all', 'account' => "type:{$fuelType}"]))->assertOk()->assertSee('KESTREL')->assertDontSee('MAPLEWOOD');
        $this->get(route('transactions.index', ['preset' => 'all', 'account' => 'nonsense']))->assertSessionHasErrors('account');
        $this->get(route('review.index'))->assertOk()->assertSee('Our guess')->assertSee('BRIGHTPATH');
    }

    public function test_smart_assist_learns_from_past_choices(): void
    {
        $supplies = CategoryType::where('slug', 'supplies')->firstOrFail();
        $first = $this->upload('one.csv', "Date,Description,Amount\n03/02/2026,QWXZ HOLDINGS 1001,-40.00\n03/09/2026,QWXZ HOLDINGS 1002,-45.00", 2026);
        $this->assertSame(2, $first->rows()->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count());

        // Picked twice on "Check categories" (without "Always"), as a category rather than an account
        foreach ($first->rows as $row) {
            $this->actingAs($this->owner)->post(route('review.approve', $row), ['account_id' => "type:{$supplies->id}"])->assertSessionHas('status');
            $this->assertSame($supplies->id, $row->fresh()->category_type_id);
        }

        $next = $this->upload('two.csv', "Date,Description,Amount\n04/02/2026,QWXZ HOLDINGS 1003,-38.00", 2026);
        $learned = $next->rows()->firstOrFail();
        $this->assertSame(['history', ImportRow::STATUS_POSTED, $supplies->id], [$learned->match_method, $learned->status, $learned->category_type_id]);
        $this->assertGreaterThanOrEqual(80, $learned->confidence);
        $this->actingAs($this->owner)->get(route('uploads.show', $next))->assertOk()->assertSee('Learned from your past choices');
    }

    public function test_the_review_picker_only_takes_this_companys_categories_and_accounts(): void
    {
        $upload = $this->upload('x.csv', "Date,Description,Amount\n03/02/2026,QWXZ HOLDINGS 1001,-40.00", 2026);
        $row = $upload->rows()->firstOrFail();
        $other = app(CompanyProvisioner::class)->create(User::factory()->create(), ['name' => 'Other Co', 'industry' => 'General Business']);

        $this->actingAs($this->owner);
        $this->post(route('review.approve', $row), ['account_id' => 'type:999999'])->assertSessionHas('error');
        $this->post(route('review.approve', $row), ['account_id' => (string) $other->accounts()->value('id')])->assertSessionHas('error');
        $this->post(route('review.approve', $row), ['account_id' => 'drop table'])->assertSessionHasErrors('account_id');
        $this->assertSame(ImportRow::STATUS_NEEDS_REVIEW, $row->fresh()->status);
    }

    // ---------------------------------------------------------------- changing a category later

    public function test_a_posted_transaction_can_get_another_category_from_the_transactions_page(): void
    {
        $upload = $this->upload('fuel.csv', "Date,Description,Amount\n03/03/2026,KESTREL DIESEL #88,-61.00", 2026);
        $row = $upload->rows()->with('journalEntry.lines')->firstOrFail();
        $bank = $this->company->accounts()->where('code', '1030')->value('id');
        $line = $row->journalEntry->lines->firstWhere('account_id', '!=', $bank);
        $fuelAccount = $line->account_id;
        $supplies = CategoryType::where('slug', 'supplies')->firstOrFail();
        $this->actingAs($this->owner);

        $this->post(route('transactions.recategorize', $line), ['category' => "type:{$supplies->id}", 'remember' => 1])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Shopping & Supplies') && str_contains($m, 'automatically'));

        // A correcting entry on the same date moves the amount; the list and the upload show the new category
        $correction = JournalEntry::where('reference', "reclass:{$line->id}")->firstOrFail();
        $this->assertSame('2026-03-03', $correction->entry_date->toDateString());
        $this->assertEquals(0.0, (float) $this->company->journalLines()->where('account_id', $fuelAccount)->selectRaw('SUM(debit) - SUM(credit) AS n')->value('n'));
        $this->assertSame($supplies->id, $row->fresh()->category_type_id);
        $this->get(route('transactions.index', ['preset' => 'all']))->assertOk()->assertSee('data-current="Shopping &amp; Supplies"', false)->assertSee('Category set:');
        $this->assertDatabaseHas('categorization_rules', ['company_id' => $this->company->id, 'source' => 'user', 'category_type_id' => $supplies->id]);

        // Changing it again moves it from the current category, not from the original one
        $meals = CategoryType::where('slug', 'meals')->firstOrFail();
        $this->post(route('transactions.recategorize', $line), ['category' => "type:{$meals->id}"])->assertSessionHas('status');
        $suppliesAccount = $this->company->accounts()->where('code', '6500')->value('id');
        $this->assertEquals(0.0, (float) $this->company->journalLines()->where('account_id', $suppliesAccount)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS n')->value('n'));
        $this->get(route('transactions.index', ['preset' => 'all']))->assertSee('data-current="Meals &amp; Entertainment"', false);

        // Not for the bank line, nor for another company
        $bankLine = $row->journalEntry->lines->firstWhere('account_id', $bank);
        $this->post(route('transactions.recategorize', $bankLine), ['category' => "type:{$meals->id}"])->assertSessionHas('error');
        $stranger = User::factory()->create();
        app(CompanyProvisioner::class)->create($stranger, ['name' => 'Other Co', 'industry' => 'General Business'])
            ->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_other', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->actingAs($stranger->fresh())->post(route('transactions.recategorize', $line), ['category' => "type:{$meals->id}"])->assertNotFound();
    }

    public function test_my_rules_lists_the_always_choices_and_they_can_be_changed_or_deleted(): void
    {
        $upload = $this->upload('z.csv', "Date,Description,Amount\n03/02/2026,QWXZ HOLDINGS 1001,-40.00", 2026);
        $supplies = CategoryType::where('slug', 'supplies')->firstOrFail();
        $meals = CategoryType::where('slug', 'meals')->firstOrFail();
        $this->actingAs($this->owner)->post(route('review.approve', $upload->rows()->first()), ['account_id' => "type:{$supplies->id}", 'remember' => 1]);
        $rule = $this->company->categorizationRules()->where('source', 'user')->firstOrFail();

        $this->get(route('rules.index'))->assertOk()->assertSee($rule->keyword)->assertSee('Shopping &amp; Supplies', false);
        $this->patch(route('rules.update', $rule), ['category' => "type:{$meals->id}"])->assertSessionHas('status');
        $this->assertSame($meals->id, $rule->fresh()->category_type_id);

        // Another business cannot touch it
        $stranger = User::factory()->create();
        app(CompanyProvisioner::class)->create($stranger, ['name' => 'Other Co', 'industry' => 'General Business'])
            ->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_other', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->actingAs($stranger->fresh())->delete(route('rules.destroy', $rule))->assertNotFound();

        $this->actingAs($this->owner)->delete(route('rules.destroy', $rule))->assertSessionHas('status');
        $this->assertModelMissing($rule);
    }

    public function test_cash_withdrawals_and_returned_items_get_a_guess_to_check(): void
    {
        $upload = $this->upload('atm.csv', "Date,Description,Amount\n03/02/2026,ATM WITHDRAWAL 1234 MAIN ST,-200.00\n03/03/2026,RETURNED ITEM CHARGEBACK,-35.00", 2026);
        foreach ($upload->rows()->with('categoryType')->get() as $row) {
            $this->assertSame([ImportRow::STATUS_NEEDS_REVIEW, 'smart'], [$row->status, $row->match_method], $row->description);
            $this->assertNotNull($row->categoryType);
        }
    }

    // ---------------------------------------------------------------- business names

    public function test_the_kind_of_business_in_the_merchant_name_gives_the_category(): void
    {
        $csv = "Date,Description,Amount\n"
            ."03/02/2026,Fondue Chinoise,-80.00\n"
            ."03/03/2026,Eppies Restaurant,-42.00\n"
            ."03/04/2026,ABC Roofing Corp,-1200.00\n"
            ."03/05/2026,\"Eleonore Zetrenne, M D , Inc\",-150.00\n"
            ."03/06/2026,Bonum Home Health Service,-300.00\n"
            ."03/07/2026,\"Gold Coast Heavy Hauling, Inc.\",-900.00\n"
            ."03/08/2026,Crossroads of Ivanhoe,-55.00";
        $upload = $this->upload('names.csv', $csv, 2026);
        $row = fn (string $text) => $upload->rows()->with('categoryType')->where('description', 'like', "%{$text}%")->firstOrFail();

        $expect = [
            'Fondue' => ['Restaurant', 'meals', ImportRow::STATUS_POSTED],
            'Eppies' => ['Restaurant', 'meals', ImportRow::STATUS_POSTED],
            'Roofing' => ['Roofing contractor', 'repairs', ImportRow::STATUS_POSTED],
            'Hauling' => ['Trucking company', 'shipping', ImportRow::STATUS_POSTED],
            // Could be the business's or the owner's: suggested, and checked by a person
            'Zetrenne' => ['Medical practice', 'personal', ImportRow::STATUS_NEEDS_REVIEW],
            'Bonum' => ['Home health care', 'personal', ImportRow::STATUS_NEEDS_REVIEW],
        ];
        foreach ($expect as $text => [$business, $slug, $status]) {
            $line = $row($text);
            $this->assertSame([$business, $slug, $status], [$line->business_type, $line->categoryType?->slug, $line->status], $text);
        }
        // Nothing in the name says what it is: it waits on Check categories
        $this->assertSame(ImportRow::STATUS_NEEDS_REVIEW, $row('Crossroads')->status);
        $this->assertNull($row('Crossroads')->categoryLabel());

        // Shown next to the merchant on Transactions
        $this->actingAs($this->owner)->get(route('transactions.index', ['preset' => 'all']))->assertOk()
            ->assertSee(['ABC Roofing Corp', 'Roofing contractor', 'Gold Coast Heavy Hauling', 'Trucking company']);
    }

    public function test_a_business_type_beats_a_generic_one_word_pattern_but_not_a_named_merchant(): void
    {
        $upload = $this->upload('w.csv', "Date,Description,Amount\n03/02/2026,Allied Waste Services,-90.00\n03/03/2026,SHELL OIL 57444,-40.00", 2026);
        $waste = $upload->rows()->with('categoryType')->where('description', 'like', 'Allied%')->firstOrFail();
        $shell = $upload->rows()->with('categoryType')->where('description', 'like', 'SHELL%')->firstOrFail();

        $this->assertSame(['trash', 'Trash & recycling'], [$waste->categoryType?->slug, $waste->business_type]);
        $this->assertSame(['fuel', 'vendor', 'Gas station'], [$shell->categoryType?->slug, $shell->match_method, $shell->business_type]);
    }

    // ---------------------------------------------------------------- dates first, every category

    public function test_transactions_and_the_general_ledger_ask_for_the_dates_first(): void
    {
        $this->upload('d.csv', "Date,Description,Amount\n03/02/2026,Eppies Restaurant,-42.00", 2026);
        $this->actingAs($this->owner);

        $this->get(route('transactions.index'))->assertOk()->assertSee('Which dates do you want to see?')->assertDontSee('Eppies');
        $this->get(route('transactions.index', ['preset' => 'all']))->assertOk()->assertSee('Eppies');
        $this->get(route('transactions.index', ['preset' => 'custom', 'start' => '2026-03-01', 'end' => '2026-03-31']))->assertOk()->assertSee('Eppies');
        $this->get(route('transactions.index', ['preset' => 'custom', 'start' => '2026-04-01', 'end' => '2026-04-30']))->assertOk()->assertDontSee('Eppies');
        // A search from the top bar looks through every date
        $this->get(route('transactions.index', ['q' => 'Eppies']))->assertOk()->assertSee('Eppies Restaurant');

        $this->get(route('ledger'))->assertOk()->assertSee('Which dates do you want to see?')->assertDontSee('Opening balance');
        $this->get(route('ledger', ['preset' => 'all']))->assertOk()->assertSee('Opening balance')->assertSee('Eppies Restaurant');
        $this->get(route('reports.show', 'ACC-22'))->assertOk()->assertSee('Which dates do you want to see?');
    }

    public function test_a_transaction_split_over_several_categories_shows_all_of_them(): void
    {
        $id = fn (string $code) => $this->company->accounts()->where('code', $code)->value('id');
        app(\App\Services\Ledger\LedgerService::class)->post($this->company, ['entry_date' => '2026-03-15', 'description' => 'Payroll run', 'source' => 'manual'], [
            ['account_id' => $id('6010'), 'debit' => '4000'],
            ['account_id' => $id('6080'), 'debit' => '306'],
            ['account_id' => $id('1030'), 'credit' => '4306'],
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('transactions.index', ['preset' => 'all']))->assertOk()
            ->assertSee([$this->company->accounts()->where('code', '6010')->value('name'), $this->company->accounts()->where('code', '6080')->value('name')])
            ->assertDontSee('1 more');
    }

    // ---------------------------------------------------------------- getting started

    public function test_new_businesses_get_a_checklist_and_a_how_it_works_page(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('dashboard'))->assertOk()->assertSee(['Get started', '1 of 4 done', 'Dump your first statement']);
        $this->get(route('help'))->assertOk()->assertSee(['Getting started', 'Dump your data', 'Check categories', 'My rules', 'Reconcile', 'Common questions', 'Profit &amp; Loss'], false);
        $this->get('/help')->assertRedirect('/guide');

        // Steps tick themselves off: upload (all categorized), then open a report
        $this->upload('smart.csv', "Date,Description,Amount\n03/02/2026,SQ *MAPLEWOOD SANDWICHES 0412,-20.00", 2026);
        $this->get(route('dashboard'))->assertSee('3 of 4 done')->assertSee('Open Profit &amp; Loss', false);
        $this->get(route('reports.show', 'ACC-26'))->assertOk();
        $this->get(route('dashboard'))->assertDontSee('Get started');
    }

    // ---------------------------------------------------------------- helpers

    private function form(string $name, string $content, ?int $year): array
    {
        return array_filter([
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'account_id' => $this->company->accounts()->where('code', '1030')->value('id'),
            'year' => $year,
            'sign_convention' => 'auto',
            'auto_post' => 1,
        ], fn ($v) => $v !== null);
    }

    private function upload(string $name, string $content, int $year): Upload
    {
        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form($name, $content, $year))->assertSessionHasNoErrors();

        return Upload::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }
}
