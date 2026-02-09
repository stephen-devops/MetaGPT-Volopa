#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_engineer.py
@Desc    : Laravel Engineer role for Volopa OOP Expense system
"""

import json
from pathlib import Path
from metagpt.roles.engineer import Engineer


class LaravelEngineer(Engineer):
    """
    Laravel Engineer specialized for implementing OOP Expense Laravel API code following DOS/DONTS.

    Responsibilities:
    - Write Laravel controllers (thin, proper status codes)
    - Write FormRequests (validation + policy authorization)
    - Write services (business logic with transactions and approval workflow)
    - Write Eloquent models (relationships, casts, fillable)
    - Write migrations (schema with indexes, foreign keys, etc.)
    - Write API Resources (response transformers)
    - Write feature tests (assert JSON, status codes, DB state)
    """

    use_fixed_sop: bool = True
    name: str = "Lucas"
    profile: str = "Laravel API Developer"
    goal: str = "Write Laravel code for OOP Expense system following DOS/DONTS patterns and Volopa conventions"

    constraints: str = """
CRITICAL OUTPUT FORMAT REQUIREMENT:
- Development Plan: List ONLY filenames to be created (e.g., "app/Models/PocketExpense.php")
- Incremental Change: For EACH file, provide ONLY this simple format:
  "app/Models/PocketExpense.php: Create Eloquent model with relationships"

DO NOT generate actual code, diff blocks, or full file contents in the Incremental Change section.
Keep each Incremental Change entry to ONE line with filename and brief description only.

Example correct format:
{
  "Development Plan": [
    "app/Models/MyModel.php",
    "app/Services/MyValidator.php"
  ],
  "Incremental Change": [
    "app/Models/MyModel.php: Eloquent model with UUID, relationships to metadata/user/client, soft deletes",
    "app/Services/MyValidator.php: CSV validation with preloaded reference data, all-or-nothing"
  ]
}

MENTAL MODEL:
Client → route (Oauth2UserClient middleware) → controller → FormRequest
(validation + policy) → service/model (domain logic, transactions) →
API Resource (shape output) → JSON with correct status codes and error format

DOS - Always Follow These Practices:
- Add routes to routes/api.php with Oauth2UserClient middleware
- Keep route names consistent (e.g., uploads.pocket-expense.csv)
- Write migrations with proper indexes, unique constraints, and foreign keys
- Add Eloquent model relationships (hasMany, belongsTo, etc.)
- Validate all request content in FormRequests (not controllers)
- Use Policies or Gates for authorization checks via user_feature_permission
- Keep controllers thin - push business logic into services or models
- Use DB::transaction() when touching multiple tables
- Return proper HTTP status codes:
  * 201: Resource created successfully
  * 200: Success with data
  * 204: Success with no content
  * 400: Bad request
  * 401: Unauthorized (not authenticated)
  * 403: Forbidden (authenticated but not authorized)
  * 404: Resource not found
  * 422: Validation failed (CSV errors, form validation)
- Create API Resources to shape responses and hide internal fields
- Add pagination using Resource::collection($query->paginate())
- Write feature tests that assert JSON shape, status codes, DB state, and policy enforcement
- Volopa uses Oauth2UserClient middleware for authentication

DON'TS - Never Do These:
- Don't use a class or method that doesn't exist in the current repository
- Don't add methods that already exist in the current repository
- Don't return raw Eloquent models from controllers
- Don't use session/redirect patterns in APIs
- Don't return 200 status code for errors
- Don't expose stack traces or sensitive error details in responses
- Don't disable mass-assignment protection ($guarded) or trust client-owned fields
- Don't build query filters directly from user input (SQL injection risk)
- Don't create N+1 queries (use eager loading: ->with(['relation']))
- Don't return unbounded lists (always paginate)
- Don't forget DB::transaction() for multi-write operations
- Don't hardcode timestamps or timezones (use Carbon, database defaults)
- Don't ignore caching opportunities (especially for reference data)
- Don't let file uploads bloat the API process (use queues for large files or background sync)
- Don't respond with inconsistent JSON shapes or casing (use Resources)
- Don't leak environment variables or config in responses
- Don't forget observability (logging, monitoring, error tracking)
"""

    def __init__(self, **kwargs):
        """
        Initialize Laravel Engineer.

        Inherits from Engineer which provides:
        - WriteCode action
        - WriteCodeReview action (optional)
        - WriteTest action
        - Watches: WriteTasks messages from ProjectManager
        """
        super().__init__(**kwargs)

        # Load requirements from all three JSON files
        self.requirements = self._load_requirements()

        # Update constraints with loaded patterns
        self._update_constraints_from_requirements()

        # Set incremental mode to False to skip WriteCodePlanAndChange phase
        self.config.inc = False

        # Engineer needs multiple loops to write all files
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=50)

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
        """Inject loaded requirements into role constraints"""

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        # Extract key implementation details
        db_tables_um = self._format_table_summary(um.get('database_schema', {}).get('tables', []))
        db_tables_sdc = self._format_table_summary(sdc.get('database_schema', {}).get('tables', []))
        csv_columns = self._format_csv_columns(pe.get('csv_file_definition', {}).get('columns', []))
        api_route = self._format_api_route(pe.get('api_contract', {}))
        error_response = self._format_error_response(pe.get('error_response', {}))
        success_response = self._format_success_response(pe.get('success_response', {}))
        validation_rules = self._format_validation_rules(pe.get('validation_service', {}))
        security = self._format_security(pe.get('security_and_permissions', {}))
        fx_flow = self._format_fx_flow(sdc.get('fx_conversion_flow', {}))
        expense_sources = self._format_expense_sources(sdc.get('oop_expense_source', {}))

        self.constraints += f"""

