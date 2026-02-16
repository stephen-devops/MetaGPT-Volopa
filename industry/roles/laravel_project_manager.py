#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_project_manager.py
@Desc    : Laravel Project Manager role for Volopa OOP Expense system
"""

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

    Domain knowledge is loaded exclusively from YAML context specifications.
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

    TABLE DISAMBIGUATION:
    Tables with similar columns across modules are DISTINCT entities with different column types,
    status enums, and relationships. Each MUST have its own Model and Migration.
    Do NOT merge tables from different modules.

    DERIVATION RULES (derive all entity names from YAML context):
    - Create ONE Migration per new table
    - Create ONE Eloquent Model per new table (snake_case table -> PascalCase model)
    - Create ONE Policy per module that needs authorization
    - Create ONE FormRequest per API endpoint
    - Create ONE Service per domain operation (CRUD, validation, FX conversion, source config)
    - Create ONE API Resource per model returned in responses
    - Create ONE Controller per API resource group (thin, delegates to services)
    - Create Queue Jobs only where context specifies background processing

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

        # Build constraints from YAML context (local var to avoid Pydantic serialization issues)
        self._update_constraints_from_context(ContextReader())

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

    def _update_constraints_from_context(self, context_reader: ContextReader):
        """Inject YAML context into role constraints."""

        lines = []
        lines.append("")
        lines.append("=" * 60)
        lines.append("CONTEXT FROM YAML (authoritative source for all domain data):")
        lines.append("=" * 60)
        lines.append("")
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_database_tables("summary"))
        lines.append("")
        lines.append(context_reader.get_components_to_build())
        lines.append("")
        lines.append(context_reader.get_interfaces_summary())
        lines.append("")
        lines.append(context_reader.get_project_constraints())
        lines.append("")
        lines.append(context_reader.get_dos_and_donts())
        lines.append("")
        lines.append(context_reader.get_unresolved_decisions())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_platform_decisions())
        lines.append("")
        lines.append(context_reader.get_platform_constraints())
        lines.append("")
        lines.append(context_reader.get_existing_user_roles())

        self.constraints += '\n'.join(lines)
