#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-15
@File    : laravel_qa_engineer.py
@Desc    : Laravel QA Engineer role for testing Volopa Mass Payments system
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
    - Test authorization in Policies
    - Test database transactions and rollbacks
    - Test N+1 query prevention (eager loading)
    - Test pagination on list endpoints
    - Test API Resource transformations
    - Test multi-tenant isolation (client_id filtering)
    - Test status state machine transitions
    - Test queue job processing
    - Test error handling and status codes

    Allocated Intents (from massPaymentsVolopaAgents.txt):
    - validatePaymentData: Test CSV validation rules
    - validateRecipientData: Test recipient validation
    - testMultiTenantIsolation: Test client_id filtering
    - testTransactionIntegrity: Test DB::transaction() rollbacks
    - testAuthorizationRules: Test Policy enforcement

    Test Coverage Requirements:
    - Unit tests: 0% (focus on feature/integration tests for APIs)
    - Feature tests: 100% coverage of all endpoints
    - Policy tests: 100% coverage of authorization rules
    - Validation tests: 100% coverage of FormRequest rules
    """

    use_fixed_sop: bool = True
    name: str = "Darius"
    profile: str = "Laravel QA Engineer"
    goal: str = "Write comprehensive PHP Unit tests ensuring Laravel code follows DOS/DONTS patterns"

    def __init__(self, **kwargs):
        super().__init__(**kwargs)

        # Load requirements from industry/requirements/
        self.arch_requirements = self._load_architectural_requirements()
        self.tech_requirements = self._load_technical_requirements()
        self.user_requirements = self._load_user_requirements()

        # Build comprehensive test constraints
        self._build_test_constraints()

    def _load_architectural_requirements(self) -> Dict[str, Any]:
        """Load architectural design patterns to test"""
        json_path = Path(__file__).parent.parent / "requirements" / "architectural_requirements.json"
        with open(json_path, 'r', encoding='utf-8') as f:
            return json.load(f)

    def _load_technical_requirements(self) -> Dict[str, Any]:
        """Load implementation patterns to test"""
        json_path = Path(__file__).parent.parent / "requirements" / "technical_requirements.json"
        with open(json_path, 'r', encoding='utf-8') as f:
            return json.load(f)

    def _load_user_requirements(self) -> Dict[str, Any]:
        """Load functional requirements to test"""
        json_path = Path(__file__).parent.parent / "requirements" / "user_requirements.json"
        with open(json_path, 'r', encoding='utf-8') as f:
            return json.load(f)

    def _build_test_constraints(self):
        """
        Build comprehensive test constraints from:
        1. Architectural requirements (test design patterns are implemented)
        2. Technical requirements (test Laravel syntax is correct)
        3. User requirements (test functional requirements work)
        """

        # Extract key sections
        arch_mental_model = self.arch_requirements['mental_model']['flow']
        user_frs = self.user_requirements['functional_requirements']
        stats = self.user_requirements['summary_statistics']

        self.constraints = f"""
You are a Laravel QA Engineer writing PHPUnit/Pest feature tests for a Laravel API.

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
        $response = $this->actingAs($user)->getJson('/api/v1/endpoint');

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
{arch_mental_model}

For EVERY endpoint, test:
1. Route exists and is accessible
2. Authentication required (401 if not authenticated)
3. Authorization enforced (403 if not authorized)
4. Validation rules work (422 with proper errors)
5. Business logic executes correctly
6. Response structure matches API Resource
7. Database state changes are correct

========================================
PRD REQUIREMENTS STRUCTURE
========================================

The PRD contains TWO levels of requirements:

1. High-Level Sections (Strategic):
   - Product Goals, User Stories, Requirement Pool (top 5)
   - Use for understanding test scope

2. Detailed Functional Requirements (Test Specifications):
   - Contains ALL requirements with acceptance criteria
   - Each requirement has: id, title, requirement, criteria, priority
   - May include classification (environment-specific vs project-specific)

IMPORTANT: Write tests to cover ALL requirements in "Detailed Functional Requirements",
not just the top 5. Reference requirement IDs in test method names and comments.

Test Naming Convention:
- test_{requirement_id}_{description}
- Example: test_fr11_download_template_with_currency_filter()

Test Coverage Strategy:
- P0 requirements: Must have 100% test coverage
- P1 requirements: Should have comprehensive coverage
- P2 requirements: Can have basic happy-path tests

If classification exists:
- Environment-Specific tests: Test business rules and configuration
  Example: test_inr_requires_invoice_number() for currency-specific validation
- Project-Specific tests: Test generic behavior and edge cases
  Example: test_file_upload_rejects_large_files() for standard validation
