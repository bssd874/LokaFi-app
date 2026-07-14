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
        Schema::create('investment_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('asset_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('wallet_id')
                ->nullable()
                ->constrained('wallets')
                ->nullOnDelete();

            $table->string('type'); 
            // buy, sell

            $table->string('mode')->default('simulation');
            // simulation, paper, real

            $table->string('status')->default('executed');
            // executed, cancelled, failed

            $table->decimal('quantity', 20, 8);
            $table->decimal('price', 20, 8);

            $table->decimal('fee', 20, 2)->default(0);
            $table->decimal('gross_amount', 20, 2);
            $table->decimal('net_amount', 20, 2);

            $table->string('currency')->default('IDR');
            $table->text('note')->nullable();

            $table->timestamp('ordered_at');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'asset_id']);
            $table->index(['type', 'status']);
            $table->index(['ordered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_orders');
    }
};
