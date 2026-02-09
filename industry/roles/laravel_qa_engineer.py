#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-15
@File    : laravel_qa_engineer.py
@Desc    : Laravel QA Engineer role for testing Volopa OOP Expense system
"""

import json
from pathlib import Path
from typing import Dict, Any
from metagpt.roles.qa_engineer import QaEngineer
from metagpt.actions import WriteTest


class LaravelQaEngineer(QaEngineer):
    """
    Laravel QA Engineer specialized for writing PHPUnit/Pest tests for Laravel APIs.

    Responsibilities:
    - Write feature tests for all API endpoints
    - Test validation rules in FormRequests
    - Test authorization via user_feature_permission and Policies
    - Test database transactions and rollbacks (all-or-nothing CSV)
    - Test N+1 query prevention (eager loading)
    - Test pagination on list endpoints
    - Test API Resource transformations
    - Test multi-tenant isolation (client_id filtering)
    - Test expense status workflow transitions (draft -> submitted -> approved -> rejected)
    - Test queue job processing (ProcessExpenseUpload)
    - Test error handling and status codes

    Domain Modules:
    - User Management: permission grant/revoke, role hierarchy, management rights
    - Pocket Expense CSV Upload: file upload, CSV validation, background sync
    - Single Expense Capturing: CRUD, FX conversion, metadata, source config

    Test Coverage Requirements:
    - Unit tests: 0% (focus on feature/integration tests for APIs)
    - Feature tests: 100% coverage of all endpoints
    - Policy tests: 100% coverage of authorization rules via user_feature_permission
    - Validation tests: 100% coverage of FormRequest and CSV validation rules
    """

    use_fixed_sop: bool = True
    name: str = "Darius"
    profile: str = "Laravel QA Engineer"
    goal: str = (
        "Write comprehensive PHPUnit tests ensuring Laravel OOP Expense code follows specifications as input. "
        "Use same language as user requirement"
    )

    def __init__(self, **kwargs):
        super().__init__(**kwargs)

        # Load requirements from industry/requirements/updated_req/
        self.requirements = self._load_requirements()

        # Build comprehensive test constraints
        self._build_test_constraints()

    def _load_requirements(self) -> dict:
        """Load all three OOP Expense requirement JSON files"""
        req_dir = Path(__file__).parent.parent / "requirements" / "updated_req"

        files = {
            "user_management": req_dir / "SD-OOP_User_Management_System.json",
            "pocket_expense": req_dir / "SD-OOP_Pocket_expense.json",
            "single_data_capturing": req_dir / "SD-OOP_Expense_single_data_capturing.json",
        }

        loaded = {}
        for key, path in files.items():
            with open(path, 'r', encoding='utf-8') as f:
                loaded[key] = json.load(f)

        return loaded

    def _build_test_constraints(self):
        """
        Build comprehensive test constraints from:
        1. User Management requirements (role hierarchy, permissions, access control)
        2. Pocket Expense requirements (CSV upload, validation, error handling)
        3. Single Data Capturing requirements (CRUD, FX, metadata, sources)
        """

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        # Build test requirement summaries
        permission_tests = self._format_permission_tests(um)
        csv_upload_tests = self._format_csv_upload_tests(pe)
        single_expense_tests = self._format_single_expense_tests(sdc)

        self.constraints = f"""
You are a Laravel QA Engineer writing PHPUnit/Pest feature tests for the Volopa OOP Expense API.

========================================
CRITICAL TEST OUTPUT FORMAT
========================================

Generate PHP test files in this format:

File: tests/Feature/{{Resource}}Test.php

```php
<?php

namespace Tests\\Feature;

use Tests\\TestCase;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use App\\Models\\{{Model}};
use App\\Models\\User;

class {{Resource}}Test extends TestCase
{{
    use RefreshDatabase;

    /** @test */
    public function test_method_name()
    {{
        // Arrange
        $user = User::factory()->create(['client_id' => 1]);

        // Act
        $response = $this->actingAs($user)->getJson('/api/endpoint');

        // Assert
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['id', 'name']]);
    }}
}}
```

========================================
TESTING MENTAL MODEL
========================================

Test the complete flow:
Client -> route (Oauth2UserClient middleware) -> controller -> FormRequest
(validation + policy) -> service/model (domain logic, transactions) ->
API Resource (shape output) -> JSON with correct status codes and error format

