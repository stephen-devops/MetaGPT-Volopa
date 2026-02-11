#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-15
@File    : laravel_qa_engineer.py
@Desc    : Laravel QA Engineer role for testing Volopa OOP Expense system
"""

import json
from pathlib import Path
from metagpt.roles.qa_engineer import QaEngineer


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

    Domain modules are loaded from JSON specifications at runtime.

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
        "Write comprehensive PHPUnit tests ensuring Laravel code follows specifications loaded from JSON. "
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
        Build comprehensive test constraints derived entirely from JSON.
        All table names, route paths, column names, and model names come from JSON specs.
        """

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

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
        lines.append("TESTING MENTAL MODEL")
        lines.append("========================================")
        lines.append("")
        lines.append("Test the complete flow:")
        lines.append("Client -> route (Oauth2UserClient middleware) -> controller -> FormRequest")
        lines.append("(validation + policy) -> service/model (domain logic, transactions) ->")
        lines.append("API Resource (shape output) -> JSON with correct status codes and error format")
        lines.append("")
        lines.append("For EVERY endpoint, test:")
        lines.append("1. Route exists and is accessible")
        lines.append("2. Authentication required (401 if not authenticated via Oauth2UserClient)")
        lines.append("3. Authorization enforced (403 if permission check fails)")
        lines.append("4. Validation rules work (422 with proper errors)")
        lines.append("5. Business logic executes correctly")
        lines.append("6. Response structure matches API Resource")
        lines.append("7. Database state changes are correct")
        lines.append("8. Status codes are appropriate")

        # === MODULE 1: User Management Tests ===
        lines.append("")
        lines.append("========================================")
        lines.append("MODULE 1: USER MANAGEMENT TESTS")
        lines.append("========================================")
        self._append_permission_tests(lines, um)

        # === MODULE 2: Pocket Expense CSV Upload Tests ===
        lines.append("")
        lines.append("========================================")
        lines.append("MODULE 2: CSV BATCH UPLOAD TESTS")
        lines.append("========================================")
        self._append_csv_upload_tests(lines, pe)

        # === MODULE 3: Single Expense Data Capturing Tests ===
        lines.append("")
        lines.append("========================================")
        lines.append("MODULE 3: SINGLE EXPENSE DATA CAPTURING TESTS")
        lines.append("========================================")
        self._append_single_expense_tests(lines, sdc)

        # === Architectural Pattern Tests (generic, JSON-derived) ===
        lines.append("")
        lines.append("========================================")
        lines.append("ARCHITECTURAL PATTERNS TO TEST")
        lines.append("========================================")
        self._append_architectural_pattern_tests(lines, pe, sdc)

        # === Test Organization ===
        lines.append("")
        lines.append("========================================")
        lines.append("TEST ORGANIZATION")
        lines.append("========================================")
        lines.append("")
        lines.append("Organize tests by module and concern:")
        lines.append("- One test file per resource or per concern")
        lines.append("- Derive test file names from JSON table names and API routes")
        lines.append("- Example: table 'some_table' -> tests/Feature/SomeTableTest.php")
        lines.append("")

        # Derive test file list from JSON tables
        all_tables = []
        for t in um.get('database_schema', {}).get('tables', []):
            all_tables.append(t.get('name', ''))
        upload_table = pe.get('data_model', {}).get('new_table', {}).get('name', '')
        if upload_table:
            all_tables.append(upload_table)
        for t in sdc.get('database_schema', {}).get('tables', []):
            all_tables.append(t.get('name', ''))
        local_table = pe.get('storing_pocket_expenses_from_file_upload', {}).get('local_storage_schema', {}).get('table_name', '')
        if local_table and local_table not in all_tables:
            all_tables.append(local_table)

        lines.append("Tables from JSON (each needs test coverage):")
        for tname in all_tables:
            if tname:
                lines.append(f"  - {tname}")

        lines.append("")
        lines.append("Additional cross-cutting test files:")
        lines.append("  - MultiTenantIsolationTest.php (client_id filtering across all endpoints)")
        lines.append("  - TransactionIntegrityTest.php (all-or-nothing batch operations, rollback scenarios)")
        lines.append("  - CSVValidationRulesTest.php (per-field validation from validation_service)")

        # === Critical Test Requirements ===
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

        self.constraints = '\n'.join(lines)

    # ── Helper methods: each extracts one JSON section as test scenarios ──

    def _append_permission_tests(self, lines: list, um: dict):
        """Format user management requirements as test scenarios — all from JSON"""
        metrics = um.get('permission_metrics', [])
        lines.append("")
        lines.append("## Role-Permission Matrix Tests")
        for m in metrics:
            role = m.get('role', 'Unknown')
            origin = m.get('origin', 'new')
            perms = m.get('default_permissions', {})
            lines.append(f"\n### {role} (origin: {origin})")
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
                if isinstance(mgmt, dict):
                    for mk, mv in mgmt.items():
                        if mk == 'origin':
                            continue
                        if mv:
                            lines.append(f"    - Test: {role} with mgmt rights CAN {mk}: {mv}")
                        else:
                            lines.append(f"    - Test: {role} with mgmt rights CANNOT {mk}")

        # Hierarchy
        hierarchy = um.get('hierarchy', {})
        if hierarchy:
            lines.append(f"\n## Hierarchy Tests")
            lines.append(f"  Description: {hierarchy.get('description', '')}")
            tree = hierarchy.get('tree', {})
            if tree:
                lines.append(f"  - Test: {tree.get('role', '?')} capabilities: {tree.get('capabilities', [])}")
                delegated = tree.get('delegated', {})
                if delegated:
                    lines.append(f"  - Test: {delegated.get('role', '?')} capabilities: {delegated.get('capabilities', [])}")

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
        if granting:
            lines.append("\n## Grant/Revoke Managing Rights Tests")
            for who in granting.get('who_can_grant', []):
                lines.append(f"  - Test: {who.get('role', '?')} can grant via {who.get('method', '?')}")

        revoking = acf.get('revoking_managing_rights', {})
        if revoking:
            effect = revoking.get('revocation_effect', '')
            if effect:
                lines.append(f"  - Test: Revocation effect: {effect}")

        # UM database tables
        um_tables = um.get('database_schema', {}).get('tables', [])
        if um_tables:
            lines.append("\n## User Management Table Tests")
            for table in um_tables:
                name = table.get('name', '?')
                origin = table.get('origin', 'new')
                cols = table.get('columns', [])
                lines.append(f"\n  Table: {name} (origin: {origin}, {len(cols)} columns)")
                lines.append(f"  - Test: Table has correct columns: {[c['name'] for c in cols]}")
                fks = table.get('foreign_keys', [])
                for fk in fks:
                    lines.append(f"  - Test: FK {fk.get('column', '?')} -> {fk.get('references', '?')}")

    def _append_csv_upload_tests(self, lines: list, pe: dict):
        """Format CSV upload requirements as test scenarios — all from JSON"""

        # Overview + validation behavior
        overview = pe.get('overview', {})
        vb = overview.get('validation_behavior', {})
        if vb:
            lines.append(f"\n## Validation Behavior (from JSON)")
            lines.append(f"  - Test: {vb.get('description', '')}")
            lines.append(f"  - Test on_failure: {vb.get('on_failure', '')}")
            lines.append(f"  - Test on_success: {vb.get('on_success', '')}")

        # API contract tests
        api = pe.get('api_contract', {})
        route = api.get('route', {})
        route_path = route.get('path', '?')
        route_method = route.get('method', 'POST')
        lines.append(f"\n## API Endpoint: {route_method} {route_path}")
        lines.append(f"Middleware: {route.get('middleware', '?')}")
        lines.append(f"Controller: {route.get('controller', '?')}")

        # Form fields
        fields = api.get('form_fields', [])
        if fields:
            lines.append(f"\n### Form Field Tests")
            for field in fields:
                req = "required" if field.get('required') else "optional"
                lines.append(f"  - Test: {field['name']} ({req}): {field.get('description', '')}")

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

        # File constraints
        fc = pe.get('csv_file_definition', {}).get('file_constraints', {})
        if fc:
            lines.append(f"\n### File Constraint Tests")
            for k, v in fc.items():
                if k == 'origin':
                    continue
                lines.append(f"  - Test: {k} enforced: {v}")

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

        # Row failure behavior (JSON value may be a string or dict)
        rfb = vs.get('row_failure_behavior', '')
        if rfb:
            lines.append(f"\n### Row Failure Behavior Tests")
            if isinstance(rfb, dict):
                for k, v in rfb.items():
                    if k == 'origin':
                        continue
                    lines.append(f"  - Test: {k}: {v}")
            else:
                lines.append(f"  - Test: {rfb}")

        # File processing pipeline
        fp = pe.get('file_processing', {})
        fp_steps = fp.get('steps', [])
        if fp_steps:
            lines.append(f"\n### File Processing Pipeline Tests")
            for step in fp_steps:
                lines.append(f"  Step {step.get('step', '?')}: {step.get('name', '')}")
                details = step.get('details', [])
                if isinstance(details, list):
                    for detail in details:
                        if isinstance(detail, str):
                            lines.append(f"    - Test: {detail}")
                if 'on_error' in step:
                    err = step['on_error']
                    for action in err.get('actions', []):
                        lines.append(f"    - Test on_error: {action}")
                if 'on_success' in step:
                    suc = step['on_success']
                    for action in suc.get('actions', []):
                        lines.append(f"    - Test on_success: {action}")

        # Upload tracking table
        dm = pe.get('data_model', {}).get('new_table', {})
        if dm:
            upload_table = dm.get('name', '?')
            lines.append(f"\n### Upload Tracking Table Tests ({upload_table})")
            for col in dm.get('columns', []):
                comment = f" — {col['comment']}" if 'comment' in col else ''
                lines.append(f"  - Test: column {col['name']} {col['type']}{comment}")

        # Error response tests
        lines.append("\n### Error Response Tests")
        er = pe.get('error_response', {})
        lines.append(f"  HTTP Status: {er.get('http_status', '?')}")
        structure = er.get('structure', {})
        if structure:
            lines.append(f"  - Test: Response matches structure: {json.dumps(structure)}")
        notes = er.get('notes', [])
        for note in notes:
            lines.append(f"  - Test: {note}")
        fe_req = er.get('frontend_requirements', [])
        if fe_req:
            lines.append(f"  Frontend Requirements:")
            for req in fe_req:
                lines.append(f"    - Test: {req}")

        # Success response tests
        lines.append("\n### Success Response Tests")
        sr = pe.get('success_response', {})
        sr_structure = sr.get('structure', {})
        if sr_structure:
            lines.append(f"  - Test: Response matches structure: {json.dumps(sr_structure)}")

        # Local storage & background sync
        ls = pe.get('storing_pocket_expenses_from_file_upload', {})
        flow = ls.get('flow', [])
        if flow:
            lines.append("\n### Local Storage & Background Sync Tests")
            for step in flow:
                lines.append(f"  - Test: {step}")
        local_schema = ls.get('local_storage_schema', {})
        if local_schema:
            lines.append(f"  Local Table: {local_schema.get('table_name', '?')}")
            for col in local_schema.get('columns', []):
                lines.append(f"    - Test: column {col['name']} {col['type']}")
        bulk = ls.get('bulk_insert_pattern', {})
        if bulk:
            lines.append(f"  Bulk Insert Pattern:")
            for k, v in bulk.items():
                if k == 'origin':
                    continue
                lines.append(f"    - Test: {k}: {v}")

        # Security tests
        sec = pe.get('security_and_permissions', {})
        if sec:
            lines.append("\n### Security Tests")
            ep = sec.get('endpoint_protection', '')
            if ep:
                lines.append(f"  - Test: Endpoint protected by: {ep}")
            for check in sec.get('server_side_checks', []):
                lines.append(f"  - Test: {check}")

    def _append_single_expense_tests(self, lines: list, sdc: dict):
        """Format single expense data capturing requirements as test scenarios — all from JSON"""

        # All SDC database tables
        tables = sdc.get('database_schema', {}).get('tables', [])
        for table in tables:
            name = table.get('name', '?')
            origin = table.get('origin', 'new')
            cols = table.get('columns', [])
            lines.append(f"\n## Table Tests: {name} (origin: {origin})")
            lines.append(f"  - Test: Table has {len(cols)} columns: {[c['name'] for c in cols]}")
            # Seed data
            for seed in table.get('seed_data', []):
                if isinstance(seed, dict):
                    lines.append(f"  - Test seed: {seed}")
                else:
                    lines.append(f"  - Test seed: {seed}")
            # Unique keys
            for uk in table.get('unique_keys', []):
                lines.append(f"  - Test unique key: {uk.get('name', '?')} on {uk.get('columns', [])}")
            # Foreign keys
            for fk in table.get('foreign_keys', []):
                ref_origin = fk.get('referenced_table_origin', 'unknown')
                lines.append(f"  - Test FK: {fk.get('column', '?')} -> {fk.get('references', '?')} (ref origin: {ref_origin})")

        # Metadata JSON examples (JSON value is a dict keyed by type name, not a list)
        examples = sdc.get('database_schema', {}).get('metadata_json_examples', {})
        if examples:
            lines.append(f"\n## Metadata JSON Tests")
            for meta_type, example in examples.items():
                if meta_type == 'origin':
                    continue
                lines.append(f"  - Test: type={meta_type}, details_json={json.dumps(example) if isinstance(example, dict) else example}")

        # Expense source tests
        src = sdc.get('oop_expense_source', {})
        if src:
            lines.append("\n## Expense Source Config Tests")
            setup = src.get('default_and_global_source_setup', {})
            on_enable = setup.get('on_client_oop_feature_enable', {})
            defaults = on_enable.get('auto_create_defaults', [])
            if defaults:
                lines.append(f"  - Test: On feature enable, defaults created: {defaults}")
            global_other = on_enable.get('global_other', {})
            if global_other:
                lines.append(f"  - Test: Global Other record: {global_other}")

            dropdown = src.get('dropdown_display', {})
            if dropdown:
                lines.append(f"  Dropdown Display Tests:")
                for k, v in dropdown.items():
                    if k == 'origin':
                        continue
                    lines.append(f"    - Test: {k}: {v}")

            submission = src.get('expense_submission', {})
            if submission:
                lines.append(f"  Expense Submission Tests:")
                for k, v in submission.items():
                    if k == 'origin':
                        continue
                    lines.append(f"    - Test: {k}: {v}")

            config = src.get('client_config_behaviour', {})
            if config:
                lines.append(f"  Client Config Behaviour Tests:")
                for k, v in config.items():
                    if k == 'origin':
                        continue
                    lines.append(f"    - Test: {k}: {v}")

        # Currency conversion
        cc = sdc.get('currency_conversion', {})
        if cc:
            lines.append(f"\n## Currency Conversion Tests")
            lines.append(f"  - Test: {cc.get('description', '')}")
            lines.append(f"  - Test query: {cc.get('query_description', '')}")
            refs = cc.get('referenced_tables', [])
            if refs:
                lines.append(f"  Referenced Tables (origin: existing):")
                for ref in refs:
                    lines.append(f"    - Test: uses existing table: {ref}")
            fields = cc.get('returned_fields', [])
            if fields:
                lines.append(f"  Returned Fields:")
                for f in fields:
                    lines.append(f"    - Test: returns {f}")
            filters = cc.get('filter_conditions', [])
            if filters:
                lines.append(f"  Filter Conditions:")
                for fc in filters:
                    lines.append(f"    - Test: {fc}")

        # FX conversion flow (ALL steps, no truncation)
        fx = sdc.get('fx_conversion_flow', {})
        if fx:
            lines.append("\n## FX Conversion Flow Tests")
            for step_key, step_val in fx.items():
                if not isinstance(step_val, dict):
                    continue
                name = step_val.get('name', step_key)
                lines.append(f"\n  {name}:")
                for item in step_val.get('flow', []):
                    lines.append(f"    - Test: {item}")

    def _append_architectural_pattern_tests(self, lines: list, pe: dict, sdc: dict):
        """Generate architectural pattern test guidance derived from JSON structures, not hardcoded names."""

        # Derive key names from JSON for use in test examples
        api = pe.get('api_contract', {})
        route = api.get('route', {})
        upload_route = route.get('path', '/api/uploads/csv')
        upload_table = pe.get('data_model', {}).get('new_table', {}).get('name', 'upload_table')

        # Local storage table for batch records
        local_table = pe.get('storing_pocket_expenses_from_file_upload', {}).get(
            'local_storage_schema', {}).get('table_name', 'local_expense_table')

        # Error response structure from JSON
        er_structure = pe.get('error_response', {}).get('structure', {})
        er_keys = list(er_structure.keys()) if er_structure else ['success', 'message', 'errors']

        lines.append("")
        lines.append("## 1. Transaction Integrity - All-or-Nothing Batch")
        lines.append(f"  Route: {upload_route}")
        lines.append(f"  Target table for created records: {local_table}")
        lines.append(f"  Upload tracking table: {upload_table}")
        lines.append(f"  Error response keys: {er_keys}")
        lines.append("  Tests:")
        lines.append("  - Upload CSV with N valid rows and 1 invalid row -> assert 422, assert 0 records in target table (rollback)")
        lines.append("  - Upload CSV with all valid rows -> assert 200, assert N records created")
        lines.append("  - Assert error response matches JSON error_response.structure")
        lines.append("")
        lines.append("## 2. N+1 Query Prevention")
        lines.append("  - For EVERY list endpoint, enable query log, fetch list, assert query count <= threshold")
        lines.append("  - Threshold: number of eager-loaded relations + base query (typically <= 4)")
        lines.append("")
        lines.append("## 3. Pagination Required")
        lines.append("  - For EVERY list endpoint, assert response has: data, links (first/last/prev/next), meta (current_page/total/per_page)")
        lines.append("")
        lines.append("## 4. Multi-Tenant Isolation")
        lines.append("  - For EVERY endpoint: create records with client_id=1 and client_id=999")
        lines.append("  - Assert user with client_id=1 cannot access client_id=999 records (403 or 404)")
        lines.append("  - Assert list endpoints only return own-client records")
        lines.append("")
        lines.append("## 5. Async Processing - Background Sync")

        sync_flow = pe.get('storing_pocket_expenses_from_file_upload', {}).get('flow', [])
        if sync_flow:
            lines.append("  Background sync flow from JSON:")
            for step in sync_flow:
                lines.append(f"    - Test: {step}")
        lines.append("  - Assert Queue::fake() + Queue::assertPushed() for background jobs")
        lines.append("")
        lines.append("## 6. API Resources (Not Raw Models)")
        lines.append("  - For EVERY endpoint: assert response uses API Resource structure (data wrapper)")
        lines.append("  - Assert sensitive fields excluded (password, remember_token, etc.)")
        lines.append("")
        lines.append("## 7. Status Codes")
        lines.append("  - 200 OK: Successful GET and successful batch upload")
        lines.append("  - 201 Created: Successful POST (single record creation)")
        lines.append("  - 204 No Content: Successful DELETE")
        lines.append("  - 401 Unauthorized: No authentication token")
        lines.append("  - 403 Forbidden: Permission check fails")
        lines.append("  - 404 Not Found: Record does not exist or belongs to different client")
        lines.append("  - 422 Unprocessable: Validation failure (form fields or CSV content)")
