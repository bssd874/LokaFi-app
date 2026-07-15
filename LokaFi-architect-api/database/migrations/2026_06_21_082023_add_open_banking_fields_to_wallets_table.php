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
        Schema::table('wallets', function (Blueprint $table) {
            $table->foreignId('bank_connection_id')
                ->nullable()
                ->constrained('bank_connections')
                ->nullOnDelete();

            $table->string('provider_code')->nullable();
            $table->string('account_number_masked')->nullable();
            $table->string('connection_status')->default('manual');
            // manual, connected, expired, failed, revoked

            $table->string('sync_source')->default('manual');
            // manual, open_banking_simulator, open_banking_provider

            $table->timestamp('last_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('bank_connection_id');
                $table->dropColumn([
                    'provider_code',
                    'account_number_masked',
                    'connection_status',
                    'sync_source',
                    'last_synced_at',
                ]);
            });
        });
    }
};
