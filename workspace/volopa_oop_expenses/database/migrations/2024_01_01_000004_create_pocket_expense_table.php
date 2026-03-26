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
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->date('date');
            $table->string('merchant_name', 180);
            $table->text('merchant_description')->nullable();
            $table->unsignedBigInteger('expense_type');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->text('merchant_address')->nullable();
            $table->decimal('vat_amount', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            $table->boolean('deleted')->default(false);
            $table->dateTime('delete_time')->nullable();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['date', 'client_id']);
            $table->index(['deleted', 'client_id']);
            $table->index('uuid');
            $table->index('expense_type');
            $table->index('currency');
            $table->index('created_by_user_id');
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