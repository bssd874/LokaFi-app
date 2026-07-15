<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'invoice_id')) {
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->after('bank_connection_id')
                    ->constrained('invoices')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('transactions', 'stellar_payment_id')) {
                $table->foreignId('stellar_payment_id')
                    ->nullable()
                    ->after('invoice_id')
                    ->constrained('stellar_payments')
                    ->nullOnDelete();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['invoice_id']);
            $table->unique('stellar_payment_id', 'transactions_stellar_payment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_stellar_payment_id_unique');
            $table->dropIndex(['invoice_id']);

            if (Schema::hasColumn('transactions', 'stellar_payment_id')) {
                $table->dropConstrainedForeignId('stellar_payment_id');
            }

            if (Schema::hasColumn('transactions', 'invoice_id')) {
                $table->dropConstrainedForeignId('invoice_id');
            }
        });
    }
};
