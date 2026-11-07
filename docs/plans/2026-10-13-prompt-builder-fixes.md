# AI Idol: Sửa lỗi Prompt Builder (L1–L6), cho prompt từ Builder chạy đúng truy vấn chỉ đọc

Ngày: 13/10/2026. Người lập: Opus (planner). Người code: Sonnet (coder).

**Phạm vi được sửa:**
- `ai-idol-service/agents/command_query.py`
- `ai-idol-service/agents/query_sources.py`
- `ai-idol-service/agents/intent_classifier.py`
- `ai-idol-service/agents/service.py` (chỉ sửa 1 điều kiện và 1 dòng import)
- `ai-idol-service/agents/product_resolver.py` (chỉ thêm 2 khóa vào `ACTIVE_ALIASES`)
- `frontend/public/assets/js/ai-idol-studio.js`
- `frontend/views/admin/ai_idol/index.php`
- Test mới: `ai-idol-service/tests/test_prompt_builder_cases.py`

**Không đổi:** `agents/intent_parser.py`, `core/product_metrics.py`, ai-service-flask, chatbot, recommendation, voice, avatar, `syna-render-server.cjs`, thư mục `.runtime/`. Không thêm dependency. Không commit.

**EOL:** mọi file Python/JS/PHP sẽ sửa đều là **CRLF** (đã đếm `\r` = số dòng). Phải giữ nguyên CRLF, chỉ chèn hoặc thay đúng các dòng nêu dưới đây, không reformat. Nhiều file đang có thay đổi chưa commit. Riêng file test mới dùng **LF**, giống `tests/test_command_query.py`.

## 1. Bối cảnh (đã đọc code, chỉ đọc)

**Luồng xử lý:**
- `service.process_agent_run` gọi `classify_intent`.
- Chỉ khi `intent ∈ {CREATE_CAMPAIGN, PRODUCT_SEARCH}` **và** `business_query_requested(brief)` thì mới chạy `build_query_plan`, sau đó `load_query_sources`, rồi `execute_query` (`service.py:161-190`).
- Nếu không thỏa, mọi intent khác CREATE rơi vào `INTENT_REVIEW` / `NEEDS_INPUT` (`service.py:192-210`).

**Builder** (`ai-idol-studio.js:130-169`) ghép câu theo mẫu: `"Tìm|Lọc N" + categoryPhrase + fragments + live + style + "."`.

**Taxonomy catalog thật** (đọc từ `database/data_clean_final.csv`, cùng nguồn Hasaki với `san_pham`):
- `danh_muc_day_du` có dạng `Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt`.
- `loai_san_pham` là lá cuối cùng (ví dụ `Tẩy Trang Mặt`, `Mặt Nạ Giấy`, `Son Thỏi`, `Dầu Xả`, `Chống Nắng Cơ Thể`, `Serum / Kem Dưỡng Mắt`, `Kem / Gel / Dầu Dưỡng`).
- Không có danh mục nào tương ứng "phục hồi hàng rào da".

**`inventory_batches` thật:**
- `warehouse_id` có các giá trị `AUTO-DEFAULT`, `AUTO-SCENARIOS`.
- Chỉ có `batch_code` (ví dụ `AUTO-1-20260928`), không có `batch_id` (xem `InventoryBatch.php:205`).
- Khoảng 500 lô, trong khi catalog có 2.473 sản phẩm.

## 2. Nguyên nhân gốc

### L1. Prompt chỉ có danh mục/thương hiệu/phong cách không bao giờ chạy truy vấn
1. `command_query.py:74-81` `business_query_requested`: danh sách token chỉ gồm kho, hạn dùng, bán hàng và `"loc "`. Câu bắt đầu bằng "Tìm <danh mục>" trả `False`, nên `service.py:164` bỏ qua truy vấn và rơi vào `service.py:192-210` (`INTENT_REVIEW`, `needs ["intent"]`, đúng hai warning đã quan sát). "Lọc 5 sản phẩm." chạy được chỉ nhờ token `"loc "`.
2. `intent_classifier.py:149-161`:
   - Câu có "tim san pham" thì thành PRODUCT_SEARCH với `requires_confirmation=True`.
   - "Tìm mặt nạ chăm sóc da." và "Tìm nước tẩy trang." không chứa "san pham", và regex `has_product_reference` (`intent_classifier.py:72-73`) chỉ biết `serum|kem|sua rua mat|toner|retinol|rau ma|vichy|cerave`. Hai câu này rơi xuống `UNKNOWN` (`:163`).
3. `command_query.py:15-26` chỉ ánh xạ 4 danh mục (sunscreen, cleanser, serum, foundation). `:95-96` còn coi `mat na`, `son moi`, `dau goi`, `kem duong` là danh mục không hỗ trợ.
4. Thương hiệu:
   - `execute_query` (`:260` và `:273-274`) dùng regex `thuong hieu\s+(.+?)…` làm **lọc cứng**, kể cả với câu "ưu tiên … thương hiệu X".
   - Với "mỗi thương hiệu tối đa 2 sản phẩm", regex bắt nhầm "toi da 2 san pham" làm tên thương hiệu, nên mọi sản phẩm bị loại.
   - Regex đếm `:88` lấy "2 sản phẩm" làm `limit=2`.
   - Kế hoạch truy vấn không có trường brand hay giới hạn số sản phẩm mỗi thương hiệu.

### L2. Ngưỡng tồn kho bị hiểu thành số lượng kết quả
- Regex `:88` `(\d+)\s*(?:san pham|…)` khớp "100 san pham" trong "ton kho tren 100 san pham", cho ra `limit=100`.
- `:91-92` thêm câu hỏi "1–50", `:99` kẹp `limit` về 50. Kết quả là NEEDS_INPUT.
- `stock_min=101` (`:121-127`) vẫn đúng.
- "tồn kho tối đa 10 sản phẩm" cho `limit=10` chỉ là trùng hợp.

### L3. Mã kho/lô dính dấu chấm và nguồn lô không được nạp
1. `:105-106` có token `[a-z0-9][a-z0-9._-]{1,}` chạy trên text đã chuẩn hóa, nên nuốt cả dấu `.` cuối câu và cho ra `'auto-default.'`. `batch_in_scope` (`:245-247`) đã so sánh không phân biệt hoa thường, nên lỗi khớp là do dấu chấm.
2. `query_sources.py:45` chỉ nạp `inventory_batches` khi có điều kiện hạn dùng hoặc tồn kho. Plan chỉ có kho/lô nhận `batches=[]`, nên `command_query.py:320-322` đếm **mọi** sản phẩm là thiếu dữ liệu: DATA_UNAVAILABLE với 2473 thiếu `expiry_or_batch_quantity`.
3. Rò phạm vi: ở `:311-319`, sản phẩm có lô nhưng không có lô nào thuộc kho/lô yêu cầu vẫn được giữ lại với `stock = 0`.

### L4. Nhiều tiêu chí bán hàng
- View `index.php:104` ghi "có thể chọn nhiều" và dùng checkbox.
- `:158-160` chỉ đếm số **khóa chiến lược khác nhau**. Ba lựa chọn "bán chạy 30 ngày", "7 ngày" và "toàn kỳ" cùng khóa `units`, nên không sinh câu hỏi.
- `:167-170` chọn cửa sổ theo thứ tự ưu tiên `\b7 ngay\b`, rồi "toan ky", rồi mặc định 30. Kết quả là **âm thầm chọn một cửa sổ**.

