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
        Schema::create('pocket_expense', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external reference
            $table->uuid('uuid')->unique();
            
            // Foreign key references - required fields
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            
            // Core expense fields
            $table->date('date')->comment('Date when the expense occurred');
            $table->string('merchant_name', 180)->comment('Name of the merchant/vendor');
            $table->string('merchant_description', 255)->nullable()->comment('Additional merchant details');
            
            // Expense type reference - nullable to allow expenses without categorization
            $table->unsignedBigInteger('expense_type')->nullable();
            
            // Financial fields
            $table->string('currency', 3)->comment('3-letter ISO currency code');
            $table->decimal('amount', 15, 2)->comment('Expense amount in specified currency');
            $table->string('merchant_address', 500)->nullable()->comment('Merchant location/address');
            $table->decimal('vat_amount', 15, 2)->nullable()->comment('VAT/Tax amount if applicable');
            
            // Additional information
            $table->text('notes')->nullable()->comment('Additional notes or comments');
            
            // Status tracking
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])
                  ->default('draft')
                  ->comment('Current approval status of the expense');
            
            // Audit fields - user tracking
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            
            // Volopa timestamp pattern
            $table->datetime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->datetime('update_time')->nullable()->default(null);
            
            // Volopa soft delete pattern using flags
            $table->boolean('deleted')->default(false);
            $table->datetime('delete_time')->nullable();
            
            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade');
                  
            $table->foreign('expense_type')
                  ->references('id')
                  ->on('opt_pocket_expense_type')
                  ->onDelete('set null');
                  
            $table->foreign('created_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('updated_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
                  
            $table->foreign('approved_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
            
            // Indexes for common queries
            $table->index(['user_id', 'client_id', 'deleted'], 'user_client_active_expenses');
            $table->index(['client_id', 'status', 'deleted'], 'client_status_expenses');
            $table->index(['date', 'deleted'], 'date_expenses');
            $table->index(['status', 'deleted'], 'status_expenses');
            $table->index(['currency', 'deleted'], 'currency_expenses');
            $table->index(['expense_type', 'deleted'], 'type_expenses');
            $table->index(['uuid'], 'uuid_index');
            $table->index(['deleted', 'delete_time'], 'soft_delete_index');
            $table->index(['created_by_user_id'], 'created_by_index');
            $table->index(['approved_by_user_id'], 'approved_by_index');
            
            // Add update trigger for update_time
            DB::unprepared('
                CREATE TRIGGER pocket_expense_update_time_trigger
                BEFORE UPDATE ON pocket_expense
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
        DB::unprepared('DROP TRIGGER IF EXISTS pocket_expense_update_time_trigger');
        
        Schema::dropIfExists('pocket_expense');
    }
};