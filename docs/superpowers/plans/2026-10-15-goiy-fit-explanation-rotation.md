# Trang gợi ý (r=goiy): "Vì sao Syna chọn cho bạn" + luân phiên gợi ý 3 phút — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **KHÔNG commit** (AGENTS.md: chỉ commit khi người dùng yêu cầu).

**Goal:** Thay box "Vì sao hợp với bạn" đang đếm điều kiện ("Hỗ trợ 1/2 vấn đề…") bằng lời giải thích theo cấu trúc **Tình trạng → Vấn đề → Giải pháp (hoạt chất)** lấy đúng từ khảo sát da; đồng thời cho sản phẩm chính mỗi bước tự luân phiên sau 3 phút trong nhóm sản phẩm có độ phù hợp tương đương, có điều khiển tạm dừng/hoàn tác.

**Architecture:** Một service PHP thuần (không DB) `RecommendationExplainer` dựng dữ liệu có cấu trúc `fit_explanation` từ hồ sơ + `evidence` mà `GoiYContentBased::evaluateCompatibility()` đã tính sẵn. `HomeController::formatGoiyRoutineProduct()` gắn `fit_explanation` cho mọi sản phẩm (chính + thay thế) trước khi xoá các trường dài. View render lần đầu phía server; JS render lại khi đổi sản phẩm (bấm tay hoặc luân phiên) theo đúng cùng một markup.

**Tech Stack:** PHP 8.2 (container `skinsyntax-php`, backend mount `/var/www/html`, frontend mount `/var/www/frontend` read-only), vanilla JS ES5-style (IIFE), CSS thuần. Trang chạy qua Docker nginx `http://localhost:8080`, nginx phục vụ static từ `./frontend/public`.

**Spec:** Yêu cầu người dùng trong hội thoại (2026-10-15) + ảnh chụp trang `index.php?r=goiy`.

## Global Constraints

- Mọi file đích ĐÃ có thay đổi chưa commit của người dùng → chỉ sửa đúng chỗ (Edit), không ghi đè/định dạng lại cả file, không revert gì.
- JS được phục vụ là `frontend/public/assets/js/goiy-recommendation.js`; CSS là `frontend/public/assets/css/goiy-recommendation.css`. KHÔNG sửa bản cũ ở `assets/js/` và `public/assets/js/` (bản lệch, không được nạp trên Docker).
- Mọi chuỗi đưa vào HTML qua `h()` (PHP) hoặc `textContent` (JS). Cấm `innerHTML` với dữ liệu sản phẩm.
- Không bịa công dụng: hoạt chất chỉ được nhận diện từ trường thành phần (`key_ingredients`, `thanh_phan_chinh`, `thanh_phan_day_du`), không từ mô tả marketing.
- Văn bản hiển thị tiếng Việt có dấu, giữ đúng câu chữ ghi trong plan.
- Không đổi response shape của API khác; `fit_explanation` là khoá MỚI, thêm vào, view phải chạy được khi khoá này vắng (cache session cũ).
- Lệnh PHP chạy trong container: `docker exec skinsyntax-php php …` (đường dẫn backend trong container: `/var/www/html/...`, frontend: `/var/www/frontend/...`).

## Review Focus

1. Cache session cũ (`goiy_routine_*`, TTL 600s) chưa có `fit_explanation` → box phải rơi về danh sách lý do cũ, không lỗi/notice. (Task 2 đổi cache key; Task 3 test fallback.)
2. Người dùng đang trỏ chuột/tab focus vào card hoặc đã tự chọn sản phẩm → luân phiên KHÔNG được đổi card đó (tránh bấm "Thêm vào giỏ" nhầm sản phẩm). (Task 4.)
3. Hồ sơ thiếu trường (không có loại da / không có vấn đề / goal lạ) → explainer trả cấu trúc rỗng hợp lệ, view ẩn hàng tương ứng. (Task 1 T3, Task 3.)
4. Tab trình duyệt bị ẩn → đếm ngược dừng, không dồn nhiều lượt đổi khi quay lại. (Task 4.)
5. Điểm phần trăm ở ô thay thế: 80 hiển thị "8%" (bug `rtrim` hiện tại) — phải hiển thị "80%", 100 → "100%". (Task 3.)

---

### Task 1: Service `RecommendationExplainer` (PHP thuần) + test

**Files:**
- Create: `backend/app/services/RecommendationExplainer.php`
- Create: `backend/tests/recommendation_explainer_test.php` (theo kiểu runner PASS/FAIL của `backend/tests/product_query_cache_test.php`, không cần DB, exit code 1 nếu có FAIL)