### L5. Builder tạo câu bị chính form từ chối
- `ai-idol-studio.js:84` đặt phrase của serum là `'serum'`, nên `:151` cho ra "Tìm serum." (10 ký tự). "Tìm dầu xả." cũng chỉ 11 ký tự.
- `:167` bật nút khi `choiceCount > 0`.
- `:560` và `core/agent_runs.py:79` từ chối câu dưới 12 ký tự.

### L6. "livestream" trong mô tả tồn kho bị hiểu là lệnh tạo
- `ai-idol-studio.js:149` sinh fragment "ưu tiên sản phẩm còn đủ hàng để bán trong livestream".
- `intent_classifier.py:124-134` coi `"livestream"` đứng một mình là create cue, và kiểm tra cue này **trước** bước kiểm tra business query (`:136-141`). Kết quả là CREATE_CAMPAIGN rồi NEEDS_INPUT `product`.
- `command_query.py:118` không hiểu "còn đủ hàng".

## 3. Quyết định thiết kế

1. **Tìm theo danh mục là truy vấn chỉ đọc.**
   - Thêm `catalog_search_requested(text)`: text đã chuẩn hóa bắt đầu bằng `tim|loc|liet ke` **và** thỏa một trong ba điều kiện: có danh mục nhận diện được, có từ `san pham|sp`, hoặc có token danh mục chưa hỗ trợ.
   - Classifier dùng hàm này để route sang PRODUCT_SEARCH (0.90, không cần xác nhận).
   - Service chỉ dùng hàm này khi `intent == PRODUCT_SEARCH`. **Không mở rộng cho CREATE**: "Tìm kem chống nắng, tạo livestream…" vẫn đi đường resolver như cũ, để không tự tạo livestream với 10 sản phẩm lấy theo thứ tự mã.
   - "Cho tôi sản phẩm retinol" vẫn ở `INTENT_REVIEW`, để `test_agent` giữ nguyên.
2. **Danh mục theo taxonomy Builder (`CATEGORY_PATHS`).**
   - Alias trong brief khớp theo ranh giới từ. Cụm cụ thể nhất thắng: "kem chống nắng body" không đồng thời là "kem chống nắng"; "mặt nạ giấy" không đồng thời là "mặt nạ".
   - Bằng chứng danh mục mới **chỉ** so trên `loai_san_pham`, `danh_muc_day_du`, `category`, không dùng tên hay mô tả.
   - 4 danh mục cũ giữ nguyên hành vi để ma trận 200 case không đổi. `toner` vẫn chưa hỗ trợ (ma trận yêu cầu NEEDS_INPUT).
   - "phục hồi hàng rào da" không có danh mục thật: bỏ khỏi Builder, backend hỏi lại rõ ràng.
3. **Hoạt chất trong serum:**
   - retinol đã là ràng buộc cứng.
   - Thêm `niacinamide` và `vitamin c` vào `ACTIVE_ALIASES`, để "serum niacinamide" và "serum vitamin C" không âm thầm mở rộng ra mọi serum.
4. **Thương hiệu:**
   - Đưa vào plan: `brand` (đã chuẩn hóa) và `brand_mode`.
     - `prefer` khi trước cụm "thương hiệu X" có "ưu tiên" trong cùng mệnh đề: không loại sản phẩm, chỉ đưa X lên đầu.
     - `only` cho các trường hợp còn lại: lọc cứng, giống hành vi cũ.
   - Thêm `per_brand_max` (từ cụm "mỗi thương hiệu tối đa N"). Áp dụng sau khi xếp hạng.
   - Sản phẩm không có thương hiệu không kiểm chứng được giới hạn này: loại ra và đếm vào `missing["brand"]` (fail-closed).
5. **Số lượng (`limit`) lấy từ text đã gỡ cụm ngưỡng tồn kho và cụm giới hạn mỗi thương hiệu.**
6. **Kho/lô:**
   - Token phải kết thúc bằng chữ hoặc số.
   - Giữ nguyên chữ hoa/thường bằng cách tìm lại token trong brief gốc.
   - Nạp lô khi có kho/lô.
   - Sản phẩm đã có lô nhưng không có lô nào trong phạm vi thì bị **loại**. Sản phẩm không có lô nào vẫn tính là **thiếu dữ liệu**, giữ `test_batch_scope_does_not_fallback_to_legacy_stock`.
   - So khớp mã lô với cả `batch_id` lẫn `batch_code`.
7. **Bán hàng: chỉ một tiêu chí.**
   - Builder đổi sang radio có lựa chọn "Không chọn", nhãn "chọn 1".
   - Backend **luôn hỏi lại** khi có từ 2 chiến lược trở lên (giữ như cũ), **hoặc** khi các kỳ thống kê được nêu khác nhau (7 ngày, 30 ngày, toàn kỳ), kể cả khi cùng chiến lược. Không bao giờ âm thầm chọn.
8. **Mâu thuẫn tồn kho và hạn dùng thì hỏi lại**, không lấy điều kiện đầu tiên:
   - có từ 2 ngưỡng tồn kho trở lên;
   - "hết hàng" đi cùng "còn hàng", "còn đủ hàng" hoặc một ngưỡng dưới;
   - khoảng hạn dùng đi cùng biên hạn dùng, hoặc có từ 2 biên hạn dùng trở lên.

   Builder cũng tách thành hai nhóm radio (hạn dùng / tồn kho), và chọn "Đã hết hàng" thì tự tắt "còn đủ hàng".
9. **Prompt không có điều kiện sản phẩm** (ví dụ chỉ có phong cách) trả câu hỏi rõ ràng ở `QUERY_RESULTS`, không còn `INTENT_REVIEW`.
   - Có điều kiện khi có ít nhất một trong các yếu tố: số lượng tường minh, danh mục, thương hiệu, giới hạn mỗi thương hiệu, kho/lô, hạn dùng, tồn kho, xếp hạng bán hàng, mã sản phẩm, hoạt chất.
10. **"Còn đủ hàng"** được hiểu là `stock_min = 1`, kèm assumption nói rõ chưa có ngưỡng riêng cho live. Classifier gỡ cụm "đủ hàng … (để) bán trong/khi/trên live(stream)" trước khi tìm create cue, trừ khi ngay sau là lịch (lúc, vào, tối, sáng, chiều, ngày, chữ số). Builder đổi wording thành "ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)".
11. **Builder không bao giờ tạo prompt dưới 12 ký tự.** Nếu ngắn hơn thì thay "Tìm " bằng "Tìm sản phẩm " ("Tìm sản phẩm serum.", "Tìm sản phẩm dầu xả."). Nút "Dùng prompt này" bị tắt và hiện gợi ý khi prompt rỗng, thiếu mã kho/lô, hoặc quá ngắn. Hằng `AGENT_BRIEF_MIN_LENGTH = 12` dùng chung với submit.

## 4. Task

