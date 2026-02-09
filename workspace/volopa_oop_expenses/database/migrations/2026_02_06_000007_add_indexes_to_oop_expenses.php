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
        Schema::table('oop_expenses', function (Blueprint $table) {
            // Performance indexes for expense queries
            $table->index(['merchant_name'], 'oop_expenses_merchant_name_index');
            $table->index(['transaction_type'], 'oop_expenses_transaction_type_index');
            $table->index(['currency'], 'oop_expenses_currency_index');
            $table->index(['amount'], 'oop_expenses_amount_index');
            $table->index(['country'], 'oop_expenses_country_index');
            $table->index(['source'], 'oop_expenses_source_index');
            $table->index(['category'], 'oop_expenses_category_index');
            $table->index(['tracking_code_i'], 'oop_expenses_tracking_code_i_index');
            $table->index(['tracking_code_ii'], 'oop_expenses_tracking_code_ii_index');
            $table->index(['project_id'], 'oop_expenses_project_id_index');
            $table->index(['approved_by'], 'oop_expenses_approved_by_index');
            $table->index(['approved_at'], 'oop_expenses_approved_at_index');

            // Composite indexes for common query patterns
            $table->index(['user_id', 'date'], 'oop_expenses_user_date_index');
            $table->index(['client_id', 'date'], 'oop_expenses_client_date_index');
            $table->index(['user_id', 'currency'], 'oop_expenses_user_currency_index');
            $table->index(['client_id', 'currency'], 'oop_expenses_client_currency_index');
            $table->index(['user_id', 'transaction_type'], 'oop_expenses_user_transaction_type_index');
            $table->index(['client_id', 'transaction_type'], 'oop_expenses_client_transaction_type_index');
            $table->index(['user_id', 'merchant_name'], 'oop_expenses_user_merchant_index');
            $table->index(['client_id', 'merchant_name'], 'oop_expenses_client_merchant_index');
            $table->index(['user_id', 'amount'], 'oop_expenses_user_amount_index');
            $table->index(['client_id', 'amount'], 'oop_expenses_client_amount_index');
            $table->index(['user_id', 'approved_by'], 'oop_expenses_user_approved_by_index');
            $table->index(['client_id', 'approved_by'], 'oop_expenses_client_approved_by_index');
            $table->index(['user_id', 'approved_at'], 'oop_expenses_user_approved_at_index');
            $table->index(['client_id', 'approved_at'], 'oop_expenses_client_approved_at_index');

            // Composite indexes for filtering and sorting
            $table->index(['status', 'date'], 'oop_expenses_status_date_index');
            $table->index(['status', 'created_at'], 'oop_expenses_status_created_at_index');
            $table->index(['status', 'amount'], 'oop_expenses_status_amount_index');
            $table->index(['status', 'currency'], 'oop_expenses_status_currency_index');
            $table->index(['status', 'approved_at'], 'oop_expenses_status_approved_at_index');
            $table->index(['date', 'amount'], 'oop_expenses_date_amount_index');
            $table->index(['date', 'currency'], 'oop_expenses_date_currency_index');
            $table->index(['currency', 'amount'], 'oop_expenses_currency_amount_index');
            $table->index(['transaction_type', 'amount'], 'oop_expenses_transaction_type_amount_index');
            $table->index(['transaction_type', 'currency'], 'oop_expenses_transaction_type_currency_index');

            // Complex composite indexes for advanced filtering
            $table->index(['user_id', 'status', 'date'], 'oop_expenses_user_status_date_index');
            $table->index(['client_id', 'status', 'date'], 'oop_expenses_client_status_date_index');
            $table->index(['user_id', 'status', 'created_at'], 'oop_expenses_user_status_created_at_index');
            $table->index(['client_id', 'status', 'created_at'], 'oop_expenses_client_status_created_at_index');
            $table->index(['user_id', 'date', 'amount'], 'oop_expenses_user_date_amount_index');
            $table->index(['client_id', 'date', 'amount'], 'oop_expenses_client_date_amount_index');
            $table->index(['user_id', 'currency', 'amount'], 'oop_expenses_user_currency_amount_index');
            $table->index(['client_id', 'currency', 'amount'], 'oop_expenses_client_currency_amount_index');
            $table->index(['user_id', 'transaction_type', 'status'], 'oop_expenses_user_transaction_type_status_index');
            $table->index(['client_id', 'transaction_type', 'status'], 'oop_expenses_client_transaction_type_status_index');
            $table->index(['user_id', 'merchant_name', 'date'], 'oop_expenses_user_merchant_date_index');
            $table->index(['client_id', 'merchant_name', 'date'], 'oop_expenses_client_merchant_date_index');

            // Indexes for reporting and analytics
            $table->index(['date', 'status', 'amount'], 'oop_expenses_date_status_amount_index');
            $table->index(['currency', 'status', 'amount'], 'oop_expenses_currency_status_amount_index');
            $table->index(['transaction_type', 'status', 'amount'], 'oop_expenses_transaction_type_status_amount_index');
            $table->index(['approved_by', 'approved_at', 'amount'], 'oop_expenses_approved_by_at_amount_index');
            $table->index(['project_id', 'status', 'amount'], 'oop_expenses_project_status_amount_index');
            $table->index(['project_id', 'date', 'amount'], 'oop_expenses_project_date_amount_index');
            $table->index(['country', 'currency', 'amount'], 'oop_expenses_country_currency_amount_index');
            $table->index(['source', 'date', 'amount'], 'oop_expenses_source_date_amount_index');
            $table->index(['category', 'date', 'amount'], 'oop_expenses_category_date_amount_index');

            // Indexes for search functionality
            $table->index(['merchant_name', 'date'], 'oop_expenses_merchant_name_date_index');
            $table->index(['merchant_name', 'status'], 'oop_expenses_merchant_name_status_index');
            $table->index(['merchant_name', 'amount'], 'oop_expenses_merchant_name_amount_index');
            $table->index(['tracking_code_i', 'status'], 'oop_expenses_tracking_code_i_status_index');
            $table->index(['tracking_code_ii', 'status'], 'oop_expenses_tracking_code_ii_status_index');
            $table->index(['tracking_code_i', 'date'], 'oop_expenses_tracking_code_i_date_index');
            $table->index(['tracking_code_ii', 'date'], 'oop_expenses_tracking_code_ii_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oop_expenses', function (Blueprint $table) {
            // Drop performance indexes
            $table->dropIndex('oop_expenses_merchant_name_index');
            $table->dropIndex('oop_expenses_transaction_type_index');
            $table->dropIndex('oop_expenses_currency_index');
            $table->dropIndex('oop_expenses_amount_index');
            $table->dropIndex('oop_expenses_country_index');
            $table->dropIndex('oop_expenses_source_index');
            $table->dropIndex('oop_expenses_category_index');
            $table->dropIndex('oop_expenses_tracking_code_i_index');
            $table->dropIndex('oop_expenses_tracking_code_ii_index');
            $table->dropIndex('oop_expenses_project_id_index');
            $table->dropIndex('oop_expenses_approved_by_index');
            $table->dropIndex('oop_expenses_approved_at_index');

            // Drop composite indexes for common query patterns
            $table->dropIndex('oop_expenses_user_date_index');
            $table->dropIndex('oop_expenses_client_date_index');
            $table->dropIndex('oop_expenses_user_currency_index');
            $table->dropIndex('oop_expenses_client_currency_index');
            $table->dropIndex('oop_expenses_user_transaction_type_index');
            $table->dropIndex('oop_expenses_client_transaction_type_index');
            $table->dropIndex('oop_expenses_user_merchant_index');
            $table->dropIndex('oop_expenses_client_merchant_index');
            $table->dropIndex('oop_expenses_user_amount_index');
            $table->dropIndex('oop_expenses_client_amount_index');
            $table->dropIndex('oop_expenses_user_approved_by_index');
            $table->dropIndex('oop_expenses_client_approved_by_index');
            $table->dropIndex('oop_expenses_user_approved_at_index');
            $table->dropIndex('oop_expenses_client_approved_at_index');

            // Drop composite indexes for filtering and sorting
            $table->dropIndex('oop_expenses_status_date_index');
            $table->dropIndex('oop_expenses_status_created_at_index');
            $table->dropIndex('oop_expenses_status_amount_index');
            $table->dropIndex('oop_expenses_status_currency_index');
            $table->dropIndex('oop_expenses_status_approved_at_index');
            $table->dropIndex('oop_expenses_date_amount_index');
            $table->dropIndex('oop_expenses_date_currency_index');
            $table->dropIndex('oop_expenses_currency_amount_index');
            $table->dropIndex('oop_expenses_transaction_type_amount_index');
            $table->dropIndex('oop_expenses_transaction_type_currency_index');

            // Drop complex composite indexes
            $table->dropIndex('oop_expenses_user_status_date_index');
            $table->dropIndex('oop_expenses_client_status_date_index');
            $table->dropIndex('oop_expenses_user_status_created_at_index');
            $table->dropIndex('oop_expenses_client_status_created_at_index');
            $table->dropIndex('oop_expenses_user_date_amount_index');
            $table->dropIndex('oop_expenses_client_date_amount_index');
            $table->dropIndex('oop_expenses_user_currency_amount_index');
            $table->dropIndex('oop_expenses_client_currency_amount_index');
            $table->dropIndex('oop_expenses_user_transaction_type_status_index');
            $table->dropIndex('oop_expenses_client_transaction_type_status_index');
            $table->dropIndex('oop_expenses_user_merchant_date_index');
            $table->dropIndex('oop_expenses_client_merchant_date_index');

            // Drop reporting and analytics indexes
            $table->dropIndex('oop_expenses_date_status_amount_index');
            $table->dropIndex('oop_expenses_currency_status_amount_index');
            $table->dropIndex('oop_expenses_transaction_type_status_amount_index');
            $table->dropIndex('oop_expenses_approved_by_at_amount_index');
            $table->dropIndex('oop_expenses_project_status_amount_index');
            $table->dropIndex('oop_expenses_project_date_amount_index');
            $table->dropIndex('oop_expenses_country_currency_amount_index');
            $table->dropIndex('oop_expenses_source_date_amount_index');
            $table->dropIndex('oop_expenses_category_date_amount_index');

            // Drop search functionality indexes
            $table->dropIndex('oop_expenses_merchant_name_date_index');
            $table->dropIndex('oop_expenses_merchant_name_status_index');
            $table->dropIndex('oop_expenses_merchant_name_amount_index');
            $table->dropIndex('oop_expenses_tracking_code_i_status_index');
            $table->dropIndex('oop_expenses_tracking_code_ii_status_index');
            $table->dropIndex('oop_expenses_tracking_code_i_date_index');
            $table->dropIndex('oop_expenses_tracking_code_ii_date_index');
        });
    }
};