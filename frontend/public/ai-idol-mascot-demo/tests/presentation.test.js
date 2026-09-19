import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { presentationAt, presentationPose } from '../presentation.js';
import { poseAt, blendPreviewPose } from '../motion.js';

const cues = [
  { start: 0, end: 2, board: null },
  { start: 2.4, end: 8, board: 'centella', lookAtBoard: true },
  { start: 8.4, end: 11, board: null },
  { start: 11.4, end: 16, board: 'glycerin', lookAtBoard: true },
  { start: 16.4, end: 22, board: 'panthenol', lookAtBoard: true },
  { start: 22.4, end: 26, board: 'summary' },
];

test('board progressively reveals only spoken points; seeks have no history leak', () => {
  assert.deepEqual(presentationAt(cues, 0).visible, []);
  assert.deepEqual(presentationAt(cues, 12).visible, ['centella', 'glycerin']);
  assert.deepEqual(presentationAt(cues, 18).visible, ['centella', 'glycerin', 'panthenol']);
  assert.deepEqual(presentationAt(cues, 3).visible, ['centella']);
  assert.deepEqual(presentationAt(cues, -1).visible, []);
});

test('half-open cue boundaries retain board notes but never borrow speech/highlight', () => {
  const gap = presentationAt(cues, 8);
  assert.deepEqual(gap.visible, ['centella']);
  assert.equal(gap.retained, 'centella');
  assert.equal(gap.active, null);
  assert.equal(gap.look, 0);
  assert.equal(presentationAt(cues, 11.4).active, 'glycerin');
  assert.equal(presentationAt(cues, 26.5).summary, true);
});

test('occasional glance eases in and returns to the audience before speech ends', () => {
  assert.equal(presentationAt(cues, 2.4).look, 0);
  assert.ok(presentationAt(cues, 3.5).look > 0.95);
  assert.equal(presentationAt(cues, 7.5).look, 0);
  assert.equal(presentationAt(cues, 9.5).look, 0);
  assert.ok(Math.abs(presentationAt(cues, 3.00).look - presentationAt(cues, 3.01).look) < 0.03);
});

test('head/eyes/shoulder/arm follow board together, but reduced motion and manual previews win', () => {
  const pose = poseAt(4, null, 0), board = presentationAt(cues, 4);
  const result = presentationPose(pose, board);
  assert.ok(result.boardLook > 0.95);
  assert.ok(result.headX > pose.headX && result.gaze > pose.gaze);
  assert.ok(result.rightArm < -1.5);
  const reduced = presentationPose(poseAt(4, null, 0, true), board, true);
  assert.equal(reduced.boardLook, 0); assert.equal(reduced.head, 0);
  const manual = presentationPose(pose, board, false, { elapsed: 1.5 });
  assert.equal(manual.boardLook, 0);
  assert.equal(manual.rightArm, pose.rightArm);
});

test('unknown/invalid cue metadata cannot introduce phantom points or NaN motion', () => {
  const state = presentationAt([{ start: NaN, end: 4, board: 'glycerin' },
    { start: 0, end: 4, board: 'fake', lookAtBoard: true }], 1);
  assert.deepEqual(state.visible, []); assert.equal(state.look, 0);
  for (const value of Object.values(presentationPose(poseAt(1, null, 0), state))) {
    if (typeof value === 'number') assert.ok(Number.isFinite(value));
  }
});

test('generated presentation cues fit the actual processed WAV and all three points', () => {
  const metadata = JSON.parse(readFileSync(new URL('../assets/syna-presentation-v5.json', import.meta.url)));
  const wav = readFileSync(new URL('../assets/syna-presentation-v5.wav', import.meta.url));
  assert.equal(wav.toString('ascii', 0, 4), 'RIFF');
  let bytesPerSecond = 0, dataSize = 0;
  for (let offset = 12; offset + 8 <= wav.length;) {
    const kind = wav.toString('ascii', offset, offset + 4), size = wav.readUInt32LE(offset + 4);
    if (kind === 'fmt ') bytesPerSecond = wav.readUInt32LE(offset + 16);
    if (kind === 'data') dataSize = size;
    offset += 8 + size + size % 2;
  }
  const duration = dataSize / bytesPerSecond;
  assert.ok(Number.isFinite(duration) && duration > 20);
  assert.ok(Math.abs(metadata.duration - duration) < 0.001);
  assert.equal(metadata.processedBeforeTiming, true);
  assert.equal(metadata.presentationDemo, true);
  assert.equal(metadata.cues.length, 8);
  let previous = 0;
  for (const cue of metadata.cues) {
    assert.ok(cue.start >= previous && cue.end > cue.start && cue.end <= duration);
    previous = cue.end;
  }
  assert.deepEqual(metadata.cues.filter((cue) => cue.lookAtBoard).map((cue) => cue.board),
    ['centella', 'glycerin', 'panthenol']);
});

test('rapid preview switch begins at the displayed joints, then eases into new action', () => {
  const previous = { ...poseAt(2, null, 0, false, { name: 'greeting', elapsed: 1.4 }), boardLook: 0 };
  const target = { ...poseAt(2, null, 0, false, { name: 'happy', elapsed: 0 }), boardLook: 0 };
  const start = blendPreviewPose(target, { fromPose: previous, elapsed: 0 });
  const next = blendPreviewPose(target, { fromPose: previous, elapsed: 0.016 });
  for (const key of ['head', 'headX', 'leftArm', 'rightArm', 'leftBend', 'rightBend', 'shoulder']) {
    assert.equal(start[key], previous[key]);
    assert.ok(Math.abs(next[key] - previous[key]) < 0.003, key);
  }
  const complete = blendPreviewPose(target, { fromPose: previous, elapsed: 0.6 });
  assert.ok(Math.abs(complete.leftArm - target.leftArm) < 1e-12);
});

test('manual preview does not freeze the body when narration is paused', () => {
  const first = poseAt(2, null, 0, false, { name: 'talking', elapsed: 1 });
  const second = poseAt(2, null, 0, false, { name: 'talking', elapsed: 1.5 });
  assert.notEqual(first.breath, second.breath);
  assert.notEqual(first.shoulder, second.shoulder);
});
