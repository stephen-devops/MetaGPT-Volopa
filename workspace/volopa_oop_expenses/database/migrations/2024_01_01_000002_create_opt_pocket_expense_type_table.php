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
            $table->increments('id')->comment('Primary key');
            $table->string('option', 128)->comment('Expense type name');
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative')->comment('Sign applied to amount based on expense type');
            
            // Set table engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Index for performance
            $table->index(['option'], 'idx_expense_type_option');
        });
        
        // Insert seed data for expense types
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'ATM Withdrawal',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Point of Sale', 
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Fee & Charges',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Refund from Merchant',
                'amount_sign' => 'positive'
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