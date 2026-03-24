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
        Schema::create('pocket_expense', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external references
            $table->string('uuid', 36)->nullable()->comment('External UUID reference');
            
            // Foreign keys - user and client context
            $table->unsignedBigInteger('user_id')->comment('User who owns this expense');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            
            // Expense details
            $table->date('date')->comment('Expense date (format: YYYY-MM-DD from DD/MM/YYYY CSV input)');
            $table->string('merchant_name', 180)->comment('Merchant/vendor name (max 180 chars per DB definition)');
            $table->text('merchant_description')->nullable()->comment('Additional merchant details');
            
            // Expense type relationship
            $table->unsignedBigInteger('expense_type')->comment('Foreign key to opt_pocket_expense_type table');
            
            // Currency and amounts
            $table->string('currency', 3)->comment('3-letter ISO currency code (e.g., GBP, EUR, USD)');
            $table->decimal('amount', 14, 2)->comment('Expense amount with precision for monetary calculations');
            
            // Additional expense fields
            $table->text('merchant_address')->nullable()->comment('Merchant address information');
            $table->decimal('vat_amount', 14, 2)->nullable()->comment('VAT amount if applicable');
            $table->text('notes')->nullable()->comment('Additional notes (trimmed, SQL injection safe)');
            
            // Status workflow
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->comment('Expense approval status workflow');
            
            // Audit fields - track who created/updated/approved
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this expense record');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->comment('User who last updated this expense');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->comment('User who approved this expense (if status=approved)');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->nullable()->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->comment('Record update timestamp');
            
            // Soft delete using Volopa legacy pattern
            $table->tinyInteger('deleted')->unsigned()->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Soft delete timestamp');
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for common queries and performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index(['date'], 'idx_date');
            $table->index(['currency'], 'idx_currency');
            $table->index(['expense_type'], 'idx_expense_type');
            $table->index(['status'], 'idx_status');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['approved_by_user_id'], 'idx_approved_by');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['client_id', 'deleted'], 'idx_client_active');
            
            // Composite index for common filtering scenarios
            $table->index(['user_id', 'client_id', 'status', 'deleted'], 'idx_user_client_status_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense');
    }
};