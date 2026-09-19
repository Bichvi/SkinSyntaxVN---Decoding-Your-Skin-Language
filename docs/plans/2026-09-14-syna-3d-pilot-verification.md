# Syna 3D pilot — verification, 2026-09-14

Scope: new standalone `frontend/public/ai-idol-mascot-3d/`, two scoped helper
scripts and planning/verification documentation. No production rollout, database
write, service restart, migration, commit or push. Existing 2D demo is unchanged.

## Impact and isolation

GitNexus repository/worktree: SkinSyntaxVN---Decoding-Your-Skin-Language,
`C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language`.
Index: `84d4d5ed7a12f7c64e89d6b4ba71cda7eb444419`, eight commits behind HEAD.
Impact calls for the demo, Syna3D, PilotScene, initialize, frame, makeArm and the
new browser script returned **UNKNOWN / not found**, not a low-risk verdict.
Targeted references and module inspection resolved the bounded edit scope:
`index -> app -> scene -> model`, `app/model -> timeline`, `app -> stage`, plus
the new tests only. There are no imported application services. No existing 2D
symbol was edited. Graph coverage is incomplete; no graph-wide all-clear or
pre-commit validation is claimed.

Pre-existing tracked modifications in chatbot_flask.py, HomeController.php and
ai_chat_widget.php were preserved. This feature does not modify chatbot,
recommendation or voicechat. The frontend/E2E skills required actual Chrome
checks; those found the cold shader startup pause and verified its fix (warmup
before enabling speech), as well as the paused-render optimization.

## Executed checks

| Check | Result |
| --- | --- |
| `npm.cmd --prefix frontend/public/ai-idol-mascot-3d test` | PASS, 6 tests |
| `node scripts/ai-idol-mascot/check-3d-browser.cjs` using existing Playwright runtime | PASS, real Chrome + local web on port 8080 |
| `node --check` on five authored runtime JS modules, unit test and two CJS helpers | PASS, 8 files |
| `git diff --check` | PASS; existing CRLF-conversion warnings only |
| Targeted trailing-whitespace check of new authored files | PASS |
| `.runtime/bin/ffmpeg.exe -hide_banner -loglevel error -n -i report/ai-idol-syna-3d/syna-3d-pilot.webm -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -r 30 -c:a aac -b:a 128k -movflags +faststart report/ai-idol-syna-3d/syna-3d-pilot.mp4` | PASS, local review transcode |
| `.runtime/bin/ffmpeg.exe -hide_banner -i report/ai-idol-syna-3d/syna-3d-pilot.mp4 -f null NUL` | PASS, full decode; 1280×720 H.264 + AAC, container duration 15.02s |
| Configured ESLint/Stylelint/formatter | NOT RUN: none configured for this standalone demo |
| PHP/Python service tests, full commerce/auth E2E | NOT RUN: no changes to those layers |

Browser assertions cover real WebGL geometry, visibly different front/side
views, persistent product/price + separate ingredient board, real audio-driven
mouth, pause/seek/replay, wave/turn/point/return, reduced motion, keyboard playback,
390px mobile layout without horizontal overflow, complete 15-second narrated
WebM download, cancel-without-save, missing audio, unavailable WebGL, lost WebGL
context, and the original 2D page still loading its 12 actions. Main-page checks
observed no uncaught JS errors, external requests or non-GET requests.

Visual inspection: front, side, greeting, pointing and return frames; full mobile
page; frame extracted from the actual narrated export. Shortened ears, embedded
eyes, broad Centella leaves, connected skinned arms with closed ends, separate
product panel and board are present. No claim of artist-grade final anatomy or
subjective voice quality from automated tests.

## Measured performance

After preparing all shader variants and with no screenshots/viewport changes
during playback: 202 observed renders in 7 seconds; displayed rolling rate
28.8 fps, maximum observed render interval 45 ms, mean JS draw submission/
canvas-composition time 1.9 ms in the final sample. Render counters: 79,692
triangles and 61 geometries in the sampled speaking/pointing pose.

Renderer: ANGLE Intel UHD Graphics, Direct3D11 (Chrome headless on this Windows
host). Paused sample: **0 additional renders in 750 ms**. These observations are
not GPU-completion timings, VRAM measurements, a 30-fps guarantee or a benchmark
of a different machine. Video export is real-time and remains sensitive to
browser/host load. MP4 handoff is a local H.264/AAC transcode of the recorded
WebM, not an offline rerender; CFR conversion does not prove unique 30-fps frames.

QA artifacts (not source/commit material): `report/ai-idol-syna-3d/`, including
`browser-results.json`, screenshots, `syna-3d-pilot.webm` and MP4 handoff.

## Remaining product decisions

This is the agreed 15-second visual review milestone, **not** complete livestream
integration. User should first approve the 3D shape, eyes and movement. Full
expression/action library, phoneme lip-sync, arbitrary scripts/products and a
production artist-made model remain out of this pilot. No existing feature is
replaced. The next step requires that visual approval, not installing larger AI
models or modifying protected application services.
