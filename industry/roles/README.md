# Laravel MetaGPT Roles for Volopa Mass Payments System

This directory contains placeholder implementations of MetaGPT roles specialized for Laravel API development, specifically designed for the Volopa Mass Payments system.

## Role Hierarchy

```
LaravelProductManager  → subclasses → metagpt.roles.ProductManager
LaravelArchitect       → subclasses → metagpt.roles.Architect
LaravelProjectManager  → subclasses → metagpt.roles.ProjectManager
LaravelEngineer        → subclasses → metagpt.roles.Engineer
LaravelQaEngineer      → subclasses → metagpt.roles.QaEngineer
```

---

## NLP Requirements Loading

All roles automatically load natural language requirements from markdown files in `industry/requirements/` to inject domain knowledge into their constraints:

| Role | Files Loaded | Purpose |
|------|-------------|---------|
| **LaravelProductManager** | `user_requirements_nlp.md` | 87 functional requirements (business perspective) to transform into PRD |
| **LaravelArchitect** | `architectural_requirements_nlp.md` | 110 design patterns, mental model, DOS/DONTS principles |
| **LaravelProjectManager** | `user_requirements_nlp.md` | 87 functional requirements for task breakdown mapping |
| **LaravelEngineer** | `architectural_requirements_nlp.md` + `technical_requirements_nlp.md` | 110 design patterns + 138 implementation requirements |
| **LaravelQaEngineer** | **ALL THREE** NLP markdown files | Complete requirements for comprehensive testing (335 total requirements) |

### NLP Markdown Files Structure

**`user_requirements_nlp.md`** (Functional Requirements - v5.0)
- **87 numbered requirements** across 12 sections (enhanced from 76 in v4.0)
- Pure business and user perspective (no technical implementation details)
- Organized by user workflow: Template Management, Data Entry, File Upload, Validation, Approval, Status Tracking, Security, Notifications, Audit, User Experience, Platform Integration
- **Critical additions in v5.0**: Multi-tenant data isolation (requirements 68-71), Platform integration (84-87), Recipient queries (12-13), Navigation path (22)

**`architectural_requirements_nlp.md`** (Design Patterns)
- **110 architectural requirements** extracted from dos_and_donts.pdf
- Mental model: `Client → route → controller → FormRequest → service/model → API Resource → JSON`
- Organized by layers: Routing, Controllers, Validation, Services, Models, Resources, Security, Performance
- Architectural DOS principles and DONTS anti-patterns in natural language

**`technical_requirements_nlp.md`** (Implementation Syntax)
- **138 technical implementation requirements** for Laravel syntax
- Organized by: Route Definition, Controller Structure, FormRequest Validation, Service Layer, Eloquent Models, API Resources, Policies, Jobs, Migrations
- Implementation DOS/DONTS with code pattern descriptions

### Loading Implementation

Each role loads requirements in `__init__()` using regex parsers and injects them into constraints:

```python
# Example: LaravelProductManager
def __init__(self, **kwargs):
    super().__init__(**kwargs)

    # Load functional requirements from NLP markdown
    self.requirements = self._load_requirements()  # Returns parsed dict from .md file

    # Parse NLP markdown using regex patterns
    # Pattern: **N.** The system shall...
    parsed_requirements = self._parse_nlp_requirements(content)

    # Update constraints with loaded data
    self._update_constraints_from_requirements()
```

The loaded requirements are automatically included in every LLM call via the role's `constraints` attribute.

### NLP Parsing Implementation

Requirements are extracted using regex patterns:

```python
import re

# Extract sections: ## 1. Section Name
section_pattern = re.compile(r'^##\s+(\d+)\.\s+(.+)', re.MULTILINE)

# Extract requirements: **N.** The system shall...
requirement_pattern = re.compile(r'^\*\*(\d+)\.\*\*\s+(.+)', re.MULTILINE)

# Extract subsections: ### 1.1 Subsection Name
subsection_pattern = re.compile(r'^###\s+([\d.]+)\s+(.+)', re.MULTILINE)
```

---

## Role Descriptions

### 1. LaravelProductManager
**File**: `laravel_product_manager.py`

**Responsibility**: Define business requirements for Laravel API system

