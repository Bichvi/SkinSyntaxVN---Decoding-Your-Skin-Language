# Báo cáo sẵn sàng triển khai SkinSyntaxVN

> Ngày kiểm tra: 2026-11-19 (Asia/Bangkok)
>
> Phạm vi: toàn bộ working tree của repository `SkinSyntaxVN---Decoding-Your-Skin-Language-1`; không đọc giá trị từ `.env`, không kết nối database và không triển khai.
>
> Git: branch `fix/stability-and-bug-fixes`, commit `b2629936e4d007c0b979a90b51a5a2df96d2cbcb`; branch hiện tại lệch `origin/main` 230 commit phía trước và 1 commit phía sau. Working tree đang có nhiều thay đổi của người dùng, vì vậy báo cáo phản ánh **working tree hiện tại**, không chỉ commit HEAD.

## 1. Kết luận nhanh

- Dự án có thể triển khai lên VPS Linux x86_64 bằng Docker Compose. Đường triển khai hiện có là `Caddy -> Nginx -> PHP-FPM`, cùng các service Python, worker và data service trong Compose.
- Cấu hình production hiện tại không phải stack nhỏ: khi ghép `docker-compose.yml` và `docker-compose.prod.yml`, lệnh `up -d` mặc định tạo khoảng **17 container**. Các profile `tunnel`, `ngrok`, `voice` chỉ bổ sung tunnel/LiveKit agent; `voice-service` vẫn chạy mặc định trong file production gốc.
- Mức máy chủ hợp lý cho toàn bộ stack CPU là **4 vCPU, 8 GB RAM tối thiểu; 4-8 vCPU, 16 GB RAM khuyến nghị; SSD tối thiểu 80 GB, nên 120-150 GB; swap 4 GB nếu chỉ có 8 GB RAM**. Đây là kết luận dựa trên tài liệu đo trong repository: stack khoảng 6,3 GB RAM và các image hơn 15 GB (`update_deploy_cicd.md`).
- TENTEN Cloud VPS/Gen Cloud Server: **deploy được**, nhưng phải chọn/điều chỉnh lên ít nhất 8 GB RAM và SSD >=80 GB; gói 16 GB RAM an toàn hơn. Các plan 50 GB SSD mặc định không đủ dư địa vận hành.
- Azure for Students: **deploy được về mặt kỹ thuật**, nhưng gói VM miễn phí nhỏ không phù hợp với toàn bộ stack. Cần VM khoảng `Standard_B4ls_v2` (4 vCPU/8 GiB) tối thiểu hoặc `Standard_B4s_v2` (4 vCPU/16 GiB) khuyến nghị, Managed Disk >=128 GiB, và chi phí sẽ trừ vào credit sinh viên. Credit/quota/SKU theo vùng có thể khiến dịch vụ không chạy đủ 12 tháng.
- Render avatar SadTalker/Wav2Lip/Syna không hoàn chỉnh trên VPS CPU hiện tại. SadTalker cần NVIDIA GPU/CUDA và Syna renderer là runtime Node/Playwright chạy ngoài Compose. Website, chatbot, search và phần quản lý AI Idol vẫn có thể chạy nếu tắt/không dùng đường render này.
- CI/CD đã có GitHub Actions và SSH deploy, nhưng **chưa có bằng chứng deploy production thành công**. Workflow chỉ tự deploy từ `main`; branch đang kiểm tra không tự deploy. Pipeline cũng chưa chạy unit/integration/E2E, chưa có rollback tự động và chưa kiểm tra migration/backup.

## 2. Bằng chứng và mức tin cậy

| Nhóm | Nguồn chính | Trạng thái |
|---|---|---|
| Runtime/container | `docker-compose.yml`, `docker-compose.prod.yml`, `docker-compose.local.yml`, các `Dockerfile*` | Đã xác minh tĩnh; `docker compose ... config --quiet` PASS cho production và local bằng `.env.example`, không phân giải `.env` thật |
| Dependencies | `composer.lock`, `backend/composer.lock`, các `requirements*.txt`, `mobile/package-lock.json`, asset frontend | Đã xác minh từ manifest/lock |
| CI/CD | `.github/workflows/deploy.yml`, `scripts/deploy.sh`, `Caddyfile`, `update_deploy_cicd.md` | Đã xác minh tĩnh; triển khai VPS/GitHub Actions thật chưa được chạy trong lần kiểm tra này |
| Database | code client/repository, Compose, migration/import script | Đã phân loại production, tùy chọn và legacy |
| Tài nguyên | `update_deploy_cicd.md`, `docs/ops/search-ops.md`, giới hạn Compose | Một phần là số đo đã ghi trong repository; chưa benchmark lại trên server đích |
| Cloud | trang chính thức TENTEN và Microsoft, truy cập ngày 2026-11-19 | Có thể thay đổi theo thời điểm/vùng |

## 3. Công nghệ, framework và phiên bản

### 3.1 Web và backend PHP

| Thành phần | Phiên bản/cấu hình đã xác minh | Vai trò |
|---|---|---|
| PHP | `php:8.2-fpm-alpine` | Backend/BFF, MVC tự viết; repository không dùng Laravel/Symfony full-stack |
| PHP extensions | `mongodb`, `zip`, `mbstring`, `opcache` | MongoDB, nén, Unicode, cache bytecode |
| Composer | image `composer:2` | Cài dependency theo lock |
| MongoDB PHP library | `mongodb/mongodb 2.5.1` trong `backend/composer.lock` | Data access chính của backend |
| Redis client PHP | `predis/predis 3.7.1` | Redis/Valkey cache, rate limit, Streams |
| Nginx | `nginx:1.27-alpine` | Static files và FastCGI/reverse proxy nội bộ |
| Caddy | `caddy:2.8-alpine` | Cổng production 80/443, TLS Let's Encrypt tự động |
| Frontend | PHP views, HTML, CSS, JavaScript thuần | UI khách hàng/admin |
| Bootstrap | 5.3.3 (asset vendored) | Component/layout frontend |

