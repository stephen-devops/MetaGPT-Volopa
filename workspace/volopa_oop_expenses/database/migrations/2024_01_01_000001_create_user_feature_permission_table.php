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
        Schema::create('user_feature_permission', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign keys
            $table->unsignedBigInteger('user_id')->comment('User receiving the permission');
            $table->unsignedBigInteger('client_id')->comment('Client context for the permission');
            $table->unsignedBigInteger('feature_id')->comment('Feature being granted (16 = OOP Expense)');
            $table->unsignedBigInteger('grantor_id')->comment('User who granted this permission');
            $table->unsignedBigInteger('manager_user_id')->nullable()->comment('User who can manage the target user (optional)');
            
            // Permission state
            $table->tinyInteger('is_enabled')->unsigned()->default(1)->comment('Whether permission is active');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->nullable()->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->comment('Record update timestamp');
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
            $table->foreign('grantor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('manager_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'unique_user_client_feature');
            
            // Indexes for common queries
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'feature_id'], 'idx_client_feature');
            $table->index(['grantor_id'], 'idx_grantor');
            $table->index(['manager_user_id'], 'idx_manager');
            $table->index(['is_enabled'], 'idx_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_feature_permission');
    }
};