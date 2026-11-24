# Đo lường chi phí và lợi ích của tính năng chụp bảng thành phần

Trạng thái: **ĐÃ CODE, chưa commit; đã chạy demo thật (quét, thêm giỏ, báo cáo), chưa có số liệu khách thật** (2026-11-24, nhánh `syna_10_11`).
Tính năng gốc: [`update_chup_thanh_phan.md`](update_chup_thanh_phan.md). Tài liệu không chứa bí mật, chỉ nêu tên biến môi trường.

## 1. Vì sao cần

Câu hỏi đặt ra là tính năng giúp doanh nghiệp bán được hàng hay chỉ tốn thêm tiền API. Trước thay đổi này, code không ghi lại lượt quét nào dẫn tới thêm giỏ hay đặt hàng, nên chưa trả lời được bằng số liệu. Thay đổi này ghi lại hai đầu của phép so sánh:

- **Chi phí:** số token mà model đọc ảnh tiêu tốn trong mỗi lượt quét.
- **Lợi ích:** đi theo phễu: quét → bắt được xung đột → hiện món thay thế → khách thêm vào giỏ → khách đặt đơn (tính ra doanh thu).

## 2. Luồng dữ liệu

```
Flask vision.py      ghi usage của mỗi lần gọi provider {provider, model, ok, prompt_tokens, completion_tokens}
  → service.py       thêm "usage" vào mọi response (lượt khách sửa chữ thì [], vì không gọi model)
PHP SearchRecommendClient::validateLabelResponse  làm sạch usage (≤ 4 mục, kẹp số)
  → LabelScanPresenter  bỏ usage, trình duyệt không thấy
  → LabelScanController::track()  ghi 1 doc {type:"scan"} vào Mongo `label_scan_events`
                                   + nhớ món đã hiện (thay thế / món shop khớp) trong $_SESSION 24 giờ
SanPhamController::addToCartResult → LabelScanMetrics::attributeAddToCart()
                                   nếu món vừa được thẻ quét hiện ra → ghi {type:"add_to_cart", kind}
                                   (chống trùng theo phiên + sản phẩm + ngày)
Đơn hàng: không sửa code đặt hàng. Báo cáo ghép `hoa_don.analytics_sid` (hash phiên sẵn có) với hash phiên của sự kiện.
```

**Riêng tư:** không lưu ảnh, chữ thành phần, tên hay hãng đọc từ ảnh, email, IP hay user agent. Chỉ lưu hash phiên và người dùng, mã sản phẩm của shop, số đếm, enum và token. Không ghi tài khoản admin, nhân viên hay bot (dùng `ProductEventTracker::isExcluded`). Không ghi lượt bị chặn trước khi gọi Flask (CSRF, rate limit, file sai), vì các lượt đó không tốn tiền.

## 3. Xem báo cáo (chỉ đọc Mongo)

```bash
cd ai-service-flask && ../.venv/Scripts/python.exe scripts/label_scan_metrics.py --days 30 --margin 0.3
```

Báo cáo in ra và ghi CSV vào `report/label-scan-metrics/`:

| Nhóm | Chỉ số |
|---|---|
| Sử dụng | số lượt quét theo kết quả (ok, not_label, unreadable, vision_unavailable, unavailable), ảnh hay chữ, số phiên |
| Giá trị tư vấn | số lượt có xung đột và có xung đột mức cao (đơn có khả năng bị trả đã được chặn trước), phân bố hợp da |
| Bán hàng | số lượt hiện món thay thế, số lần thêm giỏ (thay thế / món shop), tỷ lệ thêm giỏ |
| Đơn hàng | **attributed**: doanh thu các dòng hàng đã thêm giỏ từ thẻ quét · **assisted**: tổng `tam_tinh` của đơn từ phiên có quét (trần trên, không phải quan hệ nhân quả). Bỏ đơn đã hủy, đơn đặt trước lượt quét đầu tiên và tài khoản test |
| Chi phí | token theo provider, chi phí USD và VND, chi phí mỗi lượt quét |
| Kết luận | lãi gộp = doanh thu attributed × margin; ROI = lãi gộp / chi phí; số lượt quét cần cho 1 đơn để hòa vốn |

**Giá token là giả định** (groq 0,29/0,59, gemini 0,30/2,50 USD mỗi 1 triệu token vào/ra), chưa đối chiếu với bảng giá hiện tại; báo cáo có ghi rõ là giả định. Có thể ghi đè bằng `LABEL_PRICE_GROQ_IN_USD_PER_M`, `LABEL_PRICE_GROQ_OUT_USD_PER_M`, `LABEL_PRICE_GEMINI_IN_USD_PER_M`, `LABEL_PRICE_GEMINI_OUT_USD_PER_M`. Tỷ giá đọc từ `LABEL_USD_VND` (mặc định 26000). Tắt toàn bộ việc ghi bằng `LABEL_SCAN_METRICS_ENABLED=0`.

## 4. File

