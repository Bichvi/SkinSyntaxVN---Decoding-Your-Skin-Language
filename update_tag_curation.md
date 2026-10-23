# Nhãn công dụng do người duyệt: admin nhập tay + AI gợi ý có duyệt (U07)

Cập nhật 2026-10-22, chưa commit. Trước U07, công dụng (benefit) và vấn đề da (concern) 100% suy luận từ thành phần theo `ingredient_rules.yaml`. U07 thêm nguồn dữ liệu có người chịu trách nhiệm, ưu tiên `manual` > `llm_approved` > `rule`.

## 1. Dữ liệu (Mongo Atlas)

Trên `san_pham` (chỉ ghi các trường này, không đụng `updated_at`):

| Trường | Ý nghĩa |
| --- | --- |
| `cong_dung_keys`, `van_de_da_keys`, `loai_da_keys` | Mảng key chuẩn (benefit / concern / skin type) trong `ai-service-flask/data/graph/synonyms.yaml` |
| `tag_source` | `manual` hoặc `llm_approved` |
| `tag_updated_at` | `Date`; ETL `--since` lọc theo `updated_at` **hoặc** `tag_updated_at` |
| `tag_updated_by` | `adm_` + 16 hex (HMAC email/mã NV với `SEARCH_USER_HASH_SALT`), không lưu email thô |

Collection mới `tag_suggestions`: `{ma_san_pham, suggested{benefits,concerns,skin_types}, evidence[], rule{...}, current{...}, diff{..., differs}, dropped[], model, usage{tokens}, prompt_version, created_at, status: pending|approved|rejected, reviewed_by, reviewed_at, approved{...}, edited}`. Index: `status_created`, `product_status` (tạo bởi script).

## 2. Admin nhập tay (Phần A)

- Form sửa sản phẩm (`index.php?r=admin_sp_sua&id=...`) có thêm khối "Nhãn công dụng & vấn đề da" (3 nhóm checkbox, nhãn tiếng Việt), **form riêng** với nút "Lưu nhãn" để không ghi lại các trường khác của sản phẩm.
- Route `admin_sp_tags` (POST): admin, CSRF (token theo phiên `admin_tag_csrf`), key không thuộc danh sách chuẩn thì từ chối toàn bộ và không ghi gì; ghi `tag_source=manual`; đẩy sự kiện `catalog:changes:v1` `{type:product_updated, ma_san_pham, reason:tags, source, ts}`.
- Danh sách key: `backend/app/data/tag_keys.json`, sinh bằng `python ai-service-flask/scripts/export_tag_keys.py` (có test chống lệch với YAML).
- Sửa kèm: link "Sửa" và action của form trỏ `admin_sp_edit` nhưng route chỉ có `admin_sp_sua` (trước đó trả 404). Đã thêm `case 'admin_sp_edit'` làm alias. Nút "Thêm sản phẩm mới" (`admin_sp_create`) vẫn có cùng lỗi, chưa sửa.

## 3. AI gợi ý (Phần B)

```
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/llm_tag_products.py --limit 30 --only-missing --sample random --seed 7 [--dry-run] [--ids 1496,712] [--rpm 30] [--out file.csv]
```

- Dùng pool LLM đang có (`chatbot_service.llm_pool.get_llms`, hiện gpt-4o-mini), chỉ gửi tên, danh mục, 30 thành phần đầu, mô tả <= 800 ký tự.
- Prompt chỉ cho chọn key trong danh sách; đầu ra được kiểm: JSON hỏng thì thử lại (backoff 2s, 4s), key lạ bị bỏ (chấp nhận biến thể dấu/hoa thường của key thật, ví dụ `khô_da` -> `kho_da`), tối đa 4 benefit, 4 concern, 3 loại da.
- `--dry-run` gọi LLM và in bảng, không ghi gì. Không `--dry-run` thì ghi `tag_suggestions` ở trạng thái `pending`; script không bao giờ tự duyệt.
- `--limit` bắt buộc; chạy toàn bộ catalog cần `--all --confirm-full` (chỉ sau khi chủ sản phẩm đồng ý).
- Trang duyệt `index.php?r=admin_tag_review` (menu "Duyệt nhãn công dụng"): đề xuất AI so với suy luận theo thành phần và nhãn hiện tại, căn cứ AI, Duyệt / Từ chối / Sửa rồi duyệt, duyệt và từ chối hàng loạt (tối đa 100). Sản phẩm đã có nhãn `manual` không bị ghi đè (nút Duyệt bị khóa).

## 4. ETL và xếp hạng (Phần C)

- `graph_sync/curation.py` + `transform.to_graph_row`: mỗi nhóm có nhãn người duyệt hợp lệ thì thay nhãn suy luận của nhóm đó; nhóm rỗng thì rơi về rule; loại da cộng thêm vào `ma_loai_da`. Quan hệ `HAS_BENEFIT`/`TARGETS`/`SUITABLE_FOR` ghi `source = manual|llm_approved|inferred|data`; `Product.tag_source`.
- `ranker.combine(..., curated=, w_curated=)`: cộng phẳng `SEARCH_W_CURATED` (mặc định 0.05) cho sản phẩm có `tag_source` manual/llm_approved; không đổi lý do hiển thị.

## 5. Kiểm thử

`php backend/tests/unit/TagCurationTest.php` (46), pytest `tests/graph_sync/test_curation.py`, `tests/tagging/*`, `tests/search_service/test_ranker.py`. E2E Playwright và ETL thật: xem mục U07 trong `docs/plans/upgrade-handoff.md`.