**NLP Requirements**: Loads `user_requirements_nlp.md` (87 functional requirements)

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
- `constraints`: Laravel-specific requirement patterns + loaded functional requirements from NLP markdown (87 requirements parsed via regex)
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`)
- **React Loop**: `max_react_loop=1` (execute once and hand off to next role)

---

### 2. LaravelArchitect
**File**: `laravel_architect.py`

**Responsibility**: Design Laravel API system architecture

**NLP Requirements**: Loads `architectural_requirements_nlp.md` (110 design patterns, mental model, DOS/DONTS)

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
- `constraints`: Laravel mental model, 110 architecture patterns from NLP markdown, file structure
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

**NLP Requirements**: Loads `user_requirements_nlp.md` (87 functional requirements for task mapping)

**Output**: Task Breakdown at `docs/task/mass_payments.json`

**Key Attributes**:
- `profile`: "Laravel Project Manager"
- `goal`: Break down system design with proper Laravel dependency order
- `constraints`: Laravel dependency rules from NLP requirements (migrations → models → services → controllers), outputs filenames only (no code)
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

**NLP Requirements**: Loads `architectural_requirements_nlp.md` + `technical_requirements_nlp.md` (110 design patterns + 138 implementation requirements)

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
- `constraints`: **110 architectural patterns + 138 implementation requirements from NLP markdown**, skips code plan phase to avoid JSON errors
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`) with `config.inc = False` (skip WriteCodePlanAndChange)
- **React Loop**: `max_react_loop=50` (allows multiple file generations from task list)

**ReAct + RAG Integration** (placeholder for future implementation):
- **_think()**: Reasons about implementation approach, queries RAG for examples
- **_act()**: Writes code using WriteCode action with retrieved patterns
- **_observe()**: Validates code against constraints, self-corrects if needed

---

### 5. LaravelQaEngineer
**File**: `laravel_qa_engineer.py`

**Responsibility**: Write comprehensive PHPUnit/Pest tests for Laravel APIs

**NLP Requirements**: Loads **ALL THREE** NLP markdown files:
- `user_requirements_nlp.md` - Test 87 functional requirements
- `architectural_requirements_nlp.md` - Test 110 architectural patterns
- `technical_requirements_nlp.md` - Test 138 implementation requirements

**Allocated Intents**:
- validatePaymentData
- validateRecipientData
- testMultiTenantIsolation
- testTransactionIntegrity
- testAuthorizationRules

**Output**: PHP test files at `tests/Feature/**/*Test.php`

**Key Attributes**:
- `profile`: "Laravel QA Engineer"
- `goal`: Write comprehensive PHP Unit tests ensuring Laravel code follows DOS/DONTS patterns
- `constraints`: **Complete test requirements from all three NLP markdown files (335 total requirements)** - validates functional, architectural, and implementation correctness
- **Mode**: Fixed SOP (`use_fixed_sop: bool = True`)
- **React Loop**: `max_react_loop=50` (allows writing multiple test files)

**Test Coverage Requirements**:
- **100% functional requirements** - All 87 functional requirements tested (including critical multi-tenant isolation requirements 68-71)
- **100% architectural patterns** - Transactions, N+1 prevention, pagination, multi-tenant isolation, mental model adherence
- **100% endpoints** - Authentication, authorization, validation, status codes
- **100% anti-patterns** - Ensure no raw models, consistent JSON, proper error handling

**Test Organization**:
```
tests/Feature/
├── MassPaymentFileTest.php          (CRUD, validation, authorization)
├── PaymentInstructionTest.php       (Payment creation, approval)
├── RecipientTemplateTest.php        (Template download)
├── StatusTransitionTest.php         (State machine)
├── MultiTenantIsolationTest.php     (Client data isolation)
├── TransactionIntegrityTest.php     (Rollback scenarios)
└── ValidationRulesTest.php          (All validation rules)
```

**Key Testing Patterns**:
- Uses `RefreshDatabase` trait for isolation
- Uses factories for test data (NOT manual creation)
- Tests happy path AND error scenarios
- Asserts JSON structure AND database state
- Tests authorization before functionality
- Tests multi-tenant isolation for every endpoint
- Tests N+1 queries using query log
- Tests proper status codes (200, 201, 204, 401, 403, 404, 422)

---

## Workflow: Sequential Multi-Agent Pipeline (Fixed SOP Mode)

**IMPORTANT**: All roles use `use_fixed_sop: bool = True` to ensure sequential, deterministic workflow execution rather than dynamic ReAct mode. This was chosen because:
1. The workflow is inherently sequential (ProductManager → Architect → ProjectManager → Engineer → QaEngineer)
2. Each role's output is deterministic (not exploratory)
3. Prevents infinite loops and repeated executions
4. Ensures exactly one execution per role per workflow round

