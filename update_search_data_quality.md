# Chất lượng dữ liệu tìm kiếm: alias INCI và vòng lặp từ khóa 0 kết quả (U06)

Cập nhật 2026-10-22, chưa commit. Phạm vi: `ai-service-flask` (ETL `graph_sync`, `search_service`, scripts) và dữ liệu YAML. Không đụng PHP, không ghi MongoDB.

## 1. Kết quả đo (2853 sản phẩm đang bán, đo bằng `scripts/ingredient_coverage.py`)

| Bước | Lượt thành phần | Lượt map INCI | % lượt map | % SP có >=1 hoạt chất (`role=active`) |
|---|---|---|---|---|
| Trước U06 | 50.408 | 27.294 | 54,15% | 77,43% |
| Chỉ sửa `parse_ingredients` | 67.026 | 39.048 | 58,26% | 85,77% |
| + lô alias 1 (558 mục) | 66.967 | 56.132 | 83,82% | 88,82% |
| + lô alias 2 (218 mục) | 67.213 | 59.045 | 87,85% | 89,98% |
| Cuối (thêm 77 sửa lỗi gõ, alias tiếng Việt, bộ lọc rác) | 67.165 | 59.463 | **88,53%** | **90,19%** |

Mẫu số tăng vì parser nay lấy được danh sách INCI thật nằm sau "Thành phần chi tiết:" (trước đây bị bỏ, chỉ lấy tiêu đề gạch đầu dòng). Phần còn lại (11,5%) là 3.184 tên riêng lẻ, mỗi tên dưới 12 lượt: INCI hiếm, chiết xuất thực vật hiếm, tên thương mại và chuỗi hỏng. Các ca mơ hồ nằm trong `ai-service-flask/data/graph/ingredient_review.csv` (64 dòng) chờ người duyệt, KHÔNG nằm trong alias.

## 2. Phần A: đã làm gì

- `graph_sync/ingredients.py`: tách khối marketing (bỏ "Thành phần chính" + tiêu đề sản phẩm đánh số, lấy "Thành phần chi tiết/đầy đủ"); nối lại tên bị tách do dấu gạch thành dấu phẩy ("peg, 18 methyl ether" -> "PEG-18 ..."); tách thêm theo `&`, "và", " . ", ". "; bỏ số thập phân đuôi ("Folic Acid 0.01"); bỏ tiền tố kích cỡ ("25g Aqua"); sửa chữ Cyrillic giả dạng Latin; bỏ mảnh rác ("Copolymer", "Leaf"...) và câu mô tả tiếng Việt; tra alias dự phòng (số đuôi, biến thể khoảng trắng/dấu câu qua chỉ mục "compact", chỉ nhận khi không mơ hồ).
- `data/graph/ingredient_aliases.yaml`: 776 mục INCI mới + hơn 150 alias bổ sung vào mục có sẵn. Vai trò mới `colorant`, `thickener`, `emulsifier` (đã thêm vào `GENERIC_ROLES` của `search_service/graph_repository.py` để không bị coi là "hoạt chất dùng chung").
- `graph_sync/load.py` + `__main__.py`: `--full` nay xóa nút `Ingredient` mồ côi (không còn sản phẩm chứa và không có quan hệ khác); 1.183 nút cũ bị dọn ở lần chạy đầu, lần chạy sau là 0.
- Công cụ: `scripts/ingredient_coverage.py` (số liệu + CSV tên chưa map), `scripts/suggest_ingredient_aliases.py` (rapidfuzz >= 92, bắt buộc cùng dãy chữ số, xuất CSV để duyệt, không tự ghi YAML).
- Graph sau `--full`: Ingredient 4.153 (3.184 chưa map), CONTAINS 67.165, SIMILAR_TO 28.525; chạy 2 lần cho cùng số liệu.

## 3. Phần B: vòng lặp từ khóa 0 kết quả -> synonym (có người duyệt)

1. Báo cáo: `MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/search_metrics.py --zero-result-report --days 7 --min-count 2 --out /tmp/sm`, sau đó `docker cp` ra `report/search-metrics/zero_results_<ngày>.csv`. Loại: truy vấn trông như SĐT/email/URL, `u03verify*`, tài khoản test (`ma_kh` của `khach_hang.is_test_account` và các truy vấn mà `user_hash` của tài khoản test đã tìm trong `search_events`), tìm kiếm live-filter.
2. Mỗi dòng có phân tích ý định (hãng, danh mục, thành phần, phần dư) và gợi ý: `alias` (phần dư gần một synonym có sẵn, rapidfuzz >= 88), `category` (Chroma đồng thuận >= 3/4 kết quả đầu cùng danh mục và distance <= 1,1), hoặc `need_review`.
3. Người duyệt điền cột `approve` = y (có thể sửa `alias`, `target_group`, `target_key`), rồi chạy `.venv/Scripts/python.exe ai-service-flask/scripts/apply_synonym_suggestions.py --file <csv> [--dry-run]`. Script chỉ áp dòng `approve=y`, giữ nguyên comment và thứ tự trong `synonyms.yaml`, từ chối alias đã thuộc khóa khác/nhóm khác, khóa không tồn tại hoặc quá ngắn; alias thành phần/hãng không xử lý ở đây.
4. Sau khi áp: chạy `pytest ai-service-flask/tests/graph_sync/test_normalize.py ai-service-flask/tests/search_service -q`, xuất lại từ điển cho PHP (`export_search_dictionary.py`), rồi báo Flask nạp lại bằng `publish_invalidate('dictionary')` (không cần restart; handler mới xóa cache YAML trong tiến trình).
5. Lịch chạy báo cáo hằng tuần do U11 phụ trách. Dữ liệu thật hiện rất ít (51 dòng 0 kết quả từ trước tới nay, phần lớn là truy vấn thử của các bước trước), nên chưa có dòng nào được áp vào `synonyms.yaml` thật.

## 4. File

Sửa: `graph_sync/{ingredients,load,normalize,__main__}.py`, `data/graph/ingredient_aliases.yaml`, `search_service/{graph_repository,service}.py`, `scripts/search_metrics.py`, `tests/search_service/test_intent_parser.py` (một kỳ vọng: "chiết xuất hoa sen" nay ra khóa INCI). Mới: `search_service/zero_results.py`, `scripts/{ingredient_coverage,suggest_ingredient_aliases,apply_synonym_suggestions}.py`, `data/graph/ingredient_review.csv`, test `tests/graph_sync/{test_suggest_aliases,test_ingredient_quality}.py`, `tests/search_service/test_zero_results.py`.