Lưu ý: root `composer.lock` còn giữ `mongodb/mongodb 1.19.1`, nhưng image production cài từ `backend/composer.lock` nên runtime backend dùng 2.5.1. Đây là hai manifest khác nhau cần tránh dùng nhầm khi deploy thủ công.

### 3.2 AI/search Python

| Thành phần | Phiên bản/cấu hình |
|---|---|
| Python | 3.11 (`python:3.11-slim`) |
| Flask / Flask-CORS | 3.0.3 / 4.0.1 |
| Gunicorn / Gevent | 23.0.0 / 24.11.1 |
| LangChain | `langchain>=0.3.7`, core/community >=0.3.x, OpenAI >=0.2.10 |
| LangGraph | >=1.0,<2 |
| Chroma client | `chromadb==1.5.9`, `langchain-chroma==1.1.0` |
| Embedding/rerank | `sentence-transformers`, PyTorch CPU; model embedding và cross-encoder được tải lúc build |
| Mongo/Redis/Neo4j clients | PyMongo >=4.6, redis-py >=5, Neo4j driver >=5.20,<6 |
| SQL client tùy chọn | PyMySQL >=1.1 |
| LLM/search providers | OpenAI, Google Gemini, Groq, Tavily; code còn hỗ trợ OpenRouter/Zhipu/Jina theo tính năng |
| Server process | 2 Gunicorn workers mặc định, `gthread`, 8 thread/worker, `preload_app=True` |

### 3.3 AI Idol, voice và mobile

| Thành phần | Phiên bản/cấu hình |
|---|---|
| AI Idol API/workers | Python 3.11, Flask >=3,<4, Gunicorn >=22,<24, Celery >=5.4,<6, PyMongo, Redis, FFmpeg |
| Script Council | CrewAI 1.15.21, Groq client >=0.30,<1 |
| TTS trong AI Idol | Piper TTS, Edge TTS, gTTS |
| Voice service | Python 3.11, FastAPI >=0.100, Uvicorn >=0.22, VieNeu >=3.3, ONNX Runtime >=1.20 |
| LiveKit agent | `livekit-agents>=0.8`, `livekit-api>=0.6` |
| Avatar service | Ubuntu 20.04, Python 3.8, CUDA 11.2.2/cuDNN8, PyTorch 1.9.1+cu111, SadTalker pin theo commit, Gunicorn 22 |
| Mobile | Expo ~57.0.24, React 19.2.3, React Native 0.86.3, TypeScript ~6.0.3 |

Node.js không được pin bằng `.nvmrc`/`engines`; Node chỉ cần cho Expo/mobile, prototype 3D và Syna renderer ngoài Compose. Mobile là client build riêng, không phải service bắt buộc trên server web.

## 4. Tất cả database và kho trạng thái

| Công nghệ | Trạng thái | Dữ liệu/vai trò | Cách triển khai hiện tại |
|---|---|---|---|
| MongoDB 7 / MongoDB Atlas | **Bắt buộc, nguồn dữ liệu chính** | Sản phẩm, tài khoản, đơn hàng, profile da, chat, campaign/job AI Idol, event | Compose có `mongo:7` và volume `mongo_data`; production dự kiến dùng `MONGO_URI` tới Atlas nhưng vẫn chạy container Mongo local chỉ để thỏa `depends_on` |
| ChromaDB | **Bắt buộc cho RAG/search/AI Idol knowledge** | Vector/embedding sản phẩm | Server `chromadb/chroma:0.5.20`, volume `chroma_data`; client Python 1.5.9 |
| Neo4j Community | **Đang dùng, có degrade khi mất** | Smart Search graph, sản phẩm tương tự, ingredient conflict, home recommendation, graph tools | `neo4j:5.26-community`, APOC, volume `neo4j_data`/`neo4j_logs`, giới hạn 1536 MiB |
| Redis 7 / Valkey-compatible | **Bắt buộc với stack đầy đủ** | Cache/session hỗ trợ, rate limit, Redis Streams, Celery broker/result, locks, search event và AI Idol queue | `redis:7-alpine`, AOF every second, volume `redis_data`; backend có thể dùng Valkey managed qua `VALKEY_URL` |
| MySQL | **Tùy chọn, không có container** | `recommendation_service` ưu tiên đọc bảng sản phẩm MySQL rồi fallback MongoDB | Có PyMySQL và nhóm biến `MYSQL_*`/`DB_*`; không phải nguồn chính của Compose hiện tại. Chỉ cần provision nếu chủ động bật đường MySQL |
| PostgreSQL | **Legacy/offline, không phải runtime** | Dump `database/skinsyntax1_3.sql` và spider import cũ | Không có driver trong runtime chính, không có container, README ghi legacy |
| SQLite | **Nội bộ Chroma, không phải service độc lập** | File `chroma.sqlite3` của Chroma local/persistent index | Không cần cài SQLite server riêng |
| Filesystem/volume | **Bắt buộc** | uploads, PHP sessions, model cache, AI Idol media, Caddy cert/config | Named volumes: `uploads_data`, `php_sessions`, `vieneu_model_cache`, `ai_idol_storage`, `caddy_data`, `caddy_config` |
| Cloudflare R2 | **Tùy chọn** | Xuất bản video/poster AI Idol | S3-compatible object storage qua `boto3`; nếu không cấu hình, artifact ở local volume và có thể tăng dần |

