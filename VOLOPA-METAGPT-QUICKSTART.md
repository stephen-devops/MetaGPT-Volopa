# Quick Start Guide - Volopa OOP Expenses (MetaGPT for Legacy Systems)

## What This Is

This project extends MetaGPT with Laravel-specific roles that generate a full API codebase
for Volopa's Out-of-Pocket (OOP) Expenses feature. The real goal is broader: proving that
MetaGPT can be scaled to produce code that integrates with an existing legacy platform —
not greenfield, but brownfield. Volopa OOP is the first case study.

The key insight is **YAML context injection**. Instead of letting the LLM invent
requirements, two structured YAML files feed every role with domain rules, platform
constraints, database schemas, API contracts, and existing-system interfaces. A third
YAML (`environment_artifacts.yaml`) acts as a RAG substitute — a static symbol registry
for the live codebase until a real repository search is wired up.

---

## Ready in 3 Steps

### Step 1: Install and verify

```bash
pip install -r requirements.txt

# Verify all 5 roles are importable
python -c "from industry.roles import LaravelProductManager, LaravelArchitect, LaravelProjectManager, LaravelEngineer, LaravelQaEngineer; print('All 5 roles imported successfully')"
```

### Step 2: Configure LLM

```bash
# Copy the example config
cp config/config2.example.yaml config/config2.yaml

# Edit config/config2.yaml and add your API key:
#   llm:
#     api_type: "openai"
#     model: "gpt-4o"
#     base_url: "https://api.openai.com/v1"
#     api_key: "YOUR_API_KEY"
```

### Step 3: Run

```bash
python industry/run_volopa_oop_expenses.py
```

The script cleans the workspace, resets git state for the output directory, clears
cached team storage, then runs 5 roles sequentially in 5 rounds. Output lands in
`workspace/volopa_oop_expenses/`.

---

## Project Layout

```
industry/
  roles/
    laravel_product_manager.py   # Joshua  - PRD from YAML requirements
    laravel_architect.py         # Danny   - System design, ER, API contracts
    laravel_project_manager.py   # Manuel  - Task breakdown, dependency ordering
    laravel_engineer.py          # Lucas   - Laravel code generation
    laravel_qa_engineer.py       # Darius  - PHPUnit test generation
    __init__.py                  # Exports all 5 roles
  actions/
    laravel_write_prd.py         # LaravelWritePRD action
    laravel_write_prd_an.py      # ActionNode schema for PRD
    laravel_design_api.py        # LaravelWriteDesign action
    laravel_design_api_an.py     # ActionNode schema for design
    laravel_write_code.py        # LaravelWriteCode action
  utils/
    context_reader.py            # ContextReader - shared YAML accessor
    evidence_injector.py         # Injects evidence into prompts
    check_plan_dispatcher.py     # Verification protocol logic
    repo_verifier.py             # Repository correctness checks
  requirements/
    project_context.yaml         # Feature-specific: requirements, flows, interfaces, constraints
    environment_context.yaml     # Platform-wide: standards, dos/donts, existing tables/models
    environment_artifacts.yaml   # RAG substitute: existing codebase symbols
    SD-OOP_User_Management_System.pdf
    SD-OOP_Pocket_expense.pdf
    SD-OOP_Expense_single_data_capturing.pdf
  run_volopa_oop_expenses.py     # Entry point
  dos_and_donts.pdf              # Platform coding standards reference
  Volopa - Proposed Architecture.pdf
```

---

## YAML Context Files (the core mechanism)

All roles load context through `ContextReader` (`industry/utils/context_reader.py`),
which lazy-loads and caches YAML data. Roles call methods like `get_dos_and_donts()`,
`get_database_tables("full")`, `get_flows()`, etc. to inject structured text into
their system prompts.

### project_context.yaml

Feature-scoped rules for OOP Expenses:

- `intent` — objectives, business rationale, success criteria
- `requirements` — REQ-001 through REQ-012, user journeys
- `constraints` — file limits, validation rules, permission hierarchy, FX rules
- `decisions` — architectural decisions (DEC-001+), unresolved questions
- `flows` — end-to-end flows (CSV upload, single capture, permission delegation)
- `interfaces` — database tables (new + existing), controllers, services, models,
  API routes, CSV column schema, response schemas, permission matrix

### environment_context.yaml

Platform-wide standards that apply to any Volopa feature:

- `intent` — platform purpose, mental model (Client -> route -> controller -> FormRequest -> service -> model -> Resource -> JSON), design principles
- `constraints` — dos and donts (routing, validation, controller logic, resources, testing, auth), verification protocol, Laravel task conventions
- `decisions` — platform-level architectural decisions
- `interfaces` — existing database tables, models, middleware, endpoints, user roles, FX query contract

### environment_artifacts.yaml

Static symbol registry (stopgap for live RAG). Lists existing table names, model
classes, middleware, routes, and infrastructure so the LLM avoids collisions and
re-implements correctly against the real codebase.

---

## Workflow (5 rounds)

| Round | Role | Action | Input | Output |
|-------|------|--------|-------|--------|
| 1 | LaravelProductManager (Joshua) | PrepareDocuments + LaravelWritePRD | User idea + YAML context | `docs/prd/volopa_oop_expenses.md` |
| 2 | LaravelArchitect (Danny) | LaravelWriteDesign | PRD + YAML context | `docs/system_design/volopa_oop_expenses.md` |
| 3 | LaravelProjectManager (Manuel) | WriteTasks | System design + YAML context | `docs/task/volopa_oop_expenses.json` |
| 4 | LaravelEngineer (Lucas) | LaravelWriteCode | Design + tasks + YAML context | `app/**/*.php`, `database/**/*.php`, `routes/api.php` |
| 5 | LaravelQaEngineer (Darius) | WriteTest | All code + YAML context | `tests/Feature/**/*Test.php` |

Each role subscribes to the previous role's output message and adds YAML-derived
constraints to its system prompt before acting.

---

## Output Structure

```
workspace/volopa_oop_expenses/
  .src_workspace                            # Prevents nested directory creation
  docs/
    requirement.txt                         # Original idea text
    prd/
      volopa_oop_expenses.md                # PRD (from ProductManager)
    system_design/
      volopa_oop_expenses.md                # System design (from Architect)
    task/
      volopa_oop_expenses.json              # Task breakdown (from ProjectManager)
  app/
    Http/
      Controllers/Api/V1/                   # API controllers
      Requests/                             # Form request validation
      Resources/                            # API resources
    Models/                                 # Eloquent models
    Services/                               # Business logic services
    Policies/                               # Authorization policies
  database/
    migrations/                             # Laravel migrations
    factories/                              # Test factories
  routes/
    api.php                                 # API route definitions
  tests/
    Feature/                                # PHPUnit feature tests
```

---

## Using the Roles Programmatically

```python
import asyncio
from metagpt.config2 import config
from metagpt.context import Context, AttrDict
from metagpt.team import Team

from industry.roles import (
    LaravelProductManager,
    LaravelArchitect,
    LaravelProjectManager,
    LaravelEngineer,
    LaravelQaEngineer,
)

async def main():
    workspace_path = "workspace/volopa_oop_expenses"

    config.update_via_cli(
        project_path=workspace_path,
        project_name="volopa_oop_expenses",
        inc=False,
        reqa_file="",
        max_auto_summarize_code=0,
    )

    ctx = Context(
        config=config,
        kwargs=AttrDict(project_path=workspace_path),
    )

    company = Team(context=ctx, use_mgx=False)
    company.hire([
        LaravelProductManager(context=ctx),
        LaravelArchitect(context=ctx),
        LaravelProjectManager(context=ctx),
        LaravelEngineer(context=ctx),
        LaravelQaEngineer(context=ctx),
    ])
    company.invest(investment=10.0)

    await company.run(
        n_round=5,
        idea="Build the Volopa OOP Expenses API System using Laravel 10+ with PHP 8.2+.",
        send_to="",
        auto_archive=True,
    )

asyncio.run(main())
```

---

## Troubleshooting

### "unknown origin" ValueError from Engineer

`engineer.py:319` uses forward-slash path constants (`docs/task`) but `Path.parent`
on Windows produces backslashes. The custom `LaravelEngineer` overrides
`_new_coding_context` to catch the ValueError and return None (skip).

### Token overflow during code generation

`WriteCode.get_codes()` includes the full source of every sibling file as context.
By file ~30+ this exceeds 200K tokens. `LaravelEngineer` monkey-patches `get_codes`
with `_size_limited_get_codes()` — full source up to 100K chars, then filenames-only.

### Double code generation (all files regenerated)

If `max_react_loop > 1`, the Engineer's `_think()` reads `rc.news[0]` without
consuming it, so the same WriteTasks message triggers `_new_code_actions()` on
every iteration. Fix: `max_react_loop=1` (already set in `LaravelEngineer`).

### Pydantic serialization errors

Custom objects stored as `self.xxx` on Role subclasses fail Pydantic serialization.
Keep them as local variables in `__init__` and pass to methods as parameters.

### JSONDecodeError when injecting evidence

Evidence text must be injected into `design_doc.content`, not `task_doc.content`.
The task document is parsed by `WriteCode.get_codes()` via `json.loads()` — appending
non-JSON text to it causes the error.

### Module not found: industry.roles

Run from the project root. The run script adds the project root to `sys.path`
automatically. If importing manually, ensure you are in `MetaGPT-Volopa/`.

### No API key configured

Check `config/config2.yaml` exists and has a valid `api_key` under `llm:`.

---

## Reference Files

| File | Description |
|------|-------------|
| `industry/dos_and_donts.pdf` | Platform coding standards (source for YAML constraints) |
| `industry/Volopa - Proposed Architecture.pdf` | Architecture reference |
| `industry/requirements/SD-OOP_User_Management_System.pdf` | User management spec (source PDF) |
| `industry/requirements/SD-OOP_Pocket_expense.pdf` | Pocket expense spec (source PDF) |
| `industry/requirements/SD-OOP_Expense_single_data_capturing.pdf` | Single expense spec (source PDF) |
| `industry/roles/README.md` | Role documentation (partially outdated) |
