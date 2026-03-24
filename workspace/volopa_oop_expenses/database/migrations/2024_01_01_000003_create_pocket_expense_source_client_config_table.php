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
        Schema::create('pocket_expense_source_client_config', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external references
            $table->string('uuid', 36)->nullable()->comment('External UUID reference');
            
            // Client relationship (nullable for global 'Other' record)
            $table->unsignedBigInteger('client_id')->nullable()->comment('Client owner of this expense source (NULL for global Other record)');
            
            // Source configuration
            $table->string('name', 180)->comment('Expense source name (e.g., Cash, Corporate Card, Personal Card, Other)');
            $table->tinyInteger('is_default')->unsigned()->default(0)->comment('Whether this is a default source for the client');
            
            // Soft delete using Volopa legacy pattern
            $table->tinyInteger('deleted')->unsigned()->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->comment('Soft delete timestamp');
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->nullable()->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->comment('Record update timestamp');
            
            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Unique constraint: source names must be unique per client (excluding soft-deleted)
            $table->unique(['client_id', 'name', 'deleted'], 'unique_client_source_name');
            
            // Indexes for common queries
            $table->index(['client_id'], 'idx_client');
            $table->index(['client_id', 'deleted'], 'idx_client_active');
            $table->index(['name'], 'idx_name');
            $table->index(['is_default'], 'idx_default');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['uuid'], 'idx_uuid');
        });
        
        // Seed with global 'Other' record (client_id = NULL) - not deletable
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => null,
            'client_id' => null,
            'name' => 'Other',
            'is_default' => 0,
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
};