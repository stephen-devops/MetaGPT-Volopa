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
            $table->tinyInteger('amount_sign')->default(1)->comment('1 for positive, -1 for negative');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            // Indexes for performance
            $table->index('option');
            $table->index('is_active');
            $table->index(['is_active', 'sort_order']);
            $table->index('amount_sign');
            $table->index(['option', 'is_active']);

            // Unique constraint to prevent duplicate options
            $table->unique('option', 'expense_type_option_unique');
        });

        // Insert default expense types with seeder data
        DB::table('opt_pocket_expense_type')->insert([
            [
                'option' => 'Point of Sale',
                'amount_sign' => -1,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'ATM Withdrawal',
                'amount_sign' => -1,
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Fee & Charges',
                'amount_sign' => -1,
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'option' => 'Refund from Merchant',
                'amount_sign' => 1,
                'is_active' => true,
                'sort_order' => 40,
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