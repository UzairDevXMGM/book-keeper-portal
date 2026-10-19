<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's chart of accounts library: a master chart every business gets, plus accounts that only belong to
 * one industry. Template rows with an industry are added only for companies in that industry, so the same code can
 * exist once per industry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_templates', function (Blueprint $table) {
            $table->string('industry', 120)->nullable()->after('id');
            $table->dropUnique(['code']);
            $table->unique(['industry', 'code']);
            $table->index('industry');
        });
    }

    public function down(): void
    {
        Schema::table('account_templates', function (Blueprint $table) {
            $table->dropUnique(['industry', 'code']);
            $table->dropIndex(['industry']);
            $table->dropColumn('industry');
            $table->unique('code');
        });
    }
};
