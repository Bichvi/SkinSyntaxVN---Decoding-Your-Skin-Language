$ErrorActionPreference = 'Stop'
$entry = Join-Path $PSScriptRoot 'ai-idol-mascot\syna-render-server.cjs'
$health = Invoke-RestMethod 'http://127.0.0.1:7862/health' -TimeoutSec 2
if ($health.busy) { throw 'Syna is rendering. Wait for completion before stopping.' }
Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($entry) } | ForEach-Object { taskkill /PID $_.ProcessId /F }
