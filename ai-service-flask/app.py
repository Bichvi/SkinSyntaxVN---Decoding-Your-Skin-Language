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
from pathlib import Path

# Load environment variables
load_dotenv('.env')

from config import LlamaIndexConfig

app = Flask(__name__)
CORS(app)

CHAT_CACHE_FILE = Path(__file__).resolve().parent / '.cache' / 'chat_responses.json'
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
        "Ban la tro ly tu van my pham cho website SkinSyntax. "
        "Nhiem vu cua ban la giai thich vi sao tung san pham phu hop voi nguoi dung. "
        "Chi duoc su dung du lieu da cung cap. Khong bịa them thanh phan, cong dung hoac tac dung phu. "
        "Hay tra ve JSON hop le voi schema: "
        "{\"recommendations\":[{\"product_id\":\"...\",\"llm_explanation\":\"...\",\"source\":\"llm\"}]}. "
        "Moi explanation dai 2-3 cau, tieng Viet ro rang, ca nhan hoa, co the neu 1 luu y nhe neu can."
    )

    user_prompt = json.dumps({
        "user_profile": user_profile,
        "products": products,
        "instruction": "Giai thich vi sao moi san pham phu hop voi user. Khong de xuat them san pham moi."
    }, ensure_ascii=False)

    return f"{system_prompt}\n\n{user_prompt}"


def _call_gemini_chat(prompt_text: str) -> str:
    import google.generativeai as genai
    api_keys = LlamaIndexConfig.get_google_api_keys()
    if not api_keys:
        raise ValueError('GOOGLE_API_KEY chưa được cấu hình.')

    last_error = None
    for index, api_key in enumerate(api_keys):
        try:
            genai.configure(api_key=api_key)
            model = genai.GenerativeModel(LlamaIndexConfig.RECOMMENDATION_MODEL)
            response = model.generate_content(
                prompt_text,
                generation_config=genai.types.GenerationConfig(
                    temperature=LlamaIndexConfig.TEMPERATURE,
                    max_output_tokens=900,
                ),
            )
            return response.text.strip()
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

    normalized = []
    for item in recommendations:
        if not isinstance(item, dict):
            continue
        product_id = str(item.get('product_id', '')).strip()
        explanation = str(item.get('llm_explanation', '')).strip()
        if not product_id or not explanation:
            continue
        normalized.append({
            'product_id': product_id,
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
    return jsonify({
        "status": "ok",
        "llama_index": "ready" if runtime_setup else "not_initialized",
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

@app.post("/api/chat")
def chat():
    """Chat/RAG endpoint"""
    runtime_setup = _get_runtime_llama_setup()
    if not runtime_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.get_json(force=True)
    cau_hoi = data.get("message", "")
    
    if not cau_hoi:
        return jsonify({"error": "Message is required"}), 400

    cached_response = _get_cached_chat_response(cau_hoi)
    if cached_response is not None:
        return jsonify({
            "message": cau_hoi,
            "answer": cached_response['answer'],
            "sources": cached_response['sources'],
            "cached": True,
        })
    
    try:
        result = runtime_setup.query(cau_hoi)
        if isinstance(result, dict) and result.get('error'):
            return jsonify(result), 500

        answer = ''
        sources = []
        if isinstance(result, dict):
            answer = str(result.get('response', '')).strip()
            source_nodes = result.get('source_nodes') or []
            if isinstance(source_nodes, list):
                sources = [str(item) for item in source_nodes]

        _store_cached_chat_response(cau_hoi, answer, sources)

        return jsonify({
            "message": cau_hoi,
            "answer": answer,
            "sources": sources,
            "cached": False,
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
