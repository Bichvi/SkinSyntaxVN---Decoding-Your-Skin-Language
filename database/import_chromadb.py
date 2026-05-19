import pandas as pd
import chromadb
import uuid
import math
import os
from tqdm import tqdm

# Cấu hình đường dẫn
CSV_FILE_PATH = r"C:\xampp\htdocs\xoa\SkinSyntaxVN---Decoding-Your-Skin-Language\database\data_clean_final.csv"
CHROMA_DB_PATH = r"C:\xampp\htdocs\xoa\SkinSyntaxVN---Decoding-Your-Skin-Language\database\chroma_db"
COLLECTION_NAME = "products"

def main():
    print(f"Đang đọc file CSV: {CSV_FILE_PATH}")
    if not os.path.exists(CSV_FILE_PATH):
        print("Lỗi: Không tìm thấy file CSV!")
        return

    # Đọc dữ liệu từ file CSV
    df = pd.read_csv(CSV_FILE_PATH) 
    
    # Xử lý các giá trị NaN (rỗng)
    df = df.fillna("")
    
    # Kết nối đến ChromaDB
    print("Đang kết nối đến ChromaDB...")
    client = chromadb.PersistentClient(path=CHROMA_DB_PATH)
    
    # Import embedding_functions của chromadb
    from chromadb.utils import embedding_functions
    
    # Tạo hoặc lấy collection với mô hình embedding đa ngôn ngữ giống hệt trong LangChain
    sentence_transformer_ef = embedding_functions.SentenceTransformerEmbeddingFunction(
        model_name="sentence-transformers/static-similarity-mrl-multilingual-v1"
    )
    
    collection = client.get_or_create_collection(
        name=COLLECTION_NAME, 
        embedding_function=sentence_transformer_ef
    )
    
    batch_size = 500 # Import mỗi lần 500 sản phẩm để tối ưu tốc độ và bộ nhớ
    total_rows = len(df)
    
    print(f"Tổng số sản phẩm cần import: {total_rows}")
    
    # Dùng tqdm để hiển thị thanh tiến trình cho "nhanh gọn lẹ" & dễ theo dõi
    for i in tqdm(range(0, total_rows, batch_size), desc="Đang import vào ChromaDB"):
        batch_df = df.iloc[i:i+batch_size]
        
        ids = []
        documents = []
        metadatas = []
        
        for index, row in batch_df.iterrows():
            # 1. Khởi tạo ID duy nhất cho mỗi record
            product_id = str(row.get('ma_san_pham', ''))
            if not product_id.strip():
                product_id = f"product_{index}_{uuid.uuid4().hex[:8]}"
            else:
                product_id = f"product_{product_id}"
            ids.append(product_id)
            
            # 2. Xây dựng nội dung Document (để vector hóa / tìm kiếm ngữ nghĩa)
            # Bao gồm đầy đủ thông tin để chatbot có thể đọc và hiểu rõ ràng
            doc_parts = [
                f"Tên sản phẩm: {row.get('ten_san_pham', '')}",
                f"Loại sản phẩm: {row.get('loai_san_pham', '')}",
                f"Thương hiệu: {row.get('thuong_hieu', '')}",
                f"Xuất xứ thương hiệu: {row.get('xuat_xu_thuong_hieu', '')}",
                f"Nơi sản xuất: {row.get('noi_san_xuat', '')}",
                f"Dung tích: {row.get('dung_tich', '')}",
                f"Loại da phù hợp: {row.get('loai_da', '')}",
                f"Mô tả: {row.get('mo_ta', '')}",
                f"Thành phần chính: {row.get('thanh_phan_chinh', '')}",
                f"Thành phần đầy đủ: {row.get('thanh_phan_day_du', '')}"
            ]
            document = " \n ".join(doc_parts)
            documents.append(document)
            
            # 3. Lọc Metadata (Dữ liệu đi kèm, dùng để filter và trả về frontend hiển thị)
            # Xử lý ảnh (lấy link ảnh đầu tiên nếu có nhiều link)
            raw_images = str(row.get('link_hinh_anh', ''))
            first_image = raw_images.split(" | ")[0] if raw_images else ""

            # Xử lý giá bán an toàn
            try:
                gia_ban = float(row.get('gia_ban', 0))
            except:
                gia_ban = 0.0

            metadata = {
                "ten_san_pham": str(row.get('ten_san_pham', '')),
                "loai_san_pham": str(row.get('loai_san_pham', '')),
                "gia_ban": gia_ban,
                "thuong_hieu": str(row.get('thuong_hieu', '')),
                "xuat_xu_thuong_hieu": str(row.get('xuat_xu_thuong_hieu', '')),
                "noi_san_xuat": str(row.get('noi_san_xuat', '')),
                "dung_tich": str(row.get('dung_tich', '')),
                "loai_da": str(row.get('loai_da', '')),
                "link_hinh_anh": first_image,
                "mo_ta": str(row.get('mo_ta', ''))[:1000], # Giới hạn độ dài metadata tránh quá tải
                "thanh_phan_chinh": str(row.get('thanh_phan_chinh', ''))[:1000]
                # Thành phần đầy đủ dài nên để ở Document (trên) để tìm kiếm, không nhét hết vào metadata để tránh lỗi độ dài.
            }
            metadatas.append(metadata)
            
        # 4. Upsert (Thêm hoặc cập nhật) vào ChromaDB collection
        collection.upsert(
            ids=ids,
            documents=documents,
            metadatas=metadatas
        )

    print("\nImport hoàn tất thành công! Dữ liệu đã được lưu tại:", CHROMA_DB_PATH)

if __name__ == "__main__":
    main()
