import { PilotScene } from './scene.js?v=7';
import { audioEnvelope } from './timeline.js';
import { campaignPose, drawCampaign } from './campaign-stage.js';

const id = new URLSearchParams(location.search).get('job');
const endpoint = `/job/${encodeURIComponent(id)}/`;
function loadImage(name) {
  return new Promise((resolve) => {
    const image = new Image();
    const timer = setTimeout(() => {
      console.warn('[RENDER] Image load timed out for:', name);
      resolve(image.naturalWidth > 0 ? image : null);
    }, 5000);
    image.onload = () => {
      clearTimeout(timer);
      console.log('[RENDER] Image loaded successfully:', name, image.naturalWidth + 'x' + image.naturalHeight);
      resolve(image);
    };
    image.onerror = (e) => {
      clearTimeout(timer);
      console.warn('[RENDER] Image failed to load:', name, e);
      resolve(null);
    };
    image.src = endpoint + name;
  });
}
console.log('[RENDER] Starting render.js initialization for job:', id);
try {
  const response = await fetch(endpoint + 'manifest.json');
  if (!response.ok) throw new Error('Không đọc được dữ liệu render.');
  const manifest = await response.json();
  console.log('[RENDER] Manifest loaded successfully, duration:', manifest.duration);
  const context = new AudioContext();
  let audio;
  try {
    console.log('[RENDER] Fetching audio.wav...');
    const audioRes = await fetch(endpoint + 'audio.wav');
    const audioBuf = await audioRes.arrayBuffer();
    console.log('[RENDER] Decoding audio buffer of size:', audioBuf.byteLength);
    audio = await context.decodeAudioData(audioBuf);
    console.log('[RENDER] Audio decoded successfully, length:', audio.duration);
  } finally { await context.close(); }
  if (Math.abs(audio.duration - manifest.duration) > .1) throw new Error('Thời lượng âm thanh không khớp.');
  const envelope = audioEnvelope(audio.getChannelData(0), audio.sampleRate);
  console.log('[RENDER] Loading images if present...');
  const images = { product: manifest.hasProductImage ? await loadImage('product.img') : null,
    background: manifest.hasBackground ? await loadImage('background.img') : null };
  console.log('[RENDER] Images loaded. Initializing PilotScene...');
  const scene = new PilotScene();
  console.log('[RENDER] Running scene.warmup...');
  await scene.warmup([campaignPose(0, manifest, envelope), campaignPose(2, manifest, envelope)]);
  console.log('[RENDER] Warmup complete. Preparing canvas...');
  const canvas = document.getElementById('output'), ctx = canvas.getContext('2d', { alpha: false });
  window.synaRenderFrame = time => {
    const pose = campaignPose(time, manifest, envelope);
    scene.draw(pose); drawCampaign(ctx, scene, manifest, images, time, pose);
    return canvas.toDataURL('image/jpeg', .92).split(',')[1];
  };
  window.synaRenderInfo = { duration: audio.duration, ingredients: manifest.ingredients, product: manifest.product };
  window.synaRenderFrame(0);
  console.log('[RENDER] Ready to render frames!');
  document.body.dataset.ready = 'true';
} catch (error) {
  console.error('[RENDER ERROR]', error);
  document.body.dataset.error = error.message;
}
