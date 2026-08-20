# Routes

## PHP

- `GET /backend/public/index.php?r=goiy`
  - Guest: public product discovery with keyword, price, category, brand, and sort filters.
  - Logged-in: server-side call to Recommendation Flask `http://127.0.0.1:5002/api/recommend/llamaindex` and renders personalized results.
- `POST /backend/public/index.php?r=xulygoiy`
  - Legacy AJAX recommendation endpoint kept for compatibility; the new `/goiy` view no longer uses it.

## Flask

### Recommendation Service (`ai-service-flask/rcm_flask.py`, port 5002)

- `GET /health`
  - Recommendation health check: `{"ok": true, "service": "recommendation-flask", "framework": "LlamaIndex"}`.
- `POST /api/recommend/llamaindex`
  - Personalized LlamaIndex RAG recommendation used by logged-in `/goiy`.
  - Loads `database/recommendation_index`; does not rebuild the index per request.
  - Reads MongoDB profile, skin profile, orders, cart, and chat history.
  - Retrieves candidates with LlamaIndex `VectorIndexRetriever` + `BM25Retriever`, reranks top 5, then uses Gemini for `answer_text`.
- `GET /api/recommend/guest`
  - API-level guest recommendation/search if this service mounts recommendation API later. The PHP `/goiy` guest page currently uses MongoDB directly and does not call Flask.
- `POST /api/recommend/index`
  - Rebuilds the standalone recommendation LlamaIndex if enabled through the recommendation blueprint. Normal `/goiy` requests do not call this.

### Chatbot Service (`ai-service-flask/chatbot_flask.py`, port 5001)

- `GET /health`
  - Chatbot health check.
- `GET /api/health`
  - Detailed chatbot service health check.
- `POST /api/chat`
  - Existing chatbot endpoint; unchanged.
  - Chatbot remains LangChain + ChromaDB and is separate from recommendation.
