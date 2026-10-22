# Chất lượng kịch bản AI Idol (Syna): sửa prompt (2026-10-22, chưa commit)

## Vấn đề

1. `fit_message_pair` (gọi trong `LLMProvider.invoke`) chạy `clean_text`, biến mọi xuống dòng trong prompt thành dấu cách. Model nhận cấu trúc 7 phần dưới dạng một khối chữ liền nên cũng viết `[Hook] nội dung` trên cùng một dòng. `split_labeled_sections` không nhận ra các phần đó, dẫn tới lỗi `MISSING_SECTION` và phải gọi LLM sửa thêm.
2. Prompt video sản phẩm dài khoảng 8.500–9.000 ký tự, vượt ngân sách 8.000 (`AI_IDOL_GROQ_MAX_PROMPT_CHARS`). Hàm `clip_text` cắt **phần giữa** prompt, đúng chỗ chứa mô tả công dụng và tài liệu RAG.
3. Prompt chỉ là checklist kèm nhiều lệnh cấm, không có câu mẫu, nên kịch bản ra nhạt và sáo ("Bạn có biết", "Hãy gặp", "Tóm lại", lặp thông số).

## Thay đổi

| File | Thay đổi |
| --- | --- |
| `ai-idol-service/core/text_budget.py` | Thêm `clean_prompt_text` / `clip_prompt_text` (giữ xuống dòng). `fit_message_pair` dùng hai hàm này, công thức ngân sách giữ nguyên. `clean_text` / `clip_text` **không đổi** (vẫn dùng cho trường dữ liệu DB). |
| `ai-idol-service/engines/content_agent.py` | `build_script_prompts` (product_video, live_and_product) chọn mức giới hạn dữ liệu theo "bậc thang": thu nhỏ RAG trước, dữ kiện sản phẩm sau cùng, để toàn prompt ≤ ngân sách − 200 và không bị cắt giữa. System prompt chuyển từ checklist sang **nguyên tắc kể chuyện**: Hook là một khoảnh khắc đời thường, mỗi phần có câu dẫn sang phần sau, mỗi hoạt chất kèm hình ảnh so sánh, tên đầy đủ sản phẩm chỉ nói một lần, có danh sách câu sáo cần tránh. Thêm **đoạn mẫu giọng văn** (few-shot, sản phẩm giả định): `_PRODUCT_VIDEO_SAMPLE`, `_LIVE_AND_PRODUCT_SAMPLE`. |
| `ai-idol-service/tests/test_script_prompt_quality.py` | 7 test mới: giữ xuống dòng, không cắt giữa với sản phẩm có dữ liệu rất dài, prompt có đủ tiêu đề phần và đoạn mẫu. |

Không đổi: `_live_only_prompts` (khóa bằng fingerprint sha256), `_script_council_payload`, validator, repair.

Mẫu livestream không dùng "chốt đơn" / "hôm nay", vì `core/script_text.py` coi đó là từ tạo khan hiếm (`SCARCITY_UNVERIFIED`).

## Kiểm chứng

- `cd ai-idol-service && ../.venv/Scripts/python.exe -m pytest tests/test_text_budget.py tests/test_script_purpose.py tests/test_narration.py tests/test_price_rules.py tests/test_script_sections.py tests/test_script_repair.py tests/test_script_prompt_quality.py -q`: **87 passed, 15 subtests passed**.
- So sánh trước/sau trên sản phẩm thật (98 Obagi Retinol, 101 Beplain chống nắng, 68 Timeless B5), model `openai/gpt-oss-20b`. Harness chỉ đọc dữ liệu (không tạo chiến dịch, không ghi DB): `report/ai-idol-script-quality/compare_scripts.py`. Kết quả ở `before/` và `after/`.

| Ca | Prompt gửi đi trước → sau | Số phần tách được trước → sau | Lỗi kiểm tra tự động trước → sau |
| --- | --- | --- | --- |
| 98 video sản phẩm | 8.996 bị cắt còn 8.000 → 7.579, không cắt | 0/7 → 7/7 | 1 → 0 |
| 101 video sản phẩm | 8.455 bị cắt còn 8.000 → 7.791 | 0/7 → 7/7 | 2 → 0 |
| 68 video sản phẩm | 8.582 bị cắt còn 8.000 → 7.518 | 7/7 → 7/7 | 2 → 0 |
| 101 livestream | 6.954 → 7.401 | 0/8 → 8/8 | 1 → 1 ("live hôm nay"; bước repair của pipeline xử lý) |

