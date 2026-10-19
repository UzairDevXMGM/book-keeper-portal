<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transaction lines read from an upload, staged before they are posted to the ledger.
        // Nothing read from a file is dropped silently: duplicates, ignored and unreadable lines are kept with a status.
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('txn_date')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('amount', 15, 2)->nullable();          // always positive; direction says which way the money moved
            $table->enum('direction', ['in', 'out'])->nullable();
            $table->string('bank_category', 120)->nullable();      // category text the bank put on the line, if any
            $table->json('raw')->nullable();                        // the line exactly as read from the file
            $table->char('fingerprint', 64)->nullable();            // hash of date + amount + description, for duplicate checks
            $table->foreignId('suggested_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();   // final account chosen
            $table->foreignId('rule_id')->nullable()->constrained('categorization_rules')->nullOnDelete();
            $table->string('match_method', 30)->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->enum('status', ['pending', 'needs_review', 'posted', 'duplicate', 'ignored', 'error'])->default('pending');
            $table->string('error_message')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['upload_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