**Interfaces:**
- Consumes: `GoiYContentBased` (public, không cần DB cho các hàm này): `canonicalSkinType(?string): string` (`oily|dry|combination|normal|unknown`), `canonicalConcerns($raw): array` (id ∈ `inflammatory_acne, comedonal_acne, large_pores, hyperpigmentation, aging, dry_flaky`), `canonicalGoal(?string): string`. Test tạo bằng `new GoiYContentBased(null)`.
- Produces:
  - `final class RecommendationExplainer { public function __construct(GoiYContentBased $taxonomy) }`
  - `public function explain(array $profile, array $row): array` → luôn trả đúng shape:
    ```php
    [
      'skin'     => ['label' => string, 'sensitivity' => string, 'note' => string],
      'concerns' => [['id' => string, 'label' => string, 'matched' => bool], ...], // theo thứ tự trong hồ sơ
      'goal'     => ['label' => string, 'matched' => bool] | null,
      'solution' => string,
      'actives'  => [['name' => string, 'benefit' => string], ...],               // tối đa 2
      'caution'  => string,
    ]
    ```
  - `public static function stepsForConcern(string $concernId): array` (danh sách category routine)

**Quy tắc nội dung (chép đúng câu chữ):**

- `$profile` dùng: `skin_type`, `concerns` (mảng chuỗi thô), `goal` (hoặc `muc_tieu_cham_soc`), `sensitivity`.
- `$row` dùng: `routine_step`, `key_ingredients` (mảng), `thanh_phan_chinh`, `thanh_phan_day_du`, `ten_san_pham`, `mo_ta`, `evidence` (`evidence['skin_type']['match']` ∈ `true|'all_skin'|false`, `evidence['concerns']['matched']` mảng id, `evidence['goal']['match']` bool). Thiếu khoá nào thì coi như rỗng/false.
- Chuẩn hoá text: lowercase + bỏ dấu tiếng Việt + gộp khoảng trắng (cùng bảng map như `GoiYContentBased::normalizeText`, hàm private riêng trong service).
- `skin.label` = `trim(skin_type)` nguyên văn (vd `Da khô/Hỗn hợp khô`); rỗng nếu không có.
- `skin.sensitivity` theo `sensitivity` đã chuẩn hoá: chứa `rat de` → `dễ kích ứng`; `thinh thoang` → `thỉnh thoảng kích ứng`; `khoe manh` hoặc `hiem khi` → `ít kích ứng`; còn lại `''`.
- `skin.note`: skin match `true` → `Sản phẩm ghi rõ dành cho nền da này.`; `'all_skin'` → `Công thức dùng được cho mọi loại da.`; khác → `''`. Nếu sensitivity là `dễ kích ứng`/`thỉnh thoảng kích ứng` VÀ text chuẩn hoá của `ten_san_pham + mo_ta` chứa `nhay cam`|`diu nhe`|`lanh tinh` → nối thêm (cách 1 dấu cách) `Công thức dịu nhẹ, có ghi phù hợp da nhạy cảm.`
- `concerns`: duyệt từng chuỗi thô, map qua `canonicalConcerns([$raw])[0]`; gom các chuỗi thô cùng id theo thứ tự xuất hiện, `label = implode(', ', raws)` rồi viết hoa ký tự đầu (mb-safe). Bỏ chuỗi không map được. `matched = in_array(id, evidence.concerns.matched, true)`.
- `goal`: `canonicalGoal(goal) !== ''` → `['label' => trim(goal), 'matched' => evidence.goal.match === true]`; ngược lại `null`.
- `solution` = câu mục đích theo `routine_step` (khác → `''`):
  - `remover`: `Lấy sạch kem chống nắng, bụi bẩn và dầu thừa cuối ngày — gốc rễ của bít tắc.`
  - `cleanser`: `Làm sạch dầu thừa, bụi bẩn trong lỗ chân lông, chuẩn bị nền da cho các bước sau.`
  - `toner`: `Cân bằng da sau rửa mặt, giúp serum và kem dưỡng thấm tốt hơn.`
  - `treatment`: `Đưa hoạt chất tập trung vào đúng vấn đề da bạn đang gặp.`
  - `moisturizer`: `Khóa ẩm và củng cố hàng rào bảo vệ da.`
  - `sunscreen`: `Chặn tia UV — nguyên nhân khiến thâm mụn đậm hơn và da lão hóa sớm.`
  - `spot_treatment`: `Tác động tập trung lên từng nốt mụn đang viêm.`