| File | Thay đổi |
|---|---|
| `ai-service-flask/label_service/vision.py` | `LabelText.usage`, `VisionUnavailable.attempts`, log thêm số token |
| `ai-service-flask/label_service/service.py` | thêm `usage` vào mọi response |
| `ai-service-flask/label_service/contract/label_analyze_response.schema.json` | thêm `usage` (không bắt buộc) |
| `ai-service-flask/label_service/metrics.py` (mới) | hàm thuần tính chỉ số + `collect()` chỉ đọc |
| `ai-service-flask/scripts/label_scan_metrics.py` (mới) | CLI báo cáo |
| `backend/app/services/LabelScanMetrics.php` (mới) | dựng doc, nhớ món đã hiện, ghi Mongo (không bao giờ ném lỗi) |
| `backend/app/services/SearchRecommendClient.php` | làm sạch `usage` |
| `backend/app/services/LabelScanPresenter.php` | không đưa `usage` ra trình duyệt |
| `backend/app/controllers/LabelScanController.php` | `track()` sau `remember()`; hook `metrics` để test |
| `backend/app/controllers/SanPhamController.php` | gọi `attributeAddToCart` sau khi thêm giỏ thành công |
| `.env.example` | thêm tên các biến ở §3 |
| Test | `tests/label_service/test_label_metrics.py` (mới), thêm test usage ở `test_vision.py` và `test_label_service.py`, `backend/tests/unit/LabelScanMetricsTest.php` (mới) |

## 5. Kiểm tra đã chạy (2026-11-24)

| Lệnh | Kết quả |
|---|---|
| GitNexus `impact` upstream: `addToCartResult`, `validateLabelResponse`, `LabelScanController`, `LabelText`, `LabelAnalyzeService` | LOW. `read_label` báo UNKNOWN; tìm thủ công thì chỉ `service.py` và test gọi nó |
| `pytest tests/label_service tests/search_service tests/graph_sync tests/home_service -m "not integration"` (đã bỏ các biến DB/khóa) | 716 passed, 19 deselected |
| `php tests/unit/LabelScanMetricsTest.php` / `LabelScanTest.php` / `CartInsightsTest.php` | 32 / 69 / 37 passed, 0 failed |
| `php -l` 5 file PHP đã sửa hoặc thêm | sạch |
| Demo thật trên trình duyệt + báo cáo với Atlas | đã chạy, xem §6 (không đặt đơn) |

## 6. Chạy demo thật (2026-11-24)

Khởi động lại container `skinsyntax-ai` để nạp code Flask mới và reload PHP-FPM (`kill -USR2 1`). Kịch bản Playwright là khách vãng lai, đặt UA Chrome thường vì UA "HeadlessChrome" bị bộ lọc bot loại: thêm Obagi Retinol (mã 98) vào giỏ → chụp ảnh 1 và ảnh 5 (Groq thật) → dán toner AHA/BHA → thêm món thay thế đầu tiên vào giỏ. **Không tạo đơn hàng.** Ảnh chụp màn hình: `report/label-scan-metrics/demo-0*.png`. CSV: `report/label-scan-metrics/summary_20261124_1d.csv`.

| Kết quả | Số |
|---|---|
| Lượt quét | 3 (2 ảnh: 5,6 s và 6,2 s; 1 chữ: 0,6 s), đều `ok` |
| Xung đột | 1 lượt có xung đột mức cao (AHA/BHA × Retinol trong giỏ) |
| Món thay thế | 3 món (299, 743, 68); thêm món 299 vào giỏ → 1 sự kiện `add_to_cart` kind `alternative` |
| Token mỗi ảnh (Groq qwen3.8-27b) | khoảng 1.949 vào + 200–241 ra ≈ **2.190 token** (thấp hơn mức 6.800 ghi ở tài liệu gốc) |
| Chi phí (giá giả định) | 0,0014 USD = **36 đ cho 3 lượt**, ≈ **18 đ mỗi ảnh**; lượt dán chữ không tốn token |
| Trình duyệt có nhận `usage` không | không (đúng thiết kế) |
| Mongo | doc chỉ có enum, số đếm, mã sản phẩm, token và hash; không có ảnh, chữ hay email |

**Phép tính hòa vốn (tính tay, KHÔNG phải số liệu thật vì demo không đặt đơn):** món 299 giá 1.355.000 đ, biên lãi giả định 30% → lãi gộp 406.500 đ cho mỗi đơn. Chia cho 18 đ mỗi ảnh → khoảng **22.000 lượt chụp ảnh mới cần có 1 đơn để hòa vốn**. 1.000 lượt chụp mỗi tháng tốn khoảng 18.000 đ.

Nhận xét phụ khi xem demo: món thay thế 299 là kem Obagi Retinol 0,5% trong khi giỏ đã có Obagi Retinol. Hai món này không có luật xung đột, nhưng bị trùng hoạt chất. Nên cân nhắc lọc món trùng retinoid với giỏ (chưa sửa).

## 7. Giới hạn và việc tiếp theo

- **Chưa có số liệu khách thật.** Demo ở §6 chỉ chứng minh đường ghi dữ liệu và báo cáo chạy đúng. Muốn có ROI thật, phải để khách dùng một thời gian rồi chạy báo cáo. Khi trình bày, tách 3 lượt demo ngày 2026-11-24 khỏi số liệu thật. Script E2E cũ chạy headless nên không tạo sự kiện.
- Ghép đơn hàng dựa vào cookie `ssv_sid`. Phiên không có cookie này vẫn được đếm lượt quét và thêm giỏ, nhưng không ghép được với đơn.
- Gán thêm giỏ theo kiểu "món đã được thẻ quét hiện ra trong 24 giờ". Nếu khách tự tìm và thêm đúng món đó từ chỗ khác, lượt đó vẫn được tính cho tính năng quét.
- Collection `label_scan_events` chưa có index. Khi lượng dữ liệu lớn, nên thêm index trên `event_id` (unique, sparse) và `ts`.
- Chưa đo thời gian tư vấn viên tiết kiệm được và số đơn bị trả tránh được. Hai chỉ số này chỉ có số đại diện (lượt có xung đột mức cao).
