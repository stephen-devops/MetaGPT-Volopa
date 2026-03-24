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
            $table->increments('id');
            $table->string('uuid', 36)->unique()->comment('Unique identifier for the expense');
            $table->unsignedBigInteger('user_id')->comment('User who owns this expense');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->date('date')->comment('Date of the expense transaction');
            $table->string('merchant_name', 180)->comment('Name of the merchant/vendor');
            $table->text('merchant_description')->nullable()->comment('Description of the merchant or transaction');
            $table->unsignedInteger('expense_type')->comment('Foreign key to opt_pocket_expense_type');
            $table->string('currency', 3)->comment('3-letter ISO currency code');
            $table->decimal('amount', 14, 2)->comment('Expense amount (sign determined by expense type)');
            $table->string('merchant_address')->nullable()->comment('Address of the merchant');
            $table->decimal('vat_amount', 8, 2)->nullable()->comment('VAT amount as percentage (0-100)');
            $table->text('notes')->nullable()->comment('Additional notes about the expense');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->comment('Current status of the expense');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this expense record');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->comment('User who last updated this expense');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->comment('User who approved this expense');
            $table->datetime('create_time')->nullable()->comment('Volopa legacy timestamp for creation');
            $table->datetime('update_time')->nullable()->comment('Volopa legacy timestamp for updates');
            $table->boolean('deleted')->default(false)->comment('Flag-based soft delete indicator');
            $table->datetime('delete_time')->nullable()->comment('Timestamp when expense was soft deleted');
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['date'], 'idx_date');
            $table->index(['status'], 'idx_status');
            $table->index(['currency'], 'idx_currency');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['expense_type'], 'idx_expense_type');
            
            // Composite indexes for common queries
            $table->index(['client_id', 'deleted', 'status'], 'idx_client_active_status');
            $table->index(['user_id', 'deleted', 'date'], 'idx_user_active_date');
            
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
        Schema::dropIfExists('pocket_expense');
    }
};