<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stellar_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();

            $table->string('sender_public_key', 56);
            $table->string('receiver_public_key', 56);
            $table->string('asset_code', 12)->default('XLM');
            $table->decimal('amount', 20, 7);
            $table->string('transaction_hash', 100)->unique();
            $table->unsignedBigInteger('ledger')->nullable();
            $table->string('memo', 28);
            $table->string('network', 20)->default('testnet');
            $table->string('status', 20)->default('confirmed');
            $table->timestamp('confirmed_at')->nullable();
            $table->json('safe_raw_payload')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['network']);
            $table->index(['confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stellar_payments');
    }
};
