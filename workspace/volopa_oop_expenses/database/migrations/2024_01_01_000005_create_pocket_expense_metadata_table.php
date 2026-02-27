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
            $table->unsignedBigInteger('pocket_expense_id');
            $table->enum('metadata_type', [
                'category',
                'tracking_code',
                'project',
                'file_store',
                'expense_source',
                'additional_field',
                'other'
            ])->default('other');
            $table->unsignedBigInteger('transaction_category_id')->nullable();
            $table->unsignedBigInteger('tracking_code_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('file_store_id')->nullable();
            $table->unsignedBigInteger('expense_source_id')->nullable();
            $table->unsignedBigInteger('additional_field_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('details_json')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Foreign key constraints
            $table->foreign('pocket_expense_id')
                  ->references('id')
                  ->on('pocket_expense')
                  ->onDelete('cascade');
            
            $table->foreign('transaction_category_id')
                  ->references('id')
                  ->on('transaction_categories')
                  ->onDelete('set null');
            
            $table->foreign('tracking_code_id')
                  ->references('id')
                  ->on('tracking_codes')
                  ->onDelete('set null');
            
            $table->foreign('project_id')
                  ->references('id')
                  ->on('configurable_projects')
                  ->onDelete('set null');
            
            $table->foreign('file_store_id')
                  ->references('id')
                  ->on('file_stores')
                  ->onDelete('set null');
            
            $table->foreign('expense_source_id')
                  ->references('id')
                  ->on('pocket_expense_source_client_config')
                  ->onDelete('set null');
            
            $table->foreign('additional_field_id')
                  ->references('id')
                  ->on('expense_additional_fields')
                  ->onDelete('set null');
            
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            // Indexes for performance
            $table->index(['pocket_expense_id', 'deleted']);
            $table->index(['pocket_expense_id', 'metadata_type']);
            $table->index(['metadata_type', 'deleted']);
            $table->index(['transaction_category_id']);
            $table->index(['tracking_code_id']);
            $table->index(['project_id']);
            $table->index(['file_store_id']);
            $table->index(['expense_source_id']);
            $table->index(['additional_field_id']);
            $table->index(['user_id']);
            $table->index(['deleted']);

            // Composite indexes for common queries
            $table->index(['pocket_expense_id', 'metadata_type', 'deleted'], 'idx_expense_type_deleted');
            $table->index(['pocket_expense_id', 'deleted', 'create_time'], 'idx_expense_deleted_created');

            // Unique constraint to prevent duplicate metadata types per expense
            $table->unique(['pocket_expense_id', 'metadata_type', 'transaction_category_id'], 'unique_expense_type_category');
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