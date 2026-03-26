#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_architect.py
@Desc    : Laravel Architect role for Volopa OOP Expense system
"""

from metagpt.actions.write_prd import WritePRD
from metagpt.roles.architect import Architect

from industry.actions.laravel_design_api import LaravelWriteDesign
from industry.actions.laravel_write_prd import LaravelWritePRD
from industry.utils.context_reader import ContextReader


class LaravelArchitect(Architect):
    """
    Laravel Architect specialized for system design.

    Responsibilities:
    - Design Laravel API architecture (routes, controllers, services, models)
    - Define data models and database schema (migrations, relationships)
    - Design service layer and business logic separation
    - Create API endpoint specifications
    - Design validation and authorization architecture

    Domain knowledge is loaded exclusively from YAML context specifications.
    """

    use_fixed_sop: bool = True
    name: str = "Danny"
    profile: str = "Laravel System Architect"
    goal: str = "Design Laravel API system architecture following best practices from YAML context specifications"
    constraints: str = (
        "Make sure the Laravel architecture is simple enough and use appropriate open source libraries."
        "Use the same language as user requirements"
    )

    def __init__(self, **kwargs):
        """
        Initialize Laravel Architect.

        Inherits from Architect which provides:
        - WriteDesign action
        - Tool access: RoleZero, Editor, Terminal
        - Watches: WritePRD messages from ProductManager
        """
        super().__init__(**kwargs)

        # Use Laravel-specific WriteDesign
        self.set_actions([LaravelWriteDesign])

        # Watch for both upstream WritePRD and custom LaravelWritePRD messages
        self._watch({WritePRD, LaravelWritePRD})

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
        lines.append(context_reader.get_mental_model())
        lines.append("")
        lines.append(context_reader.get_platform_constraints())
        lines.append("")
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_database_tables("full"))
        lines.append("")
        lines.append(context_reader.get_interfaces_summary())
        lines.append("")
        lines.append(context_reader.get_flows())
        lines.append("")
        lines.append(context_reader.get_unresolved_decisions())
        lines.append("")
        lines.append(context_reader.get_project_intent())
        lines.append("")
        lines.append(context_reader.get_project_constraints())
        lines.append("")
        lines.append(context_reader.get_csv_column_schema())
        lines.append("")
        lines.append(context_reader.get_api_routes())
        lines.append("")
        lines.append(context_reader.get_response_schemas())
        lines.append("")
        lines.append(context_reader.get_permission_matrix())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_fx_infrastructure())
        lines.append("")
        lines.append(context_reader.get_design_principles())
        lines.append("")
        lines.append(context_reader.get_platform_decisions())
        lines.append("")
        lines.append(context_reader.get_existing_tables_and_models())
        lines.append("")
        lines.append(context_reader.get_existing_user_roles())
        lines.append("")
        lines.append(context_reader.get_existing_platform_services())
        lines.append("")
        lines.append(context_reader.get_fx_query_contract())
        lines.append("")
        lines.append(context_reader.get_design_output_constraints())

        self.constraints += '\n'.join(lines)
