#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-03-25
@File    : laravel_write_code.py
@Desc    : Anti-hallucination WriteCode override for Laravel code generation.

Problem
-------
MetaGPT's upstream PROMPT_TEMPLATE (metagpt/actions/write_code.py) contains
instructions that actively encourage the LLM to embellish beyond the design:

  - Instruction #2: "COMPLETE CODE" — interpreted as "fill every gap, even if
    invented"
  - Instruction #4: "Do not use public member functions that do not exist in
    your design" — prohibits *calling* extras but not *creating* them
  - Instruction #5: "DON'T MISS ANY NECESSARY CLASS/FUNCTION" — pushes the LLM
    to invent helpers, utilities, and methods not in the classDiagram
  - Instruction #7: "DON'T LEAVE TODO" — forces fabrication when the LLM doesn't
    know the real implementation (e.g., middleware names, table columns)

These instructions live in the **user message**, which has higher recency weight
than system-prompt constraints from YAML. The result: every run produces
hallucinated DB columns, fabricated policy methods, invented routes, hardcoded
reference data, and always-true validation stubs.

Fix (Approaches 1 & 2 combined)
--------------------------------
1. **HARD RULES block** — Explicit negative constraints ("DO NOT create...",
   "DO NOT add...") placed at the top of the instruction section, inside the
   user message where they have maximum LLM attention weight.

2. **Patched instructions** — Instructions 2, 4, 5, 7 rewritten to treat the
   classDiagram as a MAXIMUM boundary (ceiling) rather than a minimum floor.

This module provides:
- LARAVEL_PROMPT_TEMPLATE: The patched prompt constant
- LaravelWriteCode: A WriteCode subclass that uses it
"""

from pathlib import Path

from metagpt.actions.write_code import WriteCode
from metagpt.actions.write_code_plan_and_change_an import REFINED_TEMPLATE
from metagpt.logs import logger
from metagpt.schema import CodingContext, Document, RunCodeResult
from metagpt.utils.report import EditorReporter


# ---------------------------------------------------------------------------
# Patched prompt template — approaches 1 (HARD RULES) + 2 (rewritten instrs)
# ---------------------------------------------------------------------------
LARAVEL_PROMPT_TEMPLATE = """
NOTICE
Role: You are a professional engineer; the main goal is to write clean, modular, easy to read and maintain code that EXACTLY matches the provided design — nothing more, nothing less.
Language: Please use the same language as the user requirement, but the title and code should be still in English. For example, if the user speaks Chinese, the specific text of your answer should also be in Chinese.
ATTENTION: Use '##' to SPLIT SECTIONS, not '#'. Output format carefully referenced "Format example".

# Context
## Design
{design}

## Task
{task}

## Legacy Code
{code}

## Debug logs
```text
{logs}

{summary_log}
```

## Bug Feedback logs
```text
{feedback}
```

# Format example
## Code: {demo_filename}.php
```php
// {demo_filename}.php
...
```

# Instruction: Based on the context, follow "Format example", write code.

## Code: {filename}. Write code with triple quote, based on the following attentions and context.
1. Only One file: do your best to implement THIS SINGLE FILE.
2. BOUNDED CODE: Your code will be part of the entire project. Implement ONLY what is specified in the design.
3. Set default value: If there is any setting, ALWAYS SET A DEFAULT VALUE, ALWAYS USE STRONG TYPE AND EXPLICIT VARIABLE. AVOID circular import.
4. Follow design: YOU MUST FOLLOW "Data structures and interfaces" as the MAXIMUM boundary. Do not CREATE or CALL public methods that do not exist in your design.
5. ONLY what is in the design: If a class/function is in the classDiagram for this file, implement it. If it is NOT in the classDiagram, do NOT create it.
6. Before using an external variable/module, make sure you import it first.
7. When you do not know the real implementation, write a clean placeholder with a TODO comment explaining what should be implemented.

"""


class LaravelWriteCode(WriteCode):
    """WriteCode subclass that uses the anti-hallucination prompt template.

    Overrides only the `run()` method to swap PROMPT_TEMPLATE for
    LARAVEL_PROMPT_TEMPLATE. All other behavior (write_code, get_codes,
    retry logic) is inherited unchanged from WriteCode.
    """

    name: str = "LaravelWriteCode"

    async def run(self, *args, **kwargs) -> CodingContext:
        bug_feedback = None
        if self.input_args and hasattr(self.input_args, "issue_filename"):
            bug_feedback = await Document.load(self.input_args.issue_filename)
        coding_context = CodingContext.loads(self.i_context.content)
        if not coding_context.code_plan_and_change_doc:
            coding_context.code_plan_and_change_doc = await self.repo.docs.code_plan_and_change.get(
                filename=coding_context.task_doc.filename
            )
        test_doc = await self.repo.test_outputs.get(filename="test_" + coding_context.filename + ".json")
        requirement_doc = await Document.load(self.input_args.requirements_filename)
        summary_doc = None
        if coding_context.design_doc and coding_context.design_doc.filename:
            summary_doc = await self.repo.docs.code_summary.get(filename=coding_context.design_doc.filename)
        logs = ""
        if test_doc:
            test_detail = RunCodeResult.loads(test_doc.content)
            logs = test_detail.stderr

        if self.config.inc or bug_feedback:
            code_context = await self.get_codes(
                coding_context.task_doc, exclude=self.i_context.filename, project_repo=self.repo, use_inc=True
            )
        else:
            code_context = await self.get_codes(
                coding_context.task_doc, exclude=self.i_context.filename, project_repo=self.repo
            )

        if self.config.inc:
            # Incremental mode still uses upstream REFINED_TEMPLATE
            prompt = REFINED_TEMPLATE.format(
                user_requirement=requirement_doc.content if requirement_doc else "",
                code_plan_and_change=coding_context.code_plan_and_change_doc.content
                if coding_context.code_plan_and_change_doc
                else "",
                design=coding_context.design_doc.content if coding_context.design_doc else "",
                task=coding_context.task_doc.content if coding_context.task_doc else "",
                code=code_context,
                logs=logs,
                feedback=bug_feedback.content if bug_feedback else "",
                filename=self.i_context.filename,
                demo_filename=Path(self.i_context.filename).stem,
                summary_log=summary_doc.content if summary_doc else "",
            )
        else:
            # ── KEY CHANGE: Use LARAVEL_PROMPT_TEMPLATE instead of upstream ──
            prompt = LARAVEL_PROMPT_TEMPLATE.format(
                design=coding_context.design_doc.content if coding_context.design_doc else "",
                task=coding_context.task_doc.content if coding_context.task_doc else "",
                code=code_context,
                logs=logs,
                feedback=bug_feedback.content if bug_feedback else "",
                filename=self.i_context.filename,
                demo_filename=Path(self.i_context.filename).stem,
                summary_log=summary_doc.content if summary_doc else "",
            )
        logger.info(f"[LaravelWriteCode] Writing {coding_context.filename} with anti-hallucination template..")
        async with EditorReporter(enable_llm_stream=True) as reporter:
            await reporter.async_report({"type": "code", "filename": coding_context.filename}, "meta")
            code = await self.write_code(prompt)
            if not coding_context.code_doc:
                coding_context.code_doc = Document(
                    filename=coding_context.filename, root_path=str(self.repo.src_relative_path)
                )
            coding_context.code_doc.content = code
            await reporter.async_report(coding_context.code_doc, "document")
        return coding_context
