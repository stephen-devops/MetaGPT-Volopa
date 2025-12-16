#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2025-12-02
@File    : __init__.py
@Desc    : Laravel-specific MetaGPT roles for Volopa Mass Payments system
"""

from .laravel_product_manager import LaravelProductManager
from .laravel_architect import LaravelArchitect
from .laravel_project_manager import LaravelProjectManager
from .laravel_engineer import LaravelEngineer
from .laravel_qa_engineer import LaravelQaEngineer

__all__ = [
    "LaravelProductManager",
    "LaravelArchitect",
    "LaravelProjectManager",
    "LaravelEngineer",
    "LaravelQaEngineer",
]
