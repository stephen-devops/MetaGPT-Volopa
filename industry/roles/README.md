# Laravel MetaGPT Roles for Volopa Mass Payments System

This directory contains placeholder implementations of MetaGPT roles specialized for Laravel API development, specifically designed for the Volopa Mass Payments system.

## Role Hierarchy

```
LaravelProductManager  → subclasses → metagpt.roles.ProductManager
LaravelArchitect       → subclasses → metagpt.roles.Architect
LaravelProjectManager  → subclasses → metagpt.roles.ProjectManager
LaravelEngineer        → subclasses → metagpt.roles.Engineer
```

## Role Descriptions

### 1. LaravelProductManager
**File**: `laravel_product_manager.py`

**Responsibility**: Define business requirements for Laravel API system

**Allocated Intents**:
- getReceiptTemplate
- getClientDraftFiles
- getPaymentPurposeCodes
- getAllClientFiles
- getBeneficiariesByCurrency

**Output**: PRD (Product Requirements Document) at `docs/prd/mass_payments.md`

**Key Attributes**:
- `profile`: "Laravel Product Manager"
- `goal`: Create comprehensive PRD for Laravel Mass Payments API
- `constraints`: Laravel-specific requirement patterns, API specifications
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`)
- **React Loop**: `max_react_loop=1` (execute once and hand off to next role)

---

### 2. LaravelArchitect
**File**: `laravel_architect.py`

**Responsibility**: Design Laravel API system architecture

**Allocated Intents**:
- uploadPaymentFile
- validatePaymentFile
- getUploadedStatus
- getFileSummary
- getFileErrors
- getAllBeneficiariesByFileID
- getFileErrorsList

**Output**: System Design at `docs/system_design/mass_payments.md`

**Key Attributes**:
- `profile`: "Laravel System Architect"
- `goal`: Design Laravel API architecture following DOS/DONTS
- `constraints`: Laravel mental model, architecture patterns, file structure (simplified to avoid JSON parsing errors)
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`)
- **React Loop**: `max_react_loop=1` (execute once and hand off to next role)

**Output Structure**:
1. Implementation approach
2. File list (Laravel directory structure)
3. Data structures and interfaces (Mermaid classDiagram)
4. Program call flow (Mermaid sequenceDiagram)
5. Anything UNCLEAR

---

### 3. LaravelProjectManager
**File**: `laravel_project_manager.py`

**Responsibility**: Break down system design into dependency-ordered tasks

**Output**: Task Breakdown at `docs/task/mass_payments.json`

**Key Attributes**:
- `profile`: "Laravel Project Manager"
- `goal`: Break down system design with proper Laravel dependency order
- `constraints`: Laravel dependency rules (migrations → models → services → controllers), outputs filenames only (no code)
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`)
- **React Loop**: `max_react_loop=1` (execute once and hand off to next role)

**Output Structure**:
```json
{
  "Required packages": ["laravel/framework:^10.0", "league/csv:^9.0"],
  "Logic Analysis": [
    ["database/migrations/create_payment_files_table.php", "Migration for payment_files. No dependencies."],
    ["app/Models/PaymentFile.php", "Eloquent model. Depends on migration."]
  ],
  "Task list": ["migration.php", "PaymentFile.php", "MassPaymentService.php", ...],
  "Full API spec": "openapi: 3.0.0\n...",
  "Shared Knowledge": "All services use DB::transaction()...",
  "Anything UNCLEAR": "Clarify approval workflow timing"
}
```

---

### 4. LaravelEngineer
**File**: `laravel_engineer.py`

**Responsibility**: Write Laravel code following DOS/DONTS patterns

**Allocated Intents**:
- createPaymentInstructions
- approvePaymentFile
- updateFileStatus
- redirectPaymentConfirmation
- redirectDraftPayments

**Output**: Laravel source code at `app/**/*.php`

**Key Attributes**:
- `profile`: "Laravel API Developer"
- `goal`: Write Laravel code following DOS/DONTS and Volopa conventions
- `constraints`: **FULL DOS/DONTS from dos_and_donts.pdf** (embedded in system prompt), skips code plan phase to avoid JSON errors
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`) with `config.inc = False` (skip WriteCodePlanAndChange)
- **React Loop**: `max_react_loop=50` (allows multiple file generations from task list)

**ReAct + RAG Integration** (placeholder for future implementation):
- **_think()**: Reasons about implementation approach, queries RAG for examples
- **_act()**: Writes code using WriteCode action with retrieved patterns
- **_observe()**: Validates code against constraints, self-corrects if needed

---

## Workflow: Sequential Multi-Agent Pipeline (Fixed SOP Mode)

**IMPORTANT**: All roles use `use_fixed_sop: bool = True` to ensure sequential, deterministic workflow execution rather than dynamic ReAct mode. This was chosen because:
1. The workflow is inherently sequential (ProductManager → Architect → ProjectManager → Engineer)
2. Each role's output is deterministic (not exploratory)
3. Prevents infinite loops and repeated executions
4. Ensures exactly one execution per role per workflow round

```
UserRequirement
    ↓
[ROUND 1] LaravelProductManager (use_fixed_sop=True, max_react_loop=1)
    ├─ _observe(): Watches for UserRequirement message
    ├─ _think(): Set todo to PrepareDocuments + WritePRD (BY_ORDER mode)
    ├─ _act(): Execute PrepareDocuments → WritePRD actions sequentially
    ├─ Tracking: _prd_published flag prevents duplicate execution in subsequent rounds
    └─ Publishes: AIMessage(cause_by=WritePRD, instruct_content={prd_filenames})
    ↓
[ROUND 2] LaravelArchitect (use_fixed_sop=True, max_react_loop=1)
    ├─ _observe(): Watches for WritePRD message
    ├─ Loads: docs/prd/mass_payments.md
    ├─ _think(): Set todo to WriteDesign (BY_ORDER mode)
    ├─ _act(): WriteDesign action (injects PRD content into LLM prompt)
    └─ Publishes: AIMessage(cause_by=WriteDesign, instruct_content={design_filenames})
    ↓
[ROUND 3] LaravelProjectManager (use_fixed_sop=True, max_react_loop=1)
    ├─ _observe(): Watches for WriteDesign message
    ├─ Loads: docs/system_design/mass_payments.md
    ├─ _think(): Set todo to WriteTasks (BY_ORDER mode)
    ├─ _act(): WriteTasks action (injects design content into LLM prompt)
    └─ Publishes: AIMessage(cause_by=WriteTasks, instruct_content={task_filenames})
    ↓
[ROUND 4+] LaravelEngineer (use_fixed_sop=True, max_react_loop=50, config.inc=False)
    ├─ _observe(): Watches for WriteTasks message
    ├─ Loads: docs/task/mass_payments.json + docs/system_design/mass_payments.md
    ├─ _think(): Parse task list, create WriteCode actions for each file (skips WriteCodePlanAndChange)
    ├─ _act(): WriteCode action for each file in dependency order (loops up to 50 times)
    │   ├─ Loop 1: database/migrations/create_mass_payments_table.php
    │   ├─ Loop 2: database/migrations/create_beneficiaries_table.php
    │   ├─ Loop 3: app/Models/MassPayment.php
    │   └─ ... (up to 31 files)
    └─ Output: app/Http/Controllers/..., app/Services/..., database/migrations/..., etc.
```

**Key Differences from Dynamic ReAct Mode**:
- **Fixed SOP**: Roles use `BY_ORDER` mode with predefined action sequences
- **Single Execution**: `max_react_loop=1` for PM/Architect/ProjectManager ensures one execution
- **No Re-triggering**: ProductManager tracks `_prd_published` to prevent re-execution in subsequent rounds
- **Sequential Handoff**: Each role completes fully before next role starts (waterfall pattern)
- **Predictable Rounds**: `n_round=4` is sufficient (1 round per role in sequence)

---

## Message Integration: instruct_content

Each role publishes a message with `instruct_content` containing metadata:

```python
# LaravelProductManager output
AIMessage(
    content="PRD created",
    cause_by=WritePRD,
    instruct_content={
        "project_path": "/workspace/volopa_mass_payments",
        "changed_prd_filenames": ["mass_payments.md"]
    }
)

# LaravelArchitect reads instruct_content, loads PRD file
prd_path = project_path / "docs/prd/mass_payments.md"
prd_doc = await Document.load(prd_path)

# Architect injects PRD into LLM prompt:
# "Based on this PRD: {prd_doc.content}, design the system..."
```

---

## DOS/DONTS Integration

The **LaravelEngineer** role has the **full DOS/DONTS** embedded in its `constraints` attribute:

```python
class LaravelEngineer(Engineer):
    constraints: str = """
    MENTAL MODEL:
    Client → route → controller → FormRequest → service → model → Resource → JSON

    DOS:
    - Keep controllers thin
    - Use DB::transaction() for multi-write
    - Return proper status codes (201, 200, 422, 403, 404)
    ...

    DON'TS:
    - Don't return raw Eloquent models
    - Don't create N+1 queries
    - Don't forget transactions
    ...
    """
```

This constraints string is automatically:
1. Set as `self.llm.system_prompt` during role initialization (via `_get_prefix()`)
2. Included in **every LLM call** made by the Engineer
3. Used during ReAct `_think()` phase to guide implementation decisions

---

## System Prompt Construction

Each role's system prompt is built from:

```python
# role.py:323-338
def _get_prefix(self):
    prefix = f"You are a {self.profile}, named {self.name}, your goal is {self.goal}. "

    if self.constraints:
        prefix += f"the constraint is {self.constraints}. "

    if self.rc.env:
        prefix += f"You are in {self.rc.env.desc} with roles({other_roles})."

    return prefix

# Set on LLM during initialization
self.llm.system_prompt = self._get_prefix()
```

**Example for LaravelEngineer**:
```
You are a Laravel API Developer, named LaravelEngineer, your goal is Write
Laravel code following DOS/DONTS patterns and Volopa conventions. the constraint is:

MENTAL MODEL:
Client → route → controller → FormRequest → service → model → Resource → JSON

DOS:
- Keep controllers thin
- Use DB::transaction() for multi-write
[... full DOS list ...]

DON'TS:
- Don't return raw Eloquent models
[... full DON'TS list ...]

You are in Team environment with roles(LaravelPM, LaravelArchitect, LaravelPM_Eve).
```

---

## Future Enhancements (TODOs)

### LaravelProductManager
- [ ] Add Laravel-specific PRD templates
- [ ] Add API endpoint specification helpers
- [ ] Add Laravel package recommendation logic
- [ ] Integrate Volopa business rules (currency-specific requirements, approval workflows)

### LaravelArchitect
- [ ] Add Laravel-specific system design templates
- [ ] Add Mermaid diagram generators for Laravel patterns
- [ ] Add database schema design helpers (migration templates)
- [ ] Integrate Volopa-specific patterns (WSSE auth, approval workflows)
- [ ] Add OpenAPI/Swagger specification generator

### LaravelProjectManager
- [ ] Add Laravel-specific dependency analyzer
- [ ] Add task ordering algorithm (topological sort)
- [ ] Add Laravel package recommender based on system design
- [ ] Add OpenAPI spec generator from system design
- [ ] Integrate Volopa-specific shared knowledge

### LaravelEngineer
- [ ] **Implement SearchCodeBase action** for RAG integration
- [ ] Add RAG query generation based on file type and intent
- [ ] Integrate with AWS OpenSearch (Volopa's knowledge base)
- [ ] Add code validation against DOS/DONTS patterns
- [ ] Add self-correction loop for constraint violations
- [ ] Add Laravel-specific code templates

---

## Usage Example

```python
# industry/run_volopa_mass_payments.py
import asyncio
from metagpt.config2 import config
from metagpt.context import Context
from metagpt.team import Team

from industry.roles import (
    LaravelProductManager,
    LaravelArchitect,
    LaravelProjectManager,
    LaravelEngineer
)

async def main():
    # 1. Setup context
    config.update_via_cli(
        project_path="./workspace/volopa_mass_payments",
        project_name="volopa_mass_payments"
    )
    ctx = Context(config=config)

    # 2. Create team and hire Laravel roles
    company = Team(context=ctx)
    company.hire([
        LaravelProductManager(),
        LaravelArchitect(),
        LaravelProjectManager(),
        LaravelEngineer()
    ])

    # 3. Set investment (budget for LLM calls)
    company.invest(investment=10.0)

    # 4. Run with user requirement
    idea = """
    Build the Volopa Mass Payments API System for uploading CSV files with 10000 payments.
    The system must support:
    - CSV upload with drag-and-drop
    - File validation (format, data integrity)
    - Approval workflows for specific currencies
    - Real-time status tracking
    - Notification system for approvers
    """

    await company.run(n_round=6, idea=idea)

if __name__ == "__main__":
    asyncio.run(main())
```

---

## Directory Structure

```
industry/
├── roles/
│   ├── __init__.py                      # Package exports
│   ├── README.md                        # This file
│   ├── laravel_product_manager.py       # ProductManager subclass
│   ├── laravel_architect.py             # Architect subclass
│   ├── laravel_project_manager.py       # ProjectManager subclass
│   └── laravel_engineer.py              # Engineer subclass
├── dos_and_donts.pdf                    # Laravel patterns reference
├── volopaProcess.md                     # Business process flow
├── massPaymentsVolopaAgents.txt         # Intent allocation
└── metaGPT-LLM-ReAct-RAG-Readme.txt    # RAG integration notes
```

---

## Notes

1. **All roles are placeholders** - They inherit base functionality from MetaGPT but don't yet have Volopa-specific customizations

2. **DOS/DONTS are embedded** in LaravelEngineer's constraints - This is the most critical integration point

3. **RAG integration is TODO** - SearchCodeBase action needs to be implemented to query Volopa's Laravel examples

4. **Team-level SOP is active** - Message routing (PM → Architect → PM → Engineer) is handled by MetaGPT's environment

5. **Role-level mode is Fixed SOP** - All roles use `use_fixed_sop: bool = True` for sequential, deterministic execution (NOT dynamic ReAct mode)

6. **These can be customized** - Add Volopa-specific logic, templates, and validators as needed

---

## References

- MetaGPT Framework: [https://github.com/geekan/MetaGPT](https://github.com/geekan/MetaGPT)
- Laravel Documentation: [https://laravel.com/docs](https://laravel.com/docs)
- Volopa DOS/DONTS: `../dos_and_donts.pdf`
- Mass Payments Workflow: `../volopaProcess.md`
- Intent Allocation: `../massPaymentsVolopaAgents.txt`