- `stepsForConcern`: `inflammatory_acne → [spot_treatment, treatment]`, `comedonal_acne → [treatment, cleanser, toner]`, `large_pores → [toner, treatment]`, `hyperpigmentation → [treatment, sunscreen]`, `aging → [treatment, sunscreen, moisturizer]`, `dry_flaky → [moisturizer, toner, treatment]`, id lạ → `[]`.
- `actives`: haystack = text chuẩn hoá của `implode(' ', key_ingredients) . ' ' . thanh_phan_chinh . ' ' . thanh_phan_day_du`. Mỗi entry của bảng KB khớp nếu `preg_match('/\b(?:' . keys . ')\b/u', haystack)`. Bảng KB (thứ tự = thứ tự tie-break; `keys` là regex trên text đã bỏ dấu):

  | keys | name | benefit | concerns | skins | steps | strong |
  |---|---|---|---|---|---|---|
  | `zinc oxide\|titanium dioxide\|tinosorb\|uvinul\|avobenzone\|octocrylene\|homosalate\|octinoxate\|ethylhexyl methoxycinnamate` | Màng lọc UV | chống tia UVA/UVB, ngừa thâm sạm | hyperpigmentation, aging | | sunscreen | |
  | `salicylic\|bha` | BHA (Salicylic Acid) | làm sạch sâu lỗ chân lông, giảm mụn ẩn, đầu đen | comedonal_acne, large_pores, inflammatory_acne | oily, combination | | ✓ |
  | `benzoyl peroxide` | Benzoyl Peroxide | diệt khuẩn gây mụn viêm | inflammatory_acne | | | ✓ |
  | `retinol\|retinal\|retinoid\|tretinoin\|adapalene` | Retinoid | tái tạo da, giảm mụn và nếp nhăn | aging, comedonal_acne, hyperpigmentation | | | ✓ |
  | `glycolic\|lactic acid\|mandelic\|aha` | AHA | tẩy tế bào chết, làm mịn và sáng da | hyperpigmentation, aging, comedonal_acne | | | ✓ |
  | `azelaic` | Azelaic Acid | giảm mụn viêm, mờ thâm đỏ | inflammatory_acne, hyperpigmentation | | | |
  | `niacinamide\|vitamin b3\|b3` | Niacinamide (B3) | giảm viêm đỏ, mờ thâm, điều tiết dầu | inflammatory_acne, hyperpigmentation, large_pores | oily, combination | | |
  | `tea tree\|tram tra` | Tràm trà | kháng khuẩn, giảm mụn viêm | inflammatory_acne, comedonal_acne | oily | | |
  | `mat ong\|honey\|propolis\|keo ong` | Mật ong / keo ong | kháng khuẩn dịu nhẹ, làm dịu nốt mụn | inflammatory_acne, dry_flaky | dry | | |
  | `centella\|rau ma\|madecassoside\|asiaticoside\|cica` | Rau má (Centella) | làm dịu, giảm sưng đỏ, hỗ trợ phục hồi | inflammatory_acne | | | |
  | `zinc(?! oxide)` | Kẽm (Zinc) | kiểm soát dầu, giảm mụn | inflammatory_acne, comedonal_acne, large_pores | oily | | |
  | `sulfur\|luu huynh` | Lưu huỳnh | hút dầu, làm khô cồi mụn | inflammatory_acne, comedonal_acne | oily | | |
  | `tranexamic` | Tranexamic Acid | mờ thâm nám | hyperpigmentation | | | |
  | `arbutin` | Arbutin | ức chế sắc tố, mờ thâm | hyperpigmentation | | | |
  | `vitamin c\|ascorbic\|ascorbyl` | Vitamin C | làm sáng, mờ thâm, chống oxy hóa | hyperpigmentation, aging | | | |
  | `peptide` | Peptide | hỗ trợ săn chắc, giảm nếp nhăn | aging | | | |
  | `bakuchiol` | Bakuchiol | chống lão hóa dịu nhẹ | aging | | | |
  | `hyaluronic\|hyaluronate\|ha` | HA (Hyaluronic Acid) | cấp nước, giữ ẩm cho da căng mịn | dry_flaky | dry, combination, normal | | |
  | `ceramide` | Ceramide | củng cố hàng rào da, giảm khô căng bong tróc | dry_flaky | dry | moisturizer | |
  | `panthenol\|vitamin b5\|b5` | Panthenol (B5) | làm dịu, phục hồi da khô căng | dry_flaky, inflammatory_acne | dry | | |
  | `squalane\|squalene` | Squalane | khóa ẩm mỏng nhẹ | dry_flaky | dry | moisturizer | |
  | `beta glucan` | Beta-glucan | làm dịu, cấp ẩm | dry_flaky, inflammatory_acne | | | |
  | `allantoin` | Allantoin | làm dịu, làm mềm da | dry_flaky, inflammatory_acne | | | |
  | `snail\|oc sen` | Dịch ốc sên | phục hồi, làm dịu, mờ thâm | hyperpigmentation, dry_flaky | | | |
  | `collagen` | Collagen | giữ ẩm, hỗ trợ đàn hồi | aging, dry_flaky | | | |
  | `tra xanh\|green tea\|camellia sinensis` | Trà xanh | chống oxy hóa, kiểm soát dầu | inflammatory_acne, large_pores | oily | | |
  | `amino acid\|axit amin` | Amino acid | làm sạch dịu nhẹ, hạn chế khô căng | dry_flaky | dry | cleanser | |
  | `micelle\|micellar` | Công nghệ Micellar | cuốn sạch bụi bẩn, lớp trang điểm dịu nhẹ | | | remover | |
  | `glycerin` | Glycerin | hút ẩm, giữ da mềm | dry_flaky | dry | | |
  | `vitamin e\|tocopherol` | Vitamin E | chống oxy hóa, dưỡng mềm da | | dry | | |

  - Điểm entry = `4 × |concerns ∩ id vấn đề của user|` + `2 × (canonicalSkinType(user) ∈ skins)` + `1 × (routine_step ∈ steps)`. Sắp xếp giảm dần theo điểm, hoà thì giữ thứ tự bảng (sort ổn định).
  - Tên hiển thị: chip đầu tiên trong `key_ingredients` mà text chuẩn hoá của chip khớp regex của entry → dùng chip gốc (trim, viết hoa ký tự đầu, `mb_strimwidth(…, 0, 42, '…', 'UTF-8')`); không có chip khớp → `name` của bảng. Bỏ entry trùng tên hiển thị (so lowercase).
  - Lấy tối đa 2 entry có điểm > 0; nếu không entry nào > 0 thì lấy 1 entry đầu tiên khớp (nếu có).
