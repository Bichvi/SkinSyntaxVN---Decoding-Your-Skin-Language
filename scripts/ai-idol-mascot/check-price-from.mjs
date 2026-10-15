/* Unit check: the Syna campaign board shows the price only from manifest.product.price_from.
   Run: node scripts/ai-idol-mascot/check-price-from.mjs  (exit 0 = PASS) */
import assert from 'node:assert/strict';
import { drawCampaign } from '../../frontend/public/ai-idol-mascot-3d/campaign-stage.js';

function recordingContext() {
  const texts = [];
  const ctx = new Proxy({}, {
    get(target, key) {
      if (key === 'fillText') return (text) => texts.push(String(text));
      if (key === 'measureText') return (text) => ({ width: String(text).length * 10 });
      if (key in target) return target[key];
      return () => {};
    },
    set(target, key, value) { target[key] = value; return true; },
  });
  return { ctx, texts };
}

function textsAt(priceFrom, time) {
  const { ctx, texts } = recordingContext();
  const product = { name: 'Serum A', price: 289000 };
  if (priceFrom !== 'missing') product.price_from = priceFrom;
  const manifest = { duration: 60, product, ingredients: ['Niacinamide'], cues: [] };
  drawCampaign(ctx, { renderer: { domElement: {} } }, manifest, {}, time, { active: -1 });
  return texts.join(' | ');
}

const priced = /289\.000 đ/;
assert.match(textsAt('missing', 1), priced, 'old manifests keep the price board');
assert.match(textsAt(0, 1), priced, 'live_only shows the price from the start');
assert.doesNotMatch(textsAt(null, 30), priced, 'product videos never show a price');
assert.doesNotMatch(textsAt(null, 30), /Giá đang cập nhật/, 'product videos show no price placeholder');
assert.doesNotMatch(textsAt(43.7, 20), priced, 'both-mode hides the price before the sale section');
assert.match(textsAt(43.7, 44), priced, 'both-mode shows the price from the sale section');
console.log('PASS: price board follows manifest.product.price_from');
