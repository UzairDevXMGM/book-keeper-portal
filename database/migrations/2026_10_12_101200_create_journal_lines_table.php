<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger lines: the single source for the General Ledger, Trial Balance, P&L and Balance Sheet.
        // company_id and entry_date are copied from the entry so date-range reports never need a join.
        // Sum of debits = sum of credits per entry is enforced by the posting service inside a DB transaction.
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->index(['company_id', 'account_id', 'entry_date']);
            $table->index(['company_id', 'entry_date']);
        });

        // Every line is either a debit or a credit: never negative, never both, never zero.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side
                CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND debit + credit > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
