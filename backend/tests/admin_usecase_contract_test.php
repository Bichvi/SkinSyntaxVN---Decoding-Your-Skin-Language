<?php
declare(strict_types=1);

/**
 * Static contract/regression tests for the admin surface.
 *
 * This runner intentionally does not bootstrap MongoDB. It verifies that the
 * public web entrypoint and the backend entrypoint expose the same admin
 * routes, that every route points at a real controller method, and that admin
 * controllers retain an authorization guard. It is safe to run against a
 * developer checkout without mutating application data.
 */

$projectRoot = dirname(__DIR__, 2);
$frontendRouter = $projectRoot . '/frontend/public/index.php';
$backendRouter = $projectRoot . '/backend/public/index.php';
$aiIdolRouter = $projectRoot . '/backend/app/routes/ai_idol.php';

final class AdminContractTestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function check(string $id, string $title, bool $condition, string $evidence = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "PASS {$id} - {$title}\n";
            return;
        }

        $this->failed++;
        echo "FAIL {$id} - {$title}\n";
        if ($evidence !== '') {
            echo "      {$evidence}\n";
        }
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\nAdmin contract tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function source(string $path): string
{
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

function routeCases(string $routerSource): array
{
    preg_match_all("/\\bcase\\s+'([^']+)'\\s*:/", $routerSource, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function routeBlock(string $routerSource, string $route): string
{
    $quoted = preg_quote($route, '/');
    if (!preg_match("/\\bcase\\s+'{$quoted}'\\s*:/", $routerSource, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = (int)$match[0][1];
    $tail = substr($routerSource, $start);
    if ($tail === false) {
        return '';
    }

    $break = preg_match('/\\bbreak\\s*;/', $tail, $breakMatch, PREG_OFFSET_CAPTURE)
        ? ((int)$breakMatch[0][1] + strlen($breakMatch[0][0]))
        : strlen($tail);

    // A switch may group several case labels before one handler. Reading to
    // the first break keeps the mapping valid for every label in that group.
    return substr($tail, 0, $break) ?: '';
}

function routeHandler(string $routerSource, string $route): ?string
{
    $block = routeBlock($routerSource, $route);
    if ($block === '') {
        return null;
    }

    return preg_match('/new\\s+([A-Za-z0-9_]+)\\s*\\([^;]+\)->([A-Za-z0-9_]+)\\s*\(/', $block, $match)
        ? ($match[1] . '::' . $match[2])
        : null;
}

function publicMethods(string $controllerSource): array
{
    preg_match_all('/public\\s+function\\s+([A-Za-z0-9_]+)\\s*\\(/', $controllerSource, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

function methodBody(string $controllerSource, string $method): string
{
    $quoted = preg_quote($method, '/');
    if (!preg_match("/(?:public|private|protected)\\s+function\\s+'?{$quoted}'?\\s*\\([^)]*\\)\\s*(?::[^\\{]+)?\\{(?<body>.*?)(?=\\R\\s*(?:public|private|protected)\\s+function\\s+|\\z)/s", $controllerSource, $match)) {
        return '';
    }

    return (string)($match['body'] ?? '');
}

function listDifference(array $left, array $right): string
{
    $difference = array_values(array_diff($left, $right));
    return $difference === [] ? 'none' : implode(', ', $difference);
}

$runner = new AdminContractTestRunner();
$frontend = source($frontendRouter);
$backend = source($backendRouter);
$aiRouter = source($aiIdolRouter);

$runner->check('ADM-CON-001', 'Frontend router is readable', $frontend !== '', $frontendRouter);
$runner->check('ADM-CON-002', 'Backend router is readable', $backend !== '', $backendRouter);
$runner->check('ADM-CON-003', 'AI Idol route table is readable', $aiRouter !== '', $aiIdolRouter);

/**
 * Routes in the primary switch. The expected controller method is part of the
 * contract so a typo or a removed public method fails before browser testing.
 */
$directRoutes = [
    'admin' => 'QuanTriController::adminDashboard',
    'admin_dashboard' => 'QuanTriController::adminDashboard',
    'admin_login' => 'AuthController::dangnhap',
    'admin_logout' => 'AuthController::logout',
    'admin_sp' => 'QuanTriController::adminProducts',
    'admin_sp_them' => 'QuanTriController::adminProductCreate',
    'admin_sp_sua' => 'QuanTriController::adminProductEdit',
    'admin_sp_xoa' => 'QuanTriController::adminProductDelete',
    'admin_sp_visibility' => 'QuanTriController::adminProductVisibility',
    'admin_sp_stock' => 'QuanTriController::adminProductStock',
    'admin_inventory_batches' => 'AdminInventoryController::index',
    'admin_inventory_auto_seed' => 'AdminInventoryController::autoSeed',
    'admin_inventory_diverse_seed' => 'AdminInventoryController::seedDiverse',
    'admin_inventory_fixed_expiry_seed' => 'AdminInventoryController::seedFixedExpiry',
    'admin_inventory_batch_save' => 'AdminInventoryController::saveBatch',
    'admin_inventory_movement' => 'AdminInventoryController::addMovement',
    'admin_orders' => 'QuanTriController::adminOrders',
    'admin_order_update_status' => 'QuanTriController::adminOrderStatus',
    'admin_order_status' => 'QuanTriController::adminOrderStatus',
    'admin_order_print' => 'QuanTriController::adminOrderPrint',
    'admin_vouchers' => 'QuanTriController::adminVouchers',
    'admin_live_pin_product' => 'LiveController::adminLivePinProduct',
    'admin_live_add_deal' => 'LiveController::adminLiveAddDeal',
    'admin_live_delete_deal' => 'LiveController::adminLiveDeleteDeal',
    'admin_lives' => 'QuanTriController::adminLives',
    'admin_live_create' => 'QuanTriController::adminLiveCreate',
    'admin_live_edit' => 'QuanTriController::adminLiveEdit',
    'admin_live_status' => 'QuanTriController::adminLiveStatus',
    'admin_live_delete' => 'QuanTriController::adminLiveDelete',
    'admin_live_update_recording' => 'QuanTriController::adminLiveUpdateRecording',
    'admin_voucher_them' => 'QuanTriController::adminVoucherSave',
    'admin_voucher_save' => 'QuanTriController::adminVoucherSave',
    'admin_voucher_sua' => 'QuanTriController::adminVouchers',
    'admin_voucher_xoa' => 'QuanTriController::adminVoucherDelete',
    'admin_voucher_delete' => 'QuanTriController::adminVoucherDelete',
    'admin_categories' => 'QuanTriController::adminCategories',
    'admin_category_them' => 'QuanTriController::adminCategorySave',
    'admin_category_save' => 'QuanTriController::adminCategorySave',
    'admin_category_sua' => 'QuanTriController::adminCategories',
    'admin_category_xoa' => 'QuanTriController::adminCategoryDelete',
    'admin_category_delete' => 'QuanTriController::adminCategoryDelete',
    'admin_users' => 'QuanTriController::adminUsers',
    'admin_user_them' => 'QuanTriController::adminCustomerSave',
    'admin_customer_save' => 'QuanTriController::adminCustomerSave',
    'admin_user_sua' => 'QuanTriController::adminUsers',
    'admin_user_xoa' => 'QuanTriController::adminCustomerDelete',
    'admin_customer_delete' => 'QuanTriController::adminCustomerDelete',
    'admin_staff_save' => 'QuanTriController::adminStaffSave',
    'admin_staff_delete' => 'QuanTriController::adminStaffDelete',
    'admin_reviews' => 'QuanTriController::adminReviews',
    'admin_review_duyet' => 'QuanTriController::adminReviewApprove',
    'admin_review_an' => 'QuanTriController::adminReviewHide',
    'admin_review_xoa' => 'QuanTriController::adminReviewDelete',
    'admin_review_reply' => 'QuanTriController::adminReviewReply',
    'admin_reports' => 'QuanTriController::adminReports',
    'admin_questions' => 'QuanTriController::adminQuestions',
    'admin_question_reply' => 'QuanTriController::adminQuestionReply',
    'admin_question_hide' => 'QuanTriController::adminQuestionHide',
    'admin_notifications_seen' => 'QuanTriController::markNotificationsSeen',
    'admin_sepay_webhook' => 'HomeController::paymentWebhook',
    'admin_sepay_manual_sync' => 'HomeController::adminSepayManualSync',
];

$controllerFiles = [
    'QuanTriController' => $projectRoot . '/backend/app/controllers/QuanTriController.php',
    'AuthController' => $projectRoot . '/backend/app/controllers/AuthController.php',
    'HomeController' => $projectRoot . '/backend/app/controllers/HomeController.php',
    'AdminController' => $projectRoot . '/backend/app/controllers/AdminController.php',
    'AdminInventoryController' => $projectRoot . '/backend/app/controllers/AdminInventoryController.php',
    'AdminAiIdolController' => $projectRoot . '/backend/app/controllers/AdminAiIdolController.php',
    'LiveController' => $projectRoot . '/backend/app/controllers/LiveController.php',
];
$controllerSources = [];
$controllerMethods = [];
foreach ($controllerFiles as $class => $path) {
    $controllerSources[$class] = source($path);
    $controllerMethods[$class] = publicMethods($controllerSources[$class]);
    $runner->check('ADM-CON-010-' . $class, "{$class} source is readable", $controllerSources[$class] !== '', $path);
}

$frontendCases = routeCases($frontend);
$backendCases = routeCases($backend);
$adminCases = array_keys($directRoutes);
$frontendAdminCases = array_values(array_filter(
    $frontendCases,
    static fn(string $route): bool => $route === 'admin' || str_starts_with($route, 'admin_')
));
$backendAdminCases = array_values(array_filter(
    $backendCases,
    static fn(string $route): bool => $route === 'admin' || str_starts_with($route, 'admin_')
));
$missingFrontend = array_values(array_diff($adminCases, $frontendAdminCases));
$missingBackend = array_values(array_diff($adminCases, $backendAdminCases));
$runner->check(
    'ADM-CON-011',
    'Primary frontend exposes every direct admin route',
    $missingFrontend === [],
    'Missing: ' . ($missingFrontend === [] ? 'none' : implode(', ', $missingFrontend))
);
$runner->check(
    'ADM-CON-012',
    'Backend router exposes every direct admin route',
    $missingBackend === [],
    'Missing: ' . ($missingBackend === [] ? 'none' : implode(', ', $missingBackend))
);
$runner->check(
    'ADM-CON-013',
    'Frontend and backend direct admin route sets are identical',
    array_values(array_diff($frontendAdminCases, $backendAdminCases)) === []
        && array_values(array_diff($backendAdminCases, $frontendAdminCases)) === [],
    'Frontend-only: ' . listDifference($frontendAdminCases, $backendAdminCases)
        . '; backend-only: ' . listDifference($backendAdminCases, $frontendAdminCases)
);

foreach ($directRoutes as $route => $expectedHandler) {
    $actualFrontend = routeHandler($frontend, $route);
    $actualBackend = routeHandler($backend, $route);
    $runner->check(
        'ADM-ROUTE-F-' . strtoupper(str_replace('_', '-', $route)),
        "Frontend route {$route} maps to {$expectedHandler}",
        $actualFrontend === $expectedHandler,
        'Actual: ' . ($actualFrontend ?? 'missing')
    );
    $runner->check(
        'ADM-ROUTE-B-' . strtoupper(str_replace('_', '-', $route)),
        "Backend route {$route} maps to {$expectedHandler}",
        $actualBackend === $expectedHandler,
        'Actual: ' . ($actualBackend ?? 'missing')
    );

    [$class, $method] = explode('::', $expectedHandler, 2);
    $runner->check(
        'ADM-HANDLER-' . strtoupper(str_replace('_', '-', $route)),
        "Handler {$expectedHandler} exists",
        in_array($method, $controllerMethods[$class] ?? [], true),
        $controllerFiles[$class] ?? 'Controller file not configured'
    );
}

/** AI Idol routes are dispatched before the primary switch. */
$aiRoutes = [];
if (preg_match_all("/'([^']+)'\\s*=>\\s*\\['(GET|POST)',\\s*'([A-Za-z0-9_]+)'\\]/", $aiRouter, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $aiRoutes[$match[1]] = ['method' => $match[2], 'handler' => 'AdminAiIdolController::' . $match[3]];
    }
}

$runner->check('ADM-AI-001', 'AI Idol route table contains routes', $aiRoutes !== '', 'Expected route table in ' . $aiIdolRouter);
foreach ($aiRoutes as $route => $contract) {
    $frontendPreDispatch = str_contains($frontend, "'{$route}'");
    $backendPreDispatch = str_contains($backend, "'{$route}'");
    $runner->check('ADM-AI-F-' . strtoupper(str_replace('_', '-', $route)), "Frontend exposes AI route {$route}", $frontendPreDispatch);
    $runner->check('ADM-AI-B-' . strtoupper(str_replace('_', '-', $route)), "Backend exposes AI route {$route}", $backendPreDispatch);
    [$class, $method] = explode('::', $contract['handler'], 2);
    $runner->check(
        'ADM-AI-H-' . strtoupper(str_replace('_', '-', $route)),
        "AI route {$route} handler exists",
        in_array($method, $controllerMethods[$class] ?? [], true),
        "Expected HTTP method {$contract['method']}"
    );
}

/** Authorization guard regression checks. */
$quanTriAdminMethods = [
    'adminDashboard', 'adminProducts', 'adminProductCreate', 'adminProductEdit', 'adminProductDelete',
    'adminProductVisibility', 'adminProductStock', 'adminCategories', 'adminVouchers', 'adminVoucherSave',
    'adminVoucherDelete', 'adminCategorySave', 'adminCategoryDelete', 'adminUsers', 'adminCustomerSave',
    'adminCustomerDelete', 'adminStaffSave', 'adminStaffDelete', 'adminStaffHardDelete', 'adminOrders',
    'adminOrderStatus', 'adminOrderPrint', 'adminReviews', 'adminReviewApprove', 'adminReviewHide',
    'adminReviewDelete', 'adminReviewReply', 'adminReports', 'adminQuestions', 'adminQuestionReply', 'adminQuestionHide',
    'markNotificationsSeen', 'adminLives', 'adminLiveCreate', 'adminLiveEdit', 'adminLiveStatus',
    'adminLiveDelete', 'adminLiveUpdateRecording',
];
foreach ($quanTriAdminMethods as $method) {
    $body = methodBody($controllerSources['QuanTriController'], $method);
    $runner->check(
        'ADM-GUARD-QT-' . strtoupper($method),
        "QuanTriController::{$method} keeps requireRole guard",
        $body !== '' && str_contains($body, 'requireRole('),
        'Guard not found in method body'
    );
}

foreach (['index', 'create', 'edit', 'delete'] as $method) {
    $body = methodBody($controllerSources['AdminController'], $method);
    $runner->check(
        'ADM-GUARD-LEGACY-' . strtoupper($method),
        "AdminController::{$method} keeps checkAdmin guard",
        $body !== '' && str_contains($body, 'checkAdmin()'),
        'Guard not found in method body'
    );
}

foreach (['index', 'autoSeed', 'seedDiverse', 'seedFixedExpiry', 'saveBatch', 'addMovement'] as $method) {
    $body = methodBody($controllerSources['AdminInventoryController'], $method);
    $runner->check(
        'ADM-GUARD-INV-' . strtoupper($method),
        "AdminInventoryController::{$method} keeps requireAdmin guard",
        $body !== '' && str_contains($body, 'requireAdmin()'),
        'Guard not found in method body'
    );
}

foreach (array_diff($controllerMethods['AdminAiIdolController'], ['__construct']) as $method) {
    $body = methodBody($controllerSources['AdminAiIdolController'], $method);
    $guardDelegated = in_array($method, ['confirmAgentRun', 'retryAgentRun', 'cancelAgentRun'], true)
        && str_contains($body, 'proxyAgentMutation(')
        && str_contains(methodBody($controllerSources['AdminAiIdolController'], 'proxyAgentMutation'), 'checkAdmin()');
    $runner->check(
        'ADM-GUARD-AI-' . strtoupper($method),
        "AdminAiIdolController::{$method} keeps checkAdmin guard",
        ($body !== '' && str_contains($body, 'checkAdmin()')) || $guardDelegated,
        $guardDelegated ? 'Guard is delegated to proxyAgentMutation()' : 'Guard not found in method body'
    );
}

$runner->check(
    'ADM-GUARD-SEPAY-MANUAL',
    'HomeController::adminSepayManualSync keeps login/admin/method guards',
    str_contains(methodBody($controllerSources['HomeController'], 'adminSepayManualSync'), 'is_logged_in()')
        && str_contains(methodBody($controllerSources['HomeController'], 'adminSepayManualSync'), "current_role() !== 'admin'")
        && str_contains(methodBody($controllerSources['HomeController'], 'adminSepayManualSync'), "REQUEST_METHOD")
        && str_contains(methodBody($controllerSources['HomeController'], 'adminSepayManualSync'), "'POST'")
);

$runner->check(
    'ADM-GUARD-SEPAY-WEBHOOK',
    'HomeController::paymentWebhook keeps method and webhook-secret guards',
    str_contains(methodBody($controllerSources['HomeController'], 'paymentWebhook'), "REQUEST_METHOD")
        && str_contains(methodBody($controllerSources['HomeController'], 'paymentWebhook'), "'POST'")
        && str_contains(methodBody($controllerSources['HomeController'], 'paymentWebhook'), 'isValidWebhookRequest()')
);

$runner->check(
    'ADM-GUARD-AI-PROXY',
    'AdminAiIdolController::proxyAgentMutation keeps checkAdmin guard',
    str_contains(methodBody($controllerSources['AdminAiIdolController'], 'proxyAgentMutation'), 'checkAdmin()'),
    'Guard not found in delegated mutation helper'
);

exit($runner->summary());
