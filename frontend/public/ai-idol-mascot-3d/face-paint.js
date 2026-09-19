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
    const top = 141 - 89 * open;
    const aperture = new Path2D(); aperture.moveTo(52, 212);
    aperture.bezierCurveTo(72, top, 290, top - 17 * open, 325, 213);
    aperture.bezierCurveTo(290, 141 + 194 * open, 72, 141 + 208 * open, 52, 212);
    ctx.save(); ctx.clip(aperture);
    ctx.fillStyle = '#fffaf3'; ctx.fillRect(0, 0, 384, 384);
    const ix = 195 + shift - side * 12;
    const iris = ctx.createRadialGradient(ix - 17, 149, 12, ix, 225, 139);
    iris.addColorStop(0, '#071e18'); iris.addColorStop(.55, '#0a281d');
    iris.addColorStop(.84, '#235d3d'); iris.addColorStop(1, '#488b5c');
    ctx.fillStyle = iris; ctx.beginPath(); ctx.ellipse(ix, 215, 111, 135, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#fffdf7'; ctx.beginPath(); ctx.arc(ix - 34, 133, 17, 0, Math.PI * 2); ctx.fill();
    ctx.globalAlpha = .42; ctx.beginPath(); ctx.arc(ix + 45, 288, 6, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
    ctx.strokeStyle = '#25211e'; ctx.lineWidth = 10; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(52, 212);
    ctx.bezierCurveTo(72, top, 290, top - 17 * open, 325, 213); ctx.stroke();
    // One delicate outward lash, on the character's outer eye corner only.
    ctx.beginPath();
    if (side < 0) { ctx.moveTo(56, 203); ctx.quadraticCurveTo(43, 205, 34, 190); }
    else { ctx.moveTo(320, 204); ctx.quadraticCurveTo(337, 207, 348, 194); }
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
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#9fffcf';
  ctx.shadowColor = '#48d69d'; ctx.shadowBlur = 12; ctx.fillText('S', 64, 64);
  return textureFor(canvas);
}
