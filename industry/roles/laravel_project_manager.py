#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_project_manager.py
@Desc    : Laravel Project Manager role for Volopa Mass Payments system
"""

from metagpt.roles.project_manager import ProjectManager


class LaravelProjectManager(ProjectManager):
    """
    Laravel Project Manager specialized for task breakdown and dependency analysis.

    Responsibilities:
    - Break down system design into dependency-ordered tasks
    - Analyze Laravel file dependencies (migrations → models → services → controllers)
    - Generate task list with proper execution order
    - Document shared knowledge and Laravel conventions
    - Identify required Laravel packages (composer dependencies)

    Output Structure:
    - Required packages (composer.json dependencies)
    - Logic Analysis (file-by-file with dependencies)
    - Task list (dependency-ordered filenames)
    - Full API spec (OpenAPI 3.0)
    - Shared Knowledge (Laravel conventions + DOS/DONTS)
    """

    use_fixed_sop: bool = True
    name: str = "LaravelPM_Eve"
    profile: str = "Laravel Project Manager"
    goal: str = """
    Break down Laravel system design into dependency-ordered tasks following
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
       P1: Models (Eloquent) - depend on migrations
       P2: Policies (authorization) - depend on models
       P3: FormRequests (validation) - depend on policies
       P4: Config files (config/*.php) - no dependencies
       P5: Services (business logic) - depend on models
       P6: Queue Jobs - depend on services
       P7: Notifications - depend on models
       P8: Middleware - no dependencies
       P9: Resources (transformers) - depend on models
       P10: Controllers (thin layer) - depend on services + FormRequests + Resources
       P11: Routes (routes/api.php) - depend on controllers
       P12: Tests (feature tests) - depend on all application code

    3. Parallel Development Opportunities (note in Logic Analysis):
       - Multiple migrations (if no FK dependencies)
       - Multiple models (if no relationships)
       - Multiple FormRequests
       - Multiple services (if no inter-service deps)
       - Multiple API Resources

    4. Critical Dependencies (document in Logic Analysis):
       - Controllers depend on: Services + FormRequests + Resources
       - Services depend on: Models
       - FormRequests depend on: Policies (for authorize method)
       - Policies depend on: Models
       - Tests depend on: All application code

    5. Required Composer Packages:
       - league/csv (for CSV processing)
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

        # With use_fixed_sop=True, set max_react_loop to 1 to execute actions once
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

        # Output will be a JSON document with:
        # {
        #   "Required packages": ["laravel/framework:^10.0", ...],
        #   "Logic Analysis": [
        #     ["file.php", "Description with dependencies"]
        #   ],
        #   "Task list": ["file1.php", "file2.php", ...],  # Dependency order
        #   "Full API spec": "openapi: 3.0.0\n...",
        #   "Shared Knowledge": "All services use transactions..."
        # }


# Placeholder for future customization
# TODO: Add Laravel-specific dependency analyzer
# TODO: Add task ordering algorithm (topological sort for dependencies)
# TODO: Add Laravel package recommender based on system design
# TODO: Add OpenAPI spec generator from system design
# TODO: Integrate Volopa-specific shared knowledge (WSSE auth, approval patterns)
