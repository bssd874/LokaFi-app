<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('analytics_version');
            $table->string('input_hash', 64);
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version');
            $table->json('structured_insight')->nullable();
            $table->string('validation_status');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index([
                'user_id',
                'period_start',
                'period_end',
                'analytics_version',
                'prompt_version',
                'input_hash',
            ], 'financial_insights_cache_lookup_index');
            $table->index(['user_id', 'generated_at']);
            $table->index(['validation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_insights');
    }
};
