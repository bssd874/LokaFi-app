<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('suggested_category_id')
                ->nullable()
                ->after('category_id')
                ->constrained('categories')
                ->nullOnDelete();
            $table->string('categorization_confidence')->nullable()->after('category_source');
            $table->unsignedTinyInteger('categorization_confidence_score')->nullable()->after('categorization_confidence');
            $table->string('categorization_explanation')->nullable()->after('categorization_confidence_score');

            $table->index(['suggested_category_id']);
            $table->index(['categorization_confidence']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['suggested_category_id']);
            $table->dropIndex(['categorization_confidence']);
            $table->dropConstrainedForeignId('suggested_category_id');
            $table->dropColumn([
                'categorization_confidence',
                'categorization_confidence_score',
                'categorization_explanation',
            ]);
        });
    }
};
