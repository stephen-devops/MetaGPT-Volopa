# Quick Start Guide - Volopa Mass Payments Laravel Roles

## 🚀 Ready in 3 Steps

### Step 1: Verify Installation
```bash
# Ensure MetaGPT is installed
pip install -r requirements.txt

# Verify all 5 roles are importable
python -c "from industry.roles import LaravelProductManager, LaravelArchitect, LaravelProjectManager, LaravelEngineer, LaravelQaEngineer; print('✅ All 5 roles imported successfully')"
```

### Step 2: Configure MetaGPT
```bash
# Check if config exists
ls config/config2.yaml

# If not, initialize it
metagpt --init-config

# Edit config/config2.yaml with your LLM API keys
# Example:
# llm:
#   api_type: "openai"
#   model: "gpt-4-turbo"
#   api_key: "YOUR_API_KEY"
```

### Step 3: Run Example
```bash
# Run the Volopa Mass Payments workflow (5 roles working sequentially)
python industry/run_volopa_mass_payments.py

# Watch the output - you'll see:
# 1. LaravelProductManager creating PRD (loads user_requirements.json)
# 2. LaravelArchitect creating System Design (loads architectural_requirements.json)
# 3. LaravelProjectManager creating Task Breakdown (loads user_requirements.json)
# 4. LaravelEngineer writing Laravel code (loads architectural + technical requirements)
# 5. LaravelQaEngineer writing PHPUnit tests (loads ALL 3 JSON files)

# Results will be in:
# workspace/volopa_mass_payments/
```

---

## 📁 What You'll Get

After running, check these directories:

```bash
workspace/volopa_mass_payments/
├── docs/
│   ├── requirement.txt              # Original requirement
│   ├── prd/
│   │   └── volopa_mass_payments.md  # ✅ From LaravelProductManager
│   ├── system_design/
│   │   └── volopa_mass_payments.md  # ✅ From LaravelArchitect
│   └── task/
│       └── volopa_mass_payments.json # ✅ From LaravelProjectManager
├── app/
│   ├── Http/
│   │   ├── Controllers/              # ✅ From LaravelEngineer
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Models/
│   ├── Services/
│   └── Policies/
└── tests/
    └── Feature/                      # ✅ From LaravelQaEngineer
        ├── MassPaymentFileTest.php
        ├── PaymentInstructionTest.php
        ├── MultiTenantIsolationTest.php
        └── ...
```

---

## 🎯 Using the Roles Programmatically

### Simple Example
```python
import asyncio
from metagpt.team import Team
from metagpt.context import Context
from metagpt.config2 import config

from industry.roles import (
    LaravelProductManager,
    LaravelArchitect,
    LaravelProjectManager,
    LaravelEngineer,
    LaravelQaEngineer
)

async def main():
    # Setup
    ctx = Context(config=config)
    company = Team(context=ctx)

    # Hire complete Laravel team (all 5 roles with JSON requirements auto-loading)
    company.hire([
        LaravelProductManager(),   # Loads user_requirements.json
        LaravelArchitect(),        # Loads architectural_requirements.json
        LaravelProjectManager(),   # Loads user_requirements.json
        LaravelEngineer(),         # Loads architectural + technical requirements
        LaravelQaEngineer()        # Loads ALL 3 JSON files
    ])

    # Budget for LLM calls (increased for 5 roles)
    company.invest(investment=15.0)

    # Run (8 rounds for complete workflow including tests)
    await company.run(
        n_round=8,
        idea="Build a Laravel API for managing payments"
    )

asyncio.run(main())
```

---

## 🔍 Viewing the Output

### PRD (Product Requirements Document)
```bash
cat workspace/volopa_mass_payments/docs/prd/volopa_mass_payments.md
```

### System Design
```bash
cat workspace/volopa_mass_payments/docs/system_design/volopa_mass_payments.md
```

### Task Breakdown
```bash
cat workspace/volopa_mass_payments/docs/task/volopa_mass_payments.json | jq .
```

### Generated Code
```bash
find workspace/volopa_mass_payments/app -name "*.php"
```

### Generated Tests
```bash
# View all test files
find workspace/volopa_mass_payments/tests/Feature -name "*Test.php"

# View a specific test
cat workspace/volopa_mass_payments/tests/Feature/MassPaymentFileTest.php
```

---

## ⚙️ Customizing the Workflow

### Change the Requirement
Edit `industry/run_volopa_mass_payments.py`:
```python
idea = """
Your custom requirement here...
"""
```

### Adjust Budget
```python
company.invest(investment=20.0)  # $20 for more complex projects with all 5 roles
```

### Change Number of Rounds
```python
await company.run(
    n_round=10,  # More rounds for complex projects (minimum 6 for all 5 roles)
    idea=idea
)
```

### Use Only Specific Roles
```python
# Example: Skip testing phase (only 4 roles)
company.hire([
    LaravelProductManager(),
    LaravelArchitect(),
    LaravelProjectManager(),
    LaravelEngineer()
])

await company.run(n_round=5, idea=idea)

# Example: Only PRD and Design (2 roles)
company.hire([
    LaravelProductManager(),
    LaravelArchitect()
])

await company.run(n_round=3, idea=idea)
```

---

## 🐛 Troubleshooting

### Issue: "Module not found: industry.roles"
```bash
# Ensure you're in the project root
cd /path/to/MetaGPT-Volopa

# Verify __init__.py exists
ls industry/roles/__init__.py
```

### Issue: "No API key configured"
```bash
# Check config
cat config/config2.yaml

# Set API key
export OPENAI_API_KEY="your-key-here"
# or edit config/config2.yaml
```

### Issue: "Roles not responding"
```python
# Check role subscriptions
from industry.roles import LaravelProductManager
pm = LaravelProductManager()
print(pm.rc.watch)  # Should show subscribed messages
```

### Issue: "Empty output directories"
```bash
# Check logs for errors
tail -f logs/metagpt.log

# Verify roles are executing
# You should see log entries like:
# "LaravelPM: to do WritePRD"
# "LaravelArchitect: to do WriteDesign"
```

---

## 📚 Learn More

- **Comprehensive Documentation**: `industry/roles/README.md` (12KB guide)
- **Implementation Details**: `industry/IMPLEMENTATION_SUMMARY.md`
- **DOS/DONTS Reference**: `industry/dos_and_donts.pdf`
- **Workflow Diagram**: `industry/volopaProcess.md`
- **Intent Allocation**: `industry/massPaymentsVolopaAgents.txt`

---

## 🎓 Understanding the Workflow

### 1. UserRequirement → LaravelProductManager
- **Input**: Your `idea` string
- **JSON Loaded**: `user_requirements.json` (42 functional requirements)
- **Process**: Analyzes requirements, transforms JSON into comprehensive PRD
- **Output**: PRD with user stories, requirements pool, competitive analysis
- **Message**: `WritePRD` with file path

### 2. LaravelProductManager → LaravelArchitect
- **Input**: PRD document
- **JSON Loaded**: `architectural_requirements.json` (design patterns, DOS/DONTS)
- **Process**: Designs Laravel architecture, data models, API endpoints
- **Output**: System Design with file list, class diagrams, sequence diagrams
- **Message**: `WriteDesign` with file path

### 3. LaravelArchitect → LaravelProjectManager
- **Input**: System Design document
- **JSON Loaded**: `user_requirements.json` (task breakdown statistics)
- **Process**: Breaks down into dependency-ordered tasks (40+ files)
- **Output**: Task Breakdown with file list, dependencies, packages
- **Message**: `WriteTasks` with file path

### 4. LaravelProjectManager → LaravelEngineer
- **Input**: System Design + Task Breakdown
- **JSON Loaded**: `architectural_requirements.json` + `technical_requirements.json`
- **Process**: Writes Laravel code following loaded DOS/DONTS patterns
- **Output**: Laravel source files (controllers, services, models, migrations, etc.)
- **Message**: `WriteCode` with file paths

### 5. LaravelEngineer → LaravelQaEngineer
- **Input**: All generated code + All documentation
- **JSON Loaded**: **ALL 3** JSON files (functional + architectural + technical)
- **Process**: Writes comprehensive PHPUnit tests validating all requirements
- **Output**: Feature tests with 100% coverage (15-20 test files)
- **Message**: `WriteTest` with file paths

---

## ✅ Success Checklist

After running, verify all 5 roles completed successfully:

- [ ] `workspace/volopa_mass_payments/` directory exists
- [ ] `docs/prd/volopa_mass_payments.md` was created (ProductManager ✓)
- [ ] `docs/system_design/volopa_mass_payments.md` was created (Architect ✓)
- [ ] `docs/task/volopa_mass_payments.json` was created (ProjectManager ✓)
- [ ] `app/` directory contains 40+ PHP files (Engineer ✓)
- [ ] `tests/Feature/` directory contains 15-20 test files (QaEngineer ✓)
- [ ] No errors in logs
- [ ] All JSON requirements loaded properly (check logs for "Loaded requirements from JSON")

---

## 🚦 Next Steps

1. **Review outputs**: Check the generated PRD, Design, Code, and Tests
2. **Run tests**: Execute PHPUnit tests to verify code quality
   ```bash
   cd workspace/volopa_mass_payments
   ./vendor/bin/phpunit tests/Feature/
   ```
3. **Validate JSON loading**: Check that all roles properly loaded requirements
4. **Customize roles**: Edit role files to add Volopa-specific logic
5. **Add RAG**: Implement SearchCodeBase for querying Volopa examples
6. **Iterate**: Run with different requirements to test robustness

---

## 💡 Tips

- **Start small**: Test with a simple requirement first
- **Check logs**: MetaGPT logs are very verbose and helpful for debugging
- **JSON requirements**: All roles automatically load requirements - no manual setup needed
- **Test coverage**: QaEngineer validates 100% of requirements from all 3 JSON files
- **Iterate**: The roles improve with better prompts and context
- **Budget wisely**: 5 roles use more LLM tokens ($15-20 recommended)
- **Version control**: Commit outputs to track improvements
- **Round count**: Minimum 6 rounds for all 5 roles (8-10 recommended for complex projects)

---

## 🤝 Need Help?

1. Read `industry/roles/README.md` for detailed documentation
2. Check MetaGPT docs: [https://docs.deepwisdom.ai/](https://docs.deepwisdom.ai/)
3. Review example outputs in workspace/
4. Look at role source code for implementation details

**Happy coding! 🎉**
