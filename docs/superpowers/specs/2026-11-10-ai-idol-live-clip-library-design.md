# AI Idol: livestream dài theo routine bằng kho clip + lịch phát xoay vòng (Giai đoạn 1)

Ngày: 2026-11-10 (bản 2: thêm luật nhân vật Syna và "nghỉ giữa hiệp" theo mẫu kịch bản người dùng gửi) · Trạng thái: chờ duyệt
Phạm vi: `ai-idol-service`, `scripts/ai-idol-mascot/syna-render-server.cjs`, trang `?r=live`, Studio admin

## 1. Vấn đề và mục tiêu

- Một chiến dịch live hiện tạo **mỗi sản phẩm một kịch bản 8 phần hoàn chỉnh** (Opening → Closing), rồi `engines/program_composer.py` nối các clip lại. Người xem nghe Syna tạm biệt rồi chào lại ở mỗi sản phẩm.
- Buổi phát 30–60 phút chỉ **lặp video ghép vài phút** (`broadcast_engine.py` dùng `-stream_loop -1`). Buổi thử ngày 2026-11-10 có video 4 phút 8 giây, lặp khoảng 7 lần.
- Mục tiêu: một phiên live 30–60 phút giống người bán hàng thật.
  - Syna chào một lần, giới thiệu lần lượt từng sản phẩm theo routine.
  - Giữa hai sản phẩm có 10–20 giây "nghỉ giữa hiệp" vui vẻ: nhắc mua, giới thiệu SkinSyntax, câu đùa, mẹo nhỏ. Sau đó dẫn sang món tiếp theo.
  - Xen hỏi đáp và kiến thức da. Vòng lặp sau nghe khác vòng trước. Chỉ kết buổi một lần ở cuối.
  - Giọng dí dỏm, đời thường, bắt trúng nỗi đau người xem, nhưng mọi thông tin đều đúng sự thật.

## 2. Quyết định đã chốt

| # | Quyết định |
|---|---|
| D1 | Áp dụng cho 2 chế độ live: `live_and_product` và `live_only`. `product_video` giữ nguyên. |
| D2 | Nói giá ở từng sản phẩm, rồi tóm tắt giá từng món và tổng cả bộ ở cuối mỗi vòng. Giá đọc đúng cụm giá chuẩn và được đệm thêm "… cành" ngay sau (mục 5.5). |
| D3 | Tối đa **6 sản phẩm** cho mỗi chiến dịch live. Vượt quá thì `create_campaign` từ chối. |
| D4 | Cách làm: **kho clip + bộ xếp lịch phát**, mỗi clip là một segment/job như hiện nay. |
| D5 | Kho cỡ "vừa": 2 góc mỗi SP, 6 đoạn nghỉ giữa hiệp, 4 hỏi đáp, 2 kiến thức da, 1 mở màn, 2 chuyển vòng, routine + tóm tắt giá, kết buổi. Khoảng 22 phút nội dung với 3 SP, 34 phút với 6 SP (mục 7.1). |
| D6 | Thứ tự sản phẩm trong mỗi vòng **cố định theo routine**. Giữa các vòng chỉ đổi góc nhìn và các clip đệm. |
| D7 | Trung thực: không bịa số người xem hoặc đơn hàng, không khan hiếm giả, không suất, quà, voucher hay mã "mở khóa" không có trong dữ liệu, không cam kết hiệu quả theo thời gian. |
| D8 | Clip SP góc A của chế độ `live_and_product` là bản đăng lên trang sản phẩm, cắt trước `[Price]`. |
| D9 | **Nhân vật Syna** theo mục 5.1, dùng chung cho mọi loại clip live. |
| D10 | **Giữa hai sản phẩm liên tiếp luôn có một đoạn nghỉ giữa hiệp 10–20 giây.** Không bao giờ có lời tạm biệt hay chào lại giữa buổi. |
| D11 | Mỗi lần giới thiệu sản phẩm (một góc) dài **khoảng 2 phút** (100–140 giây, dưới giới hạn 180 giây/clip của máy dựng). |

## 3. Luồng tổng thể

