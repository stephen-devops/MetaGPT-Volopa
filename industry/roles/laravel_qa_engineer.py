#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-15
@File    : laravel_qa_engineer.py
@Desc    : Laravel QA Engineer role for testing Volopa OOP Expense system
"""

from metagpt.roles.qa_engineer import QaEngineer
from industry.utils.context_reader import ContextReader


class LaravelQaEngineer(QaEngineer):
    """
    Laravel QA Engineer specialized for writing PHPUnit/Pest tests for Laravel APIs.

    Responsibilities:
    - Write feature tests for all API endpoints
    - Test validation rules in FormRequests
    - Test authorization via Policies and permission checks
    - Test database transactions and rollbacks (all-or-nothing batch operations)
    - Test N+1 query prevention (eager loading)
    - Test pagination on list endpoints
    - Test API Resource transformations
    - Test multi-tenant isolation (client_id filtering)
    - Test status workflow transitions
    - Test queue job processing
    - Test error handling and status codes

    Domain knowledge is loaded exclusively from YAML context specifications.

    Test Coverage Requirements:
    - Unit tests: 0% (focus on feature/integration tests for APIs)
    - Feature tests: 100% coverage of all endpoints
    - Policy tests: 100% coverage of authorization rules
    - Validation tests: 100% coverage of FormRequest and CSV validation rules
    """

    use_fixed_sop: bool = True
    name: str = "Darius"
    profile: str = "Laravel QA Engineer"
    goal: str = (
        "Write comprehensive PHPUnit tests ensuring Laravel code follows specifications from YAML context. "
        "Use same language as user requirement"
    )

    def __init__(self, **kwargs):
        super().__init__(**kwargs)

        # Build comprehensive test constraints from YAML (local var to avoid Pydantic serialization issues)
        self._build_test_constraints(ContextReader())

    def _build_test_constraints(self, context_reader: ContextReader):
        """Build test constraints from YAML context."""

        lines = []
        lines.append("You are a Laravel QA Engineer writing PHPUnit/Pest feature tests.")
        lines.append("")
        lines.append("========================================")
        lines.append("CRITICAL TEST OUTPUT FORMAT")
        lines.append("========================================")
        lines.append("")
        lines.append("Generate PHP test files in this format:")
        lines.append("")
        lines.append("File: tests/Feature/{Resource}Test.php")
        lines.append("")
        lines.append("```php")
        lines.append("<?php")
        lines.append("")
        lines.append("namespace Tests\\Feature;")
        lines.append("")
        lines.append("use Tests\\TestCase;")
        lines.append("use Illuminate\\Foundation\\Testing\\RefreshDatabase;")
        lines.append("use App\\Models\\{Model};")
        lines.append("use App\\Models\\User;")
        lines.append("")
        lines.append("class {Resource}Test extends TestCase")
        lines.append("{")
        lines.append("    use RefreshDatabase;")
        lines.append("")
        lines.append("    /** @test */")
        lines.append("    public function test_method_name()")
        lines.append("    {")
        lines.append("        // Arrange")
        lines.append("        $user = User::factory()->create(['client_id' => 1]);")
        lines.append("")
        lines.append("        // Act")
        lines.append("        $response = $this->actingAs($user)->getJson('/api/endpoint');")
        lines.append("")
        lines.append("        // Assert")
        lines.append("        $response->assertOk();")
        lines.append("        $response->assertJsonStructure(['data' => ['id', 'name']]);")
        lines.append("    }")
        lines.append("}")
        lines.append("```")

        lines.append("")
        lines.append("========================================")
        lines.append("TEST STRATEGY")
        lines.append("========================================")
        lines.append("")
        lines.append("For EVERY endpoint, test:")
        lines.append("1. Route exists and is accessible")
        lines.append("2. Authentication required (401 if not authenticated via Oauth2)")
        lines.append("3. Authorization enforced (403 if permission check fails)")
        lines.append("4. Validation rules work (422 with proper errors)")
        lines.append("5. Business logic executes correctly")
        lines.append("6. Response structure matches API Resource")
        lines.append("7. Database state changes are correct")
        lines.append("8. Status codes are appropriate")

        lines.append("")
        lines.append("========================================")
        lines.append("ARCHITECTURAL PATTERNS TO TEST")
        lines.append("========================================")
        lines.append("")
        lines.append("1. Transaction Integrity - All-or-Nothing Batch:")
        lines.append("   - Upload CSV with N valid rows and 1 invalid row -> assert 422, assert 0 records (rollback)")
        lines.append("   - Upload CSV with all valid rows -> assert 200, assert N records created")
        lines.append("   - Assert error response matches response_schemas structure")
        lines.append("")
        lines.append("2. N+1 Query Prevention:")
        lines.append("   - For EVERY list endpoint, enable query log, fetch list, assert query count <= threshold")
        lines.append("")
        lines.append("3. Pagination Required:")
        lines.append("   - For EVERY list endpoint, assert response has: data, links, meta")
        lines.append("")
        lines.append("4. Multi-Tenant Isolation:")
        lines.append("   - For EVERY endpoint: create records with different client_ids")
        lines.append("   - Assert user cannot access other client's records (403 or 404)")
        lines.append("")
        lines.append("5. Async Processing - Background Sync:")
        lines.append("   - Assert Queue::fake() + Queue::assertPushed() for background jobs")
        lines.append("")
        lines.append("6. API Resources (Not Raw Models):")
        lines.append("   - For EVERY endpoint: assert response uses API Resource structure (data wrapper)")
        lines.append("   - Assert sensitive fields excluded")
        lines.append("")
        lines.append("7. Status Codes:")
        lines.append("   - 200 OK: Successful GET and successful batch upload")
        lines.append("   - 201 Created: Successful POST (single record creation)")
        lines.append("   - 204 No Content: Successful DELETE")
        lines.append("   - 401 Unauthorized: No authentication token")
        lines.append("   - 403 Forbidden: Permission check fails")
        lines.append("   - 404 Not Found: Record does not exist or belongs to different client")
        lines.append("   - 422 Unprocessable: Validation failure (form fields or CSV content)")

        lines.append("")
        lines.append("========================================")
        lines.append("TEST ORGANIZATION")
        lines.append("========================================")
        lines.append("")
        lines.append("Organize tests by module and concern:")
        lines.append("- One test file per resource or per concern")
        lines.append("- Derive test file names from YAML table names and API routes")
        lines.append("- Example: table 'some_table' -> tests/Feature/SomeTableTest.php")
        lines.append("")
        lines.append("Additional cross-cutting test files:")
        lines.append("  - MultiTenantIsolationTest.php (client_id filtering across all endpoints)")
        lines.append("  - TransactionIntegrityTest.php (all-or-nothing batch operations, rollback scenarios)")
        lines.append("  - CSVValidationRulesTest.php (per-field validation from validation_service)")

        lines.append("")
        lines.append("========================================")
        lines.append("CRITICAL TEST REQUIREMENTS")
        lines.append("========================================")
        lines.append("")
        lines.append("1. Use RefreshDatabase trait (reset DB for each test)")
        lines.append("2. Use factories for test data (NOT manual creation)")
        lines.append("3. Test happy path AND error scenarios")
        lines.append("4. Assert JSON structure AND database state")
        lines.append("5. Test authorization via permission table (403 if not authorized)")
        lines.append("6. Test CSV validation (422) with all-or-nothing behavior")
        lines.append("7. Test multi-tenant isolation for EVERY endpoint")
        lines.append("8. Test N+1 queries using query log")
        lines.append("9. Test pagination structure (links + meta)")
        lines.append("10. Test proper status codes (200, 201, 204, 401, 403, 404, 422)")

        # === YAML Context (authoritative domain data) ===
        lines.append("")
        lines.append("=" * 60)
        lines.append("CONTEXT FROM YAML (authoritative source for all domain data):")
        lines.append("=" * 60)
        lines.append("")
        lines.append(context_reader.get_mental_model())
        lines.append("")
        lines.append(context_reader.get_dos_and_donts())
        lines.append("")
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_project_requirements())
        lines.append("")
        lines.append(context_reader.get_project_constraints())
        lines.append("")
        lines.append(context_reader.get_database_tables("full"))
        lines.append("")
        lines.append(context_reader.get_csv_column_schema())
        lines.append("")
        lines.append(context_reader.get_api_routes())
        lines.append("")
        lines.append(context_reader.get_response_schemas())
        lines.append("")
        lines.append(context_reader.get_permission_matrix())
        lines.append("")
        lines.append(context_reader.get_flows())
        lines.append("")
        lines.append(context_reader.get_components_to_build())
        lines.append("")
        lines.append(context_reader.get_interfaces_summary())
        lines.append("")
        lines.append(context_reader.get_existing_platform_services())
        lines.append("")
        lines.append(context_reader.get_existing_tables_and_models())
        lines.append("")
        lines.append(context_reader.get_fx_query_contract())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_existing_user_roles())

        self.constraints = '\n'.join(lines)
