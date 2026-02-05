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
            $table->unsignedBigInteger('grantor_id')->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'feature_id']);
            $table->index('feature_id');
            $table->index('grantor_id');
            $table->index('manager_user_id');

            // Unique constraint to prevent duplicate permissions
            $table->unique(['user_id', 'client_id', 'feature_id'], 'user_client_feature_unique');

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('feature_id')->references('id')->on('features')->onDelete('cascade');
            $table->foreign('grantor_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('manager_user_id')->references('id')->on('users')->onDelete('set null');
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