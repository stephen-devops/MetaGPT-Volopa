<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key to the main pocket expense record
            $table->unsignedBigInteger('pocket_expense_id');
            
            // Metadata type classification
            $table->enum('metadata_type', [
                'category',
                'tracking_code_type_1', 
                'tracking_code_type_2',
                'project',
                'additional_field',
                'file',
                'expense_source'
            ])->comment('Type of metadata being stored');
            
            // Foreign key references to various reference tables - all nullable
            $table->unsignedBigInteger('transaction_category_id')->nullable()->comment('Reference to transaction category');
            $table->unsignedBigInteger('tracking_code_id')->nullable()->comment('Reference to tracking code');
            $table->unsignedBigInteger('project_id')->nullable()->comment('Reference to configurable project');
            $table->unsignedBigInteger('file_store_id')->nullable()->comment('Reference to file storage');
            $table->unsignedBigInteger('expense_source_id')->nullable()->comment('Reference to expense source configuration');
            $table->unsignedBigInteger('additional_field_id')->nullable()->comment('Reference to additional field configuration');
            
            // User who created this metadata entry
            $table->unsignedBigInteger('user_id');
            
            // JSON field for storing additional flexible metadata
            $table->json('details_json')->nullable()->comment('Additional metadata details in JSON format');
            
            // Volopa timestamp pattern
            $table->datetime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->datetime('update_time')->nullable()->default(null);
            
            // Volopa soft delete pattern using flags
            $table->boolean('deleted')->default(false);
            $table->datetime('delete_time')->nullable();
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')
                  ->references('id')
                  ->on('pocket_expense')
                  ->onDelete('cascade');
                  
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
            
            // Foreign key constraints for reference tables - using onDelete('set null') since they're nullable
            $table->foreign('transaction_category_id', 'fk_pocket_expense_metadata_transaction_category')
                  ->references('id')
                  ->on('transaction_categories')
                  ->onDelete('set null');
                  
            $table->foreign('tracking_code_id', 'fk_pocket_expense_metadata_tracking_code')
                  ->references('id')
                  ->on('tracking_codes')
                  ->onDelete('set null');
                  
            $table->foreign('project_id', 'fk_pocket_expense_metadata_project')
                  ->references('id')
                  ->on('configurable_projects')
                  ->onDelete('set null');
                  
            $table->foreign('file_store_id', 'fk_pocket_expense_metadata_file_store')
                  ->references('id')
                  ->on('file_stores')
                  ->onDelete('set null');
                  
            $table->foreign('expense_source_id', 'fk_pocket_expense_metadata_expense_source')
                  ->references('id')
                  ->on('pocket_expense_source_client_config')
                  ->onDelete('set null');
                  
            $table->foreign('additional_field_id', 'fk_pocket_expense_metadata_additional_field')
                  ->references('id')
                  ->on('expense_additional_fields')
                  ->onDelete('set null');
            
            // Unique constraint to prevent duplicate metadata types per expense (excluding soft deleted records)
            $table->unique(['pocket_expense_id', 'metadata_type', 'deleted'], 'unique_expense_metadata_type');
            
            // Indexes for common queries
            $table->index(['pocket_expense_id', 'deleted'], 'expense_active_metadata');
            $table->index(['metadata_type', 'deleted'], 'type_active_metadata');
            $table->index(['user_id', 'deleted'], 'user_metadata');
            $table->index(['transaction_category_id'], 'category_metadata');
            $table->index(['tracking_code_id'], 'tracking_metadata');
            $table->index(['project_id'], 'project_metadata');
            $table->index(['file_store_id'], 'file_metadata');
            $table->index(['expense_source_id'], 'source_metadata');
            $table->index(['additional_field_id'], 'additional_field_metadata');
            $table->index(['deleted', 'delete_time'], 'soft_delete_index');
            $table->index(['create_time'], 'create_time_index');
            
            // Add update trigger for update_time
            DB::unprepared('
                CREATE TRIGGER pocket_expense_metadata_update_time_trigger
                BEFORE UPDATE ON pocket_expense_metadata
                FOR EACH ROW
                BEGIN
                    SET NEW.update_time = CURRENT_TIMESTAMP;
                END
            ');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Drop trigger first
        DB::unprepared('DROP TRIGGER IF EXISTS pocket_expense_metadata_update_time_trigger');
        
        Schema::dropIfExists('pocket_expense_metadata');
    }
};