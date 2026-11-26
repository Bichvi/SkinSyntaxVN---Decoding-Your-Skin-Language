# Khôi phục Chatbot RAG trên VPS

Baseline mã nguồn `490e7c7`, nhánh sửa `fix/chatbot-rag-production`. Số liệu VPS do người vận hành cung cấp gồm 2.853 sản phẩm Atlas, 7.320 nút và 92.754 quan hệ Neo4j, 2.853 tài liệu catalog Chroma và 0 tài liệu chatbot. Docker Desktop của workspace tái hiện 0 tài liệu trong `products`, nhưng catalog ở đây có 6.376 tài liệu. Hai môi trường có dữ liệu và cấu hình key khác nhau.

## Nguyên nhân và hợp đồng dữ liệu

| Đường đọc hoặc ghi | Kho và hợp đồng |
| --- | --- |
| `chatbot_service/chatbot_flask.py` và `chatbot_service/retrieval.py` | Hai singleton đọc `products` trên Chroma HTTP nếu có `CHROMA_HOST`, hoặc SQLite cục bộ. Query dùng model dùng chung và chuẩn hóa L2 norm. Các bộ lọc đọc `loai_da`, `loai_san_pham`, `thuong_hieu`, `xuat_xu_thuong_hieu`. Chi tiết tìm theo `product_<id>`. Formatter và bộ lọc thành phần cần metadata tiếng Việt. |
| `database/import_chromadb.py`, `database/import_chromadb_std.py` | Import CSV vào PersistentClient dưới `database/chroma_db`. Cả hai dùng `static-similarity-mrl-multilingual-v1`, kích thước mặc định 1024 theo model, không đặt metric riêng nên mặc định L2 khi tạo. Importer không bật chuẩn hóa vector tường minh. Document tiếng Việt gồm tên, loại, hãng, xuất xứ, nơi sản xuất, dung tích, da, mô tả và thành phần. Metadata lưu nhóm tương ứng trừ thành phần đầy đủ, cộng giá và ảnh. Bản std thêm `ma_san_pham`. CSV thiếu ID sinh UUID, do đó dòng đó không idempotent. |
| `search_service/branches.py`, `search_service/scripts/reindex_chroma.py` | Search dùng `catalog_products_v1` mặc định từ `SEARCH_CHROMA_COLLECTION`. Catalog worker gọi `reindex_ids` theo sự kiện và cập nhật collection này. ID là mã sản phẩm trần. Metadata `product_id`, `ma_san_pham`, `name`, `brand`, `category`, `sold`. Document `product_text` nhận tên, hãng, loại, da và thành phần cắt tối đa 600 ký tự từ projection Search. Hàm còn hỗ trợ concerns và description nếu caller có cung cấp. |
| `recommendation_service/config.py`, `recommendation_service/retrieval.py` | Recommendation độc lập mặc định mở `products` SQLite theo `RECOMMENDATION_CHROMA_DIR` và `RECOMMENDATION_CHROMA_COLLECTION`. `build_or_refresh` ghi `product_<id>` với metadata `product_id`, `category`, `price`. Khi Search inject `CatalogVectorstore`, lớp này đọc catalog HTTP qua adapter và ghép kết quả theo ID. |

Model runtime chung lấy từ `EMBEDDING_MODEL`, mặc định `sentence-transformers/static-similarity-mrl-multilingual-v1` và `normalize_embeddings=True`. Mẫu catalog Docker Desktop đo được 1024 chiều, collection không đặt metadata metric, tương ứng L2 mặc định theo đường tạo trong mã nguồn. `products` trống nên không đo được chiều vector lưu trữ. Cấu hình và vector VPS phải qua dry-run để xác nhận. Cùng model và kích thước chưa đủ để dùng chung trực tiếp vì hợp đồng ID, tài liệu, tên trường, phép lọc và dữ liệu cho hiển thị khác nhau.

Các consumer HTTP trong Gunicorn chung gồm `GET /api/health`, `POST /api/chat`, `/api/chat/auto` và nhóm `POST /api/recommend`, `/api/recommend/profile`, `/api/recommend/llamaindex`, `/api/recommend/langchain-rag` đi qua structured retrieval hoặc gọi lại pipeline chat. Legacy tools và consultation dùng `retrieval.py`. Agent v2 có blueprint và công cụ riêng, đường này cần đánh giá tách khỏi legacy RAG. Recommendation độc lập có các alias trên cùng `/api/recommend/routine`, `/api/recommend/explain` và `/api/recommend/index` qua app `recommendation_service/rcm_flask.py`. `/api/search/recommend` dùng catalog qua nhánh semantic.

