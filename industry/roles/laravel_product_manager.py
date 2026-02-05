#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_product_manager.py
@Desc    : Laravel Product Manager role for Volopa OOP Expense system
"""

import json
from pathlib import Path
from typing import Dict, Any
from metagpt.roles.product_manager import ProductManager


class LaravelProductManager(ProductManager):
    """
    Laravel Product Manager specialized for OOP Expense product requirements.

    Responsibilities:
    - Define business requirements for the OOP Expense Laravel APIs
    - Create PRDs with Laravel-specific technical specifications
    - Specify API endpoints, validation rules, and business logic
    - Define user stories and acceptance criteria

    Domain Modules:
    - User Management: Role hierarchy, permission metrics, access control flows
    - Pocket Expense (CSV Upload): Bulk upload, CSV validation, error handling
    - Expense Single Data Capturing: Single entry, FX conversion, metadata, source config
    """

    use_fixed_sop: bool = True
    name: str = "Joshua"
    profile: str = "Laravel Product Manager"
    goal: str = "Create comprehensive PRD for Volopa OOP Expense system (User Management, Pocket Expense CSV Upload, Single Expense Capturing)"

    constraints: str = """
    - Use same language as user requirements
    - Focus on Laravel API patterns and RESTful design principles
    - Define clear acceptance criteria for API endpoints
    - Specify data validation rules at business level
    - Document Laravel-specific requirements:
      * API routes (protected by Oauth2UserClient middleware)
      * Request/response formats (JSON)
      * Authentication requirements (OAuth2 via Oauth2UserClient)
      * Validation rules for FormRequests
      * Business logic separation (controllers vs services)
    - Prioritize requirements clearly (P0: Must-have, P1: Should-have, P2: Nice-to-have)
    - Include Laravel composer package requirements

    DOMAIN ENTITIES:
    - Users (Primary Administrator, Administrator, Business User, Card User)
    - Clients (multi-tenant, all data scoped by client_id)
    - Features & user_feature_permission (RBAC via feature flags)
    - pocket_expense (single expense records with status workflow: draft → submitted → approved → rejected)
    - pocket_expense_metadata (flexible metadata: category, tracking codes, project, file, expense_source)
    - opt_pocket_expense_type (ATM Withdrawal, Point of Sale, Fee & Charges, Refund from Merchant)
    - pocket_expense_source_client_config (client-specific + global "Other" source)
    - pocket_expense_file_uploads (CSV batch upload tracking with status lifecycle)
    - pocket_expense_uploads_data (local storage for background sync)
    - oop_expenses (expenses with approval workflow)

    KEY BUSINESS RULES:
    - All-or-nothing CSV validation: if any row fails, no expenses are created
    - Permission delegation: Primary Admin → Admin → managed users
    - Expense sources: 3 defaults (Cash, Corporate Card, Personal Card) + global "Other" + max 20 active per client
    - FX conversion: debounced API call, 30-day lookback, client commission adjustment
    - Managing rights revocation: user loses access, base user falls back to Primary Admin management
    """

    def __init__(self, **kwargs):
        """
        Initialize Laravel Product Manager.

        Inherits from ProductManager which provides:
        - WritePRD action
        - PrepareDocuments action (when use_fixed_sop=True)
        - Tool access: RoleZero, Browser, Editor, SearchEnhancedQA
        """
        super().__init__(**kwargs)

        # Load functional requirements from JSON
        self.requirements = self._load_requirements()

        # Update constraints with loaded data
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, the role uses BY_ORDER mode
        # Set max_react_loop to 1 to execute actions once and stop
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

        # Track if we've already published WritePRD to avoid duplicate execution
        self._prd_published = False

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

        # Build functional requirement summaries
        permission_text = self._format_permission_metrics(um.get('permission_metrics', []))
        access_flow_text = self._format_access_control(um.get('access_control_flow', {}))
        csv_flow_text = self._format_csv_upload_flow(pe)
        validation_text = self._format_validation_rules(pe.get('validation_service', {}))
        expense_source_text = self._format_expense_sources(sdc.get('oop_expense_source', {}))
        fx_text = self._format_fx_flow(sdc.get('fx_conversion_flow', {}))

        self.constraints += f"""

LOADED FUNCTIONAL REQUIREMENTS FROM JSON:

=== MODULE 1: User Management System ===
Roles & Permissions:
{permission_text}

Access Control Flow:
{access_flow_text}

