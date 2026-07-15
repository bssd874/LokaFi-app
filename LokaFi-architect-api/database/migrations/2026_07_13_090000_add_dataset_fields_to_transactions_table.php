<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('bank_connection_id')
                ->nullable()
                ->after('wallet_id')
                ->constrained('bank_connections')
                ->nullOnDelete();

            $table->text('description')->nullable()->after('merchant');
            $table->text('sanitized_description')->nullable()->after('raw_payload');
            $table->string('categorization_status')->default('unclassified')->after('sanitized_description');
            $table->string('category_source')->nullable()->after('categorization_status');
            $table->timestamp('categorized_at')->nullable()->after('category_source');
        });

        DB::table('transactions')
            ->whereNull('description')
            ->update(['description' => DB::raw('note')]);

        DB::table('transactions')
            ->whereNotNull('category_id')
            ->where('type', '!=', 'transfer')
            ->update([
                'categorization_status' => 'categorized',
                'category_source' => DB::raw("CASE WHEN source = 'manual' THEN 'user' ELSE 'imported' END"),
                'categorized_at' => DB::raw('updated_at'),
            ]);

        DB::table('transactions')
            ->whereNull('category_id')
            ->update(['category_source' => 'unclassified']);

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source', 'external_transaction_id']);
            $table->index(['user_id', 'bank_connection_id']);
            $table->index(['categorization_status']);
            $table->index(['category_source']);
            $table->unique(
                ['user_id', 'bank_connection_id', 'external_transaction_id'],
                'transactions_user_bank_external_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_bank_external_unique');
            $table->dropIndex(['user_id', 'bank_connection_id']);
            $table->dropIndex(['categorization_status']);
            $table->dropIndex(['category_source']);
            $table->unique(['user_id', 'source', 'external_transaction_id']);

            $table->dropConstrainedForeignId('bank_connection_id');
            $table->dropColumn([
                'description',
                'sanitized_description',
                'categorization_status',
                'category_source',
                'categorized_at',
            ]);
        });
    }
};
