# Syna 2D — product card + presentation board (v6)

Public isolated preview:
http://localhost:8080/ai-idol-mascot-demo/index.html?rev=syna-v6-1

Click **Nghe bản mẫu**. Choosing **Kho hành động** previews immediately and pauses
narration; **Xem lại** replays it, **Trở lại kịch bản** exits with an eased transition.
**Sticker dễ thương** controls occasional stickers. An action lasts 4.2 seconds;
a selected facial expression stays selected until returning to automatic mode.

## Presentation design and action fixes — 2026-09-14

The owner's clarification supersedes the first v5 layout: DO NOT replace the
product card. V6 has three separate areas: persistent product image/name/price
on the left, Syna in the center, and an additional ingredient board on the right.
The 199,000 VND price, 50 ml bottle and gel product are explicitly design fixtures,
not catalog data, an actual formulation, promotion or purchasable offer.

Three ingredient rows reveal and highlight on the corresponding measured audio
sentence. Full captions remain separate. Syna glances toward each row, points
with a rod whose grip follows the deformed paw, then faces the audience again.
The same deterministic audio clock handles gaps, backward seeking and WebM export.
Accessible notes and the sample price remain readable under the mobile player.

Action fixes: selection immediately previews; quick buttons and selector agree;
countdown and return control clarify when preview ends. Rapid changes blend from
the actual displayed pose over 550 ms instead of discarding the old pose. The
body continues a gentle motion while a gesture is previewed over paused audio.
Shoulder anchors moved inward/up, shared sockets lie behind the torso, and the
detached atlas end-cap is clipped under a connected proximal sleeve. All 12 poses
are reviewed using the actual renderer, including lifted arms and heart/laptop.

Active voice assets: `syna-presentation-v5.wav/json`, 38.9677 seconds, eight cues.
Same local Piper voice/pitch pipeline as v4; old voice assets are retained.
No extra model/download/GPU/paid API. Ingredient purpose summaries are general
references, not product efficacy claims; see `assets/provenance.md` for sources.

Modified scope: `app.js`, `motion.js`, `character.js`, `renderer.js`, `index.html`,
`style.css`, `package.json`; new `presentation.js`, `tests/presentation.test.js`,
sample voice assets; scoped generator/browser/visual scripts and documentation.
No changes to chatbot, recommendation, voicechat or live campaign service files.

Verification commands (repository root):

```powershell
node --test frontend/public/ai-idol-mascot-demo/tests/motion.test.js frontend/public/ai-idol-mascot-demo/tests/presentation.test.js
node --check frontend/public/ai-idol-mascot-demo/motion.js
node --check frontend/public/ai-idol-mascot-demo/presentation.js
node --check frontend/public/ai-idol-mascot-demo/character.js
node --check frontend/public/ai-idol-mascot-demo/renderer.js
node --check frontend/public/ai-idol-mascot-demo/app.js
.runtime/python38/python.exe -m py_compile scripts/ai-idol-mascot/generate-demo-audio.py
# Use existing Playwright via NODE_PATH, as in the previous verification below.
node scripts/ai-idol-mascot/check-browser.cjs
node scripts/ai-idol-mascot/visual-review.cjs
git diff --check
```

21 unit tests PASS; Chrome acceptance PASS for three board targets, gap retention,
backward seek, full export, rapid action switching, select-to-preview, return,
reduced motion, backgrounds/errors, 390px mobile/keyboard and missing audio.
No page JS errors, external calls, POST or campaign writes. Syntax checks PASS.
No JS/CSS lint or build command is configured for this native-module prototype.
GitNexus graph result remains UNKNOWN; isolated direct consumers were verified
manually. No whole-repository clean graph claim, commit, deploy or service restart.

Artifacts: `report/ai-idol-syna-v6/` contains stage, action/expression sheets,
three ingredient frames, browser-results, mobile and full WebM export.

## Previous v4 baseline (preserved history)

## Delivered behavior

- Two fan-shaped, scalloped Centella leaves with radiating veins. Their stems
  start at a shared headset anchor and follow the head. The user's stock preview
  is a visual reference, not an embedded asset.
- Short visible neck with endpoints attached to the moving chin and hoodie.
- Textured arm meshes bend at the elbow; eased poses return to automatic playback
  without switching layer order or jumping back at the end of a manual action.
- Eight expressions: neutral, left/right wink, happy, surprised, thinking,
  slightly sad, confident. Left/right means the character's own sides.
- Twelve action presets: idle, two talking gestures, thinking, presenting, sample
  offer board, greeting, explaining, listening, laptop, holding a heart, goodbye.
