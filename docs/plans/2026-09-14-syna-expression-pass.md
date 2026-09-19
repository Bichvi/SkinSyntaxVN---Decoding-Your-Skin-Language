# Syna: Centella, voice and expressive movement

Scope: the isolated `ai-idol-mascot-demo` and its sample-generation/test scripts.
Keep existing chatbot, recommendation, voice chat and campaign APIs unchanged.

Accepted direction: cream cat Syna, forest-green hoodie/headset, a real fan-shaped
Centella leaf with scalloped edges and veins radiating from the basal attachment.
Use the user's leaf image as a shape reference; do not embed the stock preview.
The brand spirit remains “Làm dịu – phục hồi – chữa lành”.

Design decisions:
- Keep the illustrated atlas. Replace the broken sprout mask with a clean generated
  head asset; a Canvas Centella layer grows from a fixed headset anchor.
- Replace whole-arm rotation with smoothly bent textured arms; align the neck
  endpoints with the moving chin and fixed collar, and ease every gesture.
- Eight facial expressions, twelve action presets, restrained optional stickers.
  Selectors let the owner review each expression/action without waiting for a cue.
- Create local Piper narration with Syna wording and a modest brighter pitch.
  Preserve intelligibility and measure caption boundaries after audio processing.
- Continue using one media clock for gestures, captions, mouth and export.

Validation: Node motion regressions, real Chrome playback/export and mobile/error
flows; inspect a contact sheet of expressions/actions plus exported video frames.
This is a cartoon rig/voice sample, not a claim of phoneme-accurate speech.

Impact: repository/worktree SkinSyntaxVN---Decoding-Your-Skin-Language at
`C:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language`, HEAD
`bf674f06b1489c001dbbe1f0845a5fe8258c9a27`. GitNexus index is 8 commits behind;
upstream impact on renderer, motion, app and narration returned UNKNOWN. Targeted
source inspection confirms renderer -> motion, app -> renderer/motion, tests ->
motion; sample audio generator is a standalone CLI, not imported by an AI service.
No clean whole-repository graph audit is claimed.
