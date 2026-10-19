<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The year a statement is uploaded for, and the category each statement line was given.
 * Lines dated in another year than the one picked are kept out of the books ("other_year").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('account_id');
            $table->index(['company_id', 'year']);
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->foreignId('category_type_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'needs_review', 'posted', 'duplicate', 'ignored', 'error', 'other_year'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_type_id');
        });
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'year']);
            $table->dropColumn('year');
        });
    }
};