```
UserRequirement
    ↓
[ROUND 1] LaravelProductManager (use_fixed_sop=True, max_react_loop=1)
    ├─ Loads NLP: user_requirements_nlp.md (87 requirements parsed via regex)
    ├─ _observe(): Watches for UserRequirement message
    ├─ _think(): Set todo to PrepareDocuments + WritePRD (BY_ORDER mode)
    ├─ _act(): Execute PrepareDocuments → WritePRD actions sequentially
    ├─ Tracking: _prd_published flag prevents duplicate execution in subsequent rounds
    └─ Publishes: AIMessage(cause_by=WritePRD, instruct_content={prd_filenames})
    ↓
[ROUND 2] LaravelArchitect (use_fixed_sop=True, max_react_loop=1)
    ├─ Loads NLP: architectural_requirements_nlp.md (110 design patterns parsed)
    ├─ _observe(): Watches for WritePRD message
    ├─ Loads: docs/prd/mass_payments.md
    ├─ _think(): Set todo to WriteDesign (BY_ORDER mode)
    ├─ _act(): WriteDesign action (injects PRD + NLP patterns into LLM prompt)
    └─ Publishes: AIMessage(cause_by=WriteDesign, instruct_content={design_filenames})
    ↓
[ROUND 3] LaravelProjectManager (use_fixed_sop=True, max_react_loop=1)
    ├─ Loads NLP: user_requirements_nlp.md (87 requirements for task mapping)
    ├─ _observe(): Watches for WriteDesign message
    ├─ Loads: docs/system_design/mass_payments.md
    ├─ _think(): Set todo to WriteTasks (BY_ORDER mode)
    ├─ _act(): WriteTasks action (injects design + NLP mappings into LLM prompt)
    └─ Publishes: AIMessage(cause_by=WriteTasks, instruct_content={task_filenames})
    ↓
[ROUND 4-5] LaravelEngineer (use_fixed_sop=True, max_react_loop=50, config.inc=False)
    ├─ Loads NLP: architectural_requirements_nlp.md + technical_requirements_nlp.md (248 requirements total)
    ├─ _observe(): Watches for WriteTasks message
    ├─ Loads: docs/task/mass_payments.json + docs/system_design/mass_payments.md
    ├─ _think(): Parse task list, create WriteCode actions for each file (skips WriteCodePlanAndChange)
    ├─ _act(): WriteCode action for each file in dependency order (loops up to 50 times)
    │   ├─ Loop 1: database/migrations/create_mass_payments_table.php
    │   ├─ Loop 2: database/migrations/create_beneficiaries_table.php
    │   ├─ Loop 3: app/Models/MassPayment.php
    │   └─ ... (up to 40 files)
    └─ Output: app/Http/Controllers/..., app/Services/..., database/migrations/..., etc.
    ↓
[ROUND 6+] LaravelQaEngineer (use_fixed_sop=True, max_react_loop=50)
    ├─ Loads NLP: ALL THREE (user_requirements_nlp.md + architectural_requirements_nlp.md + technical_requirements_nlp.md = 335 requirements)
    ├─ _observe(): Watches for WriteCode messages (when Engineer completes)
    ├─ Loads: All generated code files for context
    ├─ _think(): Create WriteTest actions for each test file
    ├─ _act(): WriteTest action for each test file (loops up to 50 times)
    │   ├─ Loop 1: tests/Feature/MassPaymentFileTest.php
    │   ├─ Loop 2: tests/Feature/PaymentInstructionTest.php
    │   ├─ Loop 3: tests/Feature/MultiTenantIsolationTest.php (tests critical requirements 68-71)
    │   └─ ... (estimated 15-20 test files covering all 87 functional requirements)
    └─ Output: tests/Feature/**/*Test.php with 100% coverage
```

**Key Differences from Dynamic ReAct Mode**:
- **Fixed SOP**: Roles use `BY_ORDER` mode with predefined action sequences
- **Single Execution**: `max_react_loop=1` for PM/Architect/ProjectManager ensures one execution
- **No Re-triggering**: ProductManager tracks `_prd_published` to prevent re-execution in subsequent rounds
- **Sequential Handoff**: Each role completes fully before next role starts (waterfall pattern)
- **Predictable Rounds**: `n_round=6+` is sufficient (1-2 rounds per role in sequence)
- **NLP Loading**: Each role loads relevant requirements from markdown files automatically in `__init__()` using regex parsers

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
    LaravelEngineer,
    LaravelQaEngineer
)

