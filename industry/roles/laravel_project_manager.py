#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_project_manager.py
@Desc    : Laravel Project Manager role for Volopa Mass Payments system
"""

import json
import re
from pathlib import Path
from typing import Dict, Any
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
    name: str = "Manuel"
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

        # Load functional requirements from NLP markdown file for task breakdown guidance
        self.requirements = self._load_requirements()

        # Update constraints with task breakdown data
        self._update_constraints_from_requirements()

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

    def _load_requirements(self) -> dict:
        """Load user_requirements_nlp.md file and parse functional requirements"""
        requirements_path = Path(__file__).parent.parent / "requirements" / "user_requirements_nlp.md"

        with open(requirements_path, 'r', encoding='utf-8') as f:
            content = f.read()

        return self._parse_nlp_requirements(content)

    def _parse_nlp_requirements(self, content: str) -> dict:
        """
        Parse natural language requirements from markdown file.

        Extracts:
        - Project metadata from header
        - Sections and their requirements
        - Requirement numbers and text
        """
        # Extract project title
        title_match = re.search(r'^#\s+(.+)$', content, re.MULTILINE)
        project_name = title_match.group(1).strip() if title_match else "Volopa Mass Payments"

        # Extract version
        version_match = re.search(r'\*\*Version:\*\*\s+([\d.]+)', content)
        version = version_match.group(1) if version_match else "4.0"

        # Parse sections and requirements
        sections = {}
        requirement_pattern = re.compile(r'^\*\*(\d+)\.\*\*\s+(.+)$', re.MULTILINE)
        section_pattern = re.compile(r'^##\s+(\d+)\.\s+(.+)$', re.MULTILINE)
        subsection_pattern = re.compile(r'^###\s+([\d.]+)\s+(.+)$', re.MULTILINE)

        # Find all sections
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
                    "id": f"REQ-{req_num}",
                    "number": req_num,
                    "text": req_text,
                    "subsection": current_subsection
                })

        # Build structured requirements dictionary
        return {
            "project_metadata": {
                "project_name": project_name,
                "version": version,
                "framework": "Laravel",
                "php_version": "8.2",
                "max_file_capacity": 10000,
                "api_prefix": "/api/v1",
                "source_file": "user_requirements_nlp.md"
            },
            "summary_statistics": {
                "total_functional_requirements": len(sections),
                "total_requirements": sum(len(s["requirements"]) for s in sections.values()),
                "estimated_tasks": "~40-50",
                "estimated_files": "~40"
            },
            "functional_requirements": sections
        }

    def _update_constraints_from_requirements(self):
        """Inject task breakdown guidance from functional requirements"""

        # Extract sections
        project_meta = self.requirements['project_metadata']
        stats = self.requirements['summary_statistics']
        frs = self.requirements['functional_requirements']

        # Build task mapping
        task_mapping = self._build_task_mapping(frs)

        # Append to existing constraints
        self.constraints += f"""

LOADED FUNCTIONAL REQUIREMENTS FROM NLP MARKDOWN:

Project: {project_meta['project_name']} v{project_meta['version']}
Framework: {project_meta['framework']} (PHP {project_meta['php_version']})
Max Capacity: {project_meta['max_file_capacity']} payment rows per file
API Prefix: {project_meta['api_prefix']}

Task Breakdown Statistics:
- Total Requirement Sections: {stats['total_functional_requirements']}
- Total Requirements: {stats['total_requirements']}
- Estimated Tasks to Create: {stats['estimated_tasks']}
- Estimated Files to Generate: {stats['estimated_files']}

Task Mapping Guide (Requirements by Section):
{task_mapping}
"""

    def _build_task_mapping(self, frs: dict) -> str:
        """Build mapping of requirements to implementation tasks"""
        lines = []

        for section_id, section_data in frs.items():
            lines.append(f"\n### {section_data['category']}")

            # Group requirements by subsection
            subsections = {}
            for req in section_data['requirements']:
                subsection = req.get('subsection', 'General')
                if subsection not in subsections:
                    subsections[subsection] = []
                subsections[subsection].append(req)

            # Format requirements by subsection
            for subsection, reqs in subsections.items():
                if subsection and subsection != 'General':
                    lines.append(f"\n#### {subsection}")

                for req in reqs:
                    lines.append(f"  {req['id']}: {req['text']}")

        return '\n'.join(lines)


# Placeholder for future customization
# TODO: Add Laravel-specific dependency analyzer
# TODO: Add task ordering algorithm (topological sort for dependencies)
# TODO: Add Laravel package recommender based on system design
# TODO: Add OpenAPI spec generator from system design
# TODO: Integrate Volopa-specific shared knowledge (WSSE auth, approval patterns)
