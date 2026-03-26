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
        Schema::create('pocket_expense_source_client_config', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name', 255);
            $table->boolean('is_default')->default(false);
            $table->boolean('deleted')->default(false);
            $table->dateTime('delete_time')->nullable();
            $table->dateTime('create_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('update_time')->nullable()->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            
            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Unique constraint as specified in system constraints
            $table->unique(['client_id', 'name'], 'oop_expense_source_client_name_unique');
            
            // Indexes for performance
            $table->index(['client_id', 'deleted']);
            $table->index(['deleted', 'is_default']);
            $table->index('uuid');
        });
        
        // Seed default expense sources as specified in constraints
        $this->seedDefaultExpenseSources();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
    
    /**
     * Seed default expense sources and global 'Other' record
     */
    private function seedDefaultExpenseSources(): void
    {
        $defaultSources = [
            ['uuid' => \Illuminate\Support\Str::uuid()->toString(), 'client_id' => null, 'name' => 'Other', 'is_default' => false],
        ];
        
        foreach ($defaultSources as $source) {
            \DB::table('pocket_expense_source_client_config')->insert([
                'uuid' => $source['uuid'],
                'client_id' => $source['client_id'],
                'name' => $source['name'],
                'is_default' => $source['is_default'],
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ]);
        }
    }
};