For EVERY endpoint, test:
1. Route exists and is accessible
2. Authentication required (401 if not authenticated via Oauth2UserClient)
3. Authorization enforced (403 if user_feature_permission check fails)
4. Validation rules work (422 with proper errors)
5. Business logic executes correctly
6. Response structure matches API Resource
7. Database state changes are correct
8. Status codes are appropriate

========================================
MODULE 1: USER MANAGEMENT TESTS
========================================

{permission_tests}

========================================
MODULE 2: POCKET EXPENSE CSV UPLOAD TESTS
========================================

{csv_upload_tests}

========================================
MODULE 3: SINGLE EXPENSE DATA CAPTURING TESTS
========================================

{single_expense_tests}

========================================
ARCHITECTURAL PATTERNS TO TEST
========================================

## 1. Transaction Integrity - All-or-Nothing CSV

```php
/** @test */
public function test_csv_upload_rolls_back_on_any_validation_failure()
{{
    $user = User::factory()->create();

    // CSV with 5 valid rows and 1 invalid row
    $csv = $this->createCsvWithInvalidRow();

    $response = $this->actingAs($user)->postJson('/api/uploads/pocket-expense/csv', [
        'file' => $csv,
        'user_id' => $user->id,
        'expense_user_id' => $user->id,
        'client_id' => $user->client_id,
    ]);

    $response->assertStatus(422);

    // Assert ROLLBACK: zero expenses created (all-or-nothing)
    $this->assertDatabaseCount('pocket_expense', 0);
    $response->assertJsonStructure([
        'success', 'message', 'upload_id', 'total_rows', 'error_count',
        'errors' => [['line_number', 'field', 'error', 'value']]
    ]);
}}
```

## 2. N+1 Query Prevention

```php
/** @test */
public function test_expense_list_eager_loads_relationships()
{{
    $user = User::factory()->create();
    PocketExpense::factory()->count(5)->create(['client_id' => $user->client_id]);

    \\DB::enableQueryLog();
    $response = $this->actingAs($user)->getJson('/api/pocket-expenses');
    $queries = \\DB::getQueryLog();

    $this->assertLessThanOrEqual(4, count($queries), 'N+1 query detected!');
    $response->assertOk();
}}
```

## 3. Pagination Required

```php
/** @test */
public function test_expense_list_returns_paginated_results()
{{
    $user = User::factory()->create();
    PocketExpense::factory()->count(50)->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson('/api/pocket-expenses');

    $response->assertOk();
    $response->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'total', 'per_page']
    ]);
}}
```

## 4. Multi-Tenant Isolation

```php
/** @test */
public function test_user_cannot_access_other_client_expenses()
{{
    $user = User::factory()->create(['client_id' => 1]);
    $otherExpense = PocketExpense::factory()->create(['client_id' => 999]);

    $response = $this->actingAs($user)->getJson("/api/pocket-expenses/{{$otherExpense->id}}");

    $this->assertTrue(in_array($response->status(), [403, 404]));
}}

/** @test */
public function test_list_only_returns_own_client_expenses()
{{
    $user = User::factory()->create(['client_id' => 1]);
    PocketExpense::factory()->count(3)->create(['client_id' => 1]);
    PocketExpense::factory()->count(10)->create(['client_id' => 999]);

    $response = $this->actingAs($user)->getJson('/api/pocket-expenses');

    $response->assertOk();
    $this->assertCount(3, $response->json('data'));
}}
```

## 5. Async Processing - Background Sync

```php
/** @test */
public function test_csv_upload_dispatches_background_job_on_success()
{{
    Queue::fake();
    $user = User::factory()->create();
    $csv = $this->createValidCsv();

    $response = $this->actingAs($user)->postJson('/api/uploads/pocket-expense/csv', [
        'file' => $csv,
        'user_id' => $user->id,
        'expense_user_id' => $user->id,
        'client_id' => $user->client_id,
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessExpenseUpload::class);
}}
```

## 6. API Resources (Not Raw Models)

```php
/** @test */
public function test_expense_response_uses_api_resource_structure()
{{
    $user = User::factory()->create();
    $expense = PocketExpense::factory()->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson("/api/pocket-expenses/{{$expense->id}}");

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'id', 'date', 'merchant_name', 'currency', 'amount',
            'status', 'expense_type', 'created_at', 'updated_at'
        ]
    ]);
    $response->assertJsonMissing(['password', 'remember_token']);
}}
```

