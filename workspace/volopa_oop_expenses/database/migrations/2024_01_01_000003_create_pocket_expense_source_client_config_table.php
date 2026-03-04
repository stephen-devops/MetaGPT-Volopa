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
            $table->string('uuid', 36)->nullable()->comment('External reference UUID');
            $table->unsignedBigInteger('client_id')->nullable()->comment('Client ID for multi-tenancy, NULL for global records');
            $table->string('name', 100)->comment('Expense source name');
            $table->boolean('is_default')->default(false)->comment('Whether this is the default source for client');
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->datetime('delete_time')->nullable()->comment('When record was deleted');
            $table->datetime('create_time')->useCurrent()->comment('Record creation time');
            $table->datetime('update_time')->useCurrent()->useCurrentOnUpdate()->comment('Record last update time');
            
            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['client_id', 'deleted'], 'idx_client_deleted');
            $table->index(['client_id', 'name', 'deleted'], 'idx_client_name_deleted');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['is_default', 'client_id'], 'idx_default_client');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            
            // Unique constraint for source names per client (excluding deleted records)
            $table->unique(['client_id', 'name'], 'unique_client_name');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
        });
        
        // Insert global 'Other' record that cannot be deleted or edited
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => null,
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now()
        ]);
        
        // Insert default sources for each existing client
        $clients = DB::table('clients')->select('id')->get();
        foreach ($clients as $client) {
            $defaultSources = [
                [
                    'uuid' => null,
                    'client_id' => $client->id,
                    'name' => 'Cash',
                    'is_default' => true,
                    'deleted' => false,
                    'delete_time' => null,
                    'create_time' => now(),
                    'update_time' => now()
                ],
                [
                    'uuid' => null,
                    'client_id' => $client->id,
                    'name' => 'Corporate Card',
                    'is_default' => false,
                    'deleted' => false,
                    'delete_time' => null,
                    'create_time' => now(),
                    'update_time' => now()
                ],
                [
                    'uuid' => null,
                    'client_id' => $client->id,
                    'name' => 'Personal Card',
                    'is_default' => false,
                    'deleted' => false,
                    'delete_time' => null,
                    'create_time' => now(),
                    'update_time' => now()
                ]
            ];
            
            DB::table('pocket_expense_source_client_config')->insert($defaultSources);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
};