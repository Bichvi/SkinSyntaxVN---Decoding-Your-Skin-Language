import test from 'node:test';
import assert from 'node:assert/strict';
import { campaignPose, speechWindow } from '../campaign-stage.js';
const manifest = { duration: 45, cues: [{ start: 20, end: 25, text: 'Niacinamide', ingredient: 0 }] };
test('campaign follows speech beyond the pilot and turns towards board at the ingredient cue', () => {
  const p = campaignPose(22, manifest, { step: 1, values: Array(46).fill(.5) });
  assert.equal(p.time, 22); assert.equal(p.mouth, .5); assert.equal(p.active, 0);
  assert.ok(p.headYaw > .4); assert.notEqual(p.leftArm, -.1);
  assert.equal(p.point, 0); assert.ok(Math.abs(p.leftFoot) > 0 || Math.abs(p.rightFoot) > 0);
  assert.equal(campaignPose(30, manifest, null).headYaw, 0);
});
test('quiet audio closes mouth; no repeating greeting after 15 seconds', () => {
  assert.equal(campaignPose(32, manifest, null).mouth, 0);
  assert.equal(campaignPose(17, manifest, null).leftArm, -.1);
  assert.ok(campaignPose(2, manifest, null).leftArm < -1);
});

test('semantic cues add safe gestures without product-specific lifting', () => {
  const envelope = { step: 1, values: Array(20).fill(.5) };
  const price = { duration: 20, cues: [{ start: 4, end: 8, text: 'Giá ưu đãi hôm nay', ingredient: -1 }] };
  const cta = { duration: 20, cues: [{ start: 4, end: 8, text: 'Mời bạn thêm vào giỏ hàng', ingredient: -1 }] };
  const benefit = { duration: 20, cues: [{ start: 4, end: 8, text: 'Giúp làm dịu và phục hồi', ingredient: -1 }] };
  assert.equal(campaignPose(6, price, envelope).action, 'price');
  assert.equal(campaignPose(6, price, envelope).pointTarget, 'price');
  assert.equal(campaignPose(6, price, envelope).sticker, 'sparkle');
  assert.ok(campaignPose(6, price, envelope).headYaw < 0);
  assert.equal(campaignPose(6, cta, envelope).sticker, 'heart');
  assert.equal(campaignPose(6, benefit, envelope).action, 'benefit');
  assert.equal(campaignPose(6, benefit, envelope).point, 0);
});

test('music lead-in: Syna greets before speaking and waves goodbye after the last word', () => {
  const padded = { duration: 26, speech: { start: 2.6, end: 23.6 }, cues: [{ start: 2.6, end: 6, text: 'Xin chào', ingredient: -1 }] };
  assert.deepEqual(speechWindow(padded), { start: 2.6, end: 23.6 });
  assert.ok(campaignPose(1.5, padded, null).leftArm < -1, 'waving during the silent lead-in');
  assert.ok(campaignPose(2.7, padded, null).leftArm < -1, 'still waving as the first word starts');
  assert.ok(campaignPose(4.5, padded, null).leftArm > -.5, 'arm lowered once talking');
  assert.equal(campaignPose(12, padded, null).sticker, null);
  const goodbye = campaignPose(25.2, padded, null);
  assert.ok(goodbye.leftArm < -1, 'goodbye wave after speech');
  assert.equal(goodbye.sticker, 'heart');
  assert.equal(goodbye.mouth, 0);
});

test('manifests without a speech window keep the original timing', () => {
  const legacy = { duration: 20, cues: [] };
  assert.deepEqual(speechWindow(legacy), { start: 0, end: 20 });
  assert.equal(campaignPose(19.5, legacy, null).sticker, null);
  assert.equal(campaignPose(17, legacy, null).leftArm, -.1);
});

test('library clips after the opening do not greet; manifests without the flag still do', () => {
  const clip = { duration: 20, greet: false, cues: [] };
  for (const time of [.5, 1.5, 3]) assert.equal(campaignPose(time, clip, null).leftArm, -.1);
  assert.ok(campaignPose(1.5, { duration: 20, cues: [] }, null).leftArm < -1);
  assert.ok(campaignPose(1.5, { duration: 20, greet: true, cues: [] }, null).leftArm < -1);
});

function fakeCtx() {
  const calls = [];
  const ctx = {
    textAlign: 'left', font: '', fillStyle: '', calls,
    measureText: (s) => ({ width: String(s).length * 10 }),
    beginPath() {}, roundRect() {}, fill() {}, stack: [], save() { this.stack.push(this.textAlign); }, restore() { this.textAlign = this.stack.pop(); },
    fillText(text, x, y) { calls.push(['fillText', text, x, y, this.textAlign]); },
  };
  return ctx;
}
test('captionBaselines centres one and two line blocks around the box centre', async () => {
  const { captionBaselines } = await import('../campaign-stage.js');
  const one = captionBaselines(1, 663.5, 22);
  assert.equal(one.length, 1); assert.ok(Math.abs(one[0] - 670.1) < .01);
  const two = captionBaselines(2, 663.5, 22);
  assert.equal(two.length, 2); assert.ok(Math.abs(two[1] - two[0] - 29) < 1e-9);
  assert.ok(Math.abs(two[0] - 655.6) < .01);
  assert.ok(Math.abs((two[0] - 22 * .8 + two[1] - 22 * .8 + 22) / 2 - 663.5) < 1e-9);
});
test('drawCaption centres wrapped lines at x=640 and restores textAlign', async () => {
  const { drawCaption } = await import('../campaign-stage.js');
  const ctx = fakeCtx();
  drawCaption(ctx, 'một hai ba bốn năm sáu bảy tám chín mười '.repeat(8).trim());
  const texts = ctx.calls.filter((c) => c[0] === 'fillText');
  assert.equal(texts.length, 2);
  for (const t of texts) { assert.equal(t[2], 640); assert.equal(t[4], 'center'); }
  assert.equal(ctx.textAlign, 'left');
  const short = fakeCtx(); drawCaption(short, 'Xin chào');
  const y = short.calls.find((c) => c[0] === 'fillText')[3];
  assert.ok(Math.abs(y - 670.1) < .01);
});

test('talking gestures are varied, continuous, and silent without speech', async () => {
  const { talkGesture } = await import('../campaign-stage.js');
  const cue = { start: 0, end: 40, text: 'Hôm nay mình kể bạn nghe nè', ingredient: -1 };
  let previous = talkGesture(0, cue), poses = new Set();
  for (let t = 1 / 25; t < 40; t += 1 / 25) {
    const g = talkGesture(t, cue);
    for (const key of ['leftArm', 'leftElbow', 'rightArm', 'rightElbow', 'headTilt']) {
      assert.ok(Number.isFinite(g[key]));
      assert.ok(Math.abs(g[key] - previous[key]) < .2, `${key} jumps at ${t.toFixed(2)}s`);
    }
    poses.add(`${Math.round(g.leftArm * 2)}/${Math.round(g.rightArm * 2)}`); previous = g;
  }
  assert.ok(poses.size >= 4, `only ${poses.size} distinct gestures`);
  assert.equal(talkGesture(5, null).rightArm, 0);
  const board = { start: 0, end: 40, text: 'Ý đầu tiên', ingredient: 0 };
  for (let t = 0; t < 40; t += .5) assert.equal(talkGesture(t, board).rightArm, 0, 'right paw stays free for the board');
});