Gunicorn warm-up chỉ mở collections và dựng pipeline. Import CSV ghi SQLite, còn reindex hiện hành chỉ ghi catalog. Vì vậy `products` HTTP có thể trống dù catalog đã có dữ liệu. Health hiện báo HTTP 200 và `chromadb:true` khi đếm thành công, kể cả kết quả 0. Dùng count và truy vấn làm tiêu chí sẵn sàng.

## Phương án

- Ghi trực tiếp vào `products` có mô hình hợp đồng phù hợp sau khi chuyển metadata, nhưng ứng dụng có thể đọc dữ liệu nạp dở và rollback sẽ đòi thay đổi dữ liệu đang phục vụ.
- Đổi chatbot sang `catalog_products_v1` phá ID lookup và các trường lọc/hiển thị. Sửa adapter theo hướng đọc metadata Mongo tại runtime là một thay đổi rộng hơn và phụ thuộc thêm vào Atlas trên đường trả lời.
- Chọn đồng bộ theo hợp đồng `products` vào `products_rag_v1`. Giữ nguyên document đi kèm vector, tạo ID và metadata riêng từ Mongo, xác minh toàn bộ ID/document, độ dài/norm vector và đối chiếu ba vector với model đang dùng. Chỉ thêm ID thiếu, dừng nếu có row khác biệt hoặc count sai. Hai bản hiện có tiếp tục giữ nguyên. Giữ dữ liệu như snapshot và luân phiên tên `products_rag_v2` cho đợt làm mới sau khi catalog đổi. Catalog worker hiện chỉ cập nhật Search, do đó vận hành cần theo dõi độ mới trước khi coi đây là đồng bộ liên tục.

File runtime sửa `chatbot_flask.py` và `retrieval.py`. Công cụ đồng bộ `chatbot_service/sync_rag_catalog.py` và kiểm thử `tests/test_rag_catalog_recovery.py` là file mới. GitNexus đánh dấu factory chatbot CRITICAL, đường gọi trực tiếp qua `get_vectorstore` rồi đến health, pipeline và chat. Thay đổi được giới hạn ở selector HTTP và chế độ chỉ mở collection đã có.

## API key và cấu hình container

`docker-compose.yml` truyền `.env` ở root qua `ai-service.env_file`. Khối `environment` không ghi đè các biến key được yêu cầu và production override chỉ đóng cổng của AI Service. `--env-file` của Compose dùng cho nội suy và tự nó không thay chỉ định `env_file: .env` của service. `docker restart` và `docker compose restart` dùng lại môi trường của container cũ.

Gunicorn preloads app rồi fork workers. App tìm `/app/.env` từ bind mount `ai-service-flask`, khác file root của Compose. Trước sửa, dotenv `override=True` có thể ghi đè biến container, kể cả từ giá trị rỗng. Bản sửa dùng `override=False` để môi trường được inject có ưu tiên. `GEMINI_KEYS` được tính khi import: key chính, danh sách phân cách dấu phẩy, rồi KEY_2 đến KEY_10, có dedup. `llm_pool.py` và Agent v2 lấy key chính hoặc phần tử đầu của danh sách, không loop KEY_2–10. Groq/OpenAI đọc trực tiếp các biến tương ứng. Runtime legacy thực tế xếp Groq, Gemini, Zhipu, OpenRouter, OpenAI theo các provider đã cấu hình, khác nhãn Gemini “primary” của health. Thứ tự được giữ.

Docker Desktop báo `gemini_keys=4`, `groq=true`, `openai=true`, `/app/.env` vắng mặt. Với VPS, dấu hiệu thiếu key có thể do sai file/project, container cũ hoặc dotenv che giá trị. Chưa có bằng chứng chọn một trong ba nguyên nhân VPS. Lệnh preflight dưới đây cho phép phân biệt. Nếu key có mặt nhưng gọi lỗi, phân loại 401/403 là xác thực hoặc quyền, 404 là model/endpoint cần kiểm tra, 429 hoặc resource exhausted là rate/quota, DNS/TLS/timeout là lỗi kết nối. Chỉ ghi mã lỗi và loại exception khi thu thập bằng chứng.

## Triển khai trên VPS

### 0. Chốt artefact và quyền triển khai

Commit release cục bộ `39a548a28f5c015f056b5b4e03281d16bdd7fa39` trên `fix/chatbot-rag-production`, với parent `490e7c7ebfdf78784c76c6b2365e7cd965ee31c7`. Commit chứa đúng bốn file dưới đây. Trạng thái release là chờ xác nhận triển khai. Checklist lưu ngoài commit theo yêu cầu giới hạn bốn file.

