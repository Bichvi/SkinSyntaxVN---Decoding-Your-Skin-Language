# Changed Files in Phase 2

The following files were modified during Phase 2. No database collections were dropped, seeded, or mutated. No credentials were exposed.

| File Path | Description of Changes |
| :--- | :--- |
| [`backend/app/config/db.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/config/db.php) | Enforced explicit `DB_MODE=atlas` policy; eliminated Docker hijack to local `mongodb:27017`; eliminated silent fallback to local container on error; fails explicitly if Atlas connection is unavailable. |
| [`.env`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.env) | Added explicit environment variables: `DB_MODE=atlas` and `DOCKER_USE_ATLAS=1`. |
| [`docker-compose.yml`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/docker-compose.yml) | Passed `DB_MODE: ${DB_MODE:-atlas}` and `DOCKER_USE_ATLAS: "1"` into `php-backend` service container. |
| [`backend/app/models/SanPham.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/SanPham.php) | Enhanced `menuTree()` to construct structured Level-2 parent groups with their leaf children and recursive/direct product counts, while maintaining flat count keys for backward compatibility. |
| [`frontend/views/layouts/header.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/layouts/header.php) | Redesigned header mega menu right panel into a 4-column responsive grid (2 rows of 4 groups); updated navigation shortcuts bar to display 6 key Level-2 skincare groups; updated mobile offcanvas navigation accordion. |
| [`backend/app/controllers/SanPhamController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/SanPhamController.php) | Enhanced `tatca()` to gracefully recognize `ma_danh_muc` and `category_id` query parameters in addition to `cap1` and `cap2`. |
| [`backend/app/controllers/HomeController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/HomeController.php) | Added formal `@deprecated` docblock to `getHighlightedCategories()`. |
