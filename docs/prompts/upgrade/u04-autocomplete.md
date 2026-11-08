# U04 — GỢI Ý KHI GÕ (`searchSuggestions`) THEO TỪNG TỪ + AUTOCOMPLETE REDIS

## Vai trò
Kỹ sư fullstack. Sửa ô gợi ý khi gõ đang khớp nguyên cụm (dễ rỗng) và làm nó nhanh, thông minh hơn.

## Đọc trước
`u00-common-rules.md` → handoff (mục U03: API `redis_kit`/`RedisLite`) → `backend/app/models/SanPham.php` (`searchSuggestions`, `buildSearchRegex`, `tokenizeSearchQuery`, `buildAccentInsensitiveRegex`, `buildTokenSearchFilter`) → controller/route trả gợi ý (rg `searchSuggestions`) → JS ô tìm kiếm ở header (`frontend/views/layouts/header.php` + file JS liên quan).

## Yêu cầu

### 1. Sửa khớp (bắt buộc)
- `searchSuggestions` dùng lại logic token của Bước 01 (`buildTokenSearchFilter`): AND giữa token, OR giữa trường, không dấu, có tra hãng qua `ma_thuong_hieu`.
- Token cuối cùng (đang gõ dở) khớp **tiền tố** (`^` ở đầu từ trong tên: regex `(^|\s)<token>`), các token trước khớp đủ từ.
- GitNexus impact `searchSuggestions` trước khi sửa. Không đổi định dạng JSON trả về cho JS (nếu cần thêm trường thì thêm, không đổi trường cũ).

### 2. Autocomplete nhiều loại (trả trong cùng response, theo nhóm)
- `products` (≤5): như mục 1, ưu tiên tên khớp tiền tố đầu chuỗi, rồi `so_luong_da_ban`.
- `brands` (≤3): tên hãng khớp tiền tố (không dấu).
- `categories` (≤3): danh mục khớp tiền tố + synonym (`ai-service-flask/data/graph/synonyms.yaml` — PHP đọc qua bản xuất JSON tĩnh, xem mục 3).
- `queries` (≤5): từ khóa phổ biến từ Redis (mục 4).

### 3. Từ điển dùng chung PHP ↔ Python
- Script Python `ai-service-flask/scripts/export_search_dictionary.py` xuất `backend/app/data/search_dictionary.json` (brands, categories + synonyms đã bỏ dấu, chỉ tên/key công khai) từ Neo4j/YAML. PHP cache trong APCu nếu có, không thì static per-request. Ghi lệnh tái xuất vào handoff (U11 sẽ tự động hóa).

### 4. Từ khóa phổ biến bằng Redis Sorted Set
- Khi tìm kiếm có `result_count > 0`: `ZINCRBY search:popular:v1 1 <q_norm>` (q đã chuẩn hóa, ≤ 60 ký tự, bỏ chuỗi có số điện thoại/email bằng regex). Giới hạn: job cắt `ZREMRANGEBYRANK` giữ top 5000, giảm dần (decay) hằng ngày bằng cách nhân 0.9 (script Lua hoặc job U11 — ở đây viết hàm, U11 lên lịch).
- Prefix lookup: dùng thêm Sorted Set lex `search:prefix:v1` (`ZADD` score 0 member `"<q_norm>"`, `ZRANGEBYLEX [prefix [prefix\xff LIMIT 0 50`) rồi xếp theo điểm phổ biến; hoặc giải pháp đơn giản hơn nếu chứng minh đủ nhanh — ghi lựa chọn.
- Redis lỗi → bỏ nhóm `queries`, các nhóm khác vẫn trả.

### 5. Hiệu năng & UX
- Endpoint gợi ý: debounce phía JS 150ms, hủy request cũ (`AbortController`), tối thiểu 2 ký tự.
- Cache Redis kết quả gợi ý theo prefix 60s (`search:suggest:v1:<sha1>`), có generation invalidate (U03).
- Mục tiêu p95 server < 80ms (đo).
- UI dropdown: nhóm có tiêu đề nhỏ (Sản phẩm / Thương hiệu / Danh mục / Tìm kiếm phổ biến), điều hướng bàn phím (↑↓ Enter Esc), `role="listbox"`/`aria-activedescendant`, highlight phần khớp (escape trước rồi bọc `<mark>`), mobile 375px không tràn.

## Kiểm thử
- PHP unit: tách token với token cuối dạng tiền tố, highlight an toàn XSS, lọc PII trong q.
- Python unit: export dictionary.
- E2E Playwright MCP trên :8080: gõ "toi" → có Torriden ở nhóm thương hiệu; "sua rua" → có sữa rửa mặt; "torriden sua" → sản phẩm 1496; gõ `<img src=x onerror=alert(1)>` → không chạy script; bàn phím chọn được; ảnh `report/upgrade-e2e/u04-*.png`.

## Đầu ra
Mục "U04" trong handoff: định dạng response gợi ý, key Redis mới, lệnh export dictionary, p95. Không commit.