| File trong gói chuyển lên VPS | Trạng thái Git | Vai trò |
| --- | --- | --- |
| `ai-service-flask/chatbot_service/chatbot_flask.py` | Modified | Chọn collection HTTP, ưu tiên env container |
| `ai-service-flask/chatbot_service/retrieval.py` | Modified | Chọn cùng collection cho retrieval legacy |
| `ai-service-flask/chatbot_service/sync_rag_catalog.py` | Added | Dry-run và nạp snapshot |
| `ai-service-flask/tests/test_rag_catalog_recovery.py` | Added | Kiểm thử fixture cô lập |

SHA-256 tính từ blob Git của commit release. Dùng các hash này cho gói xuất từ commit; hai file runtime có byte CRLF khác ở checkout Windows. Sau khi được phép chuyển gói, dùng `sha256sum` trên VPS để đối chiếu bốn đường dẫn sau khi giải gói.

```text
83915b0b8e8b8d354b0d5cdfa68e78dc331e0c664bd7a1519acacc5dec1b9eb4  ai-service-flask/chatbot_service/chatbot_flask.py
05a03dacfb7cbb7abd85611aedcf71d27c45ae7bd4bf7a777544bf10801d207b  ai-service-flask/chatbot_service/retrieval.py
080f8e5b0ec211a4c5d9f9bdc7b59d9cf4cbb91e5e89fb93d7d4843bb6c6ad7b  ai-service-flask/chatbot_service/sync_rag_catalog.py
42190fb9bba1f35a7dfbd825131a0e1d6089946c9337734cfca9cc1fd64c4f30  ai-service-flask/tests/test_rag_catalog_recovery.py
```

Hai file backend đang sửa `backend/app/content/simple_recommender_cache.json` và `backend/app/data/search_dictionary.json` cùng các artefact untracked nằm ngoài gói. Compose, production override, Dockerfile và requirements có diff rỗng so với baseline. Không chạy `scripts/deploy.sh` cho lần này vì script có bước cập nhật repo và chạy Compose toàn stack.

Xuất gói từ commit cục bộ khi cần. Lệnh này chỉ tạo tar cục bộ.

```bash
RAG_RELEASE=39a548a28f5c015f056b5b4e03281d16bdd7fa39
git diff-tree --no-commit-id --name-status -r "$RAG_RELEASE" || exit 1
git -c core.autocrlf=false archive --format=tar --output=skinsyntax-rag-39a548a.tar "$RAG_RELEASE" -- \
  ai-service-flask/chatbot_service/chatbot_flask.py \
  ai-service-flask/chatbot_service/retrieval.py \
  ai-service-flask/chatbot_service/sync_rag_catalog.py \
  ai-service-flask/tests/test_rag_catalog_recovery.py
```

**PASS:** có xác nhận triển khai, đối chiếu baseline trên VPS, có bản sao hai file runtime đang phục vụ và selector cũ, gói khớp đủ bốn đường dẫn và checksum. **FAIL:** thiếu xác nhận, chưa xác định source bytes, thu được diff ngoài phạm vi hoặc VPS có thay đổi xung đột.

### 1. Preflight chỉ đọc và chốt EXPECTED

Chạy từ đúng thư mục dự án và dùng đúng Docker context, Compose project cùng bộ file đang phục vụ. Hàm bên dưới dùng project mặc định của checkout. Nếu label VPS xác nhận project khác, bổ sung `-p` đã xác minh cho mọi lệnh, kể cả lệnh có `flock`. Duy trì một người vận hành và tạm ngừng sửa catalog trong cửa sổ snapshot. Sau xác nhận, sao lưu hai file runtime trên VPS trước khi chuyển bốn file. Chỉ đưa các file vào đúng bind mount đã đối chiếu.

