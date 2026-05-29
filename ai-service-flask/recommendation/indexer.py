#Mã này xây dựng một chỉ mục LlamaIndex độc lập cho sản phẩm, được sử dụng riêng cho mục đích gợi ý. 
# Nó lấy dữ liệu sản phẩm từ cơ sở dữ liệu MongoDB, chuẩn hóa và chuyển đổi chúng thành các tài liệu LlamaIndex, 
# sau đó lưu trữ chỉ mục và siêu dữ liệu sản phẩm vào thư mục được chỉ định. Cuối cùng, nó trả về một đối tượng JSON 
# chứa thông tin về quá trình xây dựng chỉ mục.
from __future__ import annotations

import json
# Các thư viện được sử dụng trong mã này bao gồm pathlib để làm việc với đường dẫn, os để truy cập biến môi trường,
from .config import RECOMMENDATION_INDEX_DIR
# Các hàm và lớp được nhập từ module mongo_source để xây dựng văn bản sản phẩm, kết nối với cơ sở dữ liệu, chuẩn hóa sản phẩm, 
# tạo siêu dữ liệu sản phẩm và lọc sản phẩm hiển thị.
from .mongo_source import build_product_text, get_database, normalize_product, product_metadata, visible_filter

# Hàm này được sử dụng để nhập các lớp và hàm cần thiết từ thư viện LlamaIndex, 
# đồng thời cấu hình mô hình embedding và LLM (Language Model) cho hệ thống recommendation.
def _llama_imports():
    from llama_index.core import Document, Settings, VectorStoreIndex
    from llama_index.embeddings.huggingface import HuggingFaceEmbedding

    from .config import EMBED_MODEL_NAME

    Settings.embed_model = HuggingFaceEmbedding(
    model_name=EMBED_MODEL_NAME,
    max_length=512
)
    Settings.llm = None
    return Document, VectorStoreIndex

# Hàm này xây dựng chỉ mục LlamaIndex độc lập cho sản phẩm, được sử dụng riêng cho mục đích gợi ý. 
# Nó lấy dữ liệu sản phẩm từ cơ sở dữ liệu MongoDB, chuẩn hóa và chuyển đổi chúng
def build_recommendation_index(limit: int | None = None) -> dict:
    """Build the standalone LlamaIndex product index used only for recommendations."""
    Document, VectorStoreIndex = _llama_imports()
    # Kết nối với cơ sở dữ liệu MongoDB và truy vấn các sản phẩm hiển thị, sắp xếp theo mã sản phẩm giảm dần.
    db = get_database()
    cursor = db.san_pham.find(visible_filter(), sort=[("ma_san_pham", -1)])
    if limit:
        cursor = cursor.limit(int(limit))

    docs = []
    products_meta = {}
    for row in cursor:
        # Chuẩn hóa dữ liệu sản phẩm và kiểm tra xem có đủ thông tin cần thiết để tạo tài liệu hay không. 
        # Nếu có, tạo một đối tượng Document với văn bản sản phẩm, ID tài liệu và siêu dữ liệu sản phẩm, 
        # sau đó thêm vào danh sách tài liệu và lưu siêu dữ liệu sản phẩm vào một dictionary.
        product = normalize_product(row, db)
        if not product.get("id") or not product.get("ten_san_pham"):
            continue
        docs.append(
            # Tạo một đối tượng Document với văn bản sản phẩm được xây dựng từ hàm build_product_text, ID tài liệu được định dạng là "product_{id}",
            Document(
                text=build_product_text(product),
                doc_id=f"product_{product['id']}",
                metadata=product_metadata(product),
            )
        )
        products_meta[product["id"]] = product
    # Tạo thư mục để lưu trữ chỉ mục nếu chưa tồn tại, xây dựng chỉ mục từ danh sách tài liệu, 
    # lưu trữ chỉ mục vào thư mục đã chỉ định,
    RECOMMENDATION_INDEX_DIR.mkdir(parents=True, exist_ok=True)
    index = VectorStoreIndex.from_documents(docs, show_progress=True)
    # Lưu index
    index.storage_context.persist(persist_dir=str(RECOMMENDATION_INDEX_DIR))

    meta_path = RECOMMENDATION_INDEX_DIR / "products_meta.json"
    meta_path.write_text(json.dumps(products_meta, ensure_ascii=False), encoding="utf-8")

    return {"ok": True, "count": len(docs), "index_dir": str(RECOMMENDATION_INDEX_DIR)}

# Cuối cùng, đoạn mã này cho phép chạy trực tiếp từ dòng lệnh để xây dựng chỉ mục recommendation và in kết quả dưới dạng JSON.
if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Build SkinSyntaxVN recommendation index.")
    parser.add_argument("--limit", type=int, default=None, help="Optional small limit for smoke tests.")
    args = parser.parse_args()

    print(json.dumps(build_recommendation_index(limit=args.limit), ensure_ascii=False, indent=2))
