#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_architect.py
@Desc    : Laravel Architect role for Volopa OOP Expense system
"""

import json
from pathlib import Path
from metagpt.roles.architect import Architect


class LaravelArchitect(Architect):
    """
    Laravel Architect specialized for OOP Expense system design.

    Responsibilities:
    - Design Laravel API architecture (routes, controllers, services, models)
    - Define data models and database schema (migrations, relationships)
    - Design service layer and business logic separation
    - Create API endpoint specifications
    - Design validation and authorization architecture

    Domain Modules:
    - User Management: user_feature_permission, role hierarchy, permission delegation
    - Pocket Expense CSV Upload: file upload pipeline, validation service, error handling
    - Single Expense Capturing: pocket_expense + metadata, FX conversion, expense sources
    """

    use_fixed_sop: bool = True
    name: str = "Danny"
    profile: str = "Laravel System Architect"
    goal: str = "Design Laravel API system architecture for OOP Expense system following best practices and DOS/DONTS patterns"

    constraints: str = """
    MENTAL MODEL (Critical for All Design Decisions):
    Client → route (Oauth2UserClient middleware) → controller → FormRequest
    (validation + policy) → service/model (domain logic, transactions) →
    API Resource (shape output) → JSON with correct status codes and error format

    ARCHITECTURE DESIGN DOS - Always Design These Patterns:

    1. Controller-Service Separation:
       - Design thin controllers (routing, validation, authorization)
       - Design service layer for all business logic
       - Controllers delegate to services, services contain domain logic

    2. Data Access & Performance:
       - Design DB::transaction() boundaries for multi-write operations
       - Design eager loading strategy to prevent N+1 queries (e.g., ->with(['relation']))
       - Design pagination for ALL list endpoints (never unbounded lists)
       - Design proper database indexes for query performance
       - Design foreign key constraints for data integrity

    3. Response Architecture:
       - Design API Resources for ALL responses (NEVER return raw Eloquent models)
       - API Resources hide internal fields, shape consistent JSON
       - Design proper HTTP status codes (201 Created, 200 OK, 204 No Content, 422 Validation, 403 Forbidden, 404 Not Found)

    4. Async Processing:
       - Design async processing (queue jobs) for large file operations
       - Design immediate response + background sync pattern (batch of 100)

    5. Validation & Authorization:
       - Design FormRequests for ALL validation (not in controllers)
       - Design Policies for ALL authorization checks
       - FormRequests check "can this be done", Policies check "can this user do it"

    6. Caching Strategy:
       - Design caching for reference data (currencies, expense types, countries, sources)
       - Design cache invalidation strategy

    7. API Versioning & Security:
       - Design routes under /api with Oauth2UserClient middleware
       - Design client data isolation (all queries filtered by client_id)

    ARCHITECTURE DESIGN DONTS - Never Design These Anti-Patterns:

    1. Controller Anti-Patterns:
       - Don't design controllers with business logic (use services)
       - Don't design controllers that return raw Eloquent models (use API Resources)
       - Don't design session/redirect patterns in APIs (use JSON responses)

    2. Query Anti-Patterns:
       - Don't design endpoints without pagination strategy
       - Don't design list endpoints without eager loading strategy (causes N+1)
       - Don't design queries without considering index usage

    3. Data Integrity Anti-Patterns:
       - Don't design multi-write operations without transaction boundaries
       - Don't design CSV processing without all-or-nothing validation
       - Don't design reference data lookups without caching (causes performance issues)

    4. Response Anti-Patterns:
       - Don't design responses that expose internal model structure
       - Don't design inconsistent JSON shapes across endpoints
       - Don't design error responses that expose sensitive details or stack traces

    5. Security Anti-Patterns:
       - Don't design endpoints without authentication
       - Don't design data access without client_id filtering (tenant isolation)
       - Don't design authorization without Policies or permission checks

    LARAVEL FILE STRUCTURE (Design Specifications):
    - routes/api.php: All API routes under /api/v1 prefix
    - app/Http/Controllers/Api/: Thin controllers (routing only)
    - app/Http/Requests/: FormRequests (validation + authorization)
    - app/Services/: Business logic services (domain operations)
    - app/Models/: Eloquent models (data access + relationships)
    - database/migrations/: Schema with indexes, foreign keys, constraints
    - app/Http/Resources/: API Resources (response transformers)
    - app/Policies/: Authorization policies (permission checks)
    - app/Jobs/: Async jobs (queue processing)
    - app/Notifications/: User notifications

    DESIGN DOCUMENTATION FORMAT:
    - Keep design documentation concise
    - Focus on implementation details over diagrams
    - Specify exact file names and class names
    - Include transaction boundaries and eager loading specifications
    - Reference DOS/DONTS constraints in design decisions
    """

    def __init__(self, **kwargs):
        """
        Initialize Laravel Architect.

        Inherits from Architect which provides:
        - WriteDesign action
        - Tool access: RoleZero, Editor, Terminal
        - Watches: WritePRD messages from ProductManager
        """
        super().__init__(**kwargs)

        # Load architectural requirements from JSON
        self.requirements = self._load_requirements()

        # Update constraints with loaded architectural patterns
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

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

    def _update_constraints_from_requirements(self):
        """Inject loaded architectural patterns into role constraints"""

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        # Build architectural constraint text
        er_text = self._format_er_diagram(um.get('er_diagram', {}))
        db_schema_um = self._format_db_tables(um.get('database_schema', {}).get('tables', []))
        db_schema_sdc = self._format_db_tables(sdc.get('database_schema', {}).get('tables', []))
        api_contract_text = self._format_api_contract(pe.get('api_contract', {}))
        data_model_text = self._format_data_model(pe.get('data_model', {}))
        file_processing_text = self._format_file_processing(pe.get('file_processing', {}))
        local_storage_text = self._format_local_storage(pe.get('storing_pocket_expenses_from_file_upload', {}))

        self.constraints += f"""

