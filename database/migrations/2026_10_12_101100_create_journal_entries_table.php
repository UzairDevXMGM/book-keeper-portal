<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Double-entry journal header. Entries are never edited or deleted once saved: corrections are reversing entries.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('entry_number');
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->string('reference', 100)->nullable();
            $table->enum('source', ['upload', 'manual', 'opening_balance', 'adjustment', 'reversal'])->default('manual');
            $table->foreignId('upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'entry_number']);
            $table->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