========================================
STATUS CODES TESTING
========================================

```php
/** @test */
public function test_endpoints_return_correct_status_codes()
{{
    $user = User::factory()->create();

    // 200 OK - Successful GET
    $this->actingAs($user)->getJson('/api/pocket-expenses')->assertOk();

    // 201 Created - Successful POST
    $response = $this->actingAs($user)->postJson('/api/pocket-expenses', [/* valid data */]);
    $response->assertCreated();

    // 401 Unauthorized - No authentication
    $this->getJson('/api/pocket-expenses')->assertUnauthorized();

    // 422 Unprocessable Entity - Validation failed
    $this->actingAs($user)->postJson('/api/pocket-expenses', [/* invalid data */])->assertUnprocessable();

    // 422 CSV validation failure
    $this->actingAs($user)->postJson('/api/uploads/pocket-expense/csv', [
        'file' => $this->createCsvWithInvalidRow()
    ])->assertStatus(422);
}}
```

========================================
TEST ORGANIZATION
========================================

Organize tests by module and concern:

tests/Feature/
├── UserPermissionTest.php              (grant/revoke, role hierarchy, management rights)
├── PocketExpenseUploadTest.php         (CSV upload, validation, error response, background sync)
├── PocketExpenseTest.php               (CRUD, status workflow, approval)
├── PocketExpenseMetadataTest.php       (metadata types, source handling)
├── ExpenseSourceConfigTest.php         (defaults, Other handling, max 20 limit)
├── FXConversionTest.php                (dated rates, commission, lookback)
├── MultiTenantIsolationTest.php        (client_id filtering across all endpoints)
├── TransactionIntegrityTest.php        (all-or-nothing CSV, rollback scenarios)
└── CSVValidationRulesTest.php          (per-field validation: date, type, currency, amount, etc.)

Each test file should test ONE resource or ONE concern.

========================================
CRITICAL TEST REQUIREMENTS
========================================

1. Use RefreshDatabase trait (reset DB for each test)
2. Use factories for test data (NOT manual creation)
3. Test happy path AND error scenarios
4. Assert JSON structure AND database state
5. Test authorization via user_feature_permission (403 if not authorized)
6. Test CSV validation (422) with all-or-nothing behavior
7. Test multi-tenant isolation for EVERY endpoint
8. Test N+1 queries using query log
9. Test pagination structure (links + meta)
10. Test proper status codes (200, 201, 204, 401, 403, 404, 422)

========================================
SUMMARY
========================================

Write feature tests that ensure:
1. All user management flows work (permission grant/revoke, role hierarchy)
2. CSV upload validates all-or-nothing with correct error structure
3. Single expense CRUD follows status workflow (draft -> submitted -> approved -> rejected)
4. FX conversion uses dated rates with 30-day lookback and commission
5. Expense sources enforce max 20 limit, global Other handling
6. Multi-tenant isolation enforced everywhere (client_id scoping)
7. All architectural patterns implemented (transactions, N+1, pagination, Resources)
8. All security requirements enforced (Oauth2UserClient, user_feature_permission)

