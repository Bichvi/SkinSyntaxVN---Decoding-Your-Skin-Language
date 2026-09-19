# LUNA 2D — prototype approval gate

## Scope agreed with the user

Build the previously proposed short, audible 2D mascot prototype before changing
the live product-generation pipeline. Do not modify chatbot, recommendation,
voice chat, existing AI Idol routes, databases, queues or deployment settings.

## Brainstorm and decision

- Pose swapping alone: inexpensive but does not demonstrate independent movement.
- Layered 2D puppet: selected; animate head, eyes, mouth, torso, arms and tail.
- Full 3D: deferred until a 2D quality sample is approved.

The user's cream cat / green headset / leaf sprout sheet is the visual reference.
Use an image-generated RGBA component atlas, not a static whole-character image.
Canvas draws the components independently. Mouth response follows the RMS envelope
of the exact bundled audio, not a timer pretending to be lip sync. This is
amplitude-driven cartoon speech, not phoneme-accurate Vietnamese lip sync.

## Design plan and critique

- Subject: LUNA, the SkinSyntax livestream host. Audience: the project owner.
- Single job: listen to and inspect a short sample before accepting a new renderer.
- Palette: forest #173f32, mint #e6f2ea, paper #fbfcfa, ink #213c32,
  blush #f5d8d7, muted #65796e.
- Type: Georgia for the restrained editorial page title; Segoe UI/system sans
  for Vietnamese body text and controls; tabular numeric playback timestamps.
- Layout: title / large stage with transport / compact direction-and-script panel.
- Signature: the independently animated cream cat on a quiet mint stage.
- Critique: avoid decorative dashboards, fake LIVE indicators, fabricated prices,
  endless movement and whole-image bobbing. The sample is visibly marked as a
  prototype, not connected to the store or scheduled broadcasting.

## Implementation

1. Prepare a reusable atlas and short local-Piper narration with real sentence
   timings. Keep every media asset within the workspace.
2. Add an isolated static folder at frontend/public/ai-idol-mascot-demo/.
3. Separate deterministic motion logic, Canvas rendering and browser controls.
4. Add play/pause/restart/seek, three stage colors, local background upload and
   bounded manual gestures. Never upload user background files.
5. Export a WebM sample only on explicit button click if MediaRecorder is supported;
   cancel on hidden tab to avoid claiming a successful frozen recording.
6. Add Node built-in unit tests and real-browser Playwright checks using available
   tooling. No new framework or global dependency.

## Acceptance

- Audio plays after a user gesture; mouth closes on pause/end/silence.
- Blinks have deterministic irregular spacing; head/arms move independently.
- Seeking and replay use the same media clock as the sentence captions.
- Missing media / invalid upload / unsupported export have visible errors.
- Keyboard-accessible controls, no horizontal overflow at 390 px, reduced-motion
  setting disables decorative motion while retaining speaking-state feedback.
- No protected module or existing campaign changes.

## Follow-up, not implemented by this milestone

Production renderer integration, arbitrary script TTS, phoneme alignment, employee
approval, scheduled/RTMP broadcast, and resource-budget benchmarks. Approve the
visual and audible sample first. A rendered video is not evidence of those flows.

## Syna visual follow-up (2026-09-13)

The mascot direction now uses the name Syna and the preferred emotional line
“Làm dịu – phục hồi – chữa lành”. In the isolated renderer, the head was made a
little smaller, a short cream neck/collar joint was added between head and hoodie,
and the upper-body pose gained eased shoulder, head, gaze and tail follow-through.
The old pointed sprout is masked on an alpha offscreen layer and replaced by a
rounded Centella leaf overlay. This keeps the existing atlas untouched and makes
the prototype immediately reviewable even while the image-generation tool quota
is unavailable. The bundled WAV still contains the earlier Luna review wording;
regenerating Piper audio with Syna copy is a separate follow-up once the local
container command is available.
