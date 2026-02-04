from flask import Flask, request, jsonify

app = Flask(__name__)

@app.get("/api/health")
def health():
    return jsonify({"status": "ok"})

@app.post("/api/goi-y")
def goi_y():
    # TODO: sau này bạn gọi recommendation / embeddings ở đây
    data = request.get_json(force=True)
    return jsonify({
        "input": data,
        "goi_y": [],
        "note": "MVP: endpoint chạy được, sẽ tích hợp RS/LLM sau"
    })

@app.post("/api/chat")
def chat():
    # TODO: sau này tích hợp RAG + ChromaDB
    data = request.get_json(force=True)
    cau_hoi = data.get("message", "")
    return jsonify({
        "message": cau_hoi,
        "answer": "MVP: Chatbot API đã chạy. Sẽ tích hợp RAG sau."
    })

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True)
