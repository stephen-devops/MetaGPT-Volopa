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
            $table->id()->comment('Primary key');
            $table->unsignedBigInteger('user_id')->comment('Reference to users table');
            $table->unsignedBigInteger('client_id')->comment('Reference to clients table');
            $table->unsignedBigInteger('feature_id')->comment('Reference to features table');
            $table->unsignedBigInteger('grantor_id')->comment('User who granted this permission');
            $table->unsignedBigInteger('manager_user_id')->comment('User being managed');
            $table->boolean('is_enabled')->default(true)->comment('Whether permission is active');
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
            $table->foreign('grantor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('manager_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'unique_user_client_feature');
            
            // Indexes for performance
            $table->index(['client_id', 'feature_id'], 'idx_client_feature');
            $table->index(['grantor_id'], 'idx_grantor');
            $table->index(['manager_user_id'], 'idx_manager_user');
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