Rủi ro dữ liệu quan trọng:

1. `docker-compose.prod.yml` ghi production dùng Atlas nhưng vẫn khởi động MongoDB local (~300 MB theo tài liệu dự án). Không được nhầm volume local với nguồn dữ liệu production.
2. Chroma client 1.5.9 và server 0.5.20 không cùng dòng phiên bản. Code có workaround `get_collection` trước `get_or_create_collection`, nhưng đây vẫn là rủi ro tương thích cần smoke test sau deploy.
3. Neo4j là bản sao dựng từ Mongo; cần chạy import/sync lần đầu. Chroma cũng cần nạp dữ liệu lần đầu.
4. Không thấy backup/restore tự động cho named volumes trong pipeline deploy.
5. PostgreSQL dump và MySQL path không nên được hiểu là database bắt buộc của production hiện tại.

## 5. Service/backend cần chạy đồng thời

### 5.1 Stack production mặc định

Khi chạy `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`, các service sau được tạo mặc định:

| Nhóm | Service | Vai trò/phụ thuộc chính |
|---|---|---|
| Edge | `caddy` | Public 80/443, TLS, proxy tới Nginx |
| Web | `nginx` | Static assets/uploads, FastCGI tới PHP |
| Backend | `php-backend` | PHP-FPM :9000; gọi Mongo, Redis/Valkey, AI service, AI Idol |
| AI | `ai-service` | Flask/Gunicorn :5001; chatbot, RAG, recommendation, search/home/graph APIs |
| Search worker | `search-worker` | Redis Stream `search:events:v1` -> Mongo |
| Catalog worker | `catalog-worker` | Đồng bộ Mongo -> Neo4j/Chroma/cache và chạy lịch ops |
| AI Idol API | `ai-idol-service` | Flask :5003, quản lý campaign/job |
| AI Idol agent | `ai-idol-agent-service` | CrewAI Script Council :5013 |
| AI Idol worker | `ai-idol-worker` | Queue `media,celery`, tạo voice/video |
| AI Idol control | `ai-idol-control-worker` | Queue `control,agent`, scheduler/watchdog/agent |
| AI Idol broadcast | `ai-idol-broadcast-worker` | FFmpeg/RTMP broadcast |
| AI Idol scheduler | `ai-idol-beat` | Celery beat |
| Voice | `voice-service` | FastAPI/Uvicorn :5002, VieNeu TTS |
| Data | `mongodb` | Local Mongo health/dependency; production data có thể ở Atlas |
| Data | `chromadb` | Vector DB :8000 nội bộ |
| Data | `neo4j` | Graph DB :7687 nội bộ; browser bind loopback |
| Data/queue | `redis` | Cache, Streams, Celery broker/result |

Các cổng data/AI chính bị đóng khỏi Internet trong override production; chỉ Caddy mở 80/443. Neo4j đã bind `127.0.0.1`, AI Idol bind loopback. SSH 22 do firewall/VPS quản lý, không nằm trong Compose.

### 5.2 Service tùy chọn hoặc chạy ngoài Compose

| Service | Trạng thái |
|---|---|
| `livekit-voice-agent` | Profile `voice`; cần LiveKit server/cloud, AI service, voice service và Redis. Không tự chạy mặc định |
| `tunnel` / `ngrok` | Profile demo; không dùng song song với Caddy production trừ khi có mục đích rõ ràng |
| `ai-idol-avatar-service` :7861 | Có Dockerfile CUDA và script Windows host, nhưng **không có service trong Compose production**; cần NVIDIA GPU và model SadTalker/Wav2Lip |
| Syna renderer :7862 | Node + Playwright + FFmpeg, được start bằng PowerShell trên Windows host; **không có Dockerfile/service production** |
| Recommendation standalone :5002 | `ai-service-flask/recommendation_service/rcm_flask.py` có entrypoint riêng nhưng route production đã được tích hợp trong `ai-service`; nếu chạy riêng sẽ xung đột port mặc định với voice service |
| Expo mobile | Build/phân phối client riêng; không phải daemon server |
| spiders/import/research/evaluation | Batch/offline; không chạy thường trực |
| `services/web-service` | Chỉ chứa một view PHP sao chép, không có manifest/entrypoint; không phải service độc lập |

## 6. Dependencies và thư viện quan trọng

- PHP: MongoDB extension + `mongodb/mongodb`, Predis, OPcache, mbstring.
- AI/search: Flask, Gunicorn/gevent, LangChain/LangGraph, ChromaDB client, sentence-transformers, PyTorch CPU, Neo4j driver, PyMongo, redis-py, pandas, RapidFuzz, requests.
- Recommendation bổ sung: LlamaIndex, OpenAI/Gemini adapters, BM25; PyMySQL chỉ cho nguồn MySQL tùy chọn.
- AI Idol: Celery/Redis, FFmpeg, Piper/Edge/gTTS, Groq, CrewAI, Chroma client, sentence-transformers, boto3/R2.
- Voice: VieNeu, ONNX Runtime, FastAPI/Uvicorn, audio libs (`soundfile`, `soxr`, `kaldi-native-fbank`).
- Avatar GPU: CUDA/cuDNN, PyTorch 1.9.1 cu111, SadTalker, Wav2Lip model, OpenCV, GFPGAN/facexlib.
- External services theo tính năng: MongoDB Atlas, OpenAI, Google Gemini, Groq, Tavily, LiveKit, Cloudflare R2, SMTP, Google/Facebook OAuth, VNPay, SePay/bank transfer webhook, RTMP destinations.
- Build production cần Internet để lấy base image, Composer/PyPI packages, PyTorch wheel, Hugging Face models, Piper voice và certificate Let's Encrypt.

