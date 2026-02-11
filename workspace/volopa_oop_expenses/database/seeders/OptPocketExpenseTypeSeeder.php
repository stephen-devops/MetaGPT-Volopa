<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\OptPocketExpenseType;
use Carbon\Carbon;

class OptPocketExpenseTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = Carbon::now();
        
        $expenseTypes = [
            [
                'name' => 'Travel',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'Meals',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'Office Supplies',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'Business Entertainment',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ];

        // Check if expense types already exist to avoid duplicates
        foreach ($expenseTypes as $expenseTypeData) {
            if (!OptPocketExpenseType::where('name', $expenseTypeData['name'])->exists()) {
                OptPocketExpenseType::create($expenseTypeData);
            }
        }

        // Output message for CLI
        $this->command->info('OptPocketExpenseType seeder completed. Created 4 default expense types.');
    }
}