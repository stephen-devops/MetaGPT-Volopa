<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            // Indexes for performance
            $table->index(['client_id', 'deleted']);
            $table->index(['client_id', 'name']);
            $table->index(['client_id', 'is_default']);
            $table->index('deleted');
            $table->index('uuid');

            // Unique constraint to prevent duplicate source names per client
            $table->unique(['client_id', 'name'], 'unique_client_source_name');
        });

        // Insert global "Other" source that cannot be deleted
        DB::table('pocket_expense_source_client_config')->insert([
            'uuid' => Str::uuid()->toString(),
            'client_id' => null,
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
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_config');
    }
};