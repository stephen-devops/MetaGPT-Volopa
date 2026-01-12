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
        Schema::create('validation_errors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_file_id');
            $table->integer('row_number');
            $table->string('field_name', 100);
            $table->text('error_message');
            $table->string('error_code', 50);
            $table->timestamps();

            // Indexes for performance
            $table->index('payment_file_id');
            $table->index(['payment_file_id', 'row_number']);
            $table->index('error_code');
            $table->index(['payment_file_id', 'error_code']);
            $table->index('field_name');
            $table->index(['payment_file_id', 'field_name']);
            
            // Foreign key constraint
            $table->foreign('payment_file_id')->references('id')->on('payment_files')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_errors');
    }
};