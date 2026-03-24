"""RAG verification against the volopa_platform_symbols Elasticsearch index.

Executes cross-type symbol lookups and collision checks for CHECK PLAN items.
Returns structured evidence (found/not-found, file_path, description) without
making pass/fail decisions — the orchestrator evaluates results.

Phase 2a: evidence acquisition only. No gating or prompt injection.
"""

from __future__ import annotations

import asyncio
from dataclasses import dataclass, field

import requests
from metagpt.logs import logger

from industry.utils.check_plan_dispatcher import CheckPlanItem


@dataclass
class VerificationReport:
    """Evidence returned by a single RAG verification query."""

    item: CheckPlanItem | None = None  # original CHECK PLAN entry (attached by verify())
    found: bool = False                # True if >=1 ES hit
    hits: int = 0                      # number of ES hits
    resolved_origin: str = "UNKNOWN"   # "EXISTING" | "NEW" (after verification)
    file_path: str | None = None       # from best ES hit (keyword field)
    symbol_name: str | None = None     # from best ES hit
    symbol_type: str | None = None     # from best ES hit
    description: str | None = None     # from best ES hit
    type_data: dict | None = None      # type-specific metadata (columns, relationships, capabilities, etc.)
    raw_hits: list[dict] = field(default_factory=list)  # full _source from all hits

    @property
    def name(self) -> str:
        """Best available name: CHECK PLAN item name, then ES symbol_name, then '?'."""
        if self.item and self.item.name:
            return self.item.name
        return self.symbol_name or "?"

    @property
    def origin(self) -> str:
        """Origin from the CHECK PLAN item, or '?' if no item attached."""
        return self.item.origin if self.item else "?"


class RepoVerifier:
    """Executes RAG queries against the volopa_platform_symbols ES index.

    Does NOT make pass/fail decisions — only fetches evidence.
    The orchestrator evaluates results against CHECK PLAN rules.
    """

    def __init__(
        self,
        es_host: str = "http://localhost:9200",
        index: str = "volopa_platform_symbols",
    ):
        self.es_host = es_host.rstrip("/")
        self.index = index

    async def verify(self, items: list[CheckPlanItem]) -> list[VerificationReport]:
        """Verify all CHECK PLAN items. Routes by origin.

        - EXISTING / UNKNOWN -> lookup_symbol (expect found for EXISTING)
        - NEW -> collision_check (expect NOT found for NEW)
        """
        results: list[VerificationReport] = []
        for item in items:
            if item.origin == "NEW":
                result = await self.collision_check(item.name)
            else:  # EXISTING or UNKNOWN
                result = await self.lookup_symbol(item.name)
            result.item = item
            results.append(result)
        return results

    async def lookup_symbol(self, name: str) -> VerificationReport:
        """Cross-type symbol lookup. Used for EXISTING and UNKNOWN items.

        Returns a VerificationResult. If found, resolved_origin is EXISTING.
        If not found, resolved_origin is NEW (for UNKNOWN) or stays EXISTING
        (caller interprets as unverified).
        """
        query = self._build_query(name)
        response = await self._search(query)
        return self._parse_response(response)

    async def collision_check(self, name: str) -> VerificationReport:
        """Cross-type collision check. Used for NEW items.

        Same ES query as lookup_symbol. Semantic difference:
        - found=True -> collision detected (name already exists)
        - found=False -> safe to create
        """
        query = self._build_query(name)
        response = await self._search(query)
        return self._parse_response(response)

    @staticmethod
    def _build_query(name: str) -> dict:
        """Build ES bool query: keyword exact match (boosted) + text match."""
        return {
            "size": 5,
            "query": {
                "bool": {
                    "should": [
                        {
                            "term": {
                                "symbol_name.keyword": {
                                    "value": name,
                                    "boost": 3,
                                }
                            }
                        },
                        {
                            "match": {
                                "symbol_name": {
                                    "query": name,
                                    "boost": 1,
                                }
                            }
                        },
                    ],
                    "minimum_should_match": 1,
                }
            },
        }

    @staticmethod
    def _parse_response(response: dict) -> VerificationReport:
        """Parse ES response into a VerificationReport.

        resolved_origin is determined purely by whether hits were found:
        found → EXISTING (symbol is in the repo), not found → NEW (symbol is not).

        type_data is extracted from the best hit and contains type-specific
        metadata (columns, relationships, capabilities, etc.) that lets
        downstream code generation reference the correct interface.
        """
        hits_list = response.get("hits", {}).get("hits", [])
        total = len(hits_list)
        found = total > 0

        symbol_name = None
        symbol_type = None
        file_path = None
        description = None
        type_data = None
        raw_hits = []

        if found:
            for hit in hits_list:
                raw_hits.append(hit.get("_source", {}))
            # Best hit is first (highest score)
            best = hits_list[0].get("_source", {})
            file_path = best.get("file_path")
            symbol_name = best.get("symbol_name")
            symbol_type = best.get("symbol_type")
            description = best.get("description")
            type_data = best.get("type_data")

        resolved_origin = "EXISTING" if found else "NEW"

        return VerificationReport(
            found=found,
            hits=total,
            resolved_origin=resolved_origin,
            file_path=file_path,
            symbol_name=symbol_name,
            symbol_type=symbol_type,
            description=description,
            type_data=type_data,
            raw_hits=raw_hits,
        )

    def _search_sync(self, query: dict) -> dict:
        """Synchronous ES _search call via requests."""
        try:
            resp = requests.post(
                f"{self.es_host}/{self.index}/_search",
                json=query,
                headers={"Content-Type": "application/json"},
                timeout=10,
            )
            resp.raise_for_status()
            return resp.json()
        except requests.ConnectionError:
            logger.warning(
                f"RepoVerifier: ES unreachable at {self.es_host} — "
                f"returning empty result (soft gate)"
            )
            return {"hits": {"hits": []}}
        except requests.Timeout:
            logger.warning(
                f"RepoVerifier: ES timeout at {self.es_host} — "
                f"returning empty result (soft gate)"
            )
            return {"hits": {"hits": []}}
        except requests.HTTPError as e:
            logger.warning(
                f"RepoVerifier: ES HTTP error: {e} — "
                f"returning empty result (soft gate)"
            )
            return {"hits": {"hits": []}}

    async def _search(self, query: dict) -> dict:
        """Async wrapper — runs sync ES call in thread executor."""
        return await asyncio.to_thread(self._search_sync, query)
