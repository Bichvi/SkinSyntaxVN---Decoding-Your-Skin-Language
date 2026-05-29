from pathlib import Path
import os

# Đây là file cấu hình cho dịch vụ recommendation trong ứng dụng Flask. Nó định nghĩa các biến môi trường và đường dẫn cần thiết để 
# kết nối với cơ sở dữ liệu MongoDB và lưu trữ các chỉ mục và cơ sở dữ liệu Chroma cho hệ thống recommendation. 
# Các biến này có thể được thiết lập thông qua biến môi trường hoặc sẽ sử dụng giá trị mặc định nếu không được thiết lập.
ROOT_DIR = Path(__file__).resolve().parents[2]
# Các biến môi trường cho MongoDB và các đường dẫn lưu trữ
MONGO_URI = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
MONGO_DB_NAME = os.getenv("MONGO_DB_NAME", "skinsyntax")

# Các biến môi trường cho chỉ mục và cơ sở dữ liệu Chroma
RECOMMENDATION_INDEX_DIR = Path(
    os.getenv("RECOMMENDATION_INDEX_DIR", str(ROOT_DIR / "database" / "recommendation_index"))
)
# Đường dẫn lưu trữ cơ sở dữ liệu Chroma cho hệ thống recommendation
RECOMMENDATION_CHROMA_DIR = Path(
    os.getenv("RECOMMENDATION_CHROMA_DIR", str(ROOT_DIR / "database" / "recommendation_chroma_db"))
)
# Tên collection trong MongoDB để lưu trữ dữ liệu sản phẩm cho hệ thống recommendation
RECOMMENDATION_COLLECTION = os.getenv("RECOMMENDATION_COLLECTION", "recommendation_products")
# Tên mô hình embedding được sử dụng cho hệ thống recommendation, có thể được thiết lập thông qua 
# biến môi trường hoặc sẽ sử dụng giá trị mặc định là "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
EMBED_MODEL_NAME = os.getenv(
    "RECOMMENDATION_EMBED_MODEL",
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
)

