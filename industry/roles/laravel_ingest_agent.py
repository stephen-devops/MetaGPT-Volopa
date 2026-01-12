#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-01-12
@File    : laravel_ingest_agent.py
@Desc    : Laravel Requirements Ingestor - Classifies mixed requirements documents
           using TYPE (6-way) + CONTEXT (2-way) taxonomy
"""

import json
import re
import yaml
from pathlib import Path
from typing import Dict, List, Any, Tuple
from datetime import datetime

from metagpt.actions import Action
from metagpt.roles import Role
from metagpt.logs import logger
from metagpt.schema import Message
from metagpt.const import MESSAGE_ROUTE_TO_ALL


class IngestSpecsAction(Action):
    """
    Ingest mixed requirements documents and classify using 2-taxonomy approach:
    - TYPE (6-way): Intent, Requirement, Constraint, Flow, Interface, Design
    - CONTEXT (2-way): Environment-specific, Project-specific

    Outputs 3 files:
    1. pm_input.md - Intent and Requirement statements for ProductManager
    2. project_context.yaml - Constraints, Flows, Interfaces for all agents
    3. architect_seeds.md - Design hints and complex flows for Architect
    """

    name: str = "IngestSpecsAction"

    # Classification prompt templates
    SEGMENTATION_PROMPT = """You are segmenting a requirements document into atomic statements.

RULES:
- One statement = one fact, rule, or requirement
- If a sentence contains multiple facts, split it
- Keep necessary context (currency, user role, condition)
- Preserve causal relationships (if X then Y)
- Number each statement

INPUT DOCUMENT:
{document_text}

OUTPUT FORMAT:
1. [First atomic statement]
2. [Second atomic statement]
3. [Third atomic statement]
...

EXAMPLE INPUT:
"Users can upload CSV files with up to 10,000 rows, and the system validates format and content asynchronously."

EXAMPLE OUTPUT:
1. Users can upload CSV files
2. CSV files can contain up to 10,000 rows
3. System validates CSV format asynchronously
4. System validates CSV content asynchronously

Now segment the document above into atomic statements.
"""

    CLASSIFICATION_PROMPT = """You are a requirements classifier. Classify each statement along TWO dimensions.

STATEMENT: "{statement}"

OUTPUT FORMAT (JSON):
{{
  "type": "[Intent|Requirement|Constraint|Flow|Interface|Design]",
  "context": "[Environment-specific|Project-specific]",
  "context_rationale": "Brief explanation why environment or project"
}}

CLASSIFICATION RULES:

=== TYPE (6-way) ===
- Intent: High-level goals ("enable users to...", "provide seamless...")
- Requirement: Concrete capabilities ("user can...", "system shall allow...")
- Constraint: Must/shall rules, limits ("max 10k rows", "INR requires invoice")
- Flow: Sequences, state transitions ("after upload → validate", "status: Draft → Validating")
- Interface: API endpoints, CSV columns, UI screens ("POST /api/v1/...", "CSV includes...")
- Design: Technical decisions ("use Queue Jobs", "design thin controllers")

=== CONTEXT (2-way) ===
Ask: "Would this be SAME or DIFFERENT in another similar company?"
- Environment-specific: DIFFERENT (Volopa-specific rules, currencies, limits, auth, data model)
- Project-specific: SAME (generic patterns, Laravel syntax, REST standards, universal rules)

EXAMPLES:

Statement: "System shall support up to 10,000 payment rows per file"
{{
  "type": "Constraint",
  "context": "Environment-specific",
  "context_rationale": "10,000 is Volopa's business-defined limit, another company might use 5,000 or 50,000"
}}

Statement: "Payment amounts must be positive numeric values"
{{
  "type": "Constraint",
  "context": "Project-specific",
  "context_rationale": "Universal business logic - negative payments don't make sense in any payment system"
}}

Statement: "Use Laravel Queue Jobs for async file processing"
{{
  "type": "Design",
  "context": "Project-specific",
  "context_rationale": "Standard Laravel pattern for background processing in any Laravel project"
}}

Statement: "INR payments must include Invoice Number and Invoice Date"
{{
  "type": "Constraint",
  "context": "Environment-specific",
  "context_rationale": "India-specific regulatory requirement, not applicable to other countries"
}}