**Lệnh test:**
- Full suite: `docker compose run --rm --no-deps -v "${PWD}/ai-idol-service:/app" ai-idol-worker python -m unittest discover -s tests -q`. Hiện tại: 198 OK.
- Ma trận 200 case: `python docs/build_ai_idol_200_testcases.py --skip-docx` (cách đã dùng trong `update.md:1168`). Lệnh này ghi lại `docs/ai-idol-agent-200-results.json` (untracked, được phép).

### T0. Chuẩn bị (chỉ đọc)
- Chạy `git status`.
- Xác nhận CRLF: `grep -c $'\r' <file>` phải bằng số dòng với 7 file nêu ở trên.
- Chạy GitNexus impact upstream (MCP `impact`, hoặc `node .gitnexus/run.cjs impact "<sym>" --direction upstream --repo .`) cho: `build_query_plan`, `execute_query`, `business_query_requested`, `_category_matches`, `classify_intent`, `load_query_sources`, `process_agent_run`, `_requested_constraints`, `_matches_constraints`, `buildAgentPrompt`.
  - Báo cáo caller và mức risk. Kết quả UNKNOWN phải xác minh lại bằng `rg`. HIGH/CRITICAL phải báo user trước khi sửa.
- `rg -n "niacinamide|vitamin c" -i ai-idol-service/tests` (đã kiểm tra: chỉ có `test_syna.py`, không liên quan resolver).
- Nếu được phép đọc DB: chạy `db.san_pham.distinct("loai_san_pham")` để xác nhận các segment trong T1 tồn tại. Chỉ đọc.

### T1. `command_query.py`: hằng số và nhận diện danh mục
- Đổi import dòng 12 thành `from agents.intent_parser import _extract_explicit_ids, normalize_text`.
- Ngay sau `CATEGORY_LABELS` (dòng 26), chèn:
```python
# Prompt Builder taxonomy -> verified catalog path segments (normalized).
# (brief aliases, catalog evidence). Evidence is matched ONLY against
# loai_san_pham / danh_muc_day_du / category, never names or descriptions.
CATEGORY_PATHS = {
    "face": (("san pham cham soc da mat",), ("cham soc da mat",)),
    "face_cleansing": (("san pham lam sach da",), ("cham soc da mat -> lam sach da",)),
    "makeup_remover": (("nuoc tay trang", "tay trang mat"), ("lam sach da -> tay trang mat",)),
    "exfoliant": (("tay te bao chet",), ("tay te bao chet da mat",)),
    "hydration": (("san pham duong am",), ("cham soc da mat -> duong am",)),
    "moisturizer": (("kem duong am",), ("kem / gel / dau duong",)),
    "mask": (("mat na",), ("cham soc da mat -> mat na",)),
    "sheet_mask": (("mat na giay",), ("mat na giay",)),
    "wash_off_mask": (("mat na rua",), ("mat na rua",)),
    "eye_care": (("san pham cham soc mat",), ("cham soc da mat -> duong mat",)),
    "eye_cream": (("kem mat", "kem duong mat"), ("kem duong mat",)),
    "body_sunscreen": (("kem chong nang body", "chong nang co the"), ("chong nang co the",)),
    "makeup": (("san pham trang diem",), ("trang diem ->",)),
    "makeup_base": (("san pham trang diem nen",), ("kem nen", "phan nen", "phan nuoc cushion", "bb / cc cream", "che khuyet diem", "kem lot")),
    "concealer": (("che khuyet diem",), ("che khuyet diem",)),
    "lip_makeup": (("san pham trang diem moi",), ("trang diem -> trang diem moi",)),
    "lipstick": (("son moi",), ("son thoi", "son kem")),
    "lip_gloss": (("son bong",), ("son bong",)),
    "body": (("san pham cham soc co the",), ("cham soc co the",)),
    "body_wash": (("sua tam", "san pham lam sach co the"), ("cham soc co the -> sua tam", "cham soc co the -> xa phong")),
    "body_lotion": (("san pham duong the", "kem duong the"), ("cham soc co the -> duong the",)),
    "hair": (("san pham cham soc toc",), ("cham soc toc",)),
    "hair_wash": (("san pham lam sach toc",), ("dau goi va dau xa",)),
    "shampoo": (("dau goi",), ("dau goi va dau xa -> dau goi",)),
    "hair_care": (("san pham duong toc",), ("cham soc toc va da dau -> duong toc", "dau goi va dau xa -> dau xa")),
    "conditioner": (("dau xa",), ("dau goi va dau xa -> dau xa",)),
    "hair_mask": (("kem u toc",), ("mat na / kem u toc",)),
}
CATEGORY_LABELS.update({
    "face": "Chăm sóc da mặt", "face_cleansing": "Làm sạch da", "makeup_remover": "Tẩy trang",
    "exfoliant": "Tẩy tế bào chết", "hydration": "Dưỡng ẩm & phục hồi", "moisturizer": "Kem dưỡng ẩm",
    "mask": "Mặt nạ", "sheet_mask": "Mặt nạ giấy", "wash_off_mask": "Mặt nạ rửa",
    "eye_care": "Chăm sóc mắt", "eye_cream": "Kem mắt", "body_sunscreen": "Chống nắng cơ thể",
    "makeup": "Trang điểm", "makeup_base": "Trang điểm nền", "concealer": "Che khuyết điểm",
    "lip_makeup": "Trang điểm môi", "lipstick": "Son môi", "lip_gloss": "Son bóng",
    "body": "Chăm sóc cơ thể", "body_wash": "Làm sạch cơ thể / sữa tắm", "body_lotion": "Dưỡng thể",
    "hair": "Chăm sóc tóc", "hair_wash": "Làm sạch tóc", "shampoo": "Dầu gội",
    "hair_care": "Dưỡng tóc", "conditioner": "Dầu xả", "hair_mask": "Kem ủ tóc",
})
UNSUPPORTED_CATEGORY_TOKENS = ("kem duong", "son moi", "toner", "mat na", "dau goi", "phuc hoi hang rao")
NO_CONDITION_QUESTION = ("Hãy chọn ít nhất một điều kiện lọc: danh mục, thương hiệu, tồn kho, hạn dùng, "
                         "kho/lô hoặc chỉ số bán hàng. Phong cách dẫn chuyện chỉ dùng khi tạo livestream.")
SEARCH_VERB_PATTERN = re.compile(r"^(?:tim|loc|liet ke)\b")
SCOPE_CODE = r"([a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)"
BRAND_PATTERN = re.compile(
    r"(?<!moi )(?<!nhieu )(?<!cac )thuong hieu\s+(?!toi da\b)(.+?)"
    r"(?=\s+(?:con|ton|ban|de|trong|voi|luc|va|dang|uu tien|moi|theo|tao|chi lay|duoc)\b|\s*[,;]|\.(?:\s|$)|$)"
)
PER_BRAND_PATTERN = re.compile(r"moi thuong hieu\s*(?:toi da|chi|khong qua)?\s*(\d+)")
STOCK_BOUND_PATTERN = r"(?:ton kho|ton)\s*(?:tren|hon|it nhat|tu|duoi|it hon|toi da)\s*\d+\b"
COUNT_EXCLUDE_PATTERN = re.compile(
    r"(?:ton kho|ton)\s*(?:tren|hon|it nhat|tu|duoi|it hon|toi da)\s*\d+\s*(?:san pham|sp)?"
    r"|moi thuong hieu\s*(?:toi da|chi|khong qua)?\s*\d+\s*(?:san pham|sp)?"
)
```
- Ngay sau `add_months` (sau dòng 71), thêm 3 hàm:
```python
def detect_categories(text: str) -> list[str]:
    """Categories named in normalized text; the most specific phrase wins."""
    hits: dict[str, str] = {}
    for key, aliases in [*CATEGORY_ALIASES.items(), *((k, v[0]) for k, v in CATEGORY_PATHS.items())]:
        found = [a for a in aliases if re.search(rf"(?<![a-z0-9]){re.escape(a)}(?![a-z0-9])", text)]
        if found:
            hits[key] = max(found, key=len)
    return [key for key, alias in hits.items() if not any(o != alias and alias in o for o in hits.values())]


def catalog_search_requested(text: str) -> bool:
    """A read-only catalog search such as "Tìm sữa rửa mặt"; never a create command by itself."""
    text = normalize_text(text)
    return bool(SEARCH_VERB_PATTERN.match(text)) and bool(
        detect_categories(text) or re.search(r"\b(?:san pham|sp)\b", text)
        or any(x in text for x in UNSUPPORTED_CATEGORY_TOKENS))


def _raw_code(brief: str, token: str) -> str:
    """Warehouse/lot code exactly as typed (case kept); trailing punctuation is never part of it."""
    match = re.search(rf"(?<![A-Za-z0-9._-]){re.escape(token)}(?![A-Za-z0-9_-])", brief, re.IGNORECASE)
    return match.group(0) if match else token
```
- Trong tuple token của `business_query_requested` (dòng 76-81): thêm `"con du hang",`.
- **Test:** chạy T7.

