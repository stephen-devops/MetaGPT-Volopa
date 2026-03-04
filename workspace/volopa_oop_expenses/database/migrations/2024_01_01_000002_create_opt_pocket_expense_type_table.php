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
            $table->id();
            $table->string('option', 100)->comment('Expense type option name');
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative')->comment('Sign applied to expense amount');
            
            // Indexes for performance
            $table->index(['option'], 'idx_option');
            $table->index(['amount_sign'], 'idx_amount_sign');
            
            // Unique constraint for option names
            $table->unique(['option'], 'unique_option');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
        });
        
        // Insert default expense types
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'General Expense',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Travel Expense',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Meal Expense',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Office Supplies',
                'amount_sign' => 'negative'
            ],
            [
                'option' => 'Refund',
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