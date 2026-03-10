<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the user_feature_permission table for managing user-specific feature access permissions
 * within the multi-tenant environment. This table enables delegation of OOP expense management
 * rights from Primary Administrators to other users within the same client.
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
        Schema::create('user_feature_permission', function (Blueprint $table) {
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key relationships
            $table->unsignedBigInteger('user_id')->comment('The user receiving the permission');
            $table->unsignedBigInteger('client_id')->comment('The client context for this permission');
            $table->unsignedBigInteger('feature_id')->comment('The feature being granted access to (e.g., 16 for OOP expenses)');
            $table->unsignedBigInteger('grantor_id')->comment('The user who granted this permission');
            $table->unsignedBigInteger('manager_user_id')->nullable()->comment('Optional designated manager for this permission');
            
            // Permission state
            $table->boolean('is_enabled')->default(true)->comment('Whether this permission is currently active');
            
            // Laravel timestamps
            $table->timestamps();
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'feature_id'], 'idx_client_feature');
            $table->index(['grantor_id'], 'idx_grantor');
            $table->index(['manager_user_id'], 'idx_manager');
            $table->index(['is_enabled'], 'idx_enabled');
            
            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'uk_user_client_feature');
            
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
                  
            $table->foreign('feature_id')
                  ->references('id')
                  ->on('features')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('grantor_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('manager_user_id')
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
        Schema::dropIfExists('user_feature_permission');
    }
};