### T2. `command_query.py`: `build_query_plan`
Thứ tự sửa theo dòng hiện tại:
1. **Dòng 87:** `categories = detect_categories(text)`.
2. **Dòng 88:** `count = re.search(r"\b(?:top\s+|loc\s+(?:ra\s+)?)?(\d+)\s*(?:san pham|sp|kem|sua|gel|serum)\b", COUNT_EXCLUDE_PATTERN.sub(" ", text))`. Pattern giữ nguyên, chỉ đổi chuỗi nguồn.
3. **Dòng 95-96:**
   ```python
   if not categories and any(x in text for x in UNSUPPORTED_CATEGORY_TOKENS):
       questions.append("Danh mục này chưa có ánh xạ xác minh trong catalog; hãy chọn danh mục có sẵn trong Prompt Builder.")
   ```
4. **Dict `plan` (dòng 102):** thêm `"brand": None, "brand_mode": None, "per_brand_max": None,` ngay sau `"warehouse_id": None, "batch_id": None,`.
5. **Dòng 105-108 và 111-112:** regex dùng `SCOPE_CODE`:
   - `warehouse = re.search(r"(?:chi lay (?:tu|o) kho|kho cu the|ma kho)\s*[:#-]?\s*" + SCOPE_CODE, text)`
   - `batch = re.search(r"(?:chi lay tu lo hang|lo hang|ma lo)\s*[:#-]?\s*" + SCOPE_CODE, text)`
   - Gán `plan["warehouse_id"] = _raw_code(brief, warehouse.group(1))` và `plan["batch_id"] = _raw_code(brief, batch.group(1))`.
6. **Ngay sau khối batch (sau dòng 114),** chèn:
   ```python
   brand = BRAND_PATTERN.search(text)
   if brand:
       plan["brand"] = brand.group(1).strip()
       plan["brand_mode"] = "prefer" if re.search(r"uu tien[^,.;]*$", text[:brand.start()]) else "only"
   per_brand = PER_BRAND_PATTERN.search(text)
   if per_brand:
       if 1 <= int(per_brand.group(1)) <= 50:
           plan["per_brand_max"] = int(per_brand.group(1))
       else:
           questions.append("Giới hạn mỗi thương hiệu cần nằm trong khoảng 1–50 sản phẩm.")
   ```
7. **Dòng 118:** tuple thêm `"con du hang"`. Ngay sau khối if/elif đó (sau dòng 120), chèn:
   ```python
   if "con du hang" in text:
       plan["assumptions"].append("“Còn đủ hàng” được hiểu là tồn kho khả dụng từ 1; chưa có ngưỡng riêng cho livestream.")
   ```
8. **Ngay sau khối `stock` (sau dòng 129),** chèn:
   ```python
   stock_bounds = re.findall(STOCK_BOUND_PATTERN, text)
   out_of_stock = "het hang" in text and "sap het hang" not in text
   if len(stock_bounds) > 1 or (out_of_stock and (stock_bounds or "con hang" in text or "con du hang" in text)):
       questions.append("Điều kiện tồn kho đang mâu thuẫn hoặc có nhiều ngưỡng; hãy chọn một: hết hàng, tồn kho tối đa N hoặc tồn kho trên N.")
   ```
9. **Trong khối hạn dùng, ngay sau dòng 134 (`bound = …`),** chèn:
   ```python
   if (interval and bound) or len(re.findall(r"(?:toi da|khong qua|duoi|it nhat|tren)\s*\d+\s*thang", text)) > 1:
       questions.append("Mỗi lần chỉ chọn một điều kiện hạn dùng: từ A đến B tháng, hoặc tối đa/ít nhất N tháng.")
   ```
10. **Ngay sau khối `if len(selected_strategies) > 1:` (dòng 159-160),** chèn:
    ```python
    if selected_strategies:
        windows = set(re.findall(r"\b(7|30)\s*ngay\b", text))
        if "toan ky" in text or "tu truoc den nay" in text:
            windows.add("all")
        if len(windows) > 1:
            questions.append("Mỗi lần chỉ chọn một kỳ thống kê bán hàng: 7 ngày, 30 ngày hoặc toàn kỳ.")
    ```
    Không đổi dòng 161-178.
11. **Trước `plan["summary"] = [` (dòng 189),** chèn:
    ```python
    from agents.product_resolver import _requested_constraints
    has_condition = bool(
        count or categories or plan["brand"] or plan["per_brand_max"] or plan["warehouse_id"] or plan["batch_id"]
        or plan["expiry_required"] or plan["stock_min"] is not None or plan["stock_max"] is not None
        or plan["rank_by"] != "catalog" or _extract_explicit_ids(text) or _requested_constraints([text])
    )
    if not has_condition and not questions:
        questions.append(NO_CONDITION_QUESTION)
    if "phong cach" in text:
        plan["assumptions"].append("Phong cách dẫn chuyện chỉ dùng khi tạo kịch bản livestream; không ảnh hưởng bộ lọc.")
    ```
12. **Sau dòng 202 (`Lô:`),** thêm:
    ```python
    if plan["brand"]:
        plan["summary"].append(("Ưu tiên thương hiệu: " if plan["brand_mode"] == "prefer" else "Thương hiệu: ") + plan["brand"])
    if plan["per_brand_max"]:
        plan["summary"].append(f"Mỗi thương hiệu tối đa {plan['per_brand_max']} sản phẩm")
    ```