=== MODULE 2: Pocket Expense - CSV Batch Upload ===
{csv_flow_text}

CSV Validation Rules:
{validation_text}

=== MODULE 3: Single Expense Data Capturing ===
Expense Sources:
{expense_source_text}

FX Conversion Flow:
{fx_text}

EXPECTED PRD OUTPUT SECTIONS:
- Business Requirements (per module)
- User Stories and Acceptance Criteria
- Role-Permission Matrix
- API Endpoint Specifications
- Validation Rules (CSV and single entry)
- Error Handling & Response Formats
- Security and Access Control Requirements
"""

    def _format_permission_metrics(self, metrics: list) -> str:
        lines = []
        for m in metrics:
            role = m.get('role', 'Unknown')
            perms = m.get('default_permissions', {})
            lines.append(f"  {role}:")
            for perm_key, perm_val in perms.items():
                lines.append(f"    {perm_key}: {perm_val}")
            if 'with_management_rights' in m:
                lines.append(f"    with_management_rights: {m['with_management_rights']}")
        return '\n'.join(lines)

    def _format_access_control(self, acf: dict) -> str:
        lines = []
        for section_key, section_val in acf.items():
            if isinstance(section_val, dict):
                lines.append(f"  {section_key}:")
                if 'description' in section_val:
                    lines.append(f"    {section_val['description']}")
            elif isinstance(section_val, list):
                lines.append(f"  {section_key}:")
                for item in section_val:
                    lines.append(f"    - {item}")
        return '\n'.join(lines)

    def _format_csv_upload_flow(self, pe: dict) -> str:
        lines = []
        overview = pe.get('overview', {})
        lines.append(f"Description: {overview.get('description', '')}")
        for cap in overview.get('key_capabilities', []):
            lines.append(f"  - {cap}")

        lines.append("\nEnd-to-End Flow:")
        for step in pe.get('end_to_end_flow', []):
            if isinstance(step, str):
                lines.append(f"  - {step}")
            elif isinstance(step, dict):
                lines.append(f"  - {step.get('step', '')}")
                for sub in step.get('sub_steps', []):
                    lines.append(f"    - {sub}")

        columns = pe.get('csv_file_definition', {}).get('columns', [])
        lines.append(f"\nCSV Columns: {len(columns)} total")
        for col in columns:
            if col.get('csv_column'):
                req = "Required" if col.get('required') else "Optional"
                lines.append(f"  - {col['csv_column']} ({req}) -> {col.get('target_db_field', 'N/A')}")
        return '\n'.join(lines)

    def _format_validation_rules(self, vs: dict) -> str:
        lines = []
        rules = vs.get('key_rules_per_row', {})
        for field, rule in rules.items():
            req = "Required" if rule.get('required') else "Optional"
            lines.append(f"  {field} ({req}):")
            for k, v in rule.items():
                if k != 'required':
                    lines.append(f"    {k}: {v}")
        return '\n'.join(lines)

    def _format_expense_sources(self, src: dict) -> str:
        lines = []
        setup = src.get('default_and_global_source_setup', {})
        on_enable = setup.get('on_client_oop_feature_enable', {})
        defaults = on_enable.get('auto_create_defaults', [])
        lines.append(f"  Default sources on enable: {defaults}")
        lines.append(f"  Global 'Other': client_id = NULL, not deletable/editable")

        config = src.get('client_config_behaviour', {})
        lines.append(f"  Max active sources per client: {config.get('max_active_sources_per_client', 20)}")
        lines.append(f"  Soft delete: deleted sources remain on historical records, excluded from future dropdowns")
        return '\n'.join(lines)

    def _format_fx_flow(self, fx: dict) -> str:
        lines = []
        for step_key, step_val in fx.items():
            name = step_val.get('name', step_key)
            lines.append(f"  {name}:")
            for item in step_val.get('flow', []):
                lines.append(f"    - {item}")
        return '\n'.join(lines)

    async def _think(self) -> bool:
        """Override _think to prevent duplicate PRD generation in multi-round workflows."""
        if self._prd_published:
            self.rc.todo = None
            return False
        result = await super()._think()
        return result

    async def _act(self) -> None:
        """Override _act to mark PRD as published after execution."""
        result = await super()._act()

        from metagpt.actions import WritePRD
        if isinstance(self.rc.todo, WritePRD) or (hasattr(self.rc, 'memory') and
            any(msg.cause_by == WritePRD.__name__ for msg in self.rc.memory.get())):
            self._prd_published = True

        return result
