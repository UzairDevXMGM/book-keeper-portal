<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matching the books for one bank / card account to its statement balance on a date
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('opening_balance', 15, 2);     // balance reconciled before this one
            $table->decimal('cleared_balance', 15, 2)->nullable();
            $table->enum('status', ['open', 'completed'])->default('open');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'account_id', 'status']);
        });

        // Which reconciliation cleared a ledger line (null = not cleared yet)
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignId('reconciliation_id')->nullable()->after('line_no')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciliation_id');
        });
        Schema::dropIfExists('reconciliations');
    }
};