8. Status codes are appropriate

========================================
FUNCTIONAL REQUIREMENTS TO TEST
========================================

You must write tests covering ALL {stats['total_sub_requirements']} functional requirements:

{self._format_test_requirements(user_frs)}

========================================
ARCHITECTURAL PATTERNS TO TEST
========================================

## 1. Transaction Integrity (ARCH-TRANS-001)

Test that multi-write operations are atomic:

```php
/** @test */
public function test_payment_creation_rolls_back_on_validation_failure()
{{
    $user = User::factory()->create();
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);

    // Simulate validation failure
    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments/{{$file->id}}/process', [
        'rows' => [['invalid' => 'data']]  // Invalid data
    ]);

    $response->assertUnprocessable();

    // Assert ROLLBACK occurred - no partial data saved
    $this->assertDatabaseCount('payment_instructions', 0);
    $this->assertEquals('validation_failed', $file->fresh()->status);
}}
```

## 2. N+1 Query Prevention (ARCH-PERF-001)

Test that eager loading is used:

```php
/** @test */
public function test_list_endpoint_eager_loads_relationships()
{{
    $user = User::factory()->create();
    MassPaymentFile::factory()->count(3)->create([
        'client_id' => $user->client_id
    ]);

    // Enable query log
    \\DB::enableQueryLog();

    $response = $this->actingAs($user)->getJson('/api/v1/mass-payments');

    $queries = \\DB::getQueryLog();

    // Assert: Should be 1 query for files + 1 for eager loaded relations
    // NOT 1 + N queries (where N = number of files)
    $this->assertLessThanOrEqual(3, count($queries), 'N+1 query detected!');

    $response->assertOk();
}}
```

## 3. Pagination Required (ARCH-PERF-002)

Test that lists are paginated:

```php
/** @test */
public function test_list_endpoint_returns_paginated_results()
{{
    $user = User::factory()->create();
    MassPaymentFile::factory()->count(50)->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson('/api/v1/mass-payments');

    $response->assertOk();
    $response->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'total', 'per_page']
    ]);

    // Assert default page size (20)
    $this->assertCount(20, $response->json('data'));
}}
```

## 4. API Resources Used (ARCH-RESP-001)

Test that responses use API Resources (not raw models):

```php
/** @test */
public function test_response_uses_api_resource_structure()
{{
    $user = User::factory()->create();
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson("/api/v1/mass-payments/{{$file->id}}");

    $response->assertOk();

    // Assert: Response matches API Resource structure (snake_case, specific fields)
    $response->assertJsonStructure([
        'data' => [
            'id',
            'file_name',
            'status',
            'total_amount',
            'client' => ['id', 'name'],  // Nested resource
            'created_at',
            'updated_at'
        ]
    ]);

    // Assert: Internal fields are NOT exposed
    $response->assertJsonMissing(['password', 'remember_token']);
}}
```

## 5. Async Processing (ARCH-ASYNC-001)

Test that large operations are queued:

```php
/** @test */
public function test_large_file_processing_is_queued()
{{
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments', [
        'file' => UploadedFile::fake()->create('large.csv', 5000)  // Large file
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'processing');

    // Assert: Job was dispatched
    Queue::assertPushed(ValidateMassPaymentFileJob::class);
}}
```

## 6. Multi-Tenant Isolation (ARCH-SEC-002)

Test that users only access their client's data:

```php
/** @test */
public function test_user_cannot_access_other_client_files()
{{
    $user = User::factory()->create(['client_id' => 1]);
    $otherClientFile = MassPaymentFile::factory()->create(['client_id' => 999]);

    $response = $this->actingAs($user)->getJson("/api/v1/mass-payments/{{$otherClientFile->id}}");

    // Assert: 404 or 403 (not 200)
    $this->assertTrue(in_array($response->status(), [403, 404]));
}}

/** @test */
public function test_list_endpoint_only_returns_own_client_files()
{{
    $user = User::factory()->create(['client_id' => 1]);
    MassPaymentFile::factory()->count(5)->create(['client_id' => 1]);  // Own client
    MassPaymentFile::factory()->count(10)->create(['client_id' => 999]);  // Other client

    $response = $this->actingAs($user)->getJson('/api/v1/mass-payments');

    $response->assertOk();
    $this->assertCount(5, $response->json('data'));  // Only own client's files

    // Assert: All returned files belong to user's client
    foreach ($response->json('data') as $file) {{
        $this->assertEquals($user->client_id, $file['client']['id']);
    }}
}}
```

========================================
VALIDATION & AUTHORIZATION TESTING
========================================