### T3. `command_query.py`: `_category_matches` và `execute_query`
- **`_category_matches`:** ngay sau `if category is None: return True` (dòng 211-212), chèn:
  ```python
  if category in CATEGORY_PATHS:
      fields = normalize_text(" ".join(str(row.get(k) or "") for k in ("loai_san_pham", "danh_muc_day_du", "category")))
      return any(x in fields for x in CATEGORY_PATHS[category][1])
  ```
- **`batch_in_scope`, dòng 247-248:** thay bằng:
  ```python
  if batch_id and normalize_text(batch_id) not in {normalize_text(str(batch.get(k) or "")) for k in ("batch_id", "batch_code")}:
      return False
  ```
- **Xóa dòng 260** (`explicit_brand = …`).
- **Thay dòng 273-274** bằng:
  ```python
  if plan.get("brand") and plan.get("brand_mode") == "only" and normalize_text(normalized["brand"]) != plan["brand"]:
      continue
  ```
- **Sau dòng 275 (`evidence = …`),** chèn:
  ```python
  if plan.get("brand") or plan.get("per_brand_max"):
      evidence["brand"] = normalized["brand"]
  ```
- **Nhánh không hạn dùng:** ngay sau dòng 312 (`scoped_batches = …`), chèn:
  ```python
  if (plan.get("warehouse_id") or plan.get("batch_id")) and not scoped_batches:
      continue  # ledger proves this product is outside the requested warehouse/lot
  ```
- **Ngay sau `rows.sort(key=sort_key)` (dòng 410)**, trước `result.update`, chèn:
  ```python
  if plan.get("brand") and plan.get("brand_mode") == "prefer":
      rows.sort(key=lambda row: normalize_text(row["evidence"].get("brand")) != plan["brand"])
  if plan.get("per_brand_max"):
      per_brand: Counter = Counter()
      capped = []
      for row in rows:
          brand_key = normalize_text(row["evidence"].get("brand"))
          if not brand_key:
              missing["brand"] += 1
              continue
          if per_brand[brand_key] < plan["per_brand_max"]:
              per_brand[brand_key] += 1
              capped.append(row)
      rows = capped
  ```

### T4. `query_sources.py`
- **Dòng 45:** đổi thành `if plan["expiry_required"] or plan["stock_min"] is not None or plan["stock_max"] is not None or plan.get("warehouse_id") or plan.get("batch_id"):`.

### T5. `intent_classifier.py` và `service.py`
- **`intent_classifier.py`:** sau `CANCEL_COMMAND_PATTERN` (dòng 47), chèn:
  ```python
  # "còn đủ hàng để bán trong livestream" describes stock, not a create action,
  # unless a schedule follows ("... trong livestream lúc 20h").
  LIVE_STOCK_QUALIFIER = re.compile(
      r"\bdu\s+hang\b[^,.;]*?\b(?:de\s+)?ban\s+(?:trong|khi|tren)\s+(?:livestream|live stream|live)\b"
      r"(?!\s*(?:luc|vao|toi|sang|chieu|ngay|\d))"
  )
  ```
  - Dòng 129: `has_create_cue = _has_any(LIVE_STOCK_QUALIFIER.sub(" ", text), create_cues)`.
  - Dòng 136-137:
    ```python
    from agents.command_query import business_query_requested, catalog_search_requested
    if business_query_requested(brief_text) or catalog_search_requested(brief_text):
    ```
- **`service.py`:**
  - Dòng 11: `from agents.command_query import build_query_plan, business_query_requested, catalog_search_requested, execute_query`.
  - Dòng 164: thay `and business_query_requested(run["brief"])` bằng:
    ```python
    and (business_query_requested(run["brief"])
         # A plain "Tìm <danh mục>" is read-only; it never widens a create command.
         or (classification.intent == PRODUCT_SEARCH and catalog_search_requested(run["brief"])))
    ```
- **`product_resolver.py`:** trong `ACTIVE_ALIASES` (dòng 15-18), thêm:
  ```python
  "niacinamide": ("niacinamide", "nicotinamide", "vitamin b3"),
  "vitamin c": ("vitamin c", "ascorbic", "ascorbyl"),
  ```

### T6. Builder: JS và view
- **`index.php`:**
  - **Dòng 90:** legend span đổi thành `mỗi nhóm chọn 1`.
  - **Dòng 92-96:** thay 5 checkbox bằng các radio sau (giữ nguyên `data-fragment` cũ):
    - `agentExpiry`: `value="" data-fragment="" checked` (label "Hạn dùng: không lọc"), `expiry_17_19`, `expiry_max_18`.
    - `agentStock`: `value="" data-fragment="" checked` (label "Tồn kho: không lọc"), `stock_high`, `stock_low`, `out_of_stock`.
  - **Dòng 104:** span đổi thành `chọn 1`. Chèn radio đầu tiên `<label><input type="radio" name="agentSales" value="" data-fragment="" checked><span>Không chọn</span></label>`. Đổi 8 input `agentSales` từ `type="checkbox"` sang `type="radio"`.
  - **Dòng 151:** `<small>` đổi thành `<small id="agentPromptHint">`.
- **`ai-idol-studio.js`:**
  1. Sau dòng 99, thêm `const AGENT_BRIEF_MIN_LENGTH = 12;`.
  2. **Dòng 83:** xóa `, barrier: 'sản phẩm phục hồi hàng rào da'`.
  3. **Dòng 135:**
     ```js
     const inventory = [...root.querySelectorAll('input[name="agentExpiry"]:checked, input[name="agentStock"]:checked')].map(input => input.dataset.fragment).filter(Boolean);
     ```
     **Dòng 136:** thêm `.filter(Boolean)`.
  4. Trước `const fragments = [];` (dòng 134), chèn:
     ```js
     const outOfStock = root.querySelector('input[name="agentStock"]:checked')?.value === 'out_of_stock';
     $('agentEnoughStock').disabled = outOfStock;
     if (outOfStock) $('agentEnoughStock').checked = false;
     ```
  5. **Dòng 149:** fragment đổi thành `'ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)'`.
  6. Sau `prompt += '.';` (dòng 160): `if (prompt.length < AGENT_BRIEF_MIN_LENGTH) prompt = prompt.replace(/^Tìm /, 'Tìm sản phẩm ');`.
  7. **Dòng 166-168:**
     ```js
     const incompleteScope = Boolean(warehouseMode && !warehouse);
     const tooShort = prompt.length < AGENT_BRIEF_MIN_LENGTH;
     useAgentBuilderPrompt.disabled = !choiceCount || incompleteScope || tooShort;
     $('agentPromptHint').textContent = !choiceCount ? 'Chọn ít nhất một điều kiện để ghép prompt.' : incompleteScope ? 'Nhập mã kho hoặc mã lô trước khi dùng prompt.' : tooShort ? `Prompt cần ít nhất ${AGENT_BRIEF_MIN_LENGTH} ký tự.` : 'Prompt chỉ được điền vào ô lệnh khi bạn bấm nút bên dưới.';
     return {prompt, choiceCount, incompleteScope, tooShort};
     ```
  8. **Dòng 201:** `if (!result.choiceCount || result.tooShort) return;`.
  9. **Dòng 560:** dùng `AGENT_BRIEF_MIN_LENGTH`, câu báo `` `Hãy mô tả yêu cầu ít nhất ${AGENT_BRIEF_MIN_LENGTH} ký tự.` ``.
  10. **`renderQueryEvidence` (dòng 529):** thêm vào `fields`: `brand:'Thương hiệu', warehouse_id:'Kho', batch_id:'Lô'`.
