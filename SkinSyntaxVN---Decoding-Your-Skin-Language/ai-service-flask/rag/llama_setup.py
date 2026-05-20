"""
LlamaIndex RAG Setup for SkinSyntax
File: ai-service-flask/rag/llama_setup.py
"""
import os
from typing import List
from llama_index.core import VectorStoreIndex, SimpleDirectoryReader, Document
from llama_index.vector_stores.chroma import ChromaVectorStore
from llama_index.core import StorageContext
from llama_index.embeddings.openai import OpenAIEmbedding
from llama_index.core import Settings
import chroma as chromadb

from config import LlamaIndexConfig

class LlamaIndexSetup:
    """Setup LlamaIndex với Chroma Vector Store"""
    
    def __init__(self):
        # Validate config
        LlamaIndexConfig.validate()
        
        # Set API key
        os.environ['OPENAI_API_KEY'] = LlamaIndexConfig.OPENAI_API_KEY
        
        # Configure Settings
        Settings.llm = LlamaIndexConfig.LLAMA_MODEL
        Settings.embed_model = OpenAIEmbedding(model="text-embedding-3-small")
        Settings.chunk_size = LlamaIndexConfig.CHUNK_SIZE
        Settings.chunk_overlap = LlamaIndexConfig.CHUNK_OVERLAP
        
        self.vector_db_path = LlamaIndexConfig.VECTOR_DB_PATH
        self.collection_name = LlamaIndexConfig.CHROMA_COLLECTION
        self.index = None
        
        # Create vector store
        self._init_vector_store()
        
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
        query_engine = self.get_query_engine()
        if query_engine is None:
            return {"error": "Index not initialized"}
        
        response = query_engine.query(query_text)
        return {
            "query": query_text,
            "response": str(response),
            "source_nodes": [str(node) for node in response.source_nodes] if hasattr(response, 'source_nodes') else []
        }

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
