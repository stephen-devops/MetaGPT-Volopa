#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_project_manager.py
@Desc    : Laravel Project Manager role for Volopa OOP Expense system
"""

from metagpt.actions.design_api import WriteDesign
from metagpt.roles.project_manager import ProjectManager

from industry.actions.laravel_design_api import LaravelWriteDesign
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
    Break down software system design into dependency-ordered tasks following PRD/technical design and Laravel conventions
    """

    constraints: str = "use the same language as user requirement"

    def __init__(self, **kwargs):
        """
        Initialize Laravel Project Manager.

        Inherits from ProjectManager which provides:
        - WriteTasks action
        - Tool access: RoleZero, Editor
        - Watches: WriteDesign messages from Architect
        """
        super().__init__(**kwargs)

        # Watch both core WriteDesign and our LaravelWriteDesign so the pipeline continues
        self._watch([WriteDesign, LaravelWriteDesign])

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
        lines.append(context_reader.get_interfaces_summary())
        lines.append("")
        lines.append(context_reader.get_unresolved_decisions())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_platform_decisions())
        lines.append("")
        lines.append(context_reader.get_existing_user_roles())
        lines.append("")
        lines.append(context_reader.get_laravel_task_conventions())

        self.constraints += '\n'.join(lines)