```bash
dc() { docker compose -f docker-compose.yml -f docker-compose.prod.yml "$@"; }
git rev-parse HEAD
git status --short --branch
docker compose version
docker context show
dc config --quiet
dc ps ai-service chromadb
docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}} {{index .Config.Labels "com.docker.compose.project.working_dir"}} {{index .Config.Labels "com.docker.compose.project.config_files"}}' "$(dc ps -q ai-service)"
docker inspect -f '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Source}} -> {{.Destination}}{{end}}{{end}}' "$(dc ps -q ai-service)"
dc exec -T ai-service python -c 'import chromadb, inspect; from langchain_chroma import Chroma; print("chroma_client", chromadb.__version__); assert "create_collection_if_not_exists" in inspect.signature(Chroma).parameters'
dc exec -T chromadb python -c 'import chromadb; c=chromadb.HttpClient(host="127.0.0.1",port=8000); print("chroma_client",chromadb.__version__,"server",c.get_version()); assert chromadb.__version__ == c.get_version() == "0.5.20"'
dc exec -T ai-service python - <<'PY'
import os
from pathlib import Path
keys = ["GOOGLE_API_KEY", "GOOGLE_API_KEYS",
        *[f"GOOGLE_API_KEY_{i}" for i in range(2, 11)], "GROQ_API_KEY", "OPENAI_API_KEY"]
print({key: bool(os.getenv(key, "").strip()) for key in keys})
print({"app_dotenv_exists": Path("/app/.env").exists()})
PY
dc exec -T ai-service curl -fsS --max-time 15 http://127.0.0.1:5001/api/health
dc exec -T ai-service python - <<'PY'
import os, sys
import chromadb
from search_service.catalog import get_mongo_db, load_catalog
try:
    db = get_mongo_db()
    if db is None:
        raise RuntimeError("mongo_unavailable")
    total = db["san_pham"].count_documents({})
    products = load_catalog(db)
    c = chromadb.HttpClient(host=os.environ["CHROMA_HOST"], port=int(os.environ["CHROMA_PORT"]))
    source = c.get_collection("catalog_products_v1")
    ids = set(source.get(include=[])["ids"])
    matches = ids == {p["id"] for p in products}
    count = source.count()
    print(dict(mongo_total=total, visible=len(products), catalog=count, id_sets_equal=matches,
               products=c.get_collection("products").count()))
    if not (len(products) == len(ids) == count > 0 and matches):
        sys.exit(1)
except Exception as exc:
    print(type(exc).__name__)
    sys.exit(1)
PY
```

**PASS:** đúng checkout và bind mount, Compose validate thành công, Chroma server/client đúng phiên bản, Atlas visible catalog và Chroma có cùng count dương và tập ID. Ghi lại số tổng Atlas riêng vì có thể gồm sản phẩm ẩn. Chỉ nhập `2853` làm `EXPECTED` nếu phép đếm vừa chạy xác nhận visible và Chroma đều là 2853. Nếu cả hai cùng ra số khác, đối chiếu với chủ dữ liệu rồi dùng số vừa xác nhận. **FAIL:** count hay ID lệch, phiên bản không khớp, model cache thiếu hoặc không xác định được môi trường. Dừng trước khi ghi.

Thu baseline Search/Recommendation bằng block HTTP ở mục 4 với `-e RAG_SMOKE_BASELINE=1` thêm vào lệnh `dc exec`. Ghi trạng thái degraded, số sections/products và xác nhận semantic Search theo response hoặc quan sát worker. Nếu một endpoint đã lỗi, xác định nguyên nhân trước khi dùng kết quả đó để đánh giá hồi quy.

### 2. Dry-run, tạo target và ghi

Chroma 0.5.20 dùng client cùng phiên bản để tạo target. Client 1.5.9 của AI đọc và ghi được collection có sẵn, còn tạo mới trên server này đã tái hiện lỗi `KeyError('_type')`. Chạy phần dưới trong Bash và chỉ tiếp tục khi từng lệnh thành công.

Sau xác nhận, chạy khối sao lưu sau trên VPS trước khi cài bốn file đã đối chiếu checksum. Lưu lại đường dẫn để dùng lúc rollback.

```bash
RAG_ROLLBACK_DIR=$(mktemp -d /tmp/skinsyntax-rag-rollback.XXXXXXXX) || exit 1
cp -p -- ai-service-flask/chatbot_service/chatbot_flask.py ai-service-flask/chatbot_service/retrieval.py "$RAG_ROLLBACK_DIR/" || exit 1
printf '%s\n' "$RAG_ROLLBACK_DIR"
```

```bash
read -r -p 'EXPECTED vừa xác minh trên VPS: ' EXPECTED
TARGET=products_rag_v1
[[ "$EXPECTED" =~ ^[1-9][0-9]*$ ]] || exit 1
dc exec -T -e HF_HUB_OFFLINE=1 ai-service python -m chatbot_service.sync_rag_catalog --target "$TARGET" --expected "$EXPECTED" || exit 1
dc exec -T chromadb python -c 'import chromadb; c=chromadb.HttpClient(host="127.0.0.1",port=8000); n="products_rag_v1"; col=c.get_collection(n) if n in {item.name for item in c.list_collections()} else c.create_collection(n,metadata={"hnsw:space":"l2"}); print("staging_count",col.count())' || exit 1
flock -n .rag-catalog-sync.lock docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T -e HF_HUB_OFFLINE=1 ai-service python -m chatbot_service.sync_rag_catalog --target "$TARGET" --expected "$EXPECTED" --apply || exit 1
dc exec -T -e HF_HUB_OFFLINE=1 ai-service python -m chatbot_service.sync_rag_catalog --target "$TARGET" --expected "$EXPECTED" || exit 1
```

