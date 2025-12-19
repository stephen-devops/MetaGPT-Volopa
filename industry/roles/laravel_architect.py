#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_architect.py
@Desc    : Laravel Architect role for Volopa Mass Payments system
"""

import json
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

        # Load architectural requirements from JSON
        self.requirements = self._load_requirements()

        # Update constraints with loaded architectural patterns
        self._update_constraints_from_requirements()

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

    def _load_requirements(self) -> dict:
        """Load architectural_requirements.json file"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "architectural_requirements.json"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            return json.load(f)

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

LOADED ARCHITECTURAL REQUIREMENTS FROM JSON:

Source: {meta['source']}
Target Output: {meta['output']}

MENTAL MODEL (Loaded from JSON):
Flow: {mental_model['flow']}

Architectural Layers:
"""
        # Add layer details
        for layer_name, layer_info in mental_model['layers'].items():
            self.constraints += f"\n- {layer_name}: {layer_info['responsibility']}"
            self.constraints += f"\n  Pattern: {layer_info['design_pattern']}"

        self.constraints += f"""

ARCHITECTURAL DESIGN PATTERNS (DOS) - Loaded from JSON:
{dos_text}

ARCHITECTURAL ANTI-PATTERNS (DONTS) - Loaded from JSON:
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
