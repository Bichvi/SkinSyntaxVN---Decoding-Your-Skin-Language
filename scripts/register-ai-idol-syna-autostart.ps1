# Opt-in: start the local Syna renderer automatically when this Windows user signs in.
# The renderer is a plain Node process, so it disappears after a reboot or sign-out and
# Docker workers then report "Network is unreachable" for host.docker.internal:7862.
# Runs as the current user only (no admin, no elevation). Remove with -Unregister.
param([switch]$Unregister)
$ErrorActionPreference = 'Stop'
$taskName = 'SkinSyntax Syna Renderer'

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Output "Removed scheduled task '$taskName'."
    exit 0
}

$startScript = Join-Path $PSScriptRoot 'start-ai-idol-syna.ps1'
if (-not (Test-Path -LiteralPath $startScript)) { throw "Missing $startScript" }
$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$startScript`"" `
    -WorkingDirectory (Split-Path -Parent $PSScriptRoot)
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $user
# Give the desktop session a moment to settle before Chrome/Node start.
$trigger.Delay = 'PT30S'
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -Principal $principal -Description 'Starts the SkinSyntax Syna renderer (localhost:7862) at sign-in.' -Force | Out-Null
Write-Output "Registered '$taskName' for $user (runs 30 s after sign-in)."
