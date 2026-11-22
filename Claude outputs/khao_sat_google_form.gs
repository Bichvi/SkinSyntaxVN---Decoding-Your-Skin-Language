/**
 * Tạo tự động Google Form "Khảo sát mức độ hài lòng SkinSyntaxVN".
 *
 * Cách dùng:
 * 1. Mở https://script.google.com -> Dự án mới.
 * 2. Xóa code mẫu, dán toàn bộ file này vào, bấm Lưu.
 * 3. Chọn hàm taoKhaoSat -> Chạy -> cấp quyền cho tài khoản Google của bạn.
 * 4. Xem Nhật ký thực thi (Execution log): có link sửa form và link gửi khách.
 * Form được lưu trong Google Drive của bạn, sửa tiếp bình thường trên giao diện Google Form.
 */

var LIKERT = [
  '1 - Rất không đồng ý',
  '2 - Không đồng ý',
  '3 - Bình thường',
  '4 - Đồng ý',
  '5 - Rất đồng ý'
];

var NHOM = [
  {
    ten: 'Phần 2. Gợi ý sản phẩm',
    moTa: 'Danh sách sản phẩm hệ thống gợi ý theo loại da / vấn đề da của bạn.',
    hoiDaDung: 'Bạn đã xem phần gợi ý sản phẩm trên SkinSyntaxVN chưa?',
    sao: 'Bạn chấm mấy sao cho các sản phẩm được gợi ý?',
    likert: [
      '[REC1] Các sản phẩm được gợi ý phù hợp với loại da và vấn đề da của tôi.',
      '[REC2] Tôi hiểu vì sao những sản phẩm này được gợi ý cho mình.',
      '[REC3] Tôi muốn mua thử ít nhất một sản phẩm trong danh sách gợi ý.'
    ],
    chip: ['Đúng loại da', 'Giải thích dễ hiểu', 'Giá hợp lý', 'Không hợp da tôi', 'Lặp lại nhiều', 'Giá cao'],
    gopY: 'Bạn muốn hệ thống gợi ý thêm điều gì?'
  },
  {
    ten: 'Phần 3. Trợ lý AI Syna',
    moTa: 'Chatbot tư vấn chăm sóc da.',
    hoiDaDung: 'Bạn đã trò chuyện với trợ lý Syna chưa?',
    sao: 'Bạn chấm mấy sao cho cuộc trò chuyện với Syna?',
    likert: [
      '[CHAT1] Syna hiểu đúng câu hỏi của tôi.',
      '[CHAT2] Câu trả lời của Syna chính xác và hữu ích.',
      '[CHAT3] Tôi tin tưởng lời khuyên của Syna để chăm sóc da.'
    ],
    chip: ['Trả lời nhanh', 'Dễ hiểu', 'Gợi ý sản phẩm hay', 'Trả lời chậm', 'Lạc đề', 'Thông tin chưa đúng'],
    gopY: 'Có câu nào Syna trả lời chưa tốt không?'
  },
  {
    ten: 'Phần 4. Kiểm tra xung đột thành phần trong giỏ hàng',
    moTa: 'Cảnh báo khi các sản phẩm trong giỏ có thành phần không nên dùng chung.',
    hoiDaDung: 'Bạn đã thấy phần cảnh báo thành phần trong giỏ hàng chưa?',
    sao: 'Bạn chấm mấy sao cho phần kiểm tra thành phần?',
    likert: [
      '[CART1] Cảnh báo xung đột thành phần giúp tôi chọn sản phẩm an toàn hơn.',
      '[CART2] Nội dung cảnh báo rõ ràng, dễ hiểu.',
      '[CART3] Tôi đã hoặc sẽ thay đổi giỏ hàng dựa trên cảnh báo này.'
    ],
    chip: ['Rất cần thiết', 'Dễ hiểu', 'Cảnh báo thừa', 'Khó hiểu', 'Thiếu cách khắc phục'],
    gopY: 'Bạn muốn phần cảnh báo hiển thị thêm gì?'
  },
  {
    ten: 'Phần 5. Routine chăm sóc da',
    moTa: 'Chu trình các bước chăm sóc da được đề xuất cho bạn.',
    hoiDaDung: 'Bạn đã xem routine được đề xuất chưa?',
    sao: 'Bạn chấm mấy sao cho routine được đề xuất?',
    likert: [
      '[ROU1] Các bước trong routine phù hợp với tình trạng da của tôi.',
      '[ROU2] Thứ tự và cách dùng sản phẩm được hướng dẫn rõ ràng.',
      '[ROU3] Tôi sẽ áp dụng routine này hằng ngày.'
    ],
    chip: ['Dễ làm theo', 'Đủ bước', 'Quá nhiều bước', 'Thiếu bước', 'Không hợp da'],
    gopY: 'Bạn muốn routine thay đổi ở đâu?'
  }
];

