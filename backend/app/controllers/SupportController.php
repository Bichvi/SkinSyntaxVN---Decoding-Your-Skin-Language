<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/SupportKnowledge.php';
require_once __DIR__ . '/../models/SanPham.php';

final class SupportController
{
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function show(?string $slug = null): void
    {
        $knowledge = new SupportKnowledge();
        $support = $knowledge->data();
        $slug = $slug ?? (is_string($_GET['bai'] ?? null) ? $_GET['bai'] : '');
        $article = $slug !== '' ? $knowledge->article($slug) : null;
        $group = is_string($_GET['nhom'] ?? null) ? $_GET['nhom'] : '';
        $supportSearch = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
        $notFound = ($slug !== '' && !$article) || ($group !== '' && !isset($support['groups'][$group]));
        if ($notFound) http_response_code(404);
        $articles = array_values(array_filter($support['articles'], static function ($item) use ($group, $supportSearch) {
            return ($group === '' || $item['group'] === $group) && ($supportSearch === '' || str_contains(SupportKnowledge::fold($item['title'] . ' ' . $item['summary'] . ' ' . implode(' ', $item['triggers'])), SupportKnowledge::fold($supportSearch)));
        }));
        $title = $article['title'] ?? ($support['groups'][$group]['title'] ?? 'Trung tâm trợ giúp');
        $hideSalesToast = true;
        try { $menuCats = (new SanPham($this->db))->menuTree(); }
        catch (Throwable $error) { $menuCats = []; error_log('Support navigation unavailable'); }
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : dirname(__DIR__, 3) . '/frontend/views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/info/support.php';
        require $viewDir . '/layouts/footer.php';
    }
}
