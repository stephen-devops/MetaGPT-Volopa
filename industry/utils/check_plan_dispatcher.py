"""Deterministic parser for CHECK PLAN output from the LLM.

Parses two formats:

1. Compact text (primary — what the LLM is prompted for):
   CHECK PLAN
   - PocketExpenseFileUpload      -> NEW       -> rag_collision_check("PocketExpenseFileUpload") -> 0 hits, create at app/Models/...
   - User (eloquent_model)        -> EXISTING  -> rag_symbol_lookup("User", "eloquent_model") -> 1 hit, file_path: UNKNOWN

2. JSON (machine-enforced):
   {"CHECK_PLAN": [{"name":"User","kind":"eloquent_model","origin":"EXISTING","verify":["rag_symbol_lookup"]}]}
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from industry.utils.repo_verifier import RepoVerifier, VerificationReport


@dataclass
class CheckPlanItem:
    """A single symbol entry from the CHECK PLAN."""

    name: str                   # "PocketExpenseFileUpload", "users", "routes/api.php"
    kind: str = ""              # "eloquent_model", "middleware", "database_table", "route_file"
    origin: str = "UNKNOWN"     # EXISTING | NEW | UNKNOWN
    verification: str = ""      # "rag_symbol_lookup", "rag_collision_check", "collision check"
    evidence: str = ""          # trailing evidence text from the LLM line


# Regex for compact format lines:
#   - Name (kind) -> ORIGIN -> verification -> evidence
# The (kind) part is optional; arrows separate fields.
_COMPACT_LINE_RE = re.compile(
    r"^-\s+"                           # leading dash
    r"(?P<name>[^\(\-\>]+?)"           # symbol name (non-greedy, stops before ( or ->)
    r"(?:\s*\((?P<kind>[^)]+)\))?"     # optional (kind)
    r"\s*->\s*"                        # first arrow
    r"(?P<origin>[A-Z_]+)"            # EXISTING | NEW | UNKNOWN
    r"(?:\s*->\s*(?P<rest>.+))?"       # optional remaining text (verification + evidence)
    r"$"
)


class CheckPlanDispatcher:
    """Deterministic parser for CHECK PLAN LLM output.  No LLM calls."""

    @staticmethod
    def parse(raw_output: str) -> list[CheckPlanItem]:
        """Parse a CHECK PLAN from raw LLM output.

        Tries JSON first, then falls back to compact text format.
        Returns an empty list if no CHECK PLAN is detected.
        """
        items = CheckPlanDispatcher._try_json(raw_output)
        if items is not None:
            return items

        items = CheckPlanDispatcher._try_compact(raw_output)
        if items is not None:
            return items

        return []

    @staticmethod
    def is_check_plan(raw_output: str) -> bool:
        """Return True if the output contains a CHECK PLAN."""
        if '"CHECK_PLAN"' in raw_output:
            return True
        # Look for the header (case-insensitive, allowing leading whitespace/hashes)
        for line in raw_output.splitlines():
            stripped = line.strip().lstrip("#").strip()
            if stripped.upper() == "CHECK PLAN":
                return True
        return False

    @staticmethod
    def has_unresolved(items: list[CheckPlanItem]) -> bool:
        """Return True if any item has origin == 'UNKNOWN'."""
        return any(item.origin == "UNKNOWN" for item in items)

    @staticmethod
    def summary(items: list[CheckPlanItem]) -> str:
        """Return a human-readable summary of all CHECK PLAN items."""
        if not items:
            return "CHECK PLAN: (empty)"

        lines = [f"CHECK PLAN: {len(items)} items"]
        for item in items:
            kind_str = f" ({item.kind})" if item.kind else ""
            verify_str = f" | {item.verification}" if item.verification else ""
            evidence_str = f" | {item.evidence}" if item.evidence else ""
            lines.append(
                f"  - {item.name}{kind_str} -> {item.origin}{verify_str}{evidence_str}"
            )

        # Counts by origin
        origin_counts: dict[str, int] = {}
        for item in items:
            origin_counts[item.origin] = origin_counts.get(item.origin, 0) + 1
        counts_str = ", ".join(f"{k}: {v}" for k, v in sorted(origin_counts.items()))
        lines.append(f"  Origins: {counts_str}")

        return "\n".join(lines)

    # ------------------------------------------------------------------
    # Phase 2a: RAG Verification dispatch
    # ------------------------------------------------------------------

    @staticmethod
    async def dispatch_verification(
        items: list[CheckPlanItem],
        verifier: "RepoVerifier",
    ) -> list["VerificationReport"]:
        """Route parsed CHECK PLAN items to the verifier for RAG evidence acquisition."""
        return await verifier.verify(items)

    @staticmethod
    def format_evidence(results: list["VerificationReport"]) -> str:
        """Format verification results into a human-readable evidence summary.

        Per PDF Section 8.3 format — one block per item plus aggregate counts.
        """
        if not results:
            return "RAG VERIFICATION: (no results)"

        lines = [f"RAG VERIFICATION: {len(results)} items"]
        for idx, r in enumerate(results, 1):
            name = r.name
            origin = r.origin
            status = "FOUND" if r.found else "NOT FOUND"
            lines.append(f"  RAG RESULT {idx}: {name}")
            lines.append(f"    Origin: {origin} -> Resolved: {r.resolved_origin}")
            lines.append(f"    Status: {status} ({r.hits} hits)")
            if r.file_path:
                lines.append(f"    Found at: {r.file_path}")
            if r.symbol_type:
                lines.append(f"    Type: {r.symbol_type}")
            if r.description:
                lines.append(f"    Description: {r.description}")

        # Aggregate counts
        verified = sum(1 for r in results if r.item and r.item.origin == "EXISTING" and r.found)
        collisions = sum(1 for r in results if r.item and r.item.origin == "NEW" and r.found)
        unverified = sum(1 for r in results if r.item and r.item.origin == "EXISTING" and not r.found)
        resolved = sum(1 for r in results if r.item and r.item.origin == "UNKNOWN")
        safe_new = sum(1 for r in results if r.item and r.item.origin == "NEW" and not r.found)

        lines.append(f"  Summary: VERIFIED={verified}, SAFE_NEW={safe_new}, "
                      f"COLLISION={collisions}, UNVERIFIED={unverified}, "
                      f"UNKNOWN_RESOLVED={resolved}")
        return "\n".join(lines)

    # ------------------------------------------------------------------
    # Internal parsers
    # ------------------------------------------------------------------

    @staticmethod
    def _try_json(raw_output: str) -> list[CheckPlanItem] | None:
        """Attempt to parse JSON format: {"CHECK_PLAN": [...]}."""
        if '"CHECK_PLAN"' not in raw_output:
            return None

        # Extract JSON object — may be embedded in markdown code fences
        text = raw_output
        # Strip markdown code fences if present
        fence_match = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.DOTALL)
        if fence_match:
            text = fence_match.group(1)
        else:
            # Try to find a bare JSON object containing CHECK_PLAN
            brace_match = re.search(r"\{[^{}]*\"CHECK_PLAN\"[^{}]*\[.*?\]\s*\}", text, re.DOTALL)
            if brace_match:
                text = brace_match.group(0)

        try:
            data = json.loads(text)
        except (json.JSONDecodeError, ValueError):
            return None

        if not isinstance(data, dict) or "CHECK_PLAN" not in data:
            return None

        items = []
        for entry in data["CHECK_PLAN"]:
            if not isinstance(entry, dict):
                continue
            verify_raw = entry.get("verify", entry.get("verification", []))
            if isinstance(verify_raw, list):
                verification = ", ".join(str(v) for v in verify_raw)
            else:
                verification = str(verify_raw)

            items.append(CheckPlanItem(
                name=str(entry.get("name", "")),
                kind=str(entry.get("kind", "")),
                origin=str(entry.get("origin", "UNKNOWN")).upper(),
                verification=verification,
                evidence=str(entry.get("evidence", "")),
            ))
        return items

    @staticmethod
    def _try_compact(raw_output: str) -> list[CheckPlanItem] | None:
        """Attempt to parse compact text format.

        Looks for a 'CHECK PLAN' header line, then parses subsequent
        '- Name (kind) -> ORIGIN -> ...' lines.
        """
        lines = raw_output.splitlines()
        # Find the CHECK PLAN header
        header_idx = None
        for idx, line in enumerate(lines):
            stripped = line.strip().lstrip("#").strip()
            if stripped.upper() == "CHECK PLAN":
                header_idx = idx
                break

        if header_idx is None:
            return None

        items = []
        for line in lines[header_idx + 1:]:
            stripped = line.strip()
            if not stripped:
                continue
            # Stop at a new section header (line starting with # or all-caps label followed by colon)
            if stripped.startswith("#") and "CHECK PLAN" not in stripped.upper():
                break

            match = _COMPACT_LINE_RE.match(stripped)
            if not match:
                # Skip non-matching lines (could be sub-headers, blank lines, etc.)
                continue

            name = match.group("name").strip()
            kind = (match.group("kind") or "").strip()
            origin = match.group("origin").strip().upper()
            rest = (match.group("rest") or "").strip()

            # Split rest into verification and evidence at the last arrow
            verification = ""
            evidence = ""
            if rest:
                # Split on ' -> ' to separate verification action from evidence
                rest_parts = rest.split(" -> ", 1)
                verification = rest_parts[0].strip()
                if len(rest_parts) > 1:
                    evidence = rest_parts[1].strip()

            items.append(CheckPlanItem(
                name=name,
                kind=kind,
                origin=origin,
                verification=verification,
                evidence=evidence,
            ))

        return items if items else None
