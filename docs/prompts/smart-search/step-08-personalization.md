# BƯỚC 08 — CÁ NHÂN HÓA, "THƯỜNG MUA CÙNG" VÀ ĐO LƯỜNG

## Vai trò
Kỹ sư dữ liệu/ML. Ghi nhận hành vi trên các nhóm gợi ý, tạo quan hệ "thường mua cùng" trong Neo4j, đưa vào xếp hạng (có cờ bật/tắt), và viết báo cáo chỉ số.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (Bước 03: lệnh sync; Bước 05: ranker, trọng số, flag; Bước 07: data-attribute tracking, endpoint log)
3. `ai-service-flask/search_service/ranker.py`, `branches.py`, `config.py`
4. `ai-service-flask/graph_sync/` (CLI, loader)
5. Nơi lưu log tương tác hiện có (`InteractionLogger` PHP) và collection đơn hàng (theo audit).

## Yêu cầu

### 1. Ghi sự kiện tìm kiếm
- Collection Mongo `search_events` (tạo mới — **hỏi người dùng trước** nếu handoff chưa ghi đồng ý tạo collection mới; nếu đã có collection log tương tác phù hợp thì dùng lại với `type` mới).
- Document: `{ts: Date, q_norm: string(≤100), section: string, product_id: string, action: 'impression'|'click'|'add_to_cart'|'chip_click', user_hash: string|null, session_hash: string}`. **Không** lưu email, IP thô, hồ sơ da.
- PHP endpoint nhận beacon (dùng lại endpoint log tương tác có sẵn nếu được): validate enum/độ dài, rate limit theo session, trả 204.
- Impression: ghi 1 lần/section/trang (không ghi từng card).
- Index: `{ts:-1}`, `{q_norm:1, ts:-1}`; TTL 180 ngày trên `ts` (hỏi người dùng nếu chưa có chính sách lưu trữ).

### 2. Quan hệ CO_PURCHASED
- Thêm vào `graph_sync`: `python -m graph_sync --derive co_purchase --days 90`.
- Đọc đơn hàng thành công 90 ngày (chỉ đọc), đếm cặp SP xuất hiện cùng đơn (bỏ đơn > 20 item), giữ cặp `count ≥ 2`; với mỗi SP giữ top 20 theo count.
- Ghi `(:Product)-[:CO_PURCHASED {count, updated_at}]->(:Product)` hai chiều, xóa quan hệ cũ trước. Batch 500, tham số hóa, dùng Redis lock như sync.

### 3. Đưa vào xếp hạng
- `branches.py`: thêm thành phần `bought_together` cho các SP xuất hiện trong kết quả + SP trong `cart_ids`: điểm = log1p(count) chuẩn hóa.
- `ranker.py`: `score += w_cop · bought_together`, `w_cop = 0.10` trong config; flag `SEARCH_CO_PURCHASE_ENABLED` (mặc định `false`).
- Nếu SP được nâng điểm chủ yếu nhờ CO_PURCHASED → `reason_code='bought_together'`.
- (Tùy chọn, flag riêng `SEARCH_CTR_BOOST_ENABLED`) cộng nhẹ theo CTR 30 ngày của SP trong section — cache Redis 1 giờ.

### 4. Báo cáo chỉ số — `ai-service-flask/scripts/search_metrics.py`
Tham số `--days 7`. In bảng + xuất CSV vào `report/search-metrics/`:
- **Zero-result rate** = số lượt tìm có kết quả chính xác = 0 / tổng lượt tìm (nguồn: log tìm kiếm có sẵn; nếu log chưa có số kết quả → thêm trường `result_count` vào log tìm kiếm ở PHP, ghi rõ).
- **Fallback CTR** theo section = click / impression.
- **Add-to-cart sau fallback** = add_to_cart / impression.
- Top 20 truy vấn zero-result (q_norm, số lần) — dùng để bổ sung synonym/dữ liệu.

## Quy trình
`git status`; GitNexus impact cho hàm có sẵn bị sửa (`InteractionLogger`, ranker...); TDD cho đếm cặp, top-k, công thức điểm, validate sự kiện.

## Kiểm thử
- pytest: đếm cặp (đơn trùng SP, đơn quá lớn bị bỏ), ngưỡng, top-k; ranker có/không flag; reason_code bought_together.
- PHP: test thuần validate payload sự kiện. Không chạy script cũ trong `backend/tests/`.

## Kiểm chứng
- `python -m graph_sync --derive co_purchase --days 90` → số quan hệ tạo; chạy lại → không đổi.
- Bật flag, gọi `/api/search/recommend` với `cart_ids` thật → có thể thấy `bought_together`; tắt flag → kết quả như cũ.
- Thao tác thật trên trang (impression, click, thêm giỏ) → `search_events` có document đúng shape.
- `python scripts/search_metrics.py --days 7` → bảng chỉ số.

## Đầu ra
Code, test, script báo cáo, CSV mẫu. Mục "Bước 08" trong handoff: collection/index mới, flag, lệnh job, số liệu chỉ số ban đầu. Không commit nếu chưa được yêu cầu.