```
create_campaign (live, ≤6 SP)
  └─ prepare_campaign
       1. show_plan  : xếp SP theo routine + lấy cảnh báo xung đột
       2. writer A   : clip SP (góc A, B) + clip khung (mở màn, chuyển vòng, nghỉ giữa hiệp, hỏi đáp, kiến thức, kết buổi)
       3. mỗi clip → segment(kind, angle) + job(kịch bản viết sẵn) → kiểm duyệt → giọng → avatar
       4. clip SP xong → writer B: routine + tóm tắt giá (chỉ SP còn lại) → job như trên
  └─ đủ điều kiện (7.3) → build_program (hàm thuần) → compose (nối theo dòng thời gian) → SCHEDULED
  └─ broadcast · trình phát trang khách ?r=live (đánh số SP theo show_plan, mục 6.4)
```

Chiến dịch cũ (segment không có `kind`) vẫn chạy đúng luồng cũ.

## 4. Dữ liệu

### 4.1 `ai_idol_campaigns` (thêm trường)

- `show_format`: `"clip_library_v1"` cho chiến dịch live mới; thiếu trường này thì là luồng cũ.
- `show_plan`:
  ```
  {
    products: [{product_id, number, step_key, step_label}],  // đã xếp theo routine; number = 1..N
    conflicts: [{a, b, severity, message_vi}],                // có thể rỗng
    theme_vi: "Routine cho da dầu mùa nắng",                   // do writer A đặt
    routine_source: "graph" | "keyword" | "fallback"
  }
  ```
- `program`: `[{segment_id, kind, product_id|null, angle|null, start, end}]` (giây trên video ghép).
- `duration_seconds`: độ dài thật của video ghép. Buổi phát dùng con số này (mục 6.3).

### 4.2 `ai_idol_segments` (thêm trường)

- `kind`: `opening | product | interlude | faq | tip | routine_recap | transition | closing`.
- `angle`: `"A" | "B"` (chỉ với `product`).
- `interlude_type`: `cta | about_shop | fun | mini_tip` (chỉ với `interlude`).
- `product_id`: bắt buộc với `product` và `faq`; `null` với loại khác.
- `variant`: số thứ tự trong cùng loại.
- `script_text`: kịch bản writer đã viết, dùng lại khi retry.
- `phase`: `"A"` hoặc `"B"` (riêng `routine_recap`).

### 4.3 `ai_idol_jobs`

- Thêm `clip_kind` và `prewritten_script` (bool).
- Job có `prewritten_script` thì **bỏ qua GENERATING_CONTENT**, đi thẳng vào kiểm duyệt → sửa → duyệt (AUTO/MANUAL như cũ) → giọng → dựng.
- Job không gắn SP: `product_id = null`, `product_snapshot` = ảnh chụp **gộp** của cả buổi (mục 5.6).
- Bố cục khung hình Syna 3D cho job không gắn SP: renderer cần `manifest.product` (`syna-render-server.cjs`). Dùng thẻ "SkinSyntax Live" với tên là `show_plan.theme_vi`, không giá, không thành phần, ảnh là logo cửa hàng. Thêm biến thể này cho bộ dựng manifest trong `engines/syna_engine.py`.

## 5. Viết kịch bản

### 5.1 Luật nhân vật Syna (`engines/syna_persona.py`, mới)

Một hằng `PERSONA_RULES_VI` được chèn vào system prompt của mọi lần viết clip live, gồm:

- **Định vị:**
  - Syna là cô AI bán mỹ phẩm của SkinSyntax: duyên dáng, hài hước, xéo xắt vừa đủ, bắt trúng nỗi đau của người xem.
  - Syna **không có da để thử sản phẩm** và biến điều này thành miếng hài: không có da để nổi mụn, nhưng thuộc lòng bảng thành phần và cách dùng.
  - **Không** tự nhận đã thử, đã dùng, hay "đã quét hàng triệu phản hồi". Đây cũng là luật `PERSONAL_EXPERIENCE` hiện có.
- **Xưng hô và từ ngữ:**
  - Xưng "Syna". Gọi người xem là "cả nhà", "mấy bà", "quý dị", "các tình yêu".
  - Từ ngữ đời thường, ví von gần gũi.
  - Cấm từ máy móc: "hệ thống", "thuật toán", "dữ liệu", "quét", "AI phân tích".
