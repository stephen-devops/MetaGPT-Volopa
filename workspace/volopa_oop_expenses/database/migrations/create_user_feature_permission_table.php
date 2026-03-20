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
        Schema::create('user_feature_permission', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key references
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('feature_id');
            $table->unsignedBigInteger('grantor_id');
            $table->unsignedBigInteger('manager_user_id')->nullable();
            
            // Permission state
            $table->boolean('is_enabled')->default(true);
            
            // Timestamps using Laravel convention
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade');
                  
            $table->foreign('grantor_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('manager_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
            
            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'user_feature_permission_unique');
            
            // Indexes for common queries
            $table->index(['client_id', 'feature_id'], 'client_feature_index');
            $table->index(['grantor_id'], 'grantor_index');
            $table->index(['manager_user_id'], 'manager_index');
            $table->index(['is_enabled'], 'enabled_index');
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