- Brief Hi, heart, sparkle, Centella, question and dots stickers. They can be
  disabled and are removed with reduced motion.
- New 24.0198-second Syna narration, generated with local Piper. Pitch raised
  2.5 semitones with duration compensation; no cloned voice or paid TTS request.
  “Si Na” is used as a pronunciation spelling for the displayed name Syna.
  Sentence captions are measured from the processed WAV segments.
- Preview, backgrounds, uploads, replay/seek, reduced motion and full WebM export.
  Background uploads stay in the browser; a hidden tab pauses/cancels recording.

The emotional brand line is “Làm dịu – phục hồi – chữa lành”.
No global site slogan, chatbot, recommendation, voice-chat or live campaign
service was changed. This page has no access to admin/campaign data.

## Source and assets

- `app.js`: local playback, controls, MediaRecorder; a shared audio clock.
- `motion.js`: eased poses, smoothed speech energy, blink/expression/sticker timing.
- `character.js`: generated atlas importer, connected neck, textured arm meshes,
  facial drawing, Centella and props/stickers.
- `renderer.js`: stage, product illustration, captions, character integration.
- `presentation.js` (v5/v6): sample product, audio-clock board state and pointing pose.
- `assets/syna-key-atlas-v4.png`: 1536×1024 built-in image-generation output,
  ~1.44 MB. Magenta-key background is removed once on loading. The image tool
  returned opaque checkerboards for alpha requests; those drafts are NOT used.
- `assets/syna-voice-v4.wav`, `syna-voice-v4.json`: previous local audio and timings.
- `assets/provenance.md`: asset origin and actual prompts.
- Previous Luna/V3 assets are retained, but are no longer loaded by this page.

## Previous v4 validation on 2026-09-14

Executed from the repository root:

```powershell
node --test frontend/public/ai-idol-mascot-demo/tests/motion.test.js
node --check frontend/public/ai-idol-mascot-demo/character.js
node --check frontend/public/ai-idol-mascot-demo/renderer.js
node --check frontend/public/ai-idol-mascot-demo/app.js
node --check scripts/ai-idol-mascot/check-browser.cjs
# Existing bundled Playwright installation, no dependency install:
$env:NODE_PATH = '<existing node_modules containing Playwright>'
node scripts/ai-idol-mascot/check-browser.cjs
node scripts/ai-idol-mascot/visual-review.cjs
git diff --check
```

Results: 13 unit tests PASS. Real Chrome acceptance flow PASS, including narration
seek, 8/12 selectors, stickers, local uploads/errors, pause, full export, cancel,
390px mobile/keyboard and missing audio. No JS errors, external calls, POST or
campaign writes. Expression/action contact sheets and exported frames inspected;
neck spacing and arm mesh seams were corrected during visual review.

The WAV has non-silent audio, mean -17.3 dB and peak -1.1 dB by FFmpeg. WebM has
720 decoded frames over ~24 seconds. MP4 copy decodes successfully with H.264
1280×720 at 30 fps plus AAC audio. Technical audio checks do not establish whether
the owner will prefer this voice; listen to the sample for that decision.

Artifacts: `report/ai-idol-syna-v4/` — `stage.png`, `expressions.png`,
`actions.png`, desktop/mobile screenshots, browser-results.json,
`syna-rau-ma-v4.webm` and `syna-rau-ma-v4.mp4`.

Python generator syntax also checked and the actual local Piper/FFmpeg generation
completed. No JS/CSS lint script is configured for this folder. Tracked whitespace
check passed; these prototype files are untracked, so targeted trailing-whitespace
search was also run over changed source files (no matches). No commit/deploy.

## Limits and next integration gate

The face is a cartoon rig driven by speech energy, not phoneme-accurate lip sync.
The voice is a locally processed review sample. Performance is checked for this
24-second sample, not an unlimited-length benchmark. The bottle/offer board are
explicit illustrations, not real catalog prices or offers.

No scheduled broadcast, arbitrary-script TTS, script/video approval workflow or
AI Idol provider integration is added here. Approve the visual/voice sample
before integrating those existing service contracts.

GitNexus: bound repository/worktree
`C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language`; current HEAD
`bf674f06b1489c001dbbe1f0845a5fe8258c9a27`. Index is 8 commits behind. Upstream
checks returned UNKNOWN for unindexed prototype symbols/files. Targeted source
inspection resolved the local consumer chain (app → renderer → character/motion;
tests → motion; standalone sample-audio CLI). No clean whole-repository graph
audit is claimed. See `docs/plans/2026-09-14-syna-expression-pass.md`.
