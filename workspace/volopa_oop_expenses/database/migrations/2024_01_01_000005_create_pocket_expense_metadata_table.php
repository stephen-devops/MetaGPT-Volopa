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
                'transaction_category',
                'tracking_code', 
                'project',
                'file_store',
                'expense_source',
                'additional_field',
                'source_note'
            ]);
            $table->unsignedBigInteger('transaction_category_id')->nullable();
            $table->unsignedBigInteger('tracking_code_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('file_store_id')->nullable();
            $table->unsignedBigInteger('expense_source_id')->nullable();
            $table->unsignedBigInteger('additional_field_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('details_json')->nullable();
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            $table->boolean('deleted')->default(false);
            $table->dateTime('delete_time')->nullable();
            
            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expense')->onDelete('cascade');
            $table->foreign('expense_source_id')->references('id')->on('pocket_expense_source_client_config')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            // TODO: Add foreign keys for transaction_category_id, tracking_code_id, project_id, file_store_id, additional_field_id
            // These reference platform tables that are not defined in the current context
            
            // Unique constraint as specified in system constraints
            $table->unique(['pocket_expense_id', 'metadata_type', 'deleted'], 'unique_expense_metadata_type');
            
            // Indexes for performance
            $table->index(['pocket_expense_id', 'deleted']);
            $table->index(['metadata_type', 'deleted']);
            $table->index(['expense_source_id']);
            $table->index(['transaction_category_id']);
            $table->index(['tracking_code_id']);
            $table->index(['project_id']);
            $table->index(['file_store_id']);
            $table->index(['additional_field_id']);
            $table->index(['user_id']);
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