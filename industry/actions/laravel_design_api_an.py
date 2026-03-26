#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-03-02
@File    : laravel_design_api_an.py
@Desc    : Custom ActionNodes for Laravel/Volopa system design.
           Extends core design_api_an with SYSTEM_CONSTRAINTS.
"""
from typing import List

from metagpt.actions.action_node import ActionNode
from metagpt.actions.design_api_an import (
    ANYTHING_UNCLEAR,
    DATA_STRUCTURES_AND_INTERFACES,
    FILE_LIST,
    IMPLEMENTATION_APPROACH,
    PROGRAM_CALL_FLOW,
    REFINED_DATA_STRUCTURES_AND_INTERFACES,
    REFINED_FILE_LIST,
    REFINED_IMPLEMENTATION_APPROACH,
    REFINED_PROGRAM_CALL_FLOW,
)

SYSTEM_CONSTRAINTS = ActionNode(
    key="System constraints",
    expected_type=List[str],
    instruction=(
        "Extract EVERY constraint from the input YAML context the system design must respect. "
        "Derive constraints from both the project level and environment level context. "
        "Walk through ALL types of constraint in both contexts: (file constraints, data table schema, "
        "authorization and validation constraints, permission constraints, business rules, "
        "data model designs, dos and donts, timestamp patterns, http status code semantics, task conventions, "
        "rules, prohibitions, coding standards and qualities, data model details, design constraints, "
        "data model keys, dependencies, default or seed data, relationships and data enumerations) "
        "and extract all rules, data values, variants, schemas, specifications and prohibitions.\n\n"
        "Preserve EXACT actual values, types, limits, specifics and hierarchies from the YAML. "
        "Do NOT summarize, generalize, round, or paraphrase "
        "- reproduce each constraint with the full fidelity of its input YAML source.\n\n"
        "Each constraint must be a single, self-contained, actionable statement that a "
        "developer can implement directly without referring back to the input context."
    ),
    example=[
        "Constraint preserving exact values, names, and rules from the YAML context.",
        "This constraint with its full specifics as stated in the YAML context.",
        "The ONLY specified auth middleware class names is applied for routes.",
        "This data table schema MUST NOT use any columns it does not have."
    ],
)

NODES = [
    IMPLEMENTATION_APPROACH,
    FILE_LIST,
    DATA_STRUCTURES_AND_INTERFACES,
    PROGRAM_CALL_FLOW,
    SYSTEM_CONSTRAINTS,
    ANYTHING_UNCLEAR,
]

REFINED_NODES = [
    REFINED_IMPLEMENTATION_APPROACH,
    REFINED_FILE_LIST,
    REFINED_DATA_STRUCTURES_AND_INTERFACES,
    REFINED_PROGRAM_CALL_FLOW,
    SYSTEM_CONSTRAINTS,
    ANYTHING_UNCLEAR,
]

DESIGN_API_NODE = ActionNode.from_children("DesignAPI", NODES)
REFINED_DESIGN_NODE = ActionNode.from_children("RefinedDesignAPI", REFINED_NODES)
