# Syna 3D — isolated 15-second pilot

Approved scope: a separate true-3D cartoon pilot, greeting -> turning to the
ingredient board -> pointing -> returning to the audience. Keep the existing 2D
page and all chatbot/recommendation/voicechat/service code unchanged.

Design: cream toy-like cat, large green eyes, green hoodie/headset, short visible
neck, scalloped fan-shaped Centella leaves. Model geometry is authored in code,
not a rotated bitmap. Continuous skinned arm meshes use shoulder/elbow/wrist bones;
head/neck/torso follow a smooth deterministic phrase timeline. No heavy fur or AI
inference. This first sculpt is a review prototype, not a final production asset.
Keep the labelled sample product image/name/price and separate ingredient board.

Implementation: new frontend/public/ai-idol-mascot-3d/ with pinned local Three.js
0.180.0 (MIT, vendor license retained), native ES modules and no runtime CDN/API.
Reuse processed local Syna audio by extracting intact existing sentence segments,
pad to exactly 15 seconds, derive captions from the source timings. Do not modify
the 2D voice or rig. Link to 2D from the new page; do not alter the old page.

Browser controls: play/pause, seek/replay, rotate inspection view, reset front,
reduced motion, local WebM export, clear WebGL/audio failure states. Hide/pause
safely; no uploads or campaign data. Fixed 1280x720 output, modest meshes/shadows.
Render frame timing is measured on this run, not inferred from the user's VRAM.

Impact: bound repo current checkout SkinSyntaxVN---Decoding-Your-Skin-Language;
index 84d4d5e is eight commits behind. Existing demo index impact UNKNOWN;
targeted searches show only isolated demo/testing consumers. Resolution: no
existing symbol edits needed. New demo and new test/audio preparation scripts
only. No graph completeness claim, commit, deployment or service restart.

Verification: pure timing/pose/skin-weight tests, actual Chrome WebGL rendering
(not mocked), different viewing angles, point/return timeline, pause/seek,
mobile layout, missing resources, real export with audio, no external requests,
check 2D still loads. Inspect front/side/point frames and actual exported video.
Record limitations and performance evidence; preserve all pre-existing changes.