- **Test:**
  - `node --check frontend/public/assets/js/ai-idol-studio.js`
  - `docker compose exec <php-service> php -l frontend/views/admin/ai_idol/index.php` (hoặc `php -l` trên host)
  - `git diff --check`

### T7. Test mới `ai-idol-service/tests/test_prompt_builder_cases.py` (LF)
**Import:**
```python
from tests.fakes import Collection, FakeDb
from unittest.mock import Mock, patch
```
cùng với `build_query_plan`, `execute_query`, `catalog_search_requested`, `NO_CONDITION_QUESTION`, `classify_intent` và các hằng intent, `fallback_parse`, `load_query_sources`, `process_agent_run`.

**Dữ liệu:**
- `TODAY = dt.date(2026, 10, 13)`.
- Hàm `row(pid, name, path, brand, stock=50, sales=10, **extra)` trả về:
  - `ma_san_pham=pid`, `ten_san_pham=name`;
  - `danh_muc_day_du="Sức Khỏe - Làm Đẹp -> " + path`, `loai_san_pham=path.split(" -> ")[-1]`;
  - `thuong_hieu=brand`, `so_luong_ton_kho=stock`, `so_luong_ban=sales`;
  - `gia_ban=100`, `gia_thi_truong=150`, `trang_thai="active"`.
- `CATALOG`:

| Mã | Tên | Đường dẫn danh mục | Thương hiệu | Ghi chú |
|---|---|---|---|---|
| "1" | Sữa Rửa Mặt CeraVe | Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt | CeraVe | stock 120 |
| "2" | Sữa Rửa Mặt CeraVe Foaming | (cùng đường dẫn) | CeraVe | stock 5 |
| "3" | Sữa Rửa Mặt CeraVe Hydrating | (cùng đường dẫn) | CeraVe | stock 0 |
| "4" | Nước Tẩy Trang Bioderma | Chăm Sóc Da Mặt -> Làm Sạch Da -> Tẩy Trang Mặt | Bioderma | |
| "5" | Mặt Nạ Giấy Mediheal | Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Giấy | Mediheal | |
| "6" | Kem Chống Nắng La Roche-Posay | Chăm Sóc Da Mặt -> Chống Nắng Da Mặt | La Roche-Posay | stock 8 |
| "7" | Serum Niacinamide The Ordinary | Chăm Sóc Da Mặt -> Đặc Trị -> Serum / Tinh Chất | The Ordinary | `thanh_phan_chinh="Niacinamide"` |
| "8" | Son Thỏi 3CE | Trang Điểm -> Trang Điểm Môi -> Son Thỏi | 3CE | |
| "9" | Kem Nền Maybelline | Trang Điểm -> Trang Điểm Mặt -> Kem Nền | Maybelline | |
| "10" | Sữa Tắm Dove | Chăm Sóc Cơ Thể -> Sữa Tắm | Dove | |
| "11" | Dầu Xả TRESemme | Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Xả | TRESemme | |
| "12" | Kem Chống Nắng Body Nivea | Chăm Sóc Cơ Thể -> Chống Nắng Cơ Thể | Nivea | |
| "13" | Serum Vitamin C Klairs | (đường dẫn serum như "7") | Klairs | `thanh_phan_chinh="Ascorbic Acid"` |

- `BATCHES`:
  - `{"product_id":"1","batch_code":"AUTO-1-20260928","warehouse_id":"AUTO-DEFAULT","status":"available","available_quantity":120,"expires_on":"2028-01-01"}`
  - `{"product_id":"2","batch_code":"DEMO-2-20260928","warehouse_id":"AUTO-SCENARIOS","status":"available","available_quantity":5,"expires_on":"2028-01-01"}`
- Helper:
  - `plan(t) = build_query_plan(t, today=TODAY)`
  - `run(t, **src) = execute_query(plan(t), list(CATALOG), brief=t, **src)`
  - `ids(r) = [x["product_id"] for x in r["selected"]]`

**Các case:**
1. **L1 danh mục.** Với mỗi `(prompt, category, expected_ids)`, assert:
   - `classify_intent(p).intent == PRODUCT_SEARCH`;
   - `catalog_search_requested(p)`;
   - `plan(p)["questions"] == []`, `plan(p)["limit"] == 10`, `plan(p)["category"] == category`;
   - `run(p)["status"] == "RESULTS_READY"` và `set(ids(run(p))) == expected_ids`.

   | Prompt | category | expected_ids |
   |---|---|---|
   | "Tìm sản phẩm chăm sóc da mặt." | face | {1..7, 13} |
   | "Tìm sản phẩm trang điểm." | makeup | {8, 9} |
   | "Tìm sản phẩm làm sạch da." | face_cleansing | {1, 2, 3, 4} |
   | "Tìm sữa rửa mặt." | cleanser | {1, 2, 3} |
   | "Tìm nước tẩy trang." | makeup_remover | {4} |
   | "Tìm mặt nạ chăm sóc da." | mask | {5} |
   | "Tìm kem chống nắng body." | body_sunscreen | {12} |
   | "Tìm sản phẩm chăm sóc cơ thể." | body | {10, 12} |
   | "Tìm sản phẩm dầu xả." | conditioner | {11} |
   | "Tìm serum niacinamide." | serum | {7} |
   | "Tìm serum vitamin C." | serum | {13} |

   Riêng "Tìm kem chống nắng.": category `sunscreen`, và `"6" in ids`.
2. **L1 phong cách và danh mục chưa hỗ trợ:**
   - "Tìm sản phẩm, theo phong cách tươi vui, dễ thương.": intent PRODUCT_SEARCH, `questions == [NO_CONDITION_QUESTION]`.
   - "Tìm sản phẩm phục hồi hàng rào da.": có question chứa "chưa có ánh xạ".
   - "Lọc 10 toner bán chạy toàn kỳ": `questions` khác rỗng.
3. **Thương hiệu:**
   - "Tìm sản phẩm ưu tiên nhiều sản phẩm của thương hiệu CeraVe.": `brand == "cerave"`, `brand_mode == "prefer"`, `limit == 10`; `ids[:3] == ["1","2","3"]`, `len == 10`.
   - "Tìm sản phẩm mỗi thương hiệu tối đa 2 sản phẩm.": `per_brand_max == 2`, `brand is None`, `limit == 10`; `"3" not in ids`; mọi thương hiệu xuất hiện ≤ 2 lần (đếm theo `evidence["brand"]`).
   - "Tìm sản phẩm ưu tiên nhiều sản phẩm của thương hiệu CeraVe, mỗi thương hiệu tối đa 2 sản phẩm.": `ids[:2] == ["1","2"]`, `"3" not in ids`.
   - "Lọc 5 sữa rửa mặt thương hiệu CeraVe": `brand_mode == "only"`, `set(ids) == {"1","2","3"}`.
   - Giới hạn mỗi thương hiệu trên catalog `[row("x","Sữa Rửa Mặt X",<path sữa rửa mặt>,"")]`: `missing_data["brand"] == 1`, `selected == []`.
