<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each company's own chart of accounts.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Template code this account came from. Global categorization rules point at template codes, so they keep
            // working even after the company renumbers or renames its accounts.
            $table->string('canonical_code', 20)->nullable();
            $table->string('code', 20);
            $table->string('name', 200);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'cost_of_sales', 'expense', 'other_income', 'other_expense']);
            $table->string('subtype', 60)->nullable();
            $table->string('section', 60)->nullable();
            $table->string('report_line', 120)->nullable();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->boolean('is_bank_account')->default(false);
            $table->boolean('is_credit_card')->default(false);
            $table->string('system_key', 40)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'canonical_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
