<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Expense type option name
            $table->string('option', 100)->comment('Expense type name (e.g., Meals, Travel, Office Supplies)');
            
            // Amount sign determines if this expense type adds or subtracts from balance
            // Refund types are positive, expense types are negative
            $table->enum('amount_sign', ['positive', 'negative'])
                  ->default('negative')
                  ->comment('Determines if amount should be positive (refund) or negative (expense)');
            
            // Timestamps using Laravel convention
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['option'], 'option_index');
            $table->index(['amount_sign'], 'amount_sign_index');
            
            // Unique constraint on option to prevent duplicate expense types
            $table->unique(['option'], 'option_unique');
        });
        
        // Insert default expense types
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'Meals',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Travel',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Office Supplies',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Communication',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Marketing',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Training',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Other',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'option' => 'Refund',
                'amount_sign' => 'positive',
                'created_at' => now(),
                'updated_at' => now()
            ]
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