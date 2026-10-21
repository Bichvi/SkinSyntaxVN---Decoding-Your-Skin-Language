# BƯỚC 04 — PHÂN TÍCH Ý ĐỊNH TRUY VẤN (QUERY INTENT PARSER)

## Vai trò
Kỹ sư NLP/Python. Viết bộ phân tích truy vấn tìm kiếm tiếng Việt **tất định** (không LLM), tách ra hãng, danh mục, thành phần, loại da, vấn đề da, công dụng, khoảng giá.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (đặc biệt mục Bước 03: danh sách key danh mục/loại da/vấn đề da/benefit)
3. `docs/neo4j/schema.md` (quy tắc key)
4. `ai-service-flask/graph_sync/normalize.py` và `ai-service-flask/data/graph/synonyms.yaml` — **tái sử dụng** `normalize_key`, `strip_accents`, synonyms; không viết lại.
5. `backend/app/models/GoiYContentBased.php` hàm `strictIntentTokens`, `normalizeText` — tham khảo các intent và từ khóa người dùng hay gõ, chuyển thành synonym nếu thiếu.

## Bối cảnh
- Parser được Bước 05 (API gợi ý) gọi cho mỗi truy vấn → phải nhanh (**< 5ms** khi từ điển đã nạp).
- Ví dụ chuẩn: `torriden sữa rửa mặt` → `brands=[torriden]`, `categories=[sua_rua_mat]`.

## Cấu trúc
```
ai-service-flask/search_service/
  __init__.py
  config.py            # SEARCH_DICT_TTL=600, FUZZY_CUTOFF=88, MAX_QUERY_LEN=200
  dictionary.py        # EntityDictionary: load từ Neo4j, fallback Mongo distinct, cache TTL, thread-safe
  intent_parser.py     # parse(q, dictionary) -> Intent
  schemas.py           # dataclass/pydantic Intent, PriceRange
ai-service-flask/tests/search_service/test_intent_parser.py
```

## Hợp đồng output
```python
@dataclass(frozen=True)
class Intent:
    raw: str
    normalized: str               # đã bỏ dấu, lower, gộp space
    brands: list[str]             # key
    categories: list[str]
    ingredients: list[str]
    skin_types: list[str]
    concerns: list[str]
    benefits: list[str]
    price: PriceRange | None      # min_vnd, max_vnd (int | None)
    residual_terms: list[str]     # token không gán được entity
    confidence: float             # 0..1
    def to_dict(self) -> dict: ...
```

## Thuật toán
1. **Làm sạch**: cắt `MAX_QUERY_LEN`, bỏ ký tự điều khiển, giữ chữ/số/khoảng trắng/`-`/`%`/`.`; rỗng → Intent rỗng, confidence 0.
2. **Chuẩn hóa**: lower, bỏ dấu (dùng hàm của `graph_sync.normalize`), gộp khoảng trắng.
3. **Tách giá trước** (rồi xóa cụm giá khỏi chuỗi):
   - `dưới|duoi|<|tối đa|max` + số + đơn vị → max
   - `trên|tren|>|từ|tu` + số + đơn vị → min
   - `a - b` / `a đến b` / `khoảng a-b` → min, max
   - Đơn vị: `k|nghin|ngan` ×1 000, `tr|trieu|m` ×1 000 000, không đơn vị và số < 10 000 → hiểu là nghìn.
4. **Khớp từ điển**: sinh n-gram từ dài 4 → 1 token; **longest-match-first**, token đã dùng không dùng lại. Từ điển gồm tên + mọi synonym (đã chuẩn hóa) → (loại entity, key).
5. **Fuzzy cho brand** (chỉ token còn lại, độ dài ≥ 4): `rapidfuzz.process.extractOne(tok, brand_surface_forms, scorer=fuzz.WRatio, score_cutoff=FUZZY_CUTOFF)`. Thêm `rapidfuzz` vào requirements.
6. **Stopword** (`cho, va, cua, loai, danh, nao, tot, nhat, mua, gia, re`) → bỏ, không tính vào residual.
7. **confidence** = (số ký tự thuộc entity hoặc giá) / (số ký tự không phải stopword); làm tròn 2 số.
8. Không LLM. Khi confidence < 0.5, Bước 05 sẽ dựa nhiều hơn vào nhánh semantic — parser không cần làm gì thêm.

## EntityDictionary
- Nguồn chính: Neo4j — `MATCH (n:Brand) RETURN n.key, n.name` (tương tự Category, Ingredient, SkinType, Concern, Benefit), cộng `synonyms.yaml`, `ingredient_aliases.yaml`.
- Neo4j lỗi → fallback: Mongo `distinct` thương hiệu + synonyms YAML; ghi log cảnh báo 1 lần.
- Cache trong RAM, làm mới khi quá TTL (khóa `threading.Lock`, chỉ 1 thread nạp lại; thread khác dùng bản cũ).
- Nhận `driver`/`mongo` qua constructor (dependency injection) để test bằng dữ liệu giả.

## Kiểm thử (TDD — viết test trước). Tối thiểu 25 ca, dùng từ điển giả trong fixture:
| Input | Kỳ vọng chính |
|---|---|
| `torriden sữa rửa mặt` | brands=[torriden], categories=[sua_rua_mat], conf ≥ 0.9 |
| `sua rua mat torriden` | như trên |
| `toriden srm` | brands=[torriden] (fuzzy), categories=[sua_rua_mat] (synonym) |
| `TORRIDEN` | brands=[torriden] |
| `srm cho da dầu mụn` | categories=[sua_rua_mat], skin_types=[da_dau], concerns=[mun] |
| `toner không cồn dưới 300k` | categories=[toner], price.max=300000, residual chứa `khong`,`con` hoặc benefit tương ứng nếu có synonym |
| `serum vitamin c` | categories=[serum], ingredients=[ascorbic_acid hoặc key thật] |
| `kem chống nắng da nhạy cảm` | categories=[kem_chong_nang], skin_types=[da_nhay_cam] |
| `200-500 nghìn` | price 200000..500000 |
| `từ 1tr` | price.min=1000000 |
| `niacinamide` | ingredients=[niacinamide] |
| `mặt nạ cấp ẩm` | categories=[mat_na], benefits=[cap_am] |
| `""`, `"   "` | rỗng, conf 0 |
| `@@##$$` | rỗng, không exception |
| `'; MATCH (n) DETACH DELETE n //` | không exception, chỉ là residual |
| chuỗi 1000 ký tự | bị cắt, không exception, < 20ms |
| `sữa rửa mặt` vs `mặt` | longest-match: chỉ categories=[sua_rua_mat], không thêm entity "mặt" |
| `xyzabc` | residual=[xyzabc], conf 0 |
| + ≥ 7 ca khác lấy từ lịch sử tìm kiếm thật (trong handoff/audit) |
- Test EntityDictionary: TTL hết hạn → nạp lại; Neo4j lỗi → fallback; nhiều thread gọi cùng lúc không nạp trùng.
- Test hiệu năng đơn giản: 1000 lần parse < 2s.

## Kiểm chứng
- Chạy pytest (lệnh theo handoff), dán kết quả.
- Script nhỏ chạy parser với từ điển thật từ Neo4j cho 10 truy vấn trong bảng, in JSON.

## Đầu ra
Code + test + requirements. Mục "Bước 04" trong handoff: API công khai (`EntityDictionary`, `parse`), ví dụ output JSON cho `torriden sữa rửa mặt`, các synonym đã thêm. Không commit nếu chưa được yêu cầu.