Ghi chú: cờ `clipped` trong `summary.json` của lượt "sau" vẫn là `true`. Cờ này đến từ việc rút gọn từng trường dữ liệu (cố ý, có từ trước), không phải do toàn prompt bị cắt giữa.

E2E NOT RUN: chưa chạy pipeline thật qua TTS/video. Harness chạy bằng code local, copy vào `/tmp` của container `skinsyntax-ai-idol` để dùng key Groq; image đang chạy **chưa được build lại**.

## Còn lại / hướng tiếp

- Giọng văn đã tốt hơn (Hook là cảnh cụ thể, Wrap-up quay về Hook), nhưng `gpt-oss-20b` vẫn còn câu sáo ("Đừng bỏ lỡ cơ hội trải nghiệm"), có khi viết chữ số ("15 phút"), và có khi chép ý từ đoạn mẫu. Đòn bẩy tiếp theo: sinh 2–3 bản rồi cho LLM chấm và chọn, hoặc dùng model lớn hơn riêng cho bước viết.
- Groq free tier giới hạn 8.000 token/phút. Prompt khoảng 2.500–3.200 token, nên chạy liên tiếp dễ gặp lỗi 429.
- Prompt sửa lỗi (`script_repair.py`) chưa yêu cầu giữ giọng Syna.
- Script Council (CrewAI, khi `agent_managed`) dùng prompt riêng, chưa được cập nhật.

## Bước 4: sinh nhiều bản, LLM chấm và chọn (2026-10-22, chưa commit)

### Thay đổi

| File | Thay đổi |
| --- | --- |
| `ai-idol-service/engines/script_selector.py` (mới) | `select_best_script` sinh N bản. Bản thứ i bắt đầu từ model thứ i trong danh sách Groq (đổi giọng, chia tải). Bản thiếu phần hoặc dính lỗi kiểm tra cứng (`_deterministic_issues`) bị loại. Các bản còn lại đấu từng cặp, do LLM giám khảo chấm 4 tiêu chí: hook, flow, natural, faithful. Code tự quyết người thắng: tổng = hook + flow + natural + 2 × faithful; bản nào faithful dưới 6 thì thua. Giám khảo lỗi thì chọn bản có ít câu sáo và chữ số hơn. |
| `ai-idol-service/config.py` | `AI_IDOL_SCRIPT_CANDIDATES` (1–3, mặc định 3). Đặt bằng 1 để quay về sinh một bản như cũ. |
| `ai-idol-service/engines/content_agent.py` | Luồng không qua Script Council gọi `select_best_script` khi N > 1. Chữ ký và kiểu trả về không đổi. |
| `ai-idol-service/tests/test_script_selector.py` (mới) | 9 test (mock LLM). |
| `tests/test_script_purpose.py`, `tests/test_agent.py` | Mỗi file thêm một dòng patch `SCRIPT_CANDIDATES = 1`, vì hai test cũ này mock `LLMProvider` của luồng sinh một bản. |

Impact (GitNexus): `generate_content_and_script` rủi ro LOW, chỉ được `run_ai_idol_pipeline` gọi.

### Kiểm chứng

- Pytest (10 file liên quan, gồm `test_e2e_flow.py`, `test_agent.py`): **151 passed, 15 subtests passed**.
- Harness: `compare_scripts.py best` (thêm tham số lọc một ca, ví dụ `best_101 101:product_video`). Kết quả ở `report/ai-idol-script-quality/best/`, mỗi bản ứng viên lưu ở file `*_candN.md`.

| Ca | Ứng viên (lỗi cứng / điểm phạt / số từ) | Chọn | Nhận xét khi đọc so với bản đơn ở `after/` |
| --- | --- | --- | --- |
| 98 video | bản 0 loại (thiếu How to Use, "hôm nay") · bản 1: 0 / 3 / 448 · bản 2: 0 / 4 / 196 | bản 1 (giám khảo cho 39 so với 37 điểm) | **Tốt hơn rõ**: Hook cụ thể (tối muộn, da khô căng), ẩn dụ "kiến trúc sư tái thiết", Wrap-up quay về Hook. Còn "0.5 phần trăm" và "lấp đầy rãnh nhăn sâu" hơi hứa hẹn. |
| 101 video | bản 0 loại (kể trải nghiệm cá nhân) · bản 1: 0 / 2 / 381 · bản 2: 0 / 4 / 303 | bản 2 (faithful 8 so với 7) | **Gần như không khá hơn**: Hook chung chung, còn "15 phút" và câu sáo "Hãy thử… trải nghiệm…". Giám khảo chỉ cho hook 5–6 điểm, tức cả ba bản đều yếu. |
| 68 video | 3 bản sạch | bản 0, thắng cả 2 cặp | Giám khảo bắt được lỗi chính tả "Timeles" và công dụng "sáng hơn" không có trong dữ liệu ở bản thua. |
| 101 livestream | 3 bản sạch | bản 2 (flow và natural cao hơn) | Hết lỗi "hôm nay" mà bản đơn ở `after/` từng có. |