4. **L2 tồn kho:**
   - "Tìm sản phẩm tồn kho trên 100 sản phẩm.": `limit 10`, `stock_min 101`, `stock_max None`, `rank_by "stock_desc"`, `questions []`, `ids == ["1"]`.
   - "Tìm sản phẩm tồn kho tối đa 10 sản phẩm, còn hàng.": `limit 10`, `stock_min 1`, `stock_max 10`, `rank_by "stock_asc"`, `ids == ["2","6"]`.
   - "Lọc 3 sản phẩm tồn kho trên 100 sản phẩm.": `limit 3`.
   - "Tìm sản phẩm đã hết hàng.": `stock_max 0`, `ids == ["3"]`.
   - Mâu thuẫn, mỗi câu phải có `questions` khác rỗng:
     - "Tìm sản phẩm tồn kho trên 100 sản phẩm, tồn kho tối đa 10 sản phẩm, còn hàng."
     - "Tìm sản phẩm đã hết hàng, ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)."
     - "Tìm sản phẩm còn hạn sử dụng từ 17 đến 19 tháng, còn hạn sử dụng tối đa 18 tháng."
5. **L3 kho/lô:**
   - Plan:
     - "Tìm sản phẩm chỉ lấy từ kho AUTO-DEFAULT." → `warehouse_id == "AUTO-DEFAULT"`.
     - "Tìm sản phẩm chỉ lấy từ lô hàng AUTO-1-20260928." → `batch_id == "AUTO-1-20260928"`.
     - "Lọc 1 kem chống nắng chỉ lấy từ kho KHO-HN-01." → `"KHO-HN-01"`.
   - `load_query_sources(FakeDb(inventory_batches=Collection(BATCHES)), plan("Tìm sản phẩm chỉ lấy từ kho AUTO-DEFAULT."))[0]` có 2 phần tử.
   - `run(…, batches=BATCHES)` với câu kho: `status RESULTS_READY`, `ids == ["1"]`, `evidence["warehouse_id"] == "AUTO-DEFAULT"`.
   - Với câu lô: `ids == ["1"]`, `evidence["batch_id"] == "AUTO-1-20260928"`.
6. **L4 bán hàng.** Một tiêu chí, mỗi câu `questions == []`:

   | Prompt | rank_by | window_days |
   |---|---|---|
   | "Tìm sản phẩm bán chạy nhất trong 30 ngày qua." | units | 30 |
   | "Tìm sản phẩm bán tốt trong 7 ngày qua." | units | 7 |
   | "Tìm sản phẩm bán chạy nhất toàn kỳ." | units | None |
   | "Tìm sản phẩm doanh thu cao nhưng tỷ lệ chuyển đổi thấp trong 30 ngày qua." | revenue_low_conversion | 30 |
   | "Tìm sản phẩm được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua." | views_no_orders | 30 |
   | "Tìm sản phẩm có nhiều lượt thêm giỏ trong 30 ngày qua." | cart | 30 |
   | "Tìm sản phẩm đang giảm giá nhưng bán chậm trong 30 ngày qua." | discount_slow | 30 |
   | "Tìm sản phẩm có doanh số tăng mạnh trong 7 ngày qua so với 7 ngày trước đó." | growth | 7 |

   Nhiều tiêu chí: `run` → `status NEEDS_INPUT`, `selected == []`:
   - "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, bán tốt trong 7 ngày qua."
   - "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, bán chạy nhất toàn kỳ."
   - "Tìm sản phẩm bán tốt trong 7 ngày qua, bán chạy nhất toàn kỳ."
   - "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, đang giảm giá nhưng bán chậm trong 30 ngày qua."

   Câu "xem nhiều" chạy với `snapshots=[{"product_id":"1","as_of":"2026-10-13","window_days":30,"coverage_complete":True,"units_sold":3,"viewed_sessions":None,"converted_sessions":None}]` → `DATA_UNAVAILABLE`, `"sales_snapshot" in missing_data`.
7. **L6 wording live:**
   - Intent PRODUCT_SEARCH cho 3 câu:
     - "Tìm sản phẩm ưu tiên sản phẩm còn đủ hàng để bán trong livestream."
     - "Tìm sản phẩm ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)."
     - Câu dài: "Tìm kem chống nắng tồn kho tối đa 10 sản phẩm, còn hàng, đang giảm giá nhưng bán chậm trong 30 ngày qua, mỗi thương hiệu tối đa 2 sản phẩm, ưu tiên sản phẩm còn đủ hàng để bán trong livestream."
   - Plan câu 1: `stock_min 1`, `rank_by "stock_desc"`, `questions []`.
   - Plan câu dài: `category sunscreen`, `limit 10`, `stock_min 1`, `stock_max 10`, `rank_by discount_slow`, `window_days 30`, `per_brand_max 2`, `brand None`, `questions []`.
   - Vẫn là CREATE:
     - "Lọc 5 kem chống nắng còn đủ hàng để bán trong livestream lúc 20h tối mai."
     - "Lọc 3 sữa rửa mặt bán chạy nhất toàn kỳ, ưu tiên sản phẩm còn đủ hàng (đủ bán khi live), tạo livestream lúc 20h00 ngày 14/10/2026, thời lượng 60 phút, chỉ chiếu nội bộ xem trước."
8. **Không được hồi quy:**
   - "Tìm sản phẩm còn hạn sử dụng từ 17 đến 19 tháng.": `expiry_from "2028-03-13"`, `expiry_to "2028-05-13"`, `as_of "2026-10-13"`, `questions []`.
   - "Tìm sản phẩm còn hạn sử dụng tối đa 18 tháng.": `expiry_from "2026-10-13"`, `expiry_to "2028-04-13"`.
   - "Lọc 5 sản phẩm.": `limit 5`, `rank_by catalog`, `questions []`, intent PRODUCT_SEARCH.
   - "Tìm sữa rửa mặt còn hạn sử dụng từ 17 đến 19 tháng.": `category cleanser`.
   - "Tìm kem nền còn hạn sử dụng từ 17 đến 19 tháng": PRODUCT_SEARCH, `category foundation`.
   - "Lọc 3 sữa rửa mặt bán chạy nhất toàn kỳ, tạo livestream lúc 20h00 ngày 14/10/2026, thời lượng 60 phút, chỉ chiếu nội bộ xem trước.": CREATE; plan `limit 3`, `cleanser`, `units`, `window None`, `questions []`; `run` → `ids == ["1","2","3"]`, `complete True`.
   - "Tối mai lúc 20 giờ, tạo livestream giới thiệu sản phẩm mã 93 an toàn.": CREATE, và `fallback_parse(...).explicit_product_ids == ["93"]`.
   - "Hủy campaign": CANCEL_CAMPAIGN.
   - `catalog_search_requested("Cho tôi sản phẩm retinol") is False`.
