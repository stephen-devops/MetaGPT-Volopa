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
        Schema::create('pocket_expense', function (Blueprint $table) {
            $table->increments('id')->comment('Primary key');
            $table->string('uuid', 36)->unique()->comment('UUID for external references');
            $table->unsignedBigInteger('user_id')->comment('Reference to users table - expense owner');
            $table->unsignedBigInteger('client_id')->comment('Reference to clients table - multi-tenancy');
            $table->date('date')->comment('Expense date');
            $table->string('merchant_name', 180)->comment('Merchant name - max VARCHAR(180)');
            $table->string('merchant_description', 255)->nullable()->comment('Optional merchant description');
            $table->unsignedInteger('expense_type')->comment('Reference to opt_pocket_expense_type table');
            $table->char('currency', 3)->comment('3-letter ISO currency code');
            $table->decimal('amount', 14, 2)->comment('Expense amount with sign based on type');
            $table->string('merchant_address', 500)->nullable()->comment('Optional merchant address');
            $table->decimal('vat_amount', 12, 2)->nullable()->comment('VAT amount 0-100');
            $table->text('notes')->nullable()->comment('Optional expense notes');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->comment('Expense workflow status');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this expense');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->comment('User who last updated this expense');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->comment('User who approved this expense');
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->default(null)->comment('Record update timestamp');
            $table->tinyInteger('deleted')->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->default(null)->comment('Soft delete timestamp');
            
            // Set table engine and charset according to constraints
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
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
            $table->index(['date'], 'idx_expense_date');
            $table->index(['status'], 'idx_status');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['currency'], 'idx_currency');
        });
        
        // Add trigger for update_time on update
        DB::unprepared('
            CREATE TRIGGER pocket_expense_update_time_trigger
            BEFORE UPDATE ON pocket_expense
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
        DB::unprepared('DROP TRIGGER IF EXISTS pocket_expense_update_time_trigger');
        
        Schema::dropIfExists('pocket_expense');
    }
};