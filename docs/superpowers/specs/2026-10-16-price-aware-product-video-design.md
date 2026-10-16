# Video sản phẩm nói giá có hạn sử dụng

Ngày: 2026-10-16 · Trạng thái: chờ duyệt · Thay thế một phần [2026-10-14-product-video-design.md](2026-10-14-product-video-design.md)

## 1. Bối cảnh và mục tiêu

Theo spec 2026-10-14, video sản phẩm (`product_video`) không được nói giá dưới mọi dạng. Chế độ `live_and_product` thì cắt clip trang sản phẩm ngay trước phần `[Price]`. Lý do là video nằm trên trang sản phẩm nhiều tháng, trong khi giá có thể đổi.

Người dùng muốn Syna nói giá trong video sản phẩm: giá bán, giá tham khảo thị trường và giá flash sale kèm hạn ưu đãi. Mục tiêu là bán hàng tốt hơn.

Cách giải quyết rủi ro giá cũ: **video có nói giá thì có hạn sử dụng**. Video lưu lại những giá nó đã được phép nói. Trang sản phẩm chỉ hiện video khi các giá đó vẫn đúng, và tự ẩn video ngay khi giá đổi hoặc flash sale hết hạn.

**Tiêu chí chấp nhận**

1. Kịch bản `product_video` có thể nói giá bán, giá tham khảo thị trường và giá flash sale (khi có). Mọi con số trong lời thoại lấy từ CSDL.
2. Kịch bản nói một con số giá không khớp dữ liệu, hoặc nói "ưu đãi, có hạn" khi không có flash sale, sẽ bị bộ kiểm tra cứng (không dùng AI) đánh FAIL.
3. Video có nói giá tự biến mất khỏi trang sản phẩm khi `gia_ban` hoặc `gia_thi_truong` đổi, khi flash sale hết hạn, hoặc khi flash sale bị sửa hay tắt. Video cũ không có dữ liệu giá thì giữ nguyên hành vi hiện tại.
4. Clip trang sản phẩm của chế độ `live_and_product` giữ lại phần `[Price]` và chỉ cắt trước `[CTA]`.
5. Studio cho phép phong cách "Nhấn mạnh giá & ưu đãi" ở chế độ video sản phẩm và báo rõ khi một video đã bị ẩn vì giá không còn đúng.

**Ngoài phạm vi:** nút tạo lại video bằng một cú bấm; voucher và mã giảm giá trong video sản phẩm; số lượng còn lại; thay đổi gì ở chế độ `live_only`.

## 2. Quyết định

