"""
LlamaIndex Integration với Flask (Google Gemini)
File: ai-service-flask/app.py
"""
from flask import Flask, jsonify, request
from flask_cors import CORS
from dotenv import load_dotenv
import os
import json
import re
import hashlib
import threading
import time
import logging
from pathlib import Path

# Load environment variables
SERVICE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SERVICE_DIR.parent
load_dotenv(PROJECT_ROOT / '.env')
load_dotenv(SERVICE_DIR / '.env')

from config import LlamaIndexConfig

try:
    from rag.mongo_hybrid_service import get_mongo_hybrid_service
except Exception as mongo_import_error:
    print(f"[WARN] Mongo hybrid service import failed: {mongo_import_error}", flush=True)
    get_mongo_hybrid_service = None

print("[DEBUG] Imports completed successfully", flush=True)

# Import LangChain components
# DISABLED: Imports causing hang during startup
# try:
#     from api.langchain_endpoints import init_langchain_components, register_langchain_routes
#     from langchain_google_genai import ChatGoogleGenerativeAI
# except Exception as langchain_import_error:
#     print(f"[WARN] LangChain components import failed: {langchain_import_error}")
#     init_langchain_components = None
#     register_langchain_routes = None
#     ChatGoogleGenerativeAI = None

# Use minimal stubs for now
init_langchain_components = None
register_langchain_routes = None
ChatGoogleGenerativeAI = None

app = Flask(__name__)
CORS(app)

# Configure Flask for LangChain
app.config['REDIS_URL'] = os.getenv('REDIS_URL', 'redis://localhost:6379/0')

# Initialize LangChain components if available
if init_langchain_components and register_langchain_routes and ChatGoogleGenerativeAI:
    try:
        # Initialize LLM
        google_api_key = LlamaIndexConfig.get_google_api_keys()
        if google_api_key:
            api_key = google_api_key[0]  # Use first available API key
            llm = ChatGoogleGenerativeAI(
                model="gemini-2.5-flash",
                google_api_key=api_key,
                temperature=0.7,
            )
            
            # Initialize LangChain components
            mongo_uri = LlamaIndexConfig.MONGODB_URI
            db_name = LlamaIndexConfig.MONGODB_DB_NAME
            init_langchain_components(app, llm, mongo_uri, db_name)
            
            # Register LangChain routes
            register_langchain_routes(app)
            
            print("[OK] LangChain RAG components initialized and routes registered")
        else:
            print("[WARN] GOOGLE_API_KEY not configured for LangChain")
    except Exception as langchain_init_error:
        print(f"[ERROR] Failed to initialize LangChain components: {langchain_init_error}")
else:
    print("[WARN] LangChain imports not available")

CHAT_CACHE_FILE = SERVICE_DIR / '.cache' / 'chat_responses.json'
CHAT_CACHE_TTL_SECONDS = max(3600, int(os.getenv('CHAT_CACHE_TTL_SECONDS', '604800')))
CHAT_CACHE_MAX_ITEMS = max(50, int(os.getenv('CHAT_CACHE_MAX_ITEMS', '500')))
_chat_cache_lock = threading.Lock()
_chat_cache = None

# Initialize LlamaIndex
try:
    from rag.llama_setup import get_llama_setup
    llama_setup = get_llama_setup()
    print("[OK] LlamaIndex initialized successfully")
except Exception as e:
    print(f"[ERROR] Error initializing LlamaIndex: {str(e)}")
    llama_setup = None


def _get_runtime_llama_setup():
    global llama_setup

    if llama_setup is not None:
        return llama_setup

    try:
        from rag.llama_setup import get_llama_setup
        llama_setup = get_llama_setup()
        print("[OK] LlamaIndex initialized lazily at request time")
        return llama_setup
    except Exception as error:
        print(f"[ERROR] Lazy LlamaIndex initialization failed: {str(error)}")
        llama_setup = None
        return None


def _get_runtime_mongo_service():
    if not LlamaIndexConfig.ENABLE_MONGODB_RAG or get_mongo_hybrid_service is None:
        return None

    try:
        service = get_mongo_hybrid_service()
        service.ping()
        return service
    except Exception as error:
        print(f"[ERROR] Mongo hybrid service unavailable: {error}")
        return None