**PASS:** dry-run cuối báo `source=EXPECTED`, `verified_existing=EXPECTED` và `missing=0`. Chạy phần tra cứu Chroma ở mục 4 trước khi chuyển service, dùng `-e CHATBOT_CHROMA_COLLECTION="$TARGET"` cho riêng tiến trình kiểm tra. **FAIL:** không lấy được lock, bất kỳ lệnh nào khác 0, row xung đột, count sai hoặc retrieval thất bại. Dừng trước khi chuyển selector. Nếu lần ghi gián đoạn, dry-run lại. Tool chỉ thêm phần thiếu khi các row đã ghi khớp hoàn toàn. Có xung đột thì giữ snapshot lại để điều tra.

### 3. Chuyển riêng AI Service

Chỉ sau khi mục 2 đạt, đặt biến cấu hình `CHATBOT_CHROMA_COLLECTION=products_rag_v1` trong file root `.env` theo quy trình vận hành. Giữ nguyên secrets. Ghi nhận selector trước đó có giá trị gì hoặc vốn không tồn tại. Dùng lại image hiện hành và recreate riêng AI Service.

```bash
dc config --quiet
dc up -d --no-deps --no-build --force-recreate --wait --wait-timeout 180 ai-service
dc ps ai-service
dc exec -T ai-service curl -fsS --max-time 20 http://127.0.0.1:5001/api/health
```

**PASS:** container mới healthy, selector nhận đúng giá trị, `chromadb=true` và `documents=EXPECTED`. Xác nhận count qua lệnh health và lệnh Chroma trực tiếp. Chạy lại probe trạng thái key trong container, đối chiếu với health của worker. **FAIL:** sai selector, count hoặc container không khởi động thì rollback. Nếu key thiếu, dừng xác nhận tính năng tạo câu trả lời và báo người quản lý cấu hình.

### 4. Smoke trước và sau chuyển đổi

```bash
dc exec -T -e HF_HUB_OFFLINE=1 -e RAG_EXPECTED="$EXPECTED" ai-service python - <<'PY'
import os, chromadb
from langchain_chroma import Chroma
from shared.embedding_provider import get_embedding_function
c = chromadb.HttpClient(host=os.environ["CHROMA_HOST"], port=int(os.environ["CHROMA_PORT"]))
name = os.environ["CHATBOT_CHROMA_COLLECTION"]
counts = {n: c.get_collection(n).count() for n in (name, "products", "catalog_products_v1")}
print(counts)
assert counts[name] == counts["catalog_products_v1"] == int(os.environ["RAG_EXPECTED"])
store = Chroma(client=c, collection_name=name, embedding_function=get_embedding_function(),
               create_collection_if_not_exists=False)
docs = store.similarity_search("serum niacinamide cho da dầu", k=5)
assert docs and all(d.metadata.get("ten_san_pham") for d in docs)
matches = [d for d in docs if "niacinamide" in (d.metadata.get("thanh_phan_chinh", "")
                                               + " " + d.metadata.get("thanh_phan_day_du", "")).casefold()]
assert matches, "no relevant ingredient in semantic top 5"
hit = matches[0]
direct = store.get(ids=[hit.id], include=["metadatas"])
assert direct["ids"] == [hit.id] and hit.id == "product_" + str(hit.metadata["ma_san_pham"])
assert direct["metadatas"][0] == hit.metadata
print({"semantic_hits":len(docs),"product_id":hit.id,"name":hit.metadata["ten_san_pham"],
       "price":hit.metadata["gia_ban"],"ingredient_lookup":True})
PY
```

**PASS retrieval:** top 5 có sản phẩm phù hợp và thành phần xác nhận được từ metadata thật. Exact ID, tên và giá phải khớp Atlas hoặc trang sản phẩm hiện tại. Đối chiếu count của `products` với preflight. Nếu query mẫu không khớp catalog, chọn một sản phẩm có thật với thành phần đã xác nhận và chạy lại query tương ứng. Khi thu baseline trước chuyển đổi, block HTTP chỉ gọi Search và Recommendation.