Statement: "Filter all database queries by client_id for tenant isolation"
{{
  "type": "Design",
  "context": "Environment-specific",
  "context_rationale": "Volopa's specific multi-tenant data model, other companies might use different isolation strategies"
}}

Statement: "POST /api/v1/mass-payments to upload file"
{{
  "type": "Interface",
  "context": "Project-specific",
  "context_rationale": "RESTful API standard applicable to any web API"
}}

Now classify the statement above.
"""

    async def run(
        self,
        pdf_paths: List[str] = None,
        pptx_paths: List[str] = None,
        md_paths: List[str] = None,
        txt_paths: List[str] = None
    ) -> Dict[str, str]:
        """
        Main ingestion pipeline.

        Args:
            pdf_paths: List of PDF file paths to ingest
            pptx_paths: List of PPTX file paths to ingest
            md_paths: List of Markdown file paths to ingest
            txt_paths: List of text file paths to ingest

        Returns:
            Dict with output file paths and contents:
            - pm_input.md
            - project_context.yaml
            - architect_seeds.md
        """
        logger.info("=" * 80)
        logger.info("Starting Requirements Ingestion with TYPE + CONTEXT taxonomy")
        logger.info("=" * 80)

        # Step 1: Parse all source documents
        parsed_docs = await self._parse_documents(
            pdf_paths or [],
            pptx_paths or [],
            md_paths or [],
            txt_paths or []
        )
        logger.info(f"Parsed {len(parsed_docs)} source documents")

        # Step 2: Segment into atomic statements
        statements = await self._segment_documents(parsed_docs)
        logger.info(f"Segmented into {len(statements)} atomic statements")

        # Step 3: Classify statements (TYPE + CONTEXT)
        classified = await self._classify_statements(statements)
        logger.info(f"Classified {len(classified)} statements")

        # Step 4: Enrich with metadata
        enriched = await self._enrich_metadata(classified)
        logger.info(f"Enriched {len(enriched)} statements with metadata")

        # Step 5: Validate classifications
        validation_errors = self._validate_classifications(enriched)
        if validation_errors:
            logger.warning(f"Validation warnings: {len(validation_errors)}")
            for error in validation_errors[:5]:  # Show first 5
                logger.warning(f"  - {error}")

        # Step 6: Generate all output files
        outputs = await self._generate_outputs(enriched)
        logger.info(f"Generated {len(outputs)} output files")

        # Step 7: Save outputs to disk
        await self._save_outputs(outputs)
        logger.info("✓ Requirements ingestion completed successfully")

        return outputs

    async def _parse_documents(
        self,
        pdf_paths: List[str],
        pptx_paths: List[str],
        md_paths: List[str],
        txt_paths: List[str]
    ) -> List[Dict]:
        """Parse multiple document types into structured text."""
        parsed = []

        # Parse text files (simplest)
        for txt_path in txt_paths:
            try:
                with open(txt_path, 'r', encoding='utf-8') as f:
                    content = f.read()
                parsed.append({
                    'source': txt_path,
                    'type': 'txt',
                    'content': content
                })
                logger.info(f"  ✓ Parsed text file: {Path(txt_path).name}")
            except Exception as e:
                logger.error(f"  ✗ Failed to parse {txt_path}: {e}")

        # Parse Markdown files
        for md_path in md_paths:
            try:
                with open(md_path, 'r', encoding='utf-8') as f:
                    content = f.read()
                parsed.append({
                    'source': md_path,
                    'type': 'md',
                    'content': content
                })
                logger.info(f"  ✓ Parsed markdown file: {Path(md_path).name}")
            except Exception as e:
                logger.error(f"  ✗ Failed to parse {md_path}: {e}")

        # Parse PDF files (requires PyPDF2 or pdfplumber)
        for pdf_path in pdf_paths:
            try:
                # TODO: Implement PDF parsing with PyPDF2 or pdfplumber
                logger.warning(f"  ⚠ PDF parsing not implemented yet: {pdf_path}")
                parsed.append({
                    'source': pdf_path,
                    'type': 'pdf',
                    'content': f"[PDF content from {pdf_path} - parsing not implemented]"
                })
            except Exception as e:
                logger.error(f"  ✗ Failed to parse {pdf_path}: {e}")

        # Parse PPTX files (requires python-pptx)
        for pptx_path in pptx_paths:
            try:
                # TODO: Implement PPTX parsing with python-pptx
                logger.warning(f"  ⚠ PPTX parsing not implemented yet: {pptx_path}")
                parsed.append({
                    'source': pptx_path,
                    'type': 'pptx',
                    'content': f"[PPTX content from {pptx_path} - parsing not implemented]"
                })
            except Exception as e:
                logger.error(f"  ✗ Failed to parse {pptx_path}: {e}")

        return parsed

    async def _segment_documents(self, parsed_docs: List[Dict]) -> List[str]:
        """Segment documents into atomic statements using LLM."""
        all_statements = []

        for doc in parsed_docs:
            logger.info(f"  Segmenting {Path(doc['source']).name}...")

            # Split content into chunks if too large (to avoid token limits)
            content_chunks = self._chunk_content(doc['content'], max_tokens=3000)

            for i, chunk in enumerate(content_chunks):
                prompt = self.SEGMENTATION_PROMPT.format(document_text=chunk)

                try:
                    response = await self._llm.aask(prompt)
                    statements = self._parse_numbered_list(response)
                    all_statements.extend(statements)
                    logger.info(f"    Chunk {i+1}/{len(content_chunks)}: {len(statements)} statements")
                except Exception as e:
                    logger.error(f"    Failed to segment chunk {i+1}: {e}")

        return all_statements

    async def _classify_statements(self, statements: List[str]) -> List[Dict]:
        """
        Classify each statement along two dimensions:
        - TYPE (6-way)
        - CONTEXT (2-way)
        """
        classified = []

        logger.info("  Classifying statements...")
        for i, statement in enumerate(statements):
            if (i + 1) % 10 == 0:
                logger.info(f"    Progress: {i+1}/{len(statements)}")

            prompt = self.CLASSIFICATION_PROMPT.format(statement=statement)

            try:
                response = await self._llm.aask(prompt)

                # Extract JSON from response (handle markdown code blocks)
                json_str = self._extract_json(response)
                classification = json.loads(json_str)

                classified.append({
                    'id': f'STMT-{i+1:03d}',
                    'statement': statement,
                    'type': classification['type'],
                    'context': classification['context'],
                    'context_rationale': classification['context_rationale']
                })
            except json.JSONDecodeError as e:
                logger.error(f"    Failed to parse classification for statement {i+1}: {e}")
                logger.error(f"    Response: {response[:200]}")
                # Use default classification on failure
                classified.append({
                    'id': f'STMT-{i+1:03d}',
                    'statement': statement,
                    'type': 'Requirement',
                    'context': 'Project-specific',
                    'context_rationale': 'Auto-classified (LLM error)'
                })
            except Exception as e:
                logger.error(f"    Failed to classify statement {i+1}: {e}")
                classified.append({
                    'id': f'STMT-{i+1:03d}',
                    'statement': statement,
                    'type': 'Requirement',
                    'context': 'Project-specific',
                    'context_rationale': 'Auto-classified (error)'
                })

        return classified

    async def _enrich_metadata(self, classified: List[Dict]) -> List[Dict]:
        """Add type-specific metadata and reassign IDs based on type."""
        enriched = []

        # Counters for each type
        type_counters = {
            'Intent': 0,
            'Requirement': 0,
            'Constraint': 0,
            'Flow': 0,
            'Interface': 0,
            'Design': 0
        }

        for stmt in classified:
            enriched_stmt = stmt.copy()

            # Assign type-specific ID
            stmt_type = stmt['type']
            type_counters[stmt_type] += 1

            type_prefixes = {
                'Intent': 'INT',
                'Requirement': 'REQ',
                'Constraint': 'CON',
                'Flow': 'FLOW',
                'Interface': 'INT',
                'Design': 'DES'
            }

            enriched_stmt['id'] = f"{type_prefixes[stmt_type]}-{type_counters[stmt_type]:03d}"

            # Add routing destinations based on type
            enriched_stmt['routing'] = self._determine_routing(stmt['type'])

            # Add priority (infer from statement content)
            enriched_stmt['priority'] = self._infer_priority(stmt['statement'])

            # Type-specific enrichment
            if stmt_type == 'Requirement':
                enriched_stmt['category'] = self._infer_category(stmt['statement'])
                enriched_stmt['acceptance_criteria'] = []  # Could be extracted via LLM

            elif stmt_type == 'Constraint':
                enriched_stmt['enforcement'] = self._infer_enforcement(stmt['statement'])
                enriched_stmt['validation_rule'] = self._extract_validation_rule(stmt['statement'])

            elif stmt_type == 'Flow':
                enriched_stmt['trigger'] = self._extract_trigger(stmt['statement'])
                enriched_stmt['outcome'] = self._extract_outcome(stmt['statement'])

            elif stmt_type == 'Interface':
                if 'api' in stmt['statement'].lower() or 'endpoint' in stmt['statement'].lower():
                    enriched_stmt['interface_type'] = 'API'
                elif 'csv' in stmt['statement'].lower():
                    enriched_stmt['interface_type'] = 'CSV'
                else:
                    enriched_stmt['interface_type'] = 'UI'

            elif stmt_type == 'Design':
                enriched_stmt['pattern'] = self._extract_pattern(stmt['statement'])

            enriched.append(enriched_stmt)

        return enriched

    def _validate_classifications(self, enriched: List[Dict]) -> List[str]:
        """Run automated quality checks on classifications."""
        checks = []

        # Check 1: All statements have type and context
        for stmt in enriched:
            if not stmt.get('type') or not stmt.get('context'):
                checks.append(f"FAIL: {stmt['id']} missing type or context")

        # Check 2: Environment-specific count is reasonable (20-40%)
        env_count = sum(1 for s in enriched if s['context'] == 'Environment-specific')
        env_percentage = env_count / len(enriched) * 100 if enriched else 0
        if env_percentage < 15 or env_percentage > 60:
            checks.append(f"WARN: Environment-specific % is {env_percentage:.1f}% (expected 20-40%)")

        # Check 3: Intent statements present
        intent_count = sum(1 for s in enriched if s['type'] == 'Intent')
        if intent_count == 0:
            checks.append("WARN: No Intent statements found - expected at least 3-5")

        # Check 4: Requirements form significant portion
        req_count = sum(1 for s in enriched if s['type'] == 'Requirement')
        req_percentage = req_count / len(enriched) * 100 if enriched else 0
        if req_percentage < 30:
            checks.append(f"WARN: Requirements only {req_percentage:.1f}% - expected 40-60%")

        return checks

    async def _generate_outputs(self, enriched: List[Dict]) -> Dict[str, str]:
        """Generate all output files from enriched classified statements."""
        outputs = {}

        # Output 1: pm_input.md
        outputs['pm_input.md'] = self._generate_pm_input_md(enriched)

        # Output 2: project_context.yaml
        outputs['project_context.yaml'] = self._generate_context_yaml(enriched)

        # Output 3: architect_seeds.md
        outputs['architect_seeds.md'] = self._generate_architect_seeds_md(enriched)

        return outputs

    def _generate_pm_input_md(self, enriched: List[Dict]) -> str:
        """Generate PM input markdown with Intent and Requirements."""
        lines = []
        lines.append("# Product Manager Input")
        lines.append(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        lines.append(f"**Source:** Requirements Ingestion (TYPE + CONTEXT taxonomy)")
        lines.append("")

        # Section 1: Project Intent
        intent_stmts = [s for s in enriched if s['type'] == 'Intent']
        lines.append("## Project Intent")
        lines.append("")
        if intent_stmts:
            for stmt in intent_stmts:
                lines.append(f"- **{stmt['id']}**: {stmt['statement']}")
                if stmt['context'] == 'Environment-specific':
                    lines.append(f"  - *Environment-specific*: {stmt['context_rationale']}")
        else:
            lines.append("*No intent statements found*")
        lines.append("")

        # Section 2: User Requirements by Category
        req_stmts = [s for s in enriched if s['type'] == 'Requirement']
        lines.append("## User Requirements")
        lines.append("")

        if req_stmts:
            # Group by category
            categories = {}
            for stmt in req_stmts:
                cat = stmt.get('category', 'Uncategorized')
                if cat not in categories:
                    categories[cat] = []
                categories[cat].append(stmt)

            for cat, stmts in categories.items():
                lines.append(f"### {cat}")
                lines.append("")
                for stmt in stmts:
                    lines.append(f"- **{stmt['id']}** [{stmt['priority']}]: {stmt['statement']}")
                    if stmt['context'] == 'Environment-specific':
                        lines.append(f"  - *Environment-specific*: {stmt['context_rationale']}")
                lines.append("")
        else:
            lines.append("*No requirements found*")

        # Section 3: User-Facing Interfaces
        interface_stmts = [s for s in enriched if s['type'] == 'Interface' and
                          s.get('interface_type') in ['UI', 'CSV']]
        if interface_stmts:
            lines.append("## User-Facing Interfaces")
            lines.append("")
            for stmt in interface_stmts:
                lines.append(f"- **{stmt['id']}**: {stmt['statement']}")
            lines.append("")

        return '\n'.join(lines)

    def _generate_context_yaml(self, enriched: List[Dict]) -> str:
        """Generate project context YAML with all constraints, flows, interfaces."""
        context_data = {
            'project_metadata': {
                'generated': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'source': 'Requirements Ingestion (TYPE + CONTEXT taxonomy)',
                'total_statements': len(enriched),
                'environment_specific': sum(1 for s in enriched if s['context'] == 'Environment-specific'),
                'project_specific': sum(1 for s in enriched if s['context'] == 'Project-specific'),
            },
            'requirements': [],
            'constraints': [],
            'flows': [],
            'interfaces': [],
            'design_mandates': []
        }

        # Add requirements (those routed to Context)
        req_stmts = [s for s in enriched if s['type'] == 'Requirement']
        for stmt in req_stmts:
            context_data['requirements'].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'type': stmt['type'],
                'context': stmt['context'],
                'context_rationale': stmt['context_rationale'],
                'priority': stmt['priority'],
                'category': stmt.get('category', 'Uncategorized')
            })

        # Add constraints
        con_stmts = [s for s in enriched if s['type'] == 'Constraint']
        for stmt in con_stmts:
            context_data['constraints'].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'context': stmt['context'],
                'context_rationale': stmt['context_rationale'],
                'priority': stmt['priority'],
                'enforcement': stmt.get('enforcement'),
                'validation_rule': stmt.get('validation_rule')
            })

        # Add flows
        flow_stmts = [s for s in enriched if s['type'] == 'Flow']
        for stmt in flow_stmts:
            context_data['flows'].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'context': stmt['context'],
                'context_rationale': stmt['context_rationale'],
                'trigger': stmt.get('trigger'),
                'outcome': stmt.get('outcome')
            })

        # Add interfaces
        int_stmts = [s for s in enriched if s['type'] == 'Interface']
        for stmt in int_stmts:
            context_data['interfaces'].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'context': stmt['context'],
                'context_rationale': stmt['context_rationale'],
                'interface_type': stmt.get('interface_type')
            })

        # Add design mandates (environment-specific design choices)
        des_stmts = [s for s in enriched if s['type'] == 'Design' and
                     s['context'] == 'Environment-specific']
        for stmt in des_stmts:
            context_data['design_mandates'].append({
                'id': stmt['id'],
                'statement': stmt['statement'],
                'context_rationale': stmt['context_rationale'],
                'pattern': stmt.get('pattern'),
                'note': 'Non-negotiable - environment-specific requirement'
            })

        return yaml.dump(context_data, default_flow_style=False, sort_keys=False, allow_unicode=True)

    def _generate_architect_seeds_md(self, enriched: List[Dict]) -> str:
        """Generate architect seeds with design patterns and complex flows."""
        lines = []
        lines.append("# Architectural Seeds & Design Guidance")
        lines.append(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        lines.append("")

        # Section 1: Design Patterns
        design_stmts = [s for s in enriched if s['type'] == 'Design']
        lines.append("## Design Patterns")
        lines.append("")
        if design_stmts:
            for stmt in design_stmts:
                lines.append(f"### {stmt['id']}: {stmt.get('pattern', 'Design Choice')}")
                lines.append(f"**Statement:** {stmt['statement']}")
                lines.append(f"**Context:** {stmt['context']}")
                lines.append(f"**Rationale:** {stmt['context_rationale']}")
                lines.append("")
        else:
            lines.append("*No design patterns extracted*")
            lines.append("")

        # Section 2: Complex Flows
        flow_stmts = [s for s in enriched if s['type'] == 'Flow']
        if flow_stmts:
            lines.append("## Complex Flows Requiring Design")
            lines.append("")
            for stmt in flow_stmts:
                lines.append(f"### {stmt['id']}")
                lines.append(f"**Flow:** {stmt['statement']}")
                lines.append(f"**Trigger:** {stmt.get('trigger', 'TBD')}")
                lines.append(f"**Outcome:** {stmt.get('outcome', 'TBD')}")
                lines.append("")

        # Section 3: Environment-Specific Mandates
        env_design = [s for s in enriched if s['type'] == 'Design' and
                      s['context'] == 'Environment-specific']
        if env_design:
            lines.append("## Mandated Design Choices (Environment-Specific)")
            lines.append("")
            lines.append("*These are non-negotiable requirements specific to the environment:*")
            lines.append("")
            for stmt in env_design:
                lines.append(f"- **{stmt['id']}**: {stmt['statement']}")
                lines.append(f"  - *Reason*: {stmt['context_rationale']}")
            lines.append("")

        # Section 4: Open Questions (placeholder)
        lines.append("## Open Questions")
        lines.append("")
        lines.append("*To be identified during architecture design phase:*")
        lines.append("")
        lines.append("- How to handle race conditions in concurrent workflows?")
        lines.append("- Cache strategy for reference data (currencies, purpose codes)?")
        lines.append("- Error granularity: store all errors or limit per file?")
        lines.append("")

        return '\n'.join(lines)

    async def _save_outputs(self, outputs: Dict[str, str]) -> None:
        """Save all output files to disk."""
        # Determine output directory (workspace or industry/output)
        output_dir = Path.cwd() / "industry" / "output"
        output_dir.mkdir(parents=True, exist_ok=True)

        for filename, content in outputs.items():
            filepath = output_dir / filename
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)
            logger.info(f"  ✓ Saved: {filepath}")

    # ========== Helper Methods ==========

    def _chunk_content(self, content: str, max_tokens: int = 3000) -> List[str]:
        """Split content into chunks to avoid token limits."""
        # Simple chunking by lines (can be improved with token counting)
        lines = content.split('\n')
        chunks = []
        current_chunk = []
        current_length = 0

        for line in lines:
            line_length = len(line.split())  # Rough token estimate
            if current_length + line_length > max_tokens and current_chunk:
                chunks.append('\n'.join(current_chunk))
                current_chunk = [line]
                current_length = line_length
            else:
                current_chunk.append(line)
                current_length += line_length

        if current_chunk:
            chunks.append('\n'.join(current_chunk))

        return chunks

    def _parse_numbered_list(self, response: str) -> List[str]:
        """Parse numbered list from LLM response."""
        lines = response.strip().split('\n')
        statements = []

        for line in lines:
            # Match patterns like "1. ", "1) ", "1: "
            match = re.match(r'^\s*\d+[\.\)\:]\s+(.+)$', line)
            if match:
                statements.append(match.group(1).strip())

        return statements

    def _extract_json(self, response: str) -> str:
        """Extract JSON from response (handle markdown code blocks)."""
        # Try to find JSON in code blocks
        json_match = re.search(r'```json\s*(\{.*?\})\s*```', response, re.DOTALL)
        if json_match:
            return json_match.group(1)

        # Try to find raw JSON
        json_match = re.search(r'(\{.*?\})', response, re.DOTALL)
        if json_match:
            return json_match.group(1)

        return response

    def _determine_routing(self, stmt_type: str) -> List[str]:
        """Determine routing destinations based on statement type."""
        routing_map = {
            'Intent': ['PM_Input'],
            'Requirement': ['PM_Input', 'Context'],
            'Constraint': ['Context'],
            'Flow': ['Context'],
            'Interface': ['Context'],
            'Design': ['Architect_Seeds']
        }
        return routing_map.get(stmt_type, [])

    def _infer_priority(self, statement: str) -> str:
        """Infer priority from statement content."""
        text = statement.lower()

        # P0 keywords
        if any(word in text for word in ['must', 'shall', 'required', 'mandatory', 'critical']):
            return 'P0'

        # P1 keywords
        if any(word in text for word in ['should', 'important', 'notification', 'status']):
            return 'P1'

        # Default P2
        return 'P2'

    def _infer_category(self, statement: str) -> str:
        """Infer category from statement content."""
        text = statement.lower()

        if 'upload' in text:
            return 'File Upload'
        elif 'template' in text or 'download' in text:
            return 'File Template Management'
        elif 'validat' in text or 'error' in text:
            return 'Validation & Errors'
        elif 'approv' in text:
            return 'Approval Workflow'
        elif 'status' in text or 'track' in text:
            return 'Status Tracking'
        elif 'notif' in text:
            return 'Notifications'
        else:
            return 'General'

    def _infer_enforcement(self, statement: str) -> str:
        """Infer enforcement type from constraint statement."""
        text = statement.lower()

        if 'must' in text or 'shall' in text:
            return 'Hard constraint'
        elif 'should' in text:
            return 'Soft constraint'
        else:
            return 'Validation rule'

    def _extract_validation_rule(self, statement: str) -> str:
        """Extract validation rule from constraint statement (simplified)."""
        # This is a simplified version - could use LLM for better extraction
        text = statement.lower()

        if 'positive' in text and 'amount' in text:
            return 'amount > 0'
        elif 'up to' in text or 'maximum' in text:
            match = re.search(r'(\d+[\,\d]*)', text)
            if match:
                return f'<= {match.group(1)}'
        elif 'required' in text or 'must include' in text:
            return 'NOT NULL'

        return 'TBD'

    def _extract_trigger(self, statement: str) -> str:
        """Extract trigger from flow statement."""
        text = statement.lower()

        if 'after' in text:
            match = re.search(r'after\s+(.+?),', text)
            if match:
                return match.group(1).strip()
        elif 'when' in text:
            match = re.search(r'when\s+(.+?),', text)
            if match:
                return match.group(1).strip()

        return 'TBD'

    def _extract_outcome(self, statement: str) -> str:
        """Extract outcome from flow statement."""
        # Simplified - take last part of statement
        parts = statement.split(',')
        if len(parts) > 1:
            return parts[-1].strip()
        return 'TBD'

    def _extract_pattern(self, statement: str) -> str:
        """Extract design pattern name from design statement."""
        text = statement.lower()

        if 'queue' in text or 'async' in text:
            return 'Async Processing Pattern'
        elif 'service' in text and 'layer' in text:
            return 'Service Layer Pattern'
        elif 'controller' in text:
            return 'Controller Pattern'
        elif 'scope' in text or 'tenant' in text:
            return 'Multi-Tenancy Pattern'
        else:
            return 'Design Pattern'


class LaravelIngestAgent(Role):
    """
    Requirements Ingestor Role - runs before ProductManager.

    Ingests mixed requirements documents and outputs structured,
    classified requirements files using TYPE + CONTEXT taxonomy.
    """

    name: str = "Alex"
    profile: str = "Requirements Ingestor"
    goal: str = "Extract and classify all requirements from source documents"

    def __init__(self, **kwargs):
        super().__init__(**kwargs)
        self._init_actions([IngestSpecsAction])
        self._watch([MESSAGE_ROUTE_TO_ALL])

    async def _act(self) -> Message:
        """Execute ingestion action."""
        logger.info(f"{self.name}: Starting requirements ingestion...")

        # Define source document paths (relative to project root)
        base_path = Path.cwd() / "industry"

        # Look for source documents
        txt_paths = []
        md_paths = []
        pdf_paths = []
        pptx_paths = []

        # Check for common document locations
        potential_files = [
            base_path / "injestion_action_alternate.txt",
            base_path / "enhanced_ingestion_process.md",
            base_path / "natural_language_requirements_v4.md",
            base_path / "requirements_classification_v4.md",
        ]

        for filepath in potential_files:
            if filepath.exists():
                if filepath.suffix == '.txt':
                    txt_paths.append(str(filepath))
                elif filepath.suffix == '.md':
                    md_paths.append(str(filepath))
                logger.info(f"  Found source document: {filepath.name}")

        if not txt_paths and not md_paths:
            logger.warning("No source documents found. Using default paths.")
            # Use defaults even if they don't exist (for demonstration)
            txt_paths = [str(base_path / "injestion_action_alternate.txt")]
            md_paths = [str(base_path / "enhanced_ingestion_process.md")]

        # Run ingestion
        try:
            outputs = await IngestSpecsAction().run(
                pdf_paths=pdf_paths,
                pptx_paths=pptx_paths,
                md_paths=md_paths,
                txt_paths=txt_paths
            )

            # Create message for next role
            msg = Message(
                content=f"Requirements ingestion completed. Generated {len(outputs)} output files.",
                role=self.profile,
                cause_by=IngestSpecsAction,
                send_to="LaravelProductManager"
            )

            return msg

        except Exception as e:
            logger.error(f"Ingestion failed: {e}")
            raise