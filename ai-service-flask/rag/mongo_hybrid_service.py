from __future__ import annotations

import hashlib
import json
import math
import re
import time
import unicodedata
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any

# Thử import cả 2 phiên bản của google generativeai để đảm bảo tương thích với các môi trường khác nhau.
try:
    import google.generativeai as genai
    GENAI_VERSION = 'old'
except ImportError:
    try:
        import google.genai as genai
        GENAI_VERSION = 'new'
    except ImportError:
        genai = None
        GENAI_VERSION = None

from pymongo import DESCENDING, MongoClient
from pymongo.collection import Collection

from config import LlamaIndexConfig

# HybridCandidate là lớp dữ liệu để lưu trữ thông tin về một ứng viên sản phẩm được đánh giá trong quá trình kết hợp keyword + semantic search.
@dataclass
class HybridCandidate:
    document: dict[str, Any]
    keyword_score: float
    semantic_score: float
    final_score: float
    reasons: list[str]

# MongoHybridRecommendationService là lớp chính để xử lý logic kết hợp giữa keyword search và semantic search trên MongoDB, đồng thời quản lý cache câu hỏi và trả lời để tối ưu hiệu suất.
class MongoHybridRecommendationService:
    # Khởi tạo kết nối MongoDB và đảm bảo các index cần thiết cho hiệu suất truy vấn.
    def __init__(self) -> None:
        self.client = MongoClient(LlamaIndexConfig.MONGODB_URI, serverSelectionTimeoutMS=5000)
        self.db = self.client[LlamaIndexConfig.MONGODB_DB_NAME]
        self.products: Collection = self.db[LlamaIndexConfig.MONGODB_PRODUCTS_COLLECTION]
        self.shop_products: Collection = self.db['san_pham']
        self.user_profiles: Collection = self.db[LlamaIndexConfig.MONGODB_USER_PROFILES_COLLECTION]
        self.order_history: Collection = self.db[LlamaIndexConfig.MONGODB_ORDER_HISTORY_COLLECTION]
        self.query_cache: Collection = self.db[LlamaIndexConfig.MONGODB_QUERY_CACHE_COLLECTION]
        self.api_keys = LlamaIndexConfig.get_google_api_keys()
        self._ensure_indexes()
    # Đảm bảo các index trên MongoDB để tối ưu hiệu suất truy vấn, đặc biệt là cho các trường thường xuyên được lọc hoặc sắp xếp.
    def _ensure_indexes(self) -> None:
        self.products.create_index([('product_id', DESCENDING)], unique=True)
        self.products.create_index([('metadata.category', DESCENDING)])
        self.products.create_index([('metadata.brand', DESCENDING)])
        self.products.create_index([('metadata.price', DESCENDING)])
        self.user_profiles.create_index([('customer_id', DESCENDING)], unique=True)
        self.order_history.create_index([('customer_id', DESCENDING), ('created_at', DESCENDING)])
        self.query_cache.create_index([('query_hash', DESCENDING)], unique=True)
        self.query_cache.create_index([('expires_at', DESCENDING)])
    # Phương thức ping để kiểm tra kết nối với MongoDB, đảm bảo rằng dịch vụ có thể truy cập cơ sở dữ liệu trước khi thực hiện các thao tác phức tạp hơn.
    def ping(self) -> bool:
        self.client.admin.command('ping')
        return True
    # Phương thức is_ready để kiểm tra xem dịch vụ đã sẵn sàng hoạt động hay chưa, bằng cách ping MongoDB và kiểm tra xem có dữ liệu sản phẩm nào trong các collection chính hay không.
    def is_ready(self) -> bool:
        try:
            self.ping()
            return self.products.estimated_document_count() > 0 or self.shop_products.estimated_document_count() > 0
        except Exception:
            return False
    # Phương thức close để đóng kết nối MongoDB khi dịch vụ không còn cần thiết, giúp giải phóng tài nguyên và đảm bảo rằng không có kết nối nào bị rò rỉ.
    @staticmethod
    def _normalize_text(value: str) -> str:
        normalized = re.sub(r'\s+', ' ', (value or '').strip().lower())
        return normalized
    # Phương thức _strip_accents để loại bỏ dấu và ký tự đặc biệt trong tiếng Việt, giúp chuẩn hóa văn bản cho việc so sánh và tìm kiếm.
    @staticmethod
    def _strip_accents(value: str) -> str:
        normalized = unicodedata.normalize('NFD', str(value or ''))
        normalized = ''.join(ch for ch in normalized if unicodedata.category(ch) != 'Mn')
        return normalized.replace('đ', 'd').replace('Đ', 'D')
    # Phương thức _normalize_search_text kết hợp chuẩn hóa văn bản và loại bỏ dấu để tạo ra một phiên bản văn bản phù hợp cho việc tìm kiếm và so sánh, đặc biệt là trong ngữ cảnh tiếng Việt.
    @classmethod
    def _normalize_search_text(cls, value: str) -> str:
        return cls._strip_accents(cls._normalize_text(value))
    # Phương thức _tokenize để tách văn bản thành các token (từ hoặc cụm từ) bằng cách sử dụng regex, giúp tạo ra một tập hợp các từ khóa để phục vụ cho việc tìm kiếm và đánh giá sự tương đồng giữa câu hỏi của người dùng và dữ liệu sản phẩm.
    @classmethod
    def _tokenize(cls, value: str) -> list[str]:
        normalized = cls._normalize_text(value)
        return re.findall(r'[\wÃ€-á»¹]+', normalized, flags=re.UNICODE)
    # Phương thức _cosine_similarity để tính toán độ tương đồng cosine giữa hai vector embedding, giúp đánh giá mức độ liên quan giữa câu hỏi của người dùng và các sản phẩm được đề xuất dựa trên semantic search.
    @staticmethod
    def _cosine_similarity(left: list[float], right: list[float]) -> float:
        if not left or not right or len(left) != len(right):
            return 0.0

        numerator = sum(a * b for a, b in zip(left, right))
        left_norm = math.sqrt(sum(a * a for a in left))
        right_norm = math.sqrt(sum(b * b for b in right))
        if left_norm == 0 or right_norm == 0:
            return 0.0
        return numerator / (left_norm * right_norm)
    # Phương thức _hash_query để tạo ra một hash duy nhất cho câu hỏi của người dùng, giúp lưu trữ và truy xuất cache một cách hiệu quả mà không cần lưu trữ toàn bộ văn bản câu hỏi.
    @staticmethod
    def _hash_query(text: str) -> str:
        return hashlib.sha256(text.encode('utf-8')).hexdigest()
    # Phương thức _call_gemini_embedding để gọi API của Google Gemini và tạo embedding cho văn bản câu hỏi đã được chuẩn hóa, đồng thời xử lý các lỗi liên quan đến giới hạn tốc độ hoặc hết hạn API key bằng cách thử với các key khác nhau trong danh sách.
    def _call_gemini_embedding(self, text: str) -> list[float]:
        if not self.api_keys:
            raise RuntimeError('GOOGLE_API_KEY chÆ°a Ä‘Æ°á»£c cáº¥u hÃ¬nh Ä‘á»ƒ táº¡o embedding.')
    # Vòng lặp qua các API key để thử tạo embedding, nếu gặp lỗi liên quan đến giới hạn tốc độ hoặc hết hạn key thì sẽ tiếp tục thử với key tiếp theo, nếu tất cả key đều không thành công thì sẽ trả về lỗi cuối cùng gặp phải.
        last_error: Exception | None = None
        for index, api_key in enumerate(self.api_keys):
            try:
                if GENAI_VERSION == 'old':
                    genai.configure(api_key=api_key)
                    response = genai.embed_content(
                        model=getattr(LlamaIndexConfig, 'EMBEDDING_MODEL', 'models/gemini-embedding-001'),
                        content=text,
                        task_type='retrieval_document',
                    )
                    values = response.get('embedding') if isinstance(response, dict) else getattr(response, 'embedding', None)
                elif GENAI_VERSION == 'new':
                    client = genai.Client(api_key=api_key)
                    model_name = getattr(LlamaIndexConfig, 'EMBEDDING_MODEL', 'models/gemini-embedding-001')
                    response = client.models.embed_content(
                        model=model_name.replace('models/', ''),
                        contents=[text],
                    )
                    values = response.embeddings[0].values if hasattr(response, 'embeddings') and response.embeddings else None
                else:
                    raise RuntimeError('Google GenAI package khÃ´ng kháº£ dá»¥ng.')
                
                if isinstance(values, list):
                    return [float(item) for item in values]
                raise RuntimeError('Embedding response khÃ´ng há»£p lá»‡.')
            except Exception as error:
                last_error = error
                message = str(error).lower()
                if ('quota' in message or '429' in message or 'rate limit' in message) and index < len(self.api_keys) - 1:
                    continue
                raise

        if last_error is not None:
            raise last_error

        raise RuntimeError('KhÃ´ng cÃ³ Gemini API key kháº£ dá»¥ng Ä‘á»ƒ táº¡o embedding.')
    # Phương thức embed_text là phương thức công khai để tạo embedding cho câu hỏi của người dùng, nó sẽ chuẩn hóa văn bản trước khi gọi API tạo embedding và sẽ trả về một vector embedding hoặc một danh sách rỗng nếu văn bản sau khi chuẩn hóa là rỗng.
    def embed_text(self, text: str) -> list[float]:
        # Tao embedding cho cau query da chuan hoa. Text rong thi khong goi API.
        normalized = self._normalize_text(text)
        if normalized == '':
            return []
        return self._call_gemini_embedding(normalized)
    # Phương thức _build_query_text để kết hợp thông tin từ hồ sơ người dùng và câu hỏi hiện tại thành một văn bản truy vấn đầy đủ hơn, giúp cải thiện hiệu quả của quá trình retrieval bằng cách cung cấp nhiều ngữ cảnh hơn về nhu cầu và sở thích của người dùng.
    def _build_query_text(self, user_profile: dict[str, Any], user_query: str) -> str:
        # Ghep cau hoi hien tai voi ho so nguoi dung thanh query day du hon cho RAG.
        # Query co them loai da, van de da, ngan sach se giup retrieval tim dung san pham hon.
        profile_parts = []
        if user_profile.get('skin_type'):
            profile_parts.append(f"loáº¡i da {user_profile['skin_type']}")
        if user_profile.get('sensitivity'):
            profile_parts.append(f"Ä‘á»™ nháº¡y cáº£m {user_profile['sensitivity']}")
        if user_profile.get('concerns'):
            profile_parts.append('váº¥n Ä‘á» da ' + ', '.join(user_profile['concerns'][:4]))
        if user_profile.get('avoid_ingredients'):
            profile_parts.append('trÃ¡nh thÃ nh pháº§n ' + ', '.join(user_profile['avoid_ingredients'][:4]))
        if user_profile.get('budget'):
            profile_parts.append(f"ngÃ¢n sÃ¡ch khoáº£ng {user_profile['budget']} VND")
        if user_profile.get('recent_keywords'):
            profile_parts.append('tá»« khÃ³a gáº§n Ä‘Ã¢y ' + ', '.join(user_profile['recent_keywords'][:3]))

        question = self._normalize_text(user_query)
        # Tim loai san pham nguoi dung dang hoi, vi du cleanser/serum/sunscreen.
        type_hint = self._extract_product_type_hint(question)
        if type_hint and type_hint not in question:
            # Chen type_hint vao query de keyword/semantic search uu tien dung nhom san pham.
            question = f"{question} {type_hint}".strip()

        base = '; '.join(profile_parts)
        if question:
            return f"{question}. há»“ sÆ¡ ngÆ°á»i dÃ¹ng: {base}" if base else question
        return base

    def _extract_product_type_hint(self, query: str) -> str:
        # Nhan dien loai san pham tu cau hoi nguoi dung.
        # Dung ban khong dau de bat duoc ca "sữa rửa mặt" lan "sua rua mat".
        query = self._normalize_text(query)
        query_ascii = self._normalize_search_text(query)
        if not query:
            return ''
        # Cleanser/sua rua mat duoc uu tien nhan dien dau tien de tranh lan voi keyword cham soc da chung chung.
        if any(term in query_ascii for term in ('sua rua mat', 'sua rua', 'cleanser', 'gel rua', 'face wash', 'foaming wash', 'rua mat')):
            return 'cleanser'
        # Kem duong/duong am.
        if any(term in query_ascii for term in ('kem duong', 'duong am', 'cap am', 'moistur', 'hydrat', 'emulsion', 'face cream')):
            return 'moisturizer'
        # Kem chong nang.
        if any(term in query_ascii for term in ('chong nang', 'sunscreen', 'sunblock', 'spf')):
            return 'sunscreen'
        # Mat na.
        if any(term in query_ascii for term in ('mat na', 'mask')):
            return 'mask'
        # Toner/nuoc can bang.
        if any(term in query_ascii for term in ('toner', 'hoa hong', 'nuoc can bang')):
            return 'toner'
        # Kem lot/primer.
        if any(term in query_ascii for term in ('kem lot', 'primer', 'lot nen')):
            return 'primer'
        # Serum/tinh chat.
        if any(term in query_ascii for term in ('serum', 'essence', 'ampoule', 'tinh chat')):
            return 'serum'
        # Thá»© tá»± Æ°u tiÃªn: cá»¥m cá»¥ thá»ƒ trÆ°á»›c (trÃ¡nh nháº§m "kem" trong "kem lÃ³t" vs "kem dÆ°á»¡ng")
        if 'kem lÃ³t' in query or 'kem lot' in query or 'primer' in query or 'lÃ³t ná»n' in query:
            return 'kem lÃ³t'
        if 'kem dÆ°á»¡ng' in query or 'kemduong' in query:
            return 'kem dÆ°á»¡ng'
        if 'dÆ°á»¡ng áº©m' in query or 'duong am' in query or 'cáº¥p áº©m' in query or 'cap am' in query:
            return 'kem dÆ°á»¡ng'
        if 'moistur' in query or 'hydrat' in query or 'emulsion' in query:
            return 'kem dÆ°á»¡ng'
        if 'serum' in query or 'essence' in query or 'ampoule' in query:
            return 'serum'
        if 'máº·t náº¡' in query or 'mat na' in query:
            return 'máº·t náº¡'
        if 'toner' in query or 'nÆ°á»›c hoa há»“ng' in query:
            return 'toner'
        if 'sá»¯a rá»­a máº·t' in query or 'cleanser' in query or 'gel rá»­a' in query or 'rua mat' in query:
            return 'sá»¯a rá»­a máº·t'
        if 'chá»‘ng náº¯ng' in query or 'sunblock' in query or 'spf' in query:
            return 'kem chá»‘ng náº¯ng'
        return ''

    @staticmethod
    def _type_hint_inclusion_regex(type_hint: str) -> str | None:
        """Má»™t regex OR Ä‘á»ƒ lá»c Mongo theo tÃªn/danh má»¥c/ná»™i dung."""
        # Regex duong tinh: san pham phai match regex nay thi moi duoc xem la dung loai.
        # Vi du type_hint='cleanser' thi ten/danh muc phai co sua rua, cleanser, gel rua, ...
        patterns = {
            'moisturizer': r'(kem\s*duong|duong\s*am|cap\s*am|moistur|hydrat|face\s*cream|night\s*cream|emulsion|lotion)',
            'serum': r'(serum|essence|ampoule|tinh\s*chat)',
            'mask': r'(mat\s*na|mask)',
            'toner': r'(toner|nuoc\s*hoa\s*hong|hoa\s*hong|nuoc\s*can\s*bang)',
            'cleanser': r'(sua\s*rua|sua\s*rua\s*mat|cleanser|rua\s*mat|gel\s*rua|foaming\s*wash|face\s*wash|cleansing\s*foam)',
            'sunscreen': r'(chong\s*nang|sunscreen|sun\s*block|sunblock|spf)',
            'primer': r'(kem\s*lot|primer|lot\s*nen)',
            'kem dÆ°á»¡ng': r'(kem dÆ°á»¡ng|kem duong|dÆ°á»¡ng áº©m|duong am|moistur|hydrat|face cream|night cream|emulsion|lotion dÆ°á»¡ng)',
            'serum': r'(serum|essence|ampoule|tinh cháº¥t)',
            'máº·t náº¡': r'(máº·t náº¡|mat na|mask)',
            'toner': r'(toner|nÆ°á»›c hoa há»“ng|hoa há»“ng)',
            'sá»¯a rá»­a máº·t': r'(sá»¯a rá»­a|sua rua|cleanser|rá»­a máº·t|rua mat|gel rá»­a|foaming wash)',
            'kem chá»‘ng náº¯ng': r'(chá»‘ng náº¯ng|chong nang|sunscreen|spf|sun block)',
            'kem lÃ³t': r'(kem lÃ³t|kem lot|primer|lÃ³t ná»n)',
        }
        return patterns.get(type_hint)

    @staticmethod
    def _type_hint_negative_phrases(type_hint: str) -> list[str]:
        """Trá»« Ä‘iá»ƒm máº¡nh náº¿u tÃªn/danh má»¥c giá»‘ng loáº¡i khÃ¡c (vd Ä‘Ã²i kem dÆ°á»¡ng nhÆ°ng lÃ  srm)."""
        # Các câu này nếu xuất hiện sẽ làm giảm điểm mạnh của ứng viên vì có thể cho thấy sản phẩm đó không đúng loại người dùng đang tìm kiếm.
        # Ví du type_hint='cleanser' thì nếu tên/danh mục có chứa "serum" hoặc "mask" thì sẽ bị trừ điểm vì có thể đó là combo hoặc sản phẩm không đúng loại.
        if type_hint == 'cleanser':
            return ['serum', 'mat na', 'mask', 'toner', 'kem duong', 'duong am', 'chong nang', 'sunscreen', 'spf', 'kem lot', 'primer', 'tay trang', 'micellar']
        if type_hint == 'moisturizer':
            return ['sua rua mat', 'sua rua', 'gel rua', 'cleanser', 'rua mat', 'tay trang', 'micellar', 'kem lot', 'primer', 'chong nang', 'sunscreen', 'spf']
        if type_hint == 'mask':
            return ['sua rua mat', 'cleanser', 'serum', 'toner', 'kem duong', 'chong nang']
        if type_hint == 'sunscreen':
            return ['sua rua mat', 'cleanser', 'serum', 'toner', 'mat na', 'mask', 'kem lot', 'primer']
        if type_hint == 'primer':
            return ['sua rua mat', 'cleanser', 'serum', 'toner', 'mat na', 'mask', 'kem duong']
        if type_hint == 'kem dÆ°á»¡ng':
            return [
                'sá»¯a rá»­a máº·t', 'sua rua mat', 'gel rá»­a', 'cleanser', 'rá»­a máº·t', 'rua mat',
                'táº©y trang', 'tay trang', 'micellar',
                'kem lÃ³t', 'kem lot', 'primer',
                'kem chá»‘ng náº¯ng', 'chá»‘ng náº¯ng', 'spf50', 'spf ',
            ]
        if type_hint == 'serum':
            return ['sá»¯a rá»­a máº·t', 'cleanser', 'rá»­a máº·t', 'kem lÃ³t', 'primer']
        if type_hint == 'toner':
            return ['sá»¯a rá»­a máº·t', 'cleanser', 'kem lÃ³t']
        return []

    @staticmethod
    # Phương thức _type_hint_label để chuyển đổi mã loại sản phẩm nội bộ thành nhãn tiếng Việt dễ hiểu, giúp đưa vào câu giải thích cho người dùng khi hiển thị kết quả đề xuất sản phẩm.
    def _type_hint_label(type_hint: str) -> str:
        # Doi ma noi bo thanh nhan tieng Viet de dua vao cau giai thich cho nguoi dung.
        labels = {
            'cleanser': 'sữa rửa mặt',
            'moisturizer': 'kem dưỡng',
            'serum': 'serum',
            'mask': 'mặt nạ',
            'toner': 'toner',
            'sunscreen': 'kem chống nắng',
            'primer': 'kem lót',
        }
        return labels.get(type_hint, type_hint)
    # Phương thức _candidate_matches_type_hint để kiểm tra xem một ứng viên sản phẩm có phù hợp với loại sản phẩm được gợi ý từ câu hỏi của người dùng hay không, bằng cách sử dụng regex để tìm kiếm các từ khóa liên quan đến loại sản phẩm trong tên và danh mục của sản phẩm đó.
    def _candidate_matches_type_hint(self, doc: dict[str, Any], type_hint: str) -> bool:
        # Kiem tra ung vien co dung loai san pham hay khong.
        # Day la lop loc cung, giup query "sua rua mat" khong bi lan serum/combo/toner.
        if not type_hint:
            return True
        rx = self._type_hint_inclusion_regex(type_hint)
        if not rx:
            return True
        haystack = self._normalize_search_text(' '.join([
            # Chi nen uu tien ten/danh muc; mo ta dai co the lam combo hoac san pham phu bi keo vao sai nhom.
            str(doc.get('name') or doc.get('ten_san_pham') or ''),
            str(doc.get('category') or doc.get('danh_muc_day_du') or doc.get('loai_san_pham') or ''),
        ]))
        if type_hint == 'cleanser' and 'combo' in haystack:
            # Rieng sua rua mat: bo combo vi combo thuong gom nhieu mon khong dung nhu cau chinh.
            return False
        return bool(re.search(rx, haystack, re.IGNORECASE))
    # Phương thức _build_product_mongo_filter để tạo ra một filter MongoDB dựa trên ngân sách và loại sản phẩm được gợi ý, giúp lọc các sản phẩm phù hợp từ cơ sở dữ liệu trước khi thực hiện các bước đánh giá chi tiết hơn.
    def _build_product_mongo_filter(self, budget: int, type_hint: str) -> dict[str, Any]:
        # Tao filter MongoDB cho collection products_rag.
        # Filter gom ngan sach va regex loai san pham neu nguoi dung noi ro nhu cau.
        clauses: list[dict[str, Any]] = []
        if budget > 0:
            # Cho phep lech ngan sach 15% de khong bo sot san pham sat nguong.
            cap = int(budget * 1.15)
            clauses.append({
                '$or': [
                    {'metadata.price': {'$lte': cap}},
                    {'price': {'$lte': cap}},
                ],
            })

        rx = self._type_hint_inclusion_regex(type_hint)
        if rx:
            # Loc trong name/category/content cua document RAG.
            clauses.append({
                '$or': [
                    {'name': {'$regex': rx, '$options': 'i'}},
                    {'category': {'$regex': rx, '$options': 'i'}},
                    {'content': {'$regex': rx, '$options': 'i'}},
                ],
            })

        if not clauses:
            return {}
        if len(clauses) == 1:
            return clauses[0]
        return {'$and': clauses}

    @staticmethod
    # Phương thức _first_image để lấy URL của hình ảnh sản phẩm từ một trường có thể chứa nhiều URL cách nhau bằng dấu "|", giúp đảm bảo rằng luôn có một hình ảnh được hiển thị cho mỗi sản phẩm trong kết quả đề xuất.
    def _first_image(raw: Any) -> str:
        # San pham co the luu nhieu anh cach nhau bang "|"; lay anh dau tien de hien thi.
        text = str(raw or '').strip()
        if not text:
            return ''
        return next((item.strip() for item in text.split('|') if item.strip()), '')

    @staticmethod
    # Phương thức _product_is_visible để kiểm tra xem một sản phẩm có đang được bán hay không dựa trên trường trạng thái, giúp loại bỏ các sản phẩm đã ngừng bán hoặc bị ẩn khỏi kết quả đề xuất.
    def _product_is_visible(doc: dict[str, Any]) -> bool:
        # San pham bi an/ngung ban thi khong duoc dua vao goi y.
        status = str(doc.get('trang_thai') or doc.get('status') or 'active').strip().lower()
        return status not in {'inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'}

    @staticmethod
    # Phương thức _product_price để lấy giá của sản phẩm từ các trường có thể khác nhau trong cơ sở dữ liệu, giúp đảm bảo rằng luôn có thông tin giá để so sánh với ngân sách của người dùng khi đánh giá các ứng viên sản phẩm.
    def _product_stock(doc: dict[str, Any]) -> int | None:
        # Doc ton kho theo nhieu ten field khac nhau vi database co the khong dong nhat.
        for field in ('so_luong_ton', 'ton_kho', 'stock', 'quantity'):
            if field in doc and doc.get(field) not in (None, ''):
                return max(0, int(doc.get(field) or 0))
        return None
    # Phương thức _normalize_shop_product_doc để chuyển đổi một document sản phẩm từ collection san_pham sang một schema chung giống như products_rag, giúp các bước đánh giá và xếp hạng sau đó có thể sử dụng chung một định dạng dữ liệu mà không cần quan tâm đến sự khác biệt trong cấu trúc của các collection khác nhau.
    def _normalize_shop_product_doc(self, doc: dict[str, Any]) -> dict[str, Any]:
        # Chuyen document tu collection san_pham sang schema chung giong products_rag.
        # Nho do cac buoc scoring/rerank phia sau dung chung mot format.
        product_id = str(doc.get('ma_san_pham') or doc.get('id') or doc.get('_id') or '').strip()
        brand = str(doc.get('thuong_hieu') or '').strip()
        category = str(doc.get('loai_san_pham') or doc.get('danh_muc_day_du') or '').strip()
    # Neu chua co brand/category thi thu lay tu ma_thuong_hieu/ma_danh_muc tuong ung.
        if not brand and doc.get('ma_thuong_hieu') is not None:
            # Tu ma_thuong_hieu lay ten thuong hieu trong collection thuong_hieu.
            th = self.db['thuong_hieu'].find_one({'ma_thuong_hieu': doc.get('ma_thuong_hieu')})
            if th:
                brand = str(th.get('ten_thuong_hieu') or '').strip()
    # Neu chua co category thi thu lay tu ma_danh_muc tuong ung.
        if not category and doc.get('ma_danh_muc') is not None:
            # Tu ma_danh_muc lay ten danh muc trong collection danh_muc.
            dm = self.db['danh_muc'].find_one({'ma_danh_muc': doc.get('ma_danh_muc')})
            if dm:
                category = str(dm.get('ten_danh_muc') or '').strip()
    # Tach thanh phan chinh thanh mang ngan de dua cho LLM va frontend, chi lay 8 thanh phan dau tien de tranh qua tai thong tin.
        key_ingredients = [
            # Tach thanh phan chinh thanh mang ngan de dua cho LLM va frontend.
            item.strip()
            for item in str(doc.get('thanh_phan_chinh') or '').replace('|', ',').split(',')
            if item.strip()
        ][:8]
        content = ' '.join(str(item or '') for item in [
            # content la khoi text tong hop de keyword search/semantic search co du ngu canh.
            product_id,
            doc.get('ten_san_pham'),
            brand,
            category,
            doc.get('loai_da'),
            doc.get('gia_ban'),
            doc.get('mo_ta'),
            doc.get('thanh_phan_chinh'),
            doc.get('thanh_phan_day_du'),
            doc.get('hdsd'),
        ]).strip()
        return {
            'product_id': product_id,
            'name': str(doc.get('ten_san_pham') or '').strip(),
            'brand': brand,
            'category': category,
            'origin': str(doc.get('xuat_xu_thuong_hieu') or '').strip(),
            'price': int(float(doc.get('gia_ban') or doc.get('gia_thi_truong') or 0)),
            'image_url': self._first_image(doc.get('link_hinh_anh') or doc.get('hinh_anh')),
            'description': str(doc.get('mo_ta') or '').strip(),
            'key_ingredients': key_ingredients,
            'content': content,
            'metadata': {
                'brand': brand,
                'category': category,
                'price': int(float(doc.get('gia_ban') or doc.get('gia_thi_truong') or 0)),
                'skin_type': str(doc.get('loai_da') or '').strip(),
                'rating': float(doc.get('diem_danh_gia') or 0),
            },
            'embedding': doc.get('embedding') if isinstance(doc.get('embedding'), list) else [],
        }
    # Phương thức _fallback_shop_products để thực hiện truy vấn trực tiếp vào collection san_pham trong MongoDB khi dữ liệu trong products_rag không đủ, đồng thời áp dụng các bộ lọc dựa trên ngân sách, trạng thái sản phẩm, tồn kho và loại sản phẩm để đảm bảo rằng chỉ những sản phẩm phù hợp nhất mới được đưa vào kết quả đề xuất.
    def _fallback_shop_products(self, budget: int, type_hint: str, fetch_cap: int) -> list[dict[str, Any]]:
        # Fallback khi products_rag thieu du lieu: doc truc tiep collection san_pham trong MongoDB.
        # Van ap dung loc ngan sach, trang thai, ton kho va loai san pham.
        rx = self._type_hint_inclusion_regex(type_hint)
        clauses: list[dict[str, Any]] = []
        if rx:
            # Neu da nhan dien loai san pham, loc Mongo theo ten/danh muc/mo ta/thanh phan.
            clauses.append({'$or': [
                {'ten_san_pham': {'$regex': rx, '$options': 'i'}},
                {'danh_muc_day_du': {'$regex': rx, '$options': 'i'}},
                {'mo_ta': {'$regex': rx, '$options': 'i'}},
                {'thanh_phan_chinh': {'$regex': rx, '$options': 'i'}},
            ]})
        if budget > 0:
            # Ap dung tran gia theo ngan sach + 15%.
            clauses.append({'gia_ban': {'$lte': int(budget * 1.15)}})
        clauses.append({'trang_thai': {'$nin': ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']}})
        mongo_filter: dict[str, Any] = {'$and': clauses} if len(clauses) > 1 else (clauses[0] if clauses else {})
        docs = []
        for raw in self.shop_products.find(mongo_filter).sort([('diem_danh_gia', DESCENDING), ('ma_san_pham', DESCENDING)]).limit(fetch_cap):
            # Duyet tung san pham MongoDB, bo san pham an/het hang/sai loai.
            doc = dict(raw)
            if not self._product_is_visible(doc):
                continue
            stock = self._product_stock(doc)
            if stock is not None and stock <= 0:
                continue
            normalized = self._normalize_shop_product_doc(doc)
            if type_hint and not self._candidate_matches_type_hint(normalized, type_hint):
                continue
            if normalized['product_id'] and normalized['name']:
                docs.append(normalized)
        return docs
    # Phương thức _load_recent_history để lấy lịch sử mua hàng hoặc tương tác gần đây của khách hàng từ collection order_history, giúp cung cấp thêm ngữ cảnh cá nhân hóa cho LLM khi đưa ra các đề xuất sản phẩm.
    def _load_recent_history(self, customer_id: int) -> list[dict[str, Any]]:
        # Lay lich su mua/tuong tac gan day cua khach hang de LLM co them ngu canh ca nhan hoa.
        if customer_id <= 0:
            return []
        cursor = self.order_history.find({'customer_id': customer_id}).sort('created_at', DESCENDING).limit(3)
        return list(cursor)
    # Phương thức _find_similar_cached_answer để tìm kiếm trong cache các câu trả lời đã lưu trước đó có nội dung hoặc embedding tương tự với câu hỏi hiện tại, giúp tiết kiệm token và tăng tốc độ phản hồi khi người dùng hỏi lặp lại hoặc có nội dung tương tự.
    def _find_similar_cached_answer(self, query_text: str, query_embedding: list[float]) -> dict[str, Any] | None:
        # Tim cau tra loi cu trong cache neu cau hoi hien tai giong cau hoi truoc do.
        # Muc tieu: tiet kiem token va tang toc khi nguoi dung hoi lap lai/noi dung tuong tu.
        now = datetime.now(timezone.utc)
        cache_key = self._hash_query(query_text)
        exact = self.query_cache.find_one({'query_hash': cache_key, 'expires_at': {'$gt': now}})
        if exact:
            # Neu query_hash trung khop hoan toan va cache chua het han thi dung luon.
            return exact

        recent_cache = list(self.query_cache.find({'expires_at': {'$gt': now}}).sort('created_at', DESCENDING).limit(25))
        best_match = None
        best_score = 0.0
        for item in recent_cache:
            cached_embedding = item.get('query_embedding') or []
            if not isinstance(cached_embedding, list):
                continue
            similarity = self._cosine_similarity(query_embedding, [float(x) for x in cached_embedding])
            if similarity >= LlamaIndexConfig.QUERY_CACHE_SIMILARITY_THRESHOLD and similarity > best_score:
                # Neu vector embedding du gan nhau thi xem nhu cau hoi tuong tu.
                best_score = similarity
                best_match = item
        return best_match
    # Phương thức _store_cached_answer để lưu câu trả lời vào cache sau khi đã trả về cho người dùng, bao gồm cả văn bản câu hỏi, embedding của câu hỏi và nội dung câu trả lời, cùng với thời gian hết hạn để đảm bảo rằng cache luôn được cập nhật với các câu trả lời mới nhất và phù hợp nhất.
    def _store_cached_answer(self, query_text: str, query_embedding: list[float], response: dict[str, Any]) -> None:
        # Luu cau tra loi vao cache de lan sau cau hoi tuong tu co the tai su dung.
        now = datetime.now(timezone.utc)
        expires_at = now + timedelta(seconds=LlamaIndexConfig.QUERY_CACHE_TTL_SECONDS)
        self.query_cache.update_one(
            {'query_hash': self._hash_query(query_text)},
            {
                '$set': {
                    'query_text': query_text,
                    'query_embedding': query_embedding,
                    'response': response,
                    'expires_at': expires_at,
                    'created_at': now,
                }
            },
            upsert=True,
        )
    # Phương thức _keyword_candidate_search là bước đầu tiên trong quá trình retrieval, nó sẽ tìm kiếm các ứng viên sản phẩm bằng cách sử dụng keyword search kết hợp với các bộ lọc MongoDB dựa trên thông tin từ hồ sơ người dùng và câu hỏi hiện tại, sau đó sẽ trả về một danh sách các ứng viên sản phẩm phù hợp để tiếp tục đánh giá chi tiết hơn bằng embedding.
    def _keyword_candidate_search(self, user_profile: dict[str, Any], query_text: str) -> list[dict[str, Any]]:
        # Buoc retrieval dau tien: lay ung vien bang keyword + filter MongoDB.
        # Sau buoc nay moi dem semantic rerank bang embedding.
        tokens = set(self._tokenize(query_text))
        budget = int(user_profile.get('budget') or 0)
        type_hint = self._extract_product_type_hint(query_text)

        projection = {
            '_id': 0,
            'product_id': 1,
            'name': 1,
            'brand': 1,
            'category': 1,
            'price': 1,
            'image_url': 1,
            'content': 1,
            'metadata': 1,
            'key_ingredients': 1,
            'embedding': 1,
            'description': 1,
            'origin': 1,
        }

        fetch_cap = max(160, LlamaIndexConfig.HYBRID_CANDIDATE_LIMIT * 8) if type_hint else max(60, LlamaIndexConfig.HYBRID_CANDIDATE_LIMIT * 4)

        # Lay san pham tu products_rag voi filter chat theo ngan sach + loai san pham.
        strict_filter = self._build_product_mongo_filter(budget, type_hint)
        docs = list(self.products.find(strict_filter, projection).limit(fetch_cap))

        if type_hint and len(docs) < 8:
            # Neu products_rag thieu du lieu, doc them truc tiep tu san_pham.
            docs.extend(self._fallback_shop_products(budget, type_hint, fetch_cap))

        if len(docs) < 3:
            # Neu van qua it ung vien, noi long filter RAG nhung van se loc lai bang _candidate_matches_type_hint o duoi.
            loose_filter = self._build_product_mongo_filter(budget, '')
            docs.extend(list(self.products.find(loose_filter, projection).limit(fetch_cap)))

        if len(docs) < 3:
            # Fallback cuoi cung: doc san_pham truc tiep.
            docs = self._fallback_shop_products(budget, type_hint, fetch_cap)

        # Loai san pham trung id khi ket hop nhieu nguon.
        unique_docs: list[dict[str, Any]] = []
        seen_ids: set[str] = set()
        for doc in docs:
            pid = str(doc.get('product_id') or doc.get('ma_san_pham') or doc.get('id') or '').strip()
            if pid and pid in seen_ids:
                continue
            if pid:
                seen_ids.add(pid)
            unique_docs.append(doc)
        docs = unique_docs

        neg_phrases = self._type_hint_negative_phrases(type_hint)
        scored_docs = []
        skin_type = self._normalize_text(str(user_profile.get('skin_type') or ''))
        concerns = {self._normalize_text(item) for item in user_profile.get('concerns') or []}

        for doc in docs:
            # Loc cung theo loai san pham lan nua de tranh ung vien sai nhom lot qua Mongo regex.
            if type_hint and not self._candidate_matches_type_hint(doc, type_hint):
                continue
            content = self._normalize_text(str(doc.get('content') or ''))
            category = self._normalize_text(str(doc.get('category') or ''))
            name = self._normalize_text(str(doc.get('name') or ''))
            search_blob = self._normalize_search_text(f'{name} {category} {content}')
            name_cat = f'{name} {category}'
            doc_tokens = set(self._tokenize(content))
            overlap = len(tokens & doc_tokens) if tokens else 0
            # Diem keyword co ban dua tren ty le token cau hoi xuat hien trong content san pham.
            keyword_score = overlap / max(1, len(tokens)) if tokens else 0.0
            reasons = []

            if type_hint:
                # Cong diem manh khi ung vien khop dung loai san pham nguoi dung mo ta.
                rx = self._type_hint_inclusion_regex(type_hint)
                label = self._type_hint_label(type_hint)
                if rx and re.search(rx, search_blob, re.IGNORECASE):
                    keyword_score += 0.42
                    reasons.append(f"Khớp loại sản phẩm bạn mô tả: {label}")
                elif type_hint in category or type_hint in name or type_hint in content:
                    keyword_score += 0.28
                    reasons.append(f"Phù hợp với yêu cầu loại sản phẩm: {label}")

                for neg in neg_phrases:
                    # Tru diem neu ten/danh muc co dau hieu thuoc nhom san pham khac.
                    neg_n = self._normalize_text(neg)
                    if neg_n and neg_n in name_cat:
                        keyword_score -= 0.55
                        reasons.append(f"Không ưu tiên: sản phẩm thuộc nhóm khác với '{label}'")
                        break

            if skin_type and skin_type in content:
                # Cong diem nhe neu content co nhac den loai da cua nguoi dung.
                keyword_score += 0.18
                reasons.append(f"CÃ³ tÃ­n hiá»‡u phÃ¹ há»£p vá»›i loáº¡i da {user_profile.get('skin_type')}")

            matched_concerns = [item for item in concerns if item and item in content]
            if matched_concerns:
                # Cong diem neu san pham co noi dung lien quan den van de da nguoi dung chon.
                keyword_score += min(0.24, 0.08 * len(matched_concerns))
                reasons.append('Khá»›p váº¥n Ä‘á» da: ' + ', '.join(matched_concerns[:3]))

            if budget > 0 and int(doc.get('price') or 0) > 0 and int(doc.get('price') or 0) <= budget:
                # Cong diem neu gia nam trong ngan sach.
                keyword_score += 0.12
                reasons.append('Náº±m trong vÃ¹ng ngÃ¢n sÃ¡ch Æ°u tiÃªn')

            doc['_keyword_score'] = keyword_score
            doc['_reasons'] = [r for r in reasons if r]
            if keyword_score > 0 or not tokens:
                scored_docs.append(doc)

        scored_docs.sort(key=lambda item: float(item.get('_keyword_score') or 0), reverse=True)
        # Chi tra ve mot tap ung vien vua du de buoc semantic rerank xu ly tiep.
        return scored_docs[: max(10, LlamaIndexConfig.HYBRID_CANDIDATE_LIMIT)]
    # Phương thức _semantic_rerank sẽ nhận vào embedding của câu hỏi và danh sách các ứng viên sản phẩm đã được đánh giá sơ bộ bằng keyword score, sau đó sẽ tính toán điểm số kết hợp giữa keyword score và cosine similarity của embedding để xếp hạng lại các ứng viên, giúp đảm bảo rằng những sản phẩm không chỉ có từ khóa phù hợp mà còn có ý nghĩa gần với yêu cầu của người dùng sẽ được ưu tiên hiển thị.
    def _semantic_rerank(self, query_embedding: list[float], candidates: list[dict[str, Any]]) -> list[HybridCandidate]:
        # Sap xep lai ung vien bang diem ket hop: keyword_score + semantic_score.
        reranked: list[HybridCandidate] = []
        for doc in candidates:
            embedding = doc.get('embedding') or []
            semantic_score = self._cosine_similarity(query_embedding, [float(x) for x in embedding]) if isinstance(embedding, list) else 0.0
            keyword_score = float(doc.get('_keyword_score') or 0)
            # 45% keyword giup bat dung tu khoa; 55% semantic giup hieu y nghia gan nhau.
            final_score = (keyword_score * 0.45) + (semantic_score * 0.55)
            reranked.append(HybridCandidate(
                document=doc,
                keyword_score=keyword_score,
                semantic_score=semantic_score,
                final_score=final_score,
                reasons=list(doc.get('_reasons') or []),
            ))

        reranked.sort(key=lambda item: item.final_score, reverse=True)
        return reranked[: LlamaIndexConfig.RECOMMENDATION_MAX_PRODUCTS]
    # Phương thức _call_gemini_text sẽ thực hiện gọi API của Google Gemini để lấy câu trả lời dựa trên prompt đã được xây dựng, đồng thời sẽ xử lý các lỗi liên quan đến hạn mức sử dụng API và thử lại với các API key khác nếu có, giúp đảm bảo rằng hệ thống có thể tiếp tục hoạt động ngay cả khi một API key bị giới hạn hoặc gặp sự cố.
    def _call_gemini_text(self, prompt_text: str) -> str:
        if not self.api_keys:
            raise RuntimeError('GOOGLE_API_KEY chÆ°a Ä‘Æ°á»£c cáº¥u hÃ¬nh Ä‘á»ƒ generate tÆ° váº¥n.')

        last_error: Exception | None = None
        for index, api_key in enumerate(self.api_keys):
            try:
                if GENAI_VERSION == 'old':
                    genai.configure(api_key=api_key)
                    model_id = LlamaIndexConfig.gemini_model_resource()
                    model = genai.GenerativeModel(model_id)
                    response = model.generate_content(
                        prompt_text,
                        generation_config=genai.types.GenerationConfig(
                            temperature=LlamaIndexConfig.TEMPERATURE,
                            max_output_tokens=1200,
                        ),
                    )
                    return (response.text or '').strip()
                elif GENAI_VERSION == 'new':
                    client = genai.Client(api_key=api_key)
                    response = client.models.generate_content(
                        model=LlamaIndexConfig.gemini_model_resource(),
                        contents=prompt_text,
                        config=genai.GenerateContentConfig(
                            temperature=LlamaIndexConfig.TEMPERATURE,
                            max_output_tokens=1200,
                        ),
                    )
                    return (response.text or '').strip()
                else:
                    raise RuntimeError('Google Gemini library chÆ°a Ä‘Æ°á»£c cÃ i Ä‘áº·t.')
            except Exception as error:
                last_error = error
                message = str(error).lower()
                if ('quota' in message or '429' in message or 'rate limit' in message) and index < len(self.api_keys) - 1:
                    continue
                raise

        if last_error is not None:
            raise last_error

        raise RuntimeError('KhÃ´ng cÃ³ Gemini API key kháº£ dá»¥ng Ä‘á»ƒ generate tÆ° váº¥n.')
    # Phương thức _extract_json_object sẽ cố gắng trích xuất một đối tượng JSON từ văn bản trả về của LLM, giúp đảm bảo rằng ngay cả khi LLM trả về một chuỗi có chứa cả văn bản giải thích và một đối tượng JSON, hệ thống vẫn có thể lấy được phần JSON đó để sử dụng cho các bước xử lý tiếp theo.
    def _extract_json_object(self, text: str) -> str:
        start = text.find('{')
        end = text.rfind('}')
        if start == -1 or end == -1 or end <= start:
            return text
        return text[start:end+1]
    # Phương thức _build_generation_prompt sẽ tạo ra một prompt chi tiết và có cấu trúc rõ ràng để gửi đến LLM, bao gồm thông tin về hồ sơ người dùng, câu hỏi hiện tại, danh sách các sản phẩm ứng viên đã được xếp hạng, lịch sử tương tác gần đây và các quy tắc về giọng điệu và nội dung mà LLM cần tuân theo khi tạo ra câu trả lời, giúp đảm bảo rằng câu trả lời của LLM không chỉ chính xác mà còn phù hợp với ngữ cảnh và yêu cầu của người dùng.
    def _build_generation_prompt(
        self,
        user_profile: dict[str, Any],
        user_query: str,
        ranked: list[HybridCandidate],
        history: list[dict[str, Any]],
        interaction_mode: str = 'chatbot',
    ) -> str:
        # Tao prompt JSON cho LLM.
        # LLM chi duoc nhin candidate_products da retrieval, nen khong duoc bia san pham ngoai database.
        product_payload = []
        for index, item in enumerate(ranked, start=1):
            # Rut gon moi san pham thanh object can thiet cho LLM viet loi tu van.
            doc = item.document
            product_payload.append({
                'rank_in_list': index,
                'product_id': doc.get('product_id'),
                'name': doc.get('name'),
                'brand': doc.get('brand'),
                'category': doc.get('category'),
                'price': doc.get('price'),
                'origin': doc.get('origin'),
                'key_ingredients': doc.get('key_ingredients') or [],
                'description': doc.get('description') or '',
                'hybrid_score': round(item.final_score, 4),
                'reasons': item.reasons,
            })

        budget = user_profile.get('budget')
        budget_note = ''
        if budget and int(budget) > 0:
            # Dua ngan sach vao prompt de LLM giai thich san pham co hop gia tien hay khong.
            budget_note = f"NgÃ¢n sÃ¡ch khÃ¡ch Ä‘Æ°a: khoáº£ng {int(budget):,} VND. So sÃ¡nh giÃ¡ tá»«ng sáº£n pháº©m (price) vá»›i má»©c nÃ y."

        if interaction_mode == 'chatbot':
            # Mode chatbot: viet giong hoi thoai tu nhien, than thien.
            voice_rules = [
                'Báº¡n lÃ  chatbot tÆ° váº¥n má»¹ pháº©m SkinSyntax, nÃ³i chuyá»‡n tá»± nhiÃªn nhÆ° ngÆ°á»i tháº­t (cÃ³ thá»ƒ xÆ°ng "tui", "mÃ¬nh").',
                'summary: viáº¿t Má»˜T Ä‘oáº¡n há»™i thoáº¡i liá»n máº¡ch 3â€“6 cÃ¢u â€” giá»‘ng tin nháº¯n chat â€” gá»£i Ã½ nÃªn Æ°u tiÃªn sáº£n pháº©m sá»‘ máº¥y trong danh sÃ¡ch, vÃ¬ sao, vÃ  náº¿u quan tÃ¢m giÃ¡ thÃ¬ gá»£i Ã½ thÃªm lá»±a chá»n ráº» hÆ¡n trong list (chá»‰ náº¿u cÃ³).',
                'Má»—i llm_explanation: 2â€“4 cÃ¢u, trÃ­ch Ã½ tá»« mÃ´ táº£/thÃ nh pháº§n trong dá»¯ liá»‡u; cÃ³ thá»ƒ nÃ³i "trong sáº£n pháº©m nÃ y cÃ³... giÃºp...".',
            ]
        else:
            # Mode advisor: viet giong chuyen gia phan tich, van dua tren du lieu that.
            voice_rules = [
                'Báº¡n lÃ  chuyÃªn gia phÃ¢n tÃ­ch má»¹ pháº©m SkinSyntax, tÆ° váº¥n nhÆ° ngÆ°á»i tháº­t (cÃ³ thá»ƒ má»Ÿ Ä‘áº§u kiá»ƒu "Theo tui nghÄ©...", "Tui nghÄ© lÃ ...").',
                'summary: tÃ³m táº¯t 2â€“4 cÃ¢u â€” nÃªu rÃµ nÃªn Æ°u tiÃªn sáº£n pháº©m thá»© máº¥y (rank_in_list) trong danh sÃ¡ch tráº£ vá», lÃ½ do theo loáº¡i da / váº¥n Ä‘á» / ngÃ¢n sÃ¡ch; cÃ³ thá»ƒ so sÃ¡nh 2 má»©c giÃ¡ trong list náº¿u phÃ¹ há»£p.',
                'Má»—i llm_explanation: giáº£i thÃ­ch vÃ¬ sao Ä‘Ãºng vá»›i profile + lá»‹ch sá»­ mua (náº¿u cÃ³), dá»±a trÃªn name, key_ingredients, description, category; nháº¯c giÃ¡ (price) khi liÃªn quan ngÃ¢n sÃ¡ch.',
            ]

        core_rules = [
            # Cac rule nay chan hallucination: LLM chi duoc noi ve san pham co trong candidate_products.
            'Chá»‰ Ä‘Æ°á»£c Ä‘á» cáº­p sáº£n pháº©m cÃ³ trong candidate_products (Ä‘Ãºng product_id).',
            'KhÃ´ng Ä‘Æ°á»£c bá»‹a thÆ°Æ¡ng hiá»‡u, thÃ nh pháº§n, cÃ´ng dá»¥ng khÃ´ng cÃ³ trong tá»«ng object sáº£n pháº©m.',
            'Náº¿u khÃ´ng Ä‘á»§ dá»¯ liá»‡u Ä‘á»ƒ nÃ³i vá» má»™t sáº£n pháº©m, hÃ£y nÃ³i ngáº¯n gá»n theo nhá»¯ng gÃ¬ cÃ³ (tÃªn, danh má»¥c, giÃ¡) thay vÃ¬ bá»‹a.',
            'Tráº£ vá» Ä‘Ãºng JSON thuáº§n tÃºy, khÃ´ng markdown, khÃ´ng text ngoÃ i JSON.',
            'Schema: {"summary":"...","product_recommendations":[{"product_id":"...","llm_explanation":"..."}]} â€” má»—i product_id pháº£i khá»›p má»™t pháº§n tá»­ trong candidate_products.',
        ]
        if budget_note:
            core_rules.insert(3, budget_note)

        prompt_payload = {
            # Day la toan bo ngu canh gui sang LLM: ho so, lich su, cau hoi, san pham ung vien, quy tac output.
            'user_profile': user_profile,
            'recent_history': history,
            'current_question': user_query,
            'candidate_products': product_payload,
            'interaction_mode': interaction_mode,
            'instruction': {
                'role': 'ChuyÃªn gia RAG: chá»‰ tráº£ lá»i dá»±a trÃªn candidate_products Ä‘Ã£ retrieval (khÃ´ng hallucination).',
                'voice_and_style': voice_rules,
                'rules': core_rules,
                'output_format': 'JSON',
            },
            'example_response': {
                'summary': 'Theo tui nghÄ©, vá»›i lÃ n da cá»§a báº¡n thÃ¬ nÃªn Æ°u tiÃªn sáº£n pháº©m sá»‘ 1 trong list vÃ¬ khá»›p váº¥n Ä‘á» báº¡n mÃ´ táº£; náº¿u báº¡n muá»‘n tiáº¿t kiá»‡m hÆ¡n thÃ¬ sáº£n pháº©m sá»‘ 3 cÅ©ng náº±m trong ngÃ¢n sÃ¡ch.',
                'product_recommendations': [
                    {'product_id': 'P001', 'llm_explanation': 'Trong sáº£n pháº©m nÃ y cÃ³ cÃ¡c thÃ nh pháº§n ... (theo dá»¯ liá»‡u) nÃªn phÃ¹ há»£p vá»›i ...'},
                ],
            },
        }
        return json.dumps(prompt_payload, ensure_ascii=False)

    def _generate_advice(
        self,
        user_profile: dict[str, Any],
        user_query: str,
        ranked: list[HybridCandidate],
        history: list[dict[str, Any]],
        interaction_mode: str = 'chatbot',
    ) -> dict[str, Any]:
        # Goi LLM de bien danh sach san pham thanh cau tu van de doc nhu nguoi that.
        prompt = self._build_generation_prompt(user_profile, user_query, ranked, history, interaction_mode)
        raw_text = self._call_gemini_text(prompt)
        cleaned = raw_text.strip()
        if cleaned.startswith('```'):
            # Neu LLM boc JSON trong markdown fence thi go bo fence truoc khi parse.
            cleaned = re.sub(r'^```(?:json)?\s*', '', cleaned)
            cleaned = re.sub(r'\s*```$', '', cleaned)
        try:
            parsed = json.loads(cleaned)
        except Exception:
            # Neu output co them text ngoai JSON, co gang tach object JSON dau tien.
            cleaned = self._extract_json_object(cleaned)
            parsed = json.loads(cleaned)

        if not isinstance(parsed, dict):
            raise RuntimeError('Gemini tráº£ vá» dá»¯ liá»‡u tÆ° váº¥n khÃ´ng há»£p lá»‡.')
        return parsed

    def recommend(self, user_profile: dict[str, Any], user_query: str, interaction_mode: str = 'chatbot') -> dict[str, Any]:
        # Entry point chinh cua service RAG recommendation.
        # Luong: build query -> embedding -> cache -> keyword retrieval -> semantic rerank -> LLM generation -> response.
        self.ping()
        interaction_mode = (interaction_mode or 'chatbot').strip().lower()
        if interaction_mode not in {'advisor', 'chatbot'}:
            interaction_mode = 'chatbot'

        query_text = self._build_query_text(user_profile, user_query)
        if query_text.strip() == '':
            # Khong co query/ho so thi khong du du lieu de goi y.
            raise ValueError('Thiáº¿u cÃ¢u há»i hoáº·c há»“ sÆ¡ ngÆ°á»i dÃ¹ng Ä‘á»ƒ táº¡o gá»£i Ã½.')

        profile_for_llm = {k: v for k, v in user_profile.items() if k != 'interaction_mode'}

        query_embedding = self.embed_text(query_text)
        # Kiem tra cache truoc de tiet kiem token neu cau hoi tuong tu da tung duoc tra loi.
        cached = self._find_similar_cached_answer(query_text, query_embedding)
        if cached and isinstance(cached.get('response'), dict):
            response = dict(cached['response'])
            response['cached'] = True
            return response

        customer_id = int(user_profile.get('customer_id') or 0)
        # Lay lich su gan day de LLM co the ca nhan hoa neu co customer_id.
        history = self._load_recent_history(customer_id)
        # Lay ung vien bang keyword/hybrid search.
        candidates = self._keyword_candidate_search(user_profile, query_text)
        # Sap xep lai ung vien bang embedding semantic.
        ranked = self._semantic_rerank(query_embedding, candidates)
        if not ranked:
            # Neu khong co san pham nao dat dieu kien, tra ve danh sach rong thay vi bia san pham.
            return {
                'status': 'success',
                'cached': False,
                'query': query_text,
                'summary': 'MÃ¬nh chÆ°a tÃ¬m tháº¥y sáº£n pháº©m Ä‘á»§ phÃ¹ há»£p tá»« bá»™ dá»¯ liá»‡u hiá»‡n táº¡i. Báº¡n hÃ£y thá»­ mÃ´ táº£ rÃµ hÆ¡n nhu cáº§u hoáº·c cáº­p nháº­t láº¡i kháº£o sÃ¡t.',
                'products': [],
            }

        summary = ''
        generated_items: list[Any] = []
        try:
            # Nho LLM viet summary va explanation cho tung san pham.
            generated = self._generate_advice(profile_for_llm, user_query, ranked, history, interaction_mode)
            summary = str(generated.get('summary') or '').strip()
            raw_items = generated.get('product_recommendations') if isinstance(generated, dict) else None
            generated_items = raw_items if isinstance(raw_items, list) else []
        except Exception as gen_err:
            # Neu LLM loi JSON/API, van tra san pham da retrieval kem giai thich fallback.
            print(f'[WARN] LLM tÆ° váº¥n JSON lá»—i, dÃ¹ng giáº£i thÃ­ch rÃºt gá»n: {gen_err}', flush=True)
            summary = ('Minh da loc san pham truc tiep tu MongoDB theo nhu cau ban nhap va ho so da hien co. Phan giai thich ben duoi duoc viet tu du lieu san pham trong database, khong bia them san pham ngoai shop.')

        valid_pids = {str(it.document.get('product_id') or '').strip() for it in ranked}
        valid_pids.discard('')

        # Map explanation theo product_id; neu LLM tra id sai thi khong gan vao san pham.
        explanation_map: dict[str, str] = {}
        orphan_explanations: list[str] = []
        if isinstance(generated_items, list):
            for item in generated_items:
                if not isinstance(item, dict):
                    continue
                product_id = str(item.get('product_id') or item.get('id') or item.get('productId') or '').strip()
                explanation = str(item.get('llm_explanation') or item.get('explanation') or item.get('advice') or '').strip()
                if not explanation:
                    continue
                if product_id and product_id in valid_pids:
                    explanation_map[product_id] = explanation
                else:
                    orphan_explanations.append(explanation)

        for item in ranked:
            # Chuyen ket qua ranked thanh format backend PHP/frontend dang dung.
            doc = item.document
            pid = str(doc.get('product_id') or '').strip()
            if pid and pid not in explanation_map and orphan_explanations:
                explanation_map[pid] = orphan_explanations.pop(0)

        products = []
        for item in ranked:
            doc = item.document
            product_id = str(doc.get('product_id') or '').strip()
            default_reason = ' vÃ  '.join(item.reasons[:2]) if item.reasons else 'phÃ¹ há»£p vá»›i há»“ sÆ¡ da vÃ  má»¥c tiÃªu chÄƒm sÃ³c cá»§a báº¡n'
            default_explanation = f"Sáº£n pháº©m nÃ y Ä‘Æ°á»£c lá»±a chá»n vÃ¬ {default_reason}."
            llm_explanation = explanation_map.get(product_id, default_explanation)
            products.append({
                'id': product_id,
                'ten_san_pham': doc.get('name') or '',
                'thuong_hieu': doc.get('brand') or '',
                'danh_muc': doc.get('category') or '',
                'gia_ban': int(doc.get('price') or 0),
                'link_hinh_anh': doc.get('image_url') or '',
                'image_url': doc.get('image_url') or '',
                'score': int(round(item.final_score * 100)),
                'reasons': item.reasons[:3],
                'llm_explanation': llm_explanation,
                'explanation_source': 'llm' if product_id in explanation_map else 'rag',
                'keyword_score': round(item.keyword_score, 4),
                'semantic_score': round(item.semantic_score, 4),
                'key_ingredients': list(doc.get('key_ingredients') or [])[:5],
            })

        response = {
            # Response cuoi cung tra ve cho route Flask/PHP proxy.
            'status': 'success',
            'cached': False,
            'query': query_text,
            'summary': summary,
            'products': products,
        }
        # Luu cache de lan sau cau hoi tuong tu co the tra nhanh hon.
        self._store_cached_answer(query_text, query_embedding, response)
        return response


def json_dumps(payload: dict[str, Any]) -> str:
    import json
    return json.dumps(payload, ensure_ascii=False)


def json_loads(raw_text: str) -> Any:
    import json
    return json.loads(raw_text)


_mongo_hybrid_service: MongoHybridRecommendationService | None = None


def get_mongo_hybrid_service() -> MongoHybridRecommendationService:
    global _mongo_hybrid_service
    if _mongo_hybrid_service is None:
        _mongo_hybrid_service = MongoHybridRecommendationService()
    return _mongo_hybrid_service

