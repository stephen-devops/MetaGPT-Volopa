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
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();

            // Indexes for performance
            $table->index(['client_id', 'deleted']);
            $table->index(['client_id', 'is_default', 'deleted']);
            $table->index('uuid');
            $table->index('name');
            $table->index('deleted');

            // Unique constraint for client-specific source names (excluding deleted)
            $table->unique(['client_id', 'name', 'deleted'], 'client_source_name_unique');

            // Foreign key constraint for client_id (nullable for global sources)
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });

        // Seed default expense sources
        $this->seedDefaultSources();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }

    /**
     * Seed the default expense sources
     */
    private function seedDefaultSources(): void
    {
        $defaultSources = [
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Cash',
                'is_default' => true,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Corporate Card',
                'is_default' => true,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Personal Card',
                'is_default' => true,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ],
        ];

        foreach ($defaultSources as $source) {
            DB::table('pocket_expense_source_client_config')->insert($source);
        }
    }
};