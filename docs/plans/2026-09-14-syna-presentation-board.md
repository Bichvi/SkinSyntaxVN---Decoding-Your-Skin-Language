# Syna v5 — product presentation board

Scope: isolated mascot demo, local sample audio generator, focused tests only.
No chatbot, recommendation, voicechat, live campaign or catalog contracts change.

Design: retain the cat on the left; turn the static product card into a readable
cream presentation board on the right. Three ingredient rows are progressively
introduced and highlighted by measured narration cues. The full sentence remains
in subtitles. On selected cues Syna glances/leans toward the board and extends a
pointer toward the active row, then returns to the audience before the cue ends.
This is a small 2D turn, not newly generated profile artwork or 3D motion.

Use an explicitly fictional gel product for this design demo. Ingredient names
illustrate layout only, not an actual formula or efficacy claim. Preserve old v4
audio and atlas; generate a v5 narration using the existing local Piper service.
No model download or paid API. Board and character are Canvas layers, including
the exported video; a text companion under the player provides mobile/accessibility.

Timing: one source of truth, audio.currentTime. The generator measures each
processed audio segment; cues carry board keys. Board retention is derived from
cue history, not mutable timers, so backward seek, gaps and export are reproducible.
Reduced motion retains text/highlights without head, body or pointing motion.
Manual gesture previews temporarily take precedence over automatic presentation.

Impact gate: bound repository SkinSyntaxVN---Decoding-Your-Skin-Language at the
current checkout. Index 84d4d5e is eight commits behind. Upstream impact reports
UNKNOWN (new prototype symbols absent; generator has no resolved callers).
Targeted consumer inspection confirms app -> renderer -> character/motion and
standalone test/generator consumers only. Graph completeness is not claimed.

Acceptance: progressive rows; spoken cue and highlight agree; no glance during
audience-only sentences; eased return; pause/seek/replay consistency; mobile
readable notes; retained expressions/stickers/backgrounds; full WebM with board.
Verify Node unit tests, syntax/whitespace, real Playwright controls/export, and
inspect actual rendered presentation frames. Do not claim audio quality by ear.

## Owner correction, implemented in v6

Keep a separate product card with image, name AND price; add the ingredient board
alongside, never replace the card. Place product left, Syna center, board right.
Use clearly labelled demo values pending catalog integration. Ingredient rows
also carry short, qualified purpose summaries with references in provenance.

Reported action-library defects: mid-preview switching discards the current pose;
the atlas sleeve end cap appears detached at shoulder rotation extremes. Remedy:
select-to-preview + explicit status/return, snapshot-and-ease actual joints on
rapid changes, connected shoulder sockets/proximal sleeve, and a contact sheet
of all twelve gestures. No bitmap edits, new model or production service changes.