9. **Service.** Helper chép theo `test_command_query_service.run_command`, thêm tham số `batches`, dùng `catalog=CATALOG`:
   - "Tìm sữa rửa mặt.": `status RESULTS_READY`, `current_stage QUERY_RESULTS`, `plan.intent_classification.intent == PRODUCT_SEARCH`, `ids` của `plan.query_result == ["1","2","3"]`. `create`, `LLMProvider` và `parse_agent_brief` không được gọi.
   - "Tìm sản phẩm, theo phong cách tươi vui, dễ thương.": `status NEEDS_INPUT`, `current_stage QUERY_RESULTS`, `plan["needs"] == ["product"]`, `NO_CONDITION_QUESTION in plan["warnings"]`.
   - "Tìm sản phẩm chỉ lấy từ kho AUTO-DEFAULT." với `batches=BATCHES`: `RESULTS_READY`, `ids == ["1"]`.
   - Đường CREATE không bị chuyển sang truy vấn catalog, với text "Tìm kem chống nắng, tạo livestream lúc 20h00 ngày 14/10/2026, thời lượng 60 phút, chỉ chiếu nội bộ xem trước.". Patch:
     - `get_agent_run` và `update_agent_run` (dict trạng thái);
     - `create_campaign`, `LLMProvider`;
     - `parse_agent_brief` với `side_effect=lambda brief, overrides, llm=None: fallback_parse(brief, overrides)`;
     - `agents.service.execute_query` as `query`.

     Assert `query.assert_not_called()` và `run["intent_classification"]["intent"] == CREATE_CAMPAIGN`.
- **Test:**
  - Toàn bộ suite: 198 test cũ và test mới đều OK.
  - `python docs/build_ai_idol_200_testcases.py --skip-docx` in `TESTCASES=200 PASS=200 FAIL=0`.

### T8. Nhật ký
- **`update.md`:** thêm một mục ngắn gồm:
  - L1–L6 và nguyên nhân;
  - kết quả thật của unittest (số test), 200/200, `node --check`, `php -l`.
- Không rebuild hay restart container nếu chưa được yêu cầu. Image chứa sẵn source, nên phải rebuild `ai-idol-*` thì UI mới thấy thay đổi Python. Ghi rõ điều này khi bàn giao.

## 5. Nguyên tắc kiến trúc

- Logic nằm ở Python. JS chỉ ghép text và trạng thái UI. Builder không bao giờ phát ra câu mà form hoặc backend chắc chắn từ chối.
- Fail-closed:
  - mâu thuẫn hoặc nhiều tiêu chí thì hỏi lại, không âm thầm chọn;
  - thiếu thương hiệu hoặc thiếu lô thì đếm vào `missing`;
  - danh mục chưa có bằng chứng catalog thì hỏi lại.
- Bằng chứng danh mục mới chỉ lấy từ trường danh mục, không từ tên hay mô tả. Hoạt chất là ràng buộc cứng.
- PRODUCT_SEARCH chỉ đọc, không gọi LLM, không tạo campaign. Câu "Tìm" chung chung không được mở rộng một lệnh CREATE thành tự chọn 10 sản phẩm.
- Snapshot có trường phiên `null` vẫn trả DATA_UNAVAILABLE cho views, cart, growth và revenue_low_conversion. Đây là đúng thiết kế, giữ nguyên.
- Giữ CRLF, diff tối thiểu, không reformat. Không đụng `.runtime/` và các service ngoài phạm vi.

## 6. Nghiệm thu (chạy lại 97 case Builder qua UI, policy assisted)

- **Tự động:** unittest toàn bộ OK; 200/200 PASS; `node --check` OK; `php -l` OK; `git diff --check` sạch; EOL không đổi.
- **L1, 26 case:**
  - Mọi prompt chỉ có danh mục: PRODUCT_SEARCH, `current_stage QUERY_RESULTS`, có `plan.query_result`.
    - Status là RESULTS_READY với sản phẩm đúng nhánh danh mục: mỗi dòng có `danh_muc_day_du` chứa segment tương ứng.
    - Status là EMPTY_RESULTS nếu catalog 2.473 sản phẩm không có sản phẩm nào thuộc nhánh đó.
    - Không còn `INTENT_REVIEW` hay UNKNOWN. "Tìm mặt nạ chăm sóc da." và "Tìm nước tẩy trang." đều là PRODUCT_SEARCH.
  - Thương hiệu CeraVe: các dòng đầu là CeraVe, có hiện "Thương hiệu".
  - "mỗi thương hiệu tối đa 2": không thương hiệu nào xuất hiện quá 2 lần, `limit 10`.
  - Chỉ có phong cách: NEEDS_INPUT ở QUERY_RESULTS, kèm câu hỏi "Hãy chọn ít nhất một điều kiện lọc…".
  - Lựa chọn "phục hồi hàng rào da" đã bị bỏ khỏi Builder, nên chỉ còn tạo được 96 case. Nếu gõ tay câu này: NEEDS_INPUT "chưa có ánh xạ".
- **L2, 8 case:** "tồn kho trên 100 sản phẩm" cho `limit 10` (hoặc Top N đã chọn) và `stock_min 101`. "tối đa 10 sản phẩm, còn hàng" cho `stock_min 1`, `stock_max 10`. Kết quả RESULTS_READY hoặc EMPTY_RESULTS, không còn câu hỏi 1–50.
- **L3:**
  - Kho AUTO-DEFAULT: RESULTS_READY, mọi dòng hiện "Kho: AUTO-DEFAULT", `complete` có thể là `false` kèm warning (sản phẩm chưa có lô).
  - Lô AUTO-1-20260928: RESULTS_READY, có sản phẩm 1 và "Lô: AUTO-1-20260928".
- **L4, 28 case:** Builder chỉ cho chọn một tiêu chí bán hàng.
  - Chọn một tiêu chí: toàn kỳ và giảm giá/bán chậm cho kết quả như trước. Views, cart, growth, doanh thu/chuyển đổi là DATA_UNAVAILABLE (đúng thiết kế). Bán chạy 30 hoặc 7 ngày cho RESULTS_READY hoặc DATA_UNAVAILABLE tùy snapshot.
  - Gõ tay câu có nhiều tiêu chí cũ (kể cả 30 ngày + 7 ngày, hoặc kỳ + toàn kỳ): luôn NEEDS_INPUT với câu hỏi chọn một tiêu chí hoặc một kỳ. Không còn trường hợp âm thầm chọn.
- **L5:** không còn prompt dưới 12 ký tự ("Tìm sản phẩm serum.", "Tìm sản phẩm dầu xả."). Nút bị tắt kèm gợi ý khi chưa chọn gì hoặc thiếu mã kho/lô.
- **L6:** hai câu "đủ hàng … livestream" và câu dài đều là PRODUCT_SEARCH với plan như T7.7, không còn NEEDS_INPUT `product`. Builder phát ra "(đủ bán khi live)".
- **Không hồi quy:** toàn bộ danh sách trong T7.8. Ngoài ra "Lọc 3 sữa rửa mặt …, tạo livestream lúc 20h00 ngày 14/10/2026 …" vẫn là CREATE_CAMPAIGN với 3 sản phẩm, và "Tối mai … mã 93" vẫn chọn sản phẩm 93.
