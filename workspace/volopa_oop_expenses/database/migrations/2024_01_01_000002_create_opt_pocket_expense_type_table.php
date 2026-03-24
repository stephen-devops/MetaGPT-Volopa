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
            $table->increments('id');
            $table->string('option', 100)->comment('Expense type name (e.g., ATM Withdrawal, Point of Sale)');
            $table->enum('amount_sign', ['positive', 'negative'])->comment('Determines if amounts should be positive or negative');
            $table->boolean('is_active')->default(true)->comment('Whether this expense type is available for selection');
            $table->integer('sort_order')->default(0)->comment('Display order in dropdowns');
            $table->timestamps();
            
            // Unique constraint to prevent duplicate expense types
            $table->unique('option', 'unique_expense_type_option');
            
            // Indexes for performance
            $table->index(['is_active', 'sort_order'], 'idx_active_sort');
            $table->index(['amount_sign'], 'idx_amount_sign');
            
            // Table configuration
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
        
        // Seed default expense types as per system constraints
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'ATM Withdrawal',
                'amount_sign' => 'negative',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Point of Sale',
                'amount_sign' => 'negative', 
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Fee & Charges',
                'amount_sign' => 'negative',
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Refund from Merchant',
                'amount_sign' => 'positive',
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now()
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