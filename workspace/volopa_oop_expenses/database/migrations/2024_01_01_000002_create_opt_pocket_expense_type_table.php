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
            $table->string('option', 255)->unique();
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
            
            // Index for performance on option lookup
            $table->index('option');
        });
        
        // Seed default expense types as specified in constraints
        $this->seedDefaultExpenseTypes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opt_pocket_expense_type');
    }
    
    /**
     * Seed default expense types as per system constraints
     */
    private function seedDefaultExpenseTypes(): void
    {
        $defaultTypes = [
            ['option' => 'ATM Withdrawal', 'amount_sign' => 'negative'],
            ['option' => 'Point of Sale', 'amount_sign' => 'negative'],
            ['option' => 'Fee & Charges', 'amount_sign' => 'negative'],
            ['option' => 'Refund from Merchant', 'amount_sign' => 'positive'],
        ];
        
        foreach ($defaultTypes as $type) {
            \DB::table('opt_pocket_expense_type')->insert([
                'option' => $type['option'],
                'amount_sign' => $type['amount_sign'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};