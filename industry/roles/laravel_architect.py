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
from industry.utils.context_reader import ContextReader


class LaravelArchitect(Architect):
    """
    Laravel Architect specialized for system design.

    Responsibilities:
    - Design Laravel API architecture (routes, controllers, services, models)
    - Define data models and database schema (migrations, relationships)
    - Design service layer and business logic separation
    - Create API endpoint specifications
    - Design validation and authorization architecture

    Domain modules are loaded from JSON specifications at runtime.
    """

    use_fixed_sop: bool = True
    name: str = "Danny"
    profile: str = "Laravel System Architect"
    goal: str = "Design Laravel API system architecture following best practices and DOS/DONTS patterns from constraints and loaded JSON input"

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

        # YAML context reader for reconciled domain data
        self.context_reader = ContextReader()

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
        """Inject loaded architectural patterns into role constraints — derived entirely from JSON."""

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        lines = []
        lines.append("")
        lines.append("LOADED ARCHITECTURAL REQUIREMENTS FROM JSON (authoritative source for all entity names and schemas):")

        # Table disambiguation
        lines.append("")
        lines.append("TABLE DISAMBIGUATION:")
        lines.append("  The JSON defines SEPARATE tables across modules. Tables with similar columns")
        lines.append("  (e.g. expense-related tables in different modules) are DISTINCT entities with")
        lines.append("  different column types, status enums, and relationships. Each MUST have its own")
        lines.append("  Model and Migration. Do NOT merge or conflate tables from different modules.")
        lines.append("  Derive model names from table names using Laravel convention (snake_case table -> PascalCase model).")

        # === MODULE 1: User Management ===
        lines.append("")
        lines.append("=== MODULE 1: User Management System ===")

        # Hierarchy
        self._append_hierarchy(lines, um.get('hierarchy', {}))

        # ER diagram
        self._append_er_diagram(lines, um.get('er_diagram', {}))

        # Permission metrics
        self._append_permission_metrics(lines, um.get('permission_metrics', []))

        # Access control flow
        self._append_access_control(lines, um.get('access_control_flow', {}))

        # Database schema (UM tables)
        self._append_tables(lines, um.get('database_schema', {}))

        # === MODULE 2: Pocket Expense CSV Upload ===
        lines.append("")
        lines.append("=== MODULE 2: Pocket Expense - CSV Batch Upload ===")

        # Overview + validation behavior
        self._append_validation_behavior(lines, pe.get('overview', {}).get('validation_behavior', {}))

        # API contract
        self._append_api_contract(lines, pe.get('api_contract', {}))

        # CSV file constraints
        self._append_file_constraints(lines, pe.get('csv_file_definition', {}).get('file_constraints', {}))

        # CSV columns
        self._append_csv_columns(lines, pe.get('csv_file_definition', {}).get('columns', []))

        # Data model: upload tracking table (now with column comments)
        self._append_data_model(lines, pe.get('data_model', {}))

        # File processing pipeline
        self._append_file_processing(lines, pe.get('file_processing', {}))

        # Validation service
        self._append_validation_service(lines, pe.get('validation_service', {}))

        # Local storage & background sync
        self._append_local_storage(lines, pe.get('storing_pocket_expenses_from_file_upload', {}))

        # Error response
        self._append_error_response(lines, pe.get('error_response', {}))

        # Success response
        self._append_success_response(lines, pe.get('success_response', {}))

        # Security & permissions
        self._append_security(lines, pe.get('security_and_permissions', {}))

        # === MODULE 3: Single Expense Data Capturing ===
        lines.append("")
        lines.append("=== MODULE 3: Single Expense Data Capturing ===")

        # Database schema (SDC tables)
        self._append_tables(lines, sdc.get('database_schema', {}))

        # Expense source config
        self._append_expense_source(lines, sdc.get('oop_expense_source', {}))

        # Currency conversion
        self._append_currency_conversion(lines, sdc.get('currency_conversion', {}))

        # FX conversion flow (ALL steps, no truncation)
        self._append_fx_flow(lines, sdc.get('fx_conversion_flow', {}))

        # User ID mapping (derive upload table name from JSON)
        upload_table_name = pe.get('data_model', {}).get('new_table', {}).get('name', 'upload_tracking_table')
        lines.append("")
        lines.append(f"CRITICAL USER ID MAPPING for {upload_table_name}:")
        lines.append("  DB user_id = the TARGET user whose expenses are being created (maps to API form field expense_user_id)")
        lines.append("  DB created_by_user_id = the ADMIN who performed the upload (maps to API form field user_id / auth token)")

        # === YAML Context (reconciled, authoritative domain data) ===
        lines.append("")
        lines.append("=" * 60)
        lines.append("RECONCILED CONTEXT FROM YAML (authoritative — supersedes JSON where conflicts exist):")
        lines.append("=" * 60)
        lines.append("")
        lines.append(self.context_reader.get_platform_constraints())
        lines.append("")
        lines.append(self.context_reader.get_do_not_build())
        lines.append("")
        lines.append(self.context_reader.get_database_tables("full"))
        lines.append("")
        lines.append(self.context_reader.get_interfaces_summary())
        lines.append("")
        lines.append(self.context_reader.get_flows())
        lines.append("")
        lines.append(self.context_reader.get_unresolved_decisions())
        lines.append("")
        lines.append(self.context_reader.get_project_intent())
        lines.append("")
        lines.append(self.context_reader.get_project_constraints())
        lines.append("")
        lines.append(self.context_reader.get_csv_column_schema())
        lines.append("")
        lines.append(self.context_reader.get_api_routes())
        lines.append("")
        lines.append(self.context_reader.get_response_schemas())
        lines.append("")
        lines.append(self.context_reader.get_permission_matrix())
        lines.append("")
        lines.append(self.context_reader.get_fx_infrastructure())
        lines.append("")
        lines.append(self.context_reader.get_design_principles())
        lines.append("")
        lines.append(self.context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(self.context_reader.get_platform_decisions())
        lines.append("")
        lines.append(self.context_reader.get_platform_flow_touchpoints())
        lines.append("")
        lines.append(self.context_reader.get_existing_tables_and_models())
        lines.append("")
        lines.append(self.context_reader.get_existing_user_roles())
        lines.append("")
        lines.append(self.context_reader.get_existing_platform_services())
        lines.append("")
        lines.append(self.context_reader.get_fx_query_contract())

        self.constraints += '\n'.join(lines)

    # ── Helper methods: each extracts one JSON section faithfully ──

    def _append_hierarchy(self, lines: list, hierarchy: dict):
        desc = hierarchy.get('description', '')
        if desc:
            lines.append(f"\nRole Hierarchy: {desc}")
        tree = hierarchy.get('tree', {})
        if tree:
            lines.append(f"  Root role: {tree.get('role', 'N/A')}")
            for cap in tree.get('capabilities', []):
                lines.append(f"    - {cap}")
            delegated = tree.get('delegated', {})
            if delegated:
                lines.append(f"  Delegated role: {delegated.get('role', 'N/A')}")
                for cap in delegated.get('capabilities', []):
                    lines.append(f"    - {cap}")

    def _append_er_diagram(self, lines: list, er: dict):
        entities = er.get('entities', [])
        if not entities:
            return
        lines.append("\nER Diagram:")
        lines.append("  Entities:")
        for e in entities:
            if isinstance(e, dict):
                lines.append(f"    - {e['name']} (origin: {e.get('origin', 'new')})")
            else:
                lines.append(f"    - {e}")
        rels = er.get('relationships', [])
        if rels:
            lines.append("  Relationships:")
            for rel in rels:
                origin = rel.get('origin', 'new')
                lines.append(f"    {rel['from']} --[{rel['type']}]--> {rel['to']} : {rel['label']} (origin: {origin})")

    def _append_permission_metrics(self, lines: list, metrics: list):
        if not metrics:
            return
        lines.append("\nRoles & Permissions:")
        for m in metrics:
            role = m.get('role', 'Unknown')
            origin = m.get('origin', 'new')
            perms = m.get('default_permissions', {})
            lines.append(f"  {role} (origin: {origin}):")
            for perm_key, perm_val in perms.items():
                if perm_key == 'origin':
                    continue
                lines.append(f"    {perm_key}: {perm_val}")
            if 'with_management_rights' in m:
                mgmt = m['with_management_rights']
                lines.append(f"    with_management_rights:")
                if isinstance(mgmt, dict):
                    for mk, mv in mgmt.items():
                        if mk == 'origin':
                            continue
                        lines.append(f"      {mk}: {mv}")
                else:
                    lines.append(f"      {mgmt}")

    def _append_access_control(self, lines: list, acf: dict):
        if not acf:
            return
        lines.append("\nAccess Control Flow:")
        for section_key, section_val in acf.items():
            if section_key == 'origin':
                continue
            if isinstance(section_val, dict):
                lines.append(f"  {section_key}:")
                if 'description' in section_val:
                    lines.append(f"    {section_val['description']}")
                for sub_key, sub_val in section_val.items():
                    if sub_key in ('description', 'origin'):
                        continue
                    if isinstance(sub_val, list):
                        lines.append(f"    {sub_key}:")
                        for item in sub_val:
                            lines.append(f"      - {item}")
                    elif isinstance(sub_val, dict):
                        lines.append(f"    {sub_key}:")
                        for k, v in sub_val.items():
                            if k == 'origin':
                                continue
                            lines.append(f"      {k}: {v}")
                    elif sub_val:
                        lines.append(f"    {sub_key}: {sub_val}")
            elif isinstance(section_val, list):
                lines.append(f"  {section_key}:")
                for item in section_val:
                    lines.append(f"    - {item}")

    def _append_tables(self, lines: list, db_schema: dict):
        tables = db_schema.get('tables', [])
        if not tables:
            return
        lines.append("\nDatabase Tables:")
        for table in tables:
            name = table.get('name', 'unknown')
            origin = table.get('origin', 'new')
            desc = table.get('description', '')
            cols = table.get('columns', [])
            fks = table.get('foreign_keys', [])
            lines.append(f"\n  Table: {name} (origin: {origin}) — {desc}")
            lines.append(f"  Columns ({len(cols)}):")
            for col in cols:
                ctype = col.get('type', '?')
                constraints = ', '.join(col.get('constraints', []))
                default = f" DEFAULT {col.get('default')}" if 'default' in col else ''
                comment = f" — {col['comment']}" if 'comment' in col else ''
                lines.append(f"    - {col['name']} {ctype}{default} [{constraints}]{comment}")
            if fks:
                lines.append(f"  Foreign Keys:")
                for fk in fks:
                    ref_origin = fk.get('referenced_table_origin', 'unknown')
                    lines.append(f"    {fk.get('column', fk.get('name', '?'))} -> {fk.get('references', '?')} (ref origin: {ref_origin})")
            if table.get('unique_keys'):
                lines.append(f"  Unique Keys:")
                for uk in table['unique_keys']:
                    lines.append(f"    {uk.get('name', '?')}: {uk.get('columns', [])}")
            if table.get('seed_data'):
                lines.append(f"  Seed Data:")
                for seed in table['seed_data']:
                    lines.append(f"    - {seed}")
        # Notes (JSON value may be a string or list)
        notes = db_schema.get('notes', [])
        if notes:
            lines.append("  Schema Notes:")
            if isinstance(notes, str):
                lines.append(f"    - {notes}")
            elif isinstance(notes, list):
                for note in notes:
                    lines.append(f"    - {note}")
        # Metadata JSON examples
        examples = db_schema.get('metadata_json_examples', {})
        if isinstance(examples, dict) and examples:
            lines.append("  Metadata JSON Examples:")
            for meta_type, details in examples.items():
                if meta_type == "origin":
                    continue
                lines.append(
                    f"    - type: {meta_type}, details_json: {details}"
                )

    def _append_validation_behavior(self, lines: list, vb: dict):
        if not vb:
            return
        lines.append(f"\nValidation Behavior:")
        lines.append(f"  Description: {vb.get('description', '')}")
        lines.append(f"  On Failure: {vb.get('on_failure', '')}")
        lines.append(f"  On Success: {vb.get('on_success', '')}")

    def _append_api_contract(self, lines: list, api: dict):
        if not api:
            return
        route = api.get('route', {})
        lines.append(f"\nAPI Contract:")
        lines.append(f"  Route: {route.get('method', 'POST')} {route.get('path', '?')}")
        lines.append(f"  Middleware: {route.get('middleware', '?')}")
        lines.append(f"  Controller: {route.get('controller', '?')}")
        lines.append(f"  Content-Type: {api.get('content_type', '?')}")

        fields = api.get('form_fields', [])
        if fields:
            lines.append(f"  Form Fields:")
            for field in fields:
                req = "required" if field.get('required') else "optional"
                lines.append(f"    - {field['name']} ({req}): {field.get('description', '')}")

        validation = api.get('server_side_validation', {})
        if validation:
            lines.append(f"  Server-side Validation:")
            for field, rule in validation.items():
                if field.endswith('_origin'):
                    continue
                lines.append(f"    - {field}: {rule}")

        checks = api.get('additional_checks', [])
        if checks:
            lines.append(f"  Additional Checks:")
            for check in checks:
                lines.append(f"    - {check}")

    def _append_file_constraints(self, lines: list, fc: dict):
        if not fc:
            return
        lines.append(f"\nCSV File Constraints:")
        for k, v in fc.items():
            if k == 'origin':
                continue
            lines.append(f"  - {k}: {v}")

    def _append_csv_columns(self, lines: list, columns: list):
        if not columns:
            return
        lines.append(f"\nCSV Columns ({len(columns)} total):")
        for col in columns:
            csv_col = col.get('csv_column', '')
            if csv_col:
                req = "Required" if col.get('required') else "Optional"
                target = col.get('target_db_field', 'N/A')
                target_table = col.get('target_table', '')
                notes = col.get('notes', '')
                line = f"  - {csv_col} ({req}) -> {target}"
                if target_table:
                    line += f" [{target_table}]"
                if notes:
                    line += f" — {notes}"
                lines.append(line)

    def _append_data_model(self, lines: list, dm: dict):
        new_table = dm.get('new_table', {})
        if not new_table:
            return
        name = new_table.get('name', 'unknown')
        cols = new_table.get('columns', [])
        lines.append(f"\nUpload Tracking Table: {name} ({len(cols)} columns)")
        lines.append(f"  Model: {new_table.get('model', 'N/A')}")
        lines.append(f"  Engine: {new_table.get('engine', 'InnoDB')}")
        for col in cols:
            default = f" DEFAULT {col.get('default')}" if 'default' in col else ''
            comment = f" — {col['comment']}" if 'comment' in col else ''
            lines.append(f"    - {col['name']} {col['type']}{default}{comment}")

    def _append_file_processing(self, lines: list, fp: dict):
        steps = fp.get('steps', [])
        if not steps:
            return
        lines.append(f"\nFile Processing Pipeline:")
        for step in steps:
            lines.append(f"  Step {step.get('step', '?')}: {step.get('name', '')}")
            details = step.get('details', [])
            if isinstance(details, str):
                lines.append(f"    - {details}")
            elif isinstance(details, list):
                for detail in details:
                    if isinstance(detail, str):
                        lines.append(f"    - {detail}")
            if 'on_error' in step:
                err = step['on_error']
                lines.append(f"    On Error: {err.get('actions', [])}")
                if err.get('response'):
                    lines.append(f"    Error Response: {err['response']}")
            if 'on_success' in step:
                suc = step['on_success']
                lines.append(f"    On Success: {suc.get('actions', [])}")

    def _append_validation_service(self, lines: list, vs: dict):
        if not vs:
            return
        lines.append(f"\nCSV Validation Service: {vs.get('name', '')}")
        preloaded = vs.get('preloaded_reference_data', [])
        if preloaded:
            lines.append(f"  Preloaded Reference Data:")
            for item in preloaded:
                lines.append(f"    - {item}")
        caching = vs.get('caching', '')
        if caching:
            lines.append(f"  Caching: {caching}")
        rules = vs.get('key_rules_per_row', {})
        if rules:
            lines.append(f"  Key Rules Per Row:")
            for field, rule in rules.items():
                req = "Required" if rule.get('required') else "Optional"
                lines.append(f"    {field} ({req}):")
                for k, v in rule.items():
                    if k != 'required':
                        lines.append(f"      {k}: {v}")
        rfb = vs.get('row_failure_behavior', '')
        if rfb:
            lines.append(f"  Row Failure Behavior:")
            if isinstance(rfb, dict):
                for k, v in rfb.items():
                    if k == 'origin':
                        continue
                    lines.append(f"    {k}: {v}")
            else:
                lines.append(f"    {rfb}")

    def _append_local_storage(self, lines: list, ls: dict):
        if not ls:
            return
        flow = ls.get('flow', [])
        if flow:
            lines.append(f"\nLocal Storage & Background Sync Flow:")
            for step in flow:
                lines.append(f"  - {step}")
        schema = ls.get('local_storage_schema', {})
        if schema:
            lines.append(f"  Local Table: {schema.get('table_name', 'N/A')}")
            for col in schema.get('columns', []):
                lines.append(f"    - {col['name']} {col['type']}")
        bulk = ls.get('bulk_insert_pattern', {})
        if bulk:
            lines.append(f"  Bulk Insert Pattern:")
            for k, v in bulk.items():
                if k == 'origin':
                    continue
                lines.append(f"    {k}: {v}")

    def _append_error_response(self, lines: list, er: dict):
        if not er:
            return
        lines.append(f"\nError Response Spec:")
        lines.append(f"  HTTP Status: {er.get('http_status', '?')}")
        structure = er.get('structure', {})
        if structure:
            lines.append(f"  Structure: {json.dumps(structure, indent=4)}")
        notes = er.get('notes', [])
        if notes:
            for note in notes:
                lines.append(f"  Note: {note}")

    def _append_success_response(self, lines: list, sr: dict):
        if not sr:
            return
        lines.append(f"\nSuccess Response Spec:")
        structure = sr.get('structure', {})
        if structure:
            lines.append(f"  Structure: {json.dumps(structure, indent=4)}")

    def _append_security(self, lines: list, sec: dict):
        if not sec:
            return
        lines.append(f"\nSecurity & Permissions:")
        ep = sec.get('endpoint_protection', '')
        if ep:
            lines.append(f"  Endpoint Protection: {ep}")
        checks = sec.get('server_side_checks', [])
        if checks:
            lines.append(f"  Server-side Checks:")
            for check in checks:
                lines.append(f"    - {check}")

    def _append_expense_source(self, lines: list, src: dict):
        if not src:
            return
        lines.append(f"\nExpense Source Configuration:")
        setup = src.get('default_and_global_source_setup', {})
        on_enable = setup.get('on_client_oop_feature_enable', {})
        defaults = on_enable.get('auto_create_defaults', [])
        if defaults:
            lines.append(f"  Default sources on feature enable: {defaults}")
        global_other = on_enable.get('global_other', {})
        if global_other:
            lines.append(f"  Global Other: {global_other}")
        dropdown = src.get('dropdown_display', {})
        if dropdown:
            lines.append(f"  Dropdown Display:")
            for k, v in dropdown.items():
                if k == 'origin':
                    continue
                lines.append(f"    {k}: {v}")
        submission = src.get('expense_submission', {})
        if submission:
            lines.append(f"  Expense Submission Rules:")
            for k, v in submission.items():
                if k == 'origin':
                    continue
                lines.append(f"    {k}: {v}")
        config = src.get('client_config_behaviour', {})
        if config:
            lines.append(f"  Client Config Behaviour:")
            for k, v in config.items():
                if k == 'origin':
                    continue
                lines.append(f"    {k}: {v}")

    def _append_currency_conversion(self, lines: list, cc: dict):
        if not cc:
            return
        lines.append(f"\nCurrency Conversion:")
        lines.append(f"  Description: {cc.get('description', '')}")
        lines.append(f"  Query: {cc.get('query_description', '')}")
        refs = cc.get('referenced_tables', [])
        if refs:
            lines.append(f"  Referenced Tables (origin: existing):")
            for ref in refs:
                lines.append(f"    - {ref}")
        fields = cc.get('returned_fields', [])
        if fields:
            lines.append(f"  Returned Fields:")
            for f in fields:
                lines.append(f"    - {f}")
        filters = cc.get('filter_conditions', [])
        if filters:
            lines.append(f"  Filter Conditions:")
            for fc in filters:
                lines.append(f"    - {fc}")

    def _append_fx_flow(self, lines: list, fx: dict):
        if not fx:
            return
        lines.append(f"\nFX Conversion Flow:")
        for step_key, step_val in fx.items():
            if not isinstance(step_val, dict):
                continue
            name = step_val.get('name', step_key)
            lines.append(f"  {name}:")
            for item in step_val.get('flow', []):
                lines.append(f"    - {item}")
