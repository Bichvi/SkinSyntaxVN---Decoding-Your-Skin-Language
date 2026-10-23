# U06 — CHẤT LƯỢNG DỮ LIỆU: ALIAS INCI + VÒNG LẶP TỪ KHÓA 0 KẾT QUẢ

## Vai trò
Kỹ sư dữ liệu. Tăng tỷ lệ map thành phần sang INCI (hiện ~54% lượt) và biến log tìm kiếm thành nguồn bổ sung từ đồng nghĩa có người duyệt.

## Đọc trước
`u00-common-rules.md` → handoff → `ai-service-flask/graph_sync/{ingredients,normalize,report}.py`, `ai-service-flask/data/graph/*.yaml`, `ai-service-flask/scripts/search_metrics.py`, `ai-service-flask/search_service/metrics.py`.

## Phần A — Alias INCI
1. Chạy `python -m graph_sync --dry-run` (trong container) lấy **top 300 thành phần chưa map** (tên + số lần). Ghi baseline: % lượt map, % SP có ≥1 hoạt chất `role=active`.
2. Phân loại có quy tắc, KHÔNG đoán bừa:
   - Biến thể chính tả/ dấu câu/ hoa thường/ khoảng trắng của INCI đã có → alias.
   - Tên tiếng Việt/tên thương mại phổ biến (vd "rau má" → centella_asiatica_extract, "nước tinh khiết" → water) → alias.
   - Tá dược (iron oxides, disteardimonium hectorite…) → thêm INCI mới với `role` đúng (`colorant`, `thickener`…) để không còn "không map".
   - Chuỗi rác (mô tả, %, "và", câu dài) → cải thiện `parse_ingredients` (tách tốt hơn) thay vì alias.
   - Trường hợp mơ hồ → đưa vào `ai-service-flask/data/graph/ingredient_review.csv` (tên, số lần, đề xuất, lý do) để người dùng duyệt; KHÔNG đưa vào alias.
3. Viết script hỗ trợ `ai-service-flask/scripts/suggest_ingredient_aliases.py` dùng `rapidfuzz` so với danh sách INCI đã biết (ngưỡng ≥ 92 mới đề xuất, vẫn cần review), xuất CSV — dùng để làm nhanh bước 2, không tự ghi YAML.
4. Chạy `--full` + `--similarity-only` sau khi cập nhật YAML; idempotent; theo dõi RAM Neo4j (1g).
5. Mục tiêu: lượt map ≥ 75%; nếu không đạt, giải thích phần còn lại là gì.

## Phần B — Vòng lặp từ khóa 0 kết quả → synonym (có duyệt)
1. Mở rộng `search_metrics.py`: lệnh `--zero-result-report --days 7 --min-count 2` xuất `report/search-metrics/zero_results_<date>.csv`: q_norm, số lần, intent parse (brands/categories/residual), gợi ý tự động:
   - residual gần (rapidfuzz ≥ 88) với synonym/brand/category đã có → đề xuất alias.
   - Có kết quả semantic tốt (top-1 score cao) → đề xuất danh mục.
   - Còn lại → "cần người xem".
   Loại tài khoản test (`is_test_account`) và q có dạng PII.
2. Quy trình duyệt: CSV có cột `approve` (y/n) + `target_key`; script `apply_synonym_suggestions.py --file <csv>` chỉ áp dòng `approve=y` vào `synonyms.yaml` (giữ comment/thứ tự, kiểm không trùng nhóm bằng test có sẵn), sau đó in lệnh cần chạy lại (export dictionary U04, reload dictionary Flask — Pub/Sub invalidate U03).
3. Lịch chạy báo cáo hằng tuần do U11 đảm nhận — ở đây chỉ làm script + tài liệu.

## Kiểm thử
- Unit: parse_ingredients với các mẫu chuỗi rác mới; suggest alias ngưỡng; apply_synonym không phá YAML, từ chối trùng nhóm; zero-result report lọc PII/test account.
- Chạy thật: dry-run trước/sau (bảng số liệu), `--full` 2 lần giống nhau, 5 truy vấn intent đại diện vẫn đúng (`torriden sữa rửa mặt`, `srm cho da dầu mụn`, `serum vitamin c`…). pytest toàn bộ.

## Đầu ra
YAML cập nhật, CSV review, scripts, mục "U06" trong handoff (số liệu trước/sau, quy trình duyệt). Không commit.
