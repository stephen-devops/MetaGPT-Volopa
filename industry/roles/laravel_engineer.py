#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_engineer.py
@Desc    : Laravel Engineer role for Volopa Mass Payments system
"""

from pathlib import Path
from metagpt.roles.engineer import Engineer

class LaravelEngineer(Engineer):
    """
    Laravel Engineer specialized for implementing Laravel API code following DOS/DONTS.

    Responsibilities:
    - Write Laravel controllers (thin, proper status codes)
    - Write FormRequests (validation + policy authorization)
    - Write services (business logic with transactions)
    - Write Eloquent models (relationships, casts, fillable)
    - Write migrations (schema with indexes and foreign keys)
    - Write API Resources (response transformers)
    - Write feature tests (assert JSON, status codes, DB state)

    Allocated Intents (from massPaymentsVolopaAgents.txt):
    - createPaymentInstructions: Implement payment creation logic
    - approvePaymentFile: Implement approval workflow with authorization
    - updateFileStatus: Implement state transition logic
    - redirectPaymentConfirmation: Implement routing logic
    - redirectDraftPayments: Implement routing logic

    ReAct + RAG Integration:
    - Uses ReAct mode for dynamic reasoning about implementation
    - Uses SearchCodeBase action to query Volopa Laravel examples (when implemented)
    - Applies DOS/DONTS constraints during _think() phase
    - Self-corrects based on validation results
    """

    use_fixed_sop: bool = True
    name: str = "Lucas"
    profile: str = "Laravel API Developer"
    goal: str = "Write Laravel code following DOS/DONTS patterns and Volopa conventions"

    # ✅ CRITICAL: Full DOS/DONTS embedded as constraints
    constraints: str = """
CRITICAL OUTPUT FORMAT REQUIREMENT:
- Development Plan: List ONLY filenames to be created (e.g., "app/Models/MassPaymentFile.php")
- Incremental Change: For EACH file, provide ONLY this simple format:
  "app/Models/MassPaymentFile.php: Create Eloquent model with relationships"

DO NOT generate actual code, diff blocks, or full file contents in the Incremental Change section.
Keep each Incremental Change entry to ONE line with filename and brief description only.

Example correct format:
{
  "Development Plan": [
    "app/Models/MassPaymentFile.php",
    "app/Services/ValidationService.php"
  ],
  "Incremental Change": [
    "app/Models/MassPaymentFile.php: Eloquent model with UUID, relationships, soft deletes",
    "app/Services/ValidationService.php: Validation methods for CSV and payment data"
  ]
}

MENTAL MODEL:
Client → route (versioned, throttled, auth) → controller → FormRequest
(validation + policy) → service/model (domain logic, transactions) →
API Resource (shape output) → JSON with correct status codes and error format

DOS - Always Follow These Practices:
- Add routes to routes/api.php under /v1 prefix with auth middleware
- Keep route names consistent (e.g., api.v1.mass-payments.upload)
- Write migrations with proper indexes, unique constraints, and foreign keys
- Add Eloquent model relationships (hasMany, belongsTo, etc.)
- Validate all request content in FormRequests (not controllers)
- Use Policies or Gates for authorization checks
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
  * 422: Validation failed
