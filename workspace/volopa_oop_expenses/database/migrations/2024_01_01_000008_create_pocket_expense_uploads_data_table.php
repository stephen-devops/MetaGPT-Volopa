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
        Schema::create('pocket_expense_uploads_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('upload_id');
            $table->integer('row_number');
            $table->string('date', 20)->nullable();
            $table->string('merchant_name', 500)->nullable();
            $table->string('amount', 50)->nullable();
            $table->string('currency', 10)->nullable();
            $table->text('description')->nullable();
            $table->string('expense_type', 200)->nullable();
            $table->string('category', 200)->nullable();
            $table->string('source', 200)->nullable();
            $table->string('project_code', 100)->nullable();
            $table->string('cost_center', 100)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('tax_amount', 50)->nullable();
            $table->string('tax_rate', 50)->nullable();
            $table->string('receipt_reference', 200)->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('raw_row_data')->nullable();
            $table->enum('validation_status', ['pending', 'valid', 'invalid'])->default('pending');
            $table->json('validation_errors')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->unsignedBigInteger('pocket_expense_id')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['upload_id', 'row_number']);
            $table->index(['upload_id', 'validation_status']);
            $table->index(['upload_id', 'is_processed']);
            $table->index(['pocket_expense_id']);
            $table->index('validation_status');
            $table->index('is_processed');
            $table->index(['upload_id', 'processed_at']);
            $table->index(['validation_status', 'is_processed']);
            $table->index(['upload_id', 'row_number', 'validation_status']);

            // Foreign key constraints
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            $table->foreign('pocket_expense_id')->references('id')->on('pocket_expenses')->onDelete('set null');

            // Unique constraint to prevent duplicate rows per upload
            $table->unique(['upload_id', 'row_number'], 'unique_upload_row');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_uploads_data');
    }
};