- **Nhịp clip sản phẩm** (mục 5.3): bắt bệnh → ví von vì sao → giải pháp bằng thành phần thật → cảm giác khi dùng → cách dùng → giá → giục chốt đơn.
- **Trung thực** (D7):
  - Giá và ưu đãi chỉ lấy từ các dòng giá trong dữ liệu (`price_lines`). Không có dòng flash sale thì không nói giảm, sale, quà, suất, voucher hay "hết live là hết giá".
  - Cảm giác dùng (chất, độ thấm, mùi) chỉ được tả khi dữ liệu mô tả hoặc cách dùng có nhắc. Nếu không, nói về cách dùng thay vì tự tả.
  - Không hứa hẹn kết quả sau N ngày, không "chuẩn y khoa", "triệt để", "trị dứt".
- **Tương tác:**
  - Được mời "thả tim", "comment" ở mở màn, `[CTA]` và các đoạn nghỉ giữa hiệp.
  - Không đặt điều kiện giả ("đủ 5.000 tim mới mở bán").
  - Không trả lời bình luận cụ thể, vì giai đoạn 1 chưa đọc bình luận.

Cùng file có `SHOP_FACTS_VI`, danh sách điều có thật về SkinSyntax mà clip `about_shop` được phép nói: "sản phẩm chính hãng", "giao hàng toàn quốc", "hỗ trợ tận tâm", "cá nhân hóa routine theo làn da", "chat với Syna trên web để được tư vấn". Không được thêm cam kết khác (freeship, đổi trả…) trừ khi được thêm vào danh sách này.

### 5.2 Xếp thứ tự routine (`core/show_plan.py`, mới)

1. **Nguồn chính:** gọi `POST {AI_IDOL_SEARCH_URL}/api/cart/analyze` cho **từng sản phẩm** với `{"product_ids": [id]}`, kèm header `X-Internal-Token` nếu có cấu hình. `routine.has` là bước của SP đó (khóa trong `ai-service-flask/data/graph/routine_steps.yaml`). Gọi thêm một lần cho cả bộ để lấy `conflicts`.
2. **Dự phòng theo từ khóa** (API lỗi, quá 5 giây, hoặc trả `degraded`): khớp danh mục/tên SP với `tẩy trang`, `sữa rửa mặt|gel rửa mặt`, `tẩy tế bào chết|tẩy da chết`, `toner|nước cân bằng`, `serum|tinh chất`, `kem dưỡng|lotion`, `chống nắng`.
3. **Thứ tự:** `tay_trang → sua_rua_mat → tay_te_bao_chet → toner → serum → kem_duong → kem_chong_nang`. Bước `tay_te_bao_chet` chỉ có ở đây. SP không xác định được bước thì đứng trước `kem_duong`. Cùng bước thì giữ thứ tự admin nhập. Gán `number` = 1..N theo thứ tự cuối.
4. Hàm thuần `order_products(products, step_lookup) -> list`, tách khỏi phần gọi HTTP.

### 5.3 Writer A (`engines/show_writer.py`, mới)

**Clip `product`:** mỗi SP một lần gọi LLM, trả về 2 góc. Mỗi góc 100–140 giây (khoảng 400–560 chữ, theo tốc độ đọc đo được khoảng 4 chữ/giây). Các phần:

| Phần | Nội dung | Ghi chú |
|---|---|---|
| `[Lead-in]` | Dẫn vào bước routine bằng **tên bước**: "Sạch rồi thì tới bước cấp cứu nền da nè…" | Không nhắc tên SP khác, không nói số thứ tự |
| `[Pain Point]` | Bắt bệnh: thói quen sai hoặc nỗi khổ cụ thể, câu hỏi tu từ "Có bà nào… không?" | |
| `[Why]` | Ví von đời thường giải thích vì sao | |
| `[Solution]` | Thành phần và công dụng từ dữ liệu thật | |
| `[Feel]` | Cảm giác khi dùng, chỉ theo dữ liệu (5.1) | Có thể ngắn hoặc gộp vào `[How to Use]` |
| `[How to Use]` | Cách dùng từ dữ liệu | |
| `[Price]` | Cụm giá chuẩn + đệm "cành" (5.5), ưu đãi chỉ khi có flash sale | |
| `[CTA]` | Giục chốt: "bấm giỏ hàng, chọn món số 2…", mời comment hoặc thả tim | |