- `caution`: nếu có entry `strong` khớp (xét trên toàn bộ entry khớp, không chỉ 2 entry hiển thị; lấy entry strong đầu tiên theo thứ tự bảng, dùng `name` của bảng): sensitivity là `dễ kích ứng`/`thỉnh thoảng kích ứng` → `Có {name}: da bạn dễ kích ứng, nên dùng cách ngày và thử trước ở vùng nhỏ.`; ngược lại → `Có {name}: bắt đầu 2–3 lần/tuần và dùng kem chống nắng ban ngày.`; không có → `''`.

- [ ] **Step 1: Viết test (fail trước)** — `backend/tests/recommendation_explainer_test.php`, `error_reporting(E_ALL)` + `set_error_handler` ném `ErrorException` để notice/warning thành FAIL. Fixture chung: profile `['skin_type' => 'Da khô/Hỗn hợp khô', 'concerns' => ['Mụn viêm', 'sưng đỏ', 'Da khô căng, bong tróc'], 'goal' => 'Sạch mụn, giảm viêm', 'sensitivity' => 'Khỏe mạnh, hiếm khi']`; row `routine_step = 'remover'`, `key_ingredients = ['công nghệ micelle complex', 'chiết xuất mật ong lên men', 'hệ vitamin (b3, c, e)', 'hệ dưỡng ẩm sâu ha (ha, super ha, nano ha)']`, `evidence = ['skin_type' => ['match' => true], 'concerns' => ['matched' => ['inflammatory_acne']], 'goal' => ['match' => true]]`.
  - T1 (ca ảnh chụp): `skin === ['label' => 'Da khô/Hỗn hợp khô', 'sensitivity' => 'ít kích ứng', 'note' => 'Sản phẩm ghi rõ dành cho nền da này.']`; `concerns === [['id' => 'inflammatory_acne', 'label' => 'Mụn viêm, sưng đỏ', 'matched' => true], ['id' => 'dry_flaky', 'label' => 'Da khô căng, bong tróc', 'matched' => false]]`; `goal === ['label' => 'Sạch mụn, giảm viêm', 'matched' => true]`; `solution` = câu `remover`; `array_column(actives, 'name') === ['Chiết xuất mật ong lên men', 'Hệ dưỡng ẩm sâu ha (ha, super ha, nano ha)']`; `actives[0]['benefit'] === 'kháng khuẩn dịu nhẹ, làm dịu nốt mụn'`; `caution === ''`.
  - T2 strong + nhạy cảm: profile `skin_type 'Da dầu/Hỗn hợp dầu'`, `concerns ['Mụn ẩn, mụn đầu đen']`, `sensitivity 'Rất dễ'`; row `routine_step 'treatment'`, `key_ingredients []`, `thanh_phan_chinh 'Salicylic Acid 2%, Niacinamide'` → `actives[0]['name'] === 'BHA (Salicylic Acid)'`; `caution === 'Có BHA (Salicylic Acid): da bạn dễ kích ứng, nên dùng cách ngày và thử trước ở vùng nhỏ.'`; `skin.sensitivity === 'dễ kích ứng'`.
  - T3 rỗng: `explain([], [])` === shape rỗng: skin 3 chuỗi `''`, `concerns []`, `goal null`, `solution ''`, `actives []`, `caution ''`; không notice.
  - T4 chống nắng: row `routine_step 'sunscreen'`, `thanh_phan_day_du 'Zinc Oxide, Titanium Dioxide'` → tên `Màng lọc UV` có trong actives, `Kẽm (Zinc)` không có.
  - T5 `stepsForConcern('dry_flaky') === ['moisturizer', 'toner', 'treatment']`; `stepsForConcern('xyz') === []`.
  - T6 `evidence.skin_type.match = 'all_skin'` → `skin.note === 'Công thức dùng được cho mọi loại da.'`.
  - T7 sensitivity `Thỉnh thoảng`, `mo_ta 'Kết cấu dịu nhẹ cho da nhạy cảm'`, skin match false → `skin.note === 'Công thức dịu nhẹ, có ghi phù hợp da nhạy cảm.'`.
  - T8 chip không khớp KB (`['Hương hoa hồng']`, không thành phần khác) → `actives === []`.
