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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('external_transaction_id')->nullable();
            $table->string('source')->default('manual');
            // manual, open_banking_simulator, open_banking_provider

            $table->json('raw_payload')->nullable();

            $table->index(['source', 'external_transaction_id']);
            $table->unique(['user_id', 'source', 'external_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source', 'external_transaction_id']);
            $table->dropIndex(['source', 'external_transaction_id']);

            $table->dropColumn([
                'external_transaction_id',
                'source',
                'raw_payload',
            ]);
        });
    }
};