- Góc A xoáy vào nỗi đau chính và thành phần. Góc B xoáy vào hợp với ai, dùng ở bước nào, lưu ý kết hợp (từ `show_plan.conflicts`).
- Hai góc phải khác nhau rõ: khác ví von, khác câu bắt bệnh.
- Các phần trước `[Price]` phải "dùng lâu dài được": không giá, không "live/hôm nay/comment/thả tim", vì đây là bản trang SP (D8). Số thứ tự "món số N" **chỉ** được nói trong `[CTA]`.

**Clip khung** (một lần gọi, biết lineup, chủ đề và giá):

- `opening` ×1 (60–120 giây):
  - Chào, kiểm tra tín hiệu ("nghe rõ giọng Syna chưa"), miếng hài "AI không có da mà đi bán mỹ phẩm".
  - Nêu chủ đề và các bước routine của buổi (tên bước, **không đọc tên SP**), mời ở lại và thả tim.
- `transition` ×2 (20–30 giây): chào người mới vào phòng, nhắc chủ đề, "Syna điểm lại từ bước đầu nhé".
- `interlude` ×6 (10–20 giây, khoảng 40–80 chữ), 2 cho mỗi kiểu `cta`, `about_shop`, `fun`:
  - `cta`: nhắc bấm giỏ hàng, xem lại món vừa nói.
  - `about_shop`: một điều trong `SHOP_FACTS_VI`.
  - `fun`: câu đùa hoặc câu hỏi vui về chăm da (không bịa sự thật).
  - Mỗi đoạn kết bằng một câu nhá chung: "Rồi, món tiếp theo cũng đỉnh không kém nè!". **Không nêu tên hay bước của món sau**, vì một đoạn nghỉ có thể đứng ở nhiều chỗ.
  - Có thể thêm kiểu `mini_tip` (một mẹo da một câu) nếu còn ngân sách độ dài; không bắt buộc.
- `faq` ×4 (20–40 giây):
  - Ưu tiên câu hỏi thật trong `hoi_dap_san_pham` của các SP trong buổi đã có `tra_loi`. Syna đọc câu hỏi theo kiểu "có bạn hỏi…" và diễn đạt lại câu trả lời, không thêm ý.
  - Thiếu thì sinh từ dữ liệu SP và `conflicts`. Mỗi clip gắn `product_id`.
- `tip` ×2 (60–90 giây): kiến thức da theo chủ đề, giọng Syna. **Không bán hàng, không tên SP hay thương hiệu, không giá.**
- `closing` ×1 (20–30 giây): cảm ơn, nhắc kiểm tra giỏ hàng đã bấm đặt chưa, tạm biệt, hẹn phiên sau. Không nêu số giá. Không "hết live là hết giá" trừ khi có flash sale với hạn kết thúc.

**Luật chung:** mỗi clip phải **tự hiểu được khi nghe riêng** (không "như Syna vừa nói"). Chỉ `closing` được tạm biệt. Chỉ `opening`/`transition` được chào.

### 5.4 Writer B: routine + tóm tắt giá

- Chạy khi mọi segment `product` đã ở trạng thái cuối. SP còn lại = SP có ít nhất 1 góc READY. SP mất cả 2 góc bị loại khỏi `show_plan.products`; ghi lý do vào log.
- **Không đánh số lại** khi có SP bị loại: các SP còn lại giữ `number` cũ để khớp với "món số N" đã đọc trong `[CTA]`, nên không clip nào phải dựng lại. Trang khách hiện đúng các số còn lại (ví dụ Số 1, Số 3), và tóm tắt cũng đọc đúng các số đó.
- Sinh 1 clip `routine_recap` (60–90 giây), giọng Syna:
  - Điểm lại từng món theo số, bước và một câu công dụng, kèm giá đúng cụm chuẩn.
  - Đọc tổng cả bộ bằng cụm `tổng cả bộ: <số bằng chữ> đồng`. **Code tính tổng** (giá bán hiện hành từ `core/pricing.py`, đổi chữ bằng `core/vi_money.py`); LLM chỉ đọc nguyên văn.
  - Nhắc thứ tự dùng sáng/tối và cảnh báo xung đột nếu có. Kết bằng giục bấm giỏ hàng.