Lỗi kiểm tra tự động ở 4 bản được chọn: 0 (bản đơn ở `after/`: 1). Bộ lọc luật đã loại 2 bản hỏng (thiếu phần, kể trải nghiệm cá nhân) mà khi chỉ sinh một bản sẽ phải đưa sang bước sửa.

### Chi phí — cần lưu ý

- Mỗi kịch bản tốn khoảng 3 lượt sinh + 2 lượt chấm, tức **khoảng 4–5 lần token** so với trước, và chậm thêm 30–90 giây.
- Groq free tier: `gpt-oss-20b` giới hạn **200.000 token/ngày** cho cả tổ chức. Lượt đo đầu tiên đã chạm giới hạn này: các lời gọi tự chuyển sang `qwen`, một ca chỉ sinh được một bản và phải chạy lại. Ở mặc định N=3, quota ngày đủ cho khoảng 12–15 kịch bản thay vì khoảng 60.
- Lượt đo đầu bị trộn model (khi gpt-oss hết quota, mọi lời gọi chuyển sang qwen), nên chưa kết luận được model nào viết hay hơn.

E2E NOT RUN; image `skinsyntax-ai-idol` chưa được build lại.

## Hạn mức Groq: thêm model, mặc định 2 bản (2026-10-22, chưa commit)

Groq tính hạn mức **theo tổ chức và theo từng model**, không theo key. Thêm key từ cùng tài khoản không tăng hạn mức; tạo nhiều tài khoản để cộng dồn hạn mức miễn phí là lách giới hạn, có nguy cơ bị khóa tài khoản, nên không làm. Cách hợp lệ: dùng thêm model trên cùng tài khoản.

Model chat tài khoản đang dùng được (kiểm tra 2026-10-22, mỗi model khoảng 1.000 lượt/ngày và 8.000 token/phút **tính riêng**): `openai/gpt-oss-20b`, `openai/gpt-oss-120b`, `qwen/qwen3.8-27b`. Có thêm `allam-2-7b`, nhưng đó là model tiếng Ả Rập nên bỏ qua.

| File | Thay đổi |
| --- | --- |
| `ai-idol-service/config.py` | Mặc định `AI_IDOL_GROQ_FALLBACK_MODELS` = `openai/gpt-oss-120b,qwen/qwen3.8-27b` (trước đây chỉ có qwen). Mặc định `AI_IDOL_SCRIPT_CANDIDATES` = **2** (trước là 3). |
| `.env.example` | Cập nhật hai biến trên kèm chú thích. |
| `report/ai-idol-script-quality/compare_scripts.py` | Chế độ `best` dùng N mặc định thay vì cố định 3. |

Khi sinh 2 bản, bản 1 bắt đầu từ `gpt-oss-20b`, bản 2 bắt đầu từ `gpt-oss-120b`. Khi một model hết hạn mức, hệ thống tự chuyển sang model tiếp theo trong danh sách.

Kiểm chứng: pytest 151 passed. Chạy thử `best2` trên 4 ca, không có lỗi 429; kết quả ở `report/ai-idol-script-quality/best2/`.

- Bản do `gpt-oss-120b` viết rõ ràng tốt hơn. Ví dụ 101 video: câu mở là cảnh cụ thể "bước ra quán cà phê"; số và ký hiệu đều viết bằng chữ ("SPF năm mươi cộng", "mười lăm phút"); có ẩn dụ đời thường; đoạn kết quay về câu mở.
- 2/4 ca cả hai bản đều dính lỗi cứng ("ngay hôm nay", "da mình"). Selector vẫn chọn bản tốt hơn, còn bước repair của pipeline sửa đúng đoạn lỗi. Với N=2, lưới an toàn mỏng hơn N=3.