| # | Quyết định | Thay thế |
|---|---|---|
| P1 | `product_video` được nói giá. LLM không tự tạo số mà nhận **cụm chữ đọc sẵn** do backend sinh ra, ví dụ "hai trăm chín mươi chín nghìn đồng", và phải chép nguyên văn | Spec cũ mục 4.1, dòng `product_video` |
| P2 | Dữ liệu giá ("pricing facts") được lấy cùng lúc với snapshot sản phẩm và lưu tại `product_snapshot.pricing` | — |
| P3 | Chỉ nói flash sale khi campaign đang ACTIVE, `flash_price < gia_ban`, và **còn ít nhất 2 giờ** mới hết hạn | — |
| P4 | Phần trăm giảm do backend tính (làm tròn như PHP: `round((regular - flash) / regular * 100)`). Hạn ưu đãi đọc bằng ngày giờ cụ thể, **không bao giờ** dùng "hôm nay", "tối nay", "ngày mai" | — |
| P5 | Bộ kiểm tra cứng thay cho lệnh cấm: bỏ các cụm giá hợp lệ khỏi văn bản; nếu phần còn lại vẫn có con số tiền hoặc phần trăm thì FAIL. Từ khuyến mãi chỉ hợp lệ khi có flash sale | Spec cũ mục 4.3, `find_price_mentions` |
| P6 | Video lưu `price_claim`, gồm **toàn bộ** giá đã cung cấp cho kịch bản và bảng giá, không chỉ những giá được đọc lên. Không có pricing thì `price_claim = null` | — |
| P7 | Bộ lọc trang sản phẩm đặt trong PHP (`ProductVideo`) và dùng `FlashSaleCampaign::resolveEffectivePrice()`, nguồn giá chuẩn của giỏ hàng | — |
| P8 | Danh sách video trên trang sản phẩm vẫn cache 30 phút. Khi đọc từ cache, lọc lại theo `price_valid_until_ts` để video hết hạn biến mất **đúng giờ**. Thay đổi `gia_ban` hoặc nội dung campaign có thể chậm tối đa 30 phút, bằng độ trễ cache hiện có | — |
| P9 | `live_and_product`: clip trang sản phẩm cắt ở đầu `[CTA]` (hoặc `[Closing]` nếu thiếu CTA). Phần `[Price]` chỉ được dùng giá từ CSDL. Ưu đãi chỉ có trong live (voucher, "hôm nay", "chốt đơn") phải nằm ở `[CTA]` | Spec cũ mục 4.1 dòng `live_and_product`, mục 4.4 |
| P10 | Bảng giá trên hình: `product_video` hiện từ giây 0 khi có `price_claim`; `live_and_product` hiện từ đầu `[Price]`. Giá trên bảng là `flash.flash_price` nếu có flash, ngược lại là `regular_price`. `live_only` giữ nguyên | Spec cũ mục 4.4 bước 3 |
| P11 | Phong cách `pricing` được dùng cho `product_video`. Phong cách `closing` ("Tập trung chốt sale") vẫn chỉ dành cho livestream | Spec cũ mục 6 |
| P12 | Video backfill cũ (D13) và mọi video không có `price_claim` không bị bộ lọc giá ảnh hưởng | — |
| P13 | Python có một bộ giải giá **mô phỏng đúng quy tắc PHP**, dùng khi sinh kịch bản và để Studio hiện trạng thái. Hai bên được ghim bằng cùng một bảng ca kiểm thử (mục 7) | — |

## 3. Dữ liệu

### 3.1 `product_snapshot.pricing` (trên job)

```json
{
  "regular_price": 299000,
  "market_price": 350000,
  "flash": {
    "campaign_id": 12,
    "campaign_name": "Sale 16/10",
    "flash_price": 249000,
    "discount_percent": 17,
    "end_ts": 1792256340
  },
  "captured_ts": 1792160000
}
```

- `regular_price`: `gia_ban`. Nếu `gia_ban <= 0` và `gia_thi_truong > 0` thì lấy `gia_thi_truong`, giống PHP. Nếu vẫn `<= 0` thì `pricing = null`, tức video không được nói giá.
- `market_price`: `gia_thi_truong` khi lớn hơn `regular_price`, ngược lại là `null`.
- `flash`: campaign trong `flash_sale_campaigns` thỏa mọi điều kiện sau, ngược lại là `null`:
  - `is_active` hoặc `enabled` là truthy;
  - `start_at <= now < end_at`, trong đó `start_at` và `end_at` là datetime UTC;
  - `end_at - now >= 7200` giây;
  - có item với `str(ma_san_pham)` (hoặc `product_id`) trùng mã sản phẩm, và `0 < flash_price < regular_price`. `flash_price` đọc từ `flash_price` hoặc `gia_flash`.
  - Nếu có nhiều campaign thỏa, chọn campaign có `flash_price` thấp nhất. PHP đã chặn campaign trùng thời gian cho cùng sản phẩm nên trường hợp này hiếm.
- Snapshot cũ không có khóa `pricing` được coi như `pricing = null`.

### 3.2 `ai_idol_videos.price_claim`

```json
{
  "product_id": "1024",
  "regular_price": 299000,
  "market_price": 350000,
  "flash": {"campaign_id": 12, "flash_price": 249000, "end_ts": 1792256340},
  "valid_until_ts": 1792256340
}
```

- Được tạo khi job xong (`_complete_video_job`) cho `product_video` và `live_and_product`, từ `product_snapshot.pricing`. Với `live_only` hoặc khi `pricing = null` thì giá trị là `null`.
- `valid_until_ts` bằng `flash.end_ts` hoặc `null`.

### 3.3 Điều kiện giá còn đúng

Hai bên Python (`price_claim_state`) và PHP (`ProductVideo::priceClaimHolds`) dùng cùng quy tắc. `pricing` hiện tại là kết quả bộ giải giá tại thời điểm `now`:

