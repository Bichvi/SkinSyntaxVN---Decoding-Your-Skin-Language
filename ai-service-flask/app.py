"""
LlamaIndex Integration với Flask
File: ai-service-flask/app.py
"""
from flask import Flask, jsonify, request
from flask_cors import CORS
from dotenv import load_dotenv
import os

# Load environment variables
load_dotenv('.env')

from config import LlamaIndexConfig
from rag.llama_setup import get_llama_setup

app = Flask(__name__)
CORS(app)

# Initialize LlamaIndex
try:
    llama_setup = get_llama_setup()
    print("[OK] LlamaIndex initialized successfully")
except Exception as e:
    print(f"[ERROR] Error initializing LlamaIndex: {str(e)}")
    llama_setup = None

# ============================================
# ROUTES
# ============================================

@app.route('/api/health', methods=['GET'])
def health():
    """Check health của API"""
    return jsonify({
        "status": "ok",
        "llama_index": "ready" if llama_setup else "not_initialized",
        "model": LlamaIndexConfig.LLAMA_MODEL,
        "vector_db": LlamaIndexConfig.VECTOR_DB_TYPE
    })

@app.route('/api/query', methods=['POST'])
def query():
    """Query LlamaIndex"""
    if not llama_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.json
    query_text = data.get('query')
    
    if not query_text:
        return jsonify({"error": "Query is required"}), 400
    
    try:
        result = llama_setup.query(query_text)
        return jsonify(result)
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/api/load-documents', methods=['POST'])
def load_documents():
    """Load documents từ thư mục"""
    if not llama_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.json
    directory = data.get('directory', './data')
    
    try:
        documents = llama_setup.load_documents_from_directory(directory)
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
        "temperature": LlamaIndexConfig.TEMPERATURE
    })

@app.post("/api/goi-y")
def goi_y():
    """Recommendation endpoint"""
    if not llama_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.get_json(force=True)
    
    # Build query from recommendation form
    loai_da = data.get('loai_da', '')
    concern = data.get('concern', '')
    budget = data.get('budget', '')
    
    query = f"san pham skincare cho da {loai_da} {concern} trong khoang gia {budget}"
    
    try:
        result = llama_setup.query(query)
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
    if not llama_setup:
        return jsonify({"error": "LlamaIndex not initialized"}), 500
    
    data = request.get_json(force=True)
    cau_hoi = data.get("message", "")
    
    if not cau_hoi:
        return jsonify({"error": "Message is required"}), 400
    
    try:
        result = llama_setup.query(cau_hoi)
        return jsonify({
            "message": cau_hoi,
            "answer": result
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
    app.run(debug=True, port=port, host='0.0.0.0')