def _strip_json_fence(raw_text: str) -> str:
    text = (raw_text or '').strip()
    if text.startswith('```'):
        text = re.sub(r'^```(?:json)?\s*', '', text)
        text = re.sub(r'\s*```$', '', text)
    return text.strip()


def _normalize_chat_cache_key(message_text: str) -> str:
    normalized = re.sub(r'\s+', ' ', (message_text or '').strip().lower())
    return hashlib.sha256(normalized.encode('utf-8')).hexdigest()


def _load_chat_cache() -> dict:
    global _chat_cache
    with _chat_cache_lock:
        if _chat_cache is not None:
            return _chat_cache

        try:
            if CHAT_CACHE_FILE.exists():
                _chat_cache = json.loads(CHAT_CACHE_FILE.read_text(encoding='utf-8'))
                if isinstance(_chat_cache, dict):
                    return _chat_cache
        except Exception:
            pass

        _chat_cache = {}
        return _chat_cache


def _persist_chat_cache(cache_data: dict) -> None:
    CHAT_CACHE_FILE.parent.mkdir(parents=True, exist_ok=True)
    CHAT_CACHE_FILE.write_text(
        json.dumps(cache_data, ensure_ascii=False, separators=(',', ':')),
        encoding='utf-8',
    )


def _get_cached_chat_response(message_text: str) -> dict | None:
    cache_data = _load_chat_cache()
    cache_key = _normalize_chat_cache_key(message_text)
    now = int(time.time())
    entry = cache_data.get(cache_key)
    if not isinstance(entry, dict):
        return None

    created_at = int(entry.get('created_at', 0) or 0)
    if created_at <= 0 or now - created_at > CHAT_CACHE_TTL_SECONDS:
        with _chat_cache_lock:
            cache_data.pop(cache_key, None)
            _persist_chat_cache(cache_data)
        return None

    answer = str(entry.get('answer', '')).strip()
    if answer == '':
        return None

    sources = entry.get('sources')
    return {
        'answer': answer,
        'sources': sources if isinstance(sources, list) else [],
        'created_at': created_at,
    }


def _store_cached_chat_response(message_text: str, answer: str, sources: list[str]) -> None:
    normalized_answer = str(answer or '').strip()
    if normalized_answer == '':
        return

    cache_data = _load_chat_cache()
    cache_key = _normalize_chat_cache_key(message_text)
    now = int(time.time())

    with _chat_cache_lock:
        cache_data[cache_key] = {
            'answer': normalized_answer,
            'sources': [str(item) for item in (sources or [])][:10],
            'created_at': now,
        }

        if len(cache_data) > CHAT_CACHE_MAX_ITEMS:
            sorted_items = sorted(
                cache_data.items(),
                key=lambda item: int((item[1] or {}).get('created_at', 0) or 0),
                reverse=True,
            )
            cache_data = dict(sorted_items[:CHAT_CACHE_MAX_ITEMS])
            globals()['_chat_cache'] = cache_data

        _persist_chat_cache(cache_data)


def _build_recommendation_prompt(user_profile: dict, products: list[dict]) -> str:
    system_prompt = (
        "Bạn là tư vấn mỹ phẩm SkinSyntax (tiếng Việt, giọng tự nhiên như người: có thể \"tui\", \"bạn\"). "
        "Chỉ dùng thông tin trong từng sản phẩm được gửi; không bịa thành phần hay công dụng. "
        "Nếu user_query nêu loại sản phẩm (vd kem dưỡng, serum), hãy giải thích đúng vì sao sản phẩm ĐÓ "
        "phù hợp với yêu cầu đó và hồ sơ da. "
        "Trả về JSON thuần: {\"recommendations\":[{\"product_id\":\"...\",\"llm_explanation\":\"...\",\"source\":\"llm\"}]} — "
        "product_id phải khớp chính xác id trong products. Mỗi llm_explanation 2–4 câu."
    )

    user_prompt = json.dumps({
        "user_profile": user_profile,
        "products": products,
        "instruction": (
            "Giải thích từng sản phẩm theo đúng product_id. "
            "Nếu sản phẩm không khớp nhu cầu user_query thì nói thận trọng theo dữ liệu có (vd danh mục, mô tả), không bịa."
        ),
    }, ensure_ascii=False)

    return f"{system_prompt}\n\n{user_prompt}"


