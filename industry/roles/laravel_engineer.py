#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_engineer.py
@Desc    : Laravel Engineer role for Volopa OOP Expense system
"""

from typing import Optional

from metagpt.roles.engineer import Engineer
from metagpt.schema import CodingContext
from metagpt.logs import logger
from industry.utils.context_reader import ContextReader


class LaravelEngineer(Engineer):
    """
    Laravel Engineer specialized for implementing OOP Expense Laravel API code.

    Responsibilities:
    - Write Laravel controllers (thin, proper status codes)
    - Write FormRequests (validation + policy authorization)
    - Write services (business logic with transactions and approval workflow)
    - Write Eloquent models (relationships, casts, fillable)
    - Write migrations (schema with indexes, foreign keys, etc.)
    - Write API Resources (response transformers)
    - Write feature tests (assert JSON, status codes, DB state)

    Domain knowledge is loaded exclusively from YAML context specifications.
    """

    use_fixed_sop: bool = True
    name: str = "Lucas"
    profile: str = "Laravel API Developer"
    goal: str = "Write Laravel code for OOP Expense system following platform constraints and Volopa conventions from YAML context"

    constraints: str = """
TABLE DISAMBIGUATION:
Tables with similar columns across modules are DISTINCT entities with different column types,
status enums, and relationships. Each MUST have its own Model and Migration.
Do NOT merge or conflate tables from different modules.
Derive model names from table names using Laravel convention (snake_case table -> PascalCase model).

CRITICAL USER ID MAPPING (for upload tracking tables):
DB user_id = the TARGET user whose expenses are being created (maps to API form field expense_user_id)
DB created_by_user_id = the ADMIN who performed the upload (maps to API form field user_id / auth token)
Do NOT swap these. The API field names differ from DB column names.
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

        # Build constraints from YAML context (local var to avoid Pydantic serialization issues)
        self._update_constraints_from_context(ContextReader())

        # Set incremental mode to False to skip WriteCodePlanAndChange phase
        self.config.inc = False

        # Engineer needs multiple loops to write all files
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=50)

    def _update_constraints_from_context(self, context_reader: ContextReader):
        """Inject YAML context into role constraints.

        Only injects guardrails and rules NOT already present in the design_doc
        and task_doc that WriteCode includes in its prompt. Sections like
        csv_column_schema, api_routes, response_schemas, components_to_build,
        interfaces_summary, database_tables, and fx_query_contract are omitted
        here because the Architect's design doc already carries them.
        """

        lines = []
        lines.append("")
        lines.append("=" * 60)
        lines.append("CONTEXT FROM YAML (authoritative guardrails):")
        lines.append("=" * 60)
        lines.append("")
        lines.append(context_reader.get_mental_model())
        lines.append("")
        lines.append(context_reader.get_dos_and_donts())
        lines.append("")
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_project_constraints())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())

        self.constraints += '\n'.join(lines)

    async def _new_coding_context(self, filename, dependency) -> Optional[CodingContext]:
        """Override to skip files with unknown origin instead of raising.

        On Windows, MetaGPT's path comparison (forward slash constants vs backslash
        Path objects) causes dependency resolution to fail for files already written
        by the Engineer in previous react loops. These files appear in changed_src_files
        but can't be linked back to task/design docs due to the slash mismatch.

        Gracefully returning None (skip) instead of raising ValueError prevents the
        entire pipeline from crashing. Files from the task list are unaffected — they
        are processed via _new_code_actions lines 354-421 which bypass this method.
        """
        try:
            return await super()._new_coding_context(filename, dependency)
        except ValueError as e:
            if "unknown origin" in str(e):
                logger.warning(f"LaravelEngineer: Skipping '{filename}' — {e}")
                return None
            raise

    async def _think(self) -> bool:
        """Override _think to ensure correct src_path and token budget before code generation."""
        # Cap max_token ONLY when Engineer is about to act (not at __init__ time,
        # which would poison the shared config before Architect/ProjectManager run).
        # 2048 tokens ≈ 8KB of PHP — sufficient for single-file generation.
        if self.context and self.context.config and self.context.config.llm:
            self.context.config.llm.max_token = 2048

        result = await super()._think()

        if hasattr(self, 'repo') and self.repo:
            from pathlib import Path
            workdir = Path(self.repo.workdir)
            current_src = self.repo.src_relative_path

            if current_src and current_src.name == workdir.name:
                self.repo.with_src_path(Path("."))
                logger.info(f"LaravelEngineer: Corrected nested src_path from '{current_src}' to '.' (workspace root)")

                nested_dir = workdir / current_src
                if nested_dir.exists() and nested_dir.is_dir():
                    import shutil
                    try:
                        shutil.rmtree(nested_dir)
                        logger.info(f"LaravelEngineer: Removed empty nested directory '{nested_dir}'")
                    except Exception as e:
                        logger.warning(f"LaravelEngineer: Could not remove nested directory '{nested_dir}': {e}")

                src_workspace_file = workdir / ".src_workspace"
                src_workspace_file.write_text(".")
                logger.info(f"LaravelEngineer: Created/updated .src_workspace file")

        return result
