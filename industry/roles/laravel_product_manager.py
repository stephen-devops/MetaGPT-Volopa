#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : laravel_product_manager.py
@Desc    : Laravel Product Manager role for Volopa Mass Payments system
"""

from metagpt.roles.product_manager import ProductManager


class LaravelProductManager(ProductManager):
    """
    Laravel Product Manager specialized for API product requirements.

    Responsibilities:
    - Define business requirements for Laravel APIs
    - Create PRDs with Laravel-specific technical specifications
    - Specify API endpoints, validation rules, and business logic
    - Define user stories and acceptance criteria

    Allocated Intents (from massPaymentsVolopaAgents.txt):
    - getReceiptTemplate: Define template download requirements
    - getClientDraftFiles: Specify draft file retrieval functionality
    - getPaymentPurposeCodes: Define purpose code lookup requirements
    - getAllClientFiles: Specify file history requirements
    - getBeneficiariesByCurrency: Define beneficiary filtering requirements
    """

    use_fixed_sop: bool = True
    name: str = "LaravelPM"
    profile: str = "Laravel Product Manager"
    goal: str = "Create comprehensive PRD for Laravel Mass Payments API system"

    constraints: str = """
    - Use same language as user requirements
    - Focus on Laravel API patterns and RESTful design principles
    - Define clear acceptance criteria for API endpoints
    - Specify data validation rules at business level
    - Document Laravel-specific requirements:
      * API routes (versioned under /v1)
      * Request/response formats (JSON)
      * Authentication requirements (OAuth2/WSSE)
      * Validation rules for FormRequests
      * Business logic separation (controllers vs services)
    - Prioritize requirements clearly (P0: Must-have, P1: Should-have, P2: Nice-to-have)
    - Include Laravel composer package requirements
    """

    def __init__(self, **kwargs):
        """
        Initialize Laravel Product Manager.

        Inherits from ProductManager which provides:
        - WritePRD action
        - PrepareDocuments action (when use_fixed_sop=True)
        - Tool access: RoleZero, Browser, Editor, SearchEnhancedQA
        """
        super().__init__(**kwargs)

        # With use_fixed_sop=True, the role uses BY_ORDER mode
        # Set max_react_loop to 1 to execute actions once and stop
        if self.use_fixed_sop:
            self._set_react_mode(self.rc.react_mode, max_react_loop=1)

        # Track if we've already published WritePRD to avoid duplicate execution
        self._prd_published = False

    async def _think(self) -> bool:
        """Override _think to prevent duplicate PRD generation in multi-round workflows."""
        # If we've already published WritePRD, don't act again
        if self._prd_published:
            self.rc.todo = None
            return False

        # Call parent _think logic
        result = await super()._think()
        return result

    async def _act(self) -> None:
        """Override _act to mark PRD as published after execution."""
        result = await super()._act()

        # Mark as published after WritePRD action completes
        from metagpt.actions import WritePRD
        if isinstance(self.rc.todo, WritePRD) or (hasattr(self.rc, 'memory') and
            any(msg.cause_by == WritePRD.__name__ for msg in self.rc.memory.get())):
            self._prd_published = True

        return result


# Placeholder for future customization
# TODO: Add Laravel-specific PRD templates
# TODO: Add API endpoint specification helpers
# TODO: Add Laravel package recommendation logic
# TODO: Integrate with Volopa business rules (currency-specific requirements, approval workflows)
