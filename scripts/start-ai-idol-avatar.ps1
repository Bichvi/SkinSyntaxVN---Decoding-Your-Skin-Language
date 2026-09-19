param(
    [switch]$Foreground
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pythonExe = Join-Path $projectRoot '.runtime\python38\python.exe'
$serviceRoot = Join-Path $projectRoot 'ai-idol-avatar-service'
$sadTalkerRoot = Join-Path $projectRoot '.runtime\SadTalker'
$wav2LipRoot = Join-Path $projectRoot '.runtime\Wav2Lip'
$logRoot = Join-Path $projectRoot '.runtime\logs'
$pidPath = Join-Path $projectRoot '.runtime\ai-idol-avatar.pid'
$binRoot = Join-Path $projectRoot '.runtime\bin'
$modelRoot = Join-Path $projectRoot '.runtime\models\torch'

if (-not (Test-Path -LiteralPath $pythonExe)) {
    throw "AI Idol Python is missing at $pythonExe"
}
if (-not (Test-Path -LiteralPath (Join-Path $sadTalkerRoot 'inference.py'))) {
    throw "SadTalker runtime is missing at $sadTalkerRoot"
}
if (-not (Test-Path -LiteralPath (Join-Path $wav2LipRoot 'inference.py'))) {
    throw "Wav2Lip runtime is missing at $wav2LipRoot"
}
if (-not (Test-Path -LiteralPath (Join-Path $wav2LipRoot 'checkpoints\wav2lip_gan.pth'))) {
    throw "Wav2Lip checkpoint is missing at $wav2LipRoot\checkpoints\wav2lip_gan.pth"
}

New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
New-Item -ItemType Directory -Force -Path $binRoot | Out-Null
New-Item -ItemType Directory -Force -Path $modelRoot | Out-Null
$bundledFfmpeg = (& $pythonExe -c 'import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())').Trim()
if (-not (Test-Path -LiteralPath $bundledFfmpeg)) {
    throw "Bundled FFmpeg is missing at $bundledFfmpeg"
}
$ffmpegExe = Join-Path $binRoot 'ffmpeg.exe'
if (-not (Test-Path -LiteralPath $ffmpegExe) -or
    (Get-Item -LiteralPath $ffmpegExe).Length -ne (Get-Item -LiteralPath $bundledFfmpeg).Length) {
    Copy-Item -LiteralPath $bundledFfmpeg -Destination $ffmpegExe -Force
}
$env:SADTALKER_ROOT = $sadTalkerRoot
$env:WAV2LIP_ROOT = $wav2LipRoot
$env:WAV2LIP_CHECKPOINT = Join-Path $wav2LipRoot 'checkpoints\wav2lip_gan.pth'
$env:AI_IDOL_WAV2LIP_BATCH_SIZE = '2'
$env:AI_IDOL_WAV2LIP_TEMPLATE_SECONDS = '20'
$env:SADTALKER_INFERENCE_TIMEOUT = '14400'
$env:AI_IDOL_FFMPEG_BIN = $ffmpegExe
$env:AI_IDOL_AVATAR_CHUNK_SECONDS = '8'
$env:AI_IDOL_AVATAR_EXPRESSION_SCALE = '0.78'
$env:AI_IDOL_AVATAR_POSE_SCALE = '0.35'
$env:TORCH_HOME = $modelRoot
$env:PYTHONPATH = if ($env:PYTHONPATH) { "$serviceRoot;$env:PYTHONPATH" } else { $serviceRoot }
$env:PYTORCH_CUDA_ALLOC_CONF = 'max_split_size_mb:128'
$env:PYTHONUNBUFFERED = '1'
$env:PATH = "$(Split-Path -Parent $pythonExe);$binRoot;$env:PATH"

$waitressArgs = @('-m', 'waitress', '--listen=0.0.0.0:7861', 'app:app')
if ($Foreground) {
    Push-Location $serviceRoot
    try {
        & $pythonExe @waitressArgs
    }
    finally {
        Pop-Location
    }
    exit $LASTEXITCODE
}

if (Test-Path -LiteralPath $pidPath) {
    $existingPid = (Get-Content -LiteralPath $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1)
    if ($existingPid -and (Get-Process -Id $existingPid -ErrorAction SilentlyContinue)) {
        Write-Output "AI Idol Avatar is already running (PID $existingPid)."
        exit 0
    }
}

$stdoutPath = Join-Path $logRoot 'ai-idol-avatar.out.log'
$stderrPath = Join-Path $logRoot 'ai-idol-avatar.err.log'
$process = Start-Process -FilePath $pythonExe `
    -ArgumentList $waitressArgs `
    -WorkingDirectory $serviceRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdoutPath `
    -RedirectStandardError $stderrPath `
    -PassThru
Set-Content -LiteralPath $pidPath -Value $process.Id
Write-Output "AI Idol Avatar started in background (PID $($process.Id))."
