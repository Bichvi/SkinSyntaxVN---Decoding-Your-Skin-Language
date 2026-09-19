$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pidPath = Join-Path $projectRoot '.runtime\ai-idol-avatar.pid'

if (-not (Test-Path -LiteralPath $pidPath)) {
    Write-Output 'AI Idol Avatar has no saved PID.'
    exit 0
}

$avatarPid = (Get-Content -LiteralPath $pidPath | Select-Object -First 1)
$process = Get-Process -Id $avatarPid -ErrorAction SilentlyContinue
if ($process) {
    Stop-Process -Id $avatarPid -Force
    $process.WaitForExit(10000) | Out-Null
}
Remove-Item -LiteralPath $pidPath -Force
Write-Output 'AI Idol Avatar stopped.'
