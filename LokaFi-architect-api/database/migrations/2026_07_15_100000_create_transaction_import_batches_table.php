<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('provider_code', 50)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('file_hash', 64);
            $table->unsignedInteger('file_size_bytes')->default(0);
            $table->json('detected_columns')->nullable();
            $table->json('column_mapping')->nullable();
            $table->string('status', 30)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_type', 'file_hash'], 'transaction_import_file_unique');
            $table->index(['user_id', 'status']);
            $table->index(['source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_import_batches');
    }
};
