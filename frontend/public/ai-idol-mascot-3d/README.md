# Syna 3D — bản thử riêng 15 giây

Mở `http://localhost:8080/ai-idol-mascot-3d/index.html?rev=syna-3d-v7` trên
Chrome/Edge, đợi chuẩn bị xong rồi bấm **Nghe bản thử**. Có thể tua, xem góc
nghiêng, giảm chuyển động, hoặc tải WebM có âm thanh. Giữ trang hiển thị khi
xuất video. Trong bước **Không gian** của AI Idol Studio, bản này được nhúng
ngay trong khung xem thử. Clip WebM này chỉ minh họa, không được dùng làm
motion template. Chọn **Syna 3D** trong ô Nhân vật dẫn chương trình để dùng
renderer riêng với giọng đọc đã duyệt, ảnh/tên/giá thật và bảng thành phần.
Xem vận hành và giới hạn tại `docs/ai-idol-syna-integration.md` ở gốc dự án.
Bản 2D và các luồng chatbot, recommend, voicechat được giữ nguyên.

## Phạm vi và giới hạn

- Hình học 3D thật: mèo kem, tai nghe/hoodie xanh, cổ ngắn, rau má lá hình quạt
  có mép khía và gân. Không dùng ảnh stock hay AI dựng video khuôn mặt.
- Hai tay là bề mặt liên tục với khớp vai–khuỷu–cổ tay; đầu/cổ/thân xoay riêng.
  Chào → nhìn bảng → chỉ rau má → quay lại người xem.
- Miệng mở theo năng lượng của âm thanh; **chưa phải lip-sync theo âm vị**.
  Chớp mắt theo các mốc không đều nhưng lặp lại được để kiểm thử. Chưa có đầy đủ
  kho biểu cảm/hành động/sticker của bản 2D.
- Đây là mẫu dựng bằng code để duyệt hướng 3D, không phải model hoàn thiện do
  họa sĩ dựng/rig. Chất lượng tạo hình và diễn xuất vẫn cần người dùng đánh giá.
- Thẻ ảnh/tên/giá sản phẩm luôn riêng với bảng thành phần. Gel dưỡng 50 ml,
  199.000 đ là **dữ liệu minh họa**, chưa kết nối danh mục, không phải công thức thật.
  Thông tin Centella là tham khảo về thành phần, không xác nhận sản phẩm mẫu chứa nó.
- Không chạy PyTorch, Docker avatar, TTS API hay dịch vụ trả phí. Trình duyệt vẫn
  dùng RAM, CPU và GPU để vẽ; không đồng nghĩa với không tốn tài nguyên/điện.
- Giữ 1280×720, tỉ lệ điểm ảnh 1, mục tiêu giới hạn 30 fps. Chuẩn bị shader trước
  khi bật nút nghe. Khi tạm dừng không vẽ WebGL lặp lại; ẩn trang sẽ dừng tiếng và
  hủy bản ghi dở. Máy/trình duyệt khác có thể đạt tốc độ khác.

## Cấu trúc

- `anatomy.js`: khối đầu tròn với má/mõm liền khối; bề mặt mắt/miệng/chi tiết áo
  cùng bám theo hình khối thật. Bản v2 thêm xem ngang ±90° và xoay đủ một vòng.
- `model.js`: hình học, vật liệu, khớp tay và tạo dáng Syna.
- `face-paint.js`: mắt vẽ trên mặt cong, mí khép không bóp con ngươi, má hồng mềm,
  chữ S trên tai nghe; texture tạo nội bộ và chỉ cập nhật khi trạng thái đổi.
- `scene.js`: camera/đèn, WebGL, chuẩn bị shader, giải phóng GPU. Các cue chỉ
  giá/bảng dùng dáng tay và sticker 2D; không vẽ que nối qua màn hình.
- `timeline.js`: chuyển động liên tục, trọng số khớp, biên độ miệng và mốc câu.
- `stage.js`: ghép sản phẩm, nhân vật, bảng thành phần và phụ đề vào canvas.
- `app.js`: một đồng hồ `audio.currentTime` cho tiếng/hình/phụ đề, điều khiển,
  xử lý lỗi, xuất WebM và số đo tốc độ; không gọi API sản phẩm.
- `assets/`: lời thoại 15 giây và mốc câu, không cần tài nguyên 2D lúc chạy.
  `syna-approved-centella.png` là ảnh mẫu đã sửa huy hiệu áo bằng imagegen, chỉ
  mở khi bấm liên kết đối chiếu; `syna-action-atlas.png` là bộ mốc biểu cảm/hành
  động do người dùng cung cấp. Cả hai đều chỉ là tham chiếu, không phải model
  hoặc khung hình nhân vật 3D.
- `vendor/`: Three.js 0.180.0 (MIT), bản chính thức r180, dùng nội bộ không CDN.
- `tests/pilot.test.js`: kiểm tra timeline, tay, giảm chuyển động và âm thanh thật.

Nguồn giọng: các câu nguyên vẹn của `../ai-idol-mascot-demo/assets/syna-presentation-v5.wav`
và mốc câu JSON tương ứng. Script `scripts/ai-idol-mascot/prepare-3d-audio.cjs`
dùng FFmpeg local để trích câu, không đổi tốc độ/giọng, thêm im lặng đến 15 giây;
script từ chối ghi đè tài nguyên đã tồn tại. Không cần chạy lại khi xem demo.

## Kiểm tra từ thư mục gốc dự án

```powershell
npm.cmd --prefix frontend/public/ai-idol-mascot-3d test
$env:NODE_PATH='C:\Users\GIGABYTE\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
node scripts/ai-idol-mascot/check-3d-browser.cjs
node scripts/ai-idol-mascot/check-approved-shape.cjs
```

Browser test dùng Playwright đã có và Chrome thật, với web local đang chạy cổng
8080. Kết quả, ảnh desktop/mobile và video xuất lưu ở `report/ai-idol-syna-3d/`
(artifact QA, không commit). Không cài thêm test runner hoặc tạo dữ liệu người dùng.
Xem kết quả chi tiết trong `docs/plans/2026-09-14-syna-3d-pilot-verification.md`.
Bản chỉnh độ đầy/góc ngang v2 và kiểm tra bổ sung được ghi trong
`docs/plans/2026-09-14-syna-3d-rounded-profile.md`.
Bản v3 sửa theo ảnh đã duyệt (rau má trên đầu/áo, mắt thấp/rộng, mõm ngắn,
tay chân ngắn, hoodie SkinSyntax) được ghi tại
`docs/plans/2026-09-14-syna-approved-design.md`. Chất liệu và hình khối vẫn đơn
giản hơn ảnh mẫu, chưa phải model sản xuất hoàn chỉnh.
Character Identity Bible khóa tỷ lệ và các chi tiết không được thay đổi nằm tại
`docs/plans/2026-09-14-syna-character-identity.md`.

Nguồn tham khảo về thành phần: [nghiên cứu công thức Centella](https://pubmed.ncbi.nlm.nih.gov/27168678/).
Renderer: [Three.js r180](https://github.com/mrdoob/three.js/tree/r180), giấy phép
giữ nguyên tại `vendor/LICENSE`.
