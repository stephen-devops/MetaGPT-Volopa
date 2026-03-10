<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pocket_expense_metadata table for storing normalized metadata
 * associated with pocket expenses. This table externalizes inline fields from
 * the main expense table to support various metadata types including categories,
 * tracking codes, projects, file attachments, expense sources, and additional fields.
 * Uses soft delete pattern and supports flexible JSON details storage.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('pocket_expense_metadata', function (Blueprint $table) {
            // Primary key
            $table->increments('id')->comment('Primary key for pocket expense metadata');
            
            // Foreign key to parent pocket expense
            $table->unsignedInteger('pocket_expense_id')->comment('Foreign key to pocket_expense table');
            
            // Metadata classification
            $table->enum('metadata_type', [
                'category',
                'tracking_code', 
                'project',
                'file_attachment',
                'expense_source',
                'additional_field',
                'other'
            ])->comment('Type of metadata being stored');
            
            // Optional foreign key relationships for different metadata types
            $table->unsignedInteger('transaction_category_id')->nullable()->comment('Foreign key to transaction_category table for category metadata');
            $table->unsignedInteger('tracking_code_id')->nullable()->comment('Foreign key to tracking_code table for tracking code metadata');
            $table->unsignedInteger('project_id')->nullable()->comment('Foreign key to project table for project metadata');
            $table->unsignedInteger('file_store_id')->nullable()->comment('Foreign key to file_store table for file attachment metadata');
            $table->unsignedInteger('expense_source_id')->nullable()->comment('Foreign key to pocket_expense_source_client_config table for source metadata');
            $table->unsignedInteger('additional_field_id')->nullable()->comment('Foreign key to additional_field table for custom field metadata');
            
            // User context for metadata creation
            $table->unsignedInteger('user_id')->comment('User who created this metadata record');
            
            // Flexible JSON storage for additional details
            $table->json('details_json')->nullable()->comment('JSON field for storing flexible metadata details and configurations');
            
            // Custom timestamp pattern using create_time and update_time
            $table->dateTime('create_time')->comment('Timestamp when the metadata record was created');
            $table->dateTime('update_time')->comment('Timestamp when the metadata record was last updated');
            
            // Soft delete pattern using deleted flag and delete_time
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Timestamp when the metadata record was soft deleted');
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['pocket_expense_id'], 'idx_pocket_expense_id');
            $table->index(['metadata_type'], 'idx_metadata_type');
            $table->index(['transaction_category_id'], 'idx_transaction_category_id');
            $table->index(['tracking_code_id'], 'idx_tracking_code_id');
            $table->index(['project_id'], 'idx_project_id');
            $table->index(['file_store_id'], 'idx_file_store_id');
            $table->index(['expense_source_id'], 'idx_expense_source_id');
            $table->index(['additional_field_id'], 'idx_additional_field_id');
            $table->index(['user_id'], 'idx_user_id');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            
            // Composite indexes for common queries
            $table->index(['pocket_expense_id', 'metadata_type', 'deleted'], 'idx_expense_type_deleted');
            $table->index(['pocket_expense_id', 'deleted'], 'idx_expense_deleted');
            $table->index(['user_id', 'metadata_type'], 'idx_user_metadata_type');
            
            // Unique constraint to prevent duplicate metadata of same type for same expense
            $table->unique([
                'pocket_expense_id', 
                'metadata_type', 
                'transaction_category_id',
                'tracking_code_id',
                'project_id',
                'file_store_id',
                'expense_source_id',
                'additional_field_id',
                'deleted'
            ], 'uk_expense_metadata_unique');
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')
                  ->references('id')
                  ->on('pocket_expense')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict')
                  ->onUpdate('cascade');
                  
            // Optional foreign key constraints for metadata type relationships
            $table->foreign('expense_source_id')
                  ->references('id')
                  ->on('pocket_expense_source_client_config')
                  ->onDelete('set null')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_metadata');
    }
};