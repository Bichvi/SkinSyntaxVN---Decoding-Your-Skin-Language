# U12 — NÂNG CAO CÓ CỔNG ĐIỀU KIỆN: CO-PURCHASE, CTR BOOST, A/B TEST, CROSS-ENCODER

## Vai trò
Kỹ sư ML/thử nghiệm. Xây **hạ tầng** để bật các tối ưu dựa trên hành vi khi đủ dữ liệu — KHÔNG bật khi dữ liệu chưa đủ.

## Đọc trước
`u00-common-rules.md` → handoff → `search_service/{ranker,service,config,metrics}.py`, `scripts/search_metrics.py`, `graph_sync/co_purchase.py`.

## Cổng điều kiện (kiểm trước, ghi số liệu thật vào báo cáo)
| Tính năng | Điều kiện bật | Nếu chưa đạt |
|---|---|---|
| Co-purchase vào xếp hạng | ≥ 200 cặp `CO_PURCHASED` có count ≥ 2 | Giữ flag `false`, chỉ làm hạ tầng + test |
| CTR boost | ≥ 5.000 impression và ≥ 200 click trong 30 ngày (bỏ test account) | Giữ flag `false` |
| A/B test | Có ≥ 1.000 lượt tìm/tuần | Làm framework, để thí nghiệm ở trạng thái `draft` |
| Cross-encoder | p95 rerank 40 cặp ≤ 120ms trên phần cứng hiện tại | Giữ tắt; ghi số đo |

## Phần A — CTR boost
- Job (đăng ký lịch U11, 05:00 hằng đêm): tổng hợp `search_events` 30 ngày theo `(section, product_id)` → CTR làm mượt Bayes: `ctr = (clicks + α) / (impressions + α + β)` với prior từ CTR trung bình section (α,β tính từ dữ liệu). Lưu Redis Hash `search:ctr:v1:<section>` (TTL 2 ngày).
- Ranker: `score += w_ctr * (ctr_norm − 0.5)` với `w_ctr = 0.08`, flag `SEARCH_CTR_BOOST_ENABLED` (mặc định false). Chỉ áp cho SP có ≥ 50 impression.
- Lưu ý vị trí (position bias): nếu event có `position` thì dùng trọng số chuẩn hóa theo vị trí; nếu chưa có, **thêm `position`** vào beacon (PHP/JS U03) — ghi rõ.

## Phần B — Framework A/B
- Gán nhóm ổn định: `bucket = sha256(session_hash + experiment_id) % 100`; cấu hình thí nghiệm YAML `ai-service-flask/data/experiments.yaml` (`id, status: draft|running|stopped, traffic: {control: 50, variant: 50}, overrides: {weights.graph: 0.45, ...}`).
- Flask: request recommend nhận `session_hash` (PHP gửi, đã băm) → chọn biến thể → áp overrides trọng số → trả `experiment: {id, variant}` (thêm trường tùy chọn vào contract, tương thích ngược). Cache key phải chứa variant.
- PHP: truyền `experiment/variant` vào data-attribute → beacon ghi kèm.
- Báo cáo `search_metrics.py --experiment <id>`: CTR, add-to-cart rate theo variant, khoảng tin cậy 95% (Wilson cho tỷ lệ), khuyến nghị "chưa đủ mẫu" nếu < 1.000 impression/variant.
- Tạo 1 thí nghiệm mẫu ở trạng thái `draft` (vd tăng `graph` 0.35→0.45, giảm `semantic`).

## Phần C — Co-purchase
- Kiểm cổng. Nếu đạt: bật `SEARCH_CO_PURCHASE_ENABLED=true` qua compose, đo trước/sau bằng A/B (Phần B) thay vì bật toàn bộ. Nếu chưa đạt: giữ false, ghi số cặp hiện tại và dự kiến khi nào đạt (theo tốc độ đơn hiện tại).

## Phần D — Cross-encoder
- Benchmark: 40 cặp, batch, `torch.set_num_threads(2|4)`, thử model nhỏ hơn đa ngôn ngữ nếu có sẵn trong cache HF (không tải model lớn mới nếu không cần), ONNX/quantize int8 nếu khả thi trong thời gian hợp lý. Đo p50/p95 trong container.
- Nếu đạt ngưỡng: bật cho **top 20** (không phải 40), chạy trong executor có timeout cứng, không chạy trong luồng request nếu vượt ngân sách. Nếu không đạt: giữ tắt, ghi bảng số đo.

## Kiểm thử
- Unit: CTR Bayes, chuẩn hóa vị trí, bucket ổn định & phân phối đều (10k session giả → 50±2%), overrides trọng số, Wilson interval, cache key có variant.
- Chạy thật: job CTR một lần (in số liệu), recommend trả `experiment` khi thí nghiệm `running` (bật tạm thí nghiệm test trong môi trường dev rồi đặt lại `draft`), report experiment chạy được.

## Đầu ra
Mục "U12" trong handoff: bảng cổng điều kiện với số thật, flag nào bật/tắt và vì sao, cách chạy thí nghiệm. Không commit.
