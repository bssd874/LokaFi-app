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
        Schema::table('bank_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_connections', 'mode')) {
                $table->string('mode')->default('mock')->after('status');
            }

            if (!Schema::hasColumn('bank_connections', 'consent_session_id')) {
                $table->string('consent_session_id')->nullable()->after('mode');
            }

            if (!Schema::hasColumn('bank_connections', 'consent_state')) {
                $table->string('consent_state')->nullable()->after('consent_session_id');
            }

            if (!Schema::hasColumn('bank_connections', 'external_connection_id')) {
                $table->string('external_connection_id')->nullable()->after('consent_state');
            }

            if (!Schema::hasColumn('bank_connections', 'external_account_id')) {
                $table->string('external_account_id')->nullable()->after('external_connection_id');
            }

            if (!Schema::hasColumn('bank_connections', 'error_message')) {
                $table->text('error_message')->nullable()->after('last_synced_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            foreach ([
                'error_message',
                'external_account_id',
                'external_connection_id',
                'consent_state',
                'consent_session_id',
                'mode',
            ] as $column) {
                if (Schema::hasColumn('bank_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
