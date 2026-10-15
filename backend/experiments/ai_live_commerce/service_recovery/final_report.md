# AI IDOL SERVICE RECOVERY — FINAL REPORT

**Role:** Senior Docker / PHP / AI Service Integration Engineer  
**Date:** 2026-10-11  
**Target URL:** `http://localhost/index.php?r=admin_ai_idol`  
**Final Status:** **`AI IDOL SERVICE RECOVERY — PASS`**

---

## 1. Executive Summary

Chức năng **AI Idol Studio** trên hệ thống SkinSyntaxVN đã được phục hồi toàn diện, kiểm chứng từ tầng hạ tầng Docker, phân giải DNS nội bộ, giao tiếp cURL proxy trong PHP backend, cho đến luồng tạo kịch bản, duyệt kịch bản, và dựng video streaming thực tế.

---

## 2. Key Answers to Investigation Points

### A. AI Idol Service có tồn tại không?
- **CÓ.** Mã nguồn service Python (`ai-idol-service/`) và cấu hình Docker (`Dockerfile.ai-idol`, `docker-compose.yml`) đã có sẵn đầy đủ trong repository.

### B. Tại sao không phân giải được hostname `ai-idol-service`?
- Image `skinsyntax-ai-idol:local` trước đây chưa được build và các container `skinsyntax-ai-idol` (HTTP API) và `skinsyntax-ai-idol-worker` (Celery background worker) chưa từng được khởi chạy. Do đó, DNS nội bộ Docker (`127.0.0.11`) không tìm thấy hostname này trên network `skinsyntax-network`.

### C. Đã sửa như thế nào?
1. Build thành công Docker image `skinsyntax-ai-idol:local` với đầy đủ PyTorch CPU, sentence-transformers model và Piper Vietnamese ONNX voice model.
2. Khởi chạy 2 service chính:
   - `ai-idol-service` (container `skinsyntax-ai-idol` tại cổng 5003).
   - `ai-idol-worker` (container `skinsyntax-ai-idol-worker` kết nối Redis `redis://redis:6379/2`).
3. Cải tiến `frontend/public/assets/js/ai-idol-studio.js`:
   - Bổ sung `AbortController` timeout (30s / 60s) để ngăn form treo vô hạn ở trạng thái *"Đang chuẩn bị..."*.
   - Bổ sung nút **Thử lại (Retry)** trực quan trong thư viện chương trình và danh sách Agent khi mất kết nối.
   - Kiểm tra `campaign_id` chặt chẽ trước khi thông báo tạo thành công.

### D. Những API nào đã hoạt động?
- `GET /api/health` — Trả về `{"ok": true, "service": "ai-idol", "mongo": "ok", "agent_enabled": true}` (HTTP 200).
- `GET /api/ai-idol/campaigns` — Trả về danh sách campaign (HTTP 200).
- `POST /api/ai-idol/campaigns` — Tạo campaign mới và enqueue Celery job (HTTP 202).
- `GET /api/ai-idol/campaigns/<id>` — Lấy chi tiết campaign và các segment (HTTP 200).
- `POST /api/ai-idol/jobs/<job_id>/script/approve` — Duyệt kịch bản và kích hoạt video pipeline (HTTP 200).
- `GET /api/ai-idol/campaigns/<id>/video` — Stream file video MP4 hỗ trợ HTTP byte-ranges (HTTP 200).
- `GET /api/ai-idol/agent/runs` & `POST /api/ai-idol/agent/runs` — Lập kế hoạch và truy vấn Agent (HTTP 200/202).

### E. Wizard tạo kịch bản đã chạy thật chưa?
- **ĐÃ CHẠY THẬT.** Tạo thành công campaign với sản phẩm mã `93` (*Sữa Dưỡng Ẩm Embryolisse*). Pipeline tự động truy xuất snapshot, RAG kiến thức, sinh kịch bản bằng Groq LLM API và kiểm duyệt an toàn quảng cáo sang trạng thái `AWAITING_APPROVAL`.

### F. Có tạo được video AI thật chưa?
- **ĐÃ TẠO THẬT.** Kịch bản sau khi được duyệt đã kích hoạt Celery worker:
  - Sinh giọng đọc tiếng Việt bằng Piper TTS (`voice_...wav`, 8.35s).
  - Ghép phụ đề SRT và card sản phẩm.
  - Render và assemble thành file video thật: `/app/storage/programs/campaign_6aca7822dec4b62c4301b1bf.mp4` dung lượng **244,284 bytes**.

### G. Có phát được video trên website chưa?
- **ĐÃ KIỂM CHỨNG.** Endpoint streaming `/index.php?r=admin_ai_idol_stream_campaign&campaign_id=...` trả về HTTP 200 `video/mp4` với `Accept-Ranges: bytes`, sẵn sàng phát trên thẻ `<video>` của Studio.

### H. Những dependency nào còn thiếu?
1. **Syna 3D Mascot Renderer (`syna_3d`):** Cần tiến trình render native trên máy chủ Windows (`scripts/start-ai-idol-syna.ps1` lắng nghe cổng 7862).
2. **GPU Video Lip-Sync Server:** Cần máy chủ GPU chuyên dụng (`http://host.docker.internal:7861`) nếu muốn dùng mô hình SadTalker/MuseTalk thay cho cơ chế ghép video chuẩn của hệ thống.

---

## 3. Checklist Kết Quả Kiểm Thử Cuối Cùng (End-to-End Checklist)

| STT | Hạng mục kiểm tra | Kết quả | Ghi chú |
| :---: | :--- | :---: | :--- |
| 1 | AI Idol Service healthcheck | **PASS** | HTTP 200 OK |
| 2 | PHP container gọi được service | **PASS** | cURL proxy thông suốt |
| 3 | Agent tải trạng thái được | **PASS** | Tải danh sách Agent runs |
| 4 | Wizard gửi yêu cầu tạo kịch bản thành công | **PASS** | HTTP 202 `created: true` |
| 5 | Kịch bản được lưu và đọc lại | **PASS** | Lưu trong `ai_idol_scripts` |
| 6 | Thư viện hiển thị chương trình | **PASS** | Danh sách cập nhật đầy đủ |
| 7 | Có thể duyệt kịch bản | **PASS** | Chuyển trạng thái sang `APPROVED` |
| 8 | Render job được tạo | **PASS** | Celery task `generate_job` thực thi |
| 9 | Video được tạo thành file thật | **PASS** | File MP4 244 KB tại `/app/storage` |
| 10 | Video có thể phát trên website | **PASS** | HTTP 200 Stream byte-range |

---

**FINAL VERDICT: `AI IDOL SERVICE RECOVERY — PASS`**