- [ ] **Step 2: Chạy, xác nhận FAIL** — `docker exec skinsyntax-php php /var/www/html/tests/recommendation_explainer_test.php` → lỗi class không tồn tại.
- [ ] **Step 3: Viết `RecommendationExplainer.php`** (`require_once __DIR__ . '/../models/GoiYContentBased.php';`), KB là `private const`.
- [ ] **Step 4: Chạy lại** — cùng lệnh → toàn bộ PASS, exit 0. `docker exec skinsyntax-php php -l /var/www/html/app/services/RecommendationExplainer.php` → `No syntax errors`.

### Task 2: Gắn `fit_explanation` trong HomeController

**Files:**
- Modify: `backend/app/controllers/HomeController.php` — require ở đầu file (cạnh dòng 10), `fetchLlamaIndexRecommendations` (~3247-3254), `buildContentBasedRoutineProducts` (~3328), `fetchAiRoutineProducts` (~3372), `formatGoiyRoutineProduct` (~3378-3403).

**Interfaces:**
- Consumes: `RecommendationExplainer::__construct(GoiYContentBased)`, `explain(array $profile, array $row): array` (Task 1).
- Produces: mỗi sản phẩm trong `$llamaResult['products']` có khoá `fit_explanation` (shape Task 1). Chữ ký mới: `private function formatGoiyRoutineProduct(array $row, array $profile = []): array`.

- [ ] **Step 1:** Thêm `require_once __DIR__ . '/../services/RecommendationExplainer.php';`; thêm property `private ?RecommendationExplainer $recommendationExplainer = null;` và tạo lazy từ `$this->goiYModel` khi cần.
- [ ] **Step 2:** Trong `formatGoiyRoutineProduct`, gán `$row['fit_explanation'] = …->explain($profile, $row);` NGAY TRƯỚC dòng `unset($row['mo_ta'], …, $row['evidence']);` (explainer cần `evidence`, `mo_ta`, `thanh_phan_day_du`). Hai nơi gọi truyền `$profile` vào.
- [ ] **Step 3:** Thêm phần tử `'fit_explanation_v1'` vào mảng được `json_encode` để tạo `$cacheKey` (cache session cũ không có khoá mới sẽ tự hết hiệu lực).
- [ ] **Step 4: Kiểm tra** — `docker exec skinsyntax-php php -l /var/www/html/app/controllers/HomeController.php` → `No syntax errors`; chạy lại test Task 1 → PASS.

### Task 3: View render server-side + sửa bug % + chú thích "bước khác đảm nhận" + CSS box

**Files:**
- Modify: `frontend/views/partials/recommendation_step_card.php` (dòng 71-88 helper, 159-164 box)
- Modify: `frontend/views/goiy.php` (sau vòng gán safety ~280-288; dòng 394 và 961 bump `?v=5` → `?v=6`)
- Modify: `frontend/public/assets/css/goiy-recommendation.css` (sau khối `.step-card__why …` ~637-669; khối `prefers-reduced-motion` ~1161)
- Create: `backend/tests/recommendation_step_card_render_test.php`