Các requirement Python phần lớn dùng range thay vì lock tuyệt đối. Build lại ở thời điểm khác có thể cài phiên bản khác; đây là rủi ro reproducibility, đặc biệt với AI Idol, voice và LiveKit.

## 7. Docker, Compose và cấu hình triển khai hiện có

### 7.1 Các file hiện có

- `docker-compose.yml`: stack đầy đủ cho dev/default.
- `docker-compose.prod.yml`: đóng port nội bộ bằng `!reset`, thêm Caddy/TLS. Yêu cầu Docker Compose >=2.24.4.
- `docker-compose.local.yml`: override Docker Desktop hiện tại; dùng network internal, Atlas cho PHP, local DB cho AI, và profile hóa một số service. File này đang untracked trong working tree, không phải nguồn production đáng tin cậy trên GitHub.
- `docker-compose.atlas.yml`: compatibility shim trống, cũng đang untracked.
- `Dockerfile.php`, `Dockerfile.ai`, `Dockerfile.ai-idol`, `Dockerfile.ai-idol-agent`, `Dockerfile.ai-idol-avatar`, cùng Dockerfile voice/LiveKit.
- `Caddyfile`: TLS tự động cho `DOMAIN`, stream proxy không buffer.
- `nginx/nginx.conf` và `nginx/proxy.d`: static/FastCGI/reverse-proxy boundary.
- `scripts/deploy.sh`: pull fast-forward, build tuần tự, `up -d`, restart theo file thay đổi, homepage smoke test, prune image.

### 7.2 Persistent storage

Production cần backup các volume: Mongo local (nếu thật sự dùng), Chroma, Neo4j data/logs, Redis AOF, uploads, PHP sessions, AI Idol storage, model cache, Caddy cert/config. `database/recommendation_index` và dữ liệu CSV/index không commit cũng phải được chuẩn bị theo hướng dẫn nội bộ.

### 7.3 Điểm cần xử lý trước go-live

1. Chọn rõ `MONGO_URI` là Atlas hay Mongo local; hiện production vẫn chạy cả hai hình thức.
2. Provision `.env` production ngoài Git; `.env` và `mobile/.env` hiện được `.gitignore`, chỉ `.env.example` được track.
3. Tạo volume backup/snapshot định kỳ; pipeline hiện chưa làm.
4. Chạy import/reindex Chroma và Neo4j lần đầu, rồi smoke test search/chat/home recommendation.
5. Chỉ mở 22/80/443 ở firewall; không public 27017/6379/7687/8000/5001/5002/5003/5013.
6. Chọn chính sách AI Idol avatar: cấp GPU service riêng hoặc tắt đường render; không để job chờ vô hạn tới `host.docker.internal` trên Linux VPS.
7. Kiểm tra `host.docker.internal` trên Linux engine; Compose có `host-gateway`, nhưng target 7861/7862 vẫn phải tồn tại.

## 8. Nhu cầu CPU, RAM, SSD và tài nguyên server

### 8.1 Cấu hình khuyến nghị

| Mức | CPU | RAM | SSD | Phù hợp |
|---|---:|---:|---:|---|
| Tối thiểu thử nghiệm full stack | 4 vCPU | 8 GB + swap 4 GB | 80 GB | Demo/tải thấp; build có thể chậm hoặc thiếu RAM |
| Khuyến nghị production học thuật | 4-8 vCPU | 16 GB | 120-150 GB SSD | Toàn bộ stack CPU, có dư địa build và dữ liệu |
| Core web + AI được cắt gọn | 4 vCPU | 8 GB | 80-100 GB | Chỉ khi chủ động bỏ/đưa ra ngoài AI Idol, voice và một số worker; Compose hiện tại chưa cấu hình production theo chế độ này |
| Avatar video GPU | >=4 vCPU + NVIDIA GPU | >=16 GB | >=150 GB | Cần CUDA-compatible GPU; bằng chứng dự án dùng RTX 3050 4 GB VRAM, nhưng chưa benchmark trên cloud |

Giải thích:

- Tài liệu dự án ghi stack đo khoảng 6,3 GB RAM và image >15 GB; lần build đầu 20-60 phút.
- Neo4j có `mem_limit: 1536m`; đo trong repo đạt khoảng 1,27-1,36 GB khi full graph sync.
- `catalog-worker` có giới hạn 1536 MiB, `search-worker` 256 MiB. AI service tải embedding/reranker và PyTorch; voice tải model ONNX vào RAM.
- PHP-FPM dùng `pm=static`, 20 worker; giới hạn mỗi PHP process 256 MB nên worst-case lý thuyết cao hơn mức sử dụng trung bình.
- AI image được ghi chú khoảng 5 GB. Docker build, layer cũ, Hugging Face cache, Chroma/Neo4j, uploads và media AI Idol làm 50 GB nhanh chóng thiếu.
- AI Idol media chưa có bằng chứng cleanup toàn diện; nếu không dùng R2, SSD sẽ tăng dần.
- GPU không cần cho chatbot/RAG/search CPU hiện tại; chỉ cần cho SadTalker/Wav2Lip thực tế.

