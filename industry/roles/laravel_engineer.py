#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_engineer.py
@Desc    : Laravel Engineer role for Volopa OOP Expense system
"""

from typing import Optional, Set

from metagpt.roles.engineer import Engineer
from metagpt.actions.write_code_review import WriteCodeReview
from metagpt.schema import CodingContext
from metagpt.logs import logger
from industry.utils.context_reader import ContextReader
from industry.utils.check_plan_dispatcher import CheckPlanDispatcher
from industry.utils.repo_verifier import RepoVerifier
from industry.utils.evidence_injector import EvidenceInjector


CHECK_PLAN_PROMPT = """
# Context
## Design
{design}

## Task
{task}

# Instruction
Produce the CHECK PLAN as specified in the VERIFICATION PROTOCOL in your constraints.
Do NOT produce any code. Output ONLY the CHECK PLAN.
"""

CHECK_PLAN_RETRY_PROMPT = """
# Context
## Design
{design}

## Task
{task}

# Verification Failures
The following CHECK PLAN items failed verification:
{failures}

# Instruction
Your previous CHECK PLAN had verification failures. Correct the classifications
and re-produce the CHECK PLAN per the VERIFICATION PROTOCOL in your constraints.
Do NOT produce any code. Output ONLY the corrected CHECK PLAN.
"""

MAX_CHECK_PLAN_RETRIES = 2  # 3 total attempts (1 initial + 2 retries)


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
    goal: str = "Write Laravel code for software system following platform constraints and policy conventions from YAML context"

    constraints: str = ""

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

        # so only 1 react loop iteration is needed.  max_react_loop > 1 causes
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

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
        lines.append(context_reader.get_do_not_build())
        lines.append("")
        lines.append(context_reader.get_inherited_behaviors())
        lines.append("")
        lines.append(context_reader.get_verification_protocol())

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

        if self.context and self.context.config and self.context.config.llm:
            self.context.config.llm.max_token = 12288

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

    async def _act_sp_with_cr(self, review=False) -> Set[str]:
        """Override to emit a CHECK PLAN before the code generation loop.

        Phases 1-3: CHECK PLAN emission, RAG verification, and evidence-gated
        verification loop. Code generation only proceeds if the gate passes.
        """
        # ── Phases 1-3: CHECK PLAN with evidence-gated retry loop ──
        if self.code_todos:
            first_ctx = CodingContext.loads(self.code_todos[0].i_context.content)
            design = first_ctx.design_doc.content if first_ctx.design_doc else ""
            task = first_ctx.task_doc.content if first_ctx.task_doc else ""

            gate_passed = False
            verification_results = []
            failures = []

            for attempt in range(1, MAX_CHECK_PLAN_RETRIES + 2):  # 1..3
                # Phase 1: CHECK PLAN emission
                if attempt == 1:
                    prompt = CHECK_PLAN_PROMPT.format(design=design, task=task)
                else:
                    failure_text = "\n".join(f"- {f}" for f in failures)
                    prompt = CHECK_PLAN_RETRY_PROMPT.format(
                        design=design, task=task, failures=failure_text)

                logger.info(f"CHECK PLAN: Attempt {attempt} — requesting check plan")
                check_plan_raw = await self.llm.aask(prompt)
                items = CheckPlanDispatcher.parse(check_plan_raw)

                if not items:
                    logger.warning("CHECK PLAN: No check plan detected in LLM output")
                    failures = ["No CHECK PLAN detected in LLM output"]
                    continue

                logger.info(f"CHECK PLAN: {len(items)} items parsed")
                logger.info(CheckPlanDispatcher.summary(items))

                # Phase 2a: RAG verification
                verifier = RepoVerifier()
                verification_results = await CheckPlanDispatcher.dispatch_verification(items, verifier)
                logger.info(f"CHECK PLAN VERIFICATION: {len(verification_results)} items verified")
                logger.info(CheckPlanDispatcher.format_evidence(verification_results))

                # Phase 3: Gate evaluation (updates item.origin from resolved_origin)
                gate_passed, failures = EvidenceInjector.evaluate_gate(verification_results)

                if gate_passed:
                    logger.info(f"CHECK PLAN GATE: PASSED on attempt {attempt}")
                    break
                else:
                    logger.warning(
                        f"CHECK PLAN GATE: FAILED on attempt {attempt} — "
                        f"{len(failures)} failure(s): {'; '.join(failures)}")

            if not gate_passed:
                logger.error(
                    f"CHECK PLAN GATE: BLOCKED after {MAX_CHECK_PLAN_RETRIES + 1} attempts. "
                    f"Code generation will not proceed.")
                return set()

            # Phase 2b: Evidence injection (only reached if gate passed)
            evidence_text = EvidenceInjector.format_observations(verification_results)
            if evidence_text:
                injected = EvidenceInjector.inject_into_todos(self.code_todos, evidence_text)
                logger.info(
                    f"CHECK PLAN INJECTION: Evidence injected into "
                    f"{injected}/{len(self.code_todos)} todos")

        # ── Code generation (only reached if gate passed) ──
        changed_files = set()
        for todo in self.code_todos:
            coding_context = await todo.run()
            if review:
                action = WriteCodeReview(
                    i_context=coding_context,
                    repo=self.repo,
                    input_args=self.input_args,
                    context=self.context,
                    llm=self.llm,
                )
                self._init_action(action)
                coding_context = await action.run()

            dependencies = {
                coding_context.design_doc.root_relative_path,
                coding_context.task_doc.root_relative_path,
            }
            if self.config.inc:
                dependencies.add(coding_context.code_plan_and_change_doc.root_relative_path)
            await self.repo.srcs.save(
                filename=coding_context.filename,
                dependencies=list(dependencies),
                content=coding_context.code_doc.content,
            )
            changed_files.add(coding_context.code_doc.filename)

        if not changed_files:
            logger.info("Nothing has changed.")
        return changed_files
