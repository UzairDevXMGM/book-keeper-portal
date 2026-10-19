<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business whose books we keep. Created at registration; owned by the user who signed up.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->enum('business_type', [
                'sole_proprietor', 'single_member_llc', 'multi_member_llc', 'partnership', 's_corp', 'c_corp', 'nonprofit',
            ])->nullable();
            $table->string('industry', 150)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('state', 2)->nullable();
            $table->char('country', 2)->default('US');
            $table->char('currency', 3)->default('USD');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->enum('accounting_method', ['cash', 'accrual'])->default('cash');
            $table->date('books_start_date')->nullable();
            // No posting, editing or reversing on or before this date (closed period)
            $table->date('locked_through')->nullable();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
