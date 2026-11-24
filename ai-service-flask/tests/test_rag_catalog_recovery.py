"""Run against a disposable Chroma 0.5.20 server with no production credentials."""

import os
import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch

from chatbot_service.sync_rag_catalog import SOURCE, stage_catalog
from recommendation_service.product_repository import product_text


@unittest.skipUnless(os.getenv("RAG_TEST_CHROMA") == "1", "requires isolated Chroma fixture")
class RecoveryTest(unittest.TestCase):
    def test_stage_retrieve_and_cutover(self):
        import chromadb
        from chatbot_service import chatbot_flask as chatbot, retrieval
        from recommendation_service.retrieval import ChromaRetrieval
        from search_service.branches import CatalogVectorstore
        from shared.embedding_provider import get_embedding_function

        client = chromadb.HttpClient(host=os.environ["CHROMA_HOST"], port=8000)
        source = client.get_collection(SOURCE)
        legacy = client.get_collection("products")
        target = client.get_collection("products_rag_fixture")
        product = {"id": "12", "ten_san_pham": "Serum Niacinamide", "thuong_hieu": "Fixture",
                   "loai_san_pham": "Serum", "loai_da": "Da dầu", "price": 99000,
                   "thanh_phan_day_du": "Niacinamide, Glycerin"}
        document = product_text(product)
        raw = [{"ma_san_pham": 12, "thanh_phan_sach": "Niacinamide, Glycerin"}]
        embed = get_embedding_function()
        source.add(ids=["12"], documents=[document], embeddings=embed.embed_documents([document]),
                   metadatas=[{"product_id": "12", "name": "Serum Niacinamide"}])
        with self.assertRaisesRegex(ValueError, "catalog_id_or_count_mismatch"):
            stage_catalog(source, target, [product], raw, embed, 2, True)
        self.assertEqual(target.count(), 0)
        self.assertEqual(stage_catalog(source, target, [product], raw, embed, 1)["missing"], 1)
        self.assertEqual(target.count(), 0)
        self.assertEqual(stage_catalog(source, target, [product], raw, embed, 1, True)["missing"], 1)
        self.assertEqual(stage_catalog(source, target, [product], raw, embed, 1, True)["missing"], 0)
        self.assertEqual((source.count(), legacy.count(), target.count()), (1, 0, 1))
        with self.assertRaisesRegex(ValueError, "target_row_conflict"):
            stage_catalog(source, target, [dict(product, price=1)], raw, embed, 1, True)

        with patch.dict(os.environ, {"CHATBOT_CHROMA_COLLECTION": target.name}):
            store = chatbot._build_vectorstore()
            direct = retrieval._build_vectorstore().get(ids=["product_12"])
            docs = store.similarity_search("serum niacinamide cho da dầu", k=1,
                                           filter={"loai_da": "Da dầu"})
            self.assertEqual(direct["ids"], ["product_12"])
            self.assertEqual(docs[0].metadata["gia_ban"], 99000)
            self.assertEqual(chatbot.filter_docs_by_ingredient(docs, "niacinamide"), docs)
            with patch.object(chatbot, "get_vectorstore", return_value=store):
                response = chatbot.app.test_client().get("/api/health")
                self.assertEqual(response.status_code, 200)
                self.assertEqual(response.json["documents"], 1)
            with patch.object(chatbot, "xu_ly_cau_hoi", return_value={
                "ok": True, "answer": "Serum có Niacinamide."}):
                response = chatbot.app.test_client().post("/api/chat/auto", json={"message": "Serum gì?"})
                self.assertEqual(response.status_code, 200)
                self.assertIn("Niacinamide", response.json["answer"])
        with patch.dict(os.environ, {"CHATBOT_CHROMA_COLLECTION": "products"}):
            self.assertEqual(chatbot._build_vectorstore()._collection.count(), 0)
        search_store = CatalogVectorstore()
        self.assertEqual(search_store.get()._collection.name, SOURCE)
        self.assertEqual(search_store.health(), "ok")
        self.assertEqual(ChromaRetrieval(vectorstore=search_store).search("serum niacinamide")[0].product_id, "12")


class ProviderFallbackTest(unittest.TestCase):
    def test_quota_and_connection_failures_try_next_provider(self):
        from chatbot_service import chatbot_flask as chatbot

        for failure, cooled in [(RuntimeError("429 quota"), True), (ConnectionError("offline"), False)]:
            with self.subTest(failure=type(failure).__name__):
                first = SimpleNamespace(model="fixture-primary", invoke=Mock(side_effect=failure))
                second = SimpleNamespace(model="fixture-fallback",
                                         invoke=Mock(return_value=SimpleNamespace(content="Niacinamide là gì?")))
                self.assertEqual(chatbot.contextualize_query("là gì?", "Niacinamide", [first, second]),
                                 "Niacinamide là gì?")
                second.invoke.assert_called_once()
                self.assertEqual(chatbot._get_llm_cooldown(first) > 0, cooled)


if __name__ == "__main__":
    unittest.main()
