# U07 — DỮ LIỆU CÔNG DỤNG: ADMIN NHẬP TAY + LLM GỢI Ý CÓ DUYỆT

## Vai trò
Kỹ sư fullstack + AI. Công dụng (benefit) và vấn đề da (concern) hiện 100% suy luận từ thành phần. Thêm nguồn dữ liệu có người chịu trách nhiệm.

## Đọc trước
`u00-common-rules.md` → handoff → trang admin sửa sản phẩm (rg route admin sản phẩm trong `QuanTriController`/view admin), `docs/neo4j/schema.md` (thuộc tính `source` trên quan hệ), `graph_sync/{transform,rules}.py`, danh sách key benefit/concern chuẩn trong `docs/plans/smart-search-handoff.md` (mục Bước 03).

## Thiết kế dữ liệu (Mongo — ghi được phép: CHỈ các trường mới dưới đây trên `san_pham`)
```
cong_dung_keys:   [string]   // key benefit chuẩn, vd ["cap_am","lam_diu"]
van_de_da_keys:   [string]   // key concern chuẩn
loai_da_keys:     [string]   // tùy chọn, bổ sung ngoài ma_loai_da
tag_source:       "manual" | "llm_approved"
tag_updated_at:   Date
tag_updated_by:   string     // id/email admin dạng hash, không lưu email thô nếu có thể
```
Collection mới `tag_suggestions` (ghi được phép): `{ma_san_pham, suggested:{benefits,concerns,skin_types}, evidence:[string], model, created_at, status:"pending"|"approved"|"rejected", reviewed_by, reviewed_at}`.

## Phần A — Admin nhập tay
- Trong form sửa sản phẩm admin: 3 nhóm checkbox (công dụng / vấn đề da / loại da) từ danh sách key chuẩn (đọc `backend/app/data/search_dictionary.json` của U04 hoặc file JSON key riêng xuất từ YAML). Hiển thị nhãn tiếng Việt.
- Lưu: validate key thuộc danh sách; set `tag_source="manual"`. CSRF, quyền admin như form hiện có. GitNexus impact cho hàm lưu sản phẩm.
- Khi lưu → publish sự kiện `catalog:changes:v1` (U03 Streams) `{type:"product_updated", ma_san_pham}` để U11 đồng bộ Neo4j/Chroma.

## Phần B — LLM gợi ý (một lần, có duyệt)
- Script `ai-service-flask/scripts/llm_tag_products.py --limit N --only-missing --dry-run`:
  - Input mỗi SP: tên, danh mục, 30 thành phần đầu, mô tả ngắn (cắt 800 ký tự). Không gửi dữ liệu khách hàng.
  - Dùng LLM pool có sẵn trong dự án (tìm `llm_pool`/cấu hình provider hiện dùng cho chatbot) — không thêm provider mới.
  - Prompt yêu cầu **chỉ chọn từ danh sách key cho sẵn**, trả JSON `{benefits:[], concerns:[], skin_types:[], evidence:[]}`; validate bằng schema, key lạ bị bỏ; tối đa 4 benefit.
  - So với suy luận rule hiện tại: lưu cả hai, đánh dấu chênh lệch.
  - Ghi vào `tag_suggestions` (status pending), có rate limit gọi LLM + retry/backoff, ghi chi phí/số token nếu provider trả.
  - Chạy thử 30 SP trước, in bảng để người dùng xem chất lượng; **chỉ chạy toàn bộ khi người dùng đồng ý** (ghi trong báo cáo, không tự chạy hết).
- Trang admin duyệt `index.php?r=admin_tag_review` (theo pattern route admin hiện có): danh sách pending, hiện đề xuất vs rule vs hiện tại, nút Duyệt/Từ chối/Sửa rồi duyệt (từng cái và hàng loạt). Duyệt → ghi trường ở trên với `tag_source="llm_approved"` + publish sự kiện.

## Phần C — ETL ưu tiên dữ liệu người
- `graph_sync/transform.py`: thứ tự ưu tiên `manual` > `llm_approved` > `rule`. Quan hệ ghi `source` tương ứng. Ranker có thể cộng nhẹ điểm cho `source in (manual, llm_approved)` — chỉ thêm hằng số trọng số, mặc định 0.05.

## Kiểm thử
- PHP unit: validate key, chống key lạ, CSRF bắt buộc.
- Python unit: parse/validate output LLM (JSON hỏng, key lạ, quá nhiều), ưu tiên nguồn trong transform.
- Chạy thật: dry-run LLM 5 SP (in kết quả); admin test (U01) sửa tag 1 SP qua Playwright MCP → Mongo có trường mới → sự kiện vào stream → (sau U11) Neo4j cập nhật; trong bước này chạy tay `graph_sync --since` để xác nhận quan hệ `source=manual`.

## Đầu ra
Mục "U07" trong handoff: schema trường mới, route admin, lệnh LLM, kết quả mẫu 30 SP, chờ người dùng quyết chạy toàn bộ. Không commit.