| Điều kiện | Kết quả |
|---|---|
| `claim` là null | còn đúng |
| `now >= claim.valid_until_ts` | `flash_ended` |
| `current.regular_price != claim.regular_price` | `regular_changed` |
| `claim.market_price` khác null và `current.market_price != claim.market_price` | `market_changed` |
| `claim.flash` khác null và hiện không có flash, **hoặc** khác `campaign_id`, **hoặc** khác `flash_price` | `flash_changed` |
| `claim.flash` là null nhưng hiện đang có flash | còn đúng (video nói giá niêm yết, giá đó vẫn đúng) |

Các điều kiện được xét theo đúng thứ tự trong bảng. Kết quả là điều kiện đầu tiên khớp.

Lưu ý: khi so sánh với PHP, `current.market_price` là `gia_thi_truong` thô do `resolveEffectivePrice` trả về. Điều kiện `market_changed` chỉ xét khi claim có `market_price`, và lúc đó `gia_thi_truong` thô bằng `claim.market_price`. Vì vậy hai bên so sánh cùng một đại lượng.

## 4. Lời thoại

### 4.1 Cụm chữ đọc sẵn (`core/vi_money.py`)

- `number_words(n)`: đọc số tiếng Việt cho `0 <= n < 10^12`, theo các quy tắc "linh", "mốt", "lăm", "mười", "không trăm". Ví dụ: `299000` → "hai trăm chín mươi chín nghìn"; `105000` → "một trăm linh năm nghìn"; `215000` → "hai trăm mười lăm nghìn"; `21000` → "hai mươi mốt nghìn"; `1250000` → "một triệu hai trăm năm mươi nghìn"; `1005000` → "một triệu không trăm linh năm nghìn"; `249500` → "hai trăm bốn mươi chín nghìn năm trăm".
- `money_words(n)` = `number_words(n) + " đồng"`.
- `percent_words(p)` = "giảm " + `number_words(p)` + " phần trăm".
- `deadline_words(end_ts)`, đổi sang giờ Asia/Ho_Chi_Minh:
  - nếu là 23:59 → "đến hết ngày {D} tháng {M}";
  - ngược lại → "đến {h} giờ[ {m} phút] ngày {D} tháng {M}" (bỏ phần phút khi phút là 0);
  - ngày 1–10 đọc là "mùng …"; tháng 4 đọc là "tư"; các số khác đọc bằng `number_words`.
  - Ví dụ: 23:59 ngày 17/10 → "đến hết ngày mười bảy tháng mười"; 20:00 ngày 5/4 → "đến hai mươi giờ ngày mùng năm tháng tư".
- `price_phrases(pricing)` → dict có các khóa `regular`, `market`, `flash`, `discount`, `deadline`. Khóa nào không có dữ liệu thì không có trong dict.

### 4.2 Khối DATA trong prompt

Áp dụng cho `product_video` và `live_and_product`, cả Script Council lẫn nhánh LLM trực tiếp. Các dòng giá thay cho "Giá: … đồng / Giá thị trường: … đồng":

```
Giá bán tại SkinSyntax: hai trăm chín mươi chín nghìn đồng
Giá tham khảo thị trường: ba trăm năm mươi nghìn đồng
Flash sale: hai trăm bốn mươi chín nghìn đồng, giảm mười bảy phần trăm, đến hết ngày mười bảy tháng mười
```

Không có pricing thì ghi một dòng: `Giá: không có dữ liệu, không nhắc giá.`

Luật trong prompt (cả writer, editor và revision):

- Chỉ được nói giá bằng **đúng nguyên văn** các cụm trên.
- Không có dòng Flash sale thì không được dùng "ưu đãi", "khuyến mãi", "sale", "giảm", "có hạn".
- Không bao giờ nói "hôm nay", "tối nay", "ngày mai", "livestream", "chốt đơn", "chỉ còn", "duy nhất", "voucher", "mã giảm".
- Gọi giá thị trường là "giá tham khảo thị trường", không gọi là "giá gốc".

Với `product_video`: bảy phần giữ nguyên. Giá được nói ở `[Product Intro]` hoặc `[Wrap-up]`. `[Wrap-up]` mời "bấm vào sản phẩm để xem giá mới nhất và đặt hàng".

