<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank descriptions can be long (Chase wire lines run past 300 characters). Statement lines keep up to 500
 * characters, so the journal keeps the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->change();
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
    }
};
