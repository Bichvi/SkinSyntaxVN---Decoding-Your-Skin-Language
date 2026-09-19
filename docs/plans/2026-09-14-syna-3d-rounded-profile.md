# Syna 3D — rounded profile correction

User requests a properly proportioned cat, not a flattened side profile.
Scope: the isolated 3D demo only; preserve voice/timeline, 2D, product/price,
ingredient board, and protected chatbot/recommend/voicechat code.

Diagnosis: skull depth radius 0.58 vs width 0.80; compressed torso depth 0.84;
eyes are multiple forward-facing flattened spheres and ears are thin extrusions.
Only +/-65-degree inspection was available. These choices favor the front view.

Alternatives considered: globally scale Z (also distorts eyes/accessories), import
a new artist model (no approved model available), or correct the actual geometry
and facial surface. Choose the third: round skull with integrated cheek/muzzle
projection, conforming eye/cheek surfaces, thicker ears, round torso and properly
positioned hoodie details. Keep the light local renderer, no new dependencies.

Acceptance: inspect front, 35/45, both true 90-degree profiles, rear 180, speech
and blink. Skull depth/width >=0.90; torso depth/width >=0.90. Facial markings
must follow the same surface as the skull rather than hover as flat discs.
Allow full -180..180 rotation with direct profile buttons. These engineering
checks are not a claim of final artist-quality anatomy; visually review outputs.

GitNexus binding: current SkinSyntaxVN---Decoding-Your-Skin-Language workspace,
index 84d4d5ed7a12f7c64e89d6b4ba71cda7eb444419, 8 commits behind. Impact for
Syna3D and changed methods, chestLabel and setView returns UNKNOWN/not found.
Manual reference confirmation: all model methods -> Syna3D -> PilotScene ->
isolated app; setView only view controls/export reset. No other service consumers.
No graph-wide all-clear, index rebuild, commit or deployment claimed.

Files: new anatomy geometry helper, model.js, inspection controls/cache revisions,
unit tests and scoped browser tests; documentation. Verify exact geometric depth
and conforming face coordinates, full existing playback/export flow, desktop and
mobile, idle optimization, real render rate, missing WebGL/audio handling.

## Implementation and verification

Implemented local `anatomy.js`: a solid high-resolution skull with shared
cheek/muzzle surface function; curved eye/mouth markings re-fit to this surface
during blinking/gaze. Torso Z scale 0.97 instead of 0.84, sleeve/hoodie preserved;
chest text and pocket follow body curvature. Ear base tapers to swept tip, nose is
rounded in profile, tail sweeps backward instead of remaining in the XY plane.
UI now provides -180..180 rotation, direct +/-90 and rear buttons, and current
angle readout. Audio, timing, services and 2D assets are unchanged.

Changed files: anatomy.js (new), model.js, app.js, scene.js (cache import),
index.html, style.css, tests/pilot.test.js, README.md; scoped
scripts/ai-idol-mascot/check-3d-browser.cjs; this plan/report. App/scene imports and
HTML assets use cache revision 3; user-facing URL `?rev=syna-3d-v2`.

- PASS: `npm.cmd --prefix frontend/public/ai-idol-mascot-3d test`, 9 tests.
  Three new geometry tests cover actual solid depth, shared curved face surface
  under blink/gaze, shared eyelid pivot and curved torso detail placement.
- PASS: `node scripts/ai-idol-mascot/check-3d-browser.cjs` with existing Playwright
  runtime via NODE_PATH and installed Chrome, local web port 8080. Real front,
  side, both 90-degree and rear views, slider Home/End, playback/pause/seek,
  blink states, gesture flow, mobile, WebGL/audio failures, recorded WebM with
  narration, export cancellation, and original 2D smoke check. No main-page
  uncaught JS errors, external network requests or non-GET requests observed.
- PASS: `node --check` on six runtime modules, unit test and browser helper.
- PASS: `git diff --check` (only existing line-ending warnings) and targeted
  trailing-whitespace check of authored files, including untracked demo files.
- NOT RUN: ESLint/Stylelint formatter (none configured for this demo), unrelated
  PHP/Python/service suites (unchanged), graph-wide/pre-commit checks (no commit).

Final uninterrupted Chrome sample: 202 observed renders in 7 seconds; displayed
rolling 28.8 fps, maximum interval 44 ms, average JS draw/composition 2.3 ms;
69,158 rendered triangles and 78 geometries in sampled speaking pose. Intel UHD
Graphics / ANGLE Direct3D11 on this host. Paused sample: 0 redraws in 750 ms.
This is not a VRAM measurement, GPU completion time, or universal FPS guarantee.

Inspected front, 35-degree, +/-90, rear, half/closed blink, pointing and actual
speaking export. Before/after QA is in `report/ai-idol-syna-3d-shape/`; latest
complete browser screenshots/results/WebM in `report/ai-idol-syna-3d/`.
The local MP4 review is `report/ai-idol-syna-3d-shape/syna-3d-rounded.mp4`, encoded
from the browser recording (not a new offline 3D render). Artifacts are not source
commit material. Shape is now volumetric in profile, but this remains a code-built
cartoon model; no claim of an artist-sculpted production asset or complete live
integration. Full expression/phoneme library remains outside this correction.
