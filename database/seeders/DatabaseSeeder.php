<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            ReportSeeder::class,
            PlanSeeder::class,
            AccountTemplateSeeder::class,
            CategoryTypeSeeder::class,
            CategorizationRuleSeeder::class,
        ]);

        $this->seedAdmin();
    }

    /** Platform admin from ADMIN_EMAIL / ADMIN_PASSWORD in .env. Skipped when no password is set. */
    private function seedAdmin(): void
    {
        $email = config('bookkeeper.admin.email');
        $password = config('bookkeeper.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set in .env: admin user not created.');

            return;
        }

        $admin = User::firstOrNew(['email' => $email]);
        if (! $admin->exists) {
            $admin->fill(['name' => config('bookkeeper.admin.name'), 'password' => $password]);
        }
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'is_active' => true, 'email_verified_at' => $admin->email_verified_at ?? now()])->save();
    }
}
