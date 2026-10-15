# Kết quả sửa và kiểm thử chatbot — 09/09/2026

Bản sửa đã được nạp vào container `skinsyntax-ai`. Endpoint đang chạy là
`/api/chat/auto`, pipeline `consultation_v2`. Kiểm tra cuối: health trả `ok: true`.

## Những gì đã sửa

- **Sản phẩm bị loại vì chỉ mục thiếu INCI:** dùng chỉ mục để tìm ứng viên, rồi
  đọc lại sản phẩm từ MongoDB trước khi lọc. Giữ bảng thành phần đầy đủ, nhóm,
  giá, hướng dẫn và trạng thái bán. Loại sản phẩm đã bị xóa khỏi database;
  không lấy thông tin cũ từ chỉ mục thay thế. Mở rộng ứng viên theo nhóm/hoạt chất.
- **Nhầm toner với trị mụn:** câu mới nêu nhóm cụ thể được ưu tiên; không để
  nhóm/hoạt chất/thương hiệu do câu trả lời cũ gợi ra thay thế yêu cầu mới.
  Lịch sử do khách nêu vẫn được dùng cho điều kiện tránh thành phần và ngân sách.
- **Nhận diện ý định:** phân biệt hỏi có nên dùng/mua với nhờ tìm sản phẩm;
  hỗ trợ “kiếm cho mình”, không dấu, số lượng, 12 nhóm sản phẩm, thương hiệu
  nêu rõ và câu tiếp nối “rẻ hơn”.
- **Ràng buộc thành phần:** chuẩn hóa `Fragrance/Parfum`, giữ phủ định, phân biệt
  “không hương liệu nhưng có retinol”; không coi “không giới hạn/không quá 500k”
  là thành phần. Không coi `Unknown` hoặc `N/A` là bảng thành phần đầy đủ.
- **Đầu ra mâu thuẫn:** kiểm tra sản phẩm được chọn, link, giá, chủ đề cũ và một
  số khẳng định phù hợp quá mức. Nếu không đạt, dựng câu trả lời từ dữ liệu đã lọc.
  Trường hợp không có lựa chọn giải thích rõ lý do lọc; lỗi truy xuất được báo
  riêng với `fallback_reason=catalog_unavailable`.
- **Quyết định dùng retinol:** tách việc tìm được sản phẩm khỏi việc nên bắt đầu
  dùng ngay khi hồ sơ/câu hiện tại nêu khô căng, bong tróc hoặc đỏ rát. Có câu hỏi
  xác nhận tình trạng còn diễn ra; không tự đưa lịch dùng hằng ngày cho trường hợp
  này. Quy tắc thận trọng dựa trên [AAD — Retinoid or retinol?](https://www.aad.org/public/everyday-care/skin-care-secrets/anti-aging/retinoid-retinol), đối chiếu ngày 09/09/2026.
- **PHP:** truyền đủ metadata, chỉ hiển thị danh sách Flask đã kiểm tra kể cả
  danh sách rỗng; bỏ câu bán hàng giả khi upstream thất bại và điểm mặc định 1.0.
- **API:** JSON sai kiểu, message không phải chuỗi hoặc rỗng trả 400 thay vì 500.

## Kiểm thử đã chạy

| Nhóm | Kết quả cuối | Phạm vi |
|---|---:|---|
| Python unittest | 36/36 test methods | Bao gồm bộ 100 tình huống cũ trong một test, cùng 30 test hồi quy mới; một số test dùng nhiều subcase |
| API Flask thật + đối chiếu MongoDB | 30/30 tình huống | Chuỗi 4 lượt, 12 nhóm, không dấu, giá, thương hiệu không tồn tại, loại trừ, routine, người khác, chi tiết sản phẩm, câu hỏi kiến thức |
| Biên HTTP | 12/12 tình huống | 8 payload không hợp lệ; 4 lượt đi qua PHP → Flask → PHP |
| PHP syntax | Đạt | `HomeController.php` |
| Whitespace trong file tracked đã sửa ở lượt này | Đạt | `HomeController.php`, `chatbot_flask.py` |

Trong quá trình kiểm thử đã sửa lỗi “không giới hạn” bị hiểu là thành phần,
điều kiện dương/âm bị gộp, ngân sách lịch sử không giới hạn bị hồ sơ ghi đè,
và các khẳng định/lịch dùng retinol không phù hợp với ngữ cảnh. Đã chạy lại
bộ unittest và toàn bộ API sau các thay đổi này.

Kết quả chuỗi hội thoại gốc trên bản cuối:

1. “Có nên mua retinol hiện tại”: `knowledge`, 0 thẻ, trả lời quyết định theo
   tình trạng đã nêu và hỏi xác nhận; không báo thiếu sản phẩm.
2. “Kiếm 1 chai retinol”: `search`, đúng 1 serum retinol, giá/thành phần được
   đối chiếu database; phân biệt tham khảo sản phẩm với khuyến nghị bắt đầu dùng.
3. Toner: `search`, 3 sản phẩm đúng nhóm.
4. Hỗ trợ trị mụn: `search`, 3 sản phẩm đúng nhóm, không lặp câu trả lời toner.

## Chạy lại

Từ thư mục gốc repository:

```powershell
python -X utf8 -m unittest discover -s ai-service-flask/tests -p 'test_*.py'
python -X utf8 report/chatbot-audit-2026-09-09/verify_live_fix.py
python -X utf8 report/chatbot-audit-2026-09-09/verify_php_boundary.py
php -l backend/app/controllers/HomeController.php
```

Hai script live sử dụng hội thoại guest giả lập và đọc catalog; không gửi email,
không sửa hồ sơ/giỏ hàng, không lưu hội thoại vào tài khoản. Chúng gọi model thật.
JSON chi tiết nằm trong `live-fix-results.json` và `php-boundary-results.json`.

## Giới hạn còn lại

- Đây là kiểm thử hành vi phần mềm và đối chiếu dữ liệu, chưa phải chứng nhận
  chất lượng tư vấn y khoa hoặc kiểm định mọi cách diễn đạt tiếng Việt.
- Bảng thành phần trong database có thể cũ hoặc gộp nhiều phiên bản. Việc có dữ
  liệu không được diễn giải thành bảo đảm hợp da. Chưa làm sạch toàn bộ catalog.
- Tập mở rộng trực tiếp giới hạn 200 ứng viên mỗi truy vấn có điều kiện; đây
  không phải phép chứng minh rằng toàn cửa hàng không còn lựa chọn khác.
- Đã kiểm tra HTTP qua PHP; chưa chạy thao tác bấm giao diện bằng trình duyệt.
- Log vẫn có cảnh báo telemetry Chroma có từ trước; các request kiểm thử cuối
  trả 200 hoặc 400 theo dự kiến, không ghi nhận lỗi 500 trong các lượt này.

Giữ nguyên các thay đổi khác vốn có trong workspace; không tạo commit hoặc
rebuild/xóa dữ liệu Chroma, MongoDB.
