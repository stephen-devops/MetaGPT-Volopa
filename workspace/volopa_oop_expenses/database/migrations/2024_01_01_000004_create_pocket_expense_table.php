<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pocket_expense table for storing out-of-pocket expenses.
 * This is the core table for the OOP expense management system that stores
 * individual expense records with relationships to users, clients, and expense types.
 * Includes soft delete pattern and audit fields for tracking changes.
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
        Schema::create('pocket_expense', function (Blueprint $table) {
            // Primary key
            $table->increments('id')->comment('Primary key for pocket expense');
            
            // UUID for external references
            $table->string('uuid', 36)->unique()->comment('Unique identifier for external references');
            
            // Foreign key relationships
            $table->unsignedInteger('user_id')->comment('The user who owns this expense');
            $table->unsignedInteger('client_id')->comment('The client context for this expense');
            
            // Core expense fields
            $table->date('date')->comment('The date when the expense occurred');
            $table->string('merchant_name', 180)->comment('The name of the merchant/vendor');
            $table->string('merchant_description', 255)->nullable()->comment('Additional description of the merchant/transaction');
            $table->unsignedInteger('expense_type')->comment('Foreign key to opt_pocket_expense_type table');
            $table->string('currency', 3)->comment('ISO 3-letter currency code');
            $table->decimal('amount', 15, 2)->comment('The expense amount in the specified currency');
            $table->string('merchant_address', 500)->nullable()->comment('Address of the merchant');
            $table->decimal('vat_amount', 15, 2)->nullable()->comment('VAT/tax amount if applicable');
            $table->text('notes')->nullable()->comment('Additional notes or comments about the expense');
            
            // Status and approval workflow
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])
                  ->default('draft')
                  ->comment('Current status of the expense in the approval workflow');
            
            // Audit trail fields
            $table->unsignedInteger('created_by_user_id')->comment('User who created this expense record');
            $table->unsignedInteger('updated_by_user_id')->nullable()->comment('User who last updated this expense record');
            $table->unsignedInteger('approved_by_user_id')->nullable()->comment('User who approved this expense (if applicable)');
            
            // Custom timestamp pattern using create_time and update_time
            $table->dateTime('create_time')->comment('Timestamp when the record was created');
            $table->dateTime('update_time')->comment('Timestamp when the record was last updated');
            
            // Soft delete pattern using deleted flag and delete_time
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Timestamp when the record was soft deleted');
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id'], 'idx_client_id');
            $table->index(['expense_type'], 'idx_expense_type');
            $table->index(['status'], 'idx_status');
            $table->index(['date'], 'idx_date');
            $table->index(['currency'], 'idx_currency');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['updated_by_user_id'], 'idx_updated_by');
            $table->index(['approved_by_user_id'], 'idx_approved_by');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            $table->index(['uuid'], 'idx_uuid');
            
            // Composite indexes for common queries
            $table->index(['client_id', 'user_id', 'status'], 'idx_client_user_status');
            $table->index(['client_id', 'date', 'deleted'], 'idx_client_date_deleted');
            
            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('expense_type')
                  ->references('id')
                  ->on('opt_pocket_expense_type')
                  ->onDelete('restrict')
                  ->onUpdate('cascade');
                  
            $table->foreign('created_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict')
                  ->onUpdate('cascade');
                  
            $table->foreign('updated_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null')
                  ->onUpdate('cascade');
                  
            $table->foreign('approved_by_user_id')
                  ->references('id')
                  ->on('users')
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
        Schema::dropIfExists('pocket_expense');
    }
};