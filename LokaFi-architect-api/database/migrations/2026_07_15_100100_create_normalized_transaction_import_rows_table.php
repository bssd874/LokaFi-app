<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_transaction_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_import_batch_id')
                ->constrained('transaction_import_batches')
                ->cascadeOnDelete();
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('dedupe_fingerprint', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['transaction_import_batch_id', 'row_number'],
                'transaction_import_rows_batch_row_unique',
            );
            $table->index(['transaction_import_batch_id', 'status'], 'transaction_import_rows_batch_status_index');
            $table->index(['dedupe_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_transaction_import_rows');
    }
};
