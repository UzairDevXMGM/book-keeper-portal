<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Stripe invoice we emailed. The unique id stops the checkout return page and the Stripe webhook
        // from both sending the same invoice.
        Schema::create('invoice_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('sent_to');
            $table->timestamp('sent_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
    }
};
