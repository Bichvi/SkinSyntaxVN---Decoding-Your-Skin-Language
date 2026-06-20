from __future__ import annotations

import json
import math
import os
import re
import sys
import unicodedata
from typing import Any

from recommendation.config import EMBED_MODEL_NAME, RECOMMENDATION_INDEX_DIR
from recommendation.mongo_source import get_database, normalize_product

# Đề xuất cá nhân hóa sử dụng LlamaIndex để truy xuất sản phẩm dựa trên hồ sơ khách hàng và lịch sử tương tác.
# Pipeline này không generate sản phẩm mới: nó chỉ retrieve sản phẩm thật từ MongoDB/index, rerank lại,
# rồi dùng LLM được cấu hình trong .env để viết phần answer_text giải thích cho khách hàng.
def _norm(value: Any) -> str:
    """Chuẩn hóa chuỗi về dạng không dấu, chữ thường để so khớp mềm metadata.

    Hàm này được dùng khi lọc loại da/vấn đề da vì dữ liệu MongoDB có thể
    khác nhau về dấu tiếng Việt, khoảng trắng hoặc kiểu dữ liệu.
    """
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", text).strip()


def _safe_error_message(exc: Exception) -> str:
    """Rút gọn lỗi provider LLM để log an toàn, không lộ API key."""
    message = str(exc)
    for env_name in ("OPENAI_API_KEY", "GOOGLE_API_KEY"):
        key = os.getenv(env_name, "").strip()
        if key:
            message = message.replace(key, "[redacted]")
    message = re.sub(r"sk-[A-Za-z0-9_\-]{12,}", "sk-[redacted]", message)
    return f"{exc.__class__.__name__}: {message[:220]}"

# Lớp dịch vụ đề xuất sử dụng LlamaIndex để truy xuất sản phẩm dựa trên hồ sơ khách hàng và lịch sử tương tác.
class LlamaIndexRecommendService:
    """Personalized recommendation pipeline for /api/recommend/llamaindex.

    Pipeline:
    1. Đọc hồ sơ khách hàng từ MongoDB dựa trên user_id hoặc email.
    2. Xây dựng truy vấn ngầm định dựa trên hồ sơ khách hàng và lịch sử tương tác (đơn hàng, giỏ hàng, chat).
    3. Sử dụng LlamaIndex để truy xuất các sản phẩm phù hợp nhất với truy vấn đã xây dựng.
    4. Kết hợp điểm số vector từ LlamaIndex với điểm số BM25 để lọc và xếp hạng sản phẩm.
    5. Lấy thông tin chi tiết của các sản phẩm từ MongoDB dựa trên danh sách product_ids đã lọc và xếp hạng.
    6. Sử dụng LLM được cấu hình trong .env để tạo câu trả lời giải thích cho khách hàng.
    """
    def __init__(self):
        """Khởi tạo service và cache các tài nguyên nặng.

        MongoDB được mở một lần qua get_database(). LlamaIndex index, metadata và node list
        được lazy-load để API không build lại index trong mỗi request.
        """
        self.db = get_database()
        self._index = None
        self._meta = None
        self._index_nodes = None
    # Hàm này tải chỉ mục LlamaIndex đã được xây dựng trước đó từ thư mục RECOMMENDATION_INDEX_DIR. 
    # Nếu chỉ mục đã được tải, nó sẽ trả về lại. Nếu không tìm thấy tệp docstore.json, nó sẽ báo lỗi. 
    # Sau đó, nó thiết lập embedding model và llm cho LlamaIndex, tạo storage context từ thư mục chỉ mục, 
    # và tải chỉ mục từ storage. Cuối cùng, nó trả về đối tượng chỉ mục đã tải.
    def _load_index(self):
        """Load VectorStoreIndex đã persist từ database/recommendation_index.

        Hàm này chỉ đọc index có sẵn, không rebuild index. Nếu docstore.json không tồn tại,
        API trả lỗi rõ ràng để người vận hành chạy `python -m recommendation.indexer`.
        """
        if self._index is not None:
            return self._index
        if not (RECOMMENDATION_INDEX_DIR / "docstore.json").exists():
            raise RuntimeError("Recommendation index chưa được build. Hãy chạy python -m recommendation.indexer")

        from llama_index.core import Settings, StorageContext, load_index_from_storage
        from llama_index.embeddings.huggingface import HuggingFaceEmbedding

        Settings.embed_model = HuggingFaceEmbedding(model_name=EMBED_MODEL_NAME)
        Settings.llm = None
        storage_context = StorageContext.from_defaults(persist_dir=str(RECOMMENDATION_INDEX_DIR))
        self._index = load_index_from_storage(storage_context)
        return self._index

    def _load_index_nodes(self) -> list:
        """Lấy danh sách node sản phẩm từ docstore của LlamaIndex index.

        BM25Retriever cần cùng tập node đã được persist trong index để hybrid search
        dùng chung nguồn dữ liệu với vector retriever.
        """
        if self._index_nodes is not None:
            return self._index_nodes

        index = self._load_index()
        docstore = getattr(getattr(index, "storage_context", None), "docstore", None)
        docs = getattr(docstore, "docs", {}) if docstore is not None else {}
        self._index_nodes = list(docs.values())
        if not self._index_nodes:
            raise RuntimeError("Recommendation index không có node sản phẩm. Hãy chạy python -m recommendation.indexer")
        return self._index_nodes

    def _scores_from_nodes(self, nodes: list) -> dict[str, float]:
        """Chuyển kết quả retriever của LlamaIndex thành map product_id -> score.

        Retriever trả về node hoặc NodeWithScore tùy phiên bản thư viện. Hàm này đọc metadata
        product_id và score theo cách linh hoạt để các bước rerank phía sau dùng chung.
        """
        scores = {}
        for item in nodes:
            node = getattr(item, "node", item)
            metadata = getattr(node, "metadata", {}) or {}
            product_id = str(metadata.get("product_id") or "").strip()
            if product_id:
                scores[product_id] = float(getattr(item, "score", 0.0) or 0.0)
        return scores
    # Hàm này tải metadata sản phẩm từ tệp products_meta.json trong thư mục RECOMMENDATION_INDEX_DIR.
    def _load_meta(self) -> dict[str, dict]:
        """Load metadata sản phẩm được indexer ghi ra products_meta.json.

        Metadata này là phần phụ trợ cho recommendation index. Hàm có cache nội bộ
        để tránh đọc file lặp lại trong cùng vòng đời Flask service.
        """
        if self._meta is not None:
            return self._meta
        path = RECOMMENDATION_INDEX_DIR / "products_meta.json"
        if not path.exists():
            raise RuntimeError("Recommendation product metadata is missing.")
        self._meta = json.loads(path.read_text(encoding="utf-8"))
        return self._meta
    # Hàm này giải quyết hồ sơ khách hàng dựa trên user_id hoặc email. Nó tìm kiếm khách hàng trong cơ sở dữ liệu 
    # MongoDB dựa trên ma_kh hoặc email, và trả về thông tin khách hàng và tài khoản liên quan. 
    # Nếu không tìm thấy hồ sơ khách hàng, nó sẽ báo lỗi.
    def _resolve_customer(self, user_id: Any, email: str = "") -> tuple[dict, dict]:
        """Tìm hồ sơ khách hàng thật từ user_id hoặc email do PHP session gửi sang.

        Website có thể gửi mã từ collection `khach_hang` hoặc `nguoidung`, nên hàm này
        thử nhiều mapping hợp lệ trước khi báo lỗi. Không tạo hồ sơ giả nếu không tìm thấy.
        """
        customer = {}
        account = {}
        email = str(email or "").strip()

        if user_id not in (None, "", 0, "0"):
            uid = int(user_id) if str(user_id).isdigit() else user_id
            customer = self.db.khach_hang.find_one({"ma_kh": uid}) or {}
            account = (
                self.db.nguoidung.find_one({"id": uid})
                or self.db.nguoidung.find_one({"ma_nguoi_dung": uid})
                or self.db.nguoidung.find_one({"_id": uid})
                or {}
            )

        if not customer and email:
            rx = re.compile("^" + re.escape(email) + "$", re.I)
            customer = self.db.khach_hang.find_one({"email": rx}) or {}
            account = self.db.nguoidung.find_one({"email": rx}) or account or {}

        # PHP session thường gửi id của collection nguoidung, không phải ma_kh.
        # Nếu đã tìm được account, dùng email account để map sang khach_hang.
        if not customer and account.get("email"):
            rx = re.compile("^" + re.escape(str(account.get("email"))) + "$", re.I)
            customer = self.db.khach_hang.find_one({"email": rx}) or {}

        # Một số schema lưu mã liên kết user/customer trực tiếp.
        if not customer and account:
            for field in ("ma_kh", "customer_id", "khach_hang_id"):
                if account.get(field) not in (None, "", 0, "0"):
                    value = account.get(field)
                    value = int(value) if str(value).isdigit() else value
                    customer = self.db.khach_hang.find_one({"ma_kh": value}) or {}
                    if customer:
                        break

        if not account and customer.get("email"):
            rx = re.compile("^" + re.escape(str(customer.get("email"))) + "$", re.I)
            account = self.db.nguoidung.find_one({"email": rx}) or {}

        if not customer:
            raise RuntimeError("Không tìm thấy hồ sơ khách hàng.")
        return customer, account
    # Hàm này trích xuất loại da của khách hàng từ trường tinh_trang_dac_biet. 
    # Nó tìm kiếm một mẫu loaida:(\d+) trong trường này,
    def _skin_type_from_customer(self, customer: dict) -> str:
        """Đọc loại da từ trường `tinh_trang_dac_biet` của khách hàng nếu có.

        Một số dữ liệu cũ lưu loại da dưới dạng token `loaida:<id>`, vì vậy hàm này
        map id đó sang collection `loai_da` để lấy tên loại da dễ hiểu cho implicit query.
        """
        special = str(customer.get("tinh_trang_dac_biet") or "")
        match = re.search(r"loaida:(\d+)", special)
        if not match:
            return ""
        row = self.db.loai_da.find_one({"ma_loai_da": int(match.group(1))})
        return str((row or {}).get("ten_loai_da") or "")
    # Hàm này thu thập lịch sử tương tác của khách hàng, bao gồm các sản phẩm đã mua, sản phẩm trong giỏ hàng, 
    # và các tin nhắn chat gần đây.
    def _history(self, customer_id: int) -> dict:
        """Thu thập tín hiệu hành vi của khách hàng từ MongoDB.

        Dữ liệu gồm sản phẩm đã mua, sản phẩm trong giỏ hàng và các nhu cầu chat gần đây.
        Các tín hiệu này chỉ dùng để tạo truy vấn ngầm định, không thay đổi dữ liệu nguồn.
        """
        order_items = []
        cart_items = []
        chat_terms = []

        order_ids = []
        for order in self.db.hoa_don.find({"ma_kh": customer_id}, sort=[("ngay_dat", -1), ("ma_hoa_don", -1)], limit=5):
            order_ids.append(order.get("ma_hoa_don"))
        if order_ids:
            for detail in self.db.chi_tiet_hoa_don.find({"ma_hoa_don": {"$in": order_ids}}, limit=20):
                product = self.db.san_pham.find_one({"ma_san_pham": detail.get("ma_san_pham")})
                if product and product.get("ten_san_pham"):
                    order_items.append(str(product.get("ten_san_pham")))

        for cart in self.db.gio_hang.find({"ma_kh": customer_id}, sort=[("updated_at", -1)], limit=10):
            product = self.db.san_pham.find_one({"ma_san_pham": cart.get("ma_san_pham")})
            if product and product.get("ten_san_pham"):
                cart_items.append(str(product.get("ten_san_pham")))

        chat_filter = {"$or": [{"ma_kh": customer_id}, {"customer_id": customer_id}, {"user_id": customer_id}]}
        for chat in self.db.lich_su_chat.find(chat_filter, sort=[("created_at", -1)], limit=8):
            text = str(chat.get("message") or chat.get("noi_dung") or chat.get("text") or "")
            if text:
                chat_terms.append(text[:180])

        return {"orders": order_items, "cart": cart_items, "chat": chat_terms}
    # Hàm này tìm kiếm hồ sơ da của khách hàng trong bộ sưu tập ho_so_da dựa trên ma_kh hoặc email.
    def _skin_profile_doc(self, customer: dict) -> dict:
        """Tìm hồ sơ da trong collection `ho_so_da` theo mã khách hàng hoặc email.

        Nếu collection không tồn tại hoặc MongoDB lỗi ở phần hồ sơ da, hàm trả dict rỗng
        để service có thể tiếp tục dùng các tín hiệu khác của khách hàng.
        """
        customer_id = customer.get("ma_kh")
        email = str(customer.get("email") or "")
        clauses = []
        if customer_id:
            clauses.extend([{"ma_kh": customer_id}, {"customer_id": customer_id}, {"user_id": customer_id}])
        if email:
            clauses.append({"email": re.compile("^" + re.escape(email) + "$", re.I)})
        if not clauses:
            return {}
        try:
            return self.db.ho_so_da.find_one({"$or": clauses}) or {}
        except Exception:
            return {}
    # Hàm này xây dựng một truy vấn ngầm định dựa trên hồ sơ khách hàng và lịch sử tương tác. 
    # Nó kết hợp thông tin về loại da, vấn đề da, ngân sách, độ nhạy cảm, thành phần cần tránh, 
    # các sản phẩm đã mua trước đó, sản phẩm trong giỏ hàng, và các nhu cầu chat gần đây để tạo 
    # thành một truy vấn mô tả hồ sơ và nhu cầu của khách hàng. Nó cũng xây dựng một bộ lọc để 
    # sử dụng trong quá trình lọc sản phẩm sau này.
    def _implicit_query(self, customer: dict, history: dict) -> tuple[str, dict]:
        """Tạo truy vấn ngầm định và metadata filter từ hồ sơ khách hàng.

        Người dùng đã đăng nhập không cần nhập câu hỏi. Hàm này gom loại da, vấn đề da,
        ngân sách, thành phần cần tránh, lịch sử mua, giỏ hàng và chat để tạo query cho RAG.
        """
        skin_profile = self._skin_profile_doc(customer)
        skin_type = self._skin_type_from_customer(customer)
        if not skin_type:
            skin_type = str(skin_profile.get("loai_da") or skin_profile.get("skin_type") or "")
        concerns = str(skin_profile.get("van_de_da") or skin_profile.get("concerns") or customer.get("van_de_da") or "")
        budget = skin_profile.get("ngan_sach") or skin_profile.get("budget") or customer.get("ngan_sach")
        sensitivity = str(skin_profile.get("muc_do_nhay_cam") or skin_profile.get("sensitivity") or customer.get("muc_do_nhay_cam") or "")
        avoid = str(skin_profile.get("thanh_phan_tranh") or skin_profile.get("avoid_ingredients") or customer.get("thanh_phan_tranh") or "")

        parts = ["Recommend real SkinSyntax skincare products"]
        if skin_type:
            parts.append(f"skin type: {skin_type}")
        if concerns:
            parts.append(f"skin concerns: {concerns}")
        if sensitivity:
            parts.append(f"sensitivity: {sensitivity}")
        if avoid:
            parts.append(f"avoid ingredients: {avoid}")
        if budget:
            parts.append(f"budget under {budget} VND")
        if history["orders"]:
            parts.append("previous purchases: " + ", ".join(history["orders"][:6]))
        if history["cart"]:
            parts.append("cart: " + ", ".join(history["cart"][:5]))
        if history["chat"]:
            parts.append("recent chat needs: " + " | ".join(history["chat"][:3]))

        filters = {
            "skin_type": skin_type,
            "concerns": concerns,
            "price_max": int(budget) if budget else None,
        }
        return ". ".join(parts), filters
    # Hàm này sử dụng LlamaIndex để truy xuất các sản phẩm phù hợp nhất với truy vấn đã xây dựng.
    def _vector_scores(self, query: str, top_k: int = 40) -> dict[str, float]:
        """Chạy VectorIndexRetriever của LlamaIndex để lấy điểm ngữ nghĩa.

        Đây là nhánh semantic search trong hybrid search, giúp tìm sản phẩm liên quan
        ngay cả khi từ khóa trong profile không trùng chính xác với mô tả sản phẩm.
        """
        index = self._load_index()
        from llama_index.core.retrievers import VectorIndexRetriever

        retriever = VectorIndexRetriever(index=index, similarity_top_k=top_k)
        return self._scores_from_nodes(retriever.retrieve(query))

    # BM25Retriever của LlamaIndex dùng cùng node đã persist trong recommendation index.
    def _bm25_scores(self, query: str, top_k: int = 40) -> dict[str, float]:
        """Chạy BM25Retriever của LlamaIndex để lấy điểm keyword/lexical.

        Đây là nhánh keyword search trong hybrid search, giúp giữ các match chính xác
        như thương hiệu, thành phần, loại da hoặc vấn đề da.
        """
        from llama_index.retrievers.bm25 import BM25Retriever

        retriever = BM25Retriever.from_defaults(
            nodes=self._load_index_nodes(),
            similarity_top_k=top_k,
        )
        return self._scores_from_nodes(retriever.retrieve(query))
    # Hàm này lấy thông tin chi tiết của các sản phẩm từ MongoDB dựa trên danh sách product_ids.
    def _hydrate(self, product_ids: list[str]) -> dict[str, dict]:
        """Lấy lại product document thật từ MongoDB theo các product_id đã retrieve.

        Bước này đảm bảo API chỉ trả sản phẩm đang tồn tại trong collection `san_pham`,
        không trả nội dung bịa từ LLM hoặc metadata cũ.
        """
        if not product_ids:
            return {}
        ids: list[Any] = list(product_ids)
        ids.extend(int(pid) for pid in product_ids if str(pid).isdigit())
        rows = self.db.san_pham.find({"ma_san_pham": {"$in": ids}})
        products = {}
        for row in rows:
            product = normalize_product(row, self.db)
            product["gia_thi_truong"] = int(row.get("gia_thi_truong") or 0)
            product["phan_tram_giam"] = int(row.get("phan_tram_giam") or 0)
            products[product["id"]] = product
        return products
    # Hàm này lọc sản phẩm dựa trên các bộ lọc đã cho, bao gồm ngân sách và loại da.
    def _filter_product(self, product: dict, filters: dict, strict_budget: bool = True, strict_skin: bool = True) -> bool:
        """Áp dụng metadata filtering sau khi retrieve ứng viên.

        Bộ lọc ưu tiên ngân sách, loại da và trạng thái sản phẩm. Khi lọc quá chặt không
        còn kết quả, `_rerank` sẽ gọi lại với chế độ mềm hơn.
        """
        if strict_budget and filters.get("price_max") and int(product.get("gia_ban") or 0) > int(filters["price_max"]):
            return False
        skin_type = _norm(filters.get("skin_type"))
        if strict_skin and skin_type and skin_type not in _norm(product.get("loai_da") or product.get("mo_ta")):
            # Do not over-filter universal products; reranking will handle softer matches.
            if "mọi loại da" not in str(product.get("loai_da") or "").lower() and "moi loai da" not in _norm(product.get("loai_da")):
                return False
        return product.get("stock_status") != "hidden"
    # Hàm này kết hợp điểm số vector từ LlamaIndex với điểm số BM25 để lọc và xếp hạng sản phẩm.
    def _rerank(self, query: str, filters: dict, limit: int = 5) -> list[dict]:
        """Kết hợp vector search + BM25 search, lọc metadata và rerank top sản phẩm.

        Hàm này là lõi retrieval/reranking của recommendation. Nó không gọi LLM và không
        generate sản phẩm; kết quả cuối cùng luôn được hydrate từ MongoDB.
        """
        vector_scores = self._vector_scores(query, top_k=60)
        bm25_scores = self._bm25_scores(query, top_k=60)
        candidate_ids = list(dict.fromkeys(
            list(vector_scores.keys()) + sorted(bm25_scores, key=bm25_scores.get, reverse=True)[:60]
        ))
        if not candidate_ids:
            raise RuntimeError("LlamaIndex retriever không trả ứng viên.")

        hydrated = self._hydrate(candidate_ids)
        max_vec = max(vector_scores.values() or [1.0])
        max_bm25 = max(bm25_scores.values() or [1.0])
        # Điểm số kết hợp: 58% vector, 32% BM25, 8% đánh giá, 6% độ phổ biến. Sau đó lọc sản phẩm dựa trên bộ lọc đã cho, 
        # và xếp hạng lại dựa trên điểm số kết hợp.
        def score_pass(strict_budget: bool, strict_skin: bool) -> list[dict]:
            rows = []
            for pid in candidate_ids:
                product = hydrated.get(pid)
                if not product or not self._filter_product(product, filters, strict_budget=strict_budget, strict_skin=strict_skin):
                    continue

                semantic = vector_scores.get(pid, 0.0) / max_vec
                lexical = bm25_scores.get(pid, 0.0) / max_bm25
                quality = 0.0
                if product.get("diem_danh_gia"):
                    quality += min(float(product["diem_danh_gia"]) / 5.0, 1.0) * 0.08
                if product.get("sold_count") or product.get("popularity"):
                    quality += min(math.log1p(float(product.get("sold_count") or product.get("popularity") or 0)) / 10.0, 1.0) * 0.06

                product["_rank_score"] = 0.58 * semantic + 0.32 * lexical + quality
                rows.append(product)
            rows.sort(key=lambda item: item["_rank_score"], reverse=True)
            return rows

        reranked = score_pass(strict_budget=True, strict_skin=True)
        if not reranked:
            reranked = score_pass(strict_budget=True, strict_skin=False)
        if not reranked:
            reranked = score_pass(strict_budget=False, strict_skin=False)
        if not reranked:
            raise RuntimeError("Không có sản phẩm phù hợp sau metadata filtering.")
        return reranked[:limit]

    def _match_info_from_rank(self, product: dict, max_score: float) -> tuple[int, str]:
        """Chuyển `_rank_score` của reranking thành phần trăm phù hợp cho UI.

        Phần trăm này được tính từ điểm retrieval/rerank nội bộ, không lấy từ LLM.
        Các sản phẩm đã lọt top 5 được giữ tối thiểu 60% để giao diện không tạo cảm giác
        hệ thống đang đề xuất sản phẩm quá kém phù hợp.
        """
        score = product.get("_rank_score")
        if score in (None, "") or max_score <= 0:
            return 80, "Phù hợp"

        try:
            raw_percent = int(round((float(score) / max_score) * 100))
        except (TypeError, ValueError, ZeroDivisionError):
            return 80, "Phù hợp"

        match_percent = max(60, min(raw_percent, 100))
        if raw_percent >= 90:
            match_label = "Rất phù hợp"
        elif raw_percent >= 75:
            match_label = "Phù hợp"
        else:
            match_label = "Có thể cân nhắc"
        return match_percent, match_label

    def _build_answer_prompt(self, customer: dict, query: str, products: list[dict]) -> str:
        """Tạo prompt chung cho OpenAI/Gemini khi viết answer_text.

        Prompt chỉ chứa hồ sơ ngầm định và danh sách sản phẩm đã retrieve/rerank. LLM được
        yêu cầu không bịa sản phẩm, không nhắc chi tiết kỹ thuật LlamaIndex/BM25/rerank.
        """
        product_lines = []
        for idx, product in enumerate(products, 1):
            product_lines.append(
                f"{idx}. {product.get('ten_san_pham')} | brand={product.get('thuong_hieu')} | "
                f"price={product.get('gia_ban')} | skin={product.get('loai_da')} | "
                f"ingredients={product.get('thanh_phan_chinh')}"
            )

        customer_name = str(customer.get("ho_ten") or customer.get("ten_khach_hang") or "").strip()
        greeting_hint = f"Tên khách hàng nếu cần xưng hô: {customer_name}" if customer_name else "Không có tên khách hàng rõ ràng."
        return f"""
Bạn là chuyên gia tư vấn mỹ phẩm SkinSyntaxVN.
Chỉ dùng các sản phẩm có trong danh sách. Không bịa sản phẩm, không nhắc sản phẩm ngoài database.

{greeting_hint}

Implicit user profile/query:
{query}

Retrieved and reranked products:
{chr(10).join(product_lines)}

Hãy viết answer_text bằng tiếng Việt, 3-5 câu, giải thích vì sao nhóm sản phẩm này phù hợp.
Không nêu chi tiết kỹ thuật LlamaIndex/BM25/rerank trong câu trả lời cho khách.
""".strip()

    def _openai_answer(self, prompt: str) -> str:
        """Gọi OpenAI/ChatGPT để tạo answer_text từ prompt đã chuẩn hóa.

        API key được đọc từ OPENAI_API_KEY và model đọc từ OPENAI_RECOMMENDATION_MODEL,
        mặc định là gpt-4o-mini. Hàm không log hoặc trả API key ra response.
        """
        key = os.getenv("OPENAI_API_KEY", "").strip()
        if not key:
            raise RuntimeError("Missing OPENAI_API_KEY for OpenAI answer_text.")

        model_name = os.getenv("OPENAI_RECOMMENDATION_MODEL", "gpt-4o-mini").strip() or "gpt-4o-mini"
        from llama_index.llms.openai import OpenAI

        llm = OpenAI(model=model_name, api_key=key, temperature=0.25)
        response = llm.complete(prompt)
        return str(getattr(response, "text", response)).strip()

    def _gemini_answer(self, prompt: str) -> str:
        """Gọi Gemini để tạo answer_text khi được cấu hình hoặc khi OpenAI fallback.

        Gemini tiếp tục dùng GOOGLE_API_KEY hoặc phần tử đầu tiên của GOOGLE_API_KEYS như
        logic hiện tại. Model ưu tiên GEMINI_RECOMMEND_MODEL, sau đó GEMINI_CHAT_MODEL.
        """
        key = os.getenv("GOOGLE_API_KEY", "").strip()
        if not key:
            plural = os.getenv("GOOGLE_API_KEYS", "").strip()
            key = plural.split(",")[0].strip() if plural else ""
        if not key:
            raise RuntimeError("Missing GOOGLE_API_KEY for Gemini answer_text.")

        model_name = os.getenv("GEMINI_RECOMMEND_MODEL", os.getenv("GEMINI_CHAT_MODEL", "gemini-2.5-flash"))
        if not model_name.startswith("models/"):
            model_name = "models/" + model_name
        from llama_index.llms.gemini import Gemini

        llm = Gemini(model=model_name, api_key=key, temperature=0.25)
        response = llm.complete(prompt)
        return str(getattr(response, "text", response)).strip()

    def _llm_answer(self, customer: dict, query: str, products: list[dict]) -> str:
        """Chọn LLM tạo answer_text theo .env và fallback an toàn.

        RECOMMENDATION_LLM_PROVIDER mặc định là `openai`. Nếu provider chính lỗi hoặc
        thiếu key, service thử provider còn lại. Lỗi kỹ thuật chỉ ghi provider, không ghi key.
        """
        prompt = self._build_answer_prompt(customer, query, products)
        provider = os.getenv("RECOMMENDATION_LLM_PROVIDER", "openai").strip().lower() or "openai"
        providers = ["gemini", "openai"] if provider == "gemini" else ["openai", "gemini"]
        last_error = None

        for item in providers:
            try:
                if item == "openai":
                    return self._openai_answer(prompt)
                if item == "gemini":
                    return self._gemini_answer(prompt)
            except Exception as exc:
                last_error = exc
                print(
                    f"[WARN] Recommendation answer_text provider failed: {item}: {_safe_error_message(exc)}",
                    file=sys.stderr,
                )

        raise RuntimeError(f"Không thể tạo answer_text bằng LLM đã cấu hình: {_safe_error_message(last_error) if last_error else 'unknown error'}")

    def recommend(self, user_id: Any, email: str = "") -> dict:
        """Chạy toàn bộ pipeline recommendation cá nhân hóa cho một khách hàng.

        Hàm này resolve khách hàng, tạo implicit query, chạy hybrid retrieval/reranking,
        gọi LLM được cấu hình trong .env để viết answer_text, rồi trả JSON chuẩn cho PHP.
        """
        customer, _account = self._resolve_customer(user_id, email)
        customer_id = int(customer.get("ma_kh") or 0)
        history = self._history(customer_id)
        query, filters = self._implicit_query(customer, history)
        products = self._rerank(query, filters, limit=5)
        answer_text = self._llm_answer(customer, query, products)
        rank_scores = [float(product.get("_rank_score") or 0.0) for product in products]
        max_score = max(rank_scores or [0.0])

        output_products = []
        for product in products:
            match_percent, match_label = self._match_info_from_rank(product, max_score)
            output_products.append({
                "id": str(product.get("id") or product.get("ma_san_pham") or ""),
                "ten_san_pham": str(product.get("ten_san_pham") or ""),
                "gia_ban": int(product.get("gia_ban") or 0),
                "gia_thi_truong": int(product.get("gia_thi_truong") or 0),
                "phan_tram_giam": int(product.get("phan_tram_giam") or 0),
                "thuong_hieu": str(product.get("thuong_hieu") or ""),
                "link_hinh_anh": str(product.get("link_hinh_anh") or product.get("image_url") or ""),
                "diem_danh_gia": float(product.get("diem_danh_gia") or product.get("rating") or 0),
                "reason": self._reason_for_product(product, filters),
                "match_percent": match_percent,
                "match_label": match_label,
            })

        return {
            "ok": True,
            "source": "llamaindex",
            "answer_text": answer_text,
            "products": output_products,
        }

    def _reason_for_product(self, product: dict, filters: dict) -> str:
        """Tạo reason ngắn cho từng product card trong JSON trả về PHP.

        Reason này dựa trên metadata filter và điểm đánh giá, không gọi LLM riêng từng sản phẩm
        để tránh tăng chi phí và độ trễ API.
        """
        reasons = []
        if filters.get("skin_type"):
            reasons.append(f"phù hợp với {filters['skin_type']}")
        if filters.get("concerns"):
            reasons.append("liên quan đến vấn đề da đã lưu")
        if filters.get("price_max"):
            reasons.append("nằm trong ngân sách")
        if product.get("diem_danh_gia"):
            reasons.append("có đánh giá tốt")
        return "Sản phẩm " + ", ".join(reasons[:3]) + "." if reasons else "Sản phẩm phù hợp với hồ sơ và hành vi mua sắm gần đây."


llamaindex_recommend_service = LlamaIndexRecommendService()
