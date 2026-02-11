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
- Development Plan: List ONLY filenames to be created (e.g., "app/Models/{ModelName}.php")
- Incremental Change: For EACH file, provide ONLY this simple format:
  "app/Models/{ModelName}.php: Create Eloquent model with relationships"

DO NOT generate actual code, diff blocks, or full file contents in the Incremental Change section.
Keep each Incremental Change entry to ONE line with filename and brief description only.

Example correct format:
{
  "Development Plan": [
    "app/Models/{ModelName}.php",
    "app/Services/{ServiceName}.php"
  ],
  "Incremental Change": [
    "app/Models/{ModelName}.php: Eloquent model with UUID, relationships to metadata/user/client, soft deletes",
    "app/Services/{ServiceName}.php: CSV validation with preloaded reference data, all-or-nothing"
  ]
}

MENTAL MODEL:
Client → route (Oauth2UserClient middleware) → controller → FormRequest
(validation + policy) → service/model (domain logic, transactions) →
API Resource (shape output) → JSON with correct status codes and error format

DOS - Always Follow These Practices:
- Add routes to routes/api.php with Oauth2UserClient middleware
- Keep route names consistent (derive from API contract route paths in JSON)
- Write migrations with proper indexes, unique constraints, and foreign keys
- Add Eloquent model relationships (hasMany, belongsTo, etc.)
- Validate all request content in FormRequests (not controllers)
- Use Policies or Gates for authorization checks via the permission table defined in JSON
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
        """Inject CONCISE requirements into constraints — only what's NOT already in the system design doc.

        The Engineer receives PRD + system design + task breakdown as context from the pipeline.
        Those documents already contain full table schemas, API contracts, CSV column definitions,
        response structures, etc. Repeating all that here causes the prompt to exceed the 200K
        token limit. Instead, we inject only:
        - Table disambiguation warnings (prevent merging similar tables across modules)
        - Table name/origin quick reference (so the LLM knows what's new vs existing)
        - Critical behavioral rules that might be buried in the docs
        - User ID mapping warning (common source of bugs)
        """

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        lines = []

        lines.append("")
        lines.append("IMPLEMENTATION GUARDRAILS (the full specs are in the System Design document above):")

        # ── Table disambiguation ──
        lines.append("")
        lines.append("TABLE DISAMBIGUATION:")
        lines.append("  The JSON defines SEPARATE tables across modules. Tables with similar columns")
        lines.append("  (e.g. expense-related tables in different modules) are DISTINCT entities with")
        lines.append("  different column types, status enums, and relationships. Each MUST have its own")
        lines.append("  Model and Migration. Do NOT merge or conflate tables from different modules.")
        lines.append("  Derive model names from table names using Laravel convention (snake_case table -> PascalCase model).")

        # ── Quick reference: all tables with origin markers ──
        lines.append("")
        lines.append("TABLE QUICK REFERENCE (origin: new = build, existing = already in platform):")
        self._append_table_names(lines, "User Management", um.get('database_schema', {}).get('tables', []))
        self._append_table_names(lines, "Pocket Expense CSV Upload", [pe.get('data_model', {}).get('new_table', {})])
        ls_schema = pe.get('storing_pocket_expenses_from_file_upload', {}).get('local_storage_schema', {})
        if ls_schema:
            lines.append(f"    {ls_schema.get('table_name', '?')} (origin: new)")
        self._append_table_names(lines, "Single Expense Data Capturing", sdc.get('database_schema', {}).get('tables', []))

        # ── Critical behavioral rules ──
        lines.append("")
        lines.append("CRITICAL BEHAVIORAL RULES:")
        vb = pe.get('overview', {}).get('validation_behavior', {})
        if vb:
            lines.append(f"  CSV Validation: {vb.get('description', '?')} | on_failure: {vb.get('on_failure', '?')}")
        vs = pe.get('validation_service', {})
        if vs:
            lines.append(f"  Validator Service: {vs.get('name', '?')}")
            rfb = vs.get('row_failure_behavior', '')
            if rfb:
                lines.append(f"  Row Failure Behavior: {rfb}")
        cc = sdc.get('currency_conversion', {})
        if cc:
            lines.append(f"  Currency Conversion: origin={cc.get('origin', '?')} — use existing platform infrastructure, do NOT build external API calls")
            ref_tables = cc.get('referenced_tables', [])
            if ref_tables:
                existing = [f"{t['name']}" for t in ref_tables]
                lines.append(f"  FX Referenced Tables (ALL existing — do NOT recreate): {existing}")
        src_config = sdc.get('oop_expense_source', {}).get('client_config_behaviour', {})
        if src_config:
            lines.append(f"  Max active expense sources per client: {src_config.get('max_active_sources_per_client', '?')}")

        # ── User ID mapping note ──
        upload_table_name = pe.get('data_model', {}).get('new_table', {}).get('name', 'upload_tracking_table')
        lines.append("")
        lines.append(f"CRITICAL USER ID MAPPING for {upload_table_name}:")
        lines.append("  DB user_id = the TARGET user whose expenses are being created (maps to API form field expense_user_id)")
        lines.append("  DB created_by_user_id = the ADMIN who performed the upload (maps to API form field user_id / auth token)")
        lines.append("  Do NOT swap these. The API field names differ from DB column names.")

        self.constraints += '\n'.join(lines)

    # ── Helper methods ──

    def _append_table_names(self, lines: list, module: str, tables: list):
        """Output just table names and origins for quick reference."""
        if not tables:
            return
        lines.append(f"  {module}:")
        for table in tables:
            if not table:
                continue
            name = table.get('name', '?')
            origin = table.get('origin', '?')
            col_count = len(table.get('columns', []))
            fk_count = len(table.get('foreign_keys', []))
            lines.append(f"    {name} (origin: {origin}, {col_count} cols, {fk_count} FKs)")

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
