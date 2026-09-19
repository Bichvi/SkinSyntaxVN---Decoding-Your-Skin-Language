param([string]$PlaywrightModules = "$env:USERPROFILE\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules")
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$logRoot = Join-Path $projectRoot '.runtime\logs'
New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
try {
    $health = Invoke-RestMethod 'http://127.0.0.1:7862/health' -TimeoutSec 2
    if ($health.renderer -eq 'syna-native-v4') { Write-Output 'Syna renderer already running.'; exit 0 }
} catch { }
if (-not (Test-Path -LiteralPath (Join-Path $PlaywrightModules 'playwright'))) { throw 'Playwright not found. Pass -PlaywrightModules with the existing node_modules directory.' }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot '.runtime\bin\ffmpeg.exe'))) { throw 'Existing FFmpeg runtime not found.' }
$env:NODE_PATH = $PlaywrightModules
$entry = Join-Path $PSScriptRoot 'ai-idol-mascot\syna-render-server.cjs'
$process = Start-Process -FilePath (Get-Command node.exe).Source -ArgumentList @('"' + $entry + '"') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logRoot 'syna-renderer.log') -RedirectStandardError (Join-Path $logRoot 'syna-renderer-error.log')
for ($attempt = 0; $attempt -lt 15; $attempt++) {
    Start-Sleep -Milliseconds 500
    if ($process.HasExited) { throw 'Syna renderer stopped; inspect .runtime/logs/syna-renderer-error.log.' }
    try {
        $health = Invoke-RestMethod 'http://127.0.0.1:7862/health' -TimeoutSec 1
        if ($health.renderer -eq 'syna-native-v4') { Write-Output "Syna ready on localhost:7862 (PID $($process.Id))."; exit 0 }
    } catch { }
}
throw 'Syna renderer did not become ready.'
