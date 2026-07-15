<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_email', 150)->nullable();
            $table->text('description');
            $table->string('fiat_currency', 10)->default('IDR');
            $table->decimal('fiat_amount', 18, 2);
            $table->decimal('demo_exchange_rate', 18, 8);
            $table->string('stellar_asset_code', 12)->default('XLM');
            $table->decimal('stellar_amount', 20, 7);
            $table->string('recipient_public_key', 56);
            $table->string('payment_memo', 28)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
