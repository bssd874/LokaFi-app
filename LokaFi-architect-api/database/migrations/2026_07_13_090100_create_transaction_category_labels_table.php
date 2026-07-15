<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_category_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->text('sanitized_description');
            $table->string('transaction_type');
            $table->decimal('amount', 18, 2);
            $table->string('source')->default('manual');
            $table->string('labeled_by')->default('user');
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique('transaction_id');
            $table->index(['user_id', 'category_id']);
            $table->index(['user_id', 'is_verified']);
            $table->index(['source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_category_labels');
    }
};
