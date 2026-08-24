<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  body.modal-open {
    overflow: hidden;
  }

  .modal-backdrop.show {
    opacity: .72;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    background: linear-gradient(125deg, rgba(9, 18, 26, .42), rgba(36, 56, 48, .35));
  }

  .survey-modal .modal-content {
    border: 0;
    border-radius: 22px;
    overflow: hidden;
    background: linear-gradient(145deg, #fff9f6 0%, #f4fffb 45%, #f6fbff 100%);
    box-shadow: 0 22px 60px rgba(16, 24, 40, .22);
    font-family: 'Quicksand', sans-serif;
  }

  .survey-head {
    padding: 22px 26px 16px;
    border-bottom: 1px solid rgba(153, 173, 191, .22);
    background: rgba(255, 255, 255, .72);
    position: relative;
  }

  .survey-close {
    position: absolute;
    top: 16px;
    right: 16px;
    z-index: 3;
    background-color: rgba(255, 255, 255, .9);
    border-radius: 999px;
    width: 34px;
    height: 34px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, .12);
  }

  .survey-kicker {
    margin: 0;
    text-transform: uppercase;
    letter-spacing: .14em;
    font-size: 11px;
    color: #5d7187;
    font-weight: 700;
  }

  .survey-title {
    margin: 6px 0 8px;
    font-family: 'Quicksand', sans-serif;
    font-size: 38px;
    font-weight: 700;
    color: #113a35;
    line-height: 1;
  }

  @media (max-width: 575.98px) {
    .survey-title {
      font-size: 24px !important;
    }
    .survey-head, .survey-body {
      padding: 16px !important;
    }
  }

  .survey-subtitle {
    margin: 0;
    color: #5b6f83;
    font-size: 14px;
  }

  .survey-progress-wrap {
    margin-top: 14px;
  }

  .survey-progress-meta {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    font-weight: 700;
    color: #516678;
    margin-bottom: 8px;
  }

  .survey-progress {
    height: 10px;
    border-radius: 999px;
    background: #eaf0f7;
    overflow: hidden;
  }

  .survey-progress .progress-bar {
    border-radius: 999px;
    background: linear-gradient(120deg, #2D5A27 0%, #4A7C59 50%, #84A98C 100%);
    transition: width .25s ease;
  }

  .survey-body {
    padding: 22px 26px 10px;
    min-height: 0;
    max-height: min(62vh, 560px);
    overflow-y: auto;
    overflow-x: hidden;
  }

  .survey-body::-webkit-scrollbar {
    width: 10px;
  }

  .survey-body::-webkit-scrollbar-track {
    background: #eaf0f7;
    border-radius: 999px;
  }

  .survey-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #9adbc5 0%, #98c4f5 100%);
    border-radius: 999px;
    border: 2px solid #eaf0f7;
  }

  .survey-step {
    display: none;
    animation: stepIn .28s ease;
  }

  .survey-step.is-active {
    display: block;
  }

  .step-title {
    text-align: center;
    margin-bottom: 18px;
    color: #1c3347;
  }

  .step-title h4 {
    font-size: 22px;
    font-weight: 800;
    margin: 0 0 6px;
  }

  .step-title p {
    margin: 0;
    font-size: 13px;
    color: #667a8f;
  }

  .q-block {
    margin-bottom: 18px;
  }

  .q-title {
    display: block;
    margin-bottom: 10px;
    font-weight: 800;
    font-size: 15px;
    color: #1e3448;
  }

  .choice-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .choice-grid.choice-grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .choice-card {
    margin: 0;
    display: block;
    cursor: pointer;
  }

  .choice-card input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
  }

  .choice-body {
    position: relative;
    min-height: 58px;
    border: 1px solid #d7e3ef;
    border-radius: 14px;
    background: #fff;
    padding: 12px 38px 12px 12px;
    color: #2a3d52;
    font-size: 13px;
    font-weight: 600;
    transition: all .2s ease;
    display: flex;
    align-items: center;
    line-height: 1.4;
  }

  .choice-card:hover .choice-body {
    transform: translateY(-2px);
    box-shadow: 0 12px 22px rgba(26, 40, 56, .1);
    border-color: #bfd4e7;
  }

  .choice-check {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%) scale(.7);
    opacity: 0;
    transition: all .2s ease;
  }

  .choice-card input:checked + .choice-body {
    border-color: #7adbb8;
    background: linear-gradient(135deg, #f8fffc 0%, #fff5fb 100%);
    box-shadow: 0 0 0 3px rgba(122, 219, 184, .18);
    color: #0f4e43;
  }

  .choice-card input:checked + .choice-body .choice-check {
    opacity: 1;
    transform: translateY(-50%) scale(1);
  }

  .survey-select {
    border-radius: 14px;
    border: 1px solid #cfdeea;
    min-height: 50px;
    font-size: 14px;
    font-weight: 600;
    color: #23405a;
    background: rgba(255, 255, 255, .88);
  }

  .survey-select:focus {
    box-shadow: 0 0 0 .2rem rgba(76, 175, 142, .16);
    border-color: #85cfb0;
  }

  .survey-foot {
    border-top: 1px solid rgba(153, 173, 191, .22);
    background: rgba(255, 255, 255, .84);
    padding: 14px 26px 20px;
    position: sticky;
    bottom: 0;
    z-index: 4;
  }

  .survey-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
  }

  .survey-actions-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .survey-actions-right {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .btn-skip-inline {
    border-radius: 999px;
    padding: 10px 16px;
    font-weight: 700;
  }

  .btn-step {
    border-radius: 999px;
    padding: 10px 18px;
    font-weight: 700;
  }

  .btn-next-disabled {
    background: #c2cad5 !important;
    border-color: #c2cad5 !important;
    color: #ffffff !important;
    box-shadow: none !important;
  }

  .btn-survey-submit {
    border: 0;
    border-radius: 999px;
    padding: 13px 22px;
    min-width: 210px;
    font-weight: 800;
    letter-spacing: .02em;
    color: #fff;
    background: linear-gradient(120deg, #16a34a 0%, #0ea5a5 45%, #3b82f6 100%);
    box-shadow: 0 14px 30px rgba(37, 99, 235, .24);
  }

  .btn-survey-submit:hover {
    color: #fff;
    transform: translateY(-1px);
  }

  .survey-skip {
    margin-top: 10px;
    text-align: right;
  }

  .survey-skip .btn-link {
    color: #63778c;
    font-weight: 700;
    text-decoration: none;
  }

  .survey-skip .btn-link:hover {
    color: #1f4b45;
    text-decoration: underline;
  }

  .step-error {
    display: none;
    margin-top: 8px;
    color: #b42318;
    font-size: 13px;
    font-weight: 700;
  }

  .step-error.show {
    display: block;
  }

  @keyframes stepIn {
    from {
      opacity: 0;
      transform: translateY(8px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  @media (max-width: 991.98px) {
    .survey-title {
      font-size: 32px;
    }

    .survey-body {
      min-height: auto;
    }

    .choice-grid,
    .choice-grid.choice-grid-3 {
      grid-template-columns: 1fr;
    }

    .survey-actions {
      flex-wrap: wrap;
    }

    .btn-survey-submit {
      width: 100%;
    }
  }
</style>

<div class="modal fade survey-modal" id="surveyWizardModal" tabindex="-1" aria-labelledby="surveyWizardLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="survey-head">
        <button type="button" class="btn-close survey-close" id="btnCloseSurvey" aria-label="Đóng"></button>
        <p class="survey-kicker">AI Beauty Profile</p>
        <h2 class="survey-title" id="surveyWizardLabel">SkinSyntax Skin Quiz</h2>
        <p class="survey-subtitle">Xin chào <?= h((string)($pending['ho_ten'] ?? 'bạn')) ?>, hoàn tất khảo sát để AI gợi ý routine sát nhu cầu nhất.</p>

        <div class="survey-progress-wrap">
          <div class="survey-progress-meta">
            <span id="stepLabel">Bước 1 / 4</span>
            <span id="progressPercent">25%</span>
          </div>
          <div class="progress survey-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="25">
            <div class="progress-bar" id="wizardProgress" style="width:25%"></div>
          </div>
        </div>
      </div>

      <div class="survey-body">
        <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosat" id="surveyWizardForm">
          <section class="survey-step is-active" data-step="1">
            <div class="step-title">
              <h4>Phần 1. Thông tin cơ bản</h4>
              <p>2 câu hỏi để AI hiểu rõ chân dung người dùng của bạn.</p>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 1. Giới tính của bạn là gì?</label>
              <div class="choice-grid choice-grid-3">
                <label class="choice-card"><input type="radio" name="q1" value="Nữ" required><span class="choice-body">Nữ <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q1" value="Nam" required><span class="choice-body">Nam <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q1" value="Khác" required><span class="choice-body">Khác <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 2. Năm sinh của bạn?</label>
              <select class="form-select survey-select" name="q2" required>
                <option value="">-- Chọn năm sinh --</option>
                <?php $currentYear = (int)date('Y'); ?>
                <?php for ($year = max(2010, $currentYear); $year >= 1970; $year--): ?>
                  <option value="<?= $year ?>"><?= $year ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="step-error" data-step-error="1"></div>
          </section>

          <section class="survey-step" data-step="2">
            <div class="step-title">
              <h4>Phần 2. Phân tích da chuyên sâu</h4>
              <p>Tập trung vào tình trạng và mục tiêu ưu tiên của da.</p>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 3. Bạn tự đánh giá loại da của mình là gì?</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="radio" name="q3" value="Da dầu/Hỗn hợp dầu" required><span class="choice-body">Da dầu / Hỗn hợp thiên dầu <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da khô/Hỗn hợp khô" required><span class="choice-body">Da khô / Hỗn hợp thiên khô <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da thường/Mọi loại da" required><span class="choice-body">Da thường / Không có vấn đề đặc biệt <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da nhạy cảm" required><span class="choice-body">Da nhạy cảm <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da mụn" required><span class="choice-body">Da mụn <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da khô" required><span class="choice-body">Da khô <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Da hỗn hợp thiên dầu" required><span class="choice-body">Da hỗn hợp thiên dầu <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q3" value="Unknown" required><span class="choice-body">Unknown <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 4. Da bạn có dễ bị kích ứng, mẩn đỏ không?</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="radio" name="q4" value="Rất dễ" required><span class="choice-body">Rất dễ (Da nhạy cảm) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q4" value="Khỏe mạnh, hiếm khi" required><span class="choice-body">Khỏe mạnh, hiếm khi <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 5. Vấn đề da bạn đang muốn cải thiện nhất? (Có thể chọn nhiều)</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Mụn viêm, sưng đỏ"><span class="choice-body">Mụn viêm, sưng đỏ <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Mụn ẩn, mụn đầu đen"><span class="choice-body">Mụn ẩn, mụn đầu đen <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Lỗ chân lông to"><span class="choice-body">Lỗ chân lông to <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Thâm mụn, sạm nám, tàn nhang"><span class="choice-body">Thâm mụn, sạm nám, tàn nhang <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Lão hóa, nếp nhăn"><span class="choice-body">Lão hóa, nếp nhăn <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q5[]" value="Da khô căng, bong tróc"><span class="choice-body">Da khô căng, bong tróc <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 6. Mục tiêu chăm sóc da ưu tiên nhất của bạn hiện tại?</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="radio" name="q6" value="Sạch mụn, giảm viêm"><span class="choice-body">Sạch mụn, giảm viêm <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q6" value="Dưỡng sáng, mờ thâm nám"><span class="choice-body">Dưỡng sáng, mờ thâm nám <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q6" value="Phục hồi màng bảo vệ da, cấp ẩm"><span class="choice-body">Phục hồi màng bảo vệ da, cấp ẩm <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q6" value="Chống lão hóa, trẻ hóa da"><span class="choice-body">Chống lão hóa, trẻ hóa da <span class="choice-check">✅</span></span></label>
              </div>
            </div>
            <div class="step-error" data-step-error="2"></div>
          </section>

          <section class="survey-step" data-step="3">
            <div class="step-title">
              <h4>Phần 3. Hoạt chất, sở thích và ngân sách</h4>
              <p>AI sẽ dùng nhóm dữ liệu này để cá nhân hóa sản phẩm phù hợp.</p>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 7. Bạn thích kết cấu sản phẩm như thế nào? (Có thể chọn nhiều)</label>
              <div class="choice-grid choice-grid-3">
                <label class="choice-card"><input type="checkbox" name="q7[]" value="Gel"><span class="choice-body">Dạng Gel (Thấm nhanh, mỏng nhẹ) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q7[]" value="Kem"><span class="choice-body">Dạng Kem (Cream - Đặc, dưỡng ẩm sâu) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q7[]" value="Lỏng/Nước"><span class="choice-body">Dạng Lỏng/Nước (Toner/Essence) <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 8. Hoạt chất bạn rất muốn có trong chu trình? (Có thể chọn nhiều)</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Niacinamide"><span class="choice-body">Niacinamide (Vitamin B3) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="BHA / Salicylic Acid"><span class="choice-body">BHA / Salicylic Acid <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Vitamin C"><span class="choice-body">Vitamin C <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Retinol / Tretinoin"><span class="choice-body">Retinol / Tretinoin <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="AHA / Glycolic Acid"><span class="choice-body">AHA / Glycolic Acid <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Hyaluronic Acid"><span class="choice-body">Hyaluronic Acid <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Ceramide"><span class="choice-body">Ceramide <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Tranexamic Acid"><span class="choice-body">Tranexamic Acid <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Azelaic Acid"><span class="choice-body">Azelaic Acid <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Peptide"><span class="choice-body">Peptide <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Centella / Cica"><span class="choice-body">Centella / Cica <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q8[]" value="Panthenol (B5)"><span class="choice-body">Panthenol (Vitamin B5) <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 9. Thành phần bạn dị ứng hoặc muốn tránh xa? (Có thể chọn nhiều)</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Alcohol"><span class="choice-body">Cồn khô (Alcohol) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Fragrance/Parfum"><span class="choice-body">Hương liệu (Fragrance/Parfum) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Paraben"><span class="choice-body">Chất bảo quản Paraben <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Mineral Oil"><span class="choice-body">Dầu khoáng (Mineral Oil) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Sulfate (SLS/SLES)"><span class="choice-body">Sulfate (SLS/SLES) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Silicone"><span class="choice-body">Silicone <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Essential Oil"><span class="choice-body">Tinh dầu đậm đặc (Essential Oil) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="MIT/CMIT"><span class="choice-body">Chất bảo quản MIT/CMIT <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Colorant"><span class="choice-body">Phẩm màu tổng hợp <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="Lanolin"><span class="choice-body">Lanolin <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q9[]" value="KhongCo"><span class="choice-body">Không có / Không quan tâm <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 10. Ngân sách tối đa cho 1 sản phẩm?</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="radio" name="q10" value="duoi_200k" required><span class="choice-body">Dưới 200.000đ (Bình dân/Học sinh) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q10" value="200_500k" required><span class="choice-body">200.000đ - 500.000đ (Tầm trung) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q10" value="500_1000k" required><span class="choice-body">500.000đ - 1.000.000đ (Cao cấp) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="radio" name="q10" value="tren_1000k" required><span class="choice-body">Trên 1.000.000đ (High-end) <span class="choice-check">✅</span></span></label>
              </div>
            </div>
            <div class="step-error" data-step-error="3"></div>
          </section>

          <section class="survey-step" data-step="4">
            <div class="step-title">
              <h4>Phần 4. Gu thương hiệu và xuất xứ</h4>
              <p>Bước cuối cùng để hệ thống tinh chỉnh danh sách sản phẩm dành riêng cho bạn.</p>
            </div>

            <div class="q-block">
              <label class="q-title">Câu 11. Xuất xứ mỹ phẩm bạn yêu thích? (Có thể chọn nhiều)</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Việt Nam"><span class="choice-body">Việt Nam (Local Brand) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Hàn Quốc"><span class="choice-body">Hàn Quốc (K-Beauty) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Pháp"><span class="choice-body">Pháp (Dược mỹ phẩm) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Mỹ"><span class="choice-body">Mỹ / Anh (Âu Mỹ) <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Nhật Bản"><span class="choice-body">Nhật Bản <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Anh"><span class="choice-body">Anh <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Úc"><span class="choice-body">Úc <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Đức"><span class="choice-body">Đức <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Thái Lan"><span class="choice-body">Thái Lan <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q11[]" value="Trung Quốc"><span class="choice-body">Trung Quốc <span class="choice-check">✅</span></span></label>
              </div>
            </div>

            <div class="q-block mb-0">
              <label class="q-title">Câu 12. Thương hiệu ưu tiên của bạn? (Có thể chọn nhiều)</label>
              <div class="choice-grid">
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Cocoon"><span class="choice-body">Cocoon <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="La Roche-Posay"><span class="choice-body">La Roche-Posay <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="L'Oreal"><span class="choice-body">L'Oreal <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Paula's Choice"><span class="choice-body">Paula's Choice <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Klairs"><span class="choice-body">Klairs <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="CeraVe"><span class="choice-body">CeraVe <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Bioderma"><span class="choice-body">Bioderma <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Vichy"><span class="choice-body">Vichy <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="Cetaphil"><span class="choice-body">Cetaphil <span class="choice-check">✅</span></span></label>
                <label class="choice-card"><input type="checkbox" name="q12[]" value="COSRX"><span class="choice-body">COSRX <span class="choice-check">✅</span></span></label>
              </div>
            </div>
            <div class="step-error" data-step-error="4"></div>
          </section>
        </form>
      </div>

      <div class="survey-foot">
        <div class="survey-actions">
          <div class="survey-actions-left">
            <button type="button" class="btn btn-outline-secondary btn-step" id="btnCloseSurveyFooter">Đóng</button>
            <button type="button" class="btn btn-outline-secondary btn-step" id="btnPrev" disabled>Quay lại</button>
          </div>

          <div class="survey-actions-right">
            <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosat" class="m-0">
              <input type="hidden" name="skip" value="1">
              <button class="btn btn-outline-secondary btn-skip-inline" type="submit">Bỏ qua</button>
            </form>
            <button type="button" class="btn btn-brand btn-step" id="btnNext">Kế tiếp</button>
            <button type="submit" form="surveyWizardForm" class="btn btn-survey-submit d-none" id="btnSubmit">Lưu hồ sơ AI</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const modalEl = document.getElementById('surveyWizardModal');
    const form = document.getElementById('surveyWizardForm');
    if (!modalEl || !form) return;

    const steps = Array.from(form.querySelectorAll('.survey-step'));
    const totalSteps = steps.length;
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');
    const btnCloseSurvey = document.getElementById('btnCloseSurvey');
    const btnCloseSurveyFooter = document.getElementById('btnCloseSurveyFooter');
    const progress = document.getElementById('wizardProgress');
    const progressPercent = document.getElementById('progressPercent');
    const stepLabel = document.getElementById('stepLabel');

    if (!btnPrev || !btnNext || !btnSubmit || !progress || !progressPercent || !stepLabel) {
      return;
    }

    let currentStep = 0;

    function clearStepError(stepIndex) {
      const el = steps[stepIndex].querySelector('[data-step-error]');
      if (!el) return;
      el.classList.remove('show');
      el.textContent = '';
    }

    function showStepError(stepIndex, message) {
      const el = steps[stepIndex].querySelector('[data-step-error]');
      if (!el) return;
      el.textContent = message;
      el.classList.add('show');
    }

    function hasAnyChecked(name) {
      return form.querySelectorAll('input[name="' + name + '"]:checked').length > 0;
    }

    function validateCurrentStep() {
      clearStepError(currentStep);

      if (currentStep === 0) {
        const q1Ok = hasAnyChecked('q1');
        const q2 = form.querySelector('select[name="q2"]');
        const q2Ok = !!(q2 && String(q2.value || '').trim() !== '');
        if (!q1Ok || !q2Ok) {
          showStepError(currentStep, 'Vui lòng chọn giới tính và năm sinh trước khi tiếp tục.');
          return false;
        }
      }

      if (currentStep === 1) {
        if (!hasAnyChecked('q3') || !hasAnyChecked('q4')) {
          showStepError(currentStep, 'Vui lòng hoàn thành Câu 3 và Câu 4.');
          return false;
        }
      }

      if (currentStep === 1 && !hasAnyChecked('q5[]')) {
        showStepError(currentStep, 'Vui lòng chọn ít nhất 1 vấn đề da ở Câu 5.');
        return false;
      }

      if (currentStep === 2 && !hasAnyChecked('q9[]')) {
        showStepError(currentStep, 'Vui lòng chọn ít nhất 1 đáp án ở Câu 9.');
        return false;
      }

      if (currentStep === 2 && !hasAnyChecked('q10')) {
        showStepError(currentStep, 'Vui lòng chọn ngân sách ở Câu 10.');
        return false;
      }

      return true;
    }

    function isStepComplete(stepIndex) {
      if (stepIndex === 0) {
        const q2 = form.querySelector('select[name="q2"]');
        return hasAnyChecked('q1') && !!(q2 && String(q2.value || '').trim() !== '');
      }

      if (stepIndex === 1) {
        return hasAnyChecked('q3') && hasAnyChecked('q4') && hasAnyChecked('q5[]');
      }

      if (stepIndex === 2) {
        return hasAnyChecked('q9[]') && hasAnyChecked('q10');
      }

      return true;
    }

    function updateNextButtonState() {
      const isLast = currentStep === totalSteps - 1;
      if (isLast) {
        btnNext.classList.add('d-none');
        return;
      }

      const canNext = isStepComplete(currentStep);
      btnNext.classList.remove('d-none');
      btnNext.classList.toggle('btn-next-disabled', !canNext);
      btnNext.setAttribute('aria-disabled', canNext ? 'false' : 'true');
    }

    function renderStep() {
      steps.forEach((step, idx) => {
        step.classList.toggle('is-active', idx === currentStep);
      });

      const percent = Math.round(((currentStep + 1) / totalSteps) * 100);
      progress.style.width = percent + '%';
      progress.parentElement.setAttribute('aria-valuenow', String(percent));
      progressPercent.textContent = percent + '%';
      stepLabel.textContent = 'Bước ' + (currentStep + 1) + ' / ' + totalSteps;

      btnPrev.disabled = currentStep === 0;
      const isLast = currentStep === totalSteps - 1;
      btnNext.classList.toggle('d-none', isLast);
      btnSubmit.classList.toggle('d-none', !isLast);
      updateNextButtonState();
    }

    btnNext.addEventListener('click', function () {
      if (!isStepComplete(currentStep)) {
        showStepError(currentStep, 'Vui lòng điền đầy đủ thông tin để qua bước mới.');
        return;
      }

      if (!validateCurrentStep()) return;
      if (currentStep < totalSteps - 1) {
        currentStep += 1;
        renderStep();
        const surveyBody = form.closest('.survey-body');
        if (surveyBody && typeof surveyBody.scrollTo === 'function') {
          surveyBody.scrollTo({ top: 0, behavior: 'smooth' });
        }
      }
    });

    btnPrev.addEventListener('click', function () {
      if (currentStep > 0) {
        currentStep -= 1;
        renderStep();
        const surveyBody = form.closest('.survey-body');
        if (surveyBody && typeof surveyBody.scrollTo === 'function') {
          surveyBody.scrollTo({ top: 0, behavior: 'smooth' });
        }
      }
    });

    form.addEventListener('change', function () {
      clearStepError(currentStep);
      updateNextButtonState();
    });

    form.addEventListener('input', function () {
      clearStepError(currentStep);
      updateNextButtonState();
    });

    form.addEventListener('submit', function (event) {
      const q2El = form.querySelector('select[name="q2"]');
      const q2Value = q2El ? q2El.value : '';

      const checks = [
        { ok: hasAnyChecked('q1') && !!q2Value, step: 0, msg: 'Vui lòng hoàn thành Phần 1.' },
        { ok: hasAnyChecked('q3') && hasAnyChecked('q4') && hasAnyChecked('q5[]'), step: 1, msg: 'Vui lòng hoàn thành đầy đủ Phần 2.' },
        { ok: hasAnyChecked('q9[]') && hasAnyChecked('q10'), step: 2, msg: 'Vui lòng hoàn thành các câu bắt buộc ở Phần 3.' }
      ];

      for (const item of checks) {
        if (!item.ok) {
          event.preventDefault();
          currentStep = item.step;
          renderStep();
          showStepError(item.step, item.msg);
          return;
        }
      }
    });

    function closeSurvey() {
      window.location.href = '<?= BASE_URL ?>/index.php?r=home';
    }

    if (btnCloseSurvey) {
      btnCloseSurvey.addEventListener('click', function () {
        closeSurvey();
      });
    }

    if (btnCloseSurveyFooter) {
      btnCloseSurveyFooter.addEventListener('click', function () {
        closeSurvey();
      });
    }

    modalEl.addEventListener('hidden.bs.modal', function () {
      if (!document.body.classList.contains('modal-open')) {
        window.location.href = '<?= BASE_URL ?>/index.php?r=home';
      }
    });

    window.addEventListener('load', function () {
      renderStep();
      if (window.bootstrap && window.bootstrap.Modal) {
        const modal = new window.bootstrap.Modal(modalEl, {
          backdrop: true,
          keyboard: true
        });
        modal.show();
      }
    });
  })();
</script>
