#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
@Time    : 2026-02-03
@File    : context_reader.py
@Desc    : Shared YAML context reader for LaravelProductManager and LaravelArchitect
           Reads and indexes the six context dimensions from YAML files
"""

import yaml
from pathlib import Path
from typing import Dict, Any, Optional, List
from dataclasses import dataclass, field


# Six context dimensions as defined in the context model
DIMENSIONS = [
    'intent',
    'requirements',
    'constraints',
    'decisions',
    'interfaces',
    'flows',  # Also includes artifacts
]


@dataclass
class ContextDimension:
    """Represents a single context dimension with its data and metadata."""
    name: str
    data: Dict[str, Any] = field(default_factory=dict)
    source_file: Optional[str] = None

    def get(self, key: str, default: Any = None) -> Any:
        """Get a value from this dimension's data."""
        return self.data.get(key, default)

    def __bool__(self) -> bool:
        """Return True if dimension has data."""
        return bool(self.data)


@dataclass
class ContextIndex:
    """
    Indexed context from YAML files, organized by the six dimensions.

    Dimensions:
    - intent: Why the system exists, objectives, success criteria
    - requirements: Externally observable behavior, features, user journeys
    - constraints: Hard rules that restrict solution space (dos/donts)
    - decisions: Architectural commitments and tradeoffs
    - interfaces: Structural contracts, schemas, API specifications
    - flows: Dynamic behavior, sequencing, message sequence charts
    """
    intent: ContextDimension = field(default_factory=lambda: ContextDimension('intent'))
    requirements: ContextDimension = field(default_factory=lambda: ContextDimension('requirements'))
    constraints: ContextDimension = field(default_factory=lambda: ContextDimension('constraints'))
    decisions: ContextDimension = field(default_factory=lambda: ContextDimension('decisions'))
    interfaces: ContextDimension = field(default_factory=lambda: ContextDimension('interfaces'))
    flows: ContextDimension = field(default_factory=lambda: ContextDimension('flows'))

    # Additional indexed data
    artifacts: Dict[str, Any] = field(default_factory=dict)
    metadata: Dict[str, Any] = field(default_factory=dict)

    def get_dimension(self, name: str) -> Optional[ContextDimension]:
        """Get a dimension by name."""
        return getattr(self, name, None) if name in DIMENSIONS else None

    def get_all_symbols(self, origin: str = None) -> List[Dict[str, Any]]:
        """
        Get all symbols across all dimensions.

        Args:
            origin: Filter by origin ('EXISTING', 'NEW', 'EXTERNAL')

        Returns:
            List of symbol dicts with name, origin, location, dimension
        """
        symbols = []

        # Collect from interfaces
        if self.interfaces.data:
            for section in ['fps_specific_models', 'controllers', 'services', 'jobs', 'exports']:
                items = self.interfaces.data.get(section, {})
                if isinstance(items, dict) and 'models' in items:
                    items = items['models']
                elif isinstance(items, dict):
                    items = items.get('components', []) or items.get('items', [])

                if isinstance(items, list):
                    for item in items:
                        if isinstance(item, dict):
                            symbol = {
                                'name': item.get('name'),
                                'origin': item.get('origin'),
                                'location': item.get('location'),
                                'dimension': 'interfaces',
                            }
                            if origin is None or symbol['origin'] == origin:
                                symbols.append(symbol)

        # Collect from flows
        if self.flows.data:
            for flow_key, flow_data in self.flows.data.items():
                if isinstance(flow_data, dict) and 'entities' in flow_data:
                    for entity in flow_data['entities']:
                        if isinstance(entity, dict):
                            symbol = {
                                'name': entity.get('name'),
                                'origin': entity.get('origin'),
                                'location': entity.get('location'),
                                'dimension': 'flows',
                            }
                            if origin is None or symbol['origin'] == origin:
                                symbols.append(symbol)

        # Collect from artifacts
        for section_key, section_data in self.artifacts.items():
            if isinstance(section_data, dict):
                items = section_data.get('models', []) or section_data.get('components', [])
                for item in items:
                    if isinstance(item, dict):
                        symbol = {
                            'name': item.get('name'),
                            'origin': item.get('origin'),
                            'location': item.get('location'),
                            'dimension': 'artifacts',
                        }
                        if origin is None or symbol['origin'] == origin:
                            symbols.append(symbol)

        return symbols

    def get_dos(self) -> Dict[str, List[str]]:
        """Get all DO rules from constraints."""
        return self.constraints.data.get('dos', {})

    def get_donts(self) -> Dict[str, List[str]]:
        """Get all DON'T rules from constraints."""
        return self.constraints.data.get('donts', {})

    def get_unresolved(self) -> List[Dict[str, Any]]:
        """Get all unresolved questions across dimensions."""
        unresolved = []
        for dim_name in DIMENSIONS:
            dim = self.get_dimension(dim_name)
            if dim and dim.data:
                if 'unresolved' in dim.data:
                    for item in dim.data['unresolved']:
                        item['dimension'] = dim_name
                        unresolved.append(item)
        return unresolved