Khuyến nghị hệ điều hành: Ubuntu 24.04 LTS x86_64, Docker Engine hiện hành, Docker Compose plugin >=2.24.4. ARM chưa được xác minh end-to-end; không chọn ARM cho production đầu tiên.

## 9. CI/CD và khả năng deploy tự động từ GitHub

### 9.1 Những gì đã có

Workflow `.github/workflows/deploy.yml`:

- chạy trên push `main`, mọi pull request và `workflow_dispatch`;
- lint cú pháp PHP bằng `php -l`;
- compile cú pháp Python 3.11 bằng `compileall`;
- validate Compose production;
- sau khi check pass trên `main`, SSH vào VPS bằng GitHub Secrets và gọi `bash ~/skinsyntax/scripts/deploy.sh`;
- dùng concurrency group để tránh hai deployment chạy song song.

`scripts/deploy.sh`:

- yêu cầu `.env` có sẵn trên VPS;
- khóa deploy bằng `flock`;
- fetch và fast-forward branch deploy;
- build tuần tự các image lớn để giảm peak RAM;
- khởi động Compose production;
- restart service theo nhóm file thay đổi;
- smoke test homepage tối đa 150 giây;
- prune dangling image.

GitHub Secrets chỉ cần ghi tên: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS`.

### 9.2 Đánh giá

**Có khả năng tự động deploy từ GitHub lên VPS qua SSH**, nhưng chưa đủ để gọi là production-ready hoàn chỉnh:

- branch hiện tại là `fix/stability-and-bug-fixes`; push branch này không kích hoạt deploy. Cần merge/fast-forward vào `main` theo quy trình dự án.
- tài liệu `update_deploy_cicd.md` tự ghi chưa chạy deploy thật, GitHub Actions thật và Let's Encrypt thật.
- CI không chạy unit test, integration test, E2E, dependency/security scan hoặc image smoke test.
- deploy không có rollback nếu image/service phụ lỗi sau khi homepage đã lên.
- không tự migration/import/index/backup database.
- health gate cuối chỉ kiểm tra homepage Nginx, không bắt buộc tất cả AI/worker/DB healthy.
- deploy build trực tiếp trên VPS; cần nhiều thời gian, disk và RAM hơn mô hình build/push image ở CI registry.
- workflow hiện chỉ là VPS-generic; chưa có Azure CLI/Bicep/Terraform/ARM template hay GitHub OIDC cho Azure.

Trạng thái kết luận: **CI PASS ở mức cấu hình tĩnh trong lần kiểm tra này; deploy thật NOT RUN**.

## 10. Biến môi trường cần thiết

Phần này chỉ liệt kê **tên biến**, không ghi giá trị.

### 10.1 Tối thiểu để Compose production khởi động đúng

`DOMAIN`, `APP_URL`, `MONGO_URI`, `MONGO_DB`, `MONGO_DB_NAME`, `NEO4J_USER`, `NEO4J_PASSWORD`, `DB_MODE`, `CACHE_DRIVER`, `REDIS_URL`, `VALKEY_URL`, `CACHE_PREFIX`, `CACHE_DEFAULT_TTL`, `MAIL_DEMO_MODE`.

Để AI trả lời thực tế, cần ít nhất một provider phù hợp với pipeline đã chọn: `OPENAI_API_KEY`, `GOOGLE_API_KEY`/`GOOGLE_API_KEYS`, hoặc `GROQ_API_KEY`; các tính năng Tavily cần `TAVILY_API_KEY`.

### 10.2 Danh sách từ `.env.example`, theo nhóm

- App/routing: `APP_URL`, `DOMAIN`, `WEB_PORT`, `FRONTEND_ROOT`, `BACKEND_ROOT`, `SOCIAL_LOCAL_FALLBACK`, `DB_MODE`.
- Database/cache: `MONGO_URI`, `MONGO_DB`, `MONGO_DB_NAME`, `REDIS_URL`, `REDIS_CACHE_TTL`, `SESSION_CACHE_TTL`, `CACHE_DRIVER`, `VALKEY_URL`, `CACHE_PREFIX`, `CACHE_DEFAULT_TTL`, `NEO4J_URI`, `NEO4J_USER`, `NEO4J_PASSWORD`, `CHROMA_DB_PATH`, `CSV_FILE_PATH`.
- AI/chat: `OPENAI_API_KEY`, `OPENAI_CHAT_MODEL`, `OPENAI_CHAT_MODELS`, `TAVILY_API_KEY`, `GROQ_API_KEY`, `CHATBOT_PORT`, `GUNICORN_WORKERS`, `GUNICORN_WORKER_CLASS`, `AI_CHAT_ENDPOINT`, `AI_CHAT_STREAM_ENDPOINT`, `AI_RECOMMENDATION_ENDPOINT`, `AI_HYBRID_RECOMMENDATION_ENDPOINT`, `AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT`, `AI_GOIY_SYNC_AI`, `AI_CHAT_TIMEOUT`, `AI_RECOMMENDATION_TIMEOUT`, `AI_HYBRID_RECOMMENDATION_TIMEOUT`, `CHAT_AGENT_TOKEN_SECRET`, `CHAT_AGENT_INTERNAL_SECRET`, `CHAT_AGENT_PHP_URL`, `CHAT_AGENT_REQUIRE_LISTENER`, `SYNA_AGENT_V2`.
- Search/home/label: `SEARCH_INTERNAL_TOKEN`, `SEARCH_FALLBACK_ENABLED`, `SEARCH_RECOMMEND_TIMEOUT_MS`, `SEARCH_RECOMMEND_CONNECT_TIMEOUT_MS`, `SEARCH_USER_HASH_SALT`, `HOME_GRAPH_ENABLED`, `HOME_RECOMMEND_ENDPOINT`, `HOME_RECOMMEND_TIMEOUT_MS`, `HOME_RECOMMEND_CONNECT_TIMEOUT_MS`, `HOME_GRAPH_CACHE_TTL`, `LABEL_ANALYZE_TIMEOUT_MS`, `LABEL_MAX_UPLOAD_MB`, `PRODUCT_EVENTS_ENABLED`.
- Mail/OAuth: `MAIL_DEMO_MODE`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_ENCRYPTION`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_OAUTH_REDIRECT_URI`, `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, `FACEBOOK_OAUTH_REDIRECT_URI`.
- Payment: `BANK_TRANSFER_BANK_ID`, `BANK_TRANSFER_BANK_NAME`, `BANK_TRANSFER_ACCOUNT_NO`, `BANK_TRANSFER_ACCOUNT_NAME`, `BANK_TRANSFER_QR_TEMPLATE`, `BANK_TRANSFER_WEBHOOK_SECRET`, `SEPAY_API_TOKEN`, `SEPAY_ACCOUNT_NUMBER`, `SEPAY_POLLING_ENABLED`, `VNPAY_TMN_CODE`, `VNPAY_HASH_SECRET`, `VNPAY_URL`, `VNPAY_RETURN_URL`.
- Voice/LiveKit: `TTS_PORT`, `TTS_SERVICE_URL`, `NUM_THREADS`, `PRECISION`, `LIVEKIT_URL`, `LIVEKIT_INTERNAL_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`, `LIVEKIT_EGRESS_WEBHOOK_URL`, `LIVEKIT_EGRESS_WEBHOOK_SIGNING_KEY`, `LIVEKIT_EGRESS_WEBHOOK_SIGNING_SECRET`, `LIVEKIT_WEBHOOK_API_KEY`, `LIVEKIT_WEBHOOK_API_SECRET`, `LIVE_RECORDING_PLAYBACK_SECRET`, `COOLDOWN_SECONDS`, `MAX_QUEUE_SIZE`, `LIVE_SYNA_MAX_PER_MINUTE`.
- AI Idol: `AI_IDOL_ENDPOINT`, `AI_IDOL_AGENT_ENABLED`, `AI_IDOL_AGENT_ALLOW_AUTO_PUBLISH`, `AI_IDOL_SCRIPT_COUNCIL_ENABLED`, `AI_IDOL_SCRIPT_COUNCIL_URL`, `AI_IDOL_SCRIPT_COUNCIL_TIMEOUT`, `AI_IDOL_CREW_REVIEW_MODEL`, `AI_IDOL_CREW_MAX_TOKENS`, `AI_IDOL_CREW_MAX_RPM`, `AI_IDOL_INTERNAL_TOKEN`, `AI_IDOL_GROQ_MODEL`, `AI_IDOL_GROQ_FALLBACK_MODELS`, `AI_IDOL_SCRIPT_CANDIDATES`, `AI_IDOL_TRACKING_STARTED_ON`, `AI_IDOL_PRODUCT_EVENTS_RETENTION_DAYS`, `AI_IDOL_AUDIO_MIX_ENABLED`, `AI_IDOL_AUDIO_TARGET_LUFS`, `AI_IDOL_MUSIC_BED_LUFS`, `AI_IDOL_MUSIC_DUCK_RATIO`, `AI_IDOL_MUSIC_INTRO_SECONDS`, `AI_IDOL_MUSIC_OUTRO_SECONDS`, `AI_IDOL_TITLE_CARDS_ENABLED`, `AI_IDOL_PIPER_SENTENCE_SILENCE`, `AI_IDOL_SECTION_PAUSE_MIN`, `AI_IDOL_SECTION_PAUSE_MAX`, `AI_IDOL_AUTOPILOT_DEFAULT_PRODUCTS`, `AI_IDOL_AUTOPILOT_MAX_PRODUCTS`, `AI_IDOL_AUTOPILOT_SLOTS`.
- R2/object storage: `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_REGION`, `R2_PUBLIC_BASE_URL`.
- Tunnel/E2E: `NGROK_AUTHTOKEN`, `NGROK_DOMAIN`, `E2E_CUSTOMER_EMAIL`, `E2E_CUSTOMER_PASSWORD`, `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`.

