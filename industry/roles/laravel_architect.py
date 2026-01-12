#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_architect.py
@Desc    : Laravel Architect role for Volopa Mass Payments system
"""

import json
import yaml
from pathlib import Path
from typing import Dict, Any, List
from metagpt.roles.architect import Architect
from metagpt.logs import logger


class LaravelArchitect(Architect):
    """
    Laravel Architect specialized for API system design.

    Responsibilities:
    - Design Laravel API architecture (routes, controllers, services, models)
    - Define data models and database schema (migrations, relationships)
    - Design service layer and business logic separation
    - Create API endpoint specifications
    - Design validation and authorization architecture

    Allocated Intents (from massPaymentsVolopaAgents.txt):
    - uploadPaymentFile: Design file upload architecture (async processing, storage)
    - validatePaymentFile: Design validation service architecture
    - getUploadedStatus: Design status tracking system
    - getFileSummary: Design aggregation and reporting structures
    - getFileErrors: Design error collection architecture
    - getAllBeneficiariesByFileID: Design data relationships and queries
    - getFileErrorsList: Design error reporting architecture
    """

    use_fixed_sop: bool = True
    name: str = "Danny"
    profile: str = "Laravel System Architect"
    goal: str = "Design Laravel API system architecture following best practices and DOS/DONTS patterns"

    constraints: str = """
    REQUIREMENTS INPUT (TYPE + CONTEXT Taxonomy):
    Architect consumes requirements from TWO sources:

    1. project_context.yaml - Contains classified requirements:
       - Constraints: Business rules, limits, validation rules (MUST satisfy all)
       - Flows: Sequences, state transitions, workflows (design to support all)
       - Interfaces: API endpoints, CSV structure, UI touchpoints (implement exactly)
       - Requirements: User-facing capabilities (understand context)

    2. architect_seeds.md - Contains design guidance:
       - Design patterns: Recommended technical approaches
       - Complex flows: Flows requiring architectural decisions
       - Open questions: Ambiguities to resolve during design
       - Mandated choices: Non-negotiable environment-specific designs

    CONTEXT Classification Strategy:
    - Environment-specific: Design as configurable (externalize in config/, database, or app/Services/Volopa/)
    - Project-specific: Design as reusable core logic (implement in app/Services/Core/ and standard Laravel locations)

    MENTAL MODEL (Critical for All Design Decisions):
    Client → route (versioned, throttled, auth) → controller → FormRequest
    (validation + policy) → service/model (domain logic, transactions) →
    API Resource (shape output) → JSON with correct status codes and error format

    PRD STRUCTURE (How to Use PRD from ProductManager):
    The PRD contains TWO levels of requirements:

    1. High-Level Sections (Strategic Direction):
       - Product Goals: 3 strategic objectives (use for vision)
       - User Stories: 3-5 key scenarios (use for understanding user journeys)
       - Requirement Pool: Top 5 priorities (use for initial focus)

    2. Detailed Functional Requirements Section (Complete Specifications):
       - Contains ALL functional requirements organized by category
       - Each requirement includes: id, title, requirement text, criteria, priority
       - May include classification (environment-specific vs project-specific)
       - Use THIS section for complete architectural design

    IMPORTANT: Design architecture to satisfy ALL requirements in "Detailed Functional
    Requirements", not just the top 5 in "Requirement Pool". The top 5 are for
    prioritization guidance only.

    If "Requirements Classification Summary" exists:
    - Environment-Specific requirements: Design as configurable (externalize business rules)
    - Project-Specific requirements: Design as reusable core logic (generic patterns)

    ARCHITECTURE DESIGN DOS - Always Design These Patterns:

    1. Controller-Service Separation:
       - Design thin controllers (routing, validation, authorization only)
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
       - Design async processing (queue jobs) for large file operations (>1000 rows)
       - Design immediate response + status polling pattern
       - User receives 201 Created immediately, processing happens in background

    5. Validation & Authorization:
       - Design FormRequests for ALL validation (not in controllers)
       - Design Policies for ALL authorization checks
       - FormRequests check "can this be done", Policies check "can this user do it"

    6. Caching Strategy:
       - Design caching for reference data (currencies, purpose codes, country codes)
       - Design cache invalidation strategy
       - Cache lookups that don't change frequently

    7. API Versioning & Security:
       - Design versioned routes under /api/v1 with auth middleware
       - Design OAuth2/WSSE authentication integration
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
       - Don't design file processing without async jobs (causes timeouts)
       - Don't design reference data lookups without caching (causes performance issues)

    4. Response Anti-Patterns:
       - Don't design responses that expose internal model structure
       - Don't design inconsistent JSON shapes across endpoints
       - Don't design error responses that expose sensitive details or stack traces

    5. Security Anti-Patterns:
       - Don't design endpoints without authentication
       - Don't design data access without client_id filtering (tenant isolation)
       - Don't design authorization without Policies

    LARAVEL FILE STRUCTURE (Design Specifications):
    - routes/api.php: All API routes under /api/v1 prefix
    - app/Http/Controllers/Api/V1/: Thin controllers (routing only)
    - app/Http/Requests/: FormRequests (validation + authorization)
    - app/Services/: Business logic services (domain operations)
    - app/Models/: Eloquent models (data access + relationships)
    - database/migrations/: Schema with indexes, foreign keys, constraints
    - app/Http/Resources/: API Resources (response transformers)
    - app/Policies/: Authorization policies (permission checks)
    - app/Jobs/: Async jobs (queue processing)
    - app/Notifications/: User notifications

    VOLOPA-SPECIFIC REQUIREMENTS:
    - OAuth2 and WSSE authentication (custom middlewares)
    - Client data isolation (global scopes on models)
    - Currency-specific business rules (approval workflows)
    - Multi-tenant architecture (client_id on all tables)

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

        # Load architectural requirements (supports both new YAML and old JSON formats)
        self.requirements = self._load_requirements()
        self.requirements_format = self._detect_requirements_format()
        self.architect_seeds = self._load_architect_seeds()

        # Update constraints with loaded architectural patterns
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

    def _load_requirements(self) -> dict:
        """
        Load requirements from either new YAML format or old JSON format.

        Priority:
        1. Check for new ingestion output: industry/output/project_context.yaml
        2. Fall back to old JSON format: industry/requirements/architectural_requirements.json
        """
        base_path = Path(__file__).parent.parent

        # Option 1: New YAML format from ingestion
        yaml_path = base_path / "output" / "project_context.yaml"
        if yaml_path.exists():
            logger.info(f"Architect loading requirements from new YAML format: {yaml_path}")
            with open(yaml_path, 'r', encoding='utf-8') as f:
                return yaml.safe_load(f)

        # Option 2: Old JSON format (backward compatibility)
        json_path = base_path / "requirements" / "architectural_requirements.json"
        if json_path.exists():
            logger.info(f"Architect loading requirements from old JSON format: {json_path}")
            with open(json_path, 'r', encoding='utf-8') as f:
                return json.load(f)

        logger.warning("No architectural requirements file found")
        return {}

    def _detect_requirements_format(self) -> str:
        """Detect whether we're using new YAML or old JSON format."""
        if 'constraints' in self.requirements and isinstance(self.requirements.get('constraints'), list):
            return 'yaml'  # New format has 'constraints', 'flows', 'interfaces'
        elif 'architectural_dos' in self.requirements:
            return 'json'  # Old format has 'architectural_dos', 'architectural_donts'
        else:
            logger.warning("Unknown requirements format, defaulting to JSON")
            return 'json'

    def _load_architect_seeds(self) -> str:
        """Load architect_seeds.md file (new YAML format only)."""
        if self.requirements_format != 'yaml':
            return ""

        base_path = Path(__file__).parent.parent
        seeds_path = base_path / "output" / "architect_seeds.md"

        if seeds_path.exists():
            logger.info(f"Architect loading design seeds from: {seeds_path}")
            with open(seeds_path, 'r', encoding='utf-8') as f:
                return f.read()

        logger.warning("No architect_seeds.md file found")
        return ""

    def _update_constraints_from_requirements(self):
        """Inject loaded requirements and design patterns into role constraints."""

        if self.requirements_format == 'yaml':
            self._update_constraints_from_yaml()
        else:
            self._update_constraints_from_json()

    def _update_constraints_from_yaml(self):
        """Update constraints from new YAML format (TYPE + CONTEXT taxonomy)."""
        project_meta = self.requirements.get('project_metadata', {})

        # Extract architect's input (Constraint, Flow, Interface statements)
        constraints = self.requirements.get('constraints', [])
        flows = self.requirements.get('flows', [])
        interfaces = self.requirements.get('interfaces', [])
        design_mandates = self.requirements.get('design_mandates', [])

        # Count environment vs project specific
        env_constraints = sum(1 for c in constraints if c.get('context') == 'Environment-specific')
        proj_constraints = sum(1 for c in constraints if c.get('context') == 'Project-specific')

        # Build constraint text
        constraint_text = self._format_yaml_architectural_input(constraints, flows, interfaces, design_mandates)

        # Add architect seeds summary
        seeds_summary = ""
        if self.architect_seeds:
            seeds_summary = f"\n\nARCHITECT SEEDS LOADED:\n{self.architect_seeds[:500]}...\n(Full content available for reference)"

        self.constraints += f"""

LOADED REQUIREMENTS FROM TYPE + CONTEXT TAXONOMY:

Total Statements: {project_meta.get('total_statements', 0)}
Your Architectural Input:
  - Constraints: {len(constraints)} (business rules, limits, validation rules)
    * Environment-specific: {env_constraints} (externalize as config/business rules)
    * Project-specific: {proj_constraints} (universal patterns)
  - Flows: {len(flows)} (sequences, state transitions to support)
  - Interfaces: {len(interfaces)} (API/CSV/UI contracts to implement)
  - Design Mandates: {len(design_mandates)} (non-negotiable environment-specific patterns)

KEY INSTRUCTIONS:
- Design architecture to satisfy ALL Constraints
- Design services and jobs to support ALL Flows
- Design routes, controllers, API Resources for ALL Interfaces
- Implement Design Mandates exactly as specified (non-negotiable)
- Use architect_seeds.md for design patterns and open questions
- Environment-specific: Externalize in app/Services/Volopa/, config/, or database
- Project-specific: Implement as reusable logic in app/Services/Core/

ARCHITECTURAL INPUT DETAILS:
{constraint_text}{seeds_summary}
"""

    def _update_constraints_from_json(self):
        """Update constraints from old JSON format (NATURE taxonomy) - backward compatibility."""
        # Extract relevant sections
        meta = self.requirements.get('meta', {})
        mental_model = self.requirements.get('mental_model', {})
        arch_dos = self.requirements.get('architectural_dos', {})
        arch_donts = self.requirements.get('architectural_donts', {})

        # Build dynamic constraint text
        dos_text = self._format_architectural_patterns(arch_dos, pattern_type="DOS")
        donts_text = self._format_architectural_patterns(arch_donts, pattern_type="DONTS")

        # Append to existing constraints
        self.constraints += f"""

LOADED ARCHITECTURAL REQUIREMENTS FROM JSON (OLD FORMAT):

Source: {meta.get('source', 'N/A')}
Target Output: {meta.get('output', 'N/A')}

MENTAL MODEL (Loaded from JSON):
Flow: {mental_model.get('flow', 'N/A')}

Architectural Layers:
"""
        # Add layer details
        if 'layers' in mental_model:
            for layer_name, layer_info in mental_model['layers'].items():
                self.constraints += f"\n- {layer_name}: {layer_info.get('responsibility', 'N/A')}"
                self.constraints += f"\n  Pattern: {layer_info.get('design_pattern', 'N/A')}"

        self.constraints += f"""

ARCHITECTURAL DESIGN PATTERNS (DOS) - Loaded from JSON:
{dos_text}

ARCHITECTURAL ANTI-PATTERNS (DONTS) - Loaded from JSON:
{donts_text}
"""

    def _format_yaml_architectural_input(
        self,
        constraints: List[Dict],
        flows: List[Dict],
        interfaces: List[Dict],
        design_mandates: List[Dict]
    ) -> str:
        """Format architectural input from YAML format."""
        lines = []

        # Format Constraints
        if constraints:
            lines.append("\n=== CONSTRAINTS (Business Rules & Validation) ===\n")
            for stmt in constraints[:10]:  # Show first 10
                lines.append(f"{stmt['id']} [{stmt.get('priority', 'P0')}]: {stmt['statement']}")
                lines.append(f"  Context: {stmt['context']}")
                if stmt.get('enforcement'):
                    lines.append(f"  Enforcement: {stmt['enforcement']}")
                if stmt.get('validation_rule'):
                    lines.append(f"  Rule: {stmt['validation_rule']}")
                lines.append("")
            if len(constraints) > 10:
                lines.append(f"... and {len(constraints) - 10} more constraints\n")

        # Format Flows
        if flows:
            lines.append("\n=== FLOWS (Sequences & State Transitions) ===\n")
            for stmt in flows[:5]:  # Show first 5
                lines.append(f"{stmt['id']}: {stmt['statement']}")
                lines.append(f"  Context: {stmt['context']}")
                if stmt.get('trigger'):
                    lines.append(f"  Trigger: {stmt['trigger']}")
                if stmt.get('outcome'):
                    lines.append(f"  Outcome: {stmt['outcome']}")
                lines.append("")
            if len(flows) > 5:
                lines.append(f"... and {len(flows) - 5} more flows\n")

        # Format Interfaces
        if interfaces:
            lines.append("\n=== INTERFACES (API/CSV/UI Contracts) ===\n")
            for stmt in interfaces:
                lines.append(f"{stmt['id']}: {stmt['statement']}")
                lines.append(f"  Context: {stmt['context']}")
                if stmt.get('interface_type'):
                    lines.append(f"  Type: {stmt['interface_type']}")
                lines.append("")

        # Format Design Mandates
        if design_mandates:
            lines.append("\n=== DESIGN MANDATES (Non-Negotiable) ===\n")
            for stmt in design_mandates:
                lines.append(f"{stmt['id']}: {stmt['statement']}")
                lines.append(f"  Rationale: {stmt['context_rationale']}")
                lines.append("  ** MUST IMPLEMENT EXACTLY AS SPECIFIED **")
                lines.append("")

        return '\n'.join(lines)

    def _format_architectural_patterns(self, patterns: dict, pattern_type: str) -> str:
        """Format architectural DOS or DONTS patterns as text (old JSON format)"""
        lines = []

        for category_key, category_data in patterns.items():
            if isinstance(category_data, dict) and 'category' in category_data:
                lines.append(f"\n### {category_data['category']}")

                if 'requirements' in category_data:
                    for req in category_data['requirements']:
                        lines.append(f"\n**{req['id']}**: {req['requirement']}")
                        lines.append(f"Rationale: {req['rationale']}")

                        if 'design_specification' in req:
                            lines.append(f"Design Spec: {req['design_specification']}")

                        if 'example' in req:
                            lines.append(f"Example: {req['example']}")

                        if 'volopa_specific' in req:
                            lines.append(f"Volopa-Specific: {req['volopa_specific']}")

        return '\n'.join(lines)


# Placeholder for future customization
# TODO: Add Laravel-specific system design templates
# TODO: Add Mermaid diagram generators for Laravel patterns
# TODO: Add database schema design helpers (migration templates)
# TODO: Integrate Volopa-specific patterns (WSSE auth, approval workflows)
# TODO: Add API specification generator (OpenAPI/Swagger)
