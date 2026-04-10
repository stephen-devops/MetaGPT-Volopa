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
            $table->increments('id')->comment('Primary key');
            $table->unsignedInteger('pocket_expense_id')->comment('Reference to pocket_expense table');
            $table->enum('metadata_type', [
                'category',
                'tracking_code_type_1',
                'tracking_code_type_2', 
                'project',
                'additional_field',
                'file',
                'expense_source'
            ])->comment('Type of metadata being stored');
            $table->unsignedInteger('transaction_category_id')->nullable()->comment('Reference to transaction_category table');
            $table->unsignedInteger('tracking_code_id')->nullable()->comment('Reference to tracking_codes table');
            $table->unsignedInteger('project_id')->nullable()->comment('Reference to configurable_projects table');
            $table->unsignedInteger('file_store_id')->nullable()->comment('Reference to file_store table');
            $table->unsignedInteger('expense_source_id')->nullable()->comment('Reference to pocket_expense_source_client_config table');
            $table->unsignedInteger('additional_field_id')->nullable()->comment('Reference to expense_additional_field table');
            $table->unsignedBigInteger('user_id')->comment('Reference to users table');
            $table->json('details_json')->nullable()->comment('Additional metadata in JSON format');
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->default(null)->comment('Record update timestamp');
            $table->tinyInteger('deleted')->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->default(null)->comment('Soft delete timestamp');
            
            // Set table engine and charset according to constraints
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('transaction_category_id')->references('id')->on('transaction_category')->onDelete('set null');
            $table->foreign('tracking_code_id')->references('id')->on('tracking_codes')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('configurable_projects')->onDelete('set null');
            $table->foreign('file_store_id')->references('id')->on('file_store')->onDelete('set null');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('additional_field_id')->references('id')->on('expense_additional_field')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate metadata per expense and type
            $table->unique(['pocket_expense_id', 'metadata_type', 'deleted'], 'unique_expense_metadata_type');
            
            // Indexes for performance
            $table->index(['pocket_expense_id'], 'idx_pocket_expense');
            $table->index(['metadata_type'], 'idx_metadata_type');
            $table->index(['user_id'], 'idx_user');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['expense_source_id'], 'idx_expense_source');
        });
        
        // Add trigger for update_time on update
        DB::unprepared('
            CREATE TRIGGER pocket_expense_metadata_update_time_trigger
            BEFORE UPDATE ON pocket_expense_metadata
            FOR EACH ROW
            BEGIN
                SET NEW.update_time = CURRENT_TIMESTAMP;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop trigger first
        DB::unprepared('DROP TRIGGER IF EXISTS pocket_expense_metadata_update_time_trigger');
        
        Schema::dropIfExists('pocket_expense_metadata');
    }
};