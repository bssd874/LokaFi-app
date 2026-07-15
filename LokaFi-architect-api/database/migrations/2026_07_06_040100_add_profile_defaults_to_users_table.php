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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone')->default('Asia/Jakarta')->after('password');
            }

            if (!Schema::hasColumn('users', 'base_currency')) {
                $table->string('base_currency', 3)->default('IDR')->after('timezone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'base_currency')) {
                $table->dropColumn('base_currency');
            }

            if (Schema::hasColumn('users', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
