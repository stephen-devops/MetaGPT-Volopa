"""Shared YAML context reader for Volopa OOP Expense roles.

Provides structured access to the two YAML context files:
  - project_context.yaml   (feature-specific intent, rules, flows, interfaces)
  - environment_context.yaml (platform-level standards, constraints, and interfaces)

Usage:
    from industry.utils.context_reader import ContextReader

    reader = ContextReader()
    reader.get("project", "decisions", "unresolved")
    reader.get_dos_and_donts()
    reader.get_database_tables("summary")
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any, Optional

import yaml


class ContextReader:
    """Reads and provides structured access to the three YAML context files."""

    _FILE_MAP = {
        "project": "project_context.yaml",
        "environment": "environment_context.yaml",
    }

    def __init__(self, base_path: Optional[str] = None):
        if base_path is None:
            # Resolve to industry/requirements/ relative to this file
            base_path = str(
                Path(__file__).resolve().parent.parent
                / "requirements"
            )
        self._base_path = base_path
        self._cache: dict[str, dict] = {}

    # ------------------------------------------------------------------
    # Lazy-loaded properties
    # ------------------------------------------------------------------

    @property
    def project(self) -> dict:
        return self._load("project")

    @property
    def environment(self) -> dict:
        return self._load("environment")

    # ------------------------------------------------------------------
    # Generic accessors
    # ------------------------------------------------------------------

    def get(self, file_key: str, *path_keys: str) -> Any:
        """Traverse nested dict by keys.

        Example:
            reader.get("project", "decisions", "unresolved")
        """
        data = self._load(file_key)
        for key in path_keys:
            if isinstance(data, dict):
                data = data[key]
            else:
                raise KeyError(f"Cannot traverse into non-dict at key '{key}'")
        return data

    def get_by_id(
        self,
        file_key: str,
        *path_keys: str,
        id_field: str = "id",
        id_value: str,
    ) -> Optional[dict]:
        """Find an item in a list by its id field.

        Example:
            reader.get_by_id("project", "decisions", "architectural_decisions",
                             id_value="DEC-011")
        """
        items = self.get(file_key, *path_keys)
        if not isinstance(items, list):
            raise TypeError(
                f"Expected list at {file_key}.{'.'.join(path_keys)}, got {type(items).__name__}"
            )
        for item in items:
            if isinstance(item, dict) and item.get(id_field) == id_value:
                return item
        return None

    # ------------------------------------------------------------------
    # Section extraction methods (return formatted strings for LLM injection)
    # ------------------------------------------------------------------

    def format_section(self, data: Any, indent: int = 0) -> str:
        """Generic recursive formatter: dict/list/scalar -> indented text lines."""
        prefix = "  " * indent
        lines: list[str] = []

        if isinstance(data, dict):
            for key, value in data.items():
                if isinstance(value, (dict, list)):
                    lines.append(f"{prefix}{key}:")
                    lines.append(self.format_section(value, indent + 1))
                else:
                    lines.append(f"{prefix}{key}: {value}")
        elif isinstance(data, list):
            for item in data:
                if isinstance(item, dict):
                    # Compact dict items with a leading dash
                    first = True
                    for key, value in item.items():
                        if first:
                            lines.append(f"{prefix}- {key}: {value}")
                            first = False
                        elif isinstance(value, (dict, list)):
                            lines.append(f"{prefix}  {key}:")
                            lines.append(self.format_section(value, indent + 2))
                        else:
                            lines.append(f"{prefix}  {key}: {value}")
                else:
                    lines.append(f"{prefix}- {item}")
        else:
            lines.append(f"{prefix}{data}")

        return "\n".join(lines)

    def get_dos_and_donts(self) -> str:
        """Return environment.constraints.dos_and_donts formatted as text."""
        data = self.get("environment", "constraints", "dos_and_donts")
        return f"=== DOS AND DONTS (Platform Standards) ===\n{self.format_section(data)}"

    def get_mental_model(self) -> str:
        """Return environment.intent.mental_model formatted as text."""
        data = self.get("environment", "intent", "mental_model")
        return f"=== MENTAL MODEL ===\n{self.format_section(data)}"

    def get_platform_constraints(self) -> str:
        """Return environment.constraints (auth, multi-tenancy, soft delete, etc.) formatted."""
        data = self.get("environment", "constraints")
        filtered = {k: v for k, v in data.items() if k not in ("description", "verification_protocol")}
        return f"=== PLATFORM CONSTRAINTS ===\n{self.format_section(filtered)}"

    def get_do_not_build(self) -> str:
        """Return DEC-011 do_not_build list as text."""
        dec = self.get_by_id(
            "project", "decisions", "architectural_decisions", id_value="DEC-011"
        )
        if dec is None:
            return "=== DO NOT BUILD ===\n(DEC-011 not found)"

        lines = ["=== DO NOT BUILD (DEC-011) ==="]
        lines.append(f"Decision: {dec.get('decision', '')}")
        lines.append(f"Rationale: {dec.get('rationale', '').strip()}")
        lines.append("")
        lines.append("Blocked artifacts:")
        for item in dec.get("do_not_build", []):
            lines.append(f"  - {item}")

        supersession = dec.get("supersession_detail")
        if supersession:
            lines.append("")
            lines.append("Supersession detail:")
            lines.append(self.format_section(supersession, indent=1))

        return "\n".join(lines)

    def get_components_to_build(self) -> str:
        """Return project.components_to_build formatted as text.

        Falls back gracefully if the section is commented out or absent.
        """
        try:
            data = self.get("project", "components_to_build")
        except KeyError:
            return "=== COMPONENTS TO BUILD ===\n(section not present — see interfaces for authoritative list)"
        return f"=== COMPONENTS TO BUILD ===\n{self.format_section(data)}"

    def get_database_tables(self, detail: str = "full") -> str:
        """Return project.interfaces.database_tables at varying verbosity.

        Args:
            detail: "names" | "summary" | "full"
              - "names"   -> table names + origins
              - "summary" -> names + column count + FK targets
              - "full"    -> complete column definitions, FKs, indexes
        """
        tables = self.get("project", "interfaces", "database_tables")
        lines = [f"=== DATABASE TABLES (detail={detail}) ==="]

        for table in tables:
            name = table.get("name", "?")
            origin = table.get("origin", "?")
            # NEW tables use "columns"; EXISTING tables use "key_columns"
            columns = table.get("columns", [])
            key_columns = table.get("key_columns", [])
            fks = table.get("foreign_keys", [])
            indexes = table.get("indexes", [])

            if detail == "names":
                lines.append(f"  - {name} (origin: {origin})")

            elif detail == "summary":
                col_count = len(columns) or len(key_columns)
                fk_targets = [fk.get("references", "") for fk in fks]
                fk_str = ", ".join(fk_targets) if fk_targets else "none"
                lines.append(
                    f"  - {name} (origin: {origin}, "
                    f"columns: {col_count}, FK targets: {fk_str})"
                )

            else:  # full
                lines.append(f"\n  Table: {name} (origin: {origin})")
                desc = table.get("description", "")
                if desc:
                    lines.append(f"  Description: {desc}")

                if columns:
                    lines.append("  Columns:")
                    for col in columns:
                        col_name = col.get("name", "?")
                        col_type = col.get("type", "?")
                        constraints = col.get("constraints", [])
                        default = col.get("default")
                        parts = [f"    - {col_name} {col_type}"]
                        if constraints:
                            parts.append(" ".join(constraints))
                        if default is not None:
                            parts.append(f"DEFAULT {default}")
                        lines.append(" ".join(parts))
                elif key_columns:
                    lines.append("  Key Columns (reference only):")
                    for col in key_columns:
                        col_name = col.get("name", "?")
                        col_type = col.get("type", "")
                        if col_type:
                            lines.append(f"    - {col_name} ({col_type})")
                        else:
                            lines.append(f"    - {col_name}")

                if fks:
                    lines.append("  Foreign Keys:")
                    for fk in fks:
                        col = fk.get("column", "?")
                        refs = fk.get("references", "?")
                        ref_origin = fk.get("ref_origin", "?")
                        lines.append(
                            f"    - {col} -> {refs} (ref_origin: {ref_origin})"
                        )

                uks = table.get("unique_keys", [])
                if uks:
                    lines.append("  Unique Keys:")
                    for uk in uks:
                        uk_name = uk.get("name", "?")
                        uk_cols = uk.get("columns", [])
                        lines.append(f"    - {uk_name}: ({', '.join(uk_cols)})")

                if indexes:
                    lines.append("  Indexes:")
                    for idx in indexes:
                        idx_name = idx.get("name", "?")
                        idx_col = idx.get("column", idx.get("columns", "?"))
                        lines.append(f"    - {idx_name}: {idx_col}")

                seed = table.get("seed_data")
                if seed:
                    lines.append("  Seed Data:")
                    for row in seed:
                        lines.append(f"    - {row}")

        return "\n".join(lines)

    def get_unresolved_decisions(self) -> str:
        """Return all unresolved decisions from both project and environment."""
        lines = ["=== UNRESOLVED DECISIONS ==="]

        # Project unresolved
        try:
            project_unresolved = self.get("project", "decisions", "unresolved")
            if project_unresolved:
                lines.append("\nProject-level:")
                for item in project_unresolved:
                    lines.append(f"  - [{item.get('id', '?')}] {item.get('area', '?')}")
                    lines.append(f"    Question: {item.get('question', '?')}")
                    note = item.get("note")
                    if note:
                        lines.append(f"    Note: {note}")
        except KeyError:
            pass

        # Environment unresolved
        try:
            env_unresolved = self.get("environment", "decisions", "unresolved")
            if env_unresolved:
                lines.append("\nEnvironment-level:")
                for item in env_unresolved:
                    lines.append(f"  - [{item.get('id', '?')}] {item.get('area', '?')}")
                    lines.append(f"    Question: {item.get('question', '?')}")
        except KeyError:
            pass

        return "\n".join(lines)

    def get_flows(self, flow_name: Optional[str] = None) -> str:
        """Return specific or all flows from project_context."""
        flows = self.get("project", "flows")
        if flow_name is not None:
            if flow_name not in flows:
                return f"Flow '{flow_name}' not found. Available: {', '.join(k for k in flows if k != 'description')}"
            data = flows[flow_name]
            return f"=== FLOW: {flow_name} ===\n{self.format_section(data)}"

        lines = ["=== ALL FLOWS ==="]
        for key, value in flows.items():
            if key == "description":
                continue
            flow_label = value.get("name", key) if isinstance(value, dict) else key
            lines.append(f"\n--- {flow_label} ---")
            lines.append(self.format_section(value))
        return "\n".join(lines)

    def get_interfaces_summary(self) -> str:
        """Return controllers, services, jobs, models from project_context."""
        lines = ["=== INTERFACES SUMMARY ==="]

        for section in ("controllers", "services", "jobs", "models"):
            try:
                items = self.get("project", "interfaces", section)
                lines.append(f"\n{section.title()}:")
                for item in items:
                    name = item.get("name", "?")
                    loc = item.get("location", "")
                    origin = item.get("origin", "?")
                    lines.append(f"  - {name} ({origin}) {loc}")
            except KeyError:
                pass

        return "\n".join(lines)

    def get_project_intent(self) -> str:
        """Return project.intent (objectives, business rationale, success criteria)."""
        data = self.get("project", "intent")
        return f"=== PROJECT INTENT ===\n{self.format_section(data)}"

    def get_project_requirements(self) -> str:
        """Return project.requirements (features REQ-001–012, user journeys)."""
        data = self.get("project", "requirements")
        filtered_data = {k: v for k, v in data.items() if k not in ("description", "inherited_behaviors")}
        return f"=== PROJECT REQUIREMENTS ===\n{self.format_section(filtered_data)}"

    def get_project_constraints(self) -> str:
        """Return project.constraints (file, validation, permission, expense source, FX, data model).
        """
        data = self.get("project", "constraints")
        filtered = {k: v for k, v in data.items() if k not in ("description", "design_output_constraints")}
        return f"=== PROJECT CONSTRAINTS ===\n{self.format_section(filtered)}"

    def get_design_output_constraints(self) -> str:
        """Return project.constraints.design_output_constraints (classDiagram/sequenceDiagram consistency rules)."""
        data = self.get("project", "constraints", "design_output_constraints")
        lines = ["=== DESIGN OUTPUT CONSTRAINTS ==="]
        desc = data.get("description", "")
        if desc:
            lines.append(desc)
        for rule in data.get("rules", []):
            lines.append(f"  [{rule.get('id', '?')}] ({rule.get('scope', '?')}): {rule.get('rule', '')}")
        return "\n".join(lines)

    def get_csv_column_schema(self) -> str:
        """Return project.interfaces.csv_column_schema (CSV template column definitions)."""
        data = self.get("project", "interfaces", "csv_column_schema")
        return f"=== CSV COLUMN SCHEMA ===\n{self.format_section(data)}"

    def get_api_routes(self) -> str:
        """Return project.interfaces.api_routes (route definitions, endpoints)."""
        data = self.get("project", "interfaces", "api_routes")
        return f"=== API ROUTES ===\n{self.format_section(data)}"

    def get_response_schemas(self) -> str:
        """Return project.interfaces.response_schemas (error/success response structures)."""
        data = self.get("project", "interfaces", "response_schemas")
        return f"=== RESPONSE SCHEMAS ===\n{self.format_section(data)}"

    def get_permission_matrix(self) -> str:
        """Return project.interfaces.permission_matrix (role permission matrix)."""
        data = self.get("project", "interfaces", "permission_matrix")
        return f"=== PERMISSION MATRIX ===\n{self.format_section(data)}"

    def get_fx_infrastructure(self) -> str:
        """Return project.interfaces.fx_infrastructure_references."""
        data = self.get("project", "interfaces", "fx_infrastructure_references")
        return f"=== FX INFRASTRUCTURE REFERENCES ===\n{self.format_section(data)}"

    # ------------------------------------------------------------------
    # environment_context.yaml — new methods
    # ------------------------------------------------------------------

    def get_design_principles(self) -> str:
        """Return environment.intent.platform_purpose + design_principles."""
        intent = self.get("environment", "intent")
        lines = ["=== DESIGN PRINCIPLES ==="]
        purpose = intent.get("platform_purpose", "")
        if purpose:
            lines.append(f"Platform Purpose: {purpose}")
        principles = intent.get("design_principles", [])
        if principles:
            lines.append("Design Principles:")
            for p in principles:
                lines.append(f"  - {p}")
        return "\n".join(lines)

    def get_inherited_behaviors(self) -> str:
        """Return environment.requirements.inherited_behaviors."""
        data = self.get("project", "requirements", "inherited_behaviors")
        lines = ["=== INHERITED BEHAVIORS (Platform) ==="]
        for item in data:
            lines.append(f"  - {item}")
        return "\n".join(lines)

    def get_platform_decisions(self) -> str:
        """Return environment.decisions.platform_decisions."""
        data = self.get("environment", "decisions", "platform_decisions")
        return f"=== PLATFORM DECISIONS ===\n{self.format_section(data)}"

    def get_existing_tables_and_models(self, detail: str = "full") -> str:
        """Return environment.interfaces.existing_database_tables + standard_volopa_models.

        Args:
            detail: "names" | "full"
              - "names" -> table names and model class names only
              - "full"  -> complete details (columns, relationships, etc.)
        """
        tables = self.get("environment", "interfaces", "existing_database_tables")
        models = self.get("environment", "interfaces", "standard_volopa_models")
        lines = [f"=== EXISTING TABLES AND MODELS (detail={detail}) ==="]

        if detail == "names":
            lines.append("\nExisting Database Tables:")
            for t in tables:
                name = t.get("name", t) if isinstance(t, dict) else t
                lines.append(f"  - {name}")
            lines.append("\nStandard Volopa Models:")
            for m in models:
                name = m.get("name", m) if isinstance(m, dict) else m
                lines.append(f"  - {name}")
        else:
            lines.append("\nExisting Database Tables:")
            lines.append(self.format_section(tables, indent=1))
            lines.append("\nStandard Volopa Models:")
            lines.append(self.format_section(models, indent=1))

        return "\n".join(lines)

    def get_existing_user_roles(self) -> str:
        """Return environment.interfaces.existing_user_roles."""
        data = self.get("environment", "interfaces", "existing_user_roles")
        return f"=== EXISTING USER ROLES ===\n{self.format_section(data)}"

    def get_existing_platform_services(self) -> str:
        """Return middleware, endpoints, infra, UI paths, validation patterns."""
        ifaces = self.get("environment", "interfaces")
        lines = ["=== EXISTING PLATFORM SERVICES ==="]
        for key in (
            "standard_volopa_middleware",
            "existing_endpoints",
            "standard_volopa_infrastructure",
            "existing_ui_entry_points",
            "existing_validation_infrastructure",
        ):
            data = ifaces.get(key)
            if data:
                lines.append(f"\n{key}:")
                lines.append(self.format_section(data, indent=1))
        return "\n".join(lines)

    def get_fx_query_contract(self) -> str:
        """Return environment.interfaces.fx_query_contract."""
        data = self.get("environment", "interfaces", "fx_query_contract")
        return f"=== FX QUERY CONTRACT ===\n{self.format_section(data)}"

    def get_laravel_task_conventions(self) -> str:
        """Return environment.constraints.laravel_task_conventions (execution order, parallelism, dependencies, derivation rules)."""
        data = self.get("environment", "constraints", "laravel_task_conventions")
        lines = ["=== LARAVEL TASK CONVENTIONS ==="]
        desc = data.get("description", "")
        if desc:
            lines.append(desc)

        # Execution order priority
        eop = data.get("execution_order_priority", {})
        if eop:
            lines.append(f"\nExecution Order Priority: {eop.get('description', '')}")
            for tier in eop.get("tiers", []):
                lines.append(f"  {tier['priority']}: {tier['artifact']} - depends on: {tier['dependencies']}")

        # Parallel development opportunities
        pdo = data.get("parallel_development_opportunities", {})
        if pdo:
            lines.append(f"\nParallel Development Opportunities: {pdo.get('description', '')}")
            for item in pdo.get("parallelisable", []):
                lines.append(f"  - {item}")

        # Critical dependencies
        cd = data.get("critical_dependencies", {})
        if cd:
            lines.append(f"\nCritical Dependencies: {cd.get('description', '')}")
            for rule in cd.get("rules", []):
                lines.append(f"  - {rule}")

        # Composer packages
        cp = data.get("composer_packages", {})
        if cp:
            lines.append(f"\nComposer Packages: {cp.get('description', '')}")

        # Derivation rules
        dr = data.get("derivation_rules", {})
        if dr:
            lines.append(f"\nDerivation Rules: {dr.get('description', '')}")
            for rule in dr.get("rules", []):
                lines.append(f"  - {rule}")

        return "\n".join(lines)

    def get_platform_flow_touchpoints(self) -> str:
        """Return project.flows.platform_flow_touchpoints.

        Falls back gracefully if the section is commented out or absent.
        """
        try:
            data = self.get("project", "flows", "platform_flow_touchpoints")
        except KeyError:
            return "=== PLATFORM FLOW TOUCHPOINTS ===\n(section not present — see environment_context.yaml#interfaces for existing platform services)"
        return f"=== PLATFORM FLOW TOUCHPOINTS ===\n{self.format_section(data)}"

    # ------------------------------------------------------------------
    # Verification Protocol
    # ------------------------------------------------------------------

    def get_verification_protocol(self) -> str:
        """Return environment.constraints.verification_protocol formatted for prompt injection.

        Produces the VERIFICATION PROTOCOL block that instructs the agent to
        emit a CHECK PLAN before generating any design or code.  Evidence
        is sourced from the live OpenSearch RAG index.
        """
        data = self.get("environment", "constraints", "verification_protocol")

        lines = [
            "",
            "=" * 60,
            "VERIFICATION PROTOCOL (MANDATORY)",
            "=" * 60,
            "",
            data.get("description", ""),
            "",
        ]

        # Output format (strict ordering)
        output_fmt = data.get("output_format", {})
        if output_fmt:
            lines.append("OUTPUT FORMAT (STRICT):")
            for step in output_fmt.get("sequence", []):
                lines.append(f"  {step}")
            rule = output_fmt.get("rule", "")
            if rule:
                lines.append(f"  {rule}")
            lines.append("")

        # Protocol rules
        for rule in data.get("protocol_rules", []):
            lines.append(f"  - {rule}")
        lines.append("")

        # Origin classification rules
        origin_rules = data.get("origin_rules", {})
        for origin, details in origin_rules.items():
            lines.append(f"  * {origin}: {details.get('definition', '')}")
            lines.append(f"    Action: {details.get('action', '')}")
            lines.append(f"    Verification: {details.get('verification', '')}")
            query = details.get("query", "")
            if query:
                lines.append(f"    Query: {query}")
            lines.append(f"    Evidence required: {details.get('evidence_required', '')}")
        lines.append("")

        # Blocking rule
        blocking = data.get("blocking_rule", "")
        if blocking:
            lines.append(f"BLOCKING RULE: {blocking}")
            lines.append("")

        # Gate failure behavior
        gate = data.get("gate_failure_behavior", {})
        if gate:
            lines.append("GATE FAILURE BEHAVIOR:")
            for rule in gate.get("rules", []):
                lines.append(f"  - {rule}")
            fail_conds = gate.get("fail_conditions", [])
            if fail_conds:
                lines.append("  Fail conditions:")
                for fc in fail_conds:
                    lines.append(f"    - {fc.get('origin', '?')} + {fc.get('rag_result', '?')} -> {fc.get('verdict', '?')}")
            pass_conds = gate.get("pass_conditions", [])
            if pass_conds:
                lines.append("  Pass conditions:")
                for pc in pass_conds:
                    lines.append(f"    - {pc.get('origin', '?')} + {pc.get('rag_result', '?')} -> {pc.get('verdict', '?')}")
            lines.append("")

        # RAG repository
        rag_repo = data.get("rag_repository", {})
        if rag_repo:
            lines.append(f"RAG Repository: {rag_repo.get('index', '')} @ {rag_repo.get('host', '')}")
            lines.append(f"  {rag_repo.get('description', '')}")
            symbol_types = rag_repo.get("indexed_symbol_types", [])
            if symbol_types:
                lines.append(f"  Indexed symbol types: {', '.join(symbol_types)}")
            doc_schema = rag_repo.get("document_schema", {})
            if doc_schema:
                lines.append("  Document fields:")
                for field, desc in doc_schema.items():
                    lines.append(f"    {field}: {desc}")
            lines.append("")

        # Query patterns
        patterns = data.get("query_patterns", {})
        if patterns:
            lines.append("Query patterns:")
            for qname, qinfo in patterns.items():
                if not isinstance(qinfo, dict):
                    continue
                lines.append(f"  {qname}: {qinfo.get('description', '')}")
                for key, val in qinfo.items():
                    if key == "description":
                        continue
                    lines.append(f"    {key}: {val}")
            lines.append("")

        # CHECK PLAN format example
        fmt = data.get("check_plan_format", {})
        if fmt:
            lines.append("Expected CHECK PLAN format:")
            desc = fmt.get("description", "")
            if desc:
                lines.append(f"  {desc}")
            example = fmt.get("example", "")
            if example:
                for ex_line in example.strip().splitlines():
                    lines.append(f"  {ex_line}")

        return "\n".join(lines)

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _load(self, file_key: str) -> dict:
        """Load and cache a YAML file by its key."""
        if file_key in self._cache:
            return self._cache[file_key]

        if file_key not in self._FILE_MAP:
            raise ValueError(
                f"Unknown file key '{file_key}'. Valid keys: {list(self._FILE_MAP.keys())}"
            )

        file_path = os.path.join(self._base_path, self._FILE_MAP[file_key])
        data = self._load_yaml(file_path)
        self._cache[file_key] = data
        return data

    @staticmethod
    def _load_yaml(path: str) -> dict:
        """Read a YAML file with yaml.safe_load()."""
        with open(path, "r", encoding="utf-8") as f:
            return yaml.safe_load(f) or {}
