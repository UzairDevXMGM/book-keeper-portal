<?php

namespace Tests\Feature;

use App\Models\CategorizationRule;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Models\Upload;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Ledger\LedgerService;
use App\Services\Reports\FinancialStatements;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\CategorizationRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Statements;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKING_CSV = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance
DEBIT,10/01/2026,"AMAZON MKTPLACE PMTS AMZN.COM/BILL WA",-42.19,DEBIT_CARD,957.81
CREDIT,10/02/2026,"SQUARE INC 261002 L2091",1250.00,ACH_CREDIT,2207.81
DEBIT,10/03/2026,"SHELL OIL 57444 HOUSTON TX",-58.20,DEBIT_CARD,2149.61
DEBIT,10/06/2026,"Zelle payment to J Rivera 9921",-300.00,QUICKPAY_DEBIT,1849.61
DEBIT,10/07/2026,"MONTHLY SERVICE FEE",-25.00,FEE_TRANSACTION,1824.61
DEBIT,10/07/2026,"MONTHLY SERVICE FEE",-25.00,FEE_TRANSACTION,1799.61
CSV;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class, CategorizationRuleSeeder::class]);
        $this->owner = User::factory()->create();
        $this->company = app(CompanyProvisioner::class)->create($this->owner, ['name' => 'Acme Supplies LLC']);
        $this->company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->owner = $this->owner->fresh();
    }

    public function test_a_bank_csv_is_read_categorized_and_posted(): void
    {
        $upload = $this->upload('checking.csv', self::CHECKING_CSV, '1030');

        $this->assertSame(Upload::STATUS_NEEDS_REVIEW, $upload->status);   // the Zelle payment needs a person
        $this->assertSame(6, $upload->rows_total);
        $this->assertSame(5, $upload->rows_posted);

        $fee = $upload->rows()->where('description', 'MONTHLY SERVICE FEE')->get();
        $this->assertCount(2, $fee, 'two identical fees on one day are two transactions');
        $this->assertSame('7300', $fee[0]->account->canonical_code);

        $zelle = $upload->rows()->where('description', 'like', 'Zelle%')->first();
        $this->assertSame(ImportRow::STATUS_NEEDS_REVIEW, $zelle->status);
        $this->assertSame('suspense', $zelle->match_method);

        // Square deposit: money in -> debit the bank, credit revenue
        $square = $upload->rows()->where('description', 'like', 'SQUARE%')->first()->journalEntry->lines;
        $this->assertSame('1250.00', $square->firstWhere('account_id', $this->accountId('1030'))->debit);
        $this->assertSame('1250.00', $square->firstWhere('account_id', $this->accountId('4000'))->credit);
    }

    public function test_the_same_transactions_are_never_imported_twice(): void
    {
        $this->upload('checking.csv', self::CHECKING_CSV, '1030');

        // The identical file is refused outright
        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form('checking.csv', self::CHECKING_CSV, '1030'))
            ->assertSessionHas('error');

        // An overlapping export (same lines + one new one) only imports the new line
        $overlap = self::CHECKING_CSV."\nDEBIT,10/09/2026,\"OFFICE DEPOT 123\",-19.99,DEBIT_CARD,1779.62";
        $second = $this->upload('checking-oct.csv', $overlap, '1030');
        $this->assertSame(6, $second->rows_duplicate);
        $this->assertSame(1, $second->rows_posted);
    }

    public function test_a_card_export_with_debit_and_credit_columns_posts_to_the_card(): void
    {
        $csv = "Transaction Date;Description;Category;Debit;Credit\n2026-10-02;STARBUCKS STORE 1234;Food & Drink;6.45;\n2026-10-09;PAYMENT THANK YOU;;;500.00";
        $upload = $this->upload('card.csv', $csv, '2020');

        $this->assertSame(2, $upload->rows_posted);
        $coffee = $upload->rows()->where('description', 'like', 'STARBUCKS%')->first();
        $this->assertSame('out', $coffee->direction);
        $this->assertSame('6.45', $coffee->journalEntry->lines->firstWhere('account_id', $this->accountId('2020'))->credit);   // card owed goes up
        $payment = $upload->rows()->where('description', 'PAYMENT THANK YOU')->first();
        $this->assertSame('in', $payment->direction);
        $this->assertSame('1495', $payment->account->canonical_code);   // transfers clearing
    }

    public function test_excel_statements_are_read(): void
    {
        $xlsx = Statements::xlsx([
            ['Date', 'Description', 'Amount'],
            [new \DateTimeImmutable('2026-09-03'), 'SHELL OIL 57444', -40.00],
            ['09/04/2026', 'MONTHLY SERVICE FEE', '-25.00'],
        ]);
        $upload = $this->upload('statement.xlsx', $xlsx, '1030');

        $this->assertSame(2, $upload->rows_posted);
        $this->assertSame('2026-09-03', $upload->rows()->orderBy('row_number')->first()->txn_date->toDateString());
    }

    public function test_text_pdf_statements_are_read_with_years_sections_and_balances(): void
    {
        $pdf = Statements::pdf([
            'Statement Period 12/01/2025 - 01/31/2026', 'Beginning Balance $1,000.00',
            'Deposits and Additions', '12/15 SQUARE INC PAYOUT 500.00', '01/05 CLIENT WIRE ACME 1,200.00',
            'Withdrawals', '01/10 SHELL OIL 57444 45.10', 'Ending Balance $2,654.90',
        ]);
        $upload = $this->upload('statement.pdf', $pdf, '1030');

        $this->assertSame(3, $upload->rows_total);
        $this->assertSame('1000.00', $upload->opening_balance);
        $this->assertSame('2654.90', $upload->closing_balance);
        $square = $upload->rows()->where('description', 'like', 'SQUARE%')->first();
        $this->assertSame('2025-12-15', $square->txn_date->toDateString());
        $this->assertSame('in', $square->direction);
        $this->assertSame(ImportRow::STATUS_OTHER_YEAR, $square->status, 'a December 2025 line in a 2026 dump stays out of the books');
        $this->assertNull($square->journal_entry_id);
        $this->assertSame('out', $upload->rows()->where('description', 'like', 'SHELL%')->first()->direction);

        $this->actingAs($this->owner)->get(route('uploads.show', $upload))->assertOk()->assertSee('Statement balances agree');
    }

    public function test_an_image_only_pdf_is_refused_with_a_clear_message(): void
    {
        $upload = $this->upload('scan.pdf', Statements::pdf([]), '1030');

        $this->assertSame(Upload::STATUS_FAILED, $upload->status);
        $this->assertStringContainsString('scanned image', $upload->error_message);
    }

    public function test_review_approval_can_teach_a_rule_that_clears_similar_lines(): void
    {
        $csv = "Date,Description,Amount\n10/01/2026,ZELLE TO J RIVERA 1,-300.00\n10/15/2026,ZELLE TO J RIVERA 2,-300.00\n10/20/2026,ODD THING,-9.00";
        $upload = $this->upload('zelle.csv', $csv, '1030');
        $this->assertSame(3, $upload->rows()->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count());

        $first = $upload->rows()->orderBy('row_number')->first();
        $this->actingAs($this->owner)->post(route('review.approve', $first), [
            'account_id' => $this->accountId('5110'), 'remember' => 1, 'keyword' => 'ZELLE TO J RIVERA',
        ])->assertSessionHas('status');

        $this->assertSame(2, $upload->rows()->where('status', ImportRow::STATUS_POSTED)->count(), 'the second Zelle line used the new rule');
        $this->assertDatabaseHas('categorization_rules', ['company_id' => $this->company->id, 'keyword' => 'ZELLE TO J RIVERA', 'source' => 'user']);

        // The next upload uses the rule straight away
        $next = $this->upload('next.csv', "Date,Description,Amount\n11/01/2026,ZELLE TO J RIVERA 3,-300.00", '1030');
        $this->assertSame(1, $next->rows_posted);
        $this->assertSame('user', $next->rows()->first()->rule->source);
    }

    public function test_bulk_review_uses_the_account_picked_in_each_row_and_can_ignore(): void
    {
        $upload = $this->upload('odd.csv', "Date,Description,Amount\n10/01/2026,ODD ONE,-10.00\n10/02/2026,ODD TWO,-20.00", '1030');
        [$one, $two] = $upload->rows()->orderBy('row_number')->get();

        $this->actingAs($this->owner)->post(route('review.bulk'), [
            'action' => 'approve', 'rows' => [$one->id], 'accounts' => [$one->id => $this->accountId('6500')],
        ])->assertSessionHas('status');
        $this->assertSame('6500', $one->fresh()->account->canonical_code);
        $this->assertSame(ImportRow::STATUS_POSTED, $one->fresh()->status);

        $this->post(route('review.bulk'), ['action' => 'ignore', 'rows' => [$two->id]]);
        $this->assertSame(ImportRow::STATUS_IGNORED, $two->fresh()->status);
        $this->assertSame(Upload::STATUS_COMPLETED, $upload->fresh()->status);
    }

    public function test_amounts_already_in_suspense_can_be_moved(): void
    {
        $entry = app(LedgerService::class)->post($this->company, ['entry_date' => '2026-10-01', 'description' => 'POS DEBIT 4471'], [
            ['account_id' => $this->accountId('6599'), 'debit' => '150.75'],
            ['account_id' => $this->accountId('1030'), 'credit' => '150.75'],
        ]);
        $line = $entry->lines->firstWhere('account_id', $this->accountId('6599'));

        $this->actingAs($this->owner)->get(route('review.index'))->assertSee('POS DEBIT 4471');
        $this->post(route('review.reclassify', $line), ['account_id' => $this->accountId('6500')])->assertSessionHas('status');

        $balances = (new FinancialStatements($this->company))->totals(null, CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(0.0, round($balances[$this->accountId('6599')]['debit'] - $balances[$this->accountId('6599')]['credit'], 2));
        $this->get(route('review.index'))->assertDontSee('POS DEBIT 4471');
    }

    public function test_reconciliation_finishes_only_at_zero_difference_and_can_lock_the_books(): void
    {
        $upload = $this->upload('checking.csv', "Date,Description,Amount\n10/01/2026,MONTHLY SERVICE FEE,-25.00\n10/02/2026,SQUARE INC 1,1000.00", '1030');
        $lines = JournalLine::where('account_id', $this->accountId('1030'))->pluck('id')->all();
        $this->actingAs($this->owner);

        $this->post(route('reconcile.store'), ['account_id' => $this->accountId('1030'), 'statement_date' => '2026-10-02', 'statement_balance' => '975.00'])
            ->assertSessionHasNoErrors();
        $rec = Reconciliation::firstOrFail();

        $this->patch(route('reconcile.update', $rec), ['cleared' => [$lines[0]], 'finish' => 1])->assertSessionHas('error');
        $this->assertTrue($rec->fresh()->isOpen());

        $this->patch(route('reconcile.update', $rec), ['cleared' => $lines, 'finish' => 1, 'lock' => 1])->assertRedirect(route('reconcile.index'));
        $this->assertSame(Reconciliation::STATUS_COMPLETED, $rec->fresh()->status);
        $this->assertSame('975.00', $rec->fresh()->cleared_balance);
        $this->assertSame('2026-10-02', $this->company->fresh()->locked_through->toDateString());
        $this->assertSame(2, JournalLine::where('reconciliation_id', $rec->id)->count());
    }

    public function test_pages_open_and_other_companies_uploads_are_hidden(): void
    {
        $upload = $this->upload('checking.csv', self::CHECKING_CSV, '1030');
        $this->actingAs($this->owner);
        foreach ([route('uploads.index'), route('uploads.show', $upload), route('review.index'), route('reconcile.index')] as $url) {
            $this->get($url)->assertOk();
        }

        $stranger = User::factory()->create();
        $theirs = app(CompanyProvisioner::class)->create($stranger, ['name' => 'Other LLC']);
        $theirs->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_other', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->actingAs($stranger->fresh())->get(route('uploads.show', $upload))->assertNotFound();
        $this->post(route('review.approve', $upload->rows()->first()), ['account_id' => 1])->assertNotFound();
    }

    // ---------------------------------------------------------------- helpers

    private function upload(string $name, string $content, string $accountCode, int $year = 2026): Upload
    {
        $this->actingAs($this->owner)->post(route('uploads.store'), $this->form($name, $content, $accountCode, $year))->assertSessionHasNoErrors();

        return Upload::latest('id')->firstOrFail();
    }

    private function form(string $name, string $content, string $accountCode, int $year = 2026): array
    {
        return [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'account_id' => $this->accountId($accountCode),
            'year' => $year,
            'sign_convention' => 'auto',
            'auto_post' => 1,
        ];
    }

    private function accountId(string $code): int
    {
        return $this->company->accounts()->where('canonical_code', $code)->value('id');
    }
}
