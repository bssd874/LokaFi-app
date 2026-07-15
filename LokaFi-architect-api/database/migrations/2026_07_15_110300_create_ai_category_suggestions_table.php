<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_category_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->string('provider', 60);
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 40);
            $table->string('input_hash', 64);
            $table->json('sanitized_input_snapshot')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('needs_review')->default(true);
            $table->string('validation_status', 40);
            $table->string('error_code', 80)->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'input_hash', 'validation_status'], 'ai_category_suggestions_cache_index');
            $table->index(['transaction_id']);
            $table->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_category_suggestions');
    }
};
