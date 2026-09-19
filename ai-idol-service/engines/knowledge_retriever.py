import chromadb
from chromadb.config import Settings
from sentence_transformers import SentenceTransformer

from config import CHROMA_COLLECTION, CHROMA_HOST, CHROMA_PORT, logger


_collection = None
_embedding_model = None


def _get_collection():
    global _collection
    if _collection is None:
        client = chromadb.HttpClient(
            host=CHROMA_HOST,
            port=CHROMA_PORT,
            settings=Settings(anonymized_telemetry=False),
        )
        _collection = client.get_collection(CHROMA_COLLECTION)
    return _collection


def _get_embedding_model():
    global _embedding_model
    if _embedding_model is None:
        _embedding_model = SentenceTransformer(
            "sentence-transformers/static-similarity-mrl-multilingual-v1",
            device="cpu",
        )
    return _embedding_model


def retrieve_knowledge(snapshot: dict) -> str:
    """Read supplementary product knowledge through Chroma's HTTP API."""
    product_name = snapshot.get("name", "")
    brand_name = snapshot.get("brand", "")
    ingredients = snapshot.get("ingredients", "")
    skin_type = snapshot.get("skin_type", "")
    query = (
        f"Sản phẩm {product_name} của {brand_name}. "
        f"Thành phần {ingredients}. Tác dụng cho {skin_type}."
    )
    try:
        embedding = _get_embedding_model().encode(query, normalize_embeddings=True).tolist()
        result = _get_collection().query(
            query_embeddings=[embedding],
            n_results=3,
            include=["documents"],
        )
        documents = (result.get("documents") or [[]])[0]
        logger.info("[KNOWLEDGE-RETRIEVER] Retrieved %s document(s)", len(documents))
        return "\n---\n".join(document for document in documents if document)
    except Exception as exc:
        # RAG is supplementary. Product snapshots remain the source of truth.
        logger.warning("[KNOWLEDGE-RETRIEVER] Chroma lookup skipped: %s", exc)
        return ""
