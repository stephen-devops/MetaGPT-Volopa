#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : run_volopa_mass_payments.py
@Desc    : Example runner for Volopa Mass Payments API development using Laravel MetaGPT roles
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
    Run the Volopa Mass Payments API development team.

    Workflow:
    1. LaravelProductManager creates PRD
    2. LaravelArchitect creates System Design
    3. LaravelProjectManager creates Task Breakdown
    4. LaravelEngineer writes Laravel code
    5. LaravelQaEngineer writes PHPUnit tests
    """

    # Configure workspace
    workspace_path = Path(__file__).parent.parent / "workspace" / "volopa_mass_payments"

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
            ["git", "ls-files", "workspace/volopa_mass_payments"],
            cwd=project_root,
            capture_output=True,
            text=True,
            check=False
        )
        if git_check.stdout.strip():
            # Workspace files are tracked, unstage all changes
            subprocess.run(
                ["git", "reset", "HEAD", "workspace/volopa_mass_payments"],
                cwd=project_root,
                capture_output=True,
                check=False
            )
            # Clean untracked files and remove deleted files from working tree
            subprocess.run(
                ["git", "clean", "-fd", "workspace/volopa_mass_payments"],
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
    # Without this, MetaGPT creates workspace/volopa_mass_payments/volopa_mass_payments/
    # With this set to ".", code goes directly into workspace/volopa_mass_payments/
    src_workspace_file = workspace_path / ".src_workspace"
    if not src_workspace_file.exists():
        src_workspace_file.write_text(".")
        logger.info("Created .src_workspace file to place Laravel code at workspace root")

    config.update_via_cli(
        project_path=str(workspace_path),
        project_name="volopa_mass_payments",
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
    logger.info("Initializing Volopa Mass Payments development team...")
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
Build the Volopa Mass Payments API System for uploading CSV files with up to 10,000 payment instructions.

## Core Requirements

### File Upload & Management
1. CSV upload with drag-and-drop interface
2. Download recipient template with latest recipient details per currency
3. Download blank CSV template for payments
4. View uploaded file status (pending, validating, validated, failed, approved)
5. View file summary (total records, valid records, failed records)

### Validation
1. Validate file format and structure
2. Validate data integrity (required fields, data types, business rules)
3. Display validation errors with row-level detail
4. Check settlement methods for each recipient

### Approval Workflow
1. Currency-specific approval requirements (some currencies require approval)
2. Notify designated approvers (bell icon notification)
3. First-approver-wins pattern (only one approval needed)
4. Redirect first approver to create payments
5. Redirect subsequent approvers to draft payments page

### Payment Processing
1. Create payment instructions from validated CSV data
2. Support multiple currencies (INR requires invoice_number field)
3. Associate payments with recipients/beneficiaries
4. Retrieve payment purpose codes by country and currency

### Data Retrieval
1. Get all beneficiaries filtered by currency
2. Get beneficiaries associated with a specific file
3. Get all uploaded files for a client
4. Get draft files awaiting approval

## Technical Requirements

### Laravel Architecture
- Laravel 10+ with PHP 8.2+
- RESTful API under /api/v1 prefix
- OAuth2 and WSSE authentication support
- JSON responses with proper status codes
- API Resources for response transformation
- Queue-based async processing for large files

### Data Volume
- Support up to 10,000 payment rows per CSV file
- Efficient batch processing without timeouts
- Proper indexing for query performance

### Quality Standards
- Follow DOS/DONTS patterns (see dos_and_donts.pdf)
- Thin controllers with service layer
- FormRequest validation with policies
- Database transactions for multi-write operations
- Feature tests for all endpoints
- No N+1 queries (use eager loading)

## Success Criteria
- API endpoints are versioned, authenticated, and throttled
- File validation completes within 30 seconds for 10,000 rows
- Approval workflow prevents duplicate processing
- All responses use consistent JSON structure
- Code passes all feature tests
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
        python industry/run_volopa_mass_payments.py

        OR as a module:
        python -m industry.run_volopa_mass_payments

    Prerequisites:
        1. Configure MetaGPT (config/config2.yaml with LLM API keys)
        2. Install dependencies: pip install -r requirements.txt
        3. Ensure industry/roles/ modules are importable

    Output:
        workspace/volopa_mass_payments/
        ├── docs/
        │   ├── requirement.txt
        │   ├── prd/
        │   │   └── volopa_mass_payments.md
        │   ├── system_design/
        │   │   └── volopa_mass_payments.md
        │   └── task/
        │       └── volopa_mass_payments.json
        ├── app/
        │   ├── Http/
        │   │   ├── Controllers/
        │   │   ├── Requests/
        │   │   └── Resources/
        │   ├── Models/
        │   ├── Services/
        │   ├── Policies/
        │   └── ...
        └── tests/
            └── Feature/
                ├── MassPaymentFileTest.php
                ├── PaymentInstructionTest.php
                └── ...

    Notes:
        - The workflow is sequential: PM → Architect → ProjectManager → Engineer → QA
        - Each role watches for specific messages (WritePRD, WriteDesign, WriteTasks, WriteCode, WriteTest)
        - Documents are passed via Message.instruct_content (file paths)
        - Engineer loads all previous documents for context
        - QA Engineer loads requirements from industry/requirements/ JSON files
        - DOS/DONTS constraints are embedded in Engineer's and QA's system prompts
    """
    asyncio.run(main())
