from __future__ import annotations

import logging
logger = logging.getLogger(__name__)

import os
import random
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from langchain_chroma import Chroma
from langchain_huggingface import HuggingFaceEmbeddings
from hybrid_search import HybridSearchPipeline, BM25Search
from schemas import PhanTichYeuCau
from model_config import (
    EMBEDDING_MODEL,
    EMBEDDING_ENCODE_KWARGS,
    EMBEDDING_MODEL_KWARGS,
)


_DEFAULT_CHROMA_PATH = str(
    Path(__file__).resolve().parent.parent / "database" / "chroma_db"
)
CHROMA_DB_PATH = os.getenv("CHROMA_DB_PATH", _DEFAULT_CHROMA_PATH)


_vectorstore: Chroma | None = None
_hybrid_pipeline: HybridSearchPipeline | None = None



@dataclass
class MockDocument:
    """Unified wrapper for ChromaDB docs and SQL docs entering the pipeline."""
    page_content: str
    metadata: dict[str, Any]
    id: str



def get_vectorstore() -> Chroma:
    global _vectorstore
    if _vectorstore is None:
        emb = HuggingFaceEmbeddings(
            model_name=EMBEDDING_MODEL,
            model_kwargs=EMBEDDING_MODEL_KWARGS,
            encode_kwargs=EMBEDDING_ENCODE_KWARGS,
        )
        _vectorstore = Chroma(
            collection_name="products",
            persist_directory=CHROMA_DB_PATH,
            embedding_function=emb,
        )
        logger.info(f"[RETRIEVAL] ChromaDB loaded — {_vectorstore._collection.count():,} docs")
    return _vectorstore



def get_hybrid_pipeline() -> HybridSearchPipeline:
    """
    Initialize HybridSearchPipeline with BM25 populated from ChromaDB.
    BM25 requires real document data for keyword search to work correctly.
    """
    global _hybrid_pipeline
    if _hybrid_pipeline is None:
        vs = get_vectorstore()

        # Populate BM25 from all documents in ChromaDB
        bm25_docs: list[dict] = []
        try:
            result = vs._collection.get(include=["documents", "metadatas"])
            for doc_id, content, meta in zip(
                result.get("ids", []),
                result.get("documents", []),
                result.get("metadatas", []),
            ):
                bm25_docs.append({"id": doc_id, "content": content or "", "metadata": meta or {}})
            logger.info(f"[RETRIEVAL] BM25 populated: {len(bm25_docs):,} docs")
        except Exception as e:
            logger.warning(f"[RETRIEVAL] BM25 population failed: {e} — keyword search disabled")

        _hybrid_pipeline = HybridSearchPipeline(
            vectorstore=vs,
            bm25_index=BM25Search(bm25_docs),
            alpha=0.6,  # Slightly favor semantic search for Vietnamese queries
        )
        logger.debug("[RETRIEVAL] Hybrid pipeline ready (BM25 + Semantic RRF + LangChain reranker)")
    return _hybrid_pipeline



def build_filter(yc: PhanTichYeuCau) -> dict | None:
    """Build ChromaDB $where filter from a PhanTichYeuCau object."""
    conds: list[dict] = []
    if yc.loai_da and yc.loai_da not in ("Unknown", None):
        conds.append({"loai_da": {"$eq": yc.loai_da}})
    if yc.loai_san_pham:
        conds.append({"loai_san_pham": {"$eq": yc.loai_san_pham}})
    if yc.xuat_xu:
        conds.append({"xuat_xu_thuong_hieu": {"$eq": yc.xuat_xu}})
    if not conds:
        return None
    return conds[0] if len(conds) == 1 else {"$and": conds}



_HDSD_TIPS: dict[str, list[str]] = {
    "rửa": [
        "Làm ướt mặt bằng nước ấm, tạo bọt mịn rồi massage nhẹ nhàng toàn mặt 60 giây, rửa sạch.",
        "Tạo bọt thật nhiều, massage kỹ vùng chữ T (trán, mũi, cằm) rồi rửa sạch bằng nước mát.",
        "Tip nhỏ: tráng lại bằng nước lạnh sau khi rửa để se khít lỗ chân lông, da sẽ mịn hơn.",
    ],
    "tẩy": [
        "Thấm bông tẩy trang đẫm sản phẩm, đặt lên vùng mắt môi 10-15 giây rồi lau nhẹ ra ngoài.",
        "Chấm nhẹ lên mặt, để 20-30 giây cho sản phẩm hòa tan lớp trang điểm rồi lau sạch.",
        "Vùng mắt và môi nhạy cảm nhất — lau extra nhẹ nhàng để tránh tổn thương vùng da mỏng.",
    ],
    "toner": [
        "Sau rửa mặt, đổ vài giọt ra lòng bàn tay rồi vỗ nhẹ đều khắp mặt để cân bằng độ ẩm.",
        "Dùng bông tẩy trang thấm toner lau nhẹ hoặc vỗ tay tùy độ đặc của sản phẩm.",
    ],
    "serum": [
        "Nhỏ 2-3 giọt lên các điểm (trán, mũi, cằm, hai má), vỗ nhẹ để thẩm thấu trước khi thoa kem.",
        "Dùng serum trước kem dưỡng — hoạt chất nồng độ cao cần thẩm thấu trực tiếp vào da.",
    ],
    "dưỡng": [
        "Lấy lượng bằng hạt đậu, chấm lên các vùng rồi thoa đều, vỗ nhẹ để khóa ẩm.",
        "Kỹ thuật 'face cupping': dùng ngón tay uốn cong ấn nhẹ rồi buông để kem thẩm thấu sâu hơn.",
    ],
    "chống nắng": [
        "Thoa đủ lượng (2 ngón tay cho cả mặt) trước ra ngoài 20-30 phút. Thoa lại mỗi 2-3 tiếng.",
        "Đừng quên vùng tai, cổ, mũi — thường bị bỏ sót nhưng rất dễ cháy nắng.",
    ],
    "mặt nạ": [
        "Rửa sạch mặt trước, đắp 15-20 phút rồi rửa sạch (hoặc để nguyên nếu là mặt nạ ngủ).",
        "Tip: để mặt nạ trong tủ lạnh — lạnh giúp se khít lỗ chân lông và làm dịu da.",
    ],
}

_HDSD_DEFAULTS = [
    "Lấy lượng vừa đủ thoa đều, vỗ nhẹ để dưỡng chất thẩm thấu sâu. Dùng sáng và tối để tối ưu hiệu quả.",
    "Dùng 2 lần/ngày, kiên trì 4-6 tuần mới thấy rõ sự khác biệt.",
    "Luôn làm sạch mặt trước — da sạch hấp thụ dưỡng chất tốt hơn bất kỳ lúc nào.",
]


def get_fallback_hdsd(category: str) -> str:
    """Return a random usage tip appropriate for the given product category."""
    cat = str(category).lower()
    for key, tips in _HDSD_TIPS.items():
        if key in cat:
            return random.choice(tips)
    return random.choice(_HDSD_DEFAULTS)



def format_search_results(docs: list) -> str:
    """Format a list of product documents into a string for the LLM to consume."""
    if not docs:
        return "Không tìm thấy sản phẩm phù hợp."

    blocks: list[str] = []
    for i, doc in enumerate(docs, 1):
        m = doc.metadata

        product_id = ""
        if hasattr(doc, "id") and doc.id:
            product_id = doc.id.replace("product_", "")
        if not product_id:
            product_id = str(m.get("id", ""))

        link = f"index.php?r=chitiet&id={product_id}" if product_id else "#"
        name  = m.get("ten_san_pham", "N/A")
        brand = m.get("thuong_hieu",  "N/A")

        try:
            gia_ban = float(m.get("gia_ban", 0))
        except (ValueError, TypeError):
            gia_ban = 0.0

        loai_da = m.get("loai_da",             "N/A")
        loai_sp = m.get("loai_san_pham",        "N/A")
        xuat_xu = m.get("xuat_xu_thuong_hieu", "N/A")
        image   = m.get("link_hinh_anh",        "") or ""

        thanh_phan = m.get("thanh_phan_chinh", "N/A") or "N/A"
        tp_short   = thanh_phan[:300] + "…" if len(thanh_phan) > 300 else thanh_phan

        mo_ta     = m.get("mo_ta", "") or ""
        mo_short  = mo_ta[:250] + "…" if len(mo_ta) > 250 else mo_ta

        hdsd = get_fallback_hdsd(loai_sp)

        def fmt(v: float) -> str:
            return f"{int(v):,}".replace(",", ".")

        gia_str = fmt(gia_ban) if gia_ban > 0 else "Liên hệ"
        markdown_link = f"**[{name}]({link})**"

        blocks.append(
            f"SP{i}:\n"
            f"  Tên (dạng link Markdown): {markdown_link}\n"
            f"  Thương hiệu: {brand}\n"
            f"  Xuất xứ: {xuat_xu}\n"
            f"  Loại da phù hợp: {loai_da}\n"
            f"  Loại sản phẩm: {loai_sp}\n"
            f"  Giá bán: {gia_str} VNĐ\n"
            f"  Thành phần nổi bật: {tp_short}\n"
            f"  Mô tả ngắn: {mo_short}\n"
            f"  Hướng dẫn sử dụng: {hdsd}\n"
            f"  Hình ảnh: {image}"
        )

    return "\n\n".join(blocks)



def resolve_product_id(doc) -> str:
    """Chuẩn hóa mã SP — không trả doc_0 (LangChain fallback)."""
    import re

    meta = getattr(doc, "metadata", None) or {}
    raw = str(getattr(doc, "id", None) or "").strip()
    if raw.startswith("product_"):
        return raw.replace("product_", "", 1)

    for key in ("ma_san_pham", "id"):
        val = str(meta.get(key) or "").strip()
        if val and val.lower() not in ("null", "none", "") and not re.match(r"^doc_\d+$", val, re.I):
            return val.replace("product_", "", 1)

    if raw and not re.match(r"^doc_\d+$", raw, re.I):
        return raw.replace("product_", "", 1)
    return ""


def docs_to_products(docs: list) -> list[dict]:
    """Convert a list of docs into JSON-serializable dicts for the frontend."""
    products: list[dict] = []
    for doc in docs:
        m = doc.metadata
        name = m.get("ten_san_pham", "")
        if not name:
            continue

        product_id = resolve_product_id(doc)

        try:
            gia_ban = float(m.get("gia_ban", 0))
        except (ValueError, TypeError):
            gia_ban = 0.0

        products.append({
            "id":         str(product_id),
            "name":       name,
            "brand":      m.get("thuong_hieu",    ""),
            "price":      gia_ban,
            "image_url":  m.get("link_hinh_anh",  "") or "",
            "detail_url": f"index.php?r=chitiet&id={product_id}" if product_id else "",
            "summary":    (m.get("thanh_phan_chinh", "") or "")[:120],
        })
    return products
