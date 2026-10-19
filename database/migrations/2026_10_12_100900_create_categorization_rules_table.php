<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyword rules that suggest an account for an uploaded transaction line.
        // company_id NULL = global rule (points at account_code, a template code); a company's own rules point at
        // account_id and are checked first.
        Schema::create('categorization_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('source', ['bank_rule', 'vendor', 'bank_category', 'user']);
            $table->string('name', 200)->nullable();         // vendor name, for display
            $table->string('keyword', 150);
            $table->enum('match_type', ['contains', 'word', 'exact'])->default('contains');
            $table->enum('direction', ['in', 'out', 'any'])->default('any');
            $table->enum('applies_to', ['bank', 'credit_card', 'any'])->default('any');
            $table->string('account_code', 20)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 120)->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedTinyInteger('confidence')->default(85);
            $table->boolean('needs_review')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'source', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorization_rules');
    }
};
