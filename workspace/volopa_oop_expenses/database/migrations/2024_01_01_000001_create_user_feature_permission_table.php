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
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('feature_id');
            $table->unsignedBigInteger('grantor_id');
            $table->unsignedBigInteger('manager_user_id');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('grantor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('manager_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Unique constraint as specified in design constraints
            $table->unique(['user_id', 'client_id', 'feature_id'], 'unique_user_feature');
            
            // Indexes for performance
            $table->index(['client_id', 'feature_id']);
            $table->index(['manager_user_id', 'client_id']);
            $table->index('grantor_id');
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