**Interfaces:**
- Consumes: `fit_explanation` (Task 2), `RecommendationExplainer::stepsForConcern()` (Task 1; class đã được HomeController nạp khi render view).
- Produces cho Task 4:
  - Payload JSON đổi sản phẩm (`data-product` của nút thay thế và `<script data-current-product>`) có thêm khoá `fit` = `fit_explanation` đã chuẩn hoá hoặc `null`.
  - Mỗi concern có thêm `handled_by` (string, có thể `''`).
  - Markup box (JS phải dựng y hệt):
    ```html
    <div class="step-card__why">
      <strong class="step-card__why-title"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Vì sao Syna chọn cho bạn</strong>
      <div class="fit-explain" data-product-fit>               <!-- hidden khi không có fit -->
        <div class="fit-explain__row">                          <!-- bỏ hàng nếu skin.label === '' -->
          <span class="fit-explain__label">Tình trạng</span>
          <p class="fit-explain__text"><b>{skin.label}</b>{' · ' . sensitivity nếu có} <span class="fit-explain__note">{note}</span></p>
        </div>
        <div class="fit-explain__row">                          <!-- bỏ nếu concerns rỗng -->
          <span class="fit-explain__label">Vấn đề</span>
          <ul class="fit-explain__concerns">
            <li class="is-matched"><i class="fa-solid fa-check" aria-hidden="true"></i> {label}</li>
            <li class="is-pending" title="Sản phẩm này chưa nhắm trực tiếp vấn đề này">{label}<small> · bước {handled_by} đảm nhận</small></li>  <!-- <small> chỉ khi handled_by !== '' -->
          </ul>
        </div>
        <div class="fit-explain__row">                          <!-- bỏ nếu solution === '' và actives rỗng -->
          <span class="fit-explain__label">Giải pháp</span>
          <div class="fit-explain__text">
            <p>{solution}</p>
            <ul class="fit-explain__actives"><li><b>{name}</b> — {benefit}</li></ul>
          </div>
        </div>
        <p class="fit-explain__goal"><i class="fa-solid fa-bullseye" aria-hidden="true"></i> Đúng mục tiêu: {goal.label}</p>  <!-- chỉ khi goal.matched -->
        <p class="fit-explain__caution"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> {caution}</p>              <!-- chỉ khi caution !== '' -->
      </div>
      <ul data-product-why>…lý do cũ…</ul>                       <!-- hidden khi có fit -->
      …disclaimer + .step-card__actions giữ nguyên…
    </div>
    ```
  - "Có fit" = `fit` là mảng và (skin.label !== '' hoặc concerns không rỗng hoặc solution !== '' hoặc actives không rỗng).

- [ ] **Step 1: Viết render test (fail trước)** — `backend/tests/recommendation_step_card_render_test.php`: `define('BASE_URL', '')` nếu chưa có, `require_once '/var/www/frontend/views/helpers.php'` (thử cả đường dẫn tương đối `__DIR__ . '/../../frontend/views/helpers.php'` khi chạy ngoài container), dựng `$step` fixture (sản phẩm chính có `fit_explanation` như kết quả T1 Task 1 + `handled_by` = `'Dưỡng ẩm'` cho `dry_flaky`; 1 alternative `match_score 80`, 1 alternative `match_score 100`), `ob_start(); include …/partials/recommendation_step_card.php; $html = ob_get_clean();`. Assert:
  - chứa `Vì sao Syna chọn cho bạn`, `Tình trạng`, `Da khô/Hỗn hợp khô`, `ít kích ứng`, `Mụn viêm, sưng đỏ`, `bước Dưỡng ẩm đảm nhận`, `Chiết xuất mật ong lên men`, `Đúng mục tiêu: Sạch mụn, giảm viêm`;
  - không chứa `Hỗ trợ 1/2`; `data-product-why` có thuộc tính `hidden`;
  - ô thay thế hiển thị `>80%<` và `>100%<`, KHÔNG có `>8%<` / `>1%<`;
  - `data-current-product` JSON decode được và có khoá `fit` là mảng;
  - Fallback: fixture KHÔNG có `fit_explanation`, `reasons = ['Lý do cũ A']` → `data-product-fit` có `hidden`, html chứa `Lý do cũ A`, không notice;
  - XSS: `skin_type` label `<script>x</script>` → html chứa `&lt;script&gt;`.
