# U02 — ỔN ĐỊNH RUNTIME: LIVEKIT-AGENT, LOCK CHATBOT, LOG `\x00`

## Vai trò
Kỹ sư SRE/backend Python. Dùng quy trình systematic-debugging: tái hiện → cô lập → nguyên nhân gốc → sửa nhỏ nhất → kiểm chứng.

## Đọc trước
`u00-common-rules.md` → `docs/plans/upgrade-handoff.md` (+ mục "Sửa timeout Flask" trong `docs/plans/smart-search-handoff.md`) → `docker-compose.yml`, `ai-service-flask/gunicorn_config.py`, `ai-service-flask/chatbot_service/chatbot_flask.py`.

## Việc 1 — `skinsyntax-livekit-agent` crash-loop
- Hiện trạng: restart ~15s/lần, lỗi DNS tới `voice-service`; mỗi lần khởi động gửi một câu chat RAG tới `ai-service:5001` (timeout 10s) → chiếm tài nguyên Flask.
- Yêu cầu:
  1. Đọc service livekit-agent và voice-service trong compose (tên service, network, profile, depends_on, healthcheck), code agent (tìm thư mục theo build context).
  2. Xác định nguyên nhân DNS (service voice-service không chạy? khác network? tên host sai? profile không bật?). Ghi bằng chứng (`docker compose ps -a`, `docker inspect` network, log).
  3. Sửa nhỏ nhất: đúng tên host/network/depends_on với `condition: service_healthy` nếu voice-service có healthcheck; **và** thêm backoff khi khởi động thất bại (agent không spam chat RAG khi phụ thuộc chưa sẵn sàng — tìm đoạn gửi "câu chào/kiểm tra" khi khởi động và chỉ gửi sau khi phụ thuộc OK, hoặc bỏ nếu là health-check thừa).
  4. Nếu voice-service cố ý không chạy trong môi trường dev: thêm `profiles: ["voice"]` cho agent để không tự khởi động, ghi rõ cách bật.
  5. Không đụng logic AI Idol/voice ngoài phạm vi khởi động/kết nối. Chạy GitNexus impact cho symbol Python bị sửa.
- Đạt: `docker compose ps` không còn container restart liên tục; trong 5 phút không có request chat tự phát từ agent tới ai-service (đếm qua log hoặc `/proc/net/tcp` như báo cáo cũ).

## Việc 2 — Lock khởi tạo trong chatbot (gunicorn gthread 8 luồng)
- Hiện trạng: `chatbot_flask.py` có `get_llms`, `get_vectorstore`, `get_hybrid_pipeline` khởi tạo lười, không lock; `get_llms` gán `_llms = []` rồi mới append → luồng khác có thể thấy danh sách rỗng; Chroma có thể khởi tạo 2 lần.
- Yêu cầu: áp dụng mẫu **double-checked locking**:
  ```python
  _llms_lock = threading.Lock()
  def get_llms():
      global _llms, _llms_ts
      cached = _llms
      if cached and not _expired(_llms_ts):
          return cached
      with _llms_lock:
          if _llms and not _expired(_llms_ts):
              return _llms
          built = _build_llms()          # dựng vào biến cục bộ
          _llms, _llms_ts = built, time.monotonic()   # gán nguyên tử
          return built
  ```
  Tương tự cho vectorstore/hybrid pipeline (lock riêng mỗi tài nguyên). Giữ nguyên TTL/hành vi hiện có. Thêm vào `post_fork` warm-up nếu an toàn (không gọi LLM thật, chỉ dựng client).
- Rà các global khác (`LLM_COOLDOWNS`, `_WEB_CACHE`…): chỉ ghi nhận, sửa nếu có ghi phức hợp không nguyên tử.
- Test: unit test dùng `ThreadPoolExecutor(16)` gọi `get_llms` đồng thời với builder giả chậm (sleep) → builder chỉ chạy 1 lần, không luồng nào nhận list rỗng.

## Việc 3 — Log `skinsyntax-ai` lẫn `\x00`
- Tái hiện `docker logs skinsyntax-ai | head` → tìm nguồn (file log mở chế độ append bị truncate? gunicorn `accesslog` trỏ tới file đã bị xoay vòng? stdout bị ghi đè bởi `logging.FileHandler` trên cùng file?).
- Sửa để log ra stdout/stderr sạch; cấu hình `logging` mức INFO cho `search_service` (để log JSON correlation_id hiện ra — trước đây không thấy). Không log PII.
- Đạt: `docker logs --tail 50 skinsyntax-ai` đọc được, thấy dòng log `search_recommend` khi gọi API.

## Kiểm chứng chung
- pytest các suite trong handoff + test mới; `/api/health`, `/api/chat` (1 câu ngắn), `/api/search/recommend` đều 200; bench ngắn (100 request, script trong handoff giai đoạn 1) không tệ hơn trước (p95 tuần tự < 150ms khi ấm).

## Đầu ra
Mục "U02" trong handoff: nguyên nhân + sửa của từng việc, số liệu trước/sau. Không commit.
