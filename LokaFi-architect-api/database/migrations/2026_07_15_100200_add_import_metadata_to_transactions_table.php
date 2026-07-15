<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')
                ->nullable()
                ->after('stellar_payment_id')
                ->constrained('transaction_import_batches')
                ->nullOnDelete();

            $table->foreignId('import_row_id')
                ->nullable()
                ->after('import_batch_id')
                ->constrained('normalized_transaction_import_rows')
                ->nullOnDelete();

            $table->string('normalized_merchant')->nullable()->after('merchant');
            $table->text('normalized_description')->nullable()->after('sanitized_description');
            $table->string('dedupe_fingerprint', 64)->nullable()->after('external_transaction_id');
            $table->timestamp('imported_at')->nullable()->after('categorized_at');

            $table->index(['import_batch_id']);
            $table->index(['import_row_id']);
            $table->unique(
                ['user_id', 'source', 'dedupe_fingerprint'],
                'transactions_user_source_fingerprint_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_source_fingerprint_unique');
            $table->dropIndex(['import_batch_id']);
            $table->dropIndex(['import_row_id']);

            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropConstrainedForeignId('import_row_id');

            $table->dropColumn([
                'normalized_merchant',
                'normalized_description',
                'dedupe_fingerprint',
                'imported_at',
            ]);
        });
    }
};
