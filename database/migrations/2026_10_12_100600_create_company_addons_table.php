<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-on reports a company has purchased. A row is kept per purchase so cancelled/expired history stays.
        Schema::create('company_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 10, 2);
            $table->enum('status', ['active', 'cancelled', 'expired'])->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Add-ons are billed as extra items on the company's Stripe subscription
            $table->string('stripe_subscription_item_id')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_addons');
    }
};
