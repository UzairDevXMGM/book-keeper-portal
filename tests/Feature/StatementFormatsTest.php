<?php

namespace Tests\Feature;

use App\Models\CategorizationRule;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\Upload;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\Import\Categorizer;
use App\Services\Import\StatementParser;
use App\Services\Import\Values;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\CategorizationRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Statements;
use Tests\TestCase;

/** Layouts seen in real customer files: Bank of America PDFs, Chase checking and card CSVs, Citi exports re-saved in Excel. */
class StatementFormatsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class, CategorizationRuleSeeder::class]);
        $this->owner = User::factory()->create();
        $this->company = app(CompanyProvisioner::class)->create($this->owner, ['name' => 'Acme Trading LLC']);
        $this->company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->owner = $this->owner->fresh();
    }

    public function test_bank_of_america_pdf_with_wrapped_lines_two_column_checks_and_daily_balances(): void
    {
        $pdf = Statements::pdf([
            'ACME TRADING LLC ! Account # 1111 2222 3333 ! March 1, 2026 to March 31, 2026',
            'Account summary',
            'Beginning balance on March 1, 2026 $1,000.00',
            'Deposits and other credits 5,250.00',
            'Withdrawals and other debits -2,410.00',
            'Checks -1,600.00',
            'Service fees -30.00',
            'Ending balance on March 31, 2026 $2,210.00',
            'Deposits and other credits',
            'Date Description Amount',
            '03/02/26 WIRE TYPE:WIRE IN DATE:260302 TIME:1012 ET TRN:2026030200001',
            'ORIG:1/NORTHWIND FOODS INC ID:000123',
            '5,000.00',
            '03/09/26 Counter Credit 250.00',
            'Total deposits and other credits $5,250.00',
            'Withdrawals and other debits',
            'Date Description Amount',
            '03/04/26 WIRE TYPE:WIRE OUT DATE:260304 TIME:0900 ET TRN:2026030400002 SERVICE',
            'REF:0001 BNF:LAKESIDE PROPERTIES LLC ID:4455 BNF BK:FIRST BANK',
            'ID:0002 PMT DET:778899 LOAN DEPOSIT',
            '-2,000.00',
            'continued on the next page',
            'Page 2 of 3',
            '03/20/26 Zelle payment to Jane Roe for "march work"; Conf# ab12cd34 -410.00',
            'Total withdrawals and other debits -$2,410.00',
            'Checks',
            'Date Check # Amount Date Check # Amount',
            '03/10/26 101 -1,000.00 03/12/26 102* -600.00',
            'Total checks -$1,600.00',
            'Service fees',
            '03/04/26 Wire Transfer Fee -30.00',
            'Total service fees -$30.00',
            'Daily ledger balances',
            'Date Balance ($) Date Balance($)',
            '03/01 1,000.00 03/04 3,970.00',
            '03/09 4,220.00 03/10 3,220.00',
        ]);
        $upload = $this->upload('eStmt_2026-03-31.pdf', $pdf, '1030');

        $this->assertSame(7, $upload->rows_total, 'daily balances are not transactions');
        $this->assertSame(0, $upload->rows_failed);
        $this->assertSame('1000.00', $upload->opening_balance);
        $this->assertSame('2210.00', $upload->closing_balance);

        $wireIn = $upload->rows()->where('description', 'like', '%NORTHWIND%')->firstOrFail();
        $this->assertSame(['2026-03-02', 'in', '5000.00'], [$wireIn->txn_date->toDateString(), $wireIn->direction, $wireIn->amount]);

        $wireOut = $upload->rows()->where('description', 'like', '%LAKESIDE%')->firstOrFail();
        $this->assertSame(['out', '2000.00'], [$wireOut->direction, $wireOut->amount]);
        $this->assertStringContainsString('LOAN DEPOSIT', $wireOut->description, 'a wrapped line that says "deposit" is not a new section');

        $checks = $upload->rows()->where('description', 'like', 'Check #%')->orderBy('description')->get();
        $this->assertSame(['Check #101', 'Check #102'], $checks->pluck('description')->all());
        $this->assertSame(['1000.00', '600.00'], $checks->pluck('amount')->all());
        $this->assertSame('2026-03-12', $checks[1]->txn_date->toDateString());

        $fee = $upload->rows()->where('description', 'Wire Transfer Fee')->firstOrFail();
        $this->assertSame([ImportRow::STATUS_POSTED, '7300'], [$fee->status, $fee->account->canonical_code]);

        $this->actingAs($this->owner)->get(route('uploads.show', $upload))->assertOk()->assertSee('Statement balances agree');
    }

    public function test_chase_card_export_with_negative_purchases_is_read_the_right_way_round(): void
    {
        $csv = "Card,Transaction Date,Post Date,Description,Category,Type,Amount,Memo\n"
            ."1234,03/02/2026,03/03/2026,ZOOM.COM 888-799-9666,Bills & Utilities,Sale,-15.99,\n"
            ."1234,03/05/2026,03/05/2026,Payment Thank You - Web,,Payment,200.00,\n"
            ."1234,03/06/2026,03/07/2026,CORNER SHOP REFUND,Shopping,Return,12.00,";
        $upload = $this->upload('Chase1234_Activity.csv', $csv, '2020');

        $zoom = $upload->rows()->where('description', 'like', 'ZOOM%')->firstOrFail();
        $this->assertSame(['out', '6540'], [$zoom->direction, $zoom->account->canonical_code]);
        $payment = $upload->rows()->where('description', 'like', 'Payment%')->firstOrFail();
        $this->assertSame(['in', '1495'], [$payment->direction, $payment->account->canonical_code]);
        $this->assertSame('in', $upload->rows()->where('description', 'like', 'CORNER%')->value('direction'));
    }

    public function test_card_files_without_a_type_column_are_signed_by_their_amounts(): void
    {
        $rows = [['Date', 'Description', 'Amount']];
        foreach (['CAFE ONE' => -8.5, 'CAFE TWO' => -4.25, 'BOOK SHOP' => -19.99, 'FUEL STOP' => -40.0, 'PHONE CO' => -55.0, 'PAYMENT' => 120.0] as $text => $amount) {
            $rows[] = ['03/0'.count($rows).'/2026', $text, $amount];
        }

        $lines = collect(app(StatementParser::class)->parseTable($rows, StatementParser::AUTO, creditCard: true)['lines']);

        $this->assertSame('out', $lines->firstWhere('description', 'CAFE ONE')['direction']);
        $this->assertSame('in', $lines->firstWhere('description', 'PAYMENT')['direction']);
    }

    public function test_a_running_balance_column_gives_the_opening_and_closing_balance(): void
    {
        // Chase lists the newest line first and adds an empty trailing column
        $csv = "Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #\n"
            ."DEBIT,03/20/2026,\"DOMESTIC WIRE FEE\",-15.00,FEE_TRANSACTION,1685.00,,\n"
            ."CREDIT,03/10/2026,\"Online Transfer from CHK ...1234 transaction#: 99\",500.00,ACCT_XFER,1700.00,,\n"
            ."DEBIT,03/02/2026,\"Payment to Chase card ending in 1111 03/02\",-300.00,LOAN_PMT,1200.00,,";
        $upload = $this->upload('Chase5555_Activity.csv', $csv, '1030');

        $this->assertSame('1500.00', $upload->opening_balance);
        $this->assertSame('1685.00', $upload->closing_balance);
        $this->assertSame('7300', $upload->rows()->where('description', 'DOMESTIC WIRE FEE')->firstOrFail()->account->canonical_code);
        $this->assertSame('1495', $upload->rows()->where('description', 'like', 'Payment to Chase%')->firstOrFail()->account->canonical_code);
        $this->actingAs($this->owner)->get(route('uploads.show', $upload))->assertSee('Statement balances agree');
    }

    public function test_dates_turned_around_by_excel_on_a_day_first_computer_are_repaired(): void
    {
        // The bank CSV said 08/10, 08/25 and 08/03. Excel set to day-first turned 08/10 into 8 October and 08/03 into
        // 8 March, and left 08/25 as text because there is no 25th month.
        $xlsx = Statements::xlsx([
            ['Status', 'Date', 'Description', 'Debit', 'Credit'],
            ['Cleared', new \DateTimeImmutable('2026-10-08'), 'SHOP A', 10, ''],
            ['Cleared', '08/25/2026', 'SHOP B', 20, ''],
            ['Cleared', new \DateTimeImmutable('2026-03-08'), 'PAYMENT THANK YOU', '', -30],
        ]);
        $upload = $this->upload('2026-Citi.xlsx', $xlsx, '2020');

        $dates = $upload->rows()->orderBy('row_number')->get()->map(fn ($r) => $r->txn_date->toDateString())->all();
        $this->assertSame(['2026-08-10', '2026-08-25', '2026-08-03'], $dates);
    }

    public function test_two_digit_years_are_read(): void
    {
        $this->assertSame('2025-01-02', Values::date('01/02/25'));
        $this->assertSame('2025-12-31', Values::date('12/31/25'));
    }

    public function test_learned_rules_keep_the_payee_name_and_ignore_punctuation(): void
    {
        $this->assertSame('ZELLE PAYMENT FROM JANE ROE', Categorizer::keywordFor('Zelle payment from JANE ROE 26875404111'));
        $this->assertSame('ZELLE PAYMENT FROM E2E TRADERS LLC', Categorizer::keywordFor('Zelle payment from E2E TRADERS LLC WFCT0ZLMGKW2'));
        $this->assertSame('GM FINANCIAL', Categorizer::keywordFor('ORIG CO NAME:GM Financial    ORIG ID:I5221 DESC DATE:251211'));
        $this->assertSame('LAKESIDE PROPERTIES LLC', Categorizer::keywordFor('WIRE TYPE:WIRE OUT DATE:260304 BNF:LAKESIDE PROPERTIES LLC ID:4455'));
        $this->assertSame('CHECK 101', Categorizer::keywordFor('Check #101'));
        $this->assertSame('BLUE BOTTLE', Categorizer::keywordFor('SQ *BLUE BOTTLE 0423 SAN FRAN'));

        $csv = "Date,Description,Amount\n"
            ."03/02/2026,SQ *BLUE BOTTLE 0423,-6.00\n03/09/2026,BLUE BOTTLE COFFEE #12,-7.50\n"
            ."03/03/2026,Zelle payment from JANE ROE 111,500.00\n03/04/2026,Zelle payment from JOHN DOE 222,800.00";
        $upload = $this->upload('mixed.csv', $csv, '1030');
        [$coffee, , $jane] = $upload->rows()->orderBy('row_number')->get();

        $this->actingAs($this->owner);
        $this->post(route('review.approve', $jane), ['account_id' => $this->accountId('4100'), 'remember' => 1])->assertSessionHas('status');
        $this->assertSame(ImportRow::STATUS_NEEDS_REVIEW, $upload->rows()->where('description', 'like', '%JOHN DOE%')->value('status'),
            'a rule learned from one Zelle sender does not catch another');

        $this->post(route('review.approve', $coffee), ['account_id' => $this->accountId('6550'), 'remember' => 1])->assertSessionHas('status');
        $this->assertSame(ImportRow::STATUS_POSTED, $upload->rows()->where('description', 'like', 'BLUE BOTTLE COFFEE%')->value('status'));
    }

    public function test_very_long_bank_descriptions_post_and_one_bad_line_does_not_stop_a_bulk_approval(): void
    {
        // Chase wire lines run past 300 characters
        $wire = 'FEDWIRE CREDIT VIA: WELLS FARGO BANK, N.A./121000248 B/O: NORTHWIND FOODS INC '.str_repeat('REF:ABC123 IMAD: 0417I1B7033R016464 ', 10);
        $csv = "Date,Description,Amount\n03/02/2026,\"{$wire}\",5000.00\n03/03/2026,ODD THING,-9.00";
        $upload = $this->upload('long.csv', $csv, '1030');
        $rows = $upload->rows()->orderBy('row_number')->pluck('id')->all();

        $this->actingAs($this->owner)->post(route('review.bulk'), ['action' => 'approve', 'rows' => $rows])->assertSessionHas('status');

        $this->assertSame(2, $upload->rows()->where('status', ImportRow::STATUS_POSTED)->count());
        $entry = $upload->rows()->orderBy('row_number')->first()->journalEntry;
        $this->assertSame(trim($wire), $entry->description);
        $this->assertGreaterThan(255, mb_strlen($entry->description));
        $this->actingAs($this->owner)->post(route('journal.reverse', $entry))->assertSessionHasNoErrors();   // "Reversal of entry #n: ..." is longer still
    }

    public function test_bank_and_card_accounts_can_be_added_and_take_uploads(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('accounts.store'), ['kind' => 'bank', 'code' => '10400', 'name' => 'Chase Checking 8350', 'type' => 'expense'])
            ->assertRedirect(route('uploads.index', ['account' => $this->accountId('10400')]));
        $this->post(route('accounts.store'), ['kind' => 'card', 'code' => '21500', 'name' => 'Capital One', 'type' => 'expense']);
        $this->post(route('accounts.store'), ['kind' => 'regular', 'code' => '62450', 'name' => 'Website', 'type' => 'expense'])->assertSessionHas('status');

        $bank = $this->company->accounts()->where('code', '10400')->firstOrFail();
        $this->assertSame(['asset', 'Current Assets', 'Cash & Cash Equivalents', true], [$bank->type, $bank->section, $bank->report_line, $bank->is_bank_account]);
        $card = $this->company->accounts()->where('code', '21500')->firstOrFail();
        $this->assertSame(['liability', 'credit', true], [$card->type, $card->normal_balance, $card->is_credit_card]);
        $this->assertFalse($this->company->accounts()->where('code', '62450')->firstOrFail()->is_bank_account);

        $this->get(route('uploads.index', ['account' => $bank->id]))->assertOk()->assertSee('Chase Checking 8350')->assertSee('Capital One');
        $upload = $this->upload('chase.csv', "Date,Description,Amount\n03/02/2026,MONTHLY SERVICE FEE,-12.00", '10400');
        $this->assertSame(1, $upload->rows_posted);
    }

    public function test_the_rule_seeder_can_run_again_and_only_adds_missing_rules(): void
    {
        $count = CategorizationRule::whereNull('company_id')->count();
        $this->seed(CategorizationRuleSeeder::class);

        $this->assertSame($count, CategorizationRule::whereNull('company_id')->count());
        $this->assertDatabaseHas('categorization_rules', ['company_id' => null, 'keyword' => 'WIRE TRANSFER FEE', 'source' => 'bank_rule']);
    }

    private function upload(string $name, string $content, string $accountCode, int $year = 2026): Upload
    {
        $this->actingAs($this->owner)->post(route('uploads.store'), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'account_id' => $this->accountId($accountCode),
            'year' => $year,
            'sign_convention' => 'auto',
            'auto_post' => 1,
        ])->assertSessionHasNoErrors();

        return Upload::latest('id')->firstOrFail();
    }

    private function accountId(string $code): int
    {
        return $this->company->accounts()->where(fn ($q) => $q->where('canonical_code', $code)->orWhere('code', $code))->value('id');
    }
}
