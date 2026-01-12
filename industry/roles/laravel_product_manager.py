#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_product_manager.py
@Desc    : Laravel Product Manager role for Volopa Mass Payments system
"""

import json
import yaml
from pathlib import Path
from typing import Dict, Any, List
from metagpt.roles.product_manager import ProductManager
from metagpt.logs import logger


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
    REQUIREMENTS INPUT (TYPE + CONTEXT Taxonomy):
    - Consumes Intent and Requirement statements from ingestion
    - Intent statements: High-level business goals and vision
    - Requirement statements: Concrete user-facing capabilities
    - Context classification helps prioritize:
      * Environment-specific: Volopa business rules (externalize in PRD)
      * Project-specific: Generic patterns (describe as reusable features)

    PRD OUTPUT REQUIREMENTS:
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
    - Transform Intent statements into Product Goals
    - Transform Requirement statements into User Stories with acceptance criteria
    - Ignore Constraint, Flow, Interface, Design statements (those are for Architect)
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

        # Load requirements (supports both new YAML and old JSON formats)
        self.requirements = self._load_requirements()
        self.requirements_format = self._detect_requirements_format()

        # Update constraints with loaded data
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, the role uses BY_ORDER mode
        # Set max_react_loop to 1 to execute actions once and stop
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

        # Track if we've already published WritePRD to avoid duplicate execution
        self._prd_published = False

    def _load_requirements(self) -> dict:
        """
        Load requirements from either new YAML format or old JSON format.

        Priority:
        1. Check for new ingestion output: industry/output/project_context.yaml
        2. Fall back to old JSON format: industry/requirements/user_requirements.json
        """
        base_path = Path(__file__).parent.parent

        # Option 1: New YAML format from ingestion
        yaml_path = base_path / "output" / "project_context.yaml"
        if yaml_path.exists():
            logger.info(f"Loading requirements from new YAML format: {yaml_path}")
            with open(yaml_path, 'r', encoding='utf-8') as f:
                return yaml.safe_load(f)

        # Option 2: Old JSON format (backward compatibility)
        json_path = base_path / "requirements" / "user_requirements.json"
        if json_path.exists():
            logger.info(f"Loading requirements from old JSON format: {json_path}")
            with open(json_path, 'r', encoding='utf-8') as f:
                return json.load(f)

        raise FileNotFoundError(
            "No requirements file found. Expected:\n"
            f"  - {yaml_path} (new YAML format)\n"
            f"  - {json_path} (old JSON format)"
        )

    def _detect_requirements_format(self) -> str:
        """Detect whether we're using new YAML or old JSON format."""
        if 'requirements' in self.requirements and isinstance(self.requirements.get('requirements'), list):
            return 'yaml'  # New format has 'requirements' as list
        elif 'functional_requirements' in self.requirements:
            return 'json'  # Old format has 'functional_requirements'
        else:
            logger.warning("Unknown requirements format, defaulting to JSON")
            return 'json'

    def _update_constraints_from_requirements(self):
        """Inject loaded requirements into role constraints."""

        if self.requirements_format == 'yaml':
            self._update_constraints_from_yaml()
        else:
            self._update_constraints_from_json()

    def _update_constraints_from_yaml(self):
        """Update constraints from new YAML format (TYPE + CONTEXT taxonomy)."""
        project_meta = self.requirements.get('project_metadata', {})

        # Extract Intent and Requirement statements (PM's input)
        intent_stmts = [r for r in self.requirements.get('requirements', [])
                        if r.get('type') == 'Intent']
        requirement_stmts = [r for r in self.requirements.get('requirements', [])
                             if r.get('type') == 'Requirement']

        # Count environment vs project specific
        env_count = sum(1 for r in requirement_stmts if r.get('context') == 'Environment-specific')
        proj_count = sum(1 for r in requirement_stmts if r.get('context') == 'Project-specific')

        # Build constraint text
        requirement_text = self._format_yaml_requirements(intent_stmts, requirement_stmts)

        self.constraints += f"""

LOADED REQUIREMENTS FROM TYPE + CONTEXT TAXONOMY:

Total Statements: {project_meta.get('total_statements', 0)}
Your Input (for PRD):
  - Intent statements: {len(intent_stmts)} (high-level business goals)
  - Requirement statements: {len(requirement_stmts)} (user-facing capabilities)
    * Environment-specific: {env_count} (Volopa business rules)
    * Project-specific: {proj_count} (generic patterns)

REQUIREMENTS TO TRANSFORM INTO PRD:
{requirement_text}

KEY INSTRUCTIONS:
- Transform Intent statements into Product Goals section
- Transform Requirement statements into User Stories with acceptance criteria
- Highlight Environment-specific requirements as configuration/business rules
- Describe Project-specific requirements as reusable features
- Ignore Constraint, Flow, Interface, Design statements (Architect will handle those)
"""

    def _update_constraints_from_json(self):
        """Update constraints from old JSON format (NATURE taxonomy) - backward compatibility."""
        # Extract relevant sections
        project_meta = self.requirements['project_metadata']
        agent_info = self.requirements['agent_assignments']['LaravelProductManager']
        frs = self.requirements['functional_requirements']

        # Build dynamic constraint text
        requirement_text = self._format_requirements_for_prd(frs)

        # Append to existing constraints
        self.constraints += f"""

LOADED FUNCTIONAL REQUIREMENTS FROM JSON (OLD FORMAT):

Project: {project_meta['project_name']}
Framework: {project_meta['framework']} (PHP {project_meta['php_version']})
Max Capacity: {project_meta['max_file_capacity']} payment rows per file
API Prefix: {project_meta['api_prefix']}

REQUIREMENTS TO TRANSFORM INTO PRD:
{requirement_text}

EXPECTED OUTPUT SECTIONS:
{', '.join(agent_info['responsibilities'])}
"""

    def _format_yaml_requirements(self, intent_stmts: List[Dict], requirement_stmts: List[Dict]) -> str:
        """Format Intent and Requirement statements from YAML format."""
        lines = []

        # Format Intent statements
        if intent_stmts:
            lines.append("\n=== INTENT STATEMENTS (Business Goals) ===\n")
            for stmt in intent_stmts:
                lines.append(f"{stmt['id']}: {stmt['statement']}")
                lines.append(f"  Context: {stmt['context']}")
                lines.append(f"  Rationale: {stmt['context_rationale']}")
                lines.append("")

        # Format Requirement statements grouped by category
        if requirement_stmts:
            lines.append("\n=== REQUIREMENT STATEMENTS (User Capabilities) ===\n")

            # Group by category
            categories = {}
            for stmt in requirement_stmts:
                cat = stmt.get('category', 'General')
                if cat not in categories:
                    categories[cat] = []
                categories[cat].append(stmt)

            for cat, stmts in categories.items():
                lines.append(f"\n### {cat}\n")
                for stmt in stmts:
                    lines.append(f"{stmt['id']} [{stmt.get('priority', 'P2')}]: {stmt['statement']}")
                    lines.append(f"  Context: {stmt['context']} - {stmt['context_rationale']}")
                    lines.append("")

        return '\n'.join(lines)

    def _format_requirements_for_prd(self, frs: dict) -> str:
        """Format all functional requirements as text for PRD creation (old JSON format)"""
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
        """Override _act to generate standard PRD + append detailed requirements."""
        # Step 1: Generate standard PRD via parent class
        result = await super()._act()

        # Step 2: Append detailed functional requirements to generated PRD
        await self._append_detailed_requirements()

        # Mark as published after WritePRD action completes
        from metagpt.actions import WritePRD
        if isinstance(self.rc.todo, WritePRD) or (hasattr(self.rc, 'memory') and
            any(msg.cause_by == WritePRD.__name__ for msg in self.rc.memory.get())):
            self._prd_published = True

        return result

    async def _append_detailed_requirements(self):
        """
        Append detailed functional requirements to the generated PRD.

        This adds a "Detailed Functional Requirements" section to the PRD JSON,
        preserving all 42 sub-requirements with their full details, priorities,
        and acceptance criteria.

        This hybrid approach provides:
        - Standard PRD sections (Product Goals, User Stories, Top 5 requirements)
        - Complete detailed requirements (all 42 sub-requirements)
        """
        from metagpt.logs import logger

        # Get the PRD file that was just generated
        if not hasattr(self, 'repo') or not self.repo:
            logger.warning("No repo available, cannot append detailed requirements")
            return

        prd_files = list(self.repo.docs.prd.changed_files.keys())
        if not prd_files:
            logger.warning("No PRD file generated, cannot append detailed requirements")
            return

        prd_filename = prd_files[0]
        logger.info(f"Appending detailed requirements to {prd_filename}")

        # Load the generated PRD
        prd_doc = await self.repo.docs.prd.get(prd_filename)
        prd_content = json.loads(prd_doc.content)

        # Add detailed requirements section
        prd_content["Detailed Functional Requirements"] = self._format_detailed_requirements()

        # Add requirements summary (handles both formats)
        if self.requirements_format == 'yaml':
            # New YAML format
            intent_count = len([r for r in self.requirements.get('requirements', [])
                                if r.get('type') == 'Intent'])
            requirement_count = len([r for r in self.requirements.get('requirements', [])
                                     if r.get('type') == 'Requirement'])
            env_specific = len([r for r in self.requirements.get('requirements', [])
                                if r.get('context') == 'Environment-specific'])
            project_specific = len([r for r in self.requirements.get('requirements', [])
                                    if r.get('context') == 'Project-specific'])

            prd_content["Requirements Classification Summary"] = {
                "taxonomy": "TYPE + CONTEXT",
                "intent_statements": intent_count,
                "requirement_statements": requirement_count,
                "total_requirements": intent_count + requirement_count,
                "environment_specific": env_specific,
                "project_specific": project_specific,
                "classification_note": "Environment-Specific = Volopa business rules; Project-Specific = Standard patterns"
            }
        else:
            # Old JSON format
            env_specific = sum(1 for fr in self.requirements['functional_requirements'].values()
                              for sub in fr['sub_requirements'].values()
                              if sub.get('classification') == 'environment-specific')
            project_specific = sum(1 for fr in self.requirements['functional_requirements'].values()
                                  for sub in fr['sub_requirements'].values()
                                  if sub.get('classification') == 'project-specific')
            total_reqs = env_specific + project_specific

            prd_content["Requirements Classification Summary"] = {
                "taxonomy": "NATURE (legacy)",
                "total_requirements": total_reqs if total_reqs > 0 else len([sub for fr in self.requirements['functional_requirements'].values() for sub in fr['sub_requirements'].values()]),
                "environment_specific": env_specific,
                "project_specific": project_specific,
                "classification_note": "Environment-Specific = Volopa business rules; Project-Specific = Standard patterns"
            }

        # Save updated PRD
        await self.repo.docs.prd.save(
            filename=prd_filename,
            content=json.dumps(prd_content, indent=2)
        )

        logger.info(f"Successfully appended detailed requirements to {prd_filename}")

    def _format_detailed_requirements(self) -> dict:
        """
        Format all requirements organized by category with priorities.
        Supports both new YAML and old JSON formats.
        """
        if self.requirements_format == 'yaml':
            return self._format_detailed_requirements_yaml()
        else:
            return self._format_detailed_requirements_json()

    def _format_detailed_requirements_yaml(self) -> dict:
        """Format requirements from new YAML format (TYPE + CONTEXT taxonomy)."""
        detailed = {}

        # Get Intent and Requirement statements
        intent_stmts = [r for r in self.requirements.get('requirements', [])
                        if r.get('type') == 'Intent']
        requirement_stmts = [r for r in self.requirements.get('requirements', [])
                             if r.get('type') == 'Requirement']

        # Add Intent statements as separate category
        if intent_stmts:
            detailed['Product Intent'] = []
            for stmt in intent_stmts:
                detailed['Product Intent'].append({
                    'id': stmt['id'],
                    'statement': stmt['statement'],
                    'type': stmt['type'],
                    'context': stmt['context'],
                    'context_rationale': stmt['context_rationale'],
                    'priority': stmt.get('priority', 'P0')
                })

        # Group Requirements by category
        for stmt in requirement_stmts:
            category = stmt.get('category', 'General')
            if category not in detailed:
                detailed[category] = []

            detailed[category].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'type': stmt['type'],
                'context': stmt['context'],
                'context_rationale': stmt['context_rationale'],
                'priority': stmt.get('priority', 'P2')
            })

        return detailed

    def _format_detailed_requirements_json(self) -> dict:
        """Format requirements from old JSON format (NATURE taxonomy)."""
        detailed = {}

        for fr_id, fr_data in self.requirements['functional_requirements'].items():
            category = fr_data['category']

            if category not in detailed:
                detailed[category] = []

            for sub_id, sub_req in fr_data['sub_requirements'].items():
                req_dict = {
                    "id": sub_id,
                    "title": sub_req['title'],
                    "requirement": sub_req['requirement'],
                    "criteria": sub_req.get('criteria', []),
                    "priority": self._infer_priority(sub_req),
                }

                # Add classification if present
                if 'classification' in sub_req:
                    req_dict['classification'] = sub_req['classification']
                if 'classification_rationale' in sub_req:
                    req_dict['classification_rationale'] = sub_req['classification_rationale']

                # Add validation info if present
                if 'validations' in sub_req and sub_req['validations']:
                    req_dict['validations'] = sub_req['validations']

                # Add columns info if present
                if 'columns_required' in sub_req and sub_req['columns_required']:
                    req_dict['columns_required'] = sub_req['columns_required']

                detailed[category].append(req_dict)

        return detailed

    def _infer_priority(self, requirement: dict) -> str:
        """
        Infer priority (P0/P1/P2) based on requirement content and keywords.

        Priority logic:
        - P0 (Must-have): Core functionality - upload, validate, approve, process, payment
        - P1 (Should-have): Important features - notification, status, audit, error, summary, track
        - P2 (Nice-to-have): Everything else - guidance, help, documentation

        Args:
            requirement: Dict containing requirement data

        Returns:
            str: "P0", "P1", or "P2"
        """
        # Check if priority is explicitly set
        if 'priority' in requirement:
            return requirement['priority']

        text = requirement.get('requirement', '').lower()

        # P0: Critical functionality
        p0_keywords = ['upload', 'validate', 'approve', 'process', 'payment', 'create',
                       'delete', 'mandatory', 'required', 'must', 'shall allow',
                       'file', 'csv', 'template', 'download']
        if any(word in text for word in p0_keywords):
            return "P0"

        # P1: Important features
        p1_keywords = ['notification', 'status', 'audit', 'error', 'summary', 'track',
                      'display', 'view', 'list', 'filter', 'cancel', 'history',
                      'permission', 'security', 'email']
        if any(word in text for word in p1_keywords):
            return "P1"

        # P2: Nice to have
        return "P2"


# Placeholder for future customization
# TODO: Add Laravel-specific PRD templates
# TODO: Add API endpoint specification helpers
# TODO: Add Laravel package recommendation logic
# TODO: Integrate with Volopa business rules (currency-specific requirements, approval workflows)
