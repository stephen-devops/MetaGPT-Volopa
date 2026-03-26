#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_product_manager.py
@Desc    : Laravel Product Manager role for Volopa OOP Expense system
"""

from metagpt.actions.prepare_documents import PrepareDocuments
from metagpt.roles.product_manager import ProductManager
from metagpt.utils.common import any_to_name, any_to_str

from industry.actions.laravel_write_prd import LaravelWritePRD
from industry.utils.context_reader import ContextReader


class LaravelProductManager(ProductManager):
    """
    Laravel Product Manager specialized for software system product requirements.

    Responsibilities:
    - Define business requirements for the system Laravel APIs
    - Create PRDs with Laravel-specific technical specifications
    - Specify API endpoints, validation rules, and business logic
    - Define user stories and acceptance criteria
    """

    use_fixed_sop: bool = True
    name: str = "Joshua"
    profile: str = "Laravel Product Manager"
    goal: str = "Create comprehensive PRD for Laravel PHP system from YAML context specifications"

    constraints: str = """
    - Use same language as user requirements
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

        # Replace upstream WritePRD with LaravelWritePRD (custom ActionNodes)
        if self.use_fixed_sop:
            self.set_actions([PrepareDocuments(send_to=any_to_str(self)), LaravelWritePRD])
            self.todo_action = any_to_name(LaravelWritePRD)

        # Build constraints from YAML context (local var to avoid Pydantic serialization issues)
        self._update_constraints_from_context(ContextReader())

        # With use_fixed_sop=True, the role uses BY_ORDER mode
        # Set max_react_loop to 1 to execute actions once and stop
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

        # Track if we've already published WritePRD to avoid duplicate execution
        self._prd_published = False

    def _update_constraints_from_context(self, context_reader: ContextReader):
        """Inject YAML context into role constraints."""

        lines = []

        # Expected output sections
        lines.append("")
        lines.append("EXPECTED PRD OUTPUT SECTIONS:")
        lines.append("- Business Requirements (per module)")
        lines.append("- User Stories and Acceptance Criteria")
        lines.append("- Role-Permission Matrix")
        lines.append("- API Endpoint Specifications")
        lines.append("- Validation Rules (CSV and single entry)")
        lines.append("- Error Handling & Response Formats")
        lines.append("- Security and Access Control Requirements")

        # === YAML Context (reconciled, authoritative domain data) ===
        lines.append("")
        lines.append("=" * 60)
        lines.append("CONTEXT FROM YAML (authoritative source for all domain data):")
        lines.append("=" * 60)
        lines.append("")
        lines.append(context_reader.get_mental_model())
        lines.append("")
        lines.append(context_reader.get_dos_and_donts())
        lines.append("")
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_database_tables("names"))
        lines.append("")
        lines.append(context_reader.get_unresolved_decisions())
        lines.append("")
        lines.append(context_reader.get_project_intent())
        lines.append("")
        lines.append(context_reader.get_project_requirements())
        lines.append("")
        lines.append(context_reader.get_project_constraints())
        lines.append("")
        lines.append(context_reader.get_permission_matrix())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_existing_user_roles())
        lines.append("")
        lines.append(context_reader.get_existing_platform_services())

        self.constraints += '\n'.join(lines)

    async def _think(self) -> bool:
        """Override _think to prevent duplicate PRD generation in multi-round workflows."""
        if self._prd_published:
            self.rc.todo = None
            return False
        result = await super()._think()
        return result

    async def _act(self) -> None:
        """Override _act to mark PRD as published after execution."""
        result = await super()._act()

        from metagpt.actions import WritePRD
        if isinstance(self.rc.todo, WritePRD) or (hasattr(self.rc, 'memory') and
            any(msg.cause_by == WritePRD.__name__ for msg in self.rc.memory.get())):
            self._prd_published = True

        return result