LOADED ARCHITECTURAL REQUIREMENTS FROM JSON:

=== ER DIAGRAM (User Management) ===
{er_text}

=== DATABASE SCHEMA: User Management ===
{db_schema_um}

=== DATABASE SCHEMA: Expense Single Data Capturing ===
{db_schema_sdc}

=== API CONTRACT: Pocket Expense CSV Upload ===
{api_contract_text}

=== DATA MODEL: File Upload Tracking ===
{data_model_text}

=== FILE PROCESSING PIPELINE ===
{file_processing_text}

=== LOCAL STORAGE & BACKGROUND SYNC ===
{local_storage_text}
"""

    def _format_er_diagram(self, er: dict) -> str:
        lines = []
        entities = er.get('entities', [])
        entity_names = [e['name'] if isinstance(e, dict) else e for e in entities]
        lines.append(f"Entities: {', '.join(entity_names)}")
        lines.append("Relationships:")
        for rel in er.get('relationships', []):
            lines.append(f"  {rel['from']} --[{rel['type']}]--> {rel['to']} : {rel['label']}")
        return '\n'.join(lines)

    def _format_db_tables(self, tables: list) -> str:
        lines = []
        for table in tables:
            name = table.get('name', 'unknown')
            cols = table.get('columns', [])
            fks = table.get('foreign_keys', [])
            lines.append(f"\nTable: {name} ({len(cols)} columns, {len(fks)} FKs)")
            for col in cols:
                constraints = ', '.join(col.get('constraints', []))
                default = f" DEFAULT {col.get('default')}" if 'default' in col else ''
                lines.append(f"  - {col['name']} {col['type']}{default} [{constraints}]")
            if fks:
                lines.append("  Foreign Keys:")
                for fk in fks:
                    lines.append(f"    {fk.get('column', fk.get('name', '?'))} -> {fk.get('references', '?')}")
        return '\n'.join(lines)

    def _format_api_contract(self, api: dict) -> str:
        lines = []
        route = api.get('route', {})
        lines.append(f"Route: {route.get('method', 'POST')} {route.get('path', '/api/uploads/pocket-expense/csv')}")
        lines.append(f"Middleware: {route.get('middleware', 'Oauth2UserClient')}")
        lines.append(f"Controller: {route.get('controller', 'PocketExpenseUploadController')}")
        lines.append(f"Content-Type: {api.get('content_type', 'multipart/form-data')}")

        lines.append("\nForm Fields:")
        for field in api.get('form_fields', []):
            req = "required" if field.get('required') else "optional"
            lines.append(f"  - {field['name']} ({req}): {field.get('description', '')}")

        validation = api.get('server_side_validation', {})
        if validation:
            lines.append("\nServer-side Validation:")
            for field, rule in validation.items():
                lines.append(f"  - {field}: {rule}")

        checks = api.get('additional_checks', [])
        if checks:
            lines.append("\nAdditional Checks:")
            for check in checks:
                lines.append(f"  - {check}")

        return '\n'.join(lines)

    def _format_data_model(self, dm: dict) -> str:
        lines = []
        new_table = dm.get('new_table', {})
        if new_table:
            name = new_table.get('name', 'unknown')
            cols = new_table.get('columns', [])
            lines.append(f"Table: {name} ({len(cols)} columns)")
            lines.append(f"Model: {new_table.get('model', 'N/A')}")
            lines.append(f"Engine: {new_table.get('engine', 'InnoDB')}")
            for col in cols:
                lines.append(f"  - {col['name']} {col['type']} default={col.get('default', 'N/A')}")
        return '\n'.join(lines)

    def _format_file_processing(self, fp: dict) -> str:
        lines = []
        for step in fp.get('steps', []):
            lines.append(f"\nStep {step.get('step', '?')}: {step.get('name', '')}")
            details = step.get('details', [])
            if isinstance(details, str):
                lines.append(f"  - {details}")
            elif isinstance(details, list):
                for detail in details:
                    if isinstance(detail, str):
                        lines.append(f"  - {detail}")
            if 'on_error' in step:
                lines.append(f"  On Error: {step['on_error'].get('actions', [])}")
            if 'on_success' in step:
                lines.append(f"  On Success: {step['on_success'].get('actions', [])}")
        return '\n'.join(lines)

    def _format_local_storage(self, ls: dict) -> str:
        lines = []
        flow = ls.get('flow', [])
        if flow:
            lines.append("Processing Flow:")
            for step in flow:
                lines.append(f"  {step}")

        schema = ls.get('local_storage_schema', {})
        if schema:
            lines.append(f"\nLocal Table: {schema.get('table_name', 'N/A')}")
            for col in schema.get('columns', []):
                lines.append(f"  - {col['name']} {col['type']}")
        return '\n'.join(lines)
