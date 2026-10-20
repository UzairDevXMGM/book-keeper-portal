<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business types: what kind of business a merchant is, told by words in its name ("Eppies Restaurant" → Restaurant,
 * "ABC Roofing Corp" → Roofing contractor, "Eleonore Zetrenne, M D, Inc" → Medical practice), and the category a
 * payment to it goes to. The type found is kept on the uploaded line so it can be shown next to the merchant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('group', 80)->nullable();          // Food & drink, Construction, Health care...
            $table->text('keywords');                          // one word or phrase per line, matched on whole words
            $table->foreignId('category_type_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['out', 'in', 'any'])->default('out');
            $table->unsignedTinyInteger('confidence')->default(72);   // below the review threshold = always checked
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('seeded_at')->nullable();             // last refreshed from business_types.json; edited later = kept
            $table->timestamps();
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->string('business_type', 120)->nullable()->after('category_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', fn (Blueprint $table) => $table->dropColumn('business_type'));
        Schema::dropIfExists('business_types');
    }
};