```bash
dc exec -T ai-service python - <<'PY'
import json, os, urllib.request
root = "http://127.0.0.1:5001"
cases = [
    ("/api/chat/auto", {"message":"Gợi ý serum Niacinamide cho da dầu"}, {}),
    ("/api/search/recommend", {"q":"serum niacinamide"}, {"X-Internal-Token":os.getenv("SEARCH_INTERNAL_TOKEN","")}),
    ("/api/recommend", {"user_profile":{"skin_type":"da dầu"}}, {}),
]
for route, payload, headers in cases:
    if os.getenv("RAG_SMOKE_BASELINE") == "1" and route == "/api/chat/auto":
        continue
    req = urllib.request.Request(root+route, json.dumps(payload).encode(),
                                 {"Content-Type":"application/json", **headers})
    with urllib.request.urlopen(req, timeout=120) as response:
        data = json.load(response)
        print(route, response.status, {k:data.get(k) for k in ("ok","degraded","fallback","mode") if k in data},
              "answer_present", bool(data.get("answer")), "products",len(data.get("products") or []),
              "sections", len(data.get("sections") or []))
        assert data.get("ok") is not False and data.get("degraded") is not True
        if route == "/api/chat/auto":
            assert data.get("answer") and data.get("products") and data.get("fallback") is False
        if route == "/api/search/recommend":
            assert data.get("sections")
        if route == "/api/recommend":
            assert data.get("ok") is True and data.get("products")
PY
```

**PASS HTTP:** chatbot trả lời có liên quan, `fallback=false`, có sản phẩm thật, Search trả sections và Recommendation trả products. Kiểm tra Search không mất nhánh semantic so với baseline và đối chiếu câu trả lời mỹ phẩm qua UI. **FAIL:** 200 nhưng payload rỗng, trả lời mặc định, thông tin sản phẩm sai hoặc Search/Recommendation suy giảm so với preflight.

**PASS provider thật:** gắn một yêu cầu smoke với đúng model có dấu hiệu sinh câu trả lời thành công `[GENERATE] OK` trên worker và nội dung trả lời đối chiếu được. Ghi model, thời điểm và kết quả, giữ giá trị key và nội dung lỗi thô ngoài bằng chứng. **FAIL hoặc chưa xác nhận:** biến thiếu là lỗi cấu hình. Key có mặt kèm 401/403 là xác thực hoặc quyền. 429 hoặc resource exhausted là rate/quota. 404, hoặc lỗi 400 nêu rõ model, cần đối chiếu model/endpoint. DNS, TLS và timeout là lỗi kết nối. Fallback thật chỉ PASS khi quan sát được provider đầu lỗi và provider kế tiếp phát sinh câu trả lời thành công trong cùng lượt. Kiểm thử fixture là evidence riêng. Health có key hoặc HTTP 200 không xác nhận provider thật.

## Rollback

Nếu lỗi trước khi chuyển service, dừng quy trình và giữ snapshot riêng. Nếu lỗi sau chuyển, khôi phục selector đã ghi nhận, hoặc gỡ dòng nếu trước đó chưa tồn tại. Rollback đầy đủ khôi phục cả hai file runtime từ bản sao đã chụp trước khi chuyển gói, gồm hành vi dotenv cũ. Giữ collection đã stage và các file công cụ để điều tra. Sau rollback, so sánh health, document count và kết quả Search/Recommendation với baseline trước release. Nếu baseline có 0 documents thì đó là trạng thái đã khôi phục.

```bash
test -n "${RAG_ROLLBACK_DIR:-}" && test -f "$RAG_ROLLBACK_DIR/chatbot_flask.py" && test -f "$RAG_ROLLBACK_DIR/retrieval.py" || exit 1
cp -p -- "$RAG_ROLLBACK_DIR/chatbot_flask.py" "$RAG_ROLLBACK_DIR/retrieval.py" ai-service-flask/chatbot_service/ || exit 1
dc config --quiet
dc up -d --no-deps --no-build --force-recreate --wait --wait-timeout 180 ai-service
dc exec -T ai-service curl -fsS --max-time 20 http://127.0.0.1:5001/api/health
```

## Bằng chứng kiểm thử