### 10.3 Biến runtime có trong code nhưng thiếu ở `.env.example`

Đây là config drift cần xem xét; phần lớn có default và không phải tất cả đều bắt buộc, nhưng nên bổ sung vào tài liệu/env template nếu tính năng tương ứng được bật:

- AI provider/model: `GOOGLE_API_KEY`, `GOOGLE_API_KEYS`, `GEMINI_AGENT_MODEL`, `GEMINI_AGENT_FALLBACK_MODEL`, `GEMINI_CHAT_MODEL`, `GEMINI_RECOMMEND_MODEL`, `GROQ_CHAT_MODEL`, `GROQ_ROUTER_MODEL`, `OPENAI_RECOMMENDATION_MODEL`, `OPENROUTER_API_KEY`, `ZHIPU_API_KEY`, `JINA_API_KEY`.
- MySQL tùy chọn: `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_PRODUCTS_TABLE`, `MYSQL_CONNECT_TIMEOUT`, `MYSQL_READ_TIMEOUT`, cùng alias `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
- Recommendation standalone/tuning: `RECOMMENDATION_HOST`, `RECOMMENDATION_PORT`, `RECOMMENDATION_REQUEST_TIMEOUT`, `RECOMMENDATION_TOP_K`, `RECOMMENDATION_MAX_STEPS`, `RECOMMENDATION_MONGO_COLLECTION`, `RECOMMENDATION_CHROMA_DIR`, `RECOMMENDATION_CHROMA_COLLECTION`, `RECOMMENDATION_INDEX_DIR`, `RECOMMENDATION_CACHE_TTL`, `RECOMMENDATION_PRODUCT_TTL`, `RECOMMENDATION_RRF_K`, `RECOMMENDATION_RRF_ALPHA`, `RECOMMENDATION_LLM_PROVIDER`, `RECOMMENDATION_LLM_TIMEOUT`, `RECOMMENDATION_BM25_TTL`.
- Search/runtime tuning: `CHROMA_HOST`, `CHROMA_PORT`, `SEARCH_CHROMA_COLLECTION`, `SEARCH_LOG_LEVEL`, `SEARCH_EXPERIMENTS_FILE`, `SEARCH_WORKER_HEARTBEAT`, `CATALOG_WORKER_HEARTBEAT`, `PHP_INTERNAL_URL`, `NEO4J_CALL_DEADLINE_S`, `NEO4J_MIGRATIONS_DIR`, `REDIS_CONNECT_TIMEOUT`, `REDIS_TIMEOUT`, `EMBEDDING_DEVICE`, `RERANKER_DEVICE`, `SYNA_AGENT_WARMUP`, `STORE_SUPPORT_LLM_BUDGET_S`.
- Backend feature flags/aliases: `AI_AGENT_ENDPOINT`, `AI_LLAMA_INDEX_RECOMMENDATION_TIMEOUT`, `AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE`, `CART_INSIGHTS_ENABLED`, `PRODUCT_VIEWS_REDIS_ENABLED`, `SEARCH_CORRECTION_ENABLED`, `SEARCH_EVENTS_ENABLED`, `SEARCH_EVENTS_STREAM_ENABLED`, `SEARCH_MORE_ENABLED`, `SEARCH_RANKING_ENABLED`, `SIMILAR_PRODUCTS_ENABLED`, `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `FACEBOOK_OAUTH_CLIENT_ID`, `FACEBOOK_OAUTH_CLIENT_SECRET`.
- LiveKit agent startup: `AI_SERVICE_URL`, `AI_HEALTH_URL`, `TTS_HEALTH_URL`, `STARTUP_WAIT_SECONDS`, `STARTUP_BACKOFF_INITIAL`, `STARTUP_BACKOFF_MAX`, `TTL_SECONDS`.
- Avatar host runtime: `SADTALKER_ROOT`, `SADTALKER_INFERENCE_TIMEOUT`, `WAV2LIP_ROOT`, `WAV2LIP_CHECKPOINT`, `AI_IDOL_FFMPEG_BIN`, `AI_IDOL_WAV2LIP_BATCH_SIZE`, `AI_IDOL_WAV2LIP_TEMPLATE_SECONDS`, `AI_IDOL_AVATAR_CHUNK_SECONDS`, `AI_IDOL_AVATAR_EXPRESSION_SCALE`, `AI_IDOL_AVATAR_POSE_SCALE`.
- AI Idol mở rộng: `AI_IDOL_PORT`, `AI_IDOL_REDIS_URL`, `AI_IDOL_CATALOG_EVENTS_REDIS_URL`, `AI_IDOL_CHROMA_HOST`, `AI_IDOL_CHROMA_PORT`, `AI_IDOL_CHROMA_COLLECTION`, `AI_IDOL_STORAGE_DIR`, `AI_IDOL_TTS_PROVIDER`, `AI_IDOL_AVATAR_SERVICE_URL`, `AI_IDOL_AVATAR_SERVICE_TIMEOUT`, `SYNA_RENDER_URL`, `AVATAR_PROVIDER`, `AI_IDOL_RTMP_TARGETS_JSON`, `AI_IDOL_BROADCAST_LATE_GRACE_MINUTES`, `AI_IDOL_BROADCAST_MAX_RETRIES`, `AI_IDOL_BROADCAST_RETRY_SECONDS`, `AI_IDOL_DISPATCH_INTERVAL_SECONDS`, `AI_IDOL_TIMEZONE`, `AI_IDOL_SEARCH_URL`, `AI_IDOL_CATALOG_CACHE_TTL`, `AI_IDOL_CREW_PORT`, `AI_IDOL_CREW_MAX_REQUEST_BYTES`, `AI_IDOL_CREW_MAX_TOKENS_LONG`.

