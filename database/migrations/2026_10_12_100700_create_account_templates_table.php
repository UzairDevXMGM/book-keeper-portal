<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default chart of accounts. Copied into `accounts` for every new company at registration.
        Schema::create('account_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'cost_of_sales', 'expense', 'other_income', 'other_expense']);
            $table->string('subtype', 60)->nullable();
            $table->string('section', 60)->nullable();       // e.g. Current Assets, Long-Term Liabilities
            $table->string('report_line', 120)->nullable();  // roll-up line on the P&L / Balance Sheet
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->boolean('is_bank_account')->default(false);
            $table->boolean('is_credit_card')->default(false);
            // Lets code find special accounts without hard-coding numbers: suspense_expense, opening_balance_equity, ...
            $table->string('system_key', 40)->nullable()->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_templates');
    }
};