### 5.5 Giá đọc kiểu "cành"

- Cụm giá chuẩn giữ nguyên ("hai trăm bảy mươi tám nghìn đồng"), vì giọng đọc và bộ kiểm tra dựa vào đó.
- Được đệm **ngay sau** cụm chuẩn: "…, tức là <X> cành thôi quý dị!", trong đó `<X>` là phần trước "nghìn" của chính cụm đó.
- `find_price_issues` chấp nhận `<X> cành` khi và chỉ khi `<X> nghìn đồng` là một cụm giá hợp lệ của dữ liệu. Ngược lại báo `PRICE_MISMATCH`.

### 5.6 Kiểm duyệt

- **Clip `product`:** `validate_and_repair_script` hiện có, với snapshot SP và `purpose` của chiến dịch.
  - Thêm biến thể cấu trúc `clip_product` cho `_revision_structure` (8 phần ở mục 5.3).
  - Bản trang SP (D8) = từ `[Pain Point]` đến hết `[How to Use]`. Cần thêm điểm bắt đầu cắt (sau `[Lead-in]`) vào `product_page_clip`, và nhãn kết thúc `price` cho `clip_product`.
  - Kiểm tra cứng thêm: các phần trước `[Price]` không có cụm giá, không có `livestream|hôm nay|comment|thả tim|bình luận`.
- **Clip không gắn SP:**
  - Snapshot gộp: tên, thương hiệu, cụm giá của mọi SP trong buổi, cộng cụm "tổng cả bộ" (chỉ có ở `routine_recap`).
  - Kiểm tra cứng: `find_price_issues` trên tập gộp; `find_external_retailer_mentions`; `FORBIDDEN_PATTERNS`.
  - `tip`, `interlude`, `opening`, `transition`, `closing`: không có cụm giá. `tip` thêm luật không có tên SP hoặc thương hiệu trong buổi. `about_shop` chỉ nói điều trong `SHOP_FACTS_VI` (LLM audit nhận danh sách này làm "thông tin gốc").
- **Mẫu cấm mới** (thêm vào `FORBIDDEN_PATTERNS`, áp cho mọi clip live), cho D7:
  - Số người xem/đơn bịa: `\d+\s*(người|bạn|bà)\s*(đang xem|đã mua|vừa mua|đầu tiên)`, `vừa (đặt|mua) (hàng|đơn)`.
  - Suất và quà bịa: `\d+\s*suất`, `tặng kèm`, `quà tặng`, `voucher`, `mở khóa`, `trợ giá`.
  - Hết live hết giá: `(tắt|hết|kết thúc) live.*(giá|voucher)`.
  - Cam kết hiệu quả: `chuẩn y khoa`, `triệt để`, `(\d+|vài|ba|bốn)\s*(hôm|ngày)\s*(là|thôi|đã)?\s*(hết|dịu|lặn|khỏi)`.
  - Mẫu khan hiếm (`chỉ còn`, `sắp hết`) vẫn theo luật flash sale hiện hành. `voucher` và `trợ giá` được phép khi dữ liệu có flash sale.
- **Từ máy móc** (`hệ thống|thuật toán|dữ liệu|quét`) là lỗi `SOFT_QUALITY` → đi vào vòng tự sửa.
- Clip vẫn trượt sau vòng tự sửa thì segment `SKIPPED`.

## 6. Xếp lịch phát, ghép video, trang khách

### 6.1 `engines/show_scheduler.py` (mới, hàm thuần)

`build_program(clips, target_seconds, plan) -> list[ProgramItem]`. `clips` là danh sách clip READY với `segment_id, kind, product_id, angle, interlude_type, variant, duration`.

Mỗi vòng `r = 1, 2, …`:
1. Vòng 1 dùng `opening`. Từ vòng 2 dùng `transition[(r-2) % n]`; không có thì dùng một `interlude` kiểu `about_shop`.
2. Lần lượt các SP theo `plan.products`. Vòng lẻ dùng góc A, vòng chẵn góc B; thiếu góc nào dùng góc còn lại.
3. **Giữa hai SP liên tiếp luôn chèn một `interlude`** (D10), xoay vòng: không lặp một interlude trước khi cả nhóm đã dùng, và hai interlude liền kề trong lịch không cùng `interlude_type` nếu tránh được.
4. Sau mỗi 2 SP (sau interlude), chèn thêm một clip đệm xoay vòng `faq`/`tip`; `faq` của SP vừa giới thiệu được ưu tiên.
5. Kết vòng bằng `routine_recap`.

