# Project Status

## Recommendation System

- Guest `/backend/public/index.php?r=goiy` is public product discovery only.
- Logged-in `/backend/public/index.php?r=goiy` calls the standalone Recommendation Flask service `http://127.0.0.1:5002/api/recommend/llamaindex`.
- Personalized recommendation uses MongoDB user/profile/order/cart/chat data, LlamaIndex `VectorIndexRetriever`, LlamaIndex `BM25Retriever`, hybrid search, reranking, and Gemini-generated `answer_text`.
- If LlamaIndex/Gemini fails for logged-in users, the UI shows a friendly unavailable message and does not return MongoDB/SQL fallback as fake personalization.
- The persisted recommendation index is loaded from `database/recommendation_index`; rebuild it manually with `python -m recommendation.indexer`.
- Recommendation Flask entrypoint is `ai-service-flask/rcm_flask.py`; project does not use `app.py`.

## Chatbot

- Existing chatbot endpoint `/api/chat` remains unchanged.
- Existing chatbot remains a separate LangChain + ChromaDB module.
- Chatbot is not migrated to LlamaIndex and is not part of the `/goiy` recommendation flow.
- Chatbot Flask entrypoint remains `ai-service-flask/chatbot_flask.py` on port `5001`.

## Architecture Split

- Chatbot: `chatbot_flask.py`, LangChain + ChromaDB, route `/api/chat`, port `5001`.
- Recommendation: `rcm_flask.py`, PHP `/backend/public/index.php?r=goiy` + Flask `/api/recommend/llamaindex` + MongoDB profile/history/product data + persisted LlamaIndex product index, port `5002`.
