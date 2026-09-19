# Syna in the AI Idol pipeline

## Scope and decision

Use the existing approved Syna WebGL model, not a rendered demo fed into a human-face lip-sync model. Keep photo/video avatar providers, authentication, chatbot, recommendations and voicechat unchanged.

Flow: choose products + Syna → generate script → employee edits/approves → measured TTS audio → native Syna renderer → H.264/AAC MP4 → existing campaign assembly, preview and scheduled broadcast.

The renderer runs on localhost:7862, using the existing Node/Chrome/FFmpeg installations. Docker's AI Idol worker calls host.docker.internal:7862. This avoids another PyTorch install or rebuilding a large image. Chrome opens only for a render and closes afterwards; one job at a time. This is pre-rendered scheduled video, not interactive generated live speech.

## Acceptance criteria

- Syna is selectable without an avatar upload; photo/video remains selectable.
- Human approval is still required before TTS/rendering.
- Mouth motion uses the actual audio envelope. Captions use measured section timing, not the 15-second pilot timeline. Phrase subdivisions are approximate, not word-level forced alignment.
- Product image, name and price remain visible alongside a separate board of source-provided ingredients. Missing facts are not invented.
- Output is a complete 1280×720, 25 fps MP4 with audio, usable by the existing playlist engine. Initial support: 16:9, up to 180 seconds per product.
- No Wav2Lip/SadTalker request in Syna mode. Failure produces an actionable error and never silently falls back to an unrelated demo.
- Temporary files and browser processes are cleaned after success/failure; output is atomically published only after validation.

## Implementation and verification

1. Add deterministic campaign stage/timeline using the existing model.
2. Add bounded local renderer and start/stop scripts.
3. Add Python renderer client and pipeline branch; validate Syna configuration.
4. Replace the misleading pilot-import bridge with explicit avatar selection.
5. Run approval/legacy/Syna contract tests, JS unit tests, PHP/JS syntax and whitespace checks.
6. Render a real approved audio/snapshot to a separate QA output without changing its existing job or schedule. Probe audio/video durations and inspect frames.
7. Verify admin selection, upload validation and payload in the real browser. Restart only idle AI Idol processes after tests.

## Limits and operations

Keep the computer awake and Docker plus the local Syna renderer running during generation. Start the renderer with `powershell -ExecutionPolicy Bypass -File scripts/start-ai-idol-syna.ps1`; stop with the matching stop script. The launcher does not install packages or change startup settings. No claim of free cloud hosting or instantaneous rendering: CPU/GPU/RAM, electricity and disk space are still used.

GitNexus: repository and worktree are SkinSyntaxVN---Decoding-Your-Skin-Language at the project root. Index 84d4d5e was 8 commits behind HEAD during pre-edit analysis; refresh attempted. Pipeline impact LOW: run_ai_idol_pipeline_async and generate_job_task; campaign creation LOW: api_create_campaign. Frontend handlers have local callers; dynamic form listeners manually inspected. Existing unrelated dirty files are preserved. No commit requested.
