<?php
$helpUrl = BASE_URL . '/index.php?r=tro_giup';
$qFold = SupportKnowledge::fold((string)$supportSearch);
$qTokens = $qFold === '' ? [] : array_values(array_filter(explode(' ', $qFold)));
$askLink = static fn(string $text): string => 'quicksend:' . rawurlencode($text);
$isStepTitle = static fn(string $title): bool => (bool)preg_match('/\b(buoc|cach|quy trinh|thuc hien|huong dan)\b/', SupportKnowledge::fold($title));
$searchText = static function (array $item): string {
    $parts = [$item['title'], $item['summary'], implode(' ', $item['triggers'] ?? [])];
    foreach ($item['sections'] ?? [] as $section) {
        $parts[] = $section['title'];
        foreach ($section['items'] as $row) { $parts[] = SupportKnowledge::itemText($row); foreach (SupportKnowledge::itemDetails($row) as $d) $parts[] = $d; }
    }
    return SupportKnowledge::fold(implode(' ', $parts));
};
$matchesQuery = static function (string $haystack) use ($qTokens): bool {
    foreach ($qTokens as $token) if (!str_contains(" " . $haystack, " " . $token)) return false;
    return true;
};
// L3 + L4 của một bài: $openFirst mở sẵn mục đầu.
$renderSections = static function (array $item, bool $openFirst) use ($isStepTitle): void {
    foreach (($item['sections'] ?? []) as $n => $section):
        $tag = $isStepTitle($section['title']) ? 'ol' : 'ul'; ?>
      <details class="ss-acc ss-acc--section" id="bai-<?= h($item['slug']) ?>--muc-<?= (int)$n + 1 ?>"<?= $openFirst && $n === 0 ? ' open' : '' ?>><summary><span class="ss-acc__title"><?= h($section['title']) ?></span></summary>
        <div class="ss-acc__body"><<?= $tag ?> class="ss-help__list"><?php foreach ($section['items'] as $row): $rowDetails = SupportKnowledge::itemDetails($row); ?>
          <li><?php if ($rowDetails): ?><details class="ss-acc ss-acc--detail"><summary><span class="ss-acc__title"><?= h(SupportKnowledge::itemText($row)) ?></span></summary><ul class="ss-help__details"><?php foreach ($rowDetails as $detail): ?><li><?= h($detail) ?></li><?php endforeach; ?></ul></details><?php else: ?><?= h(SupportKnowledge::itemText($row)) ?><?php endif; ?></li>
        <?php endforeach; ?></<?= $tag ?>></div>
      </details>
    <?php endforeach;
};
$chips = ['otp' => 'Chưa nhận được OTP', 'van-chuyen' => 'Phí & thời gian giao hàng', 'doi-tra' => 'Đổi trả sản phẩm', 'dung-voucher' => 'Dùng voucher', 'khao-sat-da' => 'Khảo sát da'];
$knownSlugs = array_column($support['articles'], 'slug');
?>
<link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/css/support-center.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/public/assets/css/support-center.css') ?: '2' ?>">
<div class="ss-help container">
  <nav class="ss-help__crumb" aria-label="Đường dẫn"><a href="<?= h(BASE_URL) ?>/index.php">Trang chủ</a><span>/</span><a href="<?= h($helpUrl) ?>">Trợ giúp</a><?php if ($article): ?><span>/</span><span><?= h($article['title']) ?></span><?php endif; ?></nav>
  <header class="ss-help__hero">
    <div><span class="ss-help__eyebrow">SkinSyntax · luôn có hướng dẫn cho bạn</span><h1><?= h($article ? $article['title'] : 'Bạn cần mình giúp gì?') ?></h1><p><?= h($article['summary'] ?? 'Tìm câu trả lời rõ ràng, từ lần đầu đăng ký đến lúc nhận đúng sản phẩm bạn chọn.') ?></p></div>
    <?php if (!$article): ?>
    <div class="ss-help__search-wrap">
      <form class="ss-help__search" method="get" action="<?= h(BASE_URL) ?>/index.php" role="search" data-help-search><input type="hidden" name="r" value="tro_giup"><label for="help-search">Tìm trong hướng dẫn</label><div><input id="help-search" name="q" type="search" autocomplete="off" maxlength="160" value="<?= h($supportSearch) ?>" placeholder="Ví dụ: OTP, đổi trả, hóa đơn…"><button type="submit" aria-label="Tìm hướng dẫn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button></div></form>
      <a class="ss-help__ask" href="<?= h($askLink($supportSearch)) ?>" data-js-only data-help-ask-search hidden>✨ Hỏi Syna</a>
    </div>
    <?php endif; ?>
  </header>
  <div class="ss-help__layout">
    <aside class="ss-help__nav"><h2>Chủ đề hỗ trợ</h2><a href="<?= h($helpUrl) ?>" <?= !$group && !$article ? 'aria-current="page"' : '' ?>>Tất cả hướng dẫn</a><?php foreach ($support['groups'] as $key => $topic): ?><a href="<?= h($helpUrl . '&nhom=' . $key . '#nhom-' . $key) ?>" data-help-topic="<?= h($key) ?>" <?= ($article['group'] ?? $group) === $key ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= h($topic['icon']) ?>" aria-hidden="true"></i><?= h($topic['title']) ?></a><?php endforeach; ?><div class="ss-help__contact"><strong>Cần trao đổi thêm?</strong><a href="tel:<?= h($support['contact']['phone']) ?>"><?= h($support['contact']['phone']) ?></a><a href="mailto:<?= h($support['contact']['email']) ?>"><?= h($support['contact']['email']) ?></a><small>Không chia sẻ OTP hoặc mật khẩu.</small></div></aside>
    <div class="ss-help__main" id="helpResults">
    <?php if ($notFound): ?><section class="ss-help__article"><h2>Chưa tìm thấy hướng dẫn này</h2><p>Đường dẫn có thể đã thay đổi. Bạn chọn chủ đề bên cạnh hoặc quay lại trung tâm trợ giúp.</p><a href="<?= h($helpUrl) ?>">Xem tất cả hướng dẫn</a></section>
    <?php elseif ($article): ?>
      <article class="ss-help__article"><div class="ss-help__article-meta">Cập nhật <?= h($article['updated'] ?? $support['version']) ?> · <?= h($support['groups'][$article['group']]['title']) ?></div>
      <?php if ($article['group'] === 'legal'): ?><div class="ss-help__notice"><strong>Thông tin pháp lý đang được hoàn thiện</strong><p>Đơn vị vận hành cần bổ sung tên pháp lý, mã số thuế, địa chỉ và xác nhận các thủ tục áp dụng trước khi công bố bản chính thức. Trang này chưa phải chứng nhận website đã hoàn tất nghĩa vụ pháp lý.</p></div><?php endif; ?>
      <div class="ss-help__sections"><?php $renderSections($article, true); ?></div>
      <div class="ss-help__actions"><?php if (isset($article['action'])): ?><a class="ss-help__button" href="<?= h(BASE_URL . '/' . $article['action']['url']) ?>"><?= h($article['action']['label']) ?> <span aria-hidden="true">→</span></a><?php endif; ?><a class="ss-help__button ss-help__button--ghost" data-js-only hidden href="<?= h($askLink($article['questions'][0] ?? ('Hướng dẫn: ' . $article['title']))) ?>">💬 Hỏi Syna về mục này</a></div>
      <?php if ($article['group'] === 'legal'): ?><section class="ss-help__references"><h2>Văn bản tham khảo</h2><a href="https://vanban.chinhphu.vn/?docid=216503&amp;pageid=27160" target="_blank" rel="noopener noreferrer">Luật Thương mại điện tử 122/2025/QH15</a><a href="https://vanban.chinhphu.vn/?classid=1&amp;docid=214590&amp;pageid=27160&amp;typegroup=" target="_blank" rel="noopener noreferrer">Luật Bảo vệ dữ liệu cá nhân 91/2025/QH15</a></section><?php endif; ?>
      </article>
      <section class="ss-help__related"><h2>Cùng chủ đề</h2><?php foreach ($support['articles'] as $related): if ($related['group'] !== $article['group'] || $related['slug'] === $article['slug']) continue; ?><a href="<?= h($helpUrl . '&bai=' . $related['slug']) ?>"><?= h($related['title']) ?> <span aria-hidden="true">→</span></a><?php endforeach; ?></section>
    <?php else: ?>
      <?php if (!$supportSearch && !$group): ?><section class="ss-help__quick"><h2>Bạn có thể đang tìm</h2><div><?php foreach ($chips as $key => $label): if (!in_array($key, $knownSlugs, true)) continue; ?><a href="<?= h($helpUrl . '&bai=' . $key) ?>"><?= h($label) ?> <span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
      <div class="ss-help__toolbar"><div class="ss-help__toolbar-actions" data-js-only hidden><button type="button" data-help-expand>Mở tất cả</button><button type="button" data-help-collapse>Thu gọn</button></div><span class="ss-help__count" data-help-count aria-live="polite"></span></div>
      <?php
        $shown = 0; $firstGroupDone = false;
        foreach ($support['groups'] as $key => $topic):
            $groupItems = array_values(array_filter($support['articles'], static fn($i) => $i['group'] === $key));
            $matchCount = 0; foreach ($groupItems as $i) if ($matchesQuery($searchText($i))) $matchCount++;
            $openGroup = $group !== '' ? $group === $key : ($qTokens ? $matchCount > 0 : !$firstGroupDone);
            $firstGroupDone = true;
            if ($qTokens && $matchCount === 0) continue; // không JS: bỏ nhóm không khớp
      ?>
      <details class="ss-acc ss-acc--group" id="nhom-<?= h($key) ?>" data-group="<?= h($key) ?>"<?= $openGroup ? ' open' : '' ?>>
        <summary><span class="ss-help__icon"><i class="fa-solid <?= h($topic['icon']) ?>" aria-hidden="true"></i></span><span class="ss-acc__head"><span class="ss-acc__title"><?= h($topic['title']) ?></span><span class="ss-acc__desc"><?= h($topic['description']) ?></span></span><span class="ss-acc__badge"><span data-group-count><?= (int)($qTokens ? $matchCount : count($groupItems)) ?></span> bài</span></summary>
        <div class="ss-acc__body">
        <?php if (!$groupItems): ?><p class="ss-help__empty">Nhóm này chưa có bài hướng dẫn.</p><?php endif; ?>
        <?php foreach ($groupItems as $item):
            $hay = $searchText($item); $hit = $matchesQuery($hay); if ($qTokens && !$hit) continue; $shown++; ?>
        <details class="ss-acc ss-acc--article" id="bai-<?= h($item['slug']) ?>" data-search="<?= h($hay) ?>"<?= $qTokens ? ' open' : '' ?>>
          <summary><span class="ss-acc__head"><span class="ss-acc__title"><?= h($item['title']) ?></span><span class="ss-acc__desc"><?= h($item['summary']) ?></span></span></summary>
          <div class="ss-acc__body">
            <p class="ss-help__meta">Cập nhật <?= h($item['updated'] ?? $support['version']) ?></p>
            <?php $renderSections($item, false); ?>
            <div class="ss-help__actions">
              <a class="ss-help__button ss-help__button--ghost" href="<?= h($helpUrl . '&bai=' . $item['slug']) ?>">Mở trang riêng ↗</a>
              <a class="ss-help__button ss-help__button--ghost" data-js-only hidden href="<?= h($askLink($item['questions'][0] ?? ('Hướng dẫn: ' . $item['title']))) ?>">💬 Hỏi Syna về mục này</a>
              <?php if (isset($item['action'])): ?><a class="ss-help__button" href="<?= h(BASE_URL . '/' . $item['action']['url']) ?>"><?= h($item['action']['label']) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
            </div>
          </div>
        </details>
        <?php endforeach; ?>
        </div>
      </details>
      <?php endforeach; ?>
      <?php if ($qTokens && $shown === 0): ?><p class="ss-help__empty" data-help-server-empty>Chưa tìm thấy bài phù hợp với “<?= h($supportSearch) ?>”. Thử từ ngắn hơn như “OTP”, “thanh toán”, hoặc liên hệ bộ phận hỗ trợ.</p><?php endif; ?>
      <div class="ss-help__empty" data-help-empty hidden><p>Chưa tìm thấy bài phù hợp. Thử từ ngắn hơn như “OTP”, “thanh toán”, hoặc hỏi trợ lý Syna.</p><a class="ss-help__button" href="quicksend:" data-js-only data-help-ask-empty hidden>✨ Hỏi Syna: <span data-help-empty-term></span></a></div>
    <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?= h(BASE_URL) ?>/assets/js/support-center.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/public/assets/js/support-center.js') ?: '1' ?>" defer></script>
