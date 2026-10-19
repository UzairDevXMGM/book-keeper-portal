<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files dropped on the "Dump your data" screen. The file itself lives on disk; this row tracks processing.
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();  // bank / card account of the statement
            $table->string('original_name');
            $table->string('disk', 30)->default('local');
            $table->string('file_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->enum('file_type', ['pdf', 'xlsx', 'xls', 'csv']);
            $table->char('file_hash', 64);   // sha256, to spot the same file uploaded twice
            $table->enum('status', ['uploaded', 'processing', 'needs_review', 'completed', 'failed'])->default('uploaded');
            // Statement header values: opening + money in - money out must equal closing, which proves no line was missed
            $table->date('statement_start_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_posted')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
