#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : run_volopa_oop_expenses.py
@Desc    : Example runner for Volopa OOP Expenses API development using Laravel MetaGPT roles
"""

import asyncio
import sys
import os
from pathlib import Path

# Fix Windows encoding issues with Unicode characters
if sys.platform == 'win32':
    # Set console to UTF-8 mode
    os.system('chcp 65001 >nul 2>&1')
    # Force UTF-8 encoding for stdout/stderr
    import codecs
    if sys.stdout.encoding != 'utf-8':
        sys.stdout = codecs.getwriter('utf-8')(sys.stdout.buffer, 'strict')
    if sys.stderr.encoding != 'utf-8':
        sys.stderr = codecs.getwriter('utf-8')(sys.stderr.buffer, 'strict')

# Add project root to Python path so we can import industry.roles
project_root = Path(__file__).parent.parent
sys.path.insert(0, str(project_root))

from metagpt.config2 import config
from metagpt.context import Context
from metagpt.team import Team
from metagpt.logs import logger

from industry.roles import (
    LaravelProductManager,
    LaravelArchitect,
    LaravelProjectManager,
    LaravelEngineer,
    LaravelQaEngineer,
)


async def main():
    """
    Run the Volopa OOP Expenses API development team.

    Workflow:
    1. LaravelProductManager creates PRD
    2. LaravelArchitect creates System Design
    3. LaravelProjectManager creates Task Breakdown
    4. LaravelEngineer writes Laravel code
    5. LaravelQaEngineer writes PHPUnit tests
    """

    # Configure workspace
    workspace_path = Path(__file__).parent.parent / "workspace" / "volopa_oop_expenses"

    # FIX: Delete existing workspace to prevent "unknown origin" errors
    # MetaGPT's Engineer role tracks which files it creates during a run.
    # If it finds existing files from previous runs, it raises ValueError to prevent overwrites.
    # Solution: Clean workspace before each run for a fresh start.
    if workspace_path.exists():
        import shutil
        shutil.rmtree(workspace_path)
        logger.info(f"Deleted existing workspace for fresh start: {workspace_path}")

    # CRITICAL FIX: Clean git state to prevent "unknown origin" errors
    # After deleting workspace, git still tracks files as deleted (D status).
    # Engineer queries git status and sees these deleted files, tries to process them,
    # then fails because there's no task document for them.
    # Solution: Reset git state for workspace directory.
    import subprocess
    try:
        # Check if workspace directory is tracked by git
        git_check = subprocess.run(
            ["git", "ls-files", "workspace/volopa_oop_expenses"],
            cwd=project_root,
            capture_output=True,
            text=True,
            check=False
        )
        if git_check.stdout.strip():
            # Workspace files are tracked, unstage all changes
            subprocess.run(
                ["git", "reset", "HEAD", "workspace/volopa_oop_expenses"],
                cwd=project_root,
                capture_output=True,
                check=False
            )
            # Clean untracked files and remove deleted files from working tree
            subprocess.run(
                ["git", "clean", "-fd", "workspace/volopa_oop_expenses"],
                cwd=project_root,
                capture_output=True,
                check=False
            )
            logger.info("Reset git state for workspace directory")
    except Exception as e:
        logger.warning(f"Could not reset git state (non-critical): {e}")

    workspace_path.mkdir(parents=True, exist_ok=True)

    # CRITICAL FIX: Delete cached team state to ensure fresh role initialization
    # Without this, team.json deserialization overrides our role configurations
    storage_path = workspace_path.parent / "storage" / "team"
    if storage_path.exists():
        import shutil
        shutil.rmtree(storage_path)
        logger.info("Deleted cached team state for fresh role initialization with use_fixed_sop=True")

    # FIX: Create .src_workspace to prevent nested directory structure
    # Without this, MetaGPT creates workspace/volopa_oop_expenses/volopa_oop_expenses/
    # With this set to ".", code goes directly into workspace/volopa_oop_expenses/
    src_workspace_file = workspace_path / ".src_workspace"
    if not src_workspace_file.exists():
        src_workspace_file.write_text(".")
        logger.info("Created .src_workspace file to place Laravel code at workspace root")

    config.update_via_cli(
        project_path=str(workspace_path),
        project_name="volopa_oop_expenses",
        inc=False,  # Incremental mode (False = start fresh)
        reqa_file="",  # Optional requirements file path
        max_auto_summarize_code=0,  # Max code size for auto-summarization (0 = no limit)
    )

    # Create context with project_path in kwargs
    from metagpt.context import AttrDict
    ctx = Context(
        config=config,
        kwargs=AttrDict(project_path=str(workspace_path))
    )

    # Create team and hire Laravel-specific roles
    logger.info("Initializing Volopa OOP Expenses development team...")
    # use_mgx=False to avoid requiring a TeamLeader role
    company = Team(context=ctx, use_mgx=False)

    # Pass context to roles explicitly
    company.hire([
        LaravelProductManager(context=ctx),
        LaravelArchitect(context=ctx),
        LaravelProjectManager(context=ctx),
        LaravelEngineer(context=ctx),
        LaravelQaEngineer(context=ctx),
    ])

    # Set investment (budget for LLM API calls)
    investment = 10.0  # $10 USD
    company.invest(investment=investment)
    logger.info(f"Investment set to ${investment}")

    # Define the requirement
    idea = """