**Lấp đầy:** thêm vòng (vòng cuối được dừng sớm tại ranh giới clip) đến khi `tổng + routine_recap + closing ≥ target - 60s`, rồi kết bằng `routine_recap` (nếu vòng cuối dừng sớm) và `closing`. Kết quả nằm trong `[target - 60s, target + 150s]`. Cận trên tăng so với bản 1 vì clip SP dài khoảng 2 phút. Không cắt giữa clip.

**Bất biến được test:**
- Clip đầu là `opening`; có đúng một `closing`, và nó nằm cuối.
- Không có hai `product` liền nhau.
- Không có hai clip giống nhau liền nhau.
- Mỗi SP còn lại xuất hiện ít nhất một lần mỗi vòng đầy đủ.
- Tổng độ dài nằm trong khoảng cho phép.

### 6.2 Ghép (`engines/program_composer.py`)

- `clip_library_v1`: lấy clip READY → `build_program` → file concat theo dòng thời gian (một file có thể xuất hiện nhiều lần) → ffmpeg stream-copy, lỗi thì mã hóa lại như hiện nay. Lưu `program`, `duration_seconds`, `master_video_path`.
- Chiến dịch cũ: giữ nguyên `assemble_campaign_program`.
- Kiểm tra dung lượng trống trước khi ghép: ≥ 2 × dung lượng ước tính của video ghép. Không đủ thì NEEDS_ATTENTION.

### 6.3 Phát sóng

- `clip_library_v1`: độ dài buổi phát = `duration_seconds`, thay vì `duration_minutes × 60`. `-stream_loop` chỉ để phòng hờ.

### 6.4 Trang khách `?r=live`

- `LiveController::findAiIdolLive` đọc thêm `show_plan.products`. Lưới sản phẩm dưới video xếp theo `number` và có **nhãn "Số N"** trên mỗi thẻ, để lời "món số 2" khớp với màn hình. Chiến dịch cũ giữ thứ tự `product_ids`, không có nhãn.
- Trình phát (`ai-idol-live.js`) không đổi.

## 7. Lỗi, thời gian chuẩn bị, điều kiện lên sóng

### 7.1 Thời gian chuẩn bị

- `create_campaign` (live, `clip_library_v1`): `preparation_minutes = max(MIN_LEAD_MINUTES, ceil(library_minutes × RENDER_FACTOR + 10))`.
  - `library_minutes = 4 × n_sp + 10`. 4 phút/SP là 2 góc × 2 phút. 10 phút gồm mở màn 1,5; chuyển vòng 0,8; nghỉ giữa hiệp 1,5; hỏi đáp 2; kiến thức 2,5; tóm tắt 1,2; kết 0,4.
  - `RENDER_FACTOR` mặc định 4, cấu hình qua `AI_IDOL_RENDER_FACTOR` (số đo thật ngày 2026-11-10).
  - Ví dụ: 3 SP → 22 phút nội dung → khoảng 98 phút chuẩn bị. 6 SP → 34 phút → khoảng 146 phút.
- Thông báo lịch quá gần nêu số phút cần. Studio và agent hiện con số ước tính khi chọn chế độ live.

### 7.2 Lỗi dựng tạm thời

- Ở RENDERING, các lỗi `HTTP 422: Cannot read audio duration`, `page.goto: Timeout`, lỗi kết nối và HTTP 5xx từ máy dựng thì tự dựng lại tối đa **2 lần** (cách 30 giây, rồi 90 giây). Giọng và kịch bản giữ nguyên. Hết lượt thì FAILED.
- `syna-render-server.cjs`: `probe()` nâng thời gian chờ từ 10 giây lên 30 giây, và báo "ffprobe timed out" khi bị kill vì quá giờ.

### 7.3 Điều kiện lên sóng

Chiến dịch được ghép khi mọi segment đã ở trạng thái cuối **và** có đủ:
- 1 `opening`, 1 `closing`, 1 `routine_recap` READY;
- ≥1 `interlude` READY nếu còn ≥2 SP;
- mỗi SP còn lại có ≥1 góc READY;
- ≥1 SP còn lại.

