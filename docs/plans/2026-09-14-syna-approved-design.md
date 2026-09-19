# Syna — approved design, chest Centella revision

## Scope and design decision

Keep the isolated real-3D pilot, its 15-second audio clock, product card and
separate ingredient board. Rebuild the procedural silhouette toward the approved
three-view reference: broad forehead, low/wide eyes, short muzzle, pillowy cheeks,
compact hoodie, short mitten paws and legs. Centella belongs on BOTH head and
shirt; retain SkinSyntax lettering and add the headphone S/microphone.

Use curved, painted eye surfaces with an animated eyelid aperture instead of
stacked glossy eye discs. This keeps the iris circular while blinking. Prefer
warm matte lighting. Do not use the reference bitmap as a fake 3D character.

Alternative rejected: another small depth-only adjustment to v2. It did not
address the face proportions the user rejected. A professionally sculpted/rigged
asset remains a possible later step; this change does not claim that quality.

## Safety / impact

Repository and worktree: SkinSyntaxVN---Decoding-Your-Skin-Language,
`C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language`.
GitNexus index: 84d4d5ed7a12f7c64e89d6b4ba71cda7eb444419, eight commits behind.
Upstream impact for Syna3D, its changed build/update methods, makeArm,
chestLabel, faceSurface, createSkullGeometry and PilotScene: UNKNOWN/not found.
No callers/processes can be inferred from the graph. A refresh was attempted.
Manual references confirm anatomy -> model -> scene -> app -> standalone index,
with local tests and QA helper as the other consumers. No production routes or
AI services import these symbols. Preserve pre-existing chatbot/HomeController/
ai_chat_widget edits; no edits to 2D, chatbot, recommend, voicechat, DB or Docker.

## Acceptance and verification

- Review actual browser front, 3/4, both profiles, blink and gesture frames against
  the approved image; visual fidelity is a human judgment, not a unit-test claim.
- Centella fan/notch/veins visible on head AND chest; no pointed two-leaf logo.
- Continuous skinned arms stay connected; no goggle rings or protruding beak.
- Run all pilot unit tests, real browser E2E, syntax and whitespace checks.
- Preserve playback/seek/export/error/reduced-motion behavior and old 2D smoke test.
- No paid API, new dependency or model download; no commit/push/deploy.

## Reference edit

Built-in imagegen, precise-object-edit. Prompt: change only the generic two-leaf
hoodie emblem in all three views to a pale mint Centella leaf with scalloped edge,
basal notch, radial veins and short stem. Preserve identity, body, poses, lighting,
head plant, headphones, all lettering and layout. Final image is stored locally
as `frontend/public/ai-idol-mascot-3d/assets/syna-approved-centella.png`.

The supplied action atlas is preserved at
`frontend/public/ai-idol-mascot-3d/assets/syna-action-atlas.png` and is shown in
the 3D pilot's “Kho hành động chuẩn” disclosure. It is a visual reference for
the next gesture pass (greeting, talking, thinking, product presentation, sale,
explanation, listening, processing, happy and goodbye); it is not rendered as a
fake live character.

## Results

- Unit: `npm.cmd --prefix frontend/public/ai-idol-mascot-3d test` — 9/9 PASS.
- Shape QA: `node scripts/ai-idol-mascot/check-approved-shape.cjs` — PASS;
  painted eyes 2, cached eye texture, closing aperture, finite skinned arms,
  chest emblem surface distance 0.0144, shoulder embedded 0.0524.
- Browser QA: `node scripts/ai-idol-mascot/check-3d-browser.cjs` — PASS;
  real Chrome, action atlas loaded, front/side/rear views, playback/seek/export,
  pause, reduced motion, missing audio/WebGL errors, mobile and old 2D smoke test.
  Seven-second sample observed 28.8 fps, 1.8 ms draw, 0 idle redraws on Intel UHD.
- Syntax/whitespace: JavaScript `node --check` and `git diff --check` PASS.
