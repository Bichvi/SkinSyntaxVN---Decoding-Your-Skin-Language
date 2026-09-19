import test from 'node:test';
import assert from 'node:assert/strict';
import { campaignPose } from '../campaign-stage.js';
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
