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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            $table->string('symbol')->unique();
            $table->string('name');
            $table->string('asset_type');

            $table->string('currency')->default('IDR');
            $table->string('exchange')->nullable();

            $table->decimal('current_price', 20, 8)->default(0);
            $table->decimal('price_change_percentage', 8, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['asset_type', 'is_active']);
            $table->index(['symbol']);
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
