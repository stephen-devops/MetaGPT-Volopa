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
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_other')->default(false);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();

            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            // Indexes for performance
            $table->index(['client_id', 'deleted']);
            $table->index(['client_id', 'is_default']);
            $table->index(['client_id', 'deleted', 'sort_order']);
            $table->index('uuid');
            $table->index('deleted');
            $table->index('is_other');
            $table->index('is_system');
            $table->index(['deleted', 'is_system']);
            $table->index(['client_id', 'name', 'deleted']);
            $table->index('create_time');
            $table->index('update_time');

            // Unique constraints
            $table->unique(['client_id', 'name', 'deleted'], 'client_name_deleted_unique');
        });

        // Insert default system records
        DB::table('pocket_expense_source_client_config')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'is_other' => true,
                'is_system' => true,
                'sort_order' => 999,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ]
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