Build the Volopa OOP (Out-of-Pocket) Expenses API System using Laravel 10+ with PHP 8.2+.

Three modules:
1. User Management & Permissions — RBAC via user_feature_permission with delegation hierarchy
2. Pocket Expense CSV Batch Upload — synchronous validation, async background sync
3. Single Expense Data Capturing — CRUD with FX conversion and flexible metadata

All domain requirements, constraints, database schemas, API contracts, flows, and platform
standards are defined in the YAML context injected into your system prompt. Use those as the
authoritative source. Do not invent requirements beyond what the YAML specifies.
"""

    # Run the team
    # n_round should be at least 5 to allow all 5 roles to complete their work in sequence:
    # Round 1: LaravelProductManager (PrepareDocuments + WritePRD)
    # Round 2: LaravelArchitect (WriteDesign)
    # Round 3: LaravelProjectManager (WriteTasks)
    # Round 4: LaravelEngineer (WriteCode)
    # Round 5: LaravelQaEngineer (WriteTest)
    logger.info("Starting development workflow...")
    logger.info("=" * 60)

    await company.run(
        n_round=5,
        idea=idea,
        send_to="",  # Broadcast to all roles
        auto_archive=True,  # Archive results to git
    )

    logger.info("=" * 60)
    logger.info("Development workflow completed!")
    logger.info(f"Output location: {workspace_path}")
    logger.info("\nGenerated artifacts:")
    logger.info(f"  - PRD: {workspace_path}/docs/prd/")
    logger.info(f"  - System Design: {workspace_path}/docs/system_design/")
    logger.info(f"  - Task Breakdown: {workspace_path}/docs/task/")
    logger.info(f"  - Laravel Code: {workspace_path}/app/")
    logger.info(f"  - PHPUnit Tests: {workspace_path}/tests/Feature/")

    return workspace_path


if __name__ == "__main__":
    """
    Usage (from project root):
        python industry/run_volopa_oop_expenses.py

        OR as a module:
        python -m industry.run_volopa_oop_expenses

    Prerequisites:
        1. Configure MetaGPT (config/config2.yaml with LLM API keys)
        2. Install dependencies: pip install -r requirements.txt
        3. Ensure industry/roles/ modules are importable

    Output:
        workspace/volopa_oop_expenses/
        ├── docs/
        │   ├── requirement.txt
        │   ├── prd/
        │   │   └── volopa_oop_expenses.md
        │   ├── system_design/
        │   │   └── volopa_oop_expenses.md
        │   └── task/
        │       └── volopa_oop_expenses.json
        ├── database/
        │   ├── factories/
        │   │   ├── UserFeaturePermissionFactory.php
        │   │   └── ...
        │   └── migrations/
        │       ├── 2024_01_01_000001_create_user_feature_permission_table.php
        │       └── ...
        ├── app/
        │   ├── Http/
        │   │   ├── Controllers/
        │   │   │   └── Api/
        │   │   │       └── V1/
        │   │   │           ├── PocketExpenseController.php
        │   │   │           ├── UserFeaturePermissionController.php
        │   │   │           └── ...
        │   │   ├── Requests/
        │   │   └── Resources/
        │   ├── Models/
        │   ├── Services/
        │   ├── Policies/
        │   └── ...
        ├── routes/
        │   └── api.php
        └── tests/
            └── Feature/
                ├── PocketExpenseTest.php
                ├── PocketExpenseUploadTest.php
                └── ...

    Notes:
        - The workflow is sequential: PM → Architect → ProjectManager → Engineer → QA
        - Each role watches for specific messages (WritePRD, WriteDesign, WriteTasks, WriteCode, WriteTest)
        - Documents are passed via Message.instruct_content (file paths)
        - Engineer loads all previous documents for context
        - QA Engineer loads requirements from industry/requirements/updated_req YAML files
        - DOS/DONTS constraints are embedded in Engineer's and QA's system prompts
    """
    asyncio.run(main())
