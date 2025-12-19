#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_product_manager.py
@Desc    : Laravel Product Manager role for Volopa Mass Payments system
"""

import json
from pathlib import Path
from typing import Dict, Any
from metagpt.roles.product_manager import ProductManager


class LaravelProductManager(ProductManager):
    """
    Laravel Product Manager specialized for API product requirements.

    Responsibilities:
    - Define business requirements for Laravel APIs
    - Create PRDs with Laravel-specific technical specifications
    - Specify API endpoints, validation rules, and business logic
    - Define user stories and acceptance criteria

    Allocated Intents (from massPaymentsVolopaAgents.txt):
    - getReceiptTemplate: Define template download requirements
    - getClientDraftFiles: Specify draft file retrieval functionality
    - getPaymentPurposeCodes: Define purpose code lookup requirements
    - getAllClientFiles: Specify file history requirements
    - getBeneficiariesByCurrency: Define beneficiary filtering requirements
    """

    use_fixed_sop: bool = True
    name: str = "Joshua"
    profile: str = "Laravel Product Manager"
    goal: str = "Create comprehensive PRD for Laravel Mass Payments API system"

    constraints: str = """
    - Use same language as user requirements
    - Focus on Laravel API patterns and RESTful design principles
    - Define clear acceptance criteria for API endpoints
    - Specify data validation rules at business level
    - Document Laravel-specific requirements:
      * API routes (versioned under /v1)
      * Request/response formats (JSON)
      * Authentication requirements (OAuth2/WSSE)
      * Validation rules for FormRequests
      * Business logic separation (controllers vs services)
    - Prioritize requirements clearly (P0: Must-have, P1: Should-have, P2: Nice-to-have)
    - Include Laravel composer package requirements
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
        """Load user_requirements.json file"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "user_requirements.json"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            return json.load(f)

    def _update_constraints_from_requirements(self):
        """Inject loaded requirements into role constraints"""

        # Extract relevant sections
        project_meta = self.requirements['project_metadata']
        agent_info = self.requirements['agent_assignments']['LaravelProductManager']
        frs = self.requirements['functional_requirements']

        # Build dynamic constraint text
        requirement_text = self._format_requirements_for_prd(frs)

        # Append to existing constraints
        self.constraints += f"""

LOADED FUNCTIONAL REQUIREMENTS FROM JSON:

Project: {project_meta['project_name']}
Framework: {project_meta['framework']} (PHP {project_meta['php_version']})
Max Capacity: {project_meta['max_file_capacity']} payment rows per file
API Prefix: {project_meta['api_prefix']}

REQUIREMENTS TO TRANSFORM INTO PRD:
{requirement_text}

EXPECTED OUTPUT SECTIONS:
{', '.join(agent_info['responsibilities'])}
"""

    def _format_requirements_for_prd(self, frs: dict) -> str:
        """Format all functional requirements as text for PRD creation"""
        lines = []

        for fr_id, fr_data in frs.items():
            lines.append(f"\n### {fr_id}: {fr_data['category']}")

            for sub_id, sub_req in fr_data['sub_requirements'].items():
                lines.append(f"\n**{sub_id}**: {sub_req['title']}")
                lines.append(f"Requirement: {sub_req['requirement']}")

                if 'criteria' in sub_req:
                    lines.append("Criteria:")
                    for criterion in sub_req['criteria']:
                        lines.append(f"  - {criterion}")

                if 'columns_required' in sub_req:
                    lines.append(f"Columns: {len(sub_req['columns_required'])} required columns")

                if 'validations' in sub_req:
                    lines.append(f"Validations: {len(sub_req['validations'])} validation rules")

        return '\n'.join(lines)

    async def _think(self) -> bool:
        """Override _think to prevent duplicate PRD generation in multi-round workflows."""
        # If we've already published WritePRD, don't act again
        if self._prd_published:
            self.rc.todo = None
            return False

        # Call parent _think logic
        result = await super()._think()
        return result

    async def _act(self) -> None:
        """Override _act to mark PRD as published after execution."""
        result = await super()._act()

        # Mark as published after WritePRD action completes
        from metagpt.actions import WritePRD
        if isinstance(self.rc.todo, WritePRD) or (hasattr(self.rc, 'memory') and
            any(msg.cause_by == WritePRD.__name__ for msg in self.rc.memory.get())):
            self._prd_published = True

        return result


# Placeholder for future customization
# TODO: Add Laravel-specific PRD templates
# TODO: Add API endpoint specification helpers
# TODO: Add Laravel package recommendation logic
# TODO: Integrate with Volopa business rules (currency-specific requirements, approval workflows)
