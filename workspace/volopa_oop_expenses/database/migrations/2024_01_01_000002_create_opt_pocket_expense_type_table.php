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
            $table->string('option', 100)->unique();
            $table->enum('amount_sign', ['positive', 'negative'])->default('negative');
            $table->timestamps();

            // Index for performance
            $table->index('option');
            $table->index('amount_sign');
        });

        // Insert default expense types
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'Business Expense',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Travel Expense',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Meal & Entertainment',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Office Supplies',
                'amount_sign' => 'negative',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Refund',
                'amount_sign' => 'positive',
                'created_at' => now(),
                'updated_at' => now(),
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