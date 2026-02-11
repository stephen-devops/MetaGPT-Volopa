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
        Schema::create('pocket_expense_metadata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pocket_expense_id');
            $table->enum('metadata_type', ['category', 'source', 'location', 'tax', 'custom'])->default('custom');
            $table->json('details_json')->nullable();
            $table->string('value', 500)->nullable();
            $table->string('label', 255)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_editable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['pocket_expense_id', 'metadata_type']);
            $table->index(['metadata_type', 'is_required']);
            $table->index(['pocket_expense_id', 'sort_order']);
            $table->index('category_id');
            $table->index('source_id');
            $table->index('country_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index('is_editable');
            $table->index('deleted_at');
            $table->index(['pocket_expense_id', 'is_required']);

            // Foreign key constraints
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expenses')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('expense_categories')->onDelete('set null');
            $table->foreign('source_id')->references('id')->on('pocket_expense_source_client_configs')->onDelete('set null');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_metadata');
    }
};