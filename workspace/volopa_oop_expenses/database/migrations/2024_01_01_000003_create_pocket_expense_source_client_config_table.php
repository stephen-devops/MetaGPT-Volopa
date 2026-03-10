<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Creates the pocket_expense_source_client_config table for managing client-specific
 * expense source configurations. This table stores the configured expense sources
 * available to each client, including default sources and custom sources.
 * Also includes a global 'Other' source that is available to all clients.
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
        Schema::create('pocket_expense_source_client_config', function (Blueprint $table) {
            // Primary key
            $table->increments('id')->comment('Primary key for expense source configuration');
            
            // UUID for external references
            $table->string('uuid', 36)->unique()->comment('Unique identifier for external references');
            
            // Foreign key relationships
            $table->unsignedInteger('client_id')->nullable()->comment('The client this source belongs to. NULL for global sources like Other');
            
            // Core fields
            $table->string('name', 100)->comment('The name of the expense source');
            $table->boolean('is_default')->default(false)->comment('Whether this is a default source for the client');
            
            // Soft delete pattern using deleted flag and delete_time
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Timestamp when the record was soft deleted');
            
            // Custom timestamp pattern using create_time and update_time
            $table->dateTime('create_time')->comment('Timestamp when the record was created');
            $table->dateTime('update_time')->comment('Timestamp when the record was last updated');
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['client_id'], 'idx_client_id');
            $table->index(['name'], 'idx_name');
            $table->index(['is_default'], 'idx_is_default');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            $table->index(['uuid'], 'idx_uuid');
            
            // Unique constraint to prevent duplicate source names per client
            $table->unique(['client_id', 'name', 'deleted'], 'uk_client_name_deleted');
            
            // Foreign key constraint for client_id (nullable for global sources)
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });
        
        // Insert the global 'Other' expense source that is available to all clients
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'client_id' => null, // Global source
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
};