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
            $table->increments('id');
            $table->string('uuid', 36)->unique()->comment('Unique identifier for the expense source');
            $table->unsignedBigInteger('client_id')->nullable()->comment('Client owning this source (NULL for global sources like Other)');
            $table->string('name', 100)->comment('Name of the expense source (e.g., Cash, Corporate Card, Personal Card)');
            $table->boolean('is_default')->default(false)->comment('Whether this is a default source for the client');
            $table->boolean('deleted')->default(false)->comment('Flag-based soft delete indicator');
            $table->datetime('delete_time')->nullable()->comment('Timestamp when source was soft deleted');
            $table->datetime('create_time')->nullable()->comment('Volopa legacy timestamp for creation');
            $table->datetime('update_time')->nullable()->comment('Volopa legacy timestamp for updates');
            
            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Unique constraint for client-specific source names (excluding global sources)
            $table->unique(['client_id', 'name'], 'unique_client_source_name');
            
            // Indexes for performance
            $table->index(['client_id', 'deleted'], 'idx_client_active');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['is_default'], 'idx_default');
            $table->index(['uuid'], 'idx_uuid');
            
            // Table configuration
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
        
        // Seed global 'Other' source as per system constraints
        // Global 'Other' record (client_id = NULL) is not deletable or editable
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now()
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