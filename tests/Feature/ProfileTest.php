<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompanyProvisioner;
use Database\Seeders\AccountTemplateSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Ledger2026check';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ReportSeeder::class, PlanSeeder::class, AccountTemplateSeeder::class]);
        $this->owner = User::factory()->create(['name' => 'Test Owner', 'email' => 'owner@acme.test', 'password' => Hash::make(self::PASSWORD)]);
        $company = app(CompanyProvisioner::class)->create($this->owner, [
            'name' => 'Acme Supplies LLC', 'business_type' => 'single_member_llc', 'industry' => 'Restaurant - Full Service', 'state' => 'TX',
        ]);
        $company->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
    }

    public function test_profile_page_shows_every_section_with_header_and_footer(): void
    {
        $this->actingAs($this->owner)->get(route('profile.show'))
            ->assertOk()
            ->assertSee(['Personal information', 'Business details', 'Password', 'Plan &amp; billing'], false)
            ->assertSee('Search transactions')      // header
            ->assertSee('All rights reserved');     // footer
    }

    public function test_personal_information_is_saved_with_a_lower_case_email(): void
    {
        $this->actingAs($this->owner)->from(route('profile.show'))
            ->put(route('user-profile-information.update'), ['name' => 'Tess Owner', 'email' => 'Tess@Acme.TEST'])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('status', 'profile-information-updated');

        $this->assertSame('Tess Owner', $this->owner->fresh()->name);
        $this->assertSame('tess@acme.test', $this->owner->fresh()->email);
    }

    public function test_business_details_are_saved_and_validated(): void
    {
        $details = [
            'name' => 'Acme Kitchen LLC', 'legal_name' => 'Acme Kitchen Holdings LLC', 'phone' => '(713) 555-0199',
            'business_type' => 's_corp', 'industry' => 'Restaurant - Full Service', 'city' => 'Austin', 'state' => 'TX',
            'fiscal_year_start_month' => 7, 'accounting_method' => 'accrual',
        ];

        $this->actingAs($this->owner)->patch(route('profile.business'), $details)->assertSessionHasNoErrors();
        $company = $this->owner->fresh()->company;
        $this->assertSame('Acme Kitchen LLC', $company->name);
        $this->assertSame('s_corp', $company->business_type);
        $this->assertSame(7, $company->fiscal_year_start_month);
        $this->assertSame('accrual', $company->accounting_method);

        $this->patch(route('profile.business'), ['industry' => 'Space Mining', 'state' => 'XX'] + $details)
            ->assertSessionHasErrorsIn('updateBusiness', ['industry', 'state']);
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->actingAs($this->owner)->put(route('user-password.update'), [
            'current_password' => 'Wrong-password-1', 'password' => 'NewLedger2026', 'password_confirmation' => 'NewLedger2026',
        ])->assertSessionHasErrorsIn('updatePassword', ['current_password']);

        $this->put(route('user-password.update'), [
            'current_password' => self::PASSWORD, 'password' => 'NewLedger2026', 'password_confirmation' => 'NewLedger2026',
        ])->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check('NewLedger2026', $this->owner->fresh()->password));
    }

    public function test_admins_have_a_profile_without_business_details(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->actingAs($admin)->get(route('profile.show'))->assertOk()->assertDontSee('Business details');
        $this->actingAs($admin)->patch(route('profile.business'), ['name' => 'x'])->assertRedirect(route('admin.overview'));
    }

    public function test_a_profile_picture_can_be_added_changed_and_removed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner);

        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('me.png', self::png(60, 60))])
            ->assertRedirect(route('profile.show'))->assertSessionHas('status');
        $first = $this->owner->fresh()->avatar_path;
        Storage::disk('local')->assertExists($first);

        // Shown in the header and on the profile page, and served to its owner only
        $this->get(route('profile.show'))->assertOk()->assertSee(route('profile.photo', $this->owner), false)->assertSee('Change photo');
        $this->get(route('profile.photo', $this->owner))->assertOk();
        $stranger = User::factory()->create();
        app(CompanyProvisioner::class)->create($stranger, ['name' => 'Other Co', 'industry' => 'General Business'])
            ->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_other', 'stripe_status' => 'active', 'stripe_price' => 'price_test']);
        $this->actingAs($stranger->fresh())->get(route('profile.photo', $this->owner))->assertNotFound();

        // A new picture replaces the old file
        $this->actingAs($this->owner)->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('new.png', self::png(80, 80))]);
        Storage::disk('local')->assertMissing($first);

        // Not an image, or too small: refused with a message
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4 test')])
            ->assertSessionHasErrors('photo', null, 'updatePhoto');
        $this->post(route('profile.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('tiny.png', self::png(10, 10))])
            ->assertSessionHasErrors('photo', null, 'updatePhoto');

        $path = $this->owner->fresh()->avatar_path;
        $this->delete(route('profile.photo.destroy'))->assertRedirect(route('profile.show'));
        $this->assertNull($this->owner->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('profile.show'))->assertSee('Add photo');
    }

    /** A plain grey PNG of the given size, built without the GD extension. */
    private static function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\0".str_repeat("\x80", $width), $height);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .$chunk('IDAT', gzcompress($rows))
            .$chunk('IEND', '');
    }
}