- Release finalization chạy sáu file test bằng pytest. `test_rag_catalog_recovery.py`, `test_recommendation_service.py`, `test_llm_provider_order.py`, `test_chat_regressions.py`, `test_chatbot_init_locks.py` và `test_chatbot_llm_warmup.py` cho kết quả 55 test đạt và 44 subtest đạt. Tích hợp sử dụng model embedding thật đã cache và Chroma loopback từ package 1.5.9. Endpoint báo server version 1.0.0. Kết nối Python ngoài loopback bị chặn trong lượt chạy test. Docker Desktop không chạy và bản Chroma 0.5.20 cài trên Windows cần compiler cho hnswlib, nên kết quả 0.5.20 bên dưới là bằng chứng từ lượt trước.
- Diễn tập rollback cục bộ khôi phục byte của cả hai file runtime từ baseline và khôi phục selector giả lập trong hai trường hợp trước đó có hoặc không có biến. GitNexus kiểm tra đúng bốn file staged và báo rủi ro CRITICAL quanh chatbot dùng chung. `git diff --cached --check` đạt.
- Docker Desktop trước sửa: HTTP 200, `documents=0`. Chroma HTTP `products=0`, `catalog_products_v1=6376`, mẫu vector 1024 chiều.
- Chroma cô lập 0.5.20: tạo collection từ client 1.5.9 lỗi `_type`. Client 0.5.20 tạo thành công.
- Docker cô lập: `python -m unittest discover -s tests -p test_rag_catalog_recovery.py -v` chạy 2 test, cả hai pass. Bộ fixture sử dụng Chroma thật và model cached. Kiểm tra dry-run, count mismatch, idempotence, từ chối ghi đè, semantic theo bộ lọc, exact ID, ingredient, health HTTP, rollback, và fallback do 429 hoặc lỗi kết nối. Câu trả lời của route dùng fixture. Search vẫn mở `catalog_products_v1` và Recommendation adapter trả đúng ID từ collection này. Docker cô lập phát cảnh báo đóng socket lúc kết thúc và Redis listener thiếu kết nối.
- Recommendation: chạy trực tiếp 3 hàm test hiện có trong `tests/test_recommendation_service.py` bằng `runpy.run_path` trong image AI, mạng bị tắt. Cả ba pass.
- Các lượt kiểm thử trước khi được duyệt triển khai chỉ chạy cục bộ. Kết quả production được ghi riêng bên dưới.

## Bản ghi triển khai production ngày 25/11/2026

Cutover thành công trên VPS `3.25.6.79` cho commit cục bộ `39a548a28f5c015f056b5b4e03281d16bdd7fa39`. VPS giữ Git HEAD `490e7c7ebfdf78784c76c6b2365e7cd965ee31c7` trên `main`. Bốn file trong manifest ở mục 0 đã được chuyển thủ công vào bind mount `ai-service-flask:/app` và cả bốn SHA-256 hậu triển khai đều khớp. Hai file runtime có diff với HEAD, hai file mới là untracked trên VPS. Tích hợp commit vào nguồn Git dùng cho CI/CD trước lần release tiếp theo và đối chiếu manifest để tránh mất các thay đổi này khi đồng bộ checkout.

- Compose project `skinsyntax` tạo lại riêng `ai-service` bằng cùng image hiện hành. `CHATBOT_CHROMA_COLLECTION=products_rag_v1` được thêm vào `.env`. Trước triển khai selector chưa tồn tại. Không thay đổi key hoặc cấu hình AWS.
- Atlas visible và catalog Chroma trùng toàn bộ 2.853 ID. Chroma 0.5.20 tạo target. Dry-run sau ghi xác minh `verified_existing=2853` và `missing=0`. `products` vẫn 0, `catalog_products_v1` vẫn 2.853. Neo4j giữ 7.320 nút và 92.754 quan hệ. Redis preflight trả `PONG`.
- Health AI Service báo 2.853 documents, 0 restart và OOM false. Semantic RAG trả 5 kết quả. Tra cứu mã `3879` khớp tên, giá 229.000 VND, Niacinamide và loại da với Atlas. Lọc loại da trả 10 kết quả cùng loại da. Chat API nội bộ trả 6 sản phẩm có Niacinamide khớp chỉ mục. Recommendation trả 6 sản phẩm. Search báo không degraded và trả 8 mục semantic trong 3 nhóm.
- Key Gemini, Groq và OpenAI đều thiếu ở root `.env`, container env và `/app/.env`. Key Zhipu, OpenRouter và Tavily cũng thiếu theo probe. Chat smoke chứng minh đường lookup sản phẩm hoạt động. Chưa gọi provider thật, chưa xác minh quota hay khả năng tạo câu trả lời bằng LLM.
- Sau cutover VPS có 2 vCPU, RAM khả dụng 3.840 MiB trên 7.776 MiB, không swap, ổ gốc còn 14 GiB trên 29 GiB. Một mẫu `docker stats` ghi nhận AI Service 0,78% CPU và 2,112 GiB RAM, Chroma khoảng 195,4 MiB RAM.
- URL public `https://skinsyntaxvn.app/` chưa qua smoke. DNS A trên VPS trả `91.195.240.94`, khác IP VPS được duyệt. HTTPS báo TLS unrecognized name từ VPS và kết nối thất bại từ workspace. VPS không có listener 80/443 ở lần kiểm tra cuối. Reverse proxy public không có trong danh sách container đang chạy cả trước lẫn sau cutover. Không sửa DNS hay ingress trong release RAG.
- Bằng chứng lưu tại `/home/ubuntu/.skinsyntax-rag-release-39a548a/` trên VPS. `manual-deployment.sha256` ghi danh sách bốn file, `manual-deployment.txt` ghi commit và selector, `readback.json`, `retrieval.json` và `smoke.log` ghi kết quả. Thư mục rollback cùng vị trí có quyền 0700. Để rollback release này, khôi phục hai file runtime từ thư mục đó, gỡ đúng dòng `CHATBOT_CHROMA_COLLECTION=products_rag_v1` và tạo lại riêng AI Service theo mục Rollback.

