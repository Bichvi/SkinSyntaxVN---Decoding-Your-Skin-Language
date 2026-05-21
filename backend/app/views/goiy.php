<?php
$needsSurvey = $needsSurvey ?? true;
$isLoggedIn = $isLoggedIn ?? false;
$surveyUrl = $surveyUrl ?? (BASE_URL . '/index.php?r=khaosat');
$profile = $recommendationProfile ?? null;
$profilePayload = $profile ? [
    'gioi_tinh' => (string)($profile['gioi_tinh'] ?? ''),
    'nam_sinh' => (string)($profile['nam_sinh'] ?? ''),
    'skin_type' => (string)($profile['skin_type'] ?? ''),
    'concerns' => array_values($profile['concerns'] ?? []),
    'avoid_ingredients' => implode(', ', $profile['avoid_ingredients'] ?? []),
    'budget' => (string)($profile['budget'] ?? ''),
] : [];
?>
<div class="container recommendation-page mt-4 mb-5">
  <?php if ($needsSurvey): ?>
    <section class="recommend-entry recommend-entry--survey">
      <div class="recommend-entry__content">
        <span class="recommend-entry__eyebrow">Bắt đầu với khảo sát</span>
        <h1 class="recommend-entry__title">Bạn chưa thực hiện khảo sát, làm khảo sát ngay để nhận gợi ý!!</h1>
        <p class="recommend-entry__desc">
          Hoàn tất vài câu hỏi ngắn để hệ thống hiểu loại da, vấn đề da và ngân sách của bạn trước khi gợi ý sản phẩm.
        </p>
        <a class="recommend-entry__button recommend-entry__button--survey" href="<?= h($surveyUrl) ?>">Khảo sát ngay</a>
        <?php if (!$isLoggedIn): ?>
          <p class="recommend-entry__hint">Bạn cần đăng nhập trước khi vào khảo sát.</p>
        <?php endif; ?>
      </div>

      <div class="recommend-entry__visual recommend-entry__visual--survey" aria-hidden="true">
        <div class="recommend-floating-card">
          <span class="recommend-floating-card__label">Skin check</span>
          <strong>3 phút để mở gợi ý đúng nhu cầu</strong>
          <p>Hệ thống sẽ dùng kết quả khảo sát để lọc nhóm sản phẩm phù hợp hơn.</p>
        </div>
        <div class="recommend-floating-pill">Da dầu</div>
        <div class="recommend-floating-pill">Da nhạy cảm</div>
        <div class="recommend-floating-pill">Ngân sách phù hợp</div>
      </div>
    </section>
  <?php else: ?>
    <section class="recommend-entry recommend-entry--ready">
      <div class="recommend-entry__content">
        <span class="recommend-entry__eyebrow">Gợi ý cá nhân hóa</span>
        <h1 class="recommend-entry__title">Dựa trên khảo sát bạn đã thực hiện, hãy bấm nhận gợi ý ngay</h1>
        <p class="recommend-entry__desc">
          Hồ sơ của <?= h((string)($profile['display_name'] ?? 'bạn')) ?> đã sẵn sàng. Nhấn nút bên dưới để lấy danh sách sản phẩm phù hợp nhất.
        </p>

        <div class="recommend-summary">
          <span class="recommend-summary__chip"><?= h((string)($profile['gioi_tinh'] ?? 'Chưa rõ giới tính')) ?></span>
          <span class="recommend-summary__chip">Năm sinh <?= h((string)($profile['nam_sinh'] ?? '')) ?></span>
          <?php if (!empty($profile['skin_type'])): ?>
            <span class="recommend-summary__chip"><?= h((string)$profile['skin_type']) ?></span>
          <?php endif; ?>
          <?php foreach (array_slice($profile['concerns'] ?? [], 0, 3) as $concern): ?>
            <span class="recommend-summary__chip recommend-summary__chip--soft"><?= h((string)$concern) ?></span>
          <?php endforeach; ?>
          <span class="recommend-summary__chip recommend-summary__chip--accent"><?= h((string)($profile['budget_label'] ?? 'Không giới hạn')) ?></span>
        </div>

        <div class="recommend-profile-panel">
          <div class="recommend-profile-panel__row">
            <span>Loại da</span>
            <strong><?= h((string)($profile['skin_type'] ?? 'Chưa có')) ?></strong>
          </div>
          <div class="recommend-profile-panel__row">
            <span>Vấn đề ưu tiên</span>
            <strong><?= h(!empty($profile['concerns']) ? implode(', ', array_slice($profile['concerns'], 0, 3)) : 'Chưa có') ?></strong>
          </div>
          <div class="recommend-profile-panel__row">
            <span>Thành phần cần tránh</span>
            <strong><?= h(!empty($profile['avoid_ingredients']) ? implode(', ', array_slice($profile['avoid_ingredients'], 0, 3)) : 'Không có') ?></strong>
          </div>
          <div class="recommend-profile-panel__row">
            <span>Ngân sách</span>
            <strong><?= h((string)($profile['budget_label'] ?? 'Không giới hạn')) ?></strong>
          </div>
        </div>

        <label class="recommend-query-box" for="recommendQueryText">
          <span class="recommend-query-box__label">Mô tả nhu cầu chi tiết</span>
          <textarea
            id="recommendQueryText"
            class="recommend-query-box__input"
            rows="4"
            placeholder="Ví dụ: Da dầu nhạy cảm, đang có mụn ẩn và thâm nhẹ, muốn serum hoặc kem dưỡng dưới 500.000 VND để dùng buổi tối."
          ></textarea>
          <span class="recommend-query-box__hint">Bạn có thể nhập dạng câu tự nhiên để hệ thống hybrid search hiểu rõ hơn nhu cầu hiện tại.</span>
        </label>

        <button
          type="button"
          id="recommendTrigger"
          class="recommend-entry__button recommend-entry__button--ready"
          data-profile="<?= h((string)json_encode($profilePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
        >
          Nhận gợi ý
        </button>
      </div>

      <div class="recommend-entry__visual recommend-entry__visual--ready" aria-hidden="true">
        <div class="recommend-preview-card">
          <span class="recommend-preview-card__eyebrow">Routine match</span>
          <h3>Ưu tiên đúng loại da và mục tiêu chăm sóc</h3>
          <ul class="recommend-preview-list">
            <li>Khớp hồ sơ khảo sát đã lưu</li>
            <li>Ưu tiên mức giá phù hợp</li>
            <li>Giảm sản phẩm chứa thành phần cần tránh</li>
          </ul>
        </div>
      </div>
    </section>

    <section class="recommend-results-shell" id="recommendResultShell" hidden>
      <div class="recommend-results-shell__head">
        <div>
          <span class="recommend-entry__eyebrow">Kết quả gợi ý</span>
          <h2 class="recommend-results-shell__title">Sản phẩm dành cho hồ sơ da của bạn</h2>
        </div>
        <div class="recommend-results-shell__meta" id="recommendMetaBadges" hidden></div>
      </div>

      <div class="recommend-advice-panel" id="recommendAdvicePanel" hidden>
        <p class="recommend-advice-panel__label">Tóm tắt từ AI</p>
        <p class="recommend-advice-panel__text" id="recommendAdviceText"></p>
      </div>

      <div class="recommend-loading" id="recommendLoading" hidden>
        <span class="recommend-loading__dot"></span>
        <span class="recommend-loading__dot"></span>
        <span class="recommend-loading__dot"></span>
      </div>

      <div class="recommend-results-grid" id="productsContainer"></div>
    </section>

    <div class="recommend-empty-state" id="recommendEmpty" hidden>
      Chưa tìm thấy sản phẩm phù hợp với hồ sơ của bạn. Hãy cập nhật khảo sát hoặc thử lại sau.
    </div>
  <?php endif; ?>
