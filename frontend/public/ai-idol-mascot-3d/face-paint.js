import * as THREE from './vendor/three.module.min.js';

function textureFor(canvas) {
  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.generateMipmaps = false;
  texture.minFilter = THREE.LinearFilter;
  return texture;
}

/** Eyelids reveal a round iris; they never squeeze its pupil/highlight into a line. */
export function createEyePaint(side) {
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 384;
  const ctx = canvas.getContext('2d'), texture = textureFor(canvas);
  let previous = '';
  function update(openness = 1, gaze = 0) {
    const open = Math.round(THREE.MathUtils.clamp(openness, 0, 1) * 100) / 100;
    const shift = Math.round(gaze * 650), key = `${open}/${shift}`;
    if (key === previous) return; previous = key;
    ctx.clearRect(0, 0, 384, 384);
    const cx = 192, cy = 198, rx = 122, ry = 146;
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    if (open < .12) {
      // Happy closed eye: a soft upside-down "U", like the reference stickers.
      ctx.strokeStyle = '#1c1714'; ctx.lineWidth = 12;
      ctx.beginPath(); ctx.moveTo(cx - rx + 14, cy + 30);
      ctx.quadraticCurveTo(cx, cy - 46, cx + rx - 14, cy + 30); ctx.stroke();
      texture.needsUpdate = true; return;
    }
    const apertureCy = cy + ry * (1 - open) * .55, apertureRy = ry * open;
    // No white sclera: the whole eye is a glossy dark-green iris, as in the reference.
    ctx.save(); ctx.beginPath(); ctx.ellipse(cx, apertureCy, rx, apertureRy, 0, 0, Math.PI * 2); ctx.clip();
    const ix = cx + shift, iy = cy;
    const iris = ctx.createRadialGradient(ix - 10, iy - 40, 8, ix, iy, 160);
    iris.addColorStop(0, '#010504'); iris.addColorStop(.5, '#03110b'); iris.addColorStop(.78, '#0a3020');
    iris.addColorStop(1, '#1b6f45');
    ctx.fillStyle = iris; ctx.fillRect(0, 0, 384, 384);
    const glow = ctx.createRadialGradient(ix, iy + 120, 4, ix, iy + 110, 120);
    glow.addColorStop(0, 'rgba(84,214,146,.55)'); glow.addColorStop(.55, 'rgba(46,160,104,.25)'); glow.addColorStop(1, 'rgba(46,160,104,0)');
    ctx.fillStyle = glow; ctx.fillRect(0, 0, 384, 384);
    ctx.fillStyle = '#ffffff';
    ctx.beginPath(); ctx.ellipse(ix - 36, iy - 56, 44, 38, -.25, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(ix + 46, iy + 44, 17, 0, Math.PI * 2); ctx.fill();
    ctx.globalAlpha = .9; ctx.beginPath(); ctx.arc(ix + 34, iy - 74, 10, 0, Math.PI * 2); ctx.fill(); ctx.globalAlpha = 1;
    ctx.restore();
    // Soft outline, slightly heavier along the top lid.
    ctx.strokeStyle = '#0d1a14'; ctx.lineWidth = 5;
    ctx.beginPath(); ctx.ellipse(cx, apertureCy, rx, apertureRy, 0, 0, Math.PI * 2); ctx.stroke();
    ctx.strokeStyle = '#15110f'; ctx.lineWidth = 10;
    ctx.beginPath(); ctx.ellipse(cx, apertureCy, rx, apertureRy, 0, Math.PI * 1.08, Math.PI * 1.92); ctx.stroke();
    // One tiny lash on the outer corner.
    ctx.lineWidth = 7; ctx.beginPath();
    const lashY = apertureCy - apertureRy * .45, lashX = rx * .9;
    if (side < 0) { ctx.moveTo(cx - lashX, lashY); ctx.quadraticCurveTo(cx - lashX - 18, lashY - 4, cx - lashX - 26, lashY - 22); }
    else { ctx.moveTo(cx + lashX, lashY); ctx.quadraticCurveTo(cx + lashX + 18, lashY - 4, cx + lashX + 26, lashY - 22); }
    ctx.stroke(); texture.needsUpdate = true;
  }
  update(); return { texture, update };
}

export function createBlushPaint() {
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 128;
  const ctx = canvas.getContext('2d'), gradient = ctx.createRadialGradient(64, 64, 7, 64, 64, 60);
  gradient.addColorStop(0, 'rgba(241,139,147,.60)');
  gradient.addColorStop(.5, 'rgba(245,162,166,.35)'); gradient.addColorStop(1, 'rgba(249,185,185,0)');
  ctx.fillStyle = gradient; ctx.fillRect(0, 0, 128, 128); return textureFor(canvas);
}

export function createHeadphoneBadge() {
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 128;
  const ctx = canvas.getContext('2d'); ctx.font = '600 100px "Segoe UI",sans-serif';
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#b8ffe0';
  ctx.shadowColor = '#3fe0a0'; ctx.shadowBlur = 18; ctx.fillText('S', 64, 64);
  return textureFor(canvas);
}

export function createChestLogo() {
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 256;
  const ctx = canvas.getContext('2d'); ctx.fillStyle = '#9fdcb3'; ctx.strokeStyle = '#9fdcb3';
  ctx.lineWidth = 12; ctx.lineCap = 'round';
  ctx.beginPath(); ctx.moveTo(128, 224); ctx.quadraticCurveTo(124, 170, 128, 128); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(124, 140); ctx.bezierCurveTo(70, 140, 30, 96, 34, 40);
  ctx.bezierCurveTo(92, 44, 128, 84, 124, 140); ctx.fill();
  ctx.beginPath(); ctx.moveTo(132, 140); ctx.bezierCurveTo(186, 140, 226, 96, 222, 40);
  ctx.bezierCurveTo(164, 44, 128, 84, 132, 140); ctx.fill();
  ctx.strokeStyle = '#164b37'; ctx.globalAlpha = .35; ctx.lineWidth = 4;
  ctx.beginPath(); ctx.moveTo(124, 136); ctx.lineTo(52, 56); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(132, 136); ctx.lineTo(204, 56); ctx.stroke();
  ctx.globalAlpha = 1; return textureFor(canvas);
}
