[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://127.0.0.1:8080'
)

$ErrorActionPreference = 'Stop'
$base = $BaseUrl.TrimEnd('/')

$routes = @(
    @{ Name = 'admin_login'; Expected = 'login entrypoint' },
    @{ Name = 'admin_logout'; Expected = 'logout entrypoint' },
    @{ Name = 'admin_dashboard'; Expected = 'protected admin page' },
    @{ Name = 'admin_sp'; Expected = 'protected admin page' },
    @{ Name = 'admin_sp_them'; Expected = 'protected admin page' },
    @{ Name = 'admin_sp_sua'; Expected = 'protected admin page' },
    @{ Name = 'admin_sp_xoa'; Expected = 'protected admin action' },
    @{ Name = 'admin_sp_visibility'; Expected = 'protected admin action' },
    @{ Name = 'admin_sp_stock'; Expected = 'protected admin action' },
    @{ Name = 'admin_inventory_batches'; Expected = 'protected inventory page' },
    @{ Name = 'admin_inventory_auto_seed'; Expected = 'protected inventory action' },
    @{ Name = 'admin_inventory_diverse_seed'; Expected = 'protected inventory action' },
    @{ Name = 'admin_inventory_fixed_expiry_seed'; Expected = 'protected inventory action' },
    @{ Name = 'admin_inventory_batch_save'; Expected = 'protected inventory action' },
    @{ Name = 'admin_inventory_movement'; Expected = 'protected inventory action' },
    @{ Name = 'admin_orders'; Expected = 'protected admin page' },
    @{ Name = 'admin_order_update_status'; Expected = 'protected admin action' },
    @{ Name = 'admin_order_status'; Expected = 'protected admin action' },
    @{ Name = 'admin_order_print'; Expected = 'protected admin page' },
    @{ Name = 'admin_vouchers'; Expected = 'protected admin page' },
    @{ Name = 'admin_voucher_them'; Expected = 'protected admin action' },
    @{ Name = 'admin_voucher_save'; Expected = 'protected admin action' },
    @{ Name = 'admin_voucher_sua'; Expected = 'protected admin page' },
    @{ Name = 'admin_voucher_xoa'; Expected = 'protected admin action' },
    @{ Name = 'admin_voucher_delete'; Expected = 'protected admin action' },
    @{ Name = 'admin_categories'; Expected = 'protected admin page' },
    @{ Name = 'admin_category_them'; Expected = 'protected admin action' },
    @{ Name = 'admin_category_save'; Expected = 'protected admin action' },
    @{ Name = 'admin_category_sua'; Expected = 'protected admin page' },
    @{ Name = 'admin_category_xoa'; Expected = 'protected admin action' },
    @{ Name = 'admin_category_delete'; Expected = 'protected admin action' },
    @{ Name = 'admin_users'; Expected = 'protected admin page' },
    @{ Name = 'admin_user_them'; Expected = 'protected admin action' },
    @{ Name = 'admin_customer_save'; Expected = 'protected admin action' },
    @{ Name = 'admin_user_sua'; Expected = 'protected admin page' },
    @{ Name = 'admin_user_xoa'; Expected = 'protected admin action' },
    @{ Name = 'admin_customer_delete'; Expected = 'protected admin action' },
    @{ Name = 'admin_staff_save'; Expected = 'protected admin action' },
    @{ Name = 'admin_staff_delete'; Expected = 'protected admin action' },
    @{ Name = 'admin_reviews'; Expected = 'protected admin page' },
    @{ Name = 'admin_review_duyet'; Expected = 'protected admin action' },
    @{ Name = 'admin_review_an'; Expected = 'protected admin action' },
    @{ Name = 'admin_review_xoa'; Expected = 'protected admin action' },
    @{ Name = 'admin_review_reply'; Expected = 'protected admin action' },
    @{ Name = 'admin_questions'; Expected = 'protected admin page' },
    @{ Name = 'admin_question_reply'; Expected = 'protected admin action' },
    @{ Name = 'admin_question_hide'; Expected = 'protected admin action' },
    @{ Name = 'admin_notifications_seen'; Expected = 'protected admin action' },
    @{ Name = 'admin_reports'; Expected = 'protected admin page' },
    @{ Name = 'admin_lives'; Expected = 'protected admin page' },
    @{ Name = 'admin_live_create'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_edit'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_status'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_delete'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_update_recording'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_pin_product'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_add_deal'; Expected = 'protected admin action' },
    @{ Name = 'admin_live_delete_deal'; Expected = 'protected admin action' },
    @{ Name = 'admin_sepay_webhook'; Expected = 'webhook endpoint' },
    @{ Name = 'admin_sepay_manual_sync'; Expected = 'protected admin action' },
    @{ Name = 'admin_ai_idol'; Expected = 'protected AI Idol page' },
    @{ Name = 'admin_ai_idol_campaigns'; Expected = 'protected AI Idol page' },
    @{ Name = 'admin_ai_idol_campaign'; Expected = 'protected AI Idol page' },
    @{ Name = 'admin_ai_idol_stream_campaign'; Expected = 'protected AI Idol page' },
    @{ Name = 'admin_ai_idol_create_campaign'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_upload_asset'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_retry_campaign'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_approve_script'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_agent_runs'; Expected = 'protected AI Idol page' },
    @{ Name = 'admin_ai_idol_agent_create'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_agent_run'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_agent_confirm'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_agent_retry'; Expected = 'protected AI Idol action' },
    @{ Name = 'admin_ai_idol_agent_cancel'; Expected = 'protected AI Idol action' }
)

$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $false
$client = [System.Net.Http.HttpClient]::new($handler)
$passed = 0
$failed = 0
$blocked = 0

try {
    foreach ($item in $routes) {
        $url = "$base/index.php?r=$($item.Name)"
        try {
            $response = $client.GetAsync($url).GetAwaiter().GetResult()
            $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            $status = [int]$response.StatusCode
            $fatal = $body -match '(?i)(Fatal error|Call to undefined method|Parse error|Uncaught Error)'
            $authStatus = $status -in @(200, 302, 401, 403, 405)

            if ($authStatus -and -not $fatal) {
                $passed++
                Write-Output "PASS ADM-HTTP-$($item.Name.ToUpper()) [$status] $($item.Expected)"
            } else {
                $failed++
                $evidence = if ($fatal) { 'fatal/undefined-handler text in response' } else { "unexpected status $status" }
                Write-Output "FAIL ADM-HTTP-$($item.Name.ToUpper()) [$status] $evidence"
            }
        } catch {
            $blocked++
            Write-Output "BLOCKED ADM-HTTP-$($item.Name.ToUpper()) $($_.Exception.Message)"
        }
    }
} finally {
    $client.Dispose()
    $handler.Dispose()
}

$total = $passed + $failed + $blocked
Write-Output "Admin runtime smoke: $passed/$total passed, $failed failed, $blocked blocked."
if ($blocked -gt 0 -and $failed -eq 0) {
    exit 2
}
if ($failed -gt 0) {
    exit 1
}
exit 0