Your tests are the final validation that the Volopa OOP Expense system is:
- Functionally correct
- Architecturally sound
- Secure and isolated
- Performance-optimized
- Following all DOS/DONTS patterns
"""

    def _format_permission_tests(self, um: dict) -> str:
        """Format user management requirements as test scenarios"""
        lines = []

        # Permission metrics tests
        metrics = um.get('permission_metrics', [])
        lines.append("## Role-Permission Matrix Tests")
        for m in metrics:
            role = m.get('role', 'Unknown')
            perms = m.get('default_permissions', {})
            lines.append(f"\n### {role}")
            lines.append(f"Default permissions: {perms}")
            lines.append(f"Test scenarios:")
            for perm_key, perm_val in perms.items():
                if perm_key == 'origin':
                    continue
                if perm_val:
                    lines.append(f"  - Test: {role} CAN {perm_key}: {perm_val}")
                else:
                    lines.append(f"  - Test: {role} CANNOT {perm_key}")
            if 'with_management_rights' in m:
                mgmt = m['with_management_rights']
                lines.append(f"  With management rights:")
                for mk, mv in mgmt.items():
                    if mk == 'origin':
                        continue
                    if mv:
                        lines.append(f"    - Test: {role} with mgmt rights CAN {mk}: {mv}")
                    else:
                        lines.append(f"    - Test: {role} with mgmt rights CANNOT {mk}")

        # Access control flow tests
        acf = um.get('access_control_flow', {})
        lines.append("\n## Access Control Flow Tests")

        enablement = acf.get('service_enablement_flow', [])
        if enablement:
            lines.append("\nService Enablement:")
            for step in enablement:
                lines.append(f"  - Test: {step}")

        defaults = acf.get('default_permissions_once_enabled', {})
        for role_key, role_val in defaults.items():
            if isinstance(role_val, dict):
                desc = role_val.get('description', role_key)
                lines.append(f"\n{desc}:")
                for p in role_val.get('permissions', []) + role_val.get('additional_permissions', []):
                    lines.append(f"  - Test: {p}")

        # Grant/revoke tests
        granting = acf.get('granting_managing_rights', {})
        lines.append("\n## Grant/Revoke Managing Rights Tests")
        for who in granting.get('who_can_grant', []):
            lines.append(f"  - Test: {who['role']} can grant via {who['method']}")

        revoking = acf.get('revoking_managing_rights', {})
        effect = revoking.get('revocation_effect', '')
        if effect:
            lines.append(f"  - Test: Revocation effect: {effect}")

        return '\n'.join(lines)

    def _format_csv_upload_tests(self, pe: dict) -> str:
        """Format pocket expense CSV upload requirements as test scenarios"""
        lines = []

        # API contract tests
        api = pe.get('api_contract', {})
        route = api.get('route', {})
        lines.append(f"## API Endpoint: {route.get('method', 'POST')} {route.get('path', '/api/uploads/pocket-expense/csv')}")
        lines.append(f"Middleware: {route.get('middleware', 'Oauth2UserClient')}")

        lines.append("\n### Request Validation Tests")
        validation = api.get('server_side_validation', {})
        for field, rule in validation.items():
            if field.endswith('_origin'):
                continue
            lines.append(f"  - Test: {field} validates with rule: {rule}")
            lines.append(f"  - Test: {field} rejects invalid input (422)")

        lines.append("\n### Additional Check Tests")
        for check in api.get('additional_checks', []):
            lines.append(f"  - Test: {check}")

        # CSV column validation tests
        lines.append("\n### CSV Column Validation Tests")
        vs = pe.get('validation_service', {})
        rules = vs.get('key_rules_per_row', {})
        for field, rule in rules.items():
            req = "required" if rule.get('required') else "optional"
            lines.append(f"\n  {field} ({req}):")
            for k, v in rule.items():
                if k != 'required':
                    lines.append(f"    - Test: {k} = {v}")

        # Error response tests
        lines.append("\n### Error Response Tests (HTTP 422)")
        er = pe.get('error_response', {})
        lines.append(f"  - Test: Response has success=false, message, upload_id, total_rows, error_count")
        lines.append(f"  - Test: errors array contains line_number, field, error, value per error")
        lines.append(f"  - Test: line_number corresponds to CSV line (header = line 1)")
        lines.append(f"  - Test: errors stored in pocket_expense_file_uploads.validation_errors")

        # Success response tests
        lines.append("\n### Success Response Tests")
        lines.append(f"  - Test: Response has success=true, message, upload_id, total_rows")
        lines.append(f"  - Test: All pocket_expense records created")
        lines.append(f"  - Test: PocketExpenseFileUpload status = 'completed', processed_at set")
        lines.append(f"  - Test: Notification issued to target user")

        # All-or-nothing tests
        lines.append("\n### All-or-Nothing Validation Tests")
        lines.append(f"  - Test: If 1 row invalid out of 200, zero expenses created")
        lines.append(f"  - Test: If all rows valid, all expenses created")
        lines.append(f"  - Test: Max 200 rows enforced")

        # Background sync tests
        ls = pe.get('storing_pocket_expenses_from_file_upload', {})
        flow = ls.get('flow', [])
        if flow:
            lines.append("\n### Background Sync Tests")
            for step in flow:
                lines.append(f"  - Test: {step}")

        # Security tests
        sec = pe.get('security_and_permissions', {})
        lines.append("\n### Security Tests")
        for check in sec.get('server_side_checks', []):
            lines.append(f"  - Test: {check}")

        return '\n'.join(lines)

    def _format_single_expense_tests(self, sdc: dict) -> str:
        """Format single expense data capturing requirements as test scenarios"""
        lines = []

        # Expense type tests
        tables = sdc.get('database_schema', {}).get('tables', [])
        for table in tables:
            if table.get('name') == 'opt_pocket_expense_type':
                lines.append("## Expense Type Tests")
                for seed in table.get('seed_data', []):
                    sign = seed.get('amount_sign', 'negative')
                    lines.append(f"  - Test: {seed['option']} has amount_sign={sign}")
                lines.append(f"  - Test: Amount sign applied correctly (+ve for Refund, -ve for others)")

        # Expense source tests
        src = sdc.get('oop_expense_source', {})
        lines.append("\n## Expense Source Config Tests")
        setup = src.get('default_and_global_source_setup', {})
        defaults = setup.get('on_client_oop_feature_enable', {}).get('auto_create_defaults', [])
        lines.append(f"  - Test: On OOP feature enable, 3 defaults created: {defaults}")
        lines.append(f"  - Test: Global 'Other' record exists with client_id=NULL")
        lines.append(f"  - Test: 'Other' is not deletable or editable by clients")

        dropdown = src.get('dropdown_display', {})
        lines.append(f"  - Test: Dropdown lists active client-specific sources (deleted=0)")
        lines.append(f"  - Test: Dropdown includes global 'Other' (client_id IS NULL)")

        submission = src.get('expense_submission', {})
        lines.append(f"  - Test: Non-Other source saves expense_source_id only")
        lines.append(f"  - Test: 'Other' source saves global Other ID + custom_source_text")

        config = src.get('client_config_behaviour', {})
        lines.append(f"  - Test: Max {config.get('max_active_sources_per_client', 20)} active sources per client enforced")
        lines.append(f"  - Test: Unique source names per client (client_id, name)")
        lines.append(f"  - Test: Soft-deleted sources remain on historical expenses")
        lines.append(f"  - Test: Soft-deleted sources excluded from future dropdowns")

        # Pocket expense CRUD tests
        lines.append("\n## Pocket Expense CRUD Tests")
        lines.append(f"  - Test: Create expense with status='draft'")
        lines.append(f"  - Test: Update expense (edit own)")
        lines.append(f"  - Test: View own expense")
        lines.append(f"  - Test: Delete own expense")
        lines.append(f"  - Test: Status workflow: draft -> submitted -> approved")
        lines.append(f"  - Test: Status workflow: draft -> submitted -> rejected")
        lines.append(f"  - Test: Only authorized users can approve (via user_feature_permission)")

        # Metadata tests
        lines.append("\n## Pocket Expense Metadata Tests")
        lines.append(f"  - Test: Category metadata with details_json")
        lines.append(f"  - Test: Tracking code metadata (type_1 and type_2)")
        lines.append(f"  - Test: Project metadata")
        lines.append(f"  - Test: File metadata")
        lines.append(f"  - Test: Expense source metadata (including custom 'Other' value)")
        lines.append(f"  - Test: Additional field metadata")
        lines.append(f"  - Test: One metadata record per type per expense (unique constraint)")

        # FX conversion tests
        fx = sdc.get('fx_conversion_flow', {})
        lines.append("\n## FX Conversion Tests")
        for step_key, step_val in fx.items():
            if not isinstance(step_val, dict):
                continue
            name = step_val.get('name', step_key)
            lines.append(f"\n  {name}:")
            for item in step_val.get('flow', []):
                lines.append(f"    - Test: {item}")

        lines.append(f"  - Test: FX rate with 30-day lookback (returns rate if within 30 days)")
        lines.append(f"  - Test: FX rate returns 'No FX Available' if no rate in 30 days")
        lines.append(f"  - Test: Adjusted rate = BaseRate x (1 - Commission%)")
        lines.append(f"  - Test: User can override converted amount (user_converted_amount stored)")
        lines.append(f"  - Test: Backend recalculates FX on form submit")

        return '\n'.join(lines)