Với `live_and_product`: phần `[Price]` dùng các cụm trên. Ưu đãi riêng của live chỉ được nói ở `[CTA]`.

### 4.3 Bộ kiểm tra cứng (`check_purpose_rules`)

Phạm vi kiểm tra:
- `product_video`: toàn bộ kịch bản.
- `live_and_product`: các phần trước phần đầu tiên có nhãn `cta` hoặc `closing`. Không có nhãn đó thì không kiểm tra; job vẫn chạy và clip bị chặn ở bước sau với lý do `missing_price_marker`, như hiện tại.

Các bước, chạy trên văn bản đã gấp dấu (`_fold`, quy "ngàn" về "nghìn"):

1. Xóa mọi cụm hợp lệ trong `price_phrases(pricing)`. Mỗi cụm tiền chấp nhận cả dạng có và không có chữ " đồng".
2. Tìm **con số tiền hoặc phần trăm** trong phần còn lại, gồm các mẫu tiền hiện có trong `_PRICE_PATTERNS` và thêm mẫu `<số hoặc chữ số> phần trăm`. Có kết quả → lỗi: `Con số giá không khớp dữ liệu, chỉ dùng đúng các cụm: <danh sách cụm>`.
3. **Từ luôn bị cấm** trên trang sản phẩm: `hom nay|toi nay|ngay mai|live ?stream|chot don|chi con|duy nhat|voucher|freeship|ma giam`. Có kết quả → lỗi.
4. **Từ chỉ hợp lệ khi có flash**: `flash sale|sale|khuyen mai|uu dai|giam gia|co han|ket thuc|sap het`. Nếu `pricing.flash` là null mà gặp các từ này → lỗi: `Không có chương trình ưu đãi đang chạy, không được nói ưu đãi`.
5. Quy tắc thiếu `[Ingredients]` giữ nguyên.

Đường duyệt kịch bản thủ công (`api_approve_job_script`) vốn đã gọi `check_purpose_rules` với `job.product_snapshot`, nên tự được áp quy tắc mới.

Bộ kiểm duyệt LLM (`validate_script_via_llm`) nhận thêm các dòng giá thị trường và flash sale, để không đánh FAIL một giá flash đúng.

## 5. Video và trang sản phẩm

### 5.1 Render

- `body_clip`: thêm hằng `PAGE_CUT_LABELS = {"cta", "closing"}` để cắt clip `live_and_product`. `SALE_LABELS` vẫn dùng cho `price_from`.
- `price_from_for(purpose, timings, intro, has_claim)`:
  - `product_video` → `0.0` nếu `has_claim`, ngược lại `None`;
  - `live_and_product` → đầu nhãn `price`; nếu không có nhãn đó thì dùng mốc `body_cut_seconds` như cũ;
  - `live_only` → `0.0`.
- Giá trên bảng (`manifest.product.price`) theo P10. Bỏ việc xóa giá khỏi `mix_snapshot` cho `product_video` khi có pricing.

### 5.2 Bộ lọc storefront (PHP)

- `ProductVideo::priceClaimHolds(?array $claim, array $pricing, int $now): bool` là hàm thuần, theo bảng ở mục 3.3.
- `loadVideoEligibility` lấy thêm `price_claim`. Với mỗi `product_id` trong các claim, gọi `resolveEffectivePrice($pid, $now)` một lần. Video có claim không còn đúng thì bị loại.
- Mỗi item trả về có thêm `price_valid_until_ts` (int hoặc null).
- `SanPhamController` (đoạn đọc `product:videos:*`): sau khi lấy từ cache, bỏ các item có `price_valid_until_ts !== null && price_valid_until_ts <= time()`.

### 5.3 Studio

- `product_page_state(video)` (Python) có thêm `price_state`: `null` (ổn hoặc không có claim) hoặc một mã lý do ở mục 3.3. Giá trị được tính bằng bộ giải giá Python lúc đọc.
- `studio-activity.js` `segmentPageState`: nếu `product_page.price_state` khác null thì hiện chữ `Giá trong video không còn đúng, video đã tự ẩn khỏi trang sản phẩm. Hãy tạo video mới.` (`live: false`).
- Bước 2 của Studio đổi mô tả:
  - Video sản phẩm: `Không phát sóng. Nói giá bán và ưu đãi đang chạy, lấy từ hệ thống. Video tự ẩn khỏi trang sản phẩm khi giá đổi hoặc ưu đãi hết hạn.`
  - Livestream + video sản phẩm: `Livestream được nói giá, khuyến mãi và kêu gọi chốt đơn. Phần giới thiệu kèm giá tự lên trang sản phẩm sau khi phát xong và tự ẩn khi giá không còn đúng.`
