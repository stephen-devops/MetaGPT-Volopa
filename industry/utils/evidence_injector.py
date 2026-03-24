"""Formats verification results into observation text and injects it into
WriteCode action prompts so code generation is grounded in verified facts.

Phase 2b: Evidence injection into CodingContext.task_doc.content.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

from metagpt.logs import logger
from metagpt.schema import CodingContext

if TYPE_CHECKING:
    from metagpt.actions import Action
    from industry.utils.repo_verifier import VerificationReport


class EvidenceInjector:
    """Formats RAG verification evidence and injects it into code-gen todos."""

    DELIMITER_START = "\n\n" + "=" * 60 + "\n## RAG VERIFICATION EVIDENCE\n" + "=" * 60
    DELIMITER_END = "=" * 60 + "\n## END RAG VERIFICATION EVIDENCE\n" + "=" * 60

    @staticmethod
    def _format_type_data(type_data: dict) -> list[str]:
        """Format type_data dict into indented evidence lines.

        Handles all symbol_types dynamically — no hardcoded type structure.
        Lists are comma-joined, nested dicts are flattened one level,
        and scalar values are rendered directly.
        """
        lines: list[str] = []
        for key, value in type_data.items():
            if value is None:
                continue
            if isinstance(value, list):
                if not value:
                    continue
                # List of dicts (e.g. key_columns_used: [{column, type, note}])
                if isinstance(value[0], dict):
                    lines.append(f"    {key}:")
                    for entry in value:
                        parts = [f"{k}: {v}" for k, v in entry.items() if v is not None]
                        lines.append(f"      - {', '.join(parts)}")
                else:
                    # Simple list of scalars (e.g. capabilities, returns)
                    lines.append(f"    {key}: {', '.join(str(v) for v in value)}")
            elif isinstance(value, dict):
                # Nested dict — flatten one level
                lines.append(f"    {key}:")
                for sub_key, sub_value in value.items():
                    if sub_value is not None:
                        lines.append(f"      {sub_key}: {sub_value}")
            else:
                lines.append(f"    {key}: {value}")
        return lines

    @staticmethod
    def format_observations(results: list[VerificationReport]) -> str:
        """Group verification results into 4 categories and produce observation text.

        Categories:
        - EXISTING_VERIFIED: claimed EXISTING, found in index
        - NEW_SAFE: claimed NEW, no collision
        - UNVERIFIED: claimed EXISTING but NOT found (warning)
        - COLLISION: claimed NEW but already exists (warning)

        For EXISTING_VERIFIED symbols, type_data is included so that downstream
        code generation can reference correct interfaces (methods, columns,
        relationships, capabilities) rather than guessing.

        Returns a delimited text block for injection into design_doc.content.
        """
        if not results:
            return ""

        existing_verified = []
        new_safe = []
        unverified = []
        collisions = []

        for r in results:
            if not r.item:
                continue
            origin = r.item.origin
            if origin == "EXISTING" and r.found:
                existing_verified.append(r)
            elif origin == "NEW" and not r.found:
                new_safe.append(r)
            elif origin == "EXISTING" and not r.found:
                unverified.append(r)
            elif origin == "NEW" and r.found:
                collisions.append(r)
            elif origin == "UNKNOWN":
                # UNKNOWN items resolved by lookup
                if r.found:
                    existing_verified.append(r)
                else:
                    new_safe.append(r)

        lines = [EvidenceInjector.DELIMITER_START]

        if existing_verified:
            lines.append("\n### EXISTING (verified in repo)")
            for r in existing_verified:
                kind = f" ({r.item.kind})" if r.item.kind else ""
                path = f" -> {r.file_path}" if r.file_path else ""
                stype = f" [{r.symbol_type}]" if r.symbol_type else ""
                desc = f" — {r.description}" if r.description else ""
                lines.append(f"- {r.item.name}{kind}{stype}{path}{desc}")
                if r.type_data:
                    lines.extend(EvidenceInjector._format_type_data(r.type_data))

        if new_safe:
            lines.append("\n### NEW (no collision, safe to create)")
            for r in new_safe:
                kind = f" ({r.item.kind})" if r.item.kind else ""
                lines.append(f"- {r.item.name}{kind}")

        if unverified:
            lines.append("\n### WARNING: UNVERIFIED (claimed EXISTING but NOT found in index)")
            lines.append("# These symbols may not exist. Double-check names, namespaces, and paths.")
            for r in unverified:
                kind = f" ({r.item.kind})" if r.item.kind else ""
                lines.append(f"- {r.item.name}{kind} -> NOT FOUND in index")

        if collisions:
            lines.append("\n### WARNING: COLLISION (claimed NEW but already exists in index)")
            lines.append("# These names are already taken. Use the existing symbol or pick a different name.")
            for r in collisions:
                kind = f" ({r.item.kind})" if r.item.kind else ""
                path = f" -> exists at {r.file_path}" if r.file_path else " -> exists in index"
                stype = f" [{r.symbol_type}]" if r.symbol_type else ""
                desc = f" — {r.description}" if r.description else ""
                lines.append(f"- {r.item.name}{kind}{stype}{path}{desc}")
                if r.type_data:
                    lines.extend(EvidenceInjector._format_type_data(r.type_data))

        lines.append("")
        lines.append(EvidenceInjector.DELIMITER_END)
        return "\n".join(lines)

    @staticmethod
    def evaluate_gate(results: list[VerificationReport]) -> tuple[bool, list[str]]:
        """Deterministic pass/fail evaluation of verification results.

        Gate rules (PDF Section 5.2b):
            EXISTING + found     -> PASS  (verified)
            NEW      + not found -> PASS  (safe to create)
            UNKNOWN  + found     -> PASS  (reclassify -> EXISTING)
            UNKNOWN  + not found -> PASS  (reclassify -> NEW)
            EXISTING + NOT found -> FAIL  (unverified — symbol may not exist)
            NEW      + found     -> FAIL  (collision — name already taken)

        Side-effect: updates each result.item.origin to result.resolved_origin
        (PDF Section 8.4 — "Agent Updates Classification").

        Returns (passed, failures) where failures is a list of
        human-readable failure reasons (empty if passed).
        """
        failures: list[str] = []

        for r in results:
            if not r.item:
                continue

            declared_origin = r.item.origin  # LLM's original classification

            # Check for failures BEFORE updating origin
            kind_label = f" ({r.item.kind})" if r.item.kind else ""

            if declared_origin == "EXISTING" and not r.found:
                failures.append(
                    f"UNVERIFIED: '{r.item.name}'{kind_label} was declared EXISTING "
                    f"but was NOT found in the repo index"
                )
            elif declared_origin == "NEW" and r.found:
                path_info = f" (exists at {r.file_path})" if r.file_path else ""
                failures.append(
                    f"COLLISION: '{r.item.name}'{kind_label} was declared NEW "
                    f"but already exists in the repo index{path_info}"
                )

            # Update item origin from resolved_origin (reclassifies UNKNOWN items)
            r.item.origin = r.resolved_origin

        return len(failures) == 0, failures

    @staticmethod
    def inject_into_todos(code_todos: list[Action], evidence_text: str) -> int:
        """Append evidence_text to design_doc.content in each todo's CodingContext.

        design_doc.content is only used as text in the prompt template's {design}
        slot and is never parsed as JSON, so appending evidence is safe.

        Returns the count of successfully injected todos.
        """
        if not evidence_text:
            return 0

        injected = 0
        for todo in code_todos:
            try:
                ctx = CodingContext.loads(todo.i_context.content)
                if ctx is None:
                    logger.warning(
                        f"EvidenceInjector: Could not deserialize CodingContext "
                        f"for {getattr(todo.i_context, 'filename', '?')}"
                    )
                    continue

                if ctx.design_doc is None:
                    logger.warning(
                        f"EvidenceInjector: No design_doc in CodingContext "
                        f"for {ctx.filename}"
                    )
                    continue

                ctx.design_doc.content += evidence_text
                todo.i_context.content = ctx.model_dump_json()
                injected += 1
            except Exception as e:
                logger.warning(
                    f"EvidenceInjector: Failed to inject into todo "
                    f"{getattr(todo.i_context, 'filename', '?')}: {e}"
                )

        return injected
