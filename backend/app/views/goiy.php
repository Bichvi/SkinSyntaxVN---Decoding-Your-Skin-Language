<?php
// backend/app/views/goiy.php
?>
<div class="container mt-4">
  <div class="row">
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h2 class="mb-4">
            <i class="fas fa-lightbulb text-warning"></i>
            Gợi Ý Sản Phẩm
          </h2>
          <p class="text-muted mb-4">
            Nhập loại da hoặc mục đích chăm sóc của bạn để nhận được gợi ý sản phẩm phù hợp.
          </p>

          <form id="recommendForm" class="mb-5">
            <div class="mb-3">
              <label for="skinType" class="form-label fw-bold">Loại da của bạn</label>
              <select class="form-select form-select-lg" id="skinType" name="skin_type">
                <option value="">-- Chọn loại da --</option>
                <option value="dry">Da khô</option>
                <option value="oily">Da dầu</option>
                <option value="combination">Da hỗn hợp</option>
                <option value="sensitive">Da nhạy cảm</option>
                <option value="normal">Da thường</option>
              </select>
            </div>

            <div class="mb-3">
              <label for="concern" class="form-label fw-bold">Vấn đề da bạn muốn giải quyết</label>
              <div class="row">
                <div class="col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="acne" value="mụn" name="concern">
                    <label class="form-check-label" for="acne">Mụn</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="wrinkles" value="nếp nhăn" name="concern">
                    <label class="form-check-label" for="wrinkles">Nếp nhăn</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dryness" value="khô" name="concern">
                    <label class="form-check-label" for="dryness">Da khô</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="pigmentation" value="nám" name="concern">
                    <label class="form-check-label" for="pigmentation">Nám, tàn nhang</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="shine" value="dầu" name="concern">
                    <label class="form-check-label" for="shine">Dầu, bóng</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="sensitivity" value="nhạy cảm" name="concern">
                    <label class="form-check-label" for="sensitivity">Nhạy cảm</label>
                  </div>
                </div>
              </div>
            </div>

            <div class="mb-4">
              <label for="budget" class="form-label fw-bold">Mức giá mong muốn</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text">₫</span>
                <input type="number" class="form-control" id="budget" name="budget" placeholder="Không giới hạn" min="0">
              </div>
            </div>

            <button type="submit" class="btn btn-lg btn-brand w-100">
              <i class="fas fa-wand-magic-sparkles"></i> Nhận Gợi Ý
            </button>
          </form>

          <div id="recommendResults" class="mt-5" style="display: none;">
            <h4 class="mb-4">Sản phẩm được gợi ý cho bạn</h4>
            <div class="row" id="productsContainer"></div>
          </div>

          <div id="noResults" class="alert alert-info" style="display: none;">
            <p class="mb-0">Chưa tìm thấy sản phẩm phù hợp với tiêu chí của bạn. Vui lòng thử lại!</p>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card bg-light">
        <div class="card-body">
          <h5 class="card-title mb-3">💡 Mẹo chọn sản phẩm</h5>
          <ul class="list-unstyled">
            <li class="mb-2">
              <strong>Da khô:</strong> Cần dưỡng ẩm sâu, tránh sản phẩm có cồn
            </li>
            <li class="mb-2">
              <strong>Da dầu:</strong> Tìm sản phẩm kiểm soát dầu, không quá dưỡng
            </li>
            <li class="mb-2">
              <strong>Da nhạy cảm:</strong> Chọn sản phẩm dịu nhẹ, không mùi
            </li>
            <li class="mb-2">
              <strong>Ngừa mụn:</strong> Tìm thành phần: salicylic acid, benzoyl peroxide
            </li>
          </ul>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-body">
          <h5 class="card-title mb-3">📌 Danh mục phổ biến</h5>
          <div class="d-flex flex-column gap-2">
            <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm Sóc Da" class="btn btn-sm btn-outline-secondary">Chăm sóc da</a>
            <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Sữa Rửa Mặt" class="btn btn-sm btn-outline-secondary">Sữa rửa mặt</a>
            <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Toner" class="btn btn-sm btn-outline-secondary">Toner</a>
            <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Serum" class="btn btn-sm btn-outline-secondary">Serum</a>
            <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Kem Dưỡng" class="btn btn-sm btn-outline-secondary">Kem dưỡng</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .btn-brand {
    background-color: #e74c3c;
    color: white;
    border-color: #e74c3c;
  }
  .btn-brand:hover {
    background-color: #c0392b;
    border-color: #c0392b;
  }
  .form-select-lg, .input-group-lg .form-control {
    padding: 0.75rem 1rem;
    font-size: 1rem;
  }
  .product-card {
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s;
  }
  .product-card:hover {
    transform: translateY(-5px);
  }
</style>

<script>
document.getElementById('recommendForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const skinType = document.getElementById('skinType').value;
  const concerns = Array.from(document.querySelectorAll('input[name="concern"]:checked')).map(el => el.value);
  const budget = document.getElementById('budget').value;

  if (!skinType && concerns.length === 0) {
    alert('Vui lòng chọn loại da hoặc vấn đề da');
    return;
  }

  // Đây là nơi bạn có thể gọi API recommendation
  // Hiện tại chỉ hiển thị demo
  const results = document.getElementById('recommendResults');
  const noResults = document.getElementById('noResults');
  const container = document.getElementById('productsContainer');

  // Simulated recommendation results
  const recommendations = [
    { id: 1, name: 'Cleanser thích hợp', price: '250,000 VND', brand: 'SK-II' },
    { id: 2, name: 'Essence dưỡng ẩm', price: '350,000 VND', brand: 'COSRX' },
    { id: 3, name: 'Sheet mask', price: '80,000 VND', brand: 'Mediheal' },
    { id: 4, name: 'Night cream', price: '450,000 VND', brand: 'Sulwhasoo' }
  ];

  container.innerHTML = recommendations.map(p => `
    <div class="col-md-6 mb-3">
      <div class="card product-card">
        <div class="card-body">
          <h6 class="card-subtitle text-muted">${p.brand}</h6>
          <h5 class="card-title">${p.name}</h5>
          <p class="card-text text-danger"><strong>${p.price}</strong></p>
          <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=${p.id}" class="btn btn-sm btn-brand">Xem chi tiết</a>
        </div>
      </div>
    </div>
  `).join('');

  results.style.display = 'block';
  noResults.style.display = 'none';
});
</script>
