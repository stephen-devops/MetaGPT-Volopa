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
        Schema::create('pocket_expense_source_client_config', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external reference
            $table->uuid('uuid')->unique();
            
            // Client association - nullable to support global/default sources
            $table->unsignedBigInteger('client_id')->nullable();
            
            // Source configuration
            $table->string('name', 100)->comment('Expense source name (e.g., Company Credit Card, Personal Cash)');
            $table->boolean('is_default')->default(false)->comment('Whether this is the default source for the client');
            
            // Volopa soft delete pattern using flags
            $table->boolean('deleted')->default(false);
            $table->datetime('delete_time')->nullable();
            
            // Volopa timestamp pattern
            $table->datetime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->datetime('update_time')->nullable()->default(null);
            
            // Foreign key constraints
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade');
            
            // Unique constraint on client_id + name to prevent duplicate source names per client
            // Uses partial index to handle nullable client_id properly
            $table->unique(['client_id', 'name', 'deleted'], 'client_source_name_unique');
            
            // Indexes for common queries
            $table->index(['client_id', 'deleted'], 'client_active_sources_index');
            $table->index(['is_default', 'deleted'], 'default_active_sources_index');
            $table->index(['uuid'], 'uuid_index');
            $table->index(['deleted', 'delete_time'], 'soft_delete_index');
            
            // Add update trigger for update_time
            DB::unprepared('
                CREATE TRIGGER pocket_expense_source_client_config_update_time_trigger
                BEFORE UPDATE ON pocket_expense_source_client_config
                FOR EACH ROW
                BEGIN
                    SET NEW.update_time = CURRENT_TIMESTAMP;
                END
            ');
        });
        
        // Insert default global expense sources
        DB::table('pocket_expense_source_client_config')->insert([
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Company Credit Card',
                'is_default' => true,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => null
            ],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Personal Cash',
                'is_default' => false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => null
            ],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Debit Card',
                'is_default' => false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => null
            ],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Bank Transfer',
                'is_default' => false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => null
            ],
            [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => null
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Drop trigger first
        DB::unprepared('DROP TRIGGER IF EXISTS pocket_expense_source_client_config_update_time_trigger');
        
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
};