GitHub Actions variables/secrets: `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS`. Deploy script optional runtime variables: `APP_DIR`, `DEPLOY_BRANCH`.

## 11. Đánh giá TENTEN Cloud VPS

Trang chính thức TENTEN hiện có các gói từ 8 GB đến 16 GB RAM và cho phép điều chỉnh CPU/RAM/SSD; một số plan chỉ có 50 GB SSD mặc định. [TENTEN Cloud VPS](https://tenten.vn/en/cloud-vps) và [TENTEN Gen Cloud Server](https://tenten.vn/vi/new-cloud/gen-cloud-server) cũng công bố lựa chọn data center Việt Nam và khả năng mở rộng storage.

**Kết luận: phù hợp, với điều kiện:**

1. Chọn Linux x86_64, tối thiểu 4 vCPU/8 GB; ưu tiên 16 GB RAM.
2. Nâng/đính kèm SSD để tổng usable >=80 GB, nên 120-150 GB.
3. Chỉ public 22/80/443, cấu hình DNS `DOMAIN`, và cài Docker Compose >=2.24.4.
4. Dùng Atlas/R2 có thể giảm áp lực database/media local, nhưng Chroma, Neo4j, Redis và image vẫn cần disk/RAM.
5. Không kỳ vọng render SadTalker/Wav2Lip nếu plan không có NVIDIA GPU.
6. Theo dõi IOPS: quy định TENTEN nêu giới hạn khi phần lớn kiểm tra vượt 3000 IOPS kéo dài; graph/vector sync và Docker build có thể tạo burst. [Quy định sử dụng TENTEN](https://tenten.vn/vi/Support/usingPolicy)

Gói 8 GB là sát mức tối thiểu; gói 16 GB phù hợp hơn cho stack hiện trạng. Nếu chỉ có 50 GB SSD thì **chưa đạt** yêu cầu lưu trữ của repository.

## 12. Đánh giá Microsoft Azure for Students

Azure for Students hiện cấp **100 USD credit trong 12 tháng**, có thể gia hạn mỗi năm nếu vẫn đủ điều kiện sinh viên; khi hết credit mà không nâng cấp, subscription/service có thể bị vô hiệu hóa. [Microsoft Learn: Azure for Students](https://learn.microsoft.com/azure/education-hub/about-azure-for-students), [FAQ Azure Education Hub](https://learn.microsoft.com/en-us/azure/education-hub/azure-dev-tools-teaching/program-faq)

Microsoft công bố một số VM burstable miễn phí 750 giờ/tháng, nhưng đây là các SKU nhỏ và quyền dùng còn phụ thuộc vùng/quota. [Azure for Students offer](https://azure.microsoft.com/en-us/free/students) VM Bsv2 có các mức phù hợp hơn như 4 vCPU/8 GiB (`B4ls_v2`) và 4 vCPU/16 GiB (`B4s_v2`), nhưng không nên giả định các mức này miễn phí hoặc luôn được cấp quota. [Thông số Bsv2](https://learn.microsoft.com/en-sg/azure/virtual-machines/sizes/general-purpose/bsv2-series)

**Kết luận: triển khai được cho demo/thesis, nhưng credit có thể không đủ cho vận hành liên tục:**

- Chọn Ubuntu x86_64 VM, tối thiểu `B4ls_v2`, khuyến nghị `B4s_v2`, Managed Disk >=128 GiB.
- B-series là burstable; build AI 20-60 phút và tải CPU kéo dài có thể tiêu credit CPU, vì vậy build trên GitHub/registry sẽ ổn định hơn build trực tiếp trên VM.
- Không chọn các VM ARM miễn phí cho lần deploy đầu: repository chỉ có kiểm tra dry-run ARM được ghi trong tài liệu, chưa có bằng chứng build/run end-to-end.
- Student subscription có thể giới hạn SKU/quota theo region; phải kiểm tra `az vm list-skus`/Portal trước khi chốt kiến trúc.
- App Service/Static Web Apps không phù hợp để bê nguyên stack vì dự án cần nhiều container stateful, Docker volume, Neo4j/Chroma/Redis/Celery và custom reverse proxy. Một Linux VM là đường ít thay đổi nhất.
- Nếu muốn Azure-native về sau, có thể tách Atlas/R2, dùng managed Redis/storage và build image vào ACR, nhưng đó là thay đổi kiến trúc ngoài phạm vi báo cáo này.
- GPU Azure cho avatar gần như chắc chắn tiêu credit nhanh và thường vướng quota; nên bỏ/tách tính năng render trong bản sinh viên.

## 13. Checklist trước deploy thật

1. Merge đúng nội dung cần phát hành vào `main`; bảo đảm working tree sạch và không đưa `.env` vào Git.
2. Chốt server 16 GB/120+ GB hoặc xác nhận chấp nhận cấu hình 8 GB/80 GB + swap.
3. Tạo `.env` production từ danh sách trên; xoay/kiểm tra toàn bộ secret bên ngoài repository.
4. Chốt Atlas hay Mongo local; whitelist IP VPS nếu dùng Atlas.
5. Backup/snapshot các named volume và dữ liệu bên ngoài Git.
6. Chạy GitHub Actions check, unit/integration test liên quan, rồi deploy staging trước.
7. Import/sync Chroma và Neo4j; xác minh Mongo, Redis, Celery queue, chatbot/search/recommendation.
8. Kiểm tra Caddy certificate, OAuth callback, webhook payment, SMTP và LiveKit URL theo domain thật.
9. Xác minh chính sách AI Idol avatar/Syna; tắt tính năng không có renderer/GPU.
10. Thiết lập monitoring disk/RAM/container health, log rotation, budget/cost alert và rollback thủ công có kiểm chứng.

## 14. Kết quả kiểm tra đã chạy

- PASS: đọc inventory toàn repository, manifest, Dockerfile, Compose, entrypoint, CI/CD, database client và env-name references.
- PASS: `docker compose --env-file .env.example -f docker-compose.yml -f docker-compose.prod.yml config --no-env-resolution --no-interpolate --quiet`.
- PASS: `docker compose --env-file .env.example -f docker-compose.local.yml config --no-env-resolution --no-interpolate --quiet`.
- PASS với cảnh báo môi trường: Docker CLI không đọc được `C:\Users\ACER\.docker\config.json` trong sandbox, nhưng Compose validation trả exit code 0.
- NOT RUN: build image, start container, connect database, test API/browser, deploy VPS/Azure/TENTEN, GitHub Actions thật và benchmark server đích. Đây là báo cáo kiểm tra tĩnh theo yêu cầu, không phải chứng nhận runtime production.
