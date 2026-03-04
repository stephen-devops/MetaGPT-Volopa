#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-03-02
@File    : laravel_design_api.py
@Desc    : Laravel-specific WriteDesign action that uses custom ActionNodes
           with SYSTEM_CONSTRAINTS included.
"""
from metagpt.actions.design_api import NEW_REQ_TEMPLATE, WriteDesign

from industry.actions.laravel_design_api_an import DESIGN_API_NODE, REFINED_DESIGN_NODE


class LaravelWriteDesign(WriteDesign):
    """WriteDesign subclass that includes SYSTEM_CONSTRAINTS in the design prompt."""

    async def _new_system_design(self, context):
        node = await DESIGN_API_NODE.fill(req=context, llm=self.llm, schema=self.prompt_schema)
        return node

    async def _merge(self, prd_doc, system_design_doc):
        context = NEW_REQ_TEMPLATE.format(old_design=system_design_doc.content, context=prd_doc.content)
        node = await REFINED_DESIGN_NODE.fill(req=context, llm=self.llm, schema=self.prompt_schema)
        system_design_doc.content = node.instruct_content.model_dump_json()
        return system_design_doc
