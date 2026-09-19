import test from 'node:test';
import assert from 'node:assert/strict';
import { buildEnvelope, cueAt, envelopeAt, eyeOpenness, formatTime, makeBlinks, poseAt,
  expressionAt, stickerAt, GESTURE_DURATION } from '../motion.js';

test('silence and stopped playback keep the mouth closed', () => {
  const silent = buildEnvelope(new Float32Array(1000), 1000);
  assert.equal(envelopeAt(silent, 0.4, true), 0);
  assert.equal(envelopeAt({ step: 0.02, values: [1, 1] }, 0.01, false), 0);
  assert.equal(envelopeAt(null, 0, true), 0);
});
test('speech response is bounded and closes after the audio ends', () => {
  const envelope = buildEnvelope(new Float32Array(100).fill(0.5), 1000);
  assert.ok(envelopeAt(envelope, 0.03, true) > 0.7);
  assert.ok(envelope.values.every((value) => value >= 0 && value <= 1));
  assert.ok(envelope.values[0] < envelope.values[2], 'speech onset should ease in');
  assert.equal(envelopeAt(envelope, 1, true), 0);
  assert.throws(() => buildEnvelope(new Float32Array(), 0));
});
test('sample clock uses actual sample rate including a final short window', () => {
  const envelope = buildEnvelope(new Float32Array(45).fill(0.05), 1000, 0.02);
  assert.equal(envelope.values.length, 3);
  assert.equal(envelope.step, 0.02);
  assert.ok(envelope.values[2] > 0, 'a final partial audio window must not be discarded');
  assert.equal(envelopeAt(envelope, 0.06, true), 0);
});
test('subtitle boundaries use half-open intervals; gaps do not borrow a sentence', () => {
  const cues = [{ start: 0, end: 1 }, { start: 1.4, end: 3 }];
  assert.equal(cueAt(cues, 0.99), cues[0]);
  assert.equal(cueAt(cues, 1), null);
  assert.equal(cueAt(cues, 1.4), cues[1]);
  assert.equal(cueAt(cues, 3), null);
});
test('blinks are irregular and deterministic across replay and seek', () => {
  const times = makeBlinks(30);
  assert.deepEqual(times, makeBlinks(30));
  assert.notEqual(times[2] - times[1], times[3] - times[2]);
  assert.ok(eyeOpenness(times[0] + 0.11, times) < 0.001);
  assert.equal(eyeOpenness(times[0] + 0.3, times), 1);
});
test('greeting and pointing control different arms', () => {
  const wave = poseAt(1, { start: 0, end: 4, gesture: 'greeting' }, 0.5);
  const point = poseAt(1, { start: 0, end: 4, gesture: 'presenting' }, 0.5);
  assert.ok(wave.leftArm > 1.5);
  assert.ok(point.rightArm < -1);
  assert.notEqual(wave.head, point.head);
});
test('upper-body follow-through is present but eased at gesture boundaries', () => {
  const atStart = poseAt(0.01, { start: 0, end: 4, gesture: 'greeting' }, 0.4);
  const shortlyAfter = poseAt(0.04, { start: 0, end: 4, gesture: 'greeting' }, 0.4);
  const mid = poseAt(1.6, { start: 0, end: 4, gesture: 'greeting' }, 0.4);
  assert.ok(Number.isFinite(atStart.shoulder));
  assert.ok(Math.abs(shortlyAfter.shoulder - atStart.shoulder) < 0.1);
  assert.ok(Math.abs(mid.body) < 0.2);
});
test('reduced motion removes body, head and tail movement', () => {
  const pose = poseAt(3, { start: 0, end: 4, gesture: 'greeting' }, 0.8, true);
  assert.equal(pose.head, 0); assert.equal(pose.body, 0);
  assert.equal(pose.tail, 0); assert.equal(pose.breath, 0);
  assert.equal(pose.leftArm, 0.12);
});
test('time labels remain valid with unloaded or invalid metadata', () => {
  assert.equal(formatTime(NaN), '00:00'); assert.equal(formatTime(-5), '00:00');
  assert.equal(formatTime(75.9), '01:15');
});
test('manual gestures blend back into a paused narration pose without snapping', () => {
  const cue = { start: 0, end: 6, gesture: 'presenting' };
  const auto = poseAt(2, cue, 0);
  const ending = poseAt(2, cue, 0, false, { name: 'greeting', elapsed: GESTURE_DURATION - 0.001 });
  for (const key of ['leftArm', 'rightArm', 'head', 'headY', 'shoulder']) {
    assert.ok(Math.abs(ending[key] - auto[key]) < 0.0001, key);
  }
});
test('a silent subtitle gap keeps all joints finite and closes the mouth', () => {
  const pose = poseAt(5, { text: ' ', gesture: 'idle' }, 0);
  for (const value of Object.values(pose)) if (typeof value === 'number') assert.ok(Number.isFinite(value));
  assert.deepEqual(expressionAt(5, { text: ' ' }), { name: 'neutral', amount: 0 });
});
test('loud syllable peaks do not shake the neck, while mouth remains audio-driven', () => {
  const cue = { start: 0, end: 5, gesture: 'talking' };
  assert.equal(poseAt(2, cue, 0).head, poseAt(2, cue, 1).head);
  assert.equal(poseAt(2, cue, 0).headY, poseAt(2, cue, 1).headY);
});
test('stickers are brief, deterministic and absent before or after their window', () => {
  const cue = { start: 5, end: 10, sticker: 'heart' };
  assert.equal(stickerAt(5.2, cue), null);
  assert.equal(stickerAt(8.8, cue), null);
  assert.equal(stickerAt(6.4, cue).name, 'heart');
  assert.deepEqual(stickerAt(6.4, cue), stickerAt(6.4, cue));
});
