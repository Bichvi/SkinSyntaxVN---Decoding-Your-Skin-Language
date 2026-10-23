# U12 - Tính năng nâng cao có cổng điều kiện

Nguyên tắc: xây hạ tầng + test, chỉ BẬT khi số liệu thật đạt cổng. Ngày kiểm: 2026-10-23.

## Cổng điều kiện (số thật)

| Tính năng | Điều kiện | Số đo | Kết quả | Cờ |
|---|---|---|---|---|
| Co-purchase vào xếp hạng | >= 200 cặp `CO_PURCHASED` có count >= 2 | 2 cặp (4 cạnh có hướng) trên 961 cặp khác nhau; 495/2109 đơn hoàn thành có >= 2 SP | CHƯA ĐẠT | `SEARCH_CO_PURCHASE_ENABLED=false` |
| CTR boost | >= 5.000 impression và >= 200 click trong 30 ngày (bỏ test) | 8 impression, 1 click (đã loại 72 sự kiện test) | CHƯA ĐẠT | `SEARCH_CTR_BOOST_ENABLED=false` |
| A/B test | >= 1.000 lượt tìm/tuần | 269 lượt tìm không-live trong 7 ngày (tuần trước đó 25, 12, 3), phần lớn do kiểm thử | CHƯA ĐẠT | thí nghiệm mẫu `draft` |
| Cross-encoder | p95 rerank 40 cặp <= 120 ms | tốt nhất p95 305 ms (int8, 4 luồng); fp32 633 ms | CHƯA ĐẠT | `SEARCH_RERANK_ENABLED=false` |

## Đã làm

- **CTR** (`search_service/ctr.py`, job `ctr_boost` 05:00 trong `workers/jobs.py`): CTR làm mượt Bayes, prior Beta theo từng section (moment), chuẩn hóa vị trí bằng "độ phơi sáng" `1/(1+log2(vị trí))`, lưu Redis Hash `search:ctr:v1:<section>` (TTL 2 ngày). Ranker `combine(ctr=, w_ctr=0.08)`: `score += w_ctr*(ctr_norm-0.5)`, chỉ SP >= 50 impression. Dùng đúng bộ lọc dữ liệu test của U11.
- **Beacon**: impression gửi thêm `product_ids` (thứ tự hiển thị), click/add_to_cart gửi `position`, mọi sự kiện kèm `experiment`/`variant` khi có thí nghiệm. Trước U12 impression không có sản phẩm nên không thể tính CTR theo sản phẩm; dữ liệu CTR theo SP chỉ bắt đầu tích lũy từ khi triển khai bản này.
- **A/B** (`search_service/experiments.py`, `data/experiments.yaml`): bucket `sha256(session_hash+id)%100`, override trọng số và công tắc `co_purchase`/`ctr_boost` theo nhánh, cache key chứa variant, response có `experiment {id, variant}` (tùy chọn trong contract), báo cáo `search_metrics.py --experiment <id>` (Wilson 95%, p-value, "Chưa đủ mẫu" dưới 1.000 impression/nhánh).
- **Cross-encoder**: `scripts/bench_cross_encoder.py`; `SEARCH_RERANK_TOP_N` mặc định đổi 40 -> 20 (chỉ có tác dụng khi cờ bật).

## Vận hành

Xem `docs/ops/search-ops.md` mục 9. Test: `ai-service-flask/tests/search_service/test_{ctr,experiments}.py`, `tests/redis_kit/test_flusher.py`, PHP `SearchEventTest`, `SearchFallbackTest`.
