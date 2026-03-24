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
            $table->increments('id');
            $table->unsignedInteger('pocket_expense_id')->comment('Foreign key to pocket_expense table');
            $table->enum('metadata_type', [
                'category',
                'tracking_code_type_1', 
                'tracking_code_type_2',
                'project',
                'additional_field',
                'file',
                'expense_source'
            ])->comment('Type of metadata being stored');
            $table->unsignedInteger('transaction_category_id')->nullable()->comment('Foreign key to transaction category reference table');
            $table->unsignedInteger('tracking_code_id')->nullable()->comment('Foreign key to tracking code reference table');
            $table->unsignedInteger('project_id')->nullable()->comment('Foreign key to project reference table');
            $table->unsignedInteger('file_store_id')->nullable()->comment('Foreign key to file storage reference table');
            $table->unsignedInteger('expense_source_id')->nullable()->comment('Foreign key to pocket_expense_source_client_config table');
            $table->unsignedInteger('additional_field_id')->nullable()->comment('Foreign key to additional field reference table');
            $table->unsignedBigInteger('user_id')->comment('User associated with this metadata entry');
            $table->json('details_json')->nullable()->comment('Additional metadata stored as JSON');
            $table->datetime('create_time')->nullable()->comment('Volopa legacy timestamp for creation');
            $table->datetime('update_time')->nullable()->comment('Volopa legacy timestamp for updates');
            $table->boolean('deleted')->default(false)->comment('Flag-based soft delete indicator');
            $table->datetime('delete_time')->nullable()->comment('Timestamp when metadata was soft deleted');
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['pocket_expense_id'], 'idx_pocket_expense');
            $table->index(['metadata_type'], 'idx_metadata_type');
            $table->index(['user_id'], 'idx_user');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['expense_source_id'], 'idx_expense_source');
            $table->index(['transaction_category_id'], 'idx_transaction_category');
            $table->index(['tracking_code_id'], 'idx_tracking_code');
            $table->index(['project_id'], 'idx_project');
            $table->index(['file_store_id'], 'idx_file_store');
            $table->index(['additional_field_id'], 'idx_additional_field');
            
            // Composite indexes for common queries
            $table->index(['pocket_expense_id', 'metadata_type'], 'idx_expense_metadata_type');
            $table->index(['pocket_expense_id', 'deleted'], 'idx_expense_active');
            $table->index(['user_id', 'metadata_type'], 'idx_user_metadata_type');
            
            // Table configuration
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