- [ ] **Step 2: Chạy, xác nhận FAIL** — `docker exec skinsyntax-php php /var/www/html/tests/recommendation_step_card_render_test.php`.
- [ ] **Step 3: Sửa partial** — `$formatScore` → `(string)(int)round((float)$value) . '%'`; thêm closure `$normalizeFit(mixed): ?array` (ép kiểu theo shape Task 1 + `handled_by`, chuỗi `trim`, tối đa 2 actives); `$swapPayload` thêm `'fit' => $normalizeFit($item['fit_explanation'] ?? null)`; render box đúng markup trên; tiêu đề đổi thành `Vì sao Syna chọn cho bạn`.
- [ ] **Step 4: Sửa `goiy.php`** — closure `$annotateHandoff(array $routine): array` chạy cho `$amRoutine` và `$pmRoutine` sau vòng gán safety: map `category → step_name` của buổi đó; với `recommended_product` và từng `alternatives[]` có `fit_explanation['concerns']`, concern `matched === false` → `handled_by` = `step_name` của category đầu tiên trong `RecommendationExplainer::stepsForConcern(id)` có mặt trong buổi đó và khác category của bước hiện tại; không có → `''`. Concern matched → `handled_by = ''`. Bọc `class_exists('RecommendationExplainer')`. Bump `?v=5` → `?v=6` cho CSS (dòng 394) và JS (dòng 961).
- [ ] **Step 5: CSS** — thêm style cho `.step-card__why-title`, `.fit-explain`, `.fit-explain__row` (grid 2 cột: nhãn 74px + nội dung; ≤575px xếp 1 cột), `.fit-explain__label` (chữ hoa nhỏ, màu `--rec-moss-700`), `.fit-explain__note` (màu `--rec-muted`), `.fit-explain__concerns li.is-matched` (màu `--rec-moss-900`, icon `--rec-moss-500`), `li.is-pending` (màu `--rec-muted`), `.fit-explain__actives`, `.fit-explain__goal`, `.fit-explain__caution` (màu `--rec-warn`, nền `--rec-warn-bg`). Chỉ dùng token `--rec-*` sẵn có; giữ box gọn (font .78–.8rem, line-height 1.45).
- [ ] **Step 6: Kiểm tra** — render test PASS; `docker exec skinsyntax-php php -l` cho 2 file view (đường dẫn `/var/www/frontend/views/...`) → `No syntax errors`.

### Task 4: JS — render box khi đổi sản phẩm + luân phiên 3 phút

**Files:**
- Modify: `frontend/public/assets/js/goiy-recommendation.js` (applyProductToCard 131-139; click handler 285-302; cuối IIFE 524-527)
- Modify: `frontend/views/goiy.php` (root dòng 880; day-switcher dòng 911; toast trước dòng 955)
- Modify: `frontend/public/assets/css/goiy-recommendation.css`

**Interfaces:**
- Consumes: payload `fit` + markup box (Task 3).
- Produces (nội bộ file): `renderFit(card, fit, why)`, `swapWithAlternative(card, button) -> boolean`, event `goiy:rotate-now` trên root (hook QA, chạy 1 lượt luân phiên ngay).

**Markup thêm vào `goiy.php`:**
- Root `.recommendation-workspace` thêm `data-rotate-interval="180000"`.
- Trong `.recommendation-day-switcher`, ngay sau `<h2 data-session-heading>`:
  `<div class="recommendation-rotation" data-rotation-control hidden><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> <span data-rotation-status>Gợi ý luân phiên sau 3:00</span> <button type="button" data-rotation-toggle>Tạm dừng</button></div>`
- Trước khối combo feedback: `<div class="recommendation-toast" data-rotation-toast role="status" aria-live="polite" hidden><span data-rotation-toast-text></span><button type="button" data-rotation-undo>Hoàn tác</button></div>`

**Hành vi (quyết định đã chốt):**
- `renderFit(card, fit, why)`: dựng đúng markup Task 3 bằng `createElement` + `textContent`; có fit → hiện `[data-product-fit]`, ẩn `[data-product-why]`; không fit → ẩn fit, render `why` (≤3) vào `[data-product-why]` như code cũ. `applyProductToCard` gọi `renderFit(card, product.fit, product.why)` thay cho khối whyList cũ.
- `swapWithAlternative(card, button)`: tách nguyên thân click handler hiện tại (parse → apply → cập nhật `data-current-product` → `renderAlternative` → `updateComboTotal` → `activateStep`). Click tay: đặt `card.dataset.userPicked = 'true'` rồi gọi hàm này.
- Pool luân phiên: lúc init, `topScore` của card = điểm cao nhất trong sản phẩm chính + các thay thế; `threshold = max(60, topScore - 15)` (không có điểm → 60). Nút thay thế đủ điều kiện khi `match_score >= threshold`. Card "rotatable" khi có ≥1 nút đủ điều kiện. Không có card rotatable nào (cả 2 buổi) → giữ control `hidden`, không chạy timer.
- Chọn sản phẩm kế tiếp: nút đủ điều kiện có `lastShownAt` nhỏ nhất (chưa từng hiện = 0), hoà → điểm cao hơn. Lưu `lastShownAt` theo product id (Map), cập nhật khi sản phẩm lên làm chính.
- Card bị bỏ qua khi: `card.matches(':hover')`, `card.contains(document.activeElement)`, `card.dataset.userPicked === 'true'`, hoặc `card.classList.contains('is-filtered-out')`. Chỉ xét card của buổi đang xem (`currentCards()`).
- Một timer `setInterval` 1000ms: chỉ trừ `remainingMs` khi đang bật và `!document.hidden`; cập nhật `[data-rotation-status]` = `Gợi ý luân phiên sau m:ss`; tới 0 → `rotateTick()` rồi `remainingMs = rotateMs`. Khi tắt: status = `Đã tạm dừng tự đổi gợi ý`, nút = `Bật tự đổi`; khi bật: nút = `Tạm dừng`.
- `rotateTick()`: với mỗi card hợp lệ (trễ `index × 120ms`): thêm class `is-rotating`, sau 250ms gọi `swapWithAlternative`, bỏ `is-rotating`, thêm `is-rotated` trong 1600ms. Ghi lại `{card, button}` để hoàn tác. Đổi ≥1 card → toast `Syna vừa làm mới {n} gợi ý có độ phù hợp tương đương.` hiện 8 giây. Hoàn tác: với từng bản ghi (ngược thứ tự), nếu `card.dataset.userPicked !== 'true'` → `swapWithAlternative(card, button)` (cùng nút đang giữ sản phẩm cũ), rồi ẩn toast. Bản ghi hoàn tác bị xoá khi lượt mới chạy hoặc toast tắt.
- Tuỳ chọn bật/tắt lưu `localStorage['skinsyntax.goiy.rotate']` (`'on'|'off'`), mọi đọc/ghi bọc `try/catch`. Mặc định: `on`, trừ khi `matchMedia('(prefers-reduced-motion: reduce)').matches` → `off`.
- `root.addEventListener('goiy:rotate-now', …)` → `rotateTick()` ngay và reset `remainingMs`.