LOADED IMPLEMENTATION REQUIREMENTS FROM JSON:

=== DATABASE TABLES: User Management ===
{db_tables_um}

=== DATABASE TABLES: Expense Single Data Capturing ===
{db_tables_sdc}

=== CSV COLUMN MAPPING (Pocket Expense Upload) ===
{csv_columns}

=== API ROUTE ===
{api_route}

=== ERROR RESPONSE FORMAT (HTTP 422) ===
{error_response}

=== SUCCESS RESPONSE FORMAT ===
{success_response}

=== CSV VALIDATION RULES (PocketExpenseCSVValidator) ===
{validation_rules}

=== SECURITY & PERMISSIONS ===
{security}

=== FX CONVERSION FLOW ===
{fx_flow}

=== EXPENSE SOURCE CONFIG ===
{expense_sources}
"""

    def _format_table_summary(self, tables: list) -> str:
        lines = []
        for table in tables:
            name = table.get('name', 'unknown')
            cols = [c['name'] for c in table.get('columns', [])]
            fks = table.get('foreign_keys', [])
            lines.append(f"  {name}: columns=[{', '.join(cols)}] FKs={len(fks)}")
        return '\n'.join(lines)

    def _format_csv_columns(self, columns: list) -> str:
        lines = []
        for col in columns:
            csv_col = col.get('csv_column')
            if csv_col:
                db_field = col.get('target_db_field', 'N/A')
                required = "REQ" if col.get('required') else "OPT"
                rules = col.get('validation_rules', [])
                rules_str = '; '.join(rules) if rules else 'none'
                lines.append(f"  [{required}] {csv_col} -> {db_field} | rules: {rules_str}")
            else:
                db_field = col.get('target_db_field', 'N/A')
                source = col.get('source', col.get('default_value', 'system'))
                lines.append(f"  [SYS] {db_field} <- {source}")
        return '\n'.join(lines)

    def _format_api_route(self, api: dict) -> str:
        route = api.get('route', {})
        return f"  {route.get('method', 'POST')} {route.get('path', '/api/uploads/pocket-expense/csv')} | middleware: {route.get('middleware', 'Oauth2UserClient')} | content: {api.get('content_type', 'multipart/form-data')}"

    def _format_error_response(self, er: dict) -> str:
        structure = er.get('structure', {})
        return json.dumps(structure, indent=2) if structure else '  (see JSON file)'

    def _format_success_response(self, sr: dict) -> str:
        structure = sr.get('structure', {})
        return json.dumps(structure, indent=2) if structure else '  (see JSON file)'

    def _format_validation_rules(self, vs: dict) -> str:
        lines = []
        rules = vs.get('key_rules_per_row', {})
        for field, rule in rules.items():
            req = "REQ" if rule.get('required') else "OPT"
            details = {k: v for k, v in rule.items() if k != 'required'}
            lines.append(f"  [{req}] {field}: {details}")
        return '\n'.join(lines)

    def _format_security(self, sec: dict) -> str:
        lines = [f"  Middleware: {sec.get('endpoint_protection', 'Oauth2UserClient')}"]
        for check in sec.get('server_side_checks', []):
            lines.append(f"  - {check}")
        return '\n'.join(lines)

    def _format_fx_flow(self, fx: dict) -> str:
        lines = []
        for step_key, step_val in fx.items():
            if not isinstance(step_val, dict):
                continue
            name = step_val.get('name', step_key)
            flow_items = step_val.get('flow', [])
            lines.append(f"  {name}: {' → '.join(flow_items[:4])}{'...' if len(flow_items) > 4 else ''}")
        return '\n'.join(lines)

    def _format_expense_sources(self, src: dict) -> str:
        lines = []
        setup = src.get('default_and_global_source_setup', {})
        defaults = setup.get('on_client_oop_feature_enable', {}).get('auto_create_defaults', [])
        lines.append(f"  Defaults on enable: {defaults}")
        lines.append(f"  Global 'Other': client_id=NULL, not deletable")
        config = src.get('client_config_behaviour', {})
        lines.append(f"  Max active per client: {config.get('max_active_sources_per_client', 20)}")
        submission = src.get('expense_submission', {})
        lines.append(f"  Non-Other: save expense_source_id only")
        lines.append(f"  Other: save expense_source_id (global Other ID) + custom_source_text")
        return '\n'.join(lines)

    async def _think(self) -> bool:
        """Override _think to ensure correct src_path before code generation."""
        result = await super()._think()

        from pathlib import Path
        from metagpt.logs import logger

        if hasattr(self, 'repo') and self.repo:
            workdir = Path(self.repo.workdir)
            current_src = self.repo.src_relative_path

            if current_src and current_src.name == workdir.name:
                self.repo.with_src_path(Path("."))
                logger.info(f"LaravelEngineer: Corrected nested src_path from '{current_src}' to '.' (workspace root)")

                nested_dir = workdir / current_src
                if nested_dir.exists() and nested_dir.is_dir():
                    import shutil
                    try:
                        shutil.rmtree(nested_dir)
                        logger.info(f"LaravelEngineer: Removed empty nested directory '{nested_dir}'")
                    except Exception as e:
                        logger.warning(f"LaravelEngineer: Could not remove nested directory '{nested_dir}': {e}")

                src_workspace_file = workdir / ".src_workspace"
                src_workspace_file.write_text(".")
                logger.info(f"LaravelEngineer: Created/updated .src_workspace file")

        return result
