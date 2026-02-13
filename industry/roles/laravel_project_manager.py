#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_project_manager.py
@Desc    : Laravel Project Manager role for Volopa OOP Expense system
"""

import json
from pathlib import Path
from metagpt.roles.project_manager import ProjectManager
from industry.utils.context_reader import ContextReader


class LaravelProjectManager(ProjectManager):
    """
    Laravel Project Manager specialized for task breakdown and dependency analysis.

    Responsibilities:
    - Break down system design into dependency-ordered tasks
    - Analyze Laravel file dependencies (migrations -> models -> services -> controllers)
    - Generate task list with proper execution order
    - Document shared knowledge and Laravel conventions
    - Identify required Laravel packages (composer dependencies)

    Domain Modules:
    - User Management: permission tables, role hierarchy, access control
    - Pocket Expense CSV Upload: upload pipeline, validation service, background sync
    - Single Expense Capturing: CRUD, FX conversion, metadata, source config
    """

    use_fixed_sop: bool = True
    name: str = "Manuel"
    profile: str = "Laravel Project Manager"
    goal: str = """
    Break down OOP Expense system design into dependency-ordered tasks following
    Laravel conventions (migrations first, then models, then services, controllers, routes)
    """

    constraints: str = """
    IMPORTANT: Output ONLY filenames and dependencies. DO NOT generate code, diff blocks, or implementations.

    Laravel Task Breakdown Rules:

    1. Output Format - Simple JSON:
       {
         "Required packages": ["package1", "package2"],
         "Logic Analysis": [
           ["file1.php", "Description of file1 purpose and what it depends on"],
           ["file2.php", "Description of file2 purpose and what it depends on"]
         ],
         "Task list": ["file1.php", "file2.php", "file3.php"],
         "Shared Knowledge": "Brief notes about Laravel patterns to follow"
       }

    2. Execution Order Priority (for Task list):
       P0: Migrations (database schema) - no dependencies
       P1: Models (Eloquent) - depends on migrations
       P2: Policies (authorization) - depends on models
       P3: FormRequests (validation) - depends on policies
       P4: Config files (config/*.php) - no dependencies
       P5: Services (business logic) - depends on models
       P6: Queue Jobs - depends on services
       P7: Notifications - depends on models
       P8: Middleware - no dependencies
       P9: Resources (transformers) - depends on models
       P10: Controllers (thin layer) - depends on services + FormRequests + Resources
       P11: Routes (routes/api.php) - depends on controllers
       P12: Tests (feature tests) - depends on all application code

    3. Parallel Development Opportunities (note in Logic Analysis):
       - Multiple migrations (if no FK dependencies between them)
       - Multiple models (if no cross-relationships)
       - Multiple FormRequests
       - Multiple services (if no inter-service dependencies)
       - Multiple API Resources

    4. Critical Dependencies (document in Logic Analysis):
       - Controllers depend on: Services + FormRequests + Resources
       - Services depend on: Models
       - FormRequests depend on: Policies (for authorize method)
       - Policies depend on: Models
       - Tests depend on: All application code

    5. Required Composer Packages:
       - league/csv (for CSV parsing)
       - Any other Laravel packages needed

    DO NOT include code examples, diff blocks, or implementation details.
    ONLY list filenames, descriptions, and dependencies.
    Keep Logic Analysis descriptions to 1-2 sentences per file.
    """

    def __init__(self, **kwargs):
        """
        Initialize Laravel Project Manager.

        Inherits from ProjectManager which provides:
        - WriteTasks action
        - Tool access: RoleZero, Editor
        - Watches: WriteDesign messages from Architect
        """
        super().__init__(**kwargs)

        # YAML context reader for reconciled domain data
        self.context_reader = ContextReader()

        # Load requirements from all three JSON files
        self.requirements = self._load_requirements()

        # Update constraints with task breakdown data
        self._update_constraints_from_requirements()

        # Increase max output tokens
        if self.context and self.context.config and self.context.config.llm:
            self.context.config.llm.max_token = 8192

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
        """Inject task breakdown guidance from loaded requirements"""

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        # Build task mapping from all three modules
        task_mapping = self._build_task_mapping(um, pe, sdc)
        stats = self._compute_stats(um, pe, sdc)

        yaml_lines = []
        yaml_lines.append("")
        yaml_lines.append("=" * 60)
        yaml_lines.append("RECONCILED CONTEXT FROM YAML (authoritative — supersedes JSON where conflicts exist):")
        yaml_lines.append("=" * 60)
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_do_not_build())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_database_tables("summary"))
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_components_to_build())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_interfaces_summary())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_unresolved_decisions())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_inherited_behaviors())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_platform_decisions())
        yaml_lines.append("")
        yaml_lines.append(self.context_reader.get_existing_user_roles())
        yaml_context = '\n'.join(yaml_lines)

        self.constraints += f"""

LOADED REQUIREMENTS FROM JSON (authoritative source — derive all entity names from this data):

Task Breakdown Statistics:
{stats}

Module Specifications (derive Migration, Model, Service, Controller, Resource names from tables and contracts below):
{task_mapping}

Expected Output:
Task breakdown with dependency-ordered implementation tasks derived from the JSON tables,
API contracts, and behavioral rules above. Use Laravel naming conventions to convert
JSON table names to Model/Migration/Controller/Resource names. Do NOT invent entity names
that are not backed by a JSON table or API contract.
{yaml_context}
"""

    def _compute_stats(self, um: dict, pe: dict, sdc: dict) -> str:
        """Derive task statistics from JSON structure."""
        um_tables = um.get('database_schema', {}).get('tables', [])
        sdc_tables = sdc.get('database_schema', {}).get('tables', [])
        pe_upload_table = pe.get('data_model', {}).get('new_table', {})
        pe_local_table = pe.get('storing_pocket_expenses_from_file_upload', {}).get('local_storage_schema', {})

        csv_columns = pe.get('csv_file_definition', {}).get('columns', [])
        csv_in_file = [c for c in csv_columns if c.get('csv_column')]
        csv_system = [c for c in csv_columns if not c.get('csv_column')]
        roles = um.get('permission_metrics', [])

        all_new_tables = (
            [t['name'] for t in um_tables if t.get('origin') == 'new']
            + [t['name'] for t in sdc_tables if t.get('origin') == 'new']
            + ([pe_upload_table['name']] if pe_upload_table.get('name') else [])
            + ([pe_local_table['table_name']] if pe_local_table.get('table_name') else [])
        )

        lines = [
            f"- New DB tables (from JSON): {len(all_new_tables)}",
            f"  Tables: {all_new_tables}",
            f"- CSV columns: {len(csv_in_file)} in-file + {len(csv_system)} system-generated",
            f"- User roles defined: {len(roles)}",
            "",
            "DERIVATION RULES (do NOT hardcode entity names — derive from JSON tables above):",
            "  - Create ONE Migration per new table",
            "  - Create ONE Eloquent Model per new table (snake_case table → PascalCase model)",
            "  - Create ONE Policy per module that needs authorization",
            "  - Create ONE FormRequest per API endpoint",
            "  - Create ONE Service per domain operation (CRUD, validation, FX conversion, source config)",
            "  - Create ONE API Resource per model returned in responses",
            "  - Create ONE Controller per API resource group (thin, delegates to services)",
            "  - Create Queue Jobs only where JSON specifies background processing",
            "",
            "TABLE DISAMBIGUATION:",
            "  The JSON defines SEPARATE tables across modules. Tables with similar columns",
            "  (e.g. expense-related tables in different modules) are DISTINCT entities with",
            "  different column types, status enums, and relationships. Each MUST have its own",
            "  Model and Migration. Do NOT merge tables from different modules.",
        ]
        return '\n'.join(lines)

    def _build_task_mapping(self, um: dict, pe: dict, sdc: dict) -> str:
        """Build CONCISE task mapping for file/dependency ordering only.

        The ProjectManager only needs table names, FK dependencies, API endpoints,
        and service names to produce a dependency-ordered file list. Full column
        definitions, seed data, validation rules, and response structures belong
        in the Architect/Engineer prompts — NOT here. Keeping this concise prevents
        LLM output truncation (JSONDecodeError from hitting max_token limit).
        """
        lines = []

        # ── Module 1: User Management ──
        lines.append("\n=== Module 1: User Management ===")
        self._append_table_summary(lines, um.get('database_schema', {}).get('tables', []))
        self._append_er_relationships(lines, um.get('er_diagram', {}))
        self._append_roles_summary(lines, um.get('permission_metrics', []))

        # ── Module 2: Pocket Expense CSV Upload ──
        lines.append("\n=== Module 2: Pocket Expense - CSV Batch Upload ===")
        self._append_api_endpoint(lines, pe.get('api_contract', {}))
        self._append_validation_behavior(lines, pe.get('overview', {}).get('validation_behavior', {}))
        self._append_upload_table_name(lines, pe.get('data_model', {}).get('new_table', {}))
        self._append_local_storage_name(lines, pe.get('storing_pocket_expenses_from_file_upload', {}))
        self._append_processing_steps(lines, pe.get('file_processing', {}))
        self._append_validator_name(lines, pe.get('validation_service', {}))
        csv_cols = pe.get('csv_file_definition', {}).get('columns', [])
        in_file = [c.get('csv_column') for c in csv_cols if c.get('csv_column')]
        lines.append(f"  CSV columns ({len(in_file)} in-file): {in_file}")

        # ── Module 3: Single Expense Data Capturing ──
        lines.append("\n=== Module 3: Single Expense Data Capturing ===")
        sdc_tables = sdc.get('database_schema', {}).get('tables', [])
        self._append_table_summary(lines, sdc_tables)
        self._append_services_needed(lines, sdc)

        return '\n'.join(lines)

    # ── Helper methods: concise extractions for task ordering ──

    def _append_table_summary(self, lines: list, tables: list):
        """List table names, origin, column count, and FK targets — no column details."""
        if not tables:
            return
        lines.append("  Tables (each requires Migration + Model):")
        for table in tables:
            name = table.get('name', '?')
            origin = table.get('origin', '?')
            cols = table.get('columns', [])
            fks = table.get('foreign_keys', [])
            seed = table.get('seed_data', [])
            fk_targets = [fk.get('references', '?') for fk in fks]
            parts = [f"    {name} (origin: {origin}, {len(cols)} cols)"]
            if fk_targets:
                parts.append(f"FKs -> {fk_targets}")
            if seed:
                parts.append(f"seed: {len(seed)} rows")
            lines.append(' | '.join(parts))

    def _append_er_relationships(self, lines: list, er: dict):
        """Output ER relationships from JSON."""
        relationships = er.get('relationships', [])
        if not relationships:
            return
        lines.append("  ER Relationships:")
        for rel in relationships:
            lines.append(f"    {rel['from']} --[{rel['type']}]--> {rel['to']}")

    def _append_roles_summary(self, lines: list, metrics: list):
        """List role names and whether they have management rights."""
        if not metrics:
            return
        lines.append("  Roles (for Policy/authorization):")
        for m in metrics:
            role = m.get('role', '?')
            has_mgmt = 'with_management_rights' in m
            lines.append(f"    {role}{' (has management_rights)' if has_mgmt else ''}")

    def _append_api_endpoint(self, lines: list, api: dict):
        """Output just the endpoint path, method, controller, and middleware."""
        if not api:
            return
        route = api.get('route', {})
        lines.append(f"  Endpoint: {route.get('method', '?')} {route.get('path', '?')}")
        lines.append(f"  Controller: {route.get('controller', '?')} (origin: {route.get('controller_origin', '?')})")
        lines.append(f"  Middleware: {route.get('middleware', '?')}")

    def _append_validation_behavior(self, lines: list, vb: dict):
        """Output validation behavior summary."""
        if not vb:
            return
        lines.append(f"  Validation: {vb.get('description', '?')} | on_failure: {vb.get('on_failure', '?')}")

    def _append_upload_table_name(self, lines: list, new_table: dict):
        """Output upload tracking table name and model."""
        if not new_table:
            return
        lines.append(f"  Upload Table: {new_table.get('name', '?')} (Model: {new_table.get('model', '?')}, {len(new_table.get('columns', []))} cols)")

    def _append_local_storage_name(self, lines: list, ls: dict):
        """Output local storage table name."""
        schema = ls.get('local_storage_schema', {}) if ls else {}
        if not schema:
            return
        lines.append(f"  Local Storage Table: {schema.get('table_name', '?')} (requires Migration + Model)")

    def _append_processing_steps(self, lines: list, fp: dict):
        """Output processing step names only."""
        steps = fp.get('steps', [])
        if not steps:
            return
        step_names = [f"Step {s.get('step', '?')}: {s.get('name', '?')}" for s in steps]
        lines.append(f"  Processing Pipeline: {step_names}")

    def _append_validator_name(self, lines: list, vs: dict):
        """Output validator service name."""
        if not vs:
            return
        lines.append(f"  Validator Service: {vs.get('name', '?')}")

    def _append_services_needed(self, lines: list, sdc: dict):
        """Summarize services needed for single expense module."""
        services = []
        if sdc.get('oop_expense_source', {}):
            src = sdc['oop_expense_source']
            config = src.get('client_config_behaviour', {})
            max_sources = config.get('max_active_sources_per_client', '?')
            services.append(f"ExpenseSourceConfig (max {max_sources} per client)")
        if sdc.get('currency_conversion', {}):
            services.append("FxConversion (uses existing tables)")
        if sdc.get('fx_conversion_flow', {}):
            fx = sdc['fx_conversion_flow']
            step_names = [v.get('name', k) for k, v in fx.items() if isinstance(v, dict)]
            services.append(f"FX flow steps: {step_names}")
        # Metadata table
        meta_examples = sdc.get('database_schema', {}).get('metadata_json_examples', {})
        if meta_examples:
            meta_types = [k for k in meta_examples if k != 'origin']
            services.append(f"Metadata types: {meta_types}")
        if services:
            lines.append("  Services needed:")
            for s in services:
                lines.append(f"    - {s}")
