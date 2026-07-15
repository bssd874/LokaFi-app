<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->string('transaction_type');
            $table->string('source')->default('');
            $table->string('normalized_merchant')->default('');
            $table->string('description_signature')->default('');
            $table->string('confidence')->default('high');
            $table->unsignedTinyInteger('confidence_score')->default(95);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'transaction_type', 'source', 'normalized_merchant', 'description_signature'],
                'transaction_category_mappings_unique',
            );
            $table->index(['user_id', 'transaction_type']);
            $table->index(['normalized_merchant']);
            $table->index(['description_signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_category_mappings');
    }
};