class ContextReader:
    """
    Reads and indexes YAML context files for LLM agent consumption.

    Usage:
        reader = ContextReader()
        context = reader.load_all()

        # Access dimensions
        dos = context.get_dos()
        new_symbols = context.get_all_symbols(origin='NEW')

        # Get specific dimension
        intent = context.intent.data
    """

    DEFAULT_CONTEXT_DIR = Path(__file__).parent.parent / "requirements" / "context_shared"

    def __init__(self, context_dir: Optional[Path] = None):
        """
        Initialize ContextReader.

        Args:
            context_dir: Path to context_shared directory. Defaults to industry/requirements/context_shared/
        """
        self.context_dir = context_dir or self.DEFAULT_CONTEXT_DIR
        self._raw_data: Dict[str, Dict] = {}

    def load_yaml(self, filename: str) -> Dict[str, Any]:
        """Load a single YAML file."""
        filepath = self.context_dir / filename
        if not filepath.exists():
            return {}

        with open(filepath, 'r', encoding='utf-8') as f:
            data = yaml.safe_load(f) or {}
            self._raw_data[filename] = data
            return data

    def load_all(self) -> ContextIndex:
        """
        Load all YAML files and build indexed context.

        Returns:
            ContextIndex with all dimensions populated
        """
        index = ContextIndex()

        # Load environment context (platform-level)
        env_context = self.load_yaml('environment_context.yaml')
        if env_context:
            index.metadata['environment'] = env_context.get('metadata', {})

            # Environment provides: intent (mental_model), constraints (dos/donts), decisions
            if 'intent' in env_context:
                index.intent.data.update(env_context['intent'])
                index.intent.source_file = 'environment_context.yaml'

            if 'constraints' in env_context:
                index.constraints.data.update(env_context['constraints'])
                index.constraints.source_file = 'environment_context.yaml'

            if 'decisions' in env_context:
                index.decisions.data['environment'] = env_context['decisions']
                index.decisions.source_file = 'environment_context.yaml'

        # Load environment artifacts (existing platform symbols)
        env_artifacts = self.load_yaml('environment_artifacts.yaml')
        if env_artifacts:
            index.artifacts['environment'] = env_artifacts

            # Extract flow components
            if 'flow_components' in env_artifacts:
                index.flows.data['environment_flows'] = env_artifacts['flow_components']

        # Load project context (feature-specific)
        project_context = self.load_yaml('project_context.yaml')
        if project_context:
            index.metadata['project'] = project_context.get('metadata', {})

            # Project provides: intent, requirements, constraints, flows, interfaces, decisions
            if 'intent' in project_context:
                # Merge with environment intent
                for key, value in project_context['intent'].items():
                    if key not in index.intent.data:
                        index.intent.data[key] = value
                    elif isinstance(value, dict) and isinstance(index.intent.data.get(key), dict):
                        index.intent.data[key].update(value)
                    else:
                        index.intent.data[f'project_{key}'] = value

            if 'requirements' in project_context:
                index.requirements.data = project_context['requirements']
                index.requirements.source_file = 'project_context.yaml'

            if 'constraints' in project_context:
                # Merge project constraints with environment constraints
                for key, value in project_context['constraints'].items():
                    if key not in index.constraints.data:
                        index.constraints.data[key] = value
                    elif key == 'unresolved':
                        index.constraints.data.setdefault('unresolved', []).extend(value)

            if 'flows' in project_context:
                index.flows.data.update(project_context['flows'])
                index.flows.source_file = 'project_context.yaml'

            if 'interfaces' in project_context:
                index.interfaces.data = project_context['interfaces']
                index.interfaces.source_file = 'project_context.yaml'

            if 'decisions' in project_context:
                index.decisions.data['project'] = project_context['decisions']

        return index

    def get_dimension_summary(self, index: ContextIndex, dimension: str) -> str:
        """
        Generate a text summary of a dimension for LLM consumption.

        Args:
            index: The loaded ContextIndex
            dimension: Name of dimension to summarize

        Returns:
            Formatted string summary
        """
        dim = index.get_dimension(dimension)
        if not dim or not dim.data:
            return f"No data for dimension: {dimension}"

        lines = [f"=== {dimension.upper()} ==="]
        lines.append(f"Source: {dim.source_file or 'multiple'}")
        lines.append("")

        if dimension == 'constraints':
            # Special formatting for constraints (dos/donts)
            dos = dim.data.get('dos', {})
            donts = dim.data.get('donts', {})

            lines.append("DO (Required Patterns):")
            for category, rules in dos.items():
                lines.append(f"  {category}:")
                if isinstance(rules, list):
                    for rule in rules:
                        lines.append(f"    - {rule}")

            lines.append("")
            lines.append("DON'T (Prohibited Patterns):")
            for category, rules in donts.items():
                lines.append(f"  {category}:")
                if isinstance(rules, list):
                    for rule in rules:
                        lines.append(f"    - {rule}")

        elif dimension == 'interfaces':
            # Special formatting for interfaces
            for section, data in dim.data.items():
                if isinstance(data, dict) and 'models' in data:
                    lines.append(f"{section}:")
                    for model in data['models']:
                        origin = model.get('origin', 'UNKNOWN')
                        lines.append(f"  - {model.get('name')} [{origin}]")
                elif isinstance(data, list):
                    lines.append(f"{section}:")
                    for item in data:
                        if isinstance(item, dict):
                            origin = item.get('origin', 'UNKNOWN')
                            lines.append(f"  - {item.get('name')} [{origin}]")

        elif dimension == 'flows':
            # Special formatting for flows/sequences
            for flow_key, flow_data in dim.data.items():
                if isinstance(flow_data, dict):
                    lines.append(f"{flow_data.get('name', flow_key)}:")
                    if 'entities' in flow_data:
                        lines.append("  Entities:")
                        for entity in flow_data['entities']:
                            origin = entity.get('origin', 'UNKNOWN')
                            lines.append(f"    - {entity.get('name')} [{origin}]")
                    if 'message_sequence' in flow_data:
                        lines.append(f"  Steps: {len(flow_data['message_sequence'])}")

        else:
            # Generic formatting
            lines.append(yaml.dump(dim.data, default_flow_style=False, allow_unicode=True))

        return "\n".join(lines)

    def get_all_dimensions_summary(self, index: ContextIndex) -> str:
        """Generate a complete summary of all dimensions."""
        summaries = []
        for dim_name in DIMENSIONS:
            summaries.append(self.get_dimension_summary(index, dim_name))
            summaries.append("")
        return "\n".join(summaries)


# Convenience function for quick access
def load_context(context_dir: Optional[Path] = None) -> ContextIndex:
    """
    Load and index all context YAML files.

    Args:
        context_dir: Optional path to context_shared directory

    Returns:
        Indexed ContextIndex object
    """
    reader = ContextReader(context_dir)
    return reader.load_all()
