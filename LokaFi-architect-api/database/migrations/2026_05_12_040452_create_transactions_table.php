<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type'); // income, expense, transfer

            // Untuk income / expense
            $table->foreignId('wallet_id')
                ->nullable()
                ->constrained('wallets')
                ->nullOnDelete();

            // Untuk transfer
            $table->foreignId('from_wallet_id')
                ->nullable()
                ->constrained('wallets')
                ->nullOnDelete();

            $table->foreignId('to_wallet_id')
                ->nullable()
                ->constrained('wallets')
                ->nullOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->decimal('amount', 18, 2);
            $table->decimal('fee', 18, 2)->default(0);
            $table->string('currency', 10)->default('IDR');

            $table->string('merchant')->nullable();
            $table->text('note')->nullable();
            $table->string('reference_code')->nullable();

            $table->timestamp('happened_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
            $table->index(['wallet_id']);
            $table->index(['category_id']);
            $table->index(['happened_at']);
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
