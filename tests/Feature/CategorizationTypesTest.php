<?php

namespace Tests\Feature;

use App\Models\CategoryType;
use App\Models\Company;
use App\Models\ImportRow;
use App\Models\Upload;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\CategorizationRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Statements;
use Tests\TestCase;

/** Categorization types (Fuel, Meals & Entertainment ...) on the client's master chart and an industry's own accounts. */
class CategorizationTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class, CategorizationRuleSeeder::class]);
    }

    public function test_merchants_are_categorized_by_type_on_the_master_chart(): void
    {
        [$owner, $company] = $this->customer('owner@acme.test', 'General Business');
        $csv = "Date,Description,Amount\n"
            ."06/02/2026,Purchase authorized on 05/31 Wal-Mart #1406 Winchester VA P000000000000101 Card 0000,-50.80\n"
            ."06/02/2026,Purchase authorized on 06/01 Love's #0771 Inside West Point GA P000000000000102 Card 0000,-24.18\n"
            ."06/03/2026,Recurring Payment authorized on 06/01 ZOOM.COM 888-799-9666 CA S000000000000103 Card 0000,-15.99\n"
            ."06/03/2026,Purchase authorized on 06/02 McDonald's F37889 East Bernard TX S000000000000104 Card 0000,-8.42\n"
            ."06/04/2026,GUSTO NET 260604 Acme Supplies,-4600.00\n"
            ."06/05/2026,MONTHLY SERVICE FEE,-25.00";
        $upload = $this->upload($owner, $company, 'checking.csv', $csv, '1030');

        $this->assertSame([
            'Wal-Mart' => '6550', "Love's" => '6800', 'ZOOM.COM' => '6540', "McDonald's" => '6550', 'GUSTO NET' => '6010', 'MONTHLY SERVICE FEE' => '7300',
        ], $this->postedCodes($upload, ['Wal-Mart', "Love's", 'ZOOM.COM', "McDonald's", 'GUSTO NET', 'MONTHLY SERVICE FEE']));

        $fuel = $upload->rows()->where('description', 'like', "%Love's%")->firstOrFail();
        $this->assertSame('Fuel', $fuel->rule->categoryType->name);
        $this->actingAs($owner)->get(route('uploads.show', $upload))->assertOk()->assertSee('Fuel')->assertSee('Meals &amp; Entertainment', false);
    }

    public function test_a_trucking_company_posts_to_its_own_industry_accounts_from_a_column_pdf(): void
    {
        [$owner, $company] = $this->customer('driver@haulers.test', 'Trucking / Freight Carrier');
        $this->assertSame('Fuel - Trucks', $company->accounts()->where('code', '5215')->value('name'));

        // Wells Fargo layout: amounts are positive; the column says whether money came in or went out
        $pdf = Statements::positionedPdf([
            800 => [[40, 'Initiate Business Checking June 30, 2026 Page 1 of 1']],
            780 => [[40, 'Beginning balance on 6/1'], [300, '$69.92']],
            766 => [[40, 'Ending balance on 6/30'], [300, '$4,735.81']],
            700 => [[80, 'Date'], [200, 'Description'], [540, 'Deposits/'], [610, 'Withdrawals/'], [700, 'Ending daily']],
            688 => [[540, 'Credits'], [610, 'Debits'], [720, 'balance']],
            670 => [[82, '6/2'], [200, 'RTS Financial SE Corp Pmt 000000000000123 Acme 101'], [541, '4,740.87']],
            658 => [[200, 'Haulers LLC']],
            646 => [[82, '6/2'], [200, "Purchase authorized on 05/31 Love's #0771 Inside West Point"], [646, '24.18']],
            622 => [[82, '6/3'], [200, 'Purchase authorized on 06/02 HCTRA EZ TAG Rebill 281-875-3279 TX'], [640, '40.00']],
            598 => [[82, '6/3'], [200, 'Purchase authorized on 06/03 Wal-Mart #5045 Tomball TX'], [646, '10.80'], [717, '4,735.81']],
            574 => [[80, 'Totals'], [541, '$4,740.87'], [640, '$74.98']],
            550 => [[80, 'Summary of checks written']],
            530 => [[82, '6/9'], [200, '1234'], [646, '50.00']],
        ]);
        $upload = $this->upload($owner, $company, '063026 WellsFargo.pdf', $pdf, '1030');

        $this->assertSame(4, $upload->rows_total, 'totals and the check summary are not transactions');
        $this->assertSame(['69.92', '4735.81'], [$upload->opening_balance, $upload->closing_balance]);
        $rts = $upload->rows()->where('description', 'like', 'RTS%')->firstOrFail();
        $this->assertSame(['in', '4740.87', 'RTS Financial SE Corp Pmt 000000000000123 Acme 101 Haulers LLC'], [$rts->direction, $rts->amount, $rts->description]);

        $this->assertSame([
            'RTS Financial' => '4205',   // factoring payout -> Line-haul Revenue
            "Love's" => '5215',          // Fuel -> Fuel - Trucks
            'HCTRA' => '5235',           // Parking & Tolls -> Tolls & Scales
            'Wal-Mart' => '6550',        // Meals & Entertainment (no trucking account for it)
        ], $this->postedCodes($upload, ['RTS Financial', "Love's", 'HCTRA', 'Wal-Mart']));
        $this->actingAs($owner)->get(route('uploads.show', $upload))->assertSee('Statement balances agree');
    }

    public function test_admin_can_repoint_a_category_move_keywords_and_set_industry_accounts(): void
    {
        [$owner, $company] = $this->customer('owner@acme.test', 'General Business');
        $admin = User::factory()->create(['email' => 'admin@bookkeeper.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $meals = CategoryType::where('slug', 'meals')->firstOrFail();
        $supplies = CategoryType::where('slug', 'supplies')->firstOrFail();

        $this->actingAs($owner)->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.categories.index'))->assertOk()->assertSee('Meals &amp; Entertainment', false)->assertSee('6550');
        $this->get(route('admin.categories.edit', ['category' => $meals, 'q' => 'WAL']))->assertOk()->assertSee('WAL-MART');

        // Meals now post to Employee Meals & Wellness for everyone
        $this->patch(route('admin.categories.update', $meals), ['name' => 'Meals & Entertainment', 'direction' => 'out', 'target' => '6170', 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->patch(route('admin.categories.update', $meals), ['name' => 'Meals', 'direction' => 'out', 'target' => 'nonsense'])->assertSessionHasErrors('target');

        // A new category with its own keyword, and Wal-Mart moved over to Shopping & Supplies
        $this->post(route('admin.categories.store'), ['name' => 'Truck Wash', 'direction' => 'out', 'target' => '6810'])->assertSessionHasNoErrors();
        $wash = CategoryType::where('name', 'Truck Wash')->firstOrFail();
        $this->post(route('admin.categories.keywords.store', $wash), ['keyword' => 'sparkle fleet', 'match_type' => 'contains', 'applies_to' => 'any'])->assertSessionHas('status');
        $this->post(route('admin.categories.keywords.store', $supplies), ['keyword' => 'WAL-MART', 'match_type' => 'contains', 'applies_to' => 'any'])
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'moved from Meals'));

        $csv = "Date,Description,Amount\n06/02/2026,SPARKLE FLEET 4471 KATY TX,-45.00\n06/03/2026,Wal-Mart #5045 Tomball TX,-10.80\n06/04/2026,McDonald's F37889,-8.42";
        $upload = $this->upload($owner, $company, 'checking.csv', $csv, '1030');
        $this->assertSame(['SPARKLE FLEET' => '6810', 'Wal-Mart' => '6500', "McDonald's" => '6170'],
            $this->postedCodes($upload, ['SPARKLE FLEET', 'Wal-Mart', "McDonald's"]));

        // Industry account: for trucking, meals go to Travel - Meals & Per Diem; an account the industry lacks is refused
        $this->actingAs($admin)->post(route('admin.categories.industries.store', $meals), ['industry' => 'Trucking / Freight Carrier', 'account_code' => '6860'])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.categories.industries.store', $meals), ['industry' => 'Trucking / Freight Carrier', 'account_code' => '9999'])
            ->assertSessionHasErrors('account_code', null, 'industry');
        [$driver, $trucking] = $this->customer('driver@haulers.test', 'Trucking / Freight Carrier');
        $truckUpload = $this->upload($driver, $trucking, 'checking.csv', "Date,Description,Amount\n06/03/2026,McDonald's F37889,-8.42", '1030');
        $this->assertSame(["McDonald's" => '6860'], $this->postedCodes($truckUpload, ["McDonald's"]));

        // Removing the keyword: the next truck wash waits for review
        $this->actingAs($admin)->delete(route('admin.categories.keywords.destroy', [$wash, $wash->rules()->firstOrFail()]))->assertSessionHas('status');
        $next = $this->upload($owner, $company, 'next.csv', "Date,Description,Amount\n06/09/2026,SPARKLE FLEET 4471 KATY TX,-45.00", '1030');
        $this->assertSame(ImportRow::STATUS_NEEDS_REVIEW, $next->rows()->value('status'));
    }

    public function test_every_old_rule_has_a_category_and_every_category_points_at_a_real_account(): void
    {
        $this->assertSame(0, \App\Models\CategorizationRule::whereNull('company_id')->whereNull('category_type_id')->count());
        foreach (CategoryType::all() as $type) {
            $this->assertTrue($type->system_key !== null || \App\Models\AccountTemplate::whereNull('industry')->where('code', $type->account_code)->exists(),
                "{$type->name} posts to {$type->account_code}, which is not in the master chart");
        }
        $fuel = CategoryType::where('slug', 'fuel')->firstOrFail();
        $this->assertSame('5215', $fuel->accountCodeFor('Trucking / Freight Carrier'));
        $this->assertSame('6800', $fuel->accountCodeFor('Dental Practice'));
        $this->assertSame('6800', $fuel->accountCodeFor('Convenience Store with Gas Station'), 'fuel freight for resale is not the owner\'s fuel');

        // Customer payments go to the industry's main sales line, never a side income such as gift card breakage
        $sales = CategoryType::where('slug', 'sales')->firstOrFail();
        $this->assertSame('4015', $sales->accountCodeFor('Retail (General Merchandise)'));
        $this->assertSame('4205', $sales->accountCodeFor('Restaurant - Full Service'));
        $this->assertSame('4000', $sales->accountCodeFor('General Business'));
    }

    /** Account code each matching line was posted to, keyed by the text it was looked up with. */
    private function postedCodes(Upload $upload, array $needles): array
    {
        $codes = [];
        foreach ($needles as $needle) {
            $row = $upload->rows()->where('description', 'like', "%{$needle}%")->firstOrFail();
            $this->assertSame(ImportRow::STATUS_POSTED, $row->status, "{$needle} was not posted ({$row->status})");
            $codes[$needle] = $row->account->code;
        }

        return $codes;
    }

    private function customer(string $email, string $industry): array
    {
        $user = User::factory()->create(['email' => $email]);
        $company = app(CompanyProvisioner::class)->create($user, ['name' => "Business of {$email}", 'industry' => $industry]);
        $company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.md5($email), 'stripe_status' => 'active', 'stripe_price' => 'price_test']);

        return [$user->fresh(), $company];
    }

    private function upload(User $user, Company $company, string $name, string $content, string $code, int $year = 2026): Upload
    {
        $this->actingAs($user)->post(route('uploads.store'), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'account_id' => $company->accounts()->where('code', $code)->value('id'),
            'year' => $year,
            'sign_convention' => 'auto',
            'auto_post' => 1,
        ])->assertSessionHasNoErrors();

        return Upload::where('company_id', $company->id)->latest('id')->firstOrFail();
    }
}