Thiếu thì NEEDS_ATTENTION, kèm lý do tiếng Việt nêu loại clip còn thiếu. "Thử lại" (`retry_campaign`) chỉ dựng lại segment FAILED. Khi tập SP còn lại thay đổi sau retry thì writer B chạy lại.

## 8. Studio admin

- Trang chi tiết chiến dịch (`frontend/views/admin/ai_idol/index.php`, `studio-logic.js`):
  - Nhóm clip theo `kind`, mỗi clip có trạng thái, góc, kiểu nghỉ giữa hiệp và nút xem kịch bản.
  - Thêm khối "Dòng thời gian buổi phát" từ `program`: thanh ngang chia theo clip, tô màu theo loại, kèm tổng thời lượng.
- Không thêm bước duyệt mới; AUTO/MANUAL áp cho từng clip.
- Agent (`agents/service.py`): khi chọn live, giới hạn 6 SP và báo thời gian chuẩn bị (7.1).

## 9. Kiểm thử

**pytest (`ai-idol-service/tests/`):** không chạm DB thật (để rỗng `MONGO_URI` và khóa LLM như các test hiện có), LLM và HTTP giả lập.
- `order_products`: đủ các bước, có tẩy da chết, SP không rõ bước, hai SP cùng bước, API lỗi thì dùng từ khóa, đánh số 1..N.
- `build_program`: mọi bất biến ở 6.1, các trường hợp 1, 2, 3, 6 SP, thiếu góc B, thiếu transition, chỉ có 1 interlude, target 15/30/60 phút.
- Tổng giá cả bộ: cộng đúng và đổi chữ đúng; flash sale đang chạy dùng giá flash.
- Giá "cành": đúng thì đạt; sai số thì `PRICE_MISMATCH`.
- Mẫu cấm D7: mỗi mẫu một ca trượt và một ca hợp lệ gần giống (ví dụ "voucher" khi có flash sale).
- Từ máy móc thì ra `SOFT_QUALITY`.
- `tip` có tên SP thì trượt; `interlude`/`closing` có cụm giá thì trượt; phần trước `[Price]` có "comment" thì trượt.
- Cắt bản trang SP: từ `[Pain Point]` đến hết `[How to Use]`.
- `program_composer`: file concat đúng thứ tự và số lần lặp; chiến dịch cũ vẫn ghép kiểu cũ.
- Lỗi dựng tạm thời: dựng lại 2 lần rồi FAILED; lỗi khác không dựng lại.
- `create_campaign`: live 7 SP bị từ chối; thời gian chuẩn bị mới; `product_video` không đổi.
- Snapshot prompt: `PERSONA_RULES_VI` có mặt trong system prompt của mọi kind live, và không có trong `product_video`.

**PHP:** `php -l`; trang `?r=live` có nhãn "Số N" theo `show_plan`.

**JS:** `node --test` cho phần dòng thời gian Studio (hàm thuần).

**Chạy thật (E2E):** một chiến dịch live 3 SP (tẩy trang/rửa mặt → serum → chống nắng), 30 phút, phát nội bộ, xem trên `?r=live`. Phải nghe được:
- chào một lần;
- có nghỉ giữa hiệp giữa mọi cặp SP;
- không tạm biệt giữa chừng;
- có chuyển vòng và tóm tắt giá kèm tổng;
- kết buổi một lần.

Ghi lại thời gian chuẩn bị thật để chỉnh `RENDER_FACTOR`.

## 10. Ngoài phạm vi

- Giai đoạn 2: lớp chữ động (thẻ "SP đang giới thiệu" theo `program`, giá, voucher thật).
- Giai đoạn 3: đọc và trả lời bình luận trên kênh nội bộ.
- Tạo khuyến mãi, quà, suất giới hạn thật để Syna được phép nói (cần trường dữ liệu và trang admin riêng).
- Lỗi đã thấy nhưng không sửa ở đây:
  - `LiveController::index` dùng `isset($mongoDb->phien_live_viewers)`, luôn sai với `MongoDB\Database`.
  - Hồ sơ SP 1386 thiếu thành phần.