function taoKhaoSat() {
  var form = FormApp.create('Khảo sát mức độ hài lòng – SkinSyntaxVN');
  form.setDescription(
    'Cảm ơn bạn đã trải nghiệm SkinSyntaxVN – nền tảng mỹ phẩm có trợ lý AI tư vấn da.\n' +
    'Khảo sát mất khoảng 3–5 phút. Câu trả lời ẩn danh, chỉ dùng cho mục đích nghiên cứu và cải thiện hệ thống.'
  );
  form.setProgressBar(true);
  form.setCollectEmail(false);
  form.setConfirmationMessage('Cảm ơn bạn đã dành thời gian góp ý cho SkinSyntaxVN! 💖');

  // ---------- Phần 1: Thông tin chung ----------
  form.addSectionHeaderItem().setTitle('Phần 1. Thông tin chung');
  form.addMultipleChoiceItem().setTitle('Độ tuổi của bạn')
    .setChoiceValues(['Dưới 18', '18 – 24', '25 – 34', '35 – 44', 'Từ 45 trở lên']).setRequired(true);
  form.addMultipleChoiceItem().setTitle('Giới tính')
    .setChoiceValues(['Nữ', 'Nam', 'Không muốn trả lời']).showOtherOption(true);
  form.addMultipleChoiceItem().setTitle('Loại da của bạn')
    .setChoiceValues(['Da dầu', 'Da khô', 'Da hỗn hợp', 'Da thường', 'Da nhạy cảm', 'Không rõ']).setRequired(true);
  form.addMultipleChoiceItem().setTitle('Bạn mua mỹ phẩm online bao lâu một lần?')
    .setChoiceValues(['Hằng tuần', 'Vài lần mỗi tháng', 'Mỗi tháng một lần', 'Vài tháng một lần', 'Hiếm khi']).setRequired(true);
  form.addMultipleChoiceItem().setTitle('Trước đây bạn đã từng dùng chatbot tư vấn mỹ phẩm chưa?')
    .setChoiceValues(['Đã từng', 'Chưa từng']).setRequired(true);

  // ---------- Phần 2–5: từng tính năng ----------
  // Tạo trang của từng nhóm theo thứ tự; câu "đã dùng chưa?" sẽ nhảy sang trang kế nếu chưa dùng.
  var cauHoiDaDung = [];
  var trangNhom = [];
  NHOM.forEach(function (n) {
    var trang = form.addPageBreakItem().setTitle(n.ten).setHelpText(n.moTa);
    trangNhom.push(trang);
    cauHoiDaDung.push(form.addMultipleChoiceItem().setTitle(n.hoiDaDung).setRequired(true));

    form.addScaleItem().setTitle('⭐ ' + n.sao)
      .setBounds(1, 5).setLabels('Rất tệ', 'Rất tốt').setRequired(true);
    form.addGridItem().setTitle('Mức độ đồng ý của bạn với các nhận định sau')
      .setRows(n.likert).setColumns(LIKERT).setRequired(true);
    form.addCheckboxItem().setTitle('Điều gì đúng với trải nghiệm của bạn? (chọn nhiều)')
      .setChoiceValues(n.chip).showOtherOption(true);
    form.addParagraphTextItem().setTitle(n.gopY);
  });

  // ---------- Phần 6: Hài lòng tổng thể ----------
  var trangTongThe = form.addPageBreakItem().setTitle('Phần 6. Đánh giá tổng thể');
  form.addScaleItem().setTitle('⭐ Nhìn chung bạn hài lòng với SkinSyntaxVN ở mức nào?')
    .setBounds(1, 5).setLabels('Rất không hài lòng', 'Rất hài lòng').setRequired(true);
  form.addGridItem().setTitle('Mức độ đồng ý của bạn với các nhận định sau')
    .setRows([
      '[ALL1] Hệ thống dễ sử dụng, tôi tìm được thứ mình cần nhanh chóng.',
      '[ALL2] Tôi sẽ tiếp tục dùng SkinSyntaxVN cho lần mua sau.'
    ])
    .setColumns(LIKERT).setRequired(true);
  form.addScaleItem().setTitle('[NPS] Bạn có sẵn lòng giới thiệu SkinSyntaxVN cho bạn bè không?')
    .setBounds(0, 10).setLabels('Chắc chắn không', 'Chắc chắn có').setRequired(true);
  form.addCheckboxItem().setTitle('Điều gì đúng với trải nghiệm chung của bạn? (chọn nhiều)')
    .setChoiceValues(['Giao diện đẹp', 'Tư vấn hay', 'Giao hàng nhanh', 'Khó tìm sản phẩm', 'Thanh toán rắc rối'])
    .showOtherOption(true);
  form.addParagraphTextItem().setTitle('Bạn thích điều gì nhất ở SkinSyntaxVN?');
  form.addParagraphTextItem().setTitle('Bạn muốn chúng tôi cải thiện điều gì nhất?');

  // Gắn điều hướng: "Chưa dùng" -> bỏ qua sang trang kế tiếp.
  cauHoiDaDung.forEach(function (q, i) {
    var trangKe = (i + 1 < trangNhom.length) ? trangNhom[i + 1] : trangTongThe;
    q.setChoices([
      q.createChoice('Đã dùng', FormApp.PageNavigationType.CONTINUE),
      q.createChoice('Chưa dùng', trangKe)
    ]);
  });

  // Tạo Google Sheet để nhận câu trả lời.
  var sheet = SpreadsheetApp.create('Kết quả khảo sát SkinSyntaxVN');
  form.setDestination(FormApp.DestinationType.SPREADSHEET, sheet.getId());

  Logger.log('Link sửa form: ' + form.getEditUrl());
  Logger.log('Link gửi khách: ' + form.getPublishedUrl());
  Logger.log('Sheet kết quả: ' + sheet.getUrl());
}
