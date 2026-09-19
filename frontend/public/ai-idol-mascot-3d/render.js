import { PilotScene } from './scene.js?v=7';
import { audioEnvelope } from './timeline.js';
import { campaignPose, drawCampaign } from './campaign-stage.js';

const id = new URLSearchParams(location.search).get('job');
const endpoint = `/job/${encodeURIComponent(id)}/`;
async function loadImage(name) {
  const image = new Image(); image.src = endpoint + name;
  await image.decode(); return image;
}
try {
  const response = await fetch(endpoint + 'manifest.json');
  if (!response.ok) throw new Error('Không đọc được dữ liệu render.');
  const manifest = await response.json();
  const context = new AudioContext();
  let audio;
  try { audio = await context.decodeAudioData(await (await fetch(endpoint + 'audio.wav')).arrayBuffer()); }
  finally { await context.close(); }
  if (Math.abs(audio.duration - manifest.duration) > .1) throw new Error('Thời lượng âm thanh không khớp.');
  const envelope = audioEnvelope(audio.getChannelData(0), audio.sampleRate);
  const images = { product: manifest.hasProductImage ? await loadImage('product.img') : null,
    background: manifest.hasBackground ? await loadImage('background.img') : null };
  const scene = new PilotScene();
  await scene.warmup([campaignPose(0, manifest, envelope), campaignPose(2, manifest, envelope)]);
  const canvas = document.getElementById('output'), ctx = canvas.getContext('2d', { alpha: false });
  window.synaRenderFrame = time => {
    const pose = campaignPose(time, manifest, envelope);
    scene.draw(pose); drawCampaign(ctx, scene, manifest, images, time, pose);
    return canvas.toDataURL('image/jpeg', .92).split(',')[1];
  };
  window.synaRenderInfo = { duration: audio.duration, ingredients: manifest.ingredients, product: manifest.product };
  window.synaRenderFrame(0);
  document.body.dataset.ready = 'true';
} catch (error) { document.body.dataset.error = error.message; }
