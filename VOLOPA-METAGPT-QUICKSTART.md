# Quick Start Guide - Volopa Mass Payments Laravel Roles

## 🚀 Ready in 3 Steps

### Step 1: Verify Installation
```bash
# Ensure MetaGPT is installed
pip install -r requirements.txt

# Verify roles are importable
python -c "from industry.roles import LaravelProductManager, LaravelArchitect, LaravelProjectManager, LaravelEngineer; print('✅ All roles imported successfully')"
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
# Run the Volopa Mass Payments workflow
python industry/run_volopa_mass_payments.py

# Watch the output - you'll see:
# 1. LaravelProductManager creating PRD
# 2. LaravelArchitect creating System Design
# 3. LaravelProjectManager creating Task Breakdown
# 4. LaravelEngineer writing Laravel code

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
└── app/
    ├── Http/
    │   ├── Controllers/              # ✅ From LaravelEngineer
    │   ├── Requests/
    │   └── Resources/
    ├── Models/
    ├── Services/
    └── Policies/
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
    LaravelEngineer
)

async def main():
    # Setup
    ctx = Context(config=config)
    company = Team(context=ctx)

    # Hire Laravel team
    company.hire([
        LaravelProductManager(),
        LaravelArchitect(),
        LaravelProjectManager(),
        LaravelEngineer()
    ])

    # Budget for LLM calls
    company.invest(investment=10.0)

    # Run
    await company.run(
        n_round=6,
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
company.invest(investment=20.0)  # $20 for more complex projects
```

### Change Number of Rounds
```python
await company.run(
    n_round=10,  # More rounds for complex projects
    idea=idea
)
```

### Use Only Specific Roles
```python
# Example: Only ProductManager and Architect
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
- **Process**: Analyzes requirements, researches similar systems
- **Output**: PRD with user stories, requirements pool, competitive analysis
- **Message**: `WritePRD` with file path

### 2. LaravelProductManager → LaravelArchitect
- **Input**: PRD document
- **Process**: Designs Laravel architecture, data models, API endpoints
- **Output**: System Design with file list, class diagrams, sequence diagrams
- **Message**: `WriteDesign` with file path

### 3. LaravelArchitect → LaravelProjectManager
- **Input**: System Design document
- **Process**: Breaks down into dependency-ordered tasks
- **Output**: Task Breakdown with file list, dependencies, packages
- **Message**: `WriteTasks` with file path

### 4. LaravelProjectManager → LaravelEngineer
- **Input**: System Design + Task Breakdown
- **Process**: Writes Laravel code following DOS/DONTS
- **Output**: Laravel source files (controllers, services, models, etc.)
- **Message**: `WriteCode` with file paths

---

## ✅ Success Checklist

After running, verify:

- [ ] `workspace/volopa_mass_payments/` directory exists
- [ ] `docs/prd/volopa_mass_payments.md` was created
- [ ] `docs/system_design/volopa_mass_payments.md` was created
- [ ] `docs/task/volopa_mass_payments.json` was created
- [ ] `app/` directory contains PHP files
- [ ] No errors in logs

---

## 🚦 Next Steps

1. **Review outputs**: Check the generated PRD, Design, and Code
2. **Customize roles**: Edit role files to add Volopa-specific logic
3. **Add RAG**: Implement SearchCodeBase for querying Volopa examples
4. **Test validation**: Verify generated code follows DOS/DONTS
5. **Iterate**: Run with different requirements to test robustness

---

## 💡 Tips

- **Start small**: Test with a simple requirement first
- **Check logs**: MetaGPT logs are very verbose and helpful
- **Iterate**: The roles improve with better prompts and context
- **Budget wisely**: Complex projects use more LLM tokens
- **Version control**: Commit outputs to track improvements

---

## 🤝 Need Help?

1. Read `industry/roles/README.md` for detailed documentation
2. Check MetaGPT docs: [https://docs.deepwisdom.ai/](https://docs.deepwisdom.ai/)
3. Review example outputs in workspace/
4. Look at role source code for implementation details

**Happy coding! 🎉**