- Phong cách: `core/narration.py` và `studio-logic.js` thêm cờ `live_only` / `liveOnly`, và chỉ `closing` có cờ này. `normalize_styles` và `allowedStyles` lọc theo cờ mới. Câu khóa trong Studio: `“Tập trung chốt sale” tạm tắt vì video sản phẩm nằm lâu dài trên trang sản phẩm.` Quyết định autopilot `STYLES_PRICE_DROPPED`: `Bỏ phong cách tập trung chốt sale vì video sản phẩm không kêu gọi chốt đơn.`

## 6. Rủi ro

| Rủi ro | Xử lý |
|---|---|
| LLM đọc số khác cụm mẫu ("hai trăm chín chín nghìn") | Bộ kiểm tra FAIL, vòng sửa (tối đa 3 lần) nhận lại danh sách cụm đúng |
| Bộ giải giá Python lệch PHP | Bảng ca chung ở mục 7 được kiểm thử ở cả hai bên |
| Giá đổi sau khi render nhưng trước khi đăng | Storefront lọc lúc đọc nên không cần kiểm tra lúc đăng |
| Cache 30 phút giữ video sau khi giá đổi | Hết hạn flash được lọc đúng giờ. Đổi giá thì chậm tối đa 30 phút, bằng độ trễ của trang sản phẩm hiện nay |
| Video ẩn hàng loạt khi admin chỉnh giá | Đúng mong muốn; Studio báo lý do |

## 7. Bảng ca chung cho bộ giải giá và điều kiện giá còn đúng

Sản phẩm `1024`, `gia_ban = 299000`, `gia_thi_truong = 350000`, `now = 2026-10-16T03:00:00Z`.

| Ca | Campaign | `pricing.flash` |
|---|---|---|
| A | không có | null |
| B | enabled, 2026-10-16T00:00Z → 2026-10-17T16:59Z, item 1024 flash 249000 | `{campaign_id, flash_price: 249000, discount_percent: 17, end_ts}` |
| C | như B nhưng `is_active=false, enabled=false` | null |
| D | như B nhưng kết thúc 2026-10-16T04:30Z (còn 1,5 giờ) | null |
| E | như B nhưng flash 299000 | null |
| F | như B nhưng item là sản phẩm 2048 | null |

Claim được lấy từ ca B. Đổi dữ liệu như sau thì kết quả `price_claim_state` là:

| Thay đổi | Kết quả |
|---|---|
| không đổi gì | ổn |
| `gia_ban = 279000` | `regular_changed` |
| `gia_thi_truong = 360000` | `market_changed` |
| `now = 2026-10-17T17:00Z` | `flash_ended` |
| campaign bị tắt | `flash_changed` |
| `flash_price = 239000` | `flash_changed` |
| claim từ ca A, sau đó campaign B bắt đầu | ổn |

## 8. Kiểm thử

- Python: `vi_money`, bộ giải giá cùng các ca A–F, `price_claim_state`, `check_purpose_rules` (cụm đúng PASS, sai số FAIL, có ưu đãi mà không có flash FAIL, "hôm nay" FAIL), các prompt, `body_clip` (mốc cắt CTA), `price_from_for`, `_complete_video_job` ghi claim, `product_page_state.price_state`, narration styles.
- Agent service: prompt Script Council có dòng giá, payload có `price_lines`.
- PHP: `priceClaimHolds` với bảng ở mục 7; lọc cache theo `price_valid_until_ts`.
- JS: `studio-logic` (`allowedStyles`), `studio-activity` (`segmentPageState` có `price_state`).
- E2E: tạo một job `product_video` từ fixture sản phẩm có flash sale, xem video trên trang sản phẩm; tắt campaign, xóa cache, rồi xác nhận video biến mất. Nếu harness chưa chạy được thì ghi `E2E NOT RUN` kèm lý do.