</div>

<?php if (!$needsSurvey): ?>
<script>
(() => {
  const trigger = document.getElementById('recommendTrigger');
  if (!trigger) {
    return;
  }

  const resultShell = document.getElementById('recommendResultShell');
  const loading = document.getElementById('recommendLoading');
  const emptyState = document.getElementById('recommendEmpty');
  const container = document.getElementById('productsContainer');
  const queryInput = document.getElementById('recommendQueryText');
  const advicePanel = document.getElementById('recommendAdvicePanel');
  const adviceText = document.getElementById('recommendAdviceText');
  const metaBadges = document.getElementById('recommendMetaBadges');
  const profile = JSON.parse(trigger.dataset.profile || '{}');

  const formatVnd = (value) => {
    const number = Number(value || 0);
    if (!Number.isFinite(number) || number <= 0) {
      return 'Liên hệ';
    }
    return number.toLocaleString('vi-VN') + ' VND';
  };

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const firstImage = (product) => {
    if (product && product.image_url) {
      return product.image_url;
    }

    const raw = product && product.link_hinh_anh ? product.link_hinh_anh : '';
    if (!raw) {
      return 'https://via.placeholder.com/450x450?text=No+Image';
    }

    const items = String(raw).split('|').map((item) => item.trim()).filter(Boolean);
    const first = items[0] || '';
    if (!first) {
      return 'https://via.placeholder.com/450x450?text=No+Image';
    }

    if (/^https?:\/\//i.test(first)) {
      return first;
    }

    return '<?= BASE_URL ?>/uploads/products/' + encodeURIComponent(first);
  };

  const buildPayload = () => {
    const formData = new FormData();
    formData.append('gioi_tinh', profile.gioi_tinh || '');
    formData.append('nam_sinh', profile.nam_sinh || '');
    formData.append('skin_type', profile.skin_type || '');
    formData.append('avoid_ingredients', profile.avoid_ingredients || '');
    formData.append('budget', profile.budget || '');
    formData.append('query_text', queryInput ? queryInput.value.trim() : '');

    (Array.isArray(profile.concerns) ? profile.concerns : []).forEach((item) => {
      formData.append('concerns[]', item);
    });

    return formData;
  };

  const renderMeta = (payload) => {
    const badges = [];
    if (payload.search_mode === 'hybrid') {
      badges.push('<span class="recommend-meta-badge recommend-meta-badge--success">Hybrid Search</span>');
    } else {
      badges.push('<span class="recommend-meta-badge">Fallback SQL</span>');
    }

    if (payload.cached) {
      badges.push('<span class="recommend-meta-badge recommend-meta-badge--warm">Cache tương tự</span>');
    }

    if (payload.query) {
      badges.push(`<span class="recommend-meta-badge recommend-meta-badge--query">${escapeHtml(payload.query)}</span>`);
    }

    metaBadges.innerHTML = badges.join('');
    metaBadges.hidden = badges.length === 0;
  };

  trigger.addEventListener('click', async () => {
    trigger.disabled = true;
    trigger.textContent = 'Đang lấy gợi ý...';
    resultShell.hidden = false;
    loading.hidden = false;
    emptyState.hidden = true;
    advicePanel.hidden = true;
    adviceText.textContent = '';
    metaBadges.hidden = true;
    metaBadges.innerHTML = '';
    container.innerHTML = '';

    try {
      const response = await fetch('<?= BASE_URL ?>/index.php?r=xulygoiy', {
        method: 'POST',
        body: buildPayload()
      });

      const rawText = await response.text();
      let payload;
      try {
        payload = JSON.parse(rawText.replace(/^\uFEFF+/, '').trim());
      } catch (parseError) {
        throw new Error('API gợi ý trả về dữ liệu không hợp lệ. Hãy kiểm tra backend recommendation.');
      }

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Không thể lấy gợi ý sản phẩm.');
      }

      const recommendations = Array.isArray(payload.data) ? payload.data : [];
      loading.hidden = true;
      renderMeta(payload);

      if (payload.advice_text) {
        adviceText.textContent = payload.advice_text;
        advicePanel.hidden = false;
      }

      if (recommendations.length === 0) {
        emptyState.hidden = false;
        return;
      }

      container.innerHTML = recommendations.map((product) => `
        <article class="recommend-product-card">
          <div class="recommend-product-card__image-wrap">
            <img
              class="recommend-product-card__image"
              src="${firstImage(product)}"
              alt="${escapeHtml(product.ten_san_pham || '')}"
              referrerpolicy="no-referrer"
              onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
            >
            <span class="recommend-product-card__score">Match ${escapeHtml(product.score ?? 0)}</span>
          </div>
          <div class="recommend-product-card__body">
            <p class="recommend-product-card__brand">${escapeHtml(product.thuong_hieu || 'Không rõ thương hiệu')}</p>
            <h3 class="recommend-product-card__name">${escapeHtml(product.ten_san_pham || '')}</h3>
            <p class="recommend-product-card__price">${formatVnd(product.gia_ban)}</p>
            <div class="recommend-product-card__reasons">
              ${Array.isArray(product.reasons) ? product.reasons.slice(0, 3).map((reason) => `<span class="recommend-product-card__reason">${escapeHtml(reason)}</span>`).join('') : ''}
            </div>
            <div class="recommend-product-card__explanation-wrap">
              <p class="recommend-product-card__explanation">${escapeHtml(product.llm_explanation || '')}</p>
              <span class="recommend-product-card__explanation-source">${product.explanation_source === 'llm' ? 'Gợi ý từ AI' : 'Tư vấn từ dữ liệu MongoDB'}</span>
            </div>
            <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=${product.id}" class="recommend-product-card__link">Xem chi tiết</a>
          </div>
        </article>
      `).join('');
    } catch (error) {
      loading.hidden = true;
      emptyState.hidden = false;
      emptyState.textContent = error.message || 'Có lỗi khi gọi hệ gợi ý.';
    } finally {
      trigger.disabled = false;
      trigger.textContent = 'Nhận gợi ý';
    }
  });
})();
</script>
<?php endif; ?>
