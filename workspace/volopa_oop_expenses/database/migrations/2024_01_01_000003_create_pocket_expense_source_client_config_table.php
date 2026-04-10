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
            $table->increments('id')->comment('Primary key');
            $table->string('uuid', 36)->unique()->comment('UUID for external references');
            $table->unsignedBigInteger('client_id')->nullable()->comment('Reference to clients table, NULL for global Other record');
            $table->string('name', 100)->comment('Expense source name');
            $table->tinyInteger('is_default')->default(0)->comment('Whether this is a default source');
            $table->tinyInteger('deleted')->default(0)->comment('Soft delete flag');
            $table->dateTime('delete_time')->nullable()->default(null)->comment('Soft delete timestamp');
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Record creation timestamp');
            $table->dateTime('update_time')->nullable()->default(null)->comment('Record update timestamp');
            
            // Set table engine and charset according to constraints
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Foreign key constraint - nullable for global Other record
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate source names per client
            $table->unique(['client_id', 'name'], 'unique_client_source_name');
            
            // Indexes for performance
            $table->index(['client_id', 'deleted'], 'idx_client_deleted');
            $table->index(['name'], 'idx_source_name');
            $table->index(['is_default'], 'idx_is_default');
        });
        
        // Insert global 'Other' record that cannot be deleted or edited
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'client_id' => null, // Global record
            'name' => 'Other',
            'is_default' => 0,
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => null
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