## Bàn giao lần triển khai website HTTPS

- Website production chạy tại `https://skinsyntaxvn.app/` với PHP, Nginx và Caddy. HTTP chuyển hướng 308 sang HTTPS. HTTPS trên `www` chuyển hướng 301 sang tên miền chính. Hai tên miền xác minh chứng chỉ thành công. Caddy là ingress public, còn Flask và các database nằm trong Docker network hoặc chỉ bind loopback.
- PHP dùng image build từ baseline `490e7c7`. Vendor trên VPS được chép từ chính image và dùng dependency theo `composer.lock`. Override runtime tại `/home/ubuntu/.skinsyntax-web-release-490e7c7/` gồm `web.override.yml`, `php-fpm.web.conf` và `php-session.web.ini`. PHP-FPM chạy `ondemand`, giới hạn 5 workers. `PHPSESSID` có `Secure`, `HttpOnly` và `SameSite=Lax`. Session volume hiện có được giữ khi tạo lại PHP.
- Manifest web `deployment-manifest.txt` và bản sao cấu hình nằm cùng thư mục. Lần vận hành tiếp theo cần nạp cả ba file Compose theo thứ tự `docker-compose.yml`, `docker-compose.prod.yml`, `/home/ubuntu/.skinsyntax-web-release-490e7c7/web.override.yml` để giữ cấu hình FPM và cookie. Manifest RAG riêng vẫn khớp cả bốn file. AI Service vẫn báo 2.853 tài liệu sau khi website lên.
- Browser smoke HTTPS mở trang chủ, catalog/nhãn hiệu, chi tiết sản phẩm, routine, trang live và cả hai modal xác thực. Tìm `niacinamide` qua form trả 24 liên kết sản phẩm. Một ảnh ngoài từ `media.hcdn.vn` lỗi 404. Smoke giỏ khách thêm một sản phẩm vào phiên thử và đọc lại trang giỏ thành công. Không tạo tài khoản hoặc đơn hàng. Admin, Staff và checkout chuyển khách chưa đăng nhập về trang chủ. Chatbot BFF trả 6 sản phẩm. Endpoint Agent SSE trả 404 khi chưa bật hoặc thiếu cấu hình, nên chưa xác minh streaming. LLM thật, giao dịch, dashboard xác thực và phát live chưa qua kiểm thử.
- Các key Gemini/Groq cục bộ chưa được xác minh tier của project. Không chuyển key và không gọi API tính phí. Catalog worker chưa chạy vì lịch có full sync và reindex tự động. Search worker (ghi telemetry) và các service AI Idol, media, voice chưa được bật cho bản website cốt lõi.
- CI/CD hiện tự deploy khi main được push hoặc chạy tay. Workflow gọi `~/skinsyntax/scripts/deploy.sh`, khác checkout đã xác minh. Script tự fetch/merge, build nhiều image, khởi động toàn stack, dọn orphan và prune image. Trước khi kích hoạt workflow, cần đưa commit RAG vào nguồn deploy, chuẩn hóa đường dẫn VPS và đưa cấu hình PHP production vào nguồn phát hành. Giới hạn lệnh up theo service đã duyệt, thêm bước xác minh manifest và kiểm thử HTTPS/RAG. Workflow và script hiện tại chưa được chạy trong lần triển khai này.
