import pandas as pd
import chromadb
import uuid
import os
import sys
from pathlib import Path
from tqdm import tqdm

BASE_DIR = Path(__file__).resolve().parent
ROOT_DIR = BASE_DIR.parent
CSV_FILE_PATH = os.getenv("CSV_FILE_PATH", str(BASE_DIR / "data_clean_final.csv"))
CHROMA_DB_PATH = os.getenv("CHROMA_DB_PATH", str(BASE_DIR / "chroma_db"))
COLLECTION_NAME = "products"

EMBEDDING_MODEL = "sentence-transformers/static-similarity-mrl-multilingual-v1"


def _get_langchain_embeddings():
    """LangChain HuggingFaceEmbeddings — same stack as ai-service retrieval.py."""
    sys.path.insert(0, str(ROOT_DIR / "ai-service-flask"))
    from langchain_huggingface import HuggingFaceEmbeddings

    return HuggingFaceEmbeddings(
        model_name=EMBEDDING_MODEL,
        model_kwargs={"device": "cpu"},
        encode_kwargs={"normalize_embeddings": True},
    )


def main():
    if not os.path.exists(CSV_FILE_PATH):
        print(f"Không tìm thấy CSV: {CSV_FILE_PATH}")
        sys.exit(1)

    df = pd.read_csv(CSV_FILE_PATH)
    df = df.fillna("")

    embeddings = _get_langchain_embeddings()
    client = chromadb.PersistentClient(path=CHROMA_DB_PATH)
    collection = client.get_or_create_collection(name=COLLECTION_NAME)

    batch_size = 500
    total_rows = len(df)

    for i in tqdm(range(0, total_rows, batch_size), desc="Đang import vào ChromaDB"):
        batch_df = df.iloc[i : i + batch_size]

        ids = []
        documents = []
        metadatas = []

        for index, row in batch_df.iterrows():
            product_id = str(row.get("ma_san_pham", ""))
            if not product_id.strip():
                product_id = f"product_{index}_{uuid.uuid4().hex[:8]}"
            else:
                product_id = f"product_{product_id}"
            ids.append(product_id)

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
                f"Thành phần đầy đủ: {row.get('thanh_phan_day_du', '')}",
            ]
            document = " \n ".join(doc_parts)
            documents.append(document)

            raw_images = str(row.get("link_hinh_anh", ""))
            first_image = raw_images.split(" | ")[0] if raw_images else ""

            try:
                gia_ban = float(row.get("gia_ban", 0))
            except Exception:
                gia_ban = 0.0

            ma_sp = str(row.get("ma_san_pham", "")).strip() or product_id.replace("product_", "")
            metadata = {
                "ma_san_pham": ma_sp,
                "id": ma_sp,
                "ten_san_pham": str(row.get("ten_san_pham", "")),
                "loai_san_pham": str(row.get("loai_san_pham", "")),
                "gia_ban": gia_ban,
                "thuong_hieu": str(row.get("thuong_hieu", "")),
                "xuat_xu_thuong_hieu": str(row.get("xuat_xu_thuong_hieu", "")),
                "noi_san_xuat": str(row.get("noi_san_xuat", "")),
                "dung_tich": str(row.get("dung_tich", "")),
                "loai_da": str(row.get("loai_da", "")),
                "link_hinh_anh": first_image,
                "mo_ta": str(row.get("mo_ta", ""))[:1000],
                "thanh_phan_chinh": str(row.get("thanh_phan_chinh", ""))[:1000],
            }
            metadatas.append(metadata)

        vectors = embeddings.embed_documents(documents)
        collection.upsert(
            ids=ids,
            documents=documents,
            metadatas=metadatas,
            embeddings=vectors,
        )

    total = collection.count()
    print(f"ChromaDB import xong: {total:,} documents tại {CHROMA_DB_PATH}")


if __name__ == "__main__":
    main()
