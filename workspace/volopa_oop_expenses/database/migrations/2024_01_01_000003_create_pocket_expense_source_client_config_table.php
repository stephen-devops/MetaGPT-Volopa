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
            $table->id();
            $table->string('uuid', 36);
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            
            // Volopa flag-based soft delete pattern
            $table->tinyInteger('deleted')->default(0);
            $table->dateTime('delete_time')->nullable();
            
            // Volopa legacy timestamp pattern
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            
            // Foreign key constraint - nullable to support global 'Other' record
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate source names per client
            $table->unique(['client_id', 'name'], 'oop_expense_source_client_name_unique');
            
            // Indexes for performance
            $table->index(['client_id', 'deleted']);
            $table->index(['uuid']);
            $table->index(['is_default']);
            
            // Table settings
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
        
        // Insert global 'Other' record (client_id = NULL) as per system constraints
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => DB::raw('UUID()'),
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => 0,
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