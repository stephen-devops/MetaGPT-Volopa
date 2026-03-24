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
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key to parent expense
            $table->unsignedBigInteger('pocket_expense_id')->comment('Reference to the parent pocket expense');
            
            // Metadata type discriminator
            $table->enum('metadata_type', [
                'category',
                'tracking_code_type_1', 
                'tracking_code_type_2',
                'project',
                'additional_field',
                'file',
                'expense_source'
            ])->comment('Type of metadata being stored');
            
            // Optional foreign key relationships - only one should be populated per record
            $table->unsignedInteger('transaction_category_id')->nullable()->comment('Reference to transaction_category table when metadata_type=category');
            $table->unsignedInteger('tracking_code_id')->nullable()->comment('Reference to tracking_codes table when metadata_type=tracking_code_type_1 or tracking_code_type_2');
            $table->unsignedInteger('project_id')->nullable()->comment('Reference to configurable_projects table when metadata_type=project');
            $table->unsignedInteger('file_store_id')->nullable()->comment('Reference to file_store table when metadata_type=file');
            $table->unsignedBigInteger('expense_source_id')->nullable()->comment('Reference to pocket_expense_source_client_config table when metadata_type=expense_source');
            $table->unsignedInteger('additional_field_id')->nullable()->comment('Reference to expense_additional_field table when metadata_type=additional_field');
            $table->unsignedBigInteger('user_id')->nullable()->comment('Reference to users table for user-specific metadata');
            
            // JSON field for flexible metadata storage
            $table->json('details_json')->nullable()->comment('Additional metadata details in JSON format');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->nullable()->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->comment('Record update timestamp');
            
            // Soft delete using Volopa legacy pattern
            $table->tinyInteger('deleted')->unsigned()->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Soft delete timestamp');
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('transaction_category_id')->references('id')->on('transaction_category')->onDelete('set null');
            $table->foreign('tracking_code_id')->references('id')->on('tracking_codes')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('configurable_projects')->onDelete('set null');
            $table->foreign('file_store_id')->references('id')->on('file_store')->onDelete('set null');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('additional_field_id')->references('id')->on('expense_additional_field')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Unique constraint: prevent duplicate metadata types per expense (excluding soft-deleted)
            $table->unique(['pocket_expense_id', 'metadata_type', 'deleted'], 'unique_expense_metadata_type');
            
            // Indexes for common queries and performance
            $table->index(['pocket_expense_id'], 'idx_pocket_expense');
            $table->index(['metadata_type'], 'idx_metadata_type');
            $table->index(['pocket_expense_id', 'metadata_type'], 'idx_expense_meta_type');
            $table->index(['transaction_category_id'], 'idx_transaction_category');
            $table->index(['tracking_code_id'], 'idx_tracking_code');
            $table->index(['project_id'], 'idx_project');
            $table->index(['file_store_id'], 'idx_file_store');
            $table->index(['expense_source_id'], 'idx_expense_source');
            $table->index(['additional_field_id'], 'idx_additional_field');
            $table->index(['user_id'], 'idx_user');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['pocket_expense_id', 'deleted'], 'idx_expense_active');
            
            // Composite index for common filtering scenarios
            $table->index(['pocket_expense_id', 'metadata_type', 'deleted'], 'idx_expense_type_active');
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