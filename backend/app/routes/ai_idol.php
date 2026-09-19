<?php
// Explicit AI Idol routes only. All handlers keep AdminAiIdolController's auth guard.
$idolRoutes = [
    'admin_ai_idol' => ['GET', 'index'],
    'admin_ai_idol_campaigns' => ['GET', 'listCampaigns'],
    'admin_ai_idol_campaign' => ['GET', 'getCampaign'],
    'admin_ai_idol_stream_campaign' => ['GET', 'streamCampaignVideo'],
    'admin_ai_idol_create_campaign' => ['POST', 'createCampaign'],
    'admin_ai_idol_upload_asset' => ['POST', 'uploadAsset'],
    'admin_ai_idol_retry_campaign' => ['POST', 'retryCampaign'],
    'admin_ai_idol_approve_script' => ['POST', 'approveScript'],
    'admin_ai_idol_agent_runs' => ['GET', 'listAgentRuns'],
    'admin_ai_idol_agent_create' => ['POST', 'createAgentRun'],
    'admin_ai_idol_agent_run' => ['GET', 'getAgentRun'],
    'admin_ai_idol_agent_confirm' => ['POST', 'confirmAgentRun'],
    'admin_ai_idol_agent_retry' => ['POST', 'retryAgentRun'],
    'admin_ai_idol_agent_cancel' => ['POST', 'cancelAgentRun'],
];
if (!isset($idolRoutes[$r])) {
    return false;
}
[$idolMethod, $idolAction] = $idolRoutes[$r];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $idolMethod) {
    http_response_code(405);
    header('Allow: ' . $idolMethod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.']);
    return true;
}
require_once __DIR__ . '/../controllers/AdminAiIdolController.php';
(new AdminAiIdolController($pdo))->{$idolAction}();
return true;
