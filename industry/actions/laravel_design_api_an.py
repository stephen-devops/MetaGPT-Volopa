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
    instruction="Extract COMPLETE, CLEAN AND DETAILED constraints the system design must respect. "
                "Derive them from the total YAML context files provided. "
                "Covers these categories:\n"
                "  - Business rules and limits\n"
                "  - Routing and API conventions\n"
                "  - Authentication and middleware identity\n"
                "  - Data model details (column types, fields, seed data, relationships, foreign keys)\n"
                "  - Validation rules, security, performance and authorization\n"
                "  - Permissions, identity checks, tenancy and hierarchy\n"
                "  - Timestamp patterns, conventions and variants\n"
                "  - Exact default data records existing on feature activation\n"
                "  - Coding standards, constants and code quality\n"
                "  - Numeric standards or file-based constraints, limits and thresholds\n\n"
                "Include both required practises and prohibited patterns derived from the input YAML context. "
                "Each constraint must be a single, actionable statement.",
    example=[
        "Maximum number of rows per CSV file.",
        "Lookup table seeded with exact records: 'Type A' (negative), 'Type B' (positive)",
        "All-or-nothing: if any CSV row fails validation, no records are created.",
        "Table X uses pattern A for timestamps; Table Y uses pattern B",
        "Parameter Name max length per DB definition (e.g. VARCHAR 180)",
        "Role hierarchy: highest role has full access by default",
        "All API routes must use Oauth2 client middleware",
        "Create ONE Migration and ONE Factory per new data table schema",
        "Numeric columns use the exact DECIMAL precision specified per column in the schema",
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