- Create API Resources to shape responses and hide internal fields
- Add pagination using Resource::collection($query->paginate())
- Write feature tests that assert JSON shape, status codes, DB state, and policy enforcement
- Volopa uses custom authorization middlewares for OAuth2 access tokens or WSSE credentials

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
- Don't let file uploads bloat the API process (use queues for large files)
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

        # Load both architectural and technical requirements from NLP markdown files
        self.architectural_requirements = self._load_architectural_requirements()
        self.technical_requirements = self._load_technical_requirements()

        # Update constraints with loaded patterns from both files
        self._update_constraints_from_requirements()

        # Set incremental mode to False to skip WriteCodePlanAndChange phase
        # This avoids JSON parsing errors with large code blocks
        # The Engineer will go straight to WriteCode for each file
        self.config.inc = False

        # Engineer needs multiple loops to write all files
        # Set max_react_loop high enough to write all files (35 files in task list)
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=50)

        # Note: Nested directory fix moved to _think() override below
        # (repo doesn't exist during __init__, it's created in _think())

        # Note: Engineer will receive task breakdown from ProjectManager
        # and process files in dependency order:
        # 1. Migrations
        # 2. Models
        # 3. Policies
        # 4. FormRequests
        # 5. Services
        # 6. Controllers
        # 7. Routes
        # 8. Resources
        # 9. Tests

        # Each WriteCode action will have access to:
        # - CodingContext with design_doc, task_doc, code_doc
        # - Constraints (DOS/DONTS) via self.constraints → self.llm.system_prompt
        # - RAG examples (when SearchCodeBase is implemented)

    def _load_architectural_requirements(self) -> dict:
        """Load architectural_requirements_nlp.md file and parse patterns"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "architectural_requirements_nlp.md"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            content = f.read()

        # Simple extraction of key info from NLP markdown
        return {
            "meta": {
                "source": "architectural_requirements_nlp.md",
                "title": "Laravel Architectural Requirements"
            },
            "mental_model": {
                "flow": "Client → route → controller → FormRequest → service/model → API Resource → JSON",
                "layers": {
                    "routing_layer": {"responsibility": "API versioning, auth", "design_pattern": "Versioned routes /api/v1"},
                    "controller_layer": {"responsibility": "Route requests", "design_pattern": "Thin controllers"},
                    "validation_layer": {"responsibility": "Validation & auth", "design_pattern": "FormRequests & Policies"},
                    "domain_layer": {"responsibility": "Business logic", "design_pattern": "Services & Models"},
                    "response_layer": {"responsibility": "Output transform", "design_pattern": "API Resources"}
                }
            },
            "content": content  # Store full content for reference
        }

    def _load_technical_requirements(self) -> dict:
        """Load technical_requirements_nlp.md file and parse patterns"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "technical_requirements_nlp.md"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            content = f.read()

        # Simple extraction of key info from NLP markdown
        return {
            "meta": {
                "source": "technical_requirements_nlp.md",
                "title": "Laravel Technical Implementation Requirements"
            },
            "content": content  # Store full content for reference
        }

    def _update_constraints_from_requirements(self):
        """Inject loaded architectural and technical patterns into role constraints"""

        # Extract metadata
        arch_meta = self.architectural_requirements['meta']
        mental_model = self.architectural_requirements['mental_model']
        tech_meta = self.technical_requirements['meta']

        # For NLP markdown files, we include key summary info only
        # The full content is available if needed for RAG/search
        self.constraints += f"""

LOADED REQUIREMENTS FROM NLP MARKDOWN FILES:

=== ARCHITECTURAL REQUIREMENTS (Design Patterns) ===
Source: {arch_meta['source']}

MENTAL MODEL:
Flow: {mental_model['flow']}

Architectural Layers:
"""
        # Add layer details
        for layer_name, layer_info in mental_model['layers'].items():
            self.constraints += f"\n- {layer_name}: {layer_info['responsibility']} | {layer_info['design_pattern']}"

        self.constraints += f"""

All 110 architectural requirements loaded from NLP markdown.
Requirements cover: API structure, database design, validation & auth,
controller-service separation, transactions, N+1 prevention, response design,
async processing, caching, security, and anti-patterns to avoid.

=== TECHNICAL REQUIREMENTS (Implementation Syntax) ===
Source: {tech_meta['source']}

All 138 technical implementation requirements loaded from NLP markdown.
Requirements cover: Route syntax, migrations, Eloquent models, FormRequests,
Policies, controllers, services, API Resources, queries, jobs, cache, tests,
error handling, logging, file structure, and implementation anti-patterns.

The full content of both files is available for reference during code generation.
"""

    async def _think(self) -> bool:
        """Override _think to ensure correct src_path before code generation."""
        # Call parent _think first - this creates self.repo and may set nested path
        result = await super()._think()

        # FIX: Correct the src_path if it's nested (AFTER parent sets it)
        from pathlib import Path
        from metagpt.logs import logger

        if hasattr(self, 'repo') and self.repo:
            workdir = Path(self.repo.workdir)
            current_src = self.repo.src_relative_path

            # Check if we have nested structure (workdir/projectname/projectname)
            if current_src and current_src.name == workdir.name:
                # We have nested structure, correct it to use workspace root
                # Use "." to indicate current directory (workdir itself)
                self.repo.with_src_path(Path("."))
                logger.info(f"LaravelEngineer: Corrected nested src_path from '{current_src}' to '.' (workspace root)")

                # Delete the empty nested directory if it exists
                nested_dir = workdir / current_src
                if nested_dir.exists() and nested_dir.is_dir():
                    import shutil
                    try:
                        shutil.rmtree(nested_dir)
                        logger.info(f"LaravelEngineer: Removed empty nested directory '{nested_dir}'")
                    except Exception as e:
                        logger.warning(f"LaravelEngineer: Could not remove nested directory '{nested_dir}': {e}")

                # Also ensure .src_workspace exists with correct content
                src_workspace_file = workdir / ".src_workspace"
                src_workspace_file.write_text(".")
                logger.info(f"LaravelEngineer: Created/updated .src_workspace file")

        return result


# Placeholder for future RAG integration
# TODO: Implement SearchCodeBase action for querying Volopa Laravel examples
# TODO: Add RAG query generation based on file type and intent
# TODO: Integrate with AWS OpenSearch (Volopa's knowledge base)
# TODO: Add code validation against DOS/DONTS patterns
# TODO: Add self-correction loop for constraint violations
# TODO: Add Laravel-specific code templates (controller, service, model, etc.)

# Example RAG queries for allocated intents:
# - "Laravel service creating payment records with DB transaction"
# - "Laravel controller approval workflow with policy authorization"
# - "Laravel model with status enum and state transitions"
# - "Laravel FormRequest with conditional validation rules"
# - "Laravel API Resource with nested relationships and eager loading"
