<div class="container my-4">
  <div class="auth-card" style="max-width: 980px; margin: 0 auto;">
    <h3 class="mb-2">📝 Khảo sát AI cá nhân hóa</h3>
    <p class="text-muted mb-4">Khảo sát 12 câu để hệ thống gợi ý sản phẩm sát nhu cầu và thói quen mua sắm của bạn.</p>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosat">
      <div class="mb-4">
        <h5 class="mb-3">PHẦN 1: THÔNG TIN CƠ BẢN</h5>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 1. Giới tính của bạn là gì?</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="Nữ" required><label class="form-check-label">Nữ</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="Nam" required><label class="form-check-label">Nam</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="Khác" required><label class="form-check-label">Khác</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 2. Năm sinh của bạn?</label>
          <select class="form-select" name="q2" required>
            <option value="">-- Chọn năm sinh --</option>
            <?php $currentYear = (int)date('Y'); ?>
            <?php for ($year = max(2010, $currentYear); $year >= 1970; $year--): ?>
              <option value="<?= $year ?>"><?= $year ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>

      <div class="mb-4">
        <h5 class="mb-3">PHẦN 2: PHÂN TÍCH CHUYÊN SÂU (Dữ liệu cho AI)</h5>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 3. Bạn tự đánh giá loại da của mình là gì?</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da dầu/Hỗn hợp dầu" required><label class="form-check-label">Da dầu / Hỗn hợp thiên dầu</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da khô/Hỗn hợp khô" required><label class="form-check-label">Da khô / Hỗn hợp thiên khô</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da thường/Mọi loại da" required><label class="form-check-label">Da thường / Không có vấn đề đặc biệt</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da nhạy cảm" required><label class="form-check-label">Da nhạy cảm</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da mụn" required><label class="form-check-label">Da mụn</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da khô" required><label class="form-check-label">Da khô</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Da hỗn hợp thiên dầu" required><label class="form-check-label">Da hỗn hợp thiên dầu</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Unknown" required><label class="form-check-label">Unknown</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 4. Da bạn có dễ bị kích ứng, mẩn đỏ không?</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="Rất dễ" required><label class="form-check-label">Rất dễ (Da nhạy cảm)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="Khỏe mạnh, hiếm khi" required><label class="form-check-label">Khỏe mạnh, hiếm khi</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 5. Vấn đề da bạn đang muốn cải thiện nhất? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Mụn viêm, sưng đỏ"><label class="form-check-label">Mụn viêm, sưng đỏ</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Mụn ẩn, mụn đầu đen"><label class="form-check-label">Mụn ẩn, mụn đầu đen</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Lỗ chân lông to"><label class="form-check-label">Lỗ chân lông to</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Thâm mụn, sạm nám, tàn nhang"><label class="form-check-label">Thâm mụn, sạm nám, tàn nhang</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Lão hóa, nếp nhăn"><label class="form-check-label">Lão hóa, nếp nhăn</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q5[]" value="Da khô căng, bong tróc"><label class="form-check-label">Da khô căng, bong tróc</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 6. Mục tiêu chăm sóc da ưu tiên nhất của bạn hiện tại?</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Sạch mụn, giảm viêm"><label class="form-check-label">Sạch mụn, giảm viêm</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Dưỡng sáng, mờ thâm nám"><label class="form-check-label">Dưỡng sáng, mờ thâm nám</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Phục hồi màng bảo vệ da, cấp ẩm"><label class="form-check-label">Phục hồi màng bảo vệ da, cấp ẩm</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Chống lão hóa, trẻ hóa da"><label class="form-check-label">Chống lão hóa, trẻ hóa da</label></div>
        </div>
      </div>

      <div class="mb-4">
        <h5 class="mb-3">PHẦN 3: SỞ THÍCH & HÀNH VI MUA SẮM</h5>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 7. Bạn thích kết cấu sản phẩm như thế nào? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q7[]" value="Gel"><label class="form-check-label">Dạng Gel (Thấm nhanh, mỏng nhẹ)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q7[]" value="Kem"><label class="form-check-label">Dạng Kem (Cream - Đặc, dưỡng ẩm sâu)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q7[]" value="Lỏng/Nước"><label class="form-check-label">Dạng Lỏng/Nước (Toner/Essence)</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 8. Hoạt chất bạn rất muốn có trong chu trình? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Niacinamide"><label class="form-check-label">Niacinamide (Vitamin B3)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="BHA / Salicylic Acid"><label class="form-check-label">BHA / Salicylic Acid</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Vitamin C"><label class="form-check-label">Vitamin C</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Retinol / Tretinoin"><label class="form-check-label">Retinol / Tretinoin</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="AHA / Glycolic Acid"><label class="form-check-label">AHA / Glycolic Acid</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Hyaluronic Acid"><label class="form-check-label">Hyaluronic Acid</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Ceramide"><label class="form-check-label">Ceramide</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Tranexamic Acid"><label class="form-check-label">Tranexamic Acid</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Azelaic Acid"><label class="form-check-label">Azelaic Acid</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Peptide"><label class="form-check-label">Peptide</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Centella / Cica"><label class="form-check-label">Centella / Cica</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q8[]" value="Panthenol (B5)"><label class="form-check-label">Panthenol (Vitamin B5)</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 9. Thành phần bạn dị ứng hoặc muốn tránh xa? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Alcohol" ><label class="form-check-label">Cồn khô (Alcohol)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Fragrance/Parfum" ><label class="form-check-label">Hương liệu (Fragrance/Parfum)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Paraben" ><label class="form-check-label">Chất bảo quản Paraben</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Mineral Oil" ><label class="form-check-label">Dầu khoáng (Mineral Oil)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Sulfate (SLS/SLES)" ><label class="form-check-label">Sulfate (SLS/SLES)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Silicone" ><label class="form-check-label">Silicone</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Essential Oil" ><label class="form-check-label">Tinh dầu đậm đặc (Essential Oil)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="MIT/CMIT" ><label class="form-check-label">Chất bảo quản MIT/CMIT</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Colorant" ><label class="form-check-label">Phẩm màu tổng hợp</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="Lanolin" ><label class="form-check-label">Lanolin</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q9[]" value="KhongCo" ><label class="form-check-label">Không có / Không quan tâm</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 10. Ngân sách tối đa cho 1 sản phẩm?</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="duoi_200k" required><label class="form-check-label">Dưới 200.000đ (Bình dân/Học sinh)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="200_500k" required><label class="form-check-label">200.000đ - 500.000đ (Tầm trung)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="500_1000k" required><label class="form-check-label">500.000đ - 1.000.000đ (Cao cấp)</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="tren_1000k" required><label class="form-check-label">Trên 1.000.000đ (High-end)</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 11. Xuất xứ mỹ phẩm bạn yêu thích? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Việt Nam"><label class="form-check-label">Việt Nam (Local Brand)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Hàn Quốc"><label class="form-check-label">Hàn Quốc (K-Beauty)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Pháp"><label class="form-check-label">Pháp (Dược mỹ phẩm)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Mỹ"><label class="form-check-label">Mỹ / Anh (Âu Mỹ)</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Nhật Bản"><label class="form-check-label">Nhật Bản</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Anh"><label class="form-check-label">Anh</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Úc"><label class="form-check-label">Úc</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Đức"><label class="form-check-label">Đức</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Thái Lan"><label class="form-check-label">Thái Lan</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q11[]" value="Trung Quốc"><label class="form-check-label">Trung Quốc</label></div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Câu 12. Thương hiệu ưu tiên của bạn? (Có thể chọn nhiều)</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Cocoon"><label class="form-check-label">Cocoon</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="La Roche-Posay"><label class="form-check-label">La Roche-Posay</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="L'Oreal"><label class="form-check-label">L'Oreal</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Paula's Choice"><label class="form-check-label">Paula's Choice</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Klairs"><label class="form-check-label">Klairs</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="CeraVe"><label class="form-check-label">CeraVe</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Bioderma"><label class="form-check-label">Bioderma</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Vichy"><label class="form-check-label">Vichy</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="Cetaphil"><label class="form-check-label">Cetaphil</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="q12[]" value="COSRX"><label class="form-check-label">COSRX</label></div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-brand" type="submit">Lưu khảo sát</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/index.php?r=home">Để sau</a>
      </div>
    </form>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosat" class="mt-3">
      <input type="hidden" name="skip" value="1">
      <button class="btn btn-link p-0" type="submit">Bỏ qua khảo sát</button>
    </form>
  </div>
</div>
