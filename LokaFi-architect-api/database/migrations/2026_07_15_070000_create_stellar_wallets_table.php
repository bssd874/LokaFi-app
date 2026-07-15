<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stellar_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_key', 56);
            $table->string('network', 20)->default('testnet');
            $table->string('wallet_provider', 50)->default('freighter');
            $table->timestamp('connected_at');

            $table->unique(['user_id', 'public_key', 'network']);
            $table->unique(['user_id', 'network', 'wallet_provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stellar_wallets');
    }
};
