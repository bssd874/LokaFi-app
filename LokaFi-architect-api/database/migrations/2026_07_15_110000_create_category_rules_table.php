<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type', 40);
            $table->string('pattern');
            $table->string('transaction_type')->nullable();
            $table->string('source')->nullable();
            $table->string('default_category_name')->nullable();
            $table->string('default_category_type')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('confidence')->default('high');
            $table->unsignedTinyInteger('confidence_score')->default(90);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'priority']);
            $table->index(['transaction_type']);
            $table->index(['source']);
        });

        DB::table('category_rules')->insert([
            [
                'name' => 'Default food and drink merchant keywords',
                'match_type' => 'keyword',
                'pattern' => 'warung,kopi,coffee,roti,makan,makanan,minuman,resto,restaurant,cafe,kantin',
                'transaction_type' => 'expense',
                'source' => null,
                'default_category_name' => 'Makanan dan Minuman',
                'default_category_type' => 'expense',
                'priority' => 200,
                'confidence' => 'medium',
                'confidence_score' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Default transportation keywords',
                'match_type' => 'keyword',
                'pattern' => 'gojek,grab,taxi,ojek,transport,transportasi,parkir,tol,bus,kereta,mrt',
                'transaction_type' => 'expense',
                'source' => null,
                'default_category_name' => 'Transportasi',
                'default_category_type' => 'expense',
                'priority' => 210,
                'confidence' => 'medium',
                'confidence_score' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Default admin fee keywords',
                'match_type' => 'keyword',
                'pattern' => 'admin,biaya admin,fee,charge,monthly fee',
                'transaction_type' => 'expense',
                'source' => null,
                'default_category_name' => 'Biaya Administrasi',
                'default_category_type' => 'expense',
                'priority' => 220,
                'confidence' => 'medium',
                'confidence_score' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Default income keywords',
                'match_type' => 'keyword',
                'pattern' => 'gaji,payroll,salary,invoice,stellar invoice,pemasukan,transfer masuk,cashback',
                'transaction_type' => 'income',
                'source' => null,
                'default_category_name' => 'Pemasukan',
                'default_category_type' => 'income',
                'priority' => 200,
                'confidence' => 'medium',
                'confidence_score' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('category_rules');
    }
};
