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
            $table->string('option', 100)->unique();
            $table->string('amount_sign', 1)->default('-');
            $table->timestamps();

            // Index for performance on option lookup
            $table->index('option');
        });

        // Seed default expense types
        $this->seedExpenseTypes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opt_pocket_expense_type');
    }

    /**
     * Seed the default expense types
     */
    private function seedExpenseTypes(): void
    {
        $expenseTypes = [
            ['option' => 'ATM Withdrawal', 'amount_sign' => '-'],
            ['option' => 'Point of Sale', 'amount_sign' => '-'],
            ['option' => 'Fee & Charges', 'amount_sign' => '-'],
            ['option' => 'Refund from Merchant', 'amount_sign' => '+'],
        ];

        foreach ($expenseTypes as $type) {
            DB::table('opt_pocket_expense_type')->insert([
                'option' => $type['option'],
                'amount_sign' => $type['amount_sign'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};