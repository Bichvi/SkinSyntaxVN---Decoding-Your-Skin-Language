"""
LlamaIndex RAG Setup for SkinSyntax (Google Gemini)
File: ai-service-flask/rag/llama_setup.py
"""
import os
from typing import List
from llama_index.core import VectorStoreIndex, SimpleDirectoryReader, Document
from llama_index.vector_stores.chroma import ChromaVectorStore
from llama_index.core import StorageContext
from llama_index.embeddings.gemini import GeminiEmbedding
from llama_index.llms.gemini import Gemini
from llama_index.core import Settings
import chromadb

from config import LlamaIndexConfig

class LlamaIndexSetup:
    """Setup LlamaIndex với Chroma Vector Store + Google Gemini"""
    
    def __init__(self):
        # Validate config
        LlamaIndexConfig.validate()

        self.google_api_keys = LlamaIndexConfig.get_google_api_keys()
        self.active_api_key = ''
        self._configure_models(self.google_api_keys[0])
        Settings.chunk_size = LlamaIndexConfig.CHUNK_SIZE
        Settings.chunk_overlap = LlamaIndexConfig.CHUNK_OVERLAP
        
        self.vector_db_path = LlamaIndexConfig.VECTOR_DB_PATH
        self.collection_name = LlamaIndexConfig.CHROMA_COLLECTION
        self.index = None
        
        # Create vector store
        self._init_vector_store()

    def _configure_models(self, api_key: str):
        os.environ['GOOGLE_API_KEY'] = api_key
        self.active_api_key = api_key

        Settings.llm = Gemini(
            model=LlamaIndexConfig.LLAMA_MODEL,
            temperature=LlamaIndexConfig.TEMPERATURE,
            api_key=api_key,
        )
        Settings.embed_model = GeminiEmbedding(
            model_name="models/text-embedding-004",
            api_key=api_key,
        )

    @staticmethod
    def _is_quota_error(error: Exception) -> bool:
        message = str(error).lower()
        return 'quota exceeded' in message or 'rate limit' in message or '429' in message or 'retry in' in message

    def _run_query_once(self, query_text: str):
        query_engine = self.get_query_engine()
        if query_engine is None:
            prompt = (
                "Ban la tro ly AI cho website SkinSyntax. "
                "Hãy trả lời bằng tiếng Việt rõ ràng, ngắn gọn, không bịa nguồn truy xuất. "
                "Neu chua co du lieu RAG thi van co the tra loi theo kien thuc chung, "
                "nhung phai noi ro rang do la tu van tong quat. "
                "Khong tu dong goi y san pham neu nguoi dung chi dang hoi kien thuc skincare, thanh phan, cach dung hoac routine. "
                "Khong sao chep mo ta dai dong. Neu can neu vi du, chi nhac rat ngan gon.\n\n"
                f"Cau hoi: {query_text}"
            )
            response = Settings.llm.complete(prompt)
            return {
                "query": query_text,
                "response": str(response),
                "source_nodes": [],
                "mode": "llm_fallback",
            }

        response = query_engine.query(query_text)
        return {
            "query": query_text,
            "response": str(response),
            "source_nodes": [str(node) for node in response.source_nodes] if hasattr(response, 'source_nodes') else []
        }
        
    def _init_vector_store(self):
        """Khởi tạo Chroma Vector Store"""
        # Tạo thư mục nếu chưa có
        os.makedirs(self.vector_db_path, exist_ok=True)
        
        # Initialize Chroma client
        chroma_client = chromadb.PersistentClient(path=self.vector_db_path)
        chroma_collection = chroma_client.get_or_create_collection(
            name=self.collection_name
        )
        
        # Create vector store
        vector_store = ChromaVectorStore(chroma_collection=chroma_collection)
        storage_context = StorageContext.from_defaults(vector_store=vector_store)
        
        # Create index
        self.vector_store = vector_store
        self.storage_context = storage_context
        self.chroma_client = chroma_client
        self.chroma_collection = chroma_collection

        if self.chroma_collection.count() > 0:
            self.index = VectorStoreIndex.from_vector_store(
                vector_store=self.vector_store,
                storage_context=self.storage_context,
            )
            print(f"✅ Reused existing index with {self.chroma_collection.count()} vectors")
        
        print(f"✅ Vector Store initialized: {self.collection_name}")
        
    def add_documents(self, documents: List[Document]):
        """Thêm documents vào index"""
        if not documents:
            print("⚠️ Không có documents để thêm")
            return
        
        # Create index from documents
        self.index = VectorStoreIndex.from_documents(
            documents,
            storage_context=self.storage_context,
            show_progress=True
        )
        print(f"✅ Đã thêm {len(documents)} documents vào index")
        
    def load_documents_from_directory(self, directory: str):
        """Load documents từ thư mục"""
        if not os.path.exists(directory):
            print(f"❌ Thư mục không tồn tại: {directory}")
            return []
        
        reader = SimpleDirectoryReader(directory)
        documents = reader.load_data()
        self.add_documents(documents)
        return documents
    
    def get_query_engine(self):
        """Lấy query engine"""
        if self.index is None and self.chroma_collection.count() > 0:
            self.index = VectorStoreIndex.from_vector_store(
                vector_store=self.vector_store,
                storage_context=self.storage_context,
            )

        if self.index is None:
            print("❌ Index chưa được khởi tạo. Vui lòng load documents trước")
            return None
        
        query_engine = self.index.as_query_engine(
            similarity_top_k=LlamaIndexConfig.TOP_K,
            streaming=False
        )
        return query_engine
    
    def query(self, query_text: str):
        """Thực hiện query"""
        last_error = None

        for index, api_key in enumerate(self.google_api_keys):
            try:
                if api_key != self.active_api_key:
                    self._configure_models(api_key)
                    print(f"[INFO] Switched Gemini key {index + 1}/{len(self.google_api_keys)} for query execution")

                return self._run_query_once(query_text)
            except Exception as error:
                last_error = error
                if self._is_quota_error(error) and index < len(self.google_api_keys) - 1:
                    print(f"[WARN] Gemini key {index + 1}/{len(self.google_api_keys)} hit quota, rotating to next key")
                    continue
                raise

        if last_error is not None:
            raise last_error

        raise RuntimeError('No Gemini API key is available for query execution.')

# Singleton instance
_llama_setup = None

def get_llama_setup() -> LlamaIndexSetup:
    """Lấy LlamaIndexSetup instance"""
    global _llama_setup
    if _llama_setup is None:
        _llama_setup = LlamaIndexSetup()
    return _llama_setup

if __name__ == '__main__':
    # Test
    print("Testing LlamaIndex Setup...")
    setup = get_llama_setup()
    print("✅ LlamaIndex Setup ready!")
