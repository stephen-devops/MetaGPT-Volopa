#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_architect.py
@Desc    : Laravel Architect role for Volopa Mass Payments system
"""

import json
import re
from pathlib import Path
from typing import Dict, Any
from metagpt.roles.architect import Architect


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
    MENTAL MODEL (Critical for All Design Decisions):
    Client → route (versioned, throttled, auth) → controller → FormRequest
    (validation + policy) → service/model (domain logic, transactions) →
    API Resource (shape output) → JSON with correct status codes and error format

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

        # Load architectural requirements from NLP markdown file
        self.requirements = self._load_requirements()

        # Update constraints with loaded architectural patterns
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

    def _load_requirements(self) -> dict:
        """Load architectural_requirements_nlp.md file and parse architectural patterns"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "architectural_requirements_nlp.md"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            content = f.read()

        return self._parse_architectural_requirements(content)

    def _parse_architectural_requirements(self, content: str) -> dict:
        """
        Parse architectural requirements from NLP markdown file.

        Extracts:
        - Project metadata
        - Mental model and architectural layers
        - Sections with architectural patterns
        - Requirements numbered 1-110
        """
        # Extract title and metadata
        title_match = re.search(r'^#\s+(.+)$', content, re.MULTILINE)
        title = title_match.group(1).strip() if title_match else "Laravel Architectural Requirements"

        # Extract version
        version_match = re.search(r'\*\*Version:\*\*\s+([\d.]+)', content)
        version = version_match.group(1) if version_match else "1.0"

        # Extract mental model
        mental_model_match = re.search(
            r'\*\*Client → .+\*\*',
            content
        )
        mental_model_flow = mental_model_match.group(0) if mental_model_match else ""

        # Parse sections and requirements
        sections = {}
        requirement_pattern = re.compile(r'^\*\*(\d+)\.\*\*\s+(.+)$', re.MULTILINE)
        section_pattern = re.compile(r'^##\s+(\d+)\.\s+(.+)$', re.MULTILINE)
        subsection_pattern = re.compile(r'^###\s+([\d.]+)\s+(.+)$', re.MULTILINE)

        # Find all main sections
        for section_match in section_pattern.finditer(content):
            section_num = section_match.group(1)
            section_name = section_match.group(2).strip()
            sections[f"section_{section_num}"] = {
                "category": section_name,
                "requirements": []
            }

        # Find all requirements and associate with sections
        lines = content.split('\n')
        current_section_key = None
        current_subsection = None

        for i, line in enumerate(lines):
            # Check for section header
            section_match = section_pattern.match(line)
            if section_match:
                section_num = section_match.group(1)
                current_section_key = f"section_{section_num}"
                current_subsection = None
                continue

            # Check for subsection header
            subsection_match = subsection_pattern.match(line)
            if subsection_match:
                current_subsection = subsection_match.group(2).strip()
                continue

            # Check for requirement
            req_match = requirement_pattern.match(line)
            if req_match and current_section_key:
                req_num = req_match.group(1)
                req_text = req_match.group(2).strip()

                sections[current_section_key]["requirements"].append({
                    "id": f"ARCH-{req_num}",
                    "number": req_num,
                    "requirement": req_text,
                    "subsection": current_subsection,
                    "rationale": "",  # Not explicitly extracted from NLP
                    "design_specification": req_text  # Use requirement text as spec
                })

        # Build structured requirements dictionary
        return {
            "meta": {
                "title": title,
                "version": version,
                "source": "industry/dos_and_donts.pdf + industry/Volopa - Proposed Architecture.pdf",
                "target_agent": "LaravelArchitect",
                "output": "docs/system_design/volopa_mass_payments.md"
            },
            "mental_model": {
                "flow": mental_model_flow,
                "layers": {
                    "routing_layer": {
                        "responsibility": "API versioning, rate limiting, authentication",
                        "design_pattern": "Versioned routes under /api/v1 with middleware"
                    },
                    "controller_layer": {
                        "responsibility": "Route requests to domain logic",
                        "design_pattern": "Thin controllers - no business logic"
                    },
                    "validation_layer": {
                        "responsibility": "Input validation and authorization",
                        "design_pattern": "FormRequests for validation, Policies for authorization"
                    },
                    "domain_layer": {
                        "responsibility": "Business logic, data access, transactions",
                        "design_pattern": "Services for complex logic, Models for data access"
                    },
                    "response_layer": {
                        "responsibility": "Output transformation and formatting",
                        "design_pattern": "API Resources to shape all responses"
                    }
                }
            },
            "architectural_dos": sections,
            "architectural_donts": {}  # Anti-patterns are mixed in sections 11
        }

    def _update_constraints_from_requirements(self):
        """Inject loaded architectural patterns into role constraints"""

        # Extract relevant sections
        meta = self.requirements['meta']
        mental_model = self.requirements['mental_model']
        arch_dos = self.requirements.get('architectural_dos', {})
        arch_donts = self.requirements.get('architectural_donts', {})

        # Build dynamic constraint text
        dos_text = self._format_architectural_patterns(arch_dos, pattern_type="DOS")
        donts_text = self._format_architectural_patterns(arch_donts, pattern_type="DONTS")

        # Append to existing constraints
        self.constraints += f"""

LOADED ARCHITECTURAL REQUIREMENTS FROM NLP MARKDOWN:

Source: {meta['source']}
Target Output: {meta['output']}

MENTAL MODEL (Loaded from NLP):
Flow: {mental_model['flow']}

Architectural Layers:
"""
        # Add layer details
        for layer_name, layer_info in mental_model['layers'].items():
            self.constraints += f"\n- {layer_name}: {layer_info['responsibility']}"
            self.constraints += f"\n  Pattern: {layer_info['design_pattern']}"

        self.constraints += f"""

ARCHITECTURAL DESIGN PATTERNS (DOS) - Loaded from NLP:
{dos_text}

ARCHITECTURAL ANTI-PATTERNS (DONTS) - Loaded from NLP:
{donts_text}
"""

    def _format_architectural_patterns(self, patterns: dict, pattern_type: str) -> str:
        """Format architectural DOS or DONTS patterns as text"""
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
