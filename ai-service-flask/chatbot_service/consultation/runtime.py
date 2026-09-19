"""Production adapters for the pure consultation use case.

This module is the only place where model and vector-store dependencies are
assembled. The rest of the consultation code can therefore be tested offline.
"""
from __future__ import annotations

import logging
import os
from dataclasses import dataclass

from .planning import QueryPlan, ModelPlanner
from .service import ConsultationService

logger = logging.getLogger(__name__)

CATEGORIES = [
    "Sữa Rửa Mặt", "Tẩy Trang Mặt", "Toner / Nước Cân Bằng Da", "Serum / Tinh Chất",
    "Kem / Gel / Dầu Dưỡng", "Lotion / Sữa Dưỡng", "Mặt Nạ Giấy", "Mặt Nạ Rửa",
    "Mặt Nạ Ngủ", "Chống Nắng Da Mặt", "Tẩy Tế Bào Chết Da Mặt", "Hỗ Trợ Trị Mụn",
]
SKIN_TYPES = ["Da dầu/Hỗn hợp dầu", "Da thường/Mọi loại da", "Da nhạy cảm", "Da khô/Hỗn hợp khô", "Da mụn"]


def _text(response) -> str:
    value = getattr(response, "content", response)
    if isinstance(value, list):
        value = "".join(str(part.get("text", "")) if isinstance(part, dict) else str(part) for part in value)
    return str(value or "").strip()


from .request_rules import RulePlanner
from .catalog import MongoCatalog, CatalogUnavailable


@dataclass
class CatalogAdapter:
    pipeline: object
    document_factory: object

    def document(self, metadata):
        return self.document_factory(page_content=str(metadata.get("mo_ta") or ""), metadata=metadata,
                                     id="product_" + str(metadata.get("id") or ""))

    def search(self, query, filters=None, limit=10):
        if self.pipeline is None:
            raise CatalogUnavailable('Search pipeline unavailable')
        try:
            ranked, _ = self.pipeline.search(query, k_total=max(limit * 3, 10), top_n=limit,
                                             filters=filters, use_reranker=False)
            return [self.document_factory(page_content=d.content, metadata=d.metadata, id=d.doc_id) for d in ranked]
        except Exception as exc:
            logger.warning("catalog.search_failed type=%s", type(exc).__name__)
            raise CatalogUnavailable("Search failed") from exc


class LLMGenerator:
    def __init__(self, llm):
        self.llm = llm

    def __call__(self, instruction, evidence):
        from langchain_core.messages import HumanMessage, SystemMessage
        return _text(self.llm.invoke([SystemMessage(content=instruction), HumanMessage(content=evidence)]))


_service = None


def get_consultation_service():
    global _service
    if _service is not None:
        return _service
    from ..retrieval import MockDocument, get_hybrid_pipeline
    from ..llm_pool import get_llms

    pipeline = None
    try:
        pipeline = get_hybrid_pipeline()
    except Exception as exc:
        logger.warning("catalog unavailable type=%s", type(exc).__name__)
    from pymongo import MongoClient
    client = MongoClient(os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017"),
                         serverSelectionTimeoutMS=3000, connectTimeoutMS=3000, socketTimeoutMS=5000)
    database = client[os.getenv("MONGO_DB_NAME") or os.getenv("MONGO_DB", "skinsyntax")]
    catalog = MongoCatalog(pipeline, MockDocument, database.san_pham)
    llms = get_llms()
    llm = llms[0] if llms else None
    if llm is None:
        def generator(_instruction, _evidence):
            raise RuntimeError("LLM unavailable")
        planner = RulePlanner()
    else:
        generator = LLMGenerator(llm)
        ai_planner = ModelPlanner(generator, CATEGORIES, SKIN_TYPES)
        fallback = RulePlanner()
        class SafePlanner:
            def plan(self, message, history, current_product_id=None):
                try:
                    return ai_planner.plan(message, history, current_product_id)
                except Exception:
                    return fallback.plan(message, history, current_product_id)
        planner = SafePlanner()
    _service = ConsultationService(planner, catalog, generator, lambda _query: [])
    return _service
