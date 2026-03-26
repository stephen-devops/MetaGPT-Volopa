#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-03-25
@File    : laravel_write_prd.py
@Desc    : WritePRD override that uses custom ActionNodes from laravel_write_prd_an.py.

Wiring
------
Upstream WritePRD (metagpt/actions/write_prd.py) hard-imports its ActionNodes
from metagpt/actions/write_prd_an.py:

    _new_prd()  → WRITE_PRD_NODE.fill()      (new PRD generation)
    _merge()    → REFINED_PRD_NODE.fill()     (incremental PRD update)

This subclass overrides those two methods to use the custom ActionNodes
defined in industry/actions/laravel_write_prd_an.py, which carry
Laravel/Volopa-specific instructions and examples (e.g., expanded product
goals, comprehensive user stories, PHP/Laravel tech stack).

All other behavior (bugfix detection, document handling, workspace renaming,
competitive analysis chart) is inherited unchanged — the key strings are
identical so output parsing works without modification.
"""

from pathlib import Path

from metagpt.actions.write_prd import WritePRD, CONTEXT_TEMPLATE, NEW_REQ_TEMPLATE
from metagpt.actions.action_node import ActionNode
from metagpt.schema import Document

from industry.actions.laravel_write_prd_an import (
    PROJECT_NAME as LARAVEL_PROJECT_NAME,
    WRITE_PRD_NODE as LARAVEL_WRITE_PRD_NODE,
    REFINED_PRD_NODE as LARAVEL_REFINED_PRD_NODE,
)


class LaravelWritePRD(WritePRD):
    """WritePRD subclass that uses custom Laravel ActionNodes.

    Overrides only _new_prd() and _merge() to swap the upstream
    WRITE_PRD_NODE / REFINED_PRD_NODE with the custom versions from
    laravel_write_prd_an.py. All other behavior is inherited.
    """

    name: str = "LaravelWritePRD"

    async def _new_prd(self, requirement: str) -> ActionNode:
        """Generate a new PRD using custom Laravel ActionNodes."""
        project_name = self.project_name
        context = CONTEXT_TEMPLATE.format(requirements=requirement, project_name=project_name)
        exclude = [LARAVEL_PROJECT_NAME.key] if project_name else []
        node = await LARAVEL_WRITE_PRD_NODE.fill(
            req=context, llm=self.llm, exclude=exclude, schema=self.prompt_schema
        )
        return node

    async def _merge(self, req: Document, related_doc: Document) -> Document:
        """Merge requirements into existing PRD using custom Laravel ActionNodes."""
        if not self.project_name:
            self.project_name = Path(self.project_path).name
        prompt = NEW_REQ_TEMPLATE.format(requirements=req.content, old_prd=related_doc.content)
        node = await LARAVEL_REFINED_PRD_NODE.fill(req=prompt, llm=self.llm, schema=self.prompt_schema)
        related_doc.content = node.instruct_content.model_dump_json()
        await self._rename_workspace(node)
        return related_doc
