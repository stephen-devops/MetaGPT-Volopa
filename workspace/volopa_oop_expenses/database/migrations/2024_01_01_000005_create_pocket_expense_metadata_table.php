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
        Schema::create('pocket_expense_metadata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pocket_expense_id');
            $table->enum('metadata_type', [
                'category',
                'tracking_code_type_1',
                'tracking_code_type_2',
                'project',
                'additional_field',
                'file',
                'expense_source'
            ]);
            $table->unsignedBigInteger('transaction_category_id')->nullable();
            $table->unsignedBigInteger('tracking_code_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('file_store_id')->nullable();
            $table->unsignedBigInteger('expense_source_id')->nullable();
            $table->unsignedBigInteger('additional_field_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('details_json')->nullable();
            
            // Volopa flag-based soft delete pattern
            $table->tinyInteger('deleted')->default(0);
            $table->dateTime('delete_time')->nullable();
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('transaction_category_id')->references('id')->on('transaction_category')->onDelete('set null');
            $table->foreign('tracking_code_id')->references('id')->on('tracking_codes')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('configurable_projects')->onDelete('set null');
            $table->foreign('file_store_id')->references('id')->on('file_store')->onDelete('set null');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('additional_field_id')->references('id')->on('expense_additional_field')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            // Unique constraint to prevent duplicate metadata types per expense
            $table->unique(['pocket_expense_id', 'metadata_type', 'deleted'], 'unique_expense_metadata_type');
            
            // Indexes for performance
            $table->index(['pocket_expense_id', 'metadata_type']);
            $table->index(['transaction_category_id']);
            $table->index(['tracking_code_id']);
            $table->index(['project_id']);
            $table->index(['file_store_id']);
            $table->index(['expense_source_id']);
            $table->index(['additional_field_id']);
            $table->index(['user_id']);
            $table->index(['deleted']);
            
            // Table settings
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_metadata');
    }
};