## FormRequest Validation Tests

Test ALL validation rules defined in FormRequests:

```php
/** @test */
public function test_validation_fails_with_invalid_data()
{{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments', [
        'file' => 'not-a-file',  // Invalid
        'client_id' => 'invalid'  // Invalid
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['file', 'client_id']);
}}

/** @test */
public function test_validation_passes_with_valid_data()
{{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments', [
        'file' => UploadedFile::fake()->create('valid.csv', 100),
        'client_id' => $user->client_id
    ]);

    $response->assertCreated();
}}
```

## Policy Authorization Tests

Test ALL authorization rules defined in Policies:

```php
/** @test */
public function test_user_cannot_approve_without_approver_role()
{{
    $user = User::factory()->create(['role' => 'uploader']);  // Not approver
    $file = MassPaymentFile::factory()->create([
        'client_id' => $user->client_id,
        'status' => 'pending_approval'
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/mass-payments/{{$file->id}}/approve");

    $response->assertForbidden();
}}

/** @test */
public function test_approver_can_approve_pending_file()
{{
    $user = User::factory()->create(['role' => 'approver']);
    $file = MassPaymentFile::factory()->create([
        'client_id' => $user->client_id,
        'status' => 'pending_approval'
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/mass-payments/{{$file->id}}/approve");

    $response->assertOk();
    $this->assertEquals('approved', $file->fresh()->status);
}}
```

========================================
STATUS CODES TESTING
========================================

Test proper HTTP status codes for all scenarios:

```php
/** @test */
public function test_endpoint_returns_correct_status_codes()
{{
    $user = User::factory()->create();

    // 200 OK - Successful GET
    $this->actingAs($user)->getJson('/api/v1/mass-payments')->assertOk();

    // 201 Created - Successful POST
    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments', [/* valid data */]);
    $response->assertCreated();

    // 204 No Content - Successful DELETE
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);
    $this->actingAs($user)->deleteJson("/api/v1/mass-payments/{{$file->id}}")->assertNoContent();

    // 401 Unauthorized - No authentication
    $this->getJson('/api/v1/mass-payments')->assertUnauthorized();

    // 403 Forbidden - Authenticated but not authorized
    $otherClientFile = MassPaymentFile::factory()->create(['client_id' => 999]);
    $this->actingAs($user)->getJson("/api/v1/mass-payments/{{$otherClientFile->id}}")->assertForbidden();

    // 404 Not Found - Resource doesn't exist
    $this->actingAs($user)->getJson('/api/v1/mass-payments/nonexistent-id')->assertNotFound();

    // 422 Unprocessable Entity - Validation failed
    $this->actingAs($user)->postJson('/api/v1/mass-payments', [/* invalid data */])->assertUnprocessable();
}}
```

========================================
IMPLEMENTATION ANTI-PATTERNS TESTING
========================================

Test that code does NOT contain anti-patterns:

## Test: No Raw Models Returned

```php
/** @test */
public function test_response_does_not_expose_internal_model_fields()
{{
    $user = User::factory()->create(['password' => bcrypt('secret'), 'remember_token' => 'abc123']);
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson("/api/v1/mass-payments/{{$file->id}}");

    // Assert: Sensitive fields NOT exposed
    $response->assertJsonMissing(['password', 'remember_token', 'api_secret']);
}}
```

## Test: Consistent JSON Casing (snake_case)

```php
/** @test */
public function test_response_uses_snake_case_consistently()
{{
    $user = User::factory()->create();
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->getJson("/api/v1/mass-payments/{{$file->id}}");

    $json = $response->json('data');

    // Assert: All keys are snake_case (not camelCase)
    foreach (array_keys($json) as $key) {{
        $this->assertEquals($key, strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key)));
    }}
}}
```

========================================
VOLOPA-SPECIFIC TESTING
========================================

## Test: OAuth2/WSSE Authentication

```php
/** @test */
public function test_endpoint_requires_volopa_authentication()
{{
    // Test without auth header
    $response = $this->getJson('/api/v1/mass-payments');
    $response->assertUnauthorized();

    // Test with Volopa OAuth2 token (if middleware configured)
    $response = $this->withHeader('Authorization', 'Bearer valid-token')
                     ->getJson('/api/v1/mass-payments');
    $response->assertOk();
}}
```

## Test: Client Feature Flags

```php
/** @test */
public function test_feature_flag_controls_access()
{{
    $user = User::factory()->create(['client_id' => 1]);

    // Disable feature for client
    ClientFeature::where('client_id', 1)->update(['mass_payments_enabled' => false]);

    $response = $this->actingAs($user)->getJson('/api/v1/mass-payments');

    $response->assertForbidden();
    $response->assertJson(['message' => 'Mass payments not enabled for your client']);
}}
```

========================================
DATABASE STATE TESTING
========================================

Always test database changes:

```php
/** @test */
public function test_creating_file_saves_to_database()
{{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/mass-payments', [
        'file' => UploadedFile::fake()->create('test.csv', 100)
    ]);

    $response->assertCreated();

    // Assert: Record exists in database
    $this->assertDatabaseHas('mass_payment_files', [
        'client_id' => $user->client_id,
        'status' => 'uploading'
    ]);
}}

/** @test */
public function test_deleting_file_removes_from_database()
{{
    $user = User::factory()->create();
    $file = MassPaymentFile::factory()->create(['client_id' => $user->client_id]);

    $response = $this->actingAs($user)->deleteJson("/api/v1/mass-payments/{{$file->id}}");

    $response->assertNoContent();

    // Assert: Record deleted (or soft deleted)
    $this->assertDatabaseMissing('mass_payment_files', ['id' => $file->id]);
    // OR for soft deletes:
    // $this->assertSoftDeleted('mass_payment_files', ['id' => $file->id]);
}}
```

========================================
TEST ORGANIZATION
========================================

Organize tests by resource:

tests/Feature/
├── MassPaymentFileTest.php          (CRUD operations, validation, authorization)
├── PaymentInstructionTest.php       (Payment creation, approval)
├── RecipientTemplateTest.php        (Template download)
├── StatusTransitionTest.php         (State machine transitions)
├── MultiTenantIsolationTest.php     (Client data isolation)
├── TransactionIntegrityTest.php     (Rollback scenarios)
└── ValidationRulesTest.php          (All validation rules)

Each test file should test ONE resource or ONE concern.

========================================
CRITICAL TEST REQUIREMENTS
========================================

1. ✅ Use RefreshDatabase trait (reset DB for each test)
2. ✅ Use factories for test data (NOT manual creation)
3. ✅ Test happy path AND error scenarios
4. ✅ Assert JSON structure AND database state
5. ✅ Test authorization (403) before testing functionality
6. ✅ Test validation (422) with multiple invalid scenarios
7. ✅ Test multi-tenant isolation for EVERY endpoint
8. ✅ Test N+1 queries using query log
9. ✅ Test pagination structure (links + meta)
10. ✅ Test proper status codes (200, 201, 204, 401, 403, 404, 422)

========================================
TEST COVERAGE GOAL
========================================

Achieve 100% coverage of:
- All {stats['total_sub_requirements']} functional requirements
- All API endpoints (routes)
- All FormRequest validation rules
- All Policy authorization methods
- All status state transitions
- All multi-write transactions
- All multi-tenant isolation scenarios

Total estimated tests: ~150-200 test methods across {stats['total_sub_requirements']} sub-requirements

========================================
SUMMARY
========================================

Write feature tests that ensure:
1. All functional requirements work correctly
2. All architectural patterns are implemented (transactions, N+1, pagination, etc.)
3. All implementation syntax is correct (status codes, Resources, snake_case)
4. All security requirements are enforced (auth, authorization, client isolation)
5. All anti-patterns are NOT present (raw models, hardcoded values, etc.)

Your tests are the final validation that the Laravel Mass Payments system is:
- Functionally correct
- Architecturally sound
- Secure and isolated
- Performance-optimized
- Following all DOS/DONTS patterns
"""

    def _format_test_requirements(self, frs: Dict) -> str:
        """Format functional requirements as test scenarios"""
        lines = []
        for fr_id, fr_data in frs.items():
            lines.append(f"\n### {fr_id}: {fr_data['category']}")
            for sub_id, sub_req in fr_data['sub_requirements'].items():
                lines.append(f"\n**{sub_id}**: {sub_req['title']}")
                lines.append("Test scenarios:")

                # Extract test scenarios from criteria
                if 'criteria' in sub_req:
                    for criterion in sub_req['criteria']:
                        lines.append(f"  - Test: {criterion}")

                # Add validation tests if present
                if 'validations' in sub_req:
                    lines.append("  - Test: All validation rules pass with valid data")
                    lines.append("  - Test: Validation fails with invalid data (422)")

                # Add authorization test
                lines.append("  - Test: Requires authentication (401 without auth)")
                lines.append("  - Test: Requires authorization (403 if not authorized)")

                # Add multi-tenant test
                lines.append("  - Test: Client data isolation (403/404 for other client's data)")

        return '\n'.join(lines)
