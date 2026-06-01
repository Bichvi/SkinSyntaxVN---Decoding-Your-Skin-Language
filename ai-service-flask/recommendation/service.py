from __future__ import annotations

import json
import math
import re
import unicodedata
from collections import Counter, defaultdict
from typing import Any

from .config import RECOMMENDATION_INDEX_DIR
from .mongo_source import (
    build_product_text,
    get_database,
    mongo_filter_from_params,
    normalize_product,
    visible_filter,
)


def _norm(text: Any) -> str:
    text = unicodedata.normalize("NFKD", str(text or "").lower())
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", text).strip()


def _tokens(text: str) -> list[str]:
    return [t for t in re.split(r"\W+", _norm(text)) if len(t) > 1]


class MongoFallbackSearch:
    """MongoDB-only fallback used when LlamaIndex or the persisted index is unavailable."""

    def __init__(self):
        self.db = get_database()

    def popular(self, filters: dict | None = None, sort: str = "popular", limit: int = 12) -> list[dict]:
        sort_map = {
            "best_seller": [("so_luong_ban", -1), ("luot_mua", -1), ("so_luong_danh_gia", -1)],
            "price_asc": [("gia_ban", 1)],
            "price_desc": [("gia_ban", -1)],
            "popular": [("luot_xem", -1), ("so_luong_danh_gia", -1), ("diem_danh_gia", -1)],
        }
        mongo_filter = visible_filter(filters or {})
        rows = self.db.san_pham.find(
            mongo_filter,
            sort=sort_map.get(sort, sort_map["popular"]),
            limit=limit,
        )
        return [self._with_score(normalize_product(row, self.db), 0.0, ["MongoDB popular/best-seller fallback."]) for row in rows]

    def keyword(self, query: str, filters: dict | None = None, limit: int = 12) -> list[dict]:
        regex = re.compile(re.escape(query), re.I)
        query_filter = {
            "$or": [
                {"ten_san_pham": regex},
                {"danh_muc_day_du": regex},
                {"loai_da": regex},
                {"thanh_phan_chinh": regex},
                {"thanh_phan_day_du": regex},
                {"mo_ta": regex},
            ]
        }
        mongo_filter = visible_filter({"$and": [query_filter, filters]} if filters else query_filter)
        rows = self.db.san_pham.find(
            mongo_filter,
            sort=[("luot_xem", -1), ("diem_danh_gia", -1)],
            limit=limit,
        )
        items = []
        for row in rows:
            product = normalize_product(row, self.db)
            text = build_product_text(product)
            score = sum(1 for token in _tokens(query) if token in _norm(text))
            items.append(self._with_score(product, float(score), ["Khớp keyword trong dữ liệu MongoDB."]))
        return items

    @staticmethod
    def _with_score(product: dict, score: float, reasons: list[str]) -> dict:
        product["score"] = round(score, 3)
        product["reasons"] = reasons
        product["llm_explanation"] = "Sản phẩm này phù hợp với bộ lọc hiện tại."
        product["explanation_source"] = "rag"
        return product


class RecommendationService:
    def __init__(self):
        self.db = get_database()
        self.fallback = MongoFallbackSearch()
        self._index = None
        self._meta = None
        self._bm25_cache = None

    def _load_index(self):
        if self._index is not None:
            return self._index
        try:
            from llama_index.core import Settings, StorageContext, load_index_from_storage
            from llama_index.embeddings.huggingface import HuggingFaceEmbedding

            from .config import EMBED_MODEL_NAME

            if not (RECOMMENDATION_INDEX_DIR / "docstore.json").exists():
                return None
            Settings.embed_model = HuggingFaceEmbedding(model_name=EMBED_MODEL_NAME)
            Settings.llm = None
            storage = StorageContext.from_defaults(persist_dir=str(RECOMMENDATION_INDEX_DIR))
            self._index = load_index_from_storage(storage)
            return self._index
        except Exception as exc:
            print(f"[RECOMMEND] LlamaIndex unavailable, using MongoDB fallback: {exc}")
            return None

    def _load_meta(self) -> dict:
        if self._meta is not None:
            return self._meta
        path = RECOMMENDATION_INDEX_DIR / "products_meta.json"
        if path.exists():
            self._meta = json.loads(path.read_text(encoding="utf-8"))
        else:
            self._meta = {}
        return self._meta

    def _bm25_docs(self) -> list[dict]:
        meta = self._load_meta()
        if meta:
            return list(meta.values())
        rows = self.db.san_pham.find(
            visible_filter(),
            sort=[("ma_san_pham", -1)],
            limit=1000,
        )
        return [normalize_product(row, self.db) for row in rows]

    def _bm25_scores(self, query: str) -> dict[str, float]:
        docs = self._bm25_docs()
        q_tokens = _tokens(query)
        if not docs or not q_tokens:
            return {}

        tokenized = []
        df = defaultdict(int)
        for product in docs:
            toks = _tokens(build_product_text(product))
            tokenized.append((product["id"], toks))
            for token in set(toks):
                df[token] += 1

        avgdl = sum(len(toks) for _, toks in tokenized) / max(1, len(tokenized))
        scores = {}
        for product_id, toks in tokenized:
            counts = Counter(toks)
            score = 0.0
            for token in q_tokens:
                if token not in counts:
                    continue
                idf = math.log(1 + (len(tokenized) - df[token] + 0.5) / (df[token] + 0.5))
                freq = counts[token]
                denom = freq + 1.5 * (1 - 0.75 + 0.75 * len(toks) / max(avgdl, 1))
                score += idf * (freq * 2.5) / max(denom, 0.0001)
            if score > 0:
                scores[product_id] = score
        return scores

    def _vector_scores(self, query: str, top_k: int) -> dict[str, float]:
        index = self._load_index()
        if index is None:
            return {}
        try:
            retriever = index.as_retriever(similarity_top_k=max(top_k, 20))
            nodes = retriever.retrieve(query)
        except Exception as exc:
            print(f"[RECOMMEND] Vector retrieval failed: {exc}")
            return {}

        scores = {}
        for node in nodes:
            meta = getattr(node.node, "metadata", {}) or {}
            product_id = str(meta.get("product_id") or "").strip()
            if product_id:
                scores[product_id] = float(getattr(node, "score", 0.0) or 0.0)
        return scores

    def _passes_filters(self, product: dict, params: dict) -> bool:
        price = int(product.get("gia_ban") or 0)
        if params.get("price_min") is not None and price < int(params["price_min"]):
            return False
        if params.get("price_max") is not None and price > int(params["price_max"]):
            return False
        checks = {
            "category": product.get("danh_muc"),
            "brand": product.get("thuong_hieu"),
            "skin_type": product.get("loai_da"),
            "concerns": product.get("concerns") or product.get("mo_ta"),
            "stock_status": product.get("stock_status"),
        }
        for key, target in checks.items():
            wanted = _norm(params.get(key))
            if wanted and wanted not in _norm(target):
                return False
        return product.get("stock_status") != "hidden"

    def _hydrate_products(self, ids: list[str]) -> dict[str, dict]:
        if not ids:
            return {}
        numeric_ids = [int(x) for x in ids if str(x).isdigit()]
        query_ids: list[Any] = list(ids) + numeric_ids
        rows = self.db.san_pham.find({"ma_san_pham": {"$in": query_ids}})
        return {normalize_product(row, self.db)["id"]: normalize_product(row, self.db) for row in rows}

    def search(self, query: str, params: dict, limit: int = 12) -> dict:
        filters = mongo_filter_from_params(params)
        if not query.strip():
            products = self.fallback.popular(filters, params.get("sort", "popular"), limit)
            return {"status": "success", "query": "", "products": products, "summary": "Trả sản phẩm phổ biến từ MongoDB theo bộ lọc hiện tại.", "retrieval_mode": "mongo_popular"}

        candidate_k = max(limit * 5, 40)
        vector_scores = self._vector_scores(query, candidate_k)
        bm25_scores = self._bm25_scores(query)
        candidate_ids = list(dict.fromkeys(list(vector_scores.keys()) + sorted(bm25_scores, key=bm25_scores.get, reverse=True)[:candidate_k]))

        if not candidate_ids:
            products = self.fallback.keyword(query, filters, limit)
            return {"status": "success" if products else "empty", "query": query, "products": products, "summary": "Fallback MongoDB keyword search vì index chưa sẵn sàng hoặc không có ứng viên.", "retrieval_mode": "mongo_keyword"}

        hydrated = self._hydrate_products(candidate_ids)
        rows = []
        max_bm25 = max(bm25_scores.values() or [1.0])
        max_vec = max(vector_scores.values() or [1.0])
        for product_id in candidate_ids:
            product = hydrated.get(product_id)
            if not product or not self._passes_filters(product, params):
                continue
            sem = vector_scores.get(product_id, 0.0) / max_vec
            lex = bm25_scores.get(product_id, 0.0) / max_bm25
            meta = 0.0
            if product.get("rating"):
                meta += min(float(product["rating"]) / 5.0, 1.0) * 0.08
            if product.get("popularity"):
                meta += min(math.log1p(float(product["popularity"])) / 10.0, 1.0) * 0.08
            score = 0.55 * sem + 0.35 * lex + meta
            product["score"] = round(score * 100, 3)
            reasons = []
            if sem > 0:
                reasons.append("Khớp ngữ nghĩa qua LlamaIndex vector search.")
            if lex > 0:
                reasons.append("Khớp keyword/BM25 với tên, brand, thành phần hoặc loại da.")
            if meta > 0:
                reasons.append("Được ưu tiên thêm nhờ rating, lượt bán hoặc độ phổ biến.")
            product["reasons"] = reasons or ["Phù hợp với truy vấn và bộ lọc."]
            product["llm_explanation"] = "Kết quả được rerank từ ứng viên thật trong MongoDB; hệ thống không tạo sản phẩm mới ngoài database."
            product["explanation_source"] = "rag"
            rows.append(product)

        rows.sort(key=lambda item: item.get("score", 0), reverse=True)
        return {
            "status": "success" if rows else "empty",
            "query": query,
            "products": rows[:limit],
            "summary": "Hybrid search: LlamaIndex vector retrieval + BM25 keyword retrieval + metadata filtering + reranking.",
            "retrieval_mode": "llamaindex_hybrid_rerank",
        }

    def profile_query(self, user_id: str) -> tuple[str, dict, bool]:
        user_id_value: Any = int(user_id) if str(user_id).isdigit() else user_id
        kh = self.db.khach_hang.find_one({"ma_kh": user_id_value}) or {}
        if not kh:
            account = self.db.nguoidung.find_one({"id": user_id_value}) or self.db.nguoidung.find_one({"_id": user_id_value}) or {}
            email_from_account = str(account.get("email") or "").strip()
            if email_from_account:
                kh = self.db.khach_hang.find_one({"email": re.compile("^" + re.escape(email_from_account) + "$", re.I)}) or {}
        email = str(kh.get("email") or "").strip()
        nd = self.db.nguoidung.find_one({"email": re.compile("^" + re.escape(email) + "$", re.I)}) if email else {}
        consent = bool(kh.get("privacy_consent") or kh.get("recommendation_consent") or (nd or {}).get("privacy_consent"))
        if not consent:
            return "", {"sort": "popular"}, False

        skin_type = ""
        special = str(kh.get("tinh_trang_dac_biet") or "")
        match = re.search(r"loaida:(\d+)", special)
        if match:
            ld = self.db.loai_da.find_one({"ma_loai_da": int(match.group(1))})
            skin_type = str((ld or {}).get("ten_loai_da") or "")

        concerns = str(kh.get("van_de_da") or "")
        budget = kh.get("ngan_sach")
        parts = ["Recommend skincare products"]
        if skin_type:
            parts.append(f"for {skin_type} skin")
        if concerns:
            parts.append(f"concerns: {concerns}")
        if budget:
            parts.append(f"budget under {budget} VND")

        recent_items = []
        for order in self.db.hoa_don.find(
            {"ma_kh": user_id_value},
            sort=[("ngay_dat", -1)],
            limit=3,
        ):
            for detail in self.db.chi_tiet_hoa_don.find(
                {"ma_hoa_don": order.get("ma_hoa_don")},
                limit=3,
            ):
                sp = self.db.san_pham.find_one({"ma_san_pham": detail.get("ma_san_pham")})
                if sp and sp.get("ten_san_pham"):
                    recent_items.append(str(sp["ten_san_pham"]))
        if recent_items:
            parts.append("history/favorites/cart/orders: " + ", ".join(recent_items[:6]))

        params = {"skin_type": skin_type or None, "concerns": concerns or None, "price_max": int(budget) if budget else None}
        return ", ".join(parts), params, True


service = RecommendationService()
