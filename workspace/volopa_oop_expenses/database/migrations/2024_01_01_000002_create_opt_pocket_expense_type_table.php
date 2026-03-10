<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the opt_pocket_expense_type table for managing expense type categories
 * with their corresponding amount signs (positive for refunds, negative for expenses).
 * This is a lookup table that categorizes different types of out-of-pocket expenses.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('opt_pocket_expense_type', function (Blueprint $table) {
            // Primary key
            $table->increments('id')->comment('Primary key for expense type');
            
            // Core fields
            $table->string('option', 100)->comment('The expense type name/option');
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative')->comment('Whether this expense type results in positive (refund) or negative (expense) amounts');
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['option'], 'idx_option');
            $table->index(['amount_sign'], 'idx_amount_sign');
            
            // Unique constraint to prevent duplicate expense type names
            $table->unique(['option'], 'uk_option');
        });
        
        // Insert default expense types
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'Travel',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Meals',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Entertainment',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Office Supplies',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Transportation',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Accommodation',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Communications',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Training',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Equipment',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Other',
                'amount_sign' => 'negative',
            ],
            [
                'option' => 'Refund',
                'amount_sign' => 'positive',
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('opt_pocket_expense_type');
    }
};