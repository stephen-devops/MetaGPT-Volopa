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
        Schema::create('user_feature_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('User receiving the permission');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->unsignedBigInteger('feature_id')->comment('Feature being granted access to');
            $table->unsignedBigInteger('grantor_id')->comment('User who granted this permission');
            $table->unsignedBigInteger('manager_user_id')->comment('User who manages this permission');
            $table->boolean('is_enabled')->default(true)->comment('Whether permission is active');
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
            $table->foreign('grantor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('manager_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id', 'feature_id'], 'idx_user_client_feature');
            $table->index(['client_id', 'feature_id'], 'idx_client_feature');
            $table->index(['manager_user_id', 'client_id'], 'idx_manager_client');
            $table->index(['grantor_id'], 'idx_grantor');
            $table->index(['is_enabled'], 'idx_is_enabled');
            
            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'unique_user_client_feature');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_feature_permissions');
    }
};