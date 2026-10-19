<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Report catalog. Reports linked to a plan (plan_report) are included in it; every other report is a paid add-on.
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->decimal('addon_price', 10, 2)->default(0);
            $table->string('stripe_price_id')->nullable();   // monthly add-on price in Stripe
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
