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
        Schema::create('opt_pocket_expense_type', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Expense type option name
            $table->string('option', 100)->comment('Expense type name (e.g., ATM Withdrawal, Point of Sale, Fee & Charges, Refund from Merchant)');
            
            // Amount sign for this expense type
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative')->comment('Sign convention for amounts: positive for refunds, negative for all others');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->nullable()->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->comment('Record update timestamp');
            
            // Indexes for common queries
            $table->index(['option'], 'idx_option');
            $table->index(['amount_sign'], 'idx_amount_sign');
        });
        
        // Seed with default expense types as per constraints
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'ATM Withdrawal',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'option' => 'Point of Sale',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'option' => 'Fee & Charges',
                'amount_sign' => 'negative',
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'option' => 'Refund from Merchant',
                'amount_sign' => 'positive',
                'create_time' => now(),
                'update_time' => now(),
            ],
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