async def main():
    # 1. Setup context
    config.update_via_cli(
        project_path="./workspace/volopa_mass_payments",
        project_name="volopa_mass_payments"
    )
    ctx = Context(config=config)

    # 2. Create team and hire Laravel roles (all roles automatically load NLP requirements)
    company = Team(context=ctx)
    company.hire([
        LaravelProductManager(),   # Loads user_requirements_nlp.md (87 requirements)
        LaravelArchitect(),        # Loads architectural_requirements_nlp.md (110 requirements)
        LaravelProjectManager(),   # Loads user_requirements_nlp.md (87 requirements)
        LaravelEngineer(),         # Loads architectural + technical NLP requirements (248 total)
        LaravelQaEngineer()        # Loads ALL THREE NLP files (335 requirements)
    ])

    # 3. Set investment (budget for LLM calls)
    company.invest(investment=15.0)  # Increased for QA tests

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

    # 5. Run workflow (6+ rounds to include QA testing)
    await company.run(n_round=8, idea=idea)

if __name__ == "__main__":
    asyncio.run(main())
```

---

## Directory Structure

```
industry/
├── roles/
│   ├── __init__.py                          # Package exports
│   ├── README.md                            # This file
│   ├── laravel_product_manager.py           # ProductManager subclass with NLP parser
│   ├── laravel_architect.py                 # Architect subclass with NLP parser
│   ├── laravel_project_manager.py           # ProjectManager subclass with NLP parser
│   ├── laravel_engineer.py                  # Engineer subclass with NLP parser
│   └── laravel_qa_engineer.py               # QaEngineer subclass with NLP parser
├── requirements/                            # NLP requirements files
│   ├── user_requirements_nlp.md             # 87 functional requirements (v5.0) - business perspective
│   ├── architectural_requirements_nlp.md    # 110 design patterns and DOS/DONTS principles
│   ├── technical_requirements_nlp.md        # 138 implementation syntax requirements
│   ├── user_requirements.json               # (Legacy) Original JSON version
│   ├── architectural_requirements.json      # (Legacy) Original JSON version
│   └── technical_requirements.json          # (Legacy) Original JSON version
├── dos_and_donts.pdf                        # Laravel patterns reference (source for NLP files)
├── volopaProcess.md                         # Business process flow
├── massPaymentsVolopaAgents.txt             # Intent allocation
└── metaGPT-LLM-ReAct-RAG-Readme.txt        # RAG integration notes
```

---

## Notes

1. **All roles load NLP requirements automatically** - Requirements are loaded from markdown files in `__init__()` using regex parsers and injected into constraints for every LLM call

2. **NLP markdown files are the source of truth** - All functional (87), architectural (110), and technical (138) requirements are programmatically loaded from human-readable markdown

3. **QaEngineer validates everything** - Loads ALL THREE NLP markdown files (335 total requirements) to test functional requirements, architectural patterns, and implementation correctness

4. **DOS/DONTS are now in NLP markdown** - LaravelEngineer and LaravelArchitect load 110 architectural patterns and 138 technical requirements from NLP markdown files

5. **Critical security requirements included** - Version 5.0 of user_requirements_nlp.md includes multi-tenant data isolation (requirements 68-71) and platform integration (84-87)

6. **Regex-based parsing** - All NLP markdown files are parsed using regex patterns: `**N.**` for requirements, `## N.` for sections, `### N.N` for subsections

7. **RAG integration is TODO** - SearchCodeBase action needs to be implemented to query Volopa's Laravel examples

8. **Team-level SOP is active** - Message routing (PM → Architect → ProjectManager → Engineer → QaEngineer) is handled by MetaGPT's environment

9. **Role-level mode is Fixed SOP** - All roles use `use_fixed_sop: bool = True` for sequential, deterministic execution (NOT dynamic ReAct mode)

10. **Complete workflow pipeline** - ProductManager → Architect → ProjectManager → Engineer → QaEngineer provides full software development lifecycle

11. **Legacy JSON files preserved** - Original JSON requirements files are kept in `requirements/` directory for reference and backward compatibility

---

## References

- MetaGPT Framework: [https://github.com/geekan/MetaGPT](https://github.com/geekan/MetaGPT)
- Laravel Documentation: [https://laravel.com/docs](https://laravel.com/docs)
- Volopa DOS/DONTS: `../dos_and_donts.pdf` (source for JSON requirements)
- Mass Payments Workflow: `../volopaProcess.md`
- Intent Allocation: `../massPaymentsVolopaAgents.txt`

### Requirements Files
- **Functional Requirements (NLP)**: `../requirements/user_requirements_nlp.md` (87 requirements, v5.0)
- **Architectural Requirements (NLP)**: `../requirements/architectural_requirements_nlp.md` (110 requirements)
- **Technical Requirements (NLP)**: `../requirements/technical_requirements_nlp.md` (138 requirements)
- **Requirements Comparison**: `../requirements_comparison.md` (JSON vs NLP analysis)
- **Methodology Comparison**: `../methodology_comp.md` (NLP vs JSON output quality analysis)
- **Legacy JSON Files**: Available in `../requirements/*.json` for reference
