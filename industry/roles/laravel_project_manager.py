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

        # Load requirements from all three JSON files
        self.requirements = self._load_requirements()

        # Update constraints with task breakdown data
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
        """Inject task breakdown guidance from loaded requirements"""

        um = self.requirements['user_management']
        pe = self.requirements['pocket_expense']
        sdc = self.requirements['single_data_capturing']

        # Build task mapping from all three modules
        task_mapping = self._build_task_mapping(um, pe, sdc)
        stats = self._compute_stats(um, pe, sdc)

        self.constraints += f"""

LOADED REQUIREMENTS FROM JSON:

Task Breakdown Statistics:
{stats}

Task Mapping Guide (Module -> Implementation Tasks):
{task_mapping}

Expected Output:
Task breakdown mapping all OOP Expense modules to implementation tasks with dependencies,
covering User Management (permissions, role hierarchy), Pocket Expense CSV Upload
(upload pipeline, validation, background sync), and Single Expense Capturing
(CRUD, FX conversion, metadata, source config).
"""

    def _compute_stats(self, um: dict, pe: dict, sdc: dict) -> str:
        um_tables = len(um.get('database_schema', {}).get('tables', []))
        sdc_tables = len(sdc.get('database_schema', {}).get('tables', []))
        pe_new_tables = 1 if pe.get('data_model', {}).get('new_table') else 0
        pe_local_tables = 1 if pe.get('storing_pocket_expenses_from_file_upload', {}).get('local_storage_schema') else 0
        total_tables = um_tables + sdc_tables + pe_new_tables + pe_local_tables

        csv_columns = len(pe.get('csv_file_definition', {}).get('columns', []))
        roles = len(um.get('permission_metrics', []))
        expense_types = len(sdc.get('database_schema', {}).get('tables', [{}])[0].get('seed_data', []))

        lines = [
            f"- Total DB Tables: {total_tables}",
            f"  - User Management: {um_tables} (user_feature_permission, oop_expenses)",
            f"  - Single Data Capturing: {sdc_tables} (opt_pocket_expense_type, source_client_config, pocket_expense, pocket_expense_metadata)",
            f"  - Pocket Expense Upload: {pe_new_tables + pe_local_tables} (file_uploads, uploads_data)",
            f"- CSV Columns: {csv_columns}",
            f"- User Roles: {roles}",
            f"- Expense Types: {expense_types}",
            f"- Estimated Models: ~8 (PocketExpense, PocketExpenseMetadata, PocketExpenseFileUpload, PocketExpenseUploadsData, OptPocketExpenseType, PocketExpenseSourceClientConfig, UserFeaturePermission, OopExpenses)",
            f"- Estimated Controllers: ~3 (PocketExpenseUploadController, PocketExpenseController, UserPermissionController)",
            f"- Estimated Services: ~5 (PocketExpenseCSVValidator, FXConversionService, ExpenseSourceService, PermissionService, PocketExpenseService)",
            f"- Estimated Migrations: ~7",
            f"- Estimated Total Files: ~35-40",
        ]
        return '\n'.join(lines)

    def _build_task_mapping(self, um: dict, pe: dict, sdc: dict) -> str:
        lines = []

        # Module 1: User Management
        lines.append("\n=== Module 1: User Management ===")
        lines.append("  Tables: user_feature_permission, oop_expenses")
        er = um.get('er_diagram', {})
        for rel in er.get('relationships', []):
            lines.append(f"  Relationship: {rel['from']} --{rel['label']}--> {rel['to']}")
        acf = um.get('access_control_flow', {})
        enablement = acf.get('service_enablement_flow', [])
        if enablement:
            lines.append("  Service Enablement Flow:")
            for step in enablement:
                lines.append(f"    - {step}")
        lines.append("  Tasks:")
        lines.append("    - Migration: create_user_feature_permission_table")
        lines.append("    - Migration: create_oop_expenses_table")
        lines.append("    - Model: UserFeaturePermission (relationships, unique constraints)")
        lines.append("    - Model: OopExpense (status enum, relationships)")
        lines.append("    - Policy: OopExpensePolicy (role-based CRUD + approve)")
        lines.append("    - Service: PermissionService (grant, revoke, check management rights)")
        lines.append("    - Controller: UserPermissionController")
        lines.append("    - Resource: UserFeaturePermissionResource")

        # Module 2: Pocket Expense CSV Upload
        lines.append("\n=== Module 2: Pocket Expense - CSV Batch Upload ===")
        api = pe.get('api_contract', {}).get('route', {})
        lines.append(f"  Endpoint: {api.get('method', 'POST')} {api.get('path', '/api/uploads/pocket-expense/csv')}")
        lines.append(f"  Max Rows: 200")
        lines.append(f"  Validation: All-or-nothing (synchronous)")
        lines.append(f"  Background Sync: ProcessExpenseUpload job, batches of 100")
        fp = pe.get('file_processing', {})
        for step in fp.get('steps', []):
            lines.append(f"  Processing Step {step.get('step', '?')}: {step.get('name', '')}")
        lines.append("  Tasks:")
        lines.append("    - Migration: create_pocket_expense_file_uploads_table")
        lines.append("    - Migration: create_pocket_expense_uploads_data_table")
        lines.append("    - Model: PocketExpenseFileUpload (status lifecycle, soft deletes)")
        lines.append("    - Model: PocketExpenseUploadsData (FK to file_uploads)")
        lines.append("    - FormRequest: UploadPocketExpenseCSVRequest (file, user_id, expense_user_id, client_id)")
        lines.append("    - Service: PocketExpenseCSVValidator (preload reference data, row-level validation)")
        lines.append("    - Controller: PocketExpenseUploadController@uploadPocketExpenseCSV")
        lines.append("    - Resource: ValidationErrorResource (line_number, field, error, value)")
        lines.append("    - Job: ProcessExpenseUpload (background sync in batches)")
        lines.append("    - Notification: ExpenseUploadCompleted")

        # Module 3: Single Expense Data Capturing
        lines.append("\n=== Module 3: Single Expense Data Capturing ===")
        db_tables = sdc.get('database_schema', {}).get('tables', [])
        for t in db_tables:
            lines.append(f"  Table: {t.get('name', '?')} ({len(t.get('columns', []))} cols)")
        fx = sdc.get('fx_conversion_flow', {})
        for step_key, step_val in fx.items():
            if not isinstance(step_val, dict):
                continue
            lines.append(f"  FX Step: {step_val.get('name', step_key)}")
        src = sdc.get('oop_expense_source', {})
        defaults = src.get('default_and_global_source_setup', {}).get('on_client_oop_feature_enable', {}).get('auto_create_defaults', [])
        lines.append(f"  Default Sources: {defaults}")
        lines.append("  Tasks:")
        lines.append("    - Migration: create_opt_pocket_expense_type_table (+ seed data)")
        lines.append("    - Migration: create_pocket_expense_source_client_config_table (+ global Other)")
        lines.append("    - Migration: create_pocket_expense_table")
        lines.append("    - Migration: create_pocket_expense_metadata_table")
        lines.append("    - Model: OptPocketExpenseType")
        lines.append("    - Model: PocketExpenseSourceClientConfig (unique name per client)")
        lines.append("    - Model: PocketExpense (status enum, relationships to metadata/user/client/type)")
        lines.append("    - Model: PocketExpenseMetadata (polymorphic metadata_type enum, FKs)")
        lines.append("    - FormRequest: StorePocketExpenseRequest")
        lines.append("    - Service: PocketExpenseService (CRUD with transactions)")
        lines.append("    - Service: FXConversionService (dated rate, 30-day lookback, commission)")
        lines.append("    - Service: ExpenseSourceService (defaults, Other handling, max 20 limit)")
        lines.append("    - Policy: PocketExpensePolicy (based on user_feature_permission)")
        lines.append("    - Controller: PocketExpenseController (thin CRUD)")
        lines.append("    - Resource: PocketExpenseResource (with metadata, whenLoaded)")

        return '\n'.join(lines)
