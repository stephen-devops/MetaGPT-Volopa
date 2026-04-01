<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opt_pocket_expense_type', function (Blueprint $table) {
            $table->id();
            $table->string('option', 100);
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            
            // Unique constraint on option to prevent duplicates
            $table->unique('option');
            
            // Table settings
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
        
        // Insert seed data as per system constraints
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'ATM Withdrawal',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now()
            ],
            [
                'option' => 'Point of Sale',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now()
            ],
            [
                'option' => 'Fee & Charges',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now()
            ],
            [
                'option' => 'Refund from Merchant',
                'amount_sign' => 'positive',
                'create_time' => now(),
                'update_time' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opt_pocket_expense_type');
    }
};