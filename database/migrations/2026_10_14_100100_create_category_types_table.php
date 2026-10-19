<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorization types: "Fuel", "Meals & Entertainment", "Software & Subscriptions" ... Merchant keywords point at a
 * type, and the type says which account it posts to (a master chart code, a system account, or an industry's own
 * account). Changing where a type posts changes every keyword under it at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->enum('direction', ['in', 'out', 'any'])->default('out');
            $table->string('account_code', 20)->nullable();   // master chart code ...
            $table->string('system_key', 40)->nullable();     // ... or a system account (owner_draws, transfers_clearing)
            $table->string('industry_hint', 200)->nullable(); // pattern for finding an industry's own account
            $table->boolean('needs_review')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('category_type_industry_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_type_id')->constrained()->cascadeOnDelete();
            $table->string('industry', 120);
            $table->string('account_code', 20);
            $table->timestamps();

            $table->unique(['category_type_id', 'industry']);
        });

        Schema::table('categorization_rules', function (Blueprint $table) {
            $table->foreignId('category_type_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categorization_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_type_id');
        });
        Schema::dropIfExists('category_type_industry_accounts');
        Schema::dropIfExists('category_types');
    }
};