- [ ] **Step 1:** Thêm markup vào `goiy.php`.
- [ ] **Step 2:** Viết `renderFit` + nối vào `applyProductToCard`; tách `swapWithAlternative`; click tay đặt `userPicked`.
- [ ] **Step 3:** Viết module luân phiên theo đúng các quy tắc trên.
- [ ] **Step 4: CSS** — `.recommendation-rotation` (pill nhỏ, chữ .74rem, màu `--rec-muted`, nút dạng link `--rec-moss-900`); `.recommendation-toast` (cố định góc dưới-trái để không đè nút chat góc phải, nền `--rec-moss-900`, chữ trắng, bo 12px, z-index trên combo bar); `.recommendation-step-card .step-card__body { transition: opacity .25s ease; }`, `.is-rotating .step-card__body { opacity: .15; }`, `.is-rotated { box-shadow: 0 0 0 2px var(--rec-moss-500); }` có transition; thêm các selector mới vào khối `prefers-reduced-motion` (transition: none).
- [ ] **Step 5: Kiểm tra** — `node --check frontend/public/assets/js/goiy-recommendation.js` → không lỗi; `docker exec skinsyntax-php php -l /var/www/frontend/views/goiy.php`; chạy lại 2 test PHP → PASS; `git diff --check` trên các file đã sửa → sạch.

### Task 6 (CHỜ NGƯỜI DÙNG DUYỆT — GitNexus risk CRITICAL): sửa taxonomy vấn đề da

Hiện `GoiYContentBased::canonicalConcerns()` kiểm tra `mb_strpos($norm, 'an')` và `'mun'` trước các nhóm khác, nên `Lỗ chân lông to`, `Thâm mụn, sạm nám, tàn nhang`, `Lão hóa, nếp nhăn` đều thành `comedonal_acne` (đã chạy thử trong container). Upstream: `evaluateCompatibility` → `goiy`, `xulygoiy`, `fetchLlamaIndexRecommendations`, `buildAiRoutineChatProducts` (6 điểm, CRITICAL). Sửa = đổi thứ tự kiểm tra: `dry_flaky` (`kho cang|bong troc`) → `inflammatory_acne` (`viem|sung do`) → `large_pores` (`lo chan long`) → `hyperpigmentation` (`tham|sam|nam|tan nhang`) → `aging` (`lao hoa|nep nhan`) → `comedonal_acne` (`mun an|dau den|mun`). Test kỳ vọng cho 6 lựa chọn khảo sát: `inflammatory_acne, comedonal_acne, large_pores, hyperpigmentation, aging, dry_flaky`.

### Task 5: Kiểm tra trên trình duyệt (Opus làm)

- [ ] Mở `http://localhost:8080/index.php?r=goiy` với tài khoản đã khảo sát; xác nhận box mới, ô thay thế hiện đúng %, bấm đổi tay cập nhật box, `root.dispatchEvent(new Event('goiy:rotate-now'))` đổi các card không bị hover/khoá, toast + Hoàn tác chạy, nút Tạm dừng lưu trạng thái sau reload, không lỗi console. Không có harness E2E (Playwright/Cypress) trong repo → ghi `E2E harness NOT RUN`, kiểm tra tay bằng trình duyệt.
