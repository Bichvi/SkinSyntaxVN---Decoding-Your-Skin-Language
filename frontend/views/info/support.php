<?php $helpUrl = BASE_URL . '/index.php?r=tro_giup'; ?>
<link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/support-center.css?v=1">
<div class="ss-help container">
  <nav class="ss-help__crumb" aria-label="Đường dẫn"><a href="<?= h(BASE_URL) ?>/index.php">Trang chủ</a><span>/</span><a href="<?= h($helpUrl) ?>">Trợ giúp</a><?php if ($article): ?><span>/</span><span><?= h($article['title']) ?></span><?php endif; ?></nav>
  <header class="ss-help__hero">
    <div><span class="ss-help__eyebrow">SkinSyntax · luôn có hướng dẫn cho bạn</span><h1><?= h($article ? $article['title'] : 'Bạn cần mình giúp gì?') ?></h1><p><?= h($article['summary'] ?? 'Tìm câu trả lời rõ ràng, từ lần đầu đăng ký đến lúc nhận đúng sản phẩm bạn chọn.') ?></p></div>
    <?php if (!$article): ?><form class="ss-help__search" method="get" action="<?= h(BASE_URL) ?>/index.php"><input type="hidden" name="r" value="tro_giup"><label for="help-search">Tìm trong hướng dẫn</label><div><input id="help-search" name="q" maxlength="160" value="<?= h($supportSearch) ?>" placeholder="Ví dụ: OTP, đổi trả, hóa đơn…"><button type="submit" aria-label="Tìm hướng dẫn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button></div></form><?php endif; ?>
  </header>
  <div class="ss-help__layout">
    <aside class="ss-help__nav"><h2>Chủ đề hỗ trợ</h2><a href="<?= h($helpUrl) ?>" <?= !$group && !$article ? 'aria-current="page"' : '' ?>>Tất cả hướng dẫn</a><?php foreach ($support['groups'] as $key => $topic): ?><a href="<?= h($helpUrl . '&nhom=' . $key) ?>" <?= ($article['group'] ?? $group) === $key ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= h($topic['icon']) ?>" aria-hidden="true"></i><?= h($topic['title']) ?></a><?php endforeach; ?><div class="ss-help__contact"><strong>Cần trao đổi thêm?</strong><a href="tel:<?= h($support['contact']['phone']) ?>"><?= h($support['contact']['phone']) ?></a><a href="mailto:<?= h($support['contact']['email']) ?>"><?= h($support['contact']['email']) ?></a><small>Không chia sẻ OTP hoặc mật khẩu.</small></div></aside>
    <div class="ss-help__main">
    <?php if ($notFound): ?><section class="ss-help__article"><h2>Chưa tìm thấy hướng dẫn này</h2><p>Đường dẫn có thể đã thay đổi. Bạn chọn chủ đề bên cạnh hoặc quay lại trung tâm trợ giúp.</p><a href="<?= h($helpUrl) ?>">Xem tất cả hướng dẫn</a></section>
    <?php elseif ($article): ?>
      <article class="ss-help__article"><div class="ss-help__article-meta">Cập nhật <?= h($support['version']) ?> · <?= h($support['groups'][$article['group']]['title']) ?></div>
      <?php if ($article['group'] === 'legal'): ?><div class="ss-help__notice"><strong>Thông tin pháp lý đang được hoàn thiện</strong><p>Đơn vị vận hành cần bổ sung tên pháp lý, mã số thuế, địa chỉ và xác nhận các thủ tục áp dụng trước khi công bố bản chính thức. Trang này chưa phải chứng nhận website đã hoàn tất nghĩa vụ pháp lý.</p></div><?php endif; ?>
      <?php foreach ($article['sections'] as $section): ?><section><h2><?= h($section['title']) ?></h2><?php foreach ($section['items'] as $item): ?><p><?= h($item) ?></p><?php endforeach; ?></section><?php endforeach; ?>
      <?php if (isset($article['action'])): ?><a class="ss-help__button" href="<?= h(BASE_URL . '/' . $article['action']['url']) ?>"><?= h($article['action']['label']) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
      <?php if ($article['group'] === 'legal'): ?><section class="ss-help__references"><h2>Văn bản tham khảo</h2><a href="https://vanban.chinhphu.vn/?docid=216503&amp;pageid=27160" target="_blank" rel="noopener noreferrer">Luật Thương mại điện tử 122/2025/QH15</a><a href="https://vanban.chinhphu.vn/?classid=1&amp;docid=214590&amp;pageid=27160&amp;typegroup=" target="_blank" rel="noopener noreferrer">Luật Bảo vệ dữ liệu cá nhân 91/2025/QH15</a></section><?php endif; ?>
      </article>
      <section class="ss-help__related"><h2>Cùng chủ đề</h2><?php foreach ($support['articles'] as $related): if ($related['group'] !== $article['group'] || $related['slug'] === $article['slug']) continue; ?><a href="<?= h($helpUrl . '&bai=' . $related['slug']) ?>"><?= h($related['title']) ?> <span aria-hidden="true">→</span></a><?php endforeach; ?></section>
    <?php else: ?>
      <?php if (!$supportSearch && !$group): ?><section class="ss-help__quick"><h2>Bạn có thể đang tìm</h2><div><?php foreach (['otp'=>'Chưa nhận được OTP', 'van-chuyen'=>'Phí & thời gian giao hàng', 'doi-tra'=>'Đổi trả sản phẩm'] as $key=>$label): ?><a href="<?= h($helpUrl . '&bai=' . $key) ?>"><?= h($label) ?> <span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
      <?php if (!$articles): ?><p class="ss-help__empty">Chưa tìm thấy bài phù hợp. Thử từ ngắn hơn như “OTP”, “thanh toán”, hoặc liên hệ bộ phận hỗ trợ.</p><?php endif; ?>
      <?php foreach ($support['groups'] as $key=>$topic): $groupArticles = array_filter($articles, static fn($item) => $item['group'] === $key); if (!$groupArticles) continue; ?>
      <section class="ss-help__topic"><div class="ss-help__topic-heading"><span class="ss-help__icon"><i class="fa-solid <?= h($topic['icon']) ?>" aria-hidden="true"></i></span><div><h2><?= h($topic['title']) ?></h2><p><?= h($topic['description']) ?></p></div></div><div class="ss-help__cards"><?php foreach ($groupArticles as $item): ?><a class="ss-help__card" href="<?= h($helpUrl . '&bai=' . $item['slug']) ?>"><h3><?= h($item['title']) ?></h3><p><?= h($item['summary']) ?></p><span>Xem hướng dẫn <span aria-hidden="true">→</span></span></a><?php endforeach; ?></div>
      <?php if ($group): ?><div class="ss-help__faq"><h2>Câu hỏi thường gặp</h2><?php foreach ($groupArticles as $item): foreach ($item['questions'] as $question): ?><details><summary><?= h($question) ?></summary><p><?= h($item['answer']) ?></p><a href="<?= h($helpUrl . '&bai=' . $item['slug']) ?>">Đọc hướng dẫn đầy đủ</a></details><?php endforeach; endforeach; ?></div><?php endif; ?></section>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>
</div>
