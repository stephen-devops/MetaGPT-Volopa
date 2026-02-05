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
            $table->unsignedBigInteger('user_id');
            $table->json('details_json')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Indexes for performance
            $table->index(['pocket_expense_id', 'deleted']);
            $table->index(['pocket_expense_id', 'metadata_type', 'deleted'], 'expense_type_deleted_idx');
            $table->index(['metadata_type', 'deleted']);
            $table->index(['user_id', 'deleted']);
            $table->index(['transaction_category_id']);
            $table->index(['tracking_code_id']);
            $table->index(['project_id']);
            $table->index(['file_store_id']);
            $table->index(['expense_source_id']);
            $table->index(['additional_field_id']);
            $table->index('deleted');

            // Composite indexes for common query patterns
            $table->index(['pocket_expense_id', 'metadata_type'], 'expense_metadata_type_idx');
            $table->index(['user_id', 'metadata_type', 'deleted'], 'user_metadata_type_deleted_idx');

            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('transaction_category_id')->references('id')->on('transaction_categories')->onDelete('set null');
            $table->foreign('tracking_code_id')->references('id')->on('tracking_codes')->onDelete('set null');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('file_store_id')->references('id')->on('file_stores')->onDelete('set null');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('additional_field_id')->references('id')->on('additional_fields')->onDelete('set null');
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