def _call_gemini_chat(prompt_text: str) -> str:
    try:
        import google.generativeai as genai
    except ImportError:
        try:
            import google.genai as genai
        except ImportError:
            raise ValueError('Google Gemini library chưa được cài đặt.')

    api_keys = LlamaIndexConfig.get_google_api_keys()
    if not api_keys:
        raise ValueError('GOOGLE_API_KEY chưa được cấu hình.')

    last_error = None
    for index, api_key in enumerate(api_keys):
        try:
            if hasattr(genai, 'configure') and hasattr(genai, 'GenerativeModel'):
                genai.configure(api_key=api_key)
                model = genai.GenerativeModel(LlamaIndexConfig.gemini_model_resource())
                response = model.generate_content(
                    prompt_text,
                    generation_config=genai.types.GenerationConfig(
                        temperature=LlamaIndexConfig.TEMPERATURE,
                        max_output_tokens=900,
                    ),
                )
                return response.text.strip()

            # google.genai fallback
            if hasattr(genai, 'Client') and hasattr(genai, 'Model'):
                client = genai.Client(api_key=api_key)
                model = genai.Model(LlamaIndexConfig.RECOMMENDATION_MODEL)
                response = client.generate(model=model, prompt=prompt_text)
                return getattr(response, 'text', str(response)).strip()

            raise RuntimeError('Không hỗ trợ thư viện Gemini hiện tại.')
        except Exception as error:
            last_error = error
            message = str(error).lower()
            is_quota_error = 'quota exceeded' in message or 'rate limit' in message or '429' in message or 'retry in' in message
            if is_quota_error and index < len(api_keys) - 1:
                print(f"[WARN] Recommendation Gemini key {index + 1}/{len(api_keys)} hit quota, rotating to next key")
                continue
            raise

    if last_error is not None:
        raise last_error

    raise RuntimeError('No Gemini API key is available for recommendation chat.')


def generate_recommendation_explanations(user_profile: dict, products: list[dict]) -> list[dict]:
    products = [product for product in products if isinstance(product, dict)][:LlamaIndexConfig.RECOMMENDATION_MAX_PRODUCTS]
    if not products:
        return []

    messages = _build_recommendation_prompt(user_profile, products)
    raw_content = _call_gemini_chat(messages)
    cleaned = _strip_json_fence(raw_content)

    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f'LLM response is not valid JSON: {cleaned[:300]}') from exc

    recommendations = parsed.get('recommendations') if isinstance(parsed, dict) else None
    if not isinstance(recommendations, list):
        raise RuntimeError('LLM response missing recommendations array.')

    valid_ids = {str(p.get('id') or p.get('product_id') or '').strip() for p in products if p}
    valid_ids.discard('')

    by_id: dict[str, str] = {}
    orphan_explanations: list[str] = []
    for item in recommendations:
        if not isinstance(item, dict):
            continue
        product_id = str(item.get('product_id', '')).strip()
        explanation = str(item.get('llm_explanation', '')).strip()
        if not explanation:
            continue
        if product_id and product_id in valid_ids:
            by_id[product_id] = explanation
        else:
            orphan_explanations.append(explanation)

    normalized = []
    for prod in products:
        pid = str(prod.get('id') or prod.get('product_id') or '').strip()
        if not pid:
            continue
        explanation = (by_id.get(pid) or '').strip()
        if not explanation and orphan_explanations:
            explanation = orphan_explanations.pop(0)
        if not explanation:
            continue
        normalized.append({
            'product_id': pid,
            'llm_explanation': explanation,
            'source': 'llm',
        })

    return normalized

# ============================================
# ROUTES
# ============================================

@app.route('/api/health', methods=['GET'])
def health():
    """Check health của API"""
    runtime_setup = _get_runtime_llama_setup()
    mongo_service = _get_runtime_mongo_service()
    return jsonify({
        "status": "ok",
        "llama_index": "ready" if runtime_setup else "not_initialized",
        "mongodb_rag": "ready" if mongo_service and mongo_service.is_ready() else "not_initialized",
        "model": LlamaIndexConfig.LLAMA_MODEL,
        "vector_db": LlamaIndexConfig.VECTOR_DB_TYPE
    })

@app.route('/api/query', methods=['POST'])
def query():
    """Query LlamaIndex"""
    runtime_setup = _get_runtime_llama_setup()
    if not runtime_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.json
    query_text = data.get('query')
    
    if not query_text:
        return jsonify({"error": "Query is required"}), 400
    
    try:
        result = runtime_setup.query(query_text)
        return jsonify(result)
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/load-documents', methods=['POST'])
def load_documents():
    """Load documents từ thư mục"""
    runtime_setup = _get_runtime_llama_setup()
    if not runtime_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.json
    directory = data.get('directory', './data')
    
    try:
        documents = runtime_setup.load_documents_from_directory(directory)
        return jsonify({
            "status": "success",
            "documents_loaded": len(documents),
            "message": f"Loaded {len(documents)} documents"
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/config', methods=['GET'])
def get_config():
    """Lấy LlamaIndex config"""
    return jsonify({
        "model": LlamaIndexConfig.LLAMA_MODEL,
        "vector_db": LlamaIndexConfig.VECTOR_DB_TYPE,
        "vector_db_path": LlamaIndexConfig.VECTOR_DB_PATH,
        "mongodb_uri": LlamaIndexConfig.MONGODB_URI,
        "mongodb_db_name": LlamaIndexConfig.MONGODB_DB_NAME,
        "chunk_size": LlamaIndexConfig.CHUNK_SIZE,
        "chunk_overlap": LlamaIndexConfig.CHUNK_OVERLAP,
        "top_k": LlamaIndexConfig.TOP_K,
        "temperature": LlamaIndexConfig.TEMPERATURE,
        "recommendation_model": LlamaIndexConfig.RECOMMENDATION_MODEL,
        "gemini_configured": bool(LlamaIndexConfig.GOOGLE_API_KEY)
    })


@app.post('/api/recommend/explain')
def recommend_explain():
    data = request.get_json(force=True) or {}
    user_profile = data.get('user_profile') or {}
    products = data.get('products') or []

    if not isinstance(user_profile, dict):
        return jsonify({"error": "user_profile must be an object"}), 400
    if not isinstance(products, list) or not products:
        return jsonify({"error": "products must be a non-empty array"}), 400

    try:
        recommendations = generate_recommendation_explanations(user_profile, products)
        return jsonify({
            'status': 'success',
            'message': 'Generated recommendation explanations successfully.',
            'recommendations': recommendations,
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 500


def _redis_recommendation_client():
    if not LlamaIndexConfig.REDIS_URL:
        return None
    try:
        import redis
        return redis.from_url(LlamaIndexConfig.REDIS_URL, decode_responses=True)
    except Exception as exc:
        print(f"[WARN] Redis không khả dụng cho recommendation cache: {exc}", flush=True)
        return None


def _hybrid_cache_fingerprint(user_profile: dict, query_text: str, interaction_mode: str) -> str:
    concerns = user_profile.get('concerns') or []
    avoid = user_profile.get('avoid_ingredients') or []
    payload = {
        'budget': user_profile.get('budget'),
        'skin_type': user_profile.get('skin_type'),
        'customer_id': user_profile.get('customer_id'),
        'concerns': sorted(str(x) for x in concerns) if isinstance(concerns, list) else [],
        'avoid': sorted(str(x) for x in avoid) if isinstance(avoid, list) else [],
        'query': re.sub(r'\s+', ' ', (query_text or '').strip().lower()),
        'mode': (interaction_mode or 'advisor').strip().lower(),
    }
    raw = json.dumps(payload, ensure_ascii=False, sort_keys=True, default=str)
    return hashlib.sha256(raw.encode('utf-8')).hexdigest()


@app.post('/api/recommend/hybrid')
@app.post('/api/recommend/langchain-rag')
def recommend_hybrid():
    """
    Hybrid RAG: keyword + semantic trên collection products_rag (MongoDB),
    cache embedding tương đồng trong MongoDB, tùy chọn Redis L1, Gemini sinh JSON tư vấn.
    """
    data = request.get_json(force=True) or {}
    user_profile = data.get('user_profile') or {}
    query_text = str(data.get('query_text') or data.get('user_query') or '').strip()
    interaction_mode = str(data.get('interaction_mode') or 'chatbot').strip().lower()
    if interaction_mode not in {'advisor', 'chatbot'}:
        interaction_mode = 'chatbot'

    if not isinstance(user_profile, dict):
        return jsonify({'error': 'user_profile must be an object'}), 400

    cache_fingerprint = _hybrid_cache_fingerprint(user_profile, query_text, interaction_mode)
    redis_key = f'skinsyntax:hybrid:{cache_fingerprint}'

    redis_client = _redis_recommendation_client()
    if redis_client is not None:
        try:
            cached_raw = redis_client.get(redis_key)
            if cached_raw:
                cached_body = json.loads(cached_raw)
                if isinstance(cached_body, dict):
                    cached_body = dict(cached_body)
                    cached_body['cached'] = True
                    cached_body['cache_layer'] = 'redis'
                    return jsonify(cached_body)
        except Exception as redis_err:
            print(f"[WARN] Redis get hybrid: {redis_err}", flush=True)

    if not LlamaIndexConfig.ENABLE_MONGODB_RAG or get_mongo_hybrid_service is None:
        return jsonify({
            'status': 'error',
            'message': 'MongoDB RAG chưa bật hoặc service không import được.',
            'summary': '',
            'products': [],
            'cached': False,
        }), 503

    try:
        mongo_service = get_mongo_hybrid_service()
        if not mongo_service.is_ready():
            return jsonify({
                'status': 'error',
                'message': 'MongoDB chưa có dữ liệu sản phẩm trong products_rag hoặc san_pham.',
                'summary': '',
                'products': [],
                'cached': False,
            }), 503

        result = mongo_service.recommend(user_profile, query_text, interaction_mode=interaction_mode)
        result = dict(result)
        result.setdefault('status', 'success')
        result.setdefault('message', 'Đã lấy gợi ý hybrid RAG.')
        if result.get('cache_layer') is None:
            result['cache_layer'] = 'mongodb' if result.get('cached') else 'none'

        if redis_client is not None and not result.get('cached'):
            try:
                to_store = dict(result)
                to_store.pop('cache_layer', None)
                redis_client.setex(
                    redis_key,
                    LlamaIndexConfig.REDIS_RECOMMENDATION_TTL_SECONDS,
                    json.dumps(to_store, ensure_ascii=False, default=str),
                )
            except Exception as redis_err:
                print(f"[WARN] Redis set hybrid: {redis_err}", flush=True)

        return jsonify(result)

    except ValueError as validation_error:
        return jsonify({
            'status': 'error',
            'message': str(validation_error),
            'summary': '',
            'products': [],
            'cached': False,
        }), 400
    except Exception as error:
        print(f"[ERROR] recommend_hybrid: {error}", flush=True)
        return jsonify({'error': str(error), 'products': [], 'status': 'error'}), 500

@app.post("/api/goi-y")
def goi_y():
    """Recommendation endpoint"""
    runtime_setup = _get_runtime_llama_setup()
    if not runtime_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.get_json(force=True)
    
    # Build query from recommendation form
    loai_da = data.get('loai_da', '')
    concern = data.get('concern', '')
    budget = data.get('budget', '')
    
    query = f"san pham skincare cho da {loai_da} {concern} trong khoang gia {budget}"
    
    try:
        result = runtime_setup.query(query)
        return jsonify({
            "status": "success",
            "query": query,
            "recommendations": result
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 500

def _format_vnd_chat(price: int) -> str:
    if price <= 0:
        return ''
    return f'{int(price):,}'.replace(',', '.') + 'đ'


@app.post("/api/chat")
def chat():
    """
    Chat: ưu tiên cùng pipeline hybrid RAG + Gemini như /api/recommend/hybrid (PHP gửi JSON có customer_question).
    Fallback: LlamaIndex query nếu Mongo RAG không sẵn sàng.
    """
    data = request.get_json(force=True) or {}
    raw_message = str(data.get('message', '') or '').strip()

    if not raw_message:
        return jsonify({"error": "Message is required"}), 400

    user_question = raw_message
    user_profile: dict = {}

    try:
        blob = json.loads(raw_message)
        if isinstance(blob, dict) and blob.get('customer_question'):
            user_question = str(blob.get('customer_question') or '').strip()
            cp = blob.get('customer_profile')
            if isinstance(cp, dict):
                user_profile = {
                    'skin_type': str(cp.get('skin_type') or ''),
                    'concerns': cp['concerns'] if isinstance(cp.get('concerns'), list) else [],
                    'avoid_ingredients': cp['avoid_ingredients'] if isinstance(cp.get('avoid_ingredients'), list) else [],
                    'budget': int(cp.get('budget') or 0),
                    'customer_id': int(cp.get('customer_id') or 0),
                }
    except (json.JSONDecodeError, TypeError, ValueError):
        pass

    cached_response = _get_cached_chat_response(raw_message)
    if cached_response is not None:
        return jsonify({
            "message": user_question,
            "answer": cached_response['answer'],
            "sources": cached_response['sources'],
            "cached": True,
        })

    mongo_service = _get_runtime_mongo_service()
    if mongo_service and mongo_service.is_ready() and user_question:
        try:
            res = mongo_service.recommend(user_profile, user_question, interaction_mode='chatbot')
            summary = str(res.get('summary') or '').strip()
            prods = res.get('products') if isinstance(res.get('products'), list) else []
            parts = []
            if summary:
                parts.append(summary)
            for i, p in enumerate(prods[:6], 1):
                if not isinstance(p, dict):
                    continue
                name = str(p.get('ten_san_pham') or p.get('name') or '').strip()
                expl = str(p.get('llm_explanation') or '').strip()
                price = int(p.get('gia_ban') or p.get('price') or 0)
                price_bit = _format_vnd_chat(price)
                if not name:
                    continue
                line = f'{i}. {name}'
                if price_bit:
                    line += f' ({price_bit})'
                if expl:
                    line += f' — {expl}'
                parts.append(line)

            answer = '\n\n'.join(parts).strip()
            if answer:
                sources = [str(p.get('ten_san_pham') or '') for p in prods if isinstance(p, dict) and p.get('ten_san_pham')]
                _store_cached_chat_response(raw_message, answer, sources)
                return jsonify({
                    'message': user_question,
                    'answer': answer,
                    'sources': sources,
                    'products': prods,
                    'cached': bool(res.get('cached')),
                    'rag': True,
                })
        except Exception as ex:
            print(f'[WARN] /api/chat hybrid RAG: {ex}', flush=True)

    runtime_setup = _get_runtime_llama_setup()
    if not runtime_setup:
        return jsonify({
            'error': 'Chưa khởi tạo LlamaIndex và hybrid RAG không trả lời được. Kiểm tra collection products_rag trong MongoDB và GOOGLE_API_KEY.',
            'answer': '',
        }), 503

    try:
        result = runtime_setup.query(raw_message)
        if isinstance(result, dict) and result.get('error'):
            return jsonify(result), 500

        answer = ''
        sources = []
        if isinstance(result, dict):
            answer = str(result.get('response', '')).strip()
            source_nodes = result.get('source_nodes') or []
            if isinstance(source_nodes, list):
                sources = [str(item) for item in source_nodes]

        _store_cached_chat_response(raw_message, answer, sources)

        return jsonify({
            "message": user_question,
            "answer": answer,
            "sources": sources,
            "cached": False,
            "rag": False,
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# ============================================
# ERROR HANDLERS
# ============================================

@app.errorhandler(404)
def not_found(error):
    return jsonify({"error": "Not found"}), 404

@app.errorhandler(500)
def internal_error(error):
    return jsonify({"error": "Internal server error"}), 500

# ============================================
# MAIN
# ============================================

if __name__ == '__main__':
    port = int(os.getenv('FLASK_PORT', 5000))
    debug_mode = os.getenv('FLASK_ENV', 'development').strip().lower() == 'development'
    app.run(debug=debug_mode, use_reloader=False, port=port, host='0.0.0.0')
