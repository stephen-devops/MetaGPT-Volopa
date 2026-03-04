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
        Schema::create('pocket_expense_metadata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pocket_expense_id')->comment('Reference to pocket_expense table');
            $table->enum('metadata_type', [
                'category',
                'tracking_code', 
                'project',
                'receipt',
                'source',
                'additional_field'
            ])->comment('Type of metadata being stored');
            $table->unsignedBigInteger('transaction_category_id')->nullable()->comment('Reference to transaction category');
            $table->unsignedBigInteger('tracking_code_id')->nullable()->comment('Reference to tracking code');
            $table->unsignedBigInteger('project_id')->nullable()->comment('Reference to project');
            $table->unsignedBigInteger('file_store_id')->nullable()->comment('Reference to file store for receipts');
            $table->unsignedBigInteger('expense_source_id')->nullable()->comment('Reference to expense source client config');
            $table->unsignedBigInteger('additional_field_id')->nullable()->comment('Reference to additional field definition');
            $table->unsignedBigInteger('user_id')->comment('User who created this metadata');
            $table->json('details_json')->nullable()->comment('Additional JSON data for flexible metadata storage');
            $table->datetime('create_time')->useCurrent()->comment('Record creation time');
            $table->datetime('update_time')->useCurrent()->useCurrentOnUpdate()->comment('Record last update time');
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->datetime('delete_time')->nullable()->comment('When record was deleted');
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('transaction_category_id')->references('id')->on('transaction_categories')->onDelete('set null');
            $table->foreign('tracking_code_id')->references('id')->on('tracking_codes')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('file_store_id')->references('id')->on('file_stores')->onDelete('set null');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('additional_field_id')->references('id')->on('additional_fields')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['pocket_expense_id'], 'idx_pocket_expense');
            $table->index(['metadata_type'], 'idx_metadata_type');
            $table->index(['transaction_category_id'], 'idx_transaction_category');
            $table->index(['tracking_code_id'], 'idx_tracking_code');
            $table->index(['project_id'], 'idx_project');
            $table->index(['file_store_id'], 'idx_file_store');
            $table->index(['expense_source_id'], 'idx_expense_source');
            $table->index(['additional_field_id'], 'idx_additional_field');
            $table->index(['user_id'], 'idx_user');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            
            // Composite indexes for common queries
            $table->index(['pocket_expense_id', 'metadata_type', 'deleted'], 'idx_expense_type_deleted');
            $table->index(['pocket_expense_id', 'deleted'], 'idx_expense_deleted');
            $table->index(['metadata_type', 'deleted'], 'idx_type_deleted');
            $table->index(['user_id', 'create_time'], 'idx_user_create_time');
            
            // Unique constraint to prevent duplicate metadata of same type per expense
            $table->unique(['pocket_expense_id', 'metadata_type'], 'unique_expense_metadata_type');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
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