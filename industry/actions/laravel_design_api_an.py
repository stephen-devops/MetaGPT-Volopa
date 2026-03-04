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
    instruction="Identify and list ALL constraints the system design must respect. "
                "Derive them from the project/environment constraints-based YAML context provided. "
                "Cover each of these categories:\n"
                "  - Business rules and limits\n"
                "  - Routing and API conventions\n"
                "  - Data model constraints (field types, lengths, relationships, engines, table identity rules)\n"
                "  - Validation rules (formats, ranges, allowed values)\n"
                "  - Security, performance, tenancy and authorization\n"
                "  - Permission delegation and hierarchy\n"
                "  - Soft delete and timestamp patterns, conventions and variants\n"
                "  - Coding standards and code quality\n"
                "  - File-based constraints (formats, limits, uploads)\n"
                "Include both required practises and prohibited anti-patterns. "
                "Each constraint must be a single, actionable statement.",
    example=[
        "Maximum number of rows per CSV file.",
        "All-or-nothing: if any CSV row fails validation, no records are created.",
        "FX rate lookup: max window lookback from initial date.",
        "Parameter Name max length per DB definition (e.g. VARCHAR 180)",
        "Only Primary Admin has full access to all users by default",
        "Create ONE Migration per new data table schema",
        "Responses must be shaped through a transform layer"
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
