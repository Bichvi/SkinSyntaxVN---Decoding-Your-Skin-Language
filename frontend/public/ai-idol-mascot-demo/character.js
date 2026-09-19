import { clamp, smooth } from './motion.js?v=6.1';

export const CHARACTER_LAYOUT = { x: 550, y: 584, scale: 0.82, shoulderX: 77, shoulderY: -187, pointerX: 782 };

const PARTS = {
  head: [38, 22, 511, 495], body: [625, 174, 366, 384],
  leftArm: [1164, 145, 218, 391], rightArm: [165, 584, 216, 392],
  tail: [624, 624, 339, 322], leftEye: [1075, 708, 170, 148], rightEye: [1314, 708, 171, 148],
};

/** Import the generated magenta-key atlas once. No per-frame raster masking. */
function importAtlas(image) {
  const canvas = document.createElement('canvas');
  canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(image, 0, 0);
  const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height);
  for (let i = 0; i < pixels.data.length; i += 4) {
    const r = pixels.data[i], g = pixels.data[i + 1], b = pixels.data[i + 2];
    const key = clamp((Math.min(r, b) - g - 60) / 110);
    pixels.data[i + 3] = Math.round(255 * (1 - key));
    if (key > 0 && key < 1) {
      pixels.data[i] = r - (r - g) * key * 0.65;
      pixels.data[i + 2] = b - (b - g) * key * 0.65;
    }
  }
  ctx.putImageData(pixels, 0, 0);
  return canvas;
}

export function drawCentellaLeaf(ctx, x, y, scale = 1, rotation = 0) {
  ctx.save(); ctx.translate(x, y); ctx.rotate(rotation); ctx.scale(scale, scale);
  // Basal notch at (0,0), a broad fan, many rounded scallops, radiating veins.
  // The upper edge stays round: no split into two heart/flower petals.
  const color = ctx.createLinearGradient(-20, -65, 35, 10);
  color.addColorStop(0, '#a6ce70'); color.addColorStop(0.48, '#7db957'); color.addColorStop(1, '#529441');
  ctx.fillStyle = color; ctx.strokeStyle = '#467f39'; ctx.lineWidth = 1.3;
  ctx.beginPath(); ctx.moveTo(0, 0);
  ctx.bezierCurveTo(-15, -2, -22, 20, -35, 16);
  ctx.bezierCurveTo(-44, 16, -44, 6, -48, 2);
  ctx.bezierCurveTo(-60, 0, -57, -9, -57, -16);
  ctx.bezierCurveTo(-65, -26, -59, -34, -54, -37);
  ctx.bezierCurveTo(-58, -49, -50, -53, -42, -55);
  ctx.bezierCurveTo(-40, -65, -30, -65, -24, -64);
  ctx.bezierCurveTo(-17, -74, -8, -71, -3, -67);
  ctx.bezierCurveTo(6, -74, 15, -70, 20, -65);
  ctx.bezierCurveTo(31, -69, 39, -60, 40, -54);
  ctx.bezierCurveTo(52, -55, 57, -45, 55, -39);
  ctx.bezierCurveTo(66, -33, 62, -22, 57, -18);
  ctx.bezierCurveTo(62, -6, 54, 1, 47, 2);
  ctx.bezierCurveTo(46, 12, 36, 14, 27, 8);
  ctx.bezierCurveTo(13, 4, 9, -1, 0, 0); ctx.closePath(); ctx.fill(); ctx.stroke();
  ctx.strokeStyle = 'rgba(51,110,39,.68)'; ctx.lineWidth = 1.7; ctx.lineCap = 'round';
  const ends = [[-45,3],[-51,-22],[-39,-48],[-17,-60],[7,-61],[31,-52],[47,-32],[48,-9]];
  for (const [vx, vy] of ends) {
    ctx.beginPath(); ctx.moveTo(0, 0);
    ctx.quadraticCurveTo(vx * 0.45, vy * 0.28, vx, vy); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(vx * 0.56, vy * 0.4);
    ctx.quadraticCurveTo(vx * 0.86 + 4, vy * 0.43 - 6, vx * 0.95 + 3, vy * 0.7 - 6); ctx.stroke();
  }
  ctx.restore();
}

function heart(ctx, x, y, size, color = '#ed8d94') {
  ctx.save(); ctx.translate(x, y); ctx.scale(size / 50, size / 50);
  ctx.fillStyle = color; ctx.beginPath(); ctx.moveTo(0, 19);
  ctx.bezierCurveTo(-45, -8, -22, -36, 0, -15);
  ctx.bezierCurveTo(22, -36, 45, -8, 0, 19); ctx.fill(); ctx.restore();
}

export class SynaCharacter {
  constructor(ctx, image) {
    this.ctx = ctx; this.atlas = importAtlas(image); this.armTextures = {};
    for (const name of ['leftArm', 'rightArm']) {
      const [x, y, w, h] = PARTS[name], texture = document.createElement('canvas');
      texture.width = w; texture.height = h;
      texture.getContext('2d').drawImage(this.atlas, x, y, w, h, 0, 0, w, h);
      this.armTextures[name] = texture;
    }
  }

  sprite(name, x, y, width, height) {
    this.ctx.drawImage(this.atlas, ...PARTS[name], x, y, width, height);
  }

  triangle(texture, uv, points) {
    const ctx = this.ctx, [p, q, r] = points, [a, b, c] = uv;
    const du1 = b[0] - a[0], dv1 = b[1] - a[1], du2 = c[0] - a[0], dv2 = c[1] - a[1];
    const determinant = du1 * dv2 - du2 * dv1;
    const ma = ((q[0] - p[0]) * dv2 - (r[0] - p[0]) * dv1) / determinant;
    const mb = ((q[1] - p[1]) * dv2 - (r[1] - p[1]) * dv1) / determinant;
    const mc = ((r[0] - p[0]) * du1 - (q[0] - p[0]) * du2) / determinant;
    const md = ((r[1] - p[1]) * du1 - (q[1] - p[1]) * du2) / determinant;
    const center = [(p[0] + q[0] + r[0]) / 3, (p[1] + q[1] + r[1]) / 3];
    ctx.save(); ctx.beginPath();
    points.forEach(([x, y], index) => {
      // Expand the thin triangles proportionally. A fixed radial pixel amount
      // barely expands their short axis and leaves visible horizontal seams.
      const px = center[0] + (x - center[0]) * 1.16;
      const py = center[1] + (y - center[1]) * 1.16;
      if (index === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
    });
    ctx.closePath(); ctx.clip();
    ctx.transform(ma, mb, mc, md, p[0] - ma * a[0] - mc * a[1], p[1] - mb * a[0] - md * a[1]);
    ctx.drawImage(texture, 0, 0); ctx.restore();
  }

  arm(name, shoulderX, shoulderY, angle, bend) {
    const ctx = this.ctx, texture = this.armTextures[name], sw = texture.width, sh = texture.height;
    ctx.save(); ctx.translate(shoulderX, shoulderY); ctx.rotate(angle);
    // Hide the atlas's detached end cap; a continuous shoulder socket joins
    // the sleeve to the torso before this arm is drawn at any rotation.
    const sleeve = ctx.createLinearGradient(-23, 0, 23, 36);
    sleeve.addColorStop(0, '#205337'); sleeve.addColorStop(0.6, '#184a30'); sleeve.addColorStop(1, '#113e28');
    ctx.fillStyle = sleeve; ctx.beginPath(); ctx.roundRect(-23, -7, 46, 61, 22); ctx.fill();
    ctx.beginPath(); ctx.rect(-95, 22, 190, 145); ctx.clip();
    // Shared mesh vertices keep elbow texture continuous even during a hug.
    const height = 143, rows = 16, vertices = [];
    for (let i = 0; i <= rows; i += 1) {
      const t = i / rows, afterElbow = Math.max(0, (t - 0.3) / 0.7);
      const x = -bend * afterElbow ** 2 * 46;
      const tangent = -Math.atan(-bend * afterElbow * 92 / (height * 0.7));
      vertices.push([[-40 * Math.cos(tangent) + x, t * height - 18 - 40 * Math.sin(tangent)],
        [40 * Math.cos(tangent) + x, t * height - 18 + 40 * Math.sin(tangent)]]);
    }
    for (let i = 0; i < rows; i += 1) {
      const v0 = i / rows * sh, v1 = (i + 1) / rows * sh;
      this.triangle(texture, [[0,v0],[sw,v0],[sw,v1]], [vertices[i][0],vertices[i][1],vertices[i+1][1]]);
      this.triangle(texture, [[0,v0],[sw,v1],[0,v1]], [vertices[i][0],vertices[i+1][1],vertices[i+1][0]]);
    }
    ctx.restore();
  }

  shoulder(x, y) {
    const ctx = this.ctx, fill = ctx.createLinearGradient(x - 27, y - 18, x + 24, y + 24);
    fill.addColorStop(0, '#23583b'); fill.addColorStop(0.55, '#16492e'); fill.addColorStop(1, '#0d3523');
    ctx.save(); ctx.fillStyle = fill;
    ctx.beginPath(); ctx.ellipse(x, y + 6, 20, 23, 0, 0, Math.PI * 2); ctx.fill(); ctx.restore();
  }

  neck(pose) {
    const ctx = this.ctx, topX = pose.headX, topY = -226 + pose.headY;
    const tilt = Math.sin(pose.head) * 29;
    const color = ctx.createLinearGradient(0, topY, 0, -207);
    color.addColorStop(0, '#eacbbc'); color.addColorStop(0.48, '#fff0e4'); color.addColorStop(1, '#efcebb');
    ctx.fillStyle = color; ctx.beginPath(); ctx.moveTo(topX - 28, topY - tilt - 8);
    ctx.quadraticCurveTo(-21, -213, -35, -206);
    ctx.quadraticCurveTo(0, -193, 35, -206);
    ctx.quadraticCurveTo(21, -213, topX + 28, topY + tilt - 8);
    ctx.closePath(); ctx.fill();
  }

  plant(sway) {
    const ctx = this.ctx;
    ctx.save(); ctx.translate(1, -252); ctx.rotate(sway);
    ctx.strokeStyle = '#508e3e'; ctx.lineWidth = 3; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(0, 3); ctx.quadraticCurveTo(-7, -16, -14, -38); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(2, 3); ctx.quadraticCurveTo(10, -11, 24, -20); ctx.stroke();
    drawCentellaLeaf(ctx, -14, -38, 0.73, -0.13);
    drawCentellaLeaf(ctx, 24, -20, 0.43, 0.36);
    ctx.restore();
  }

  face(pose, mouth, blink, expression) {
    const ctx = this.ctx, amount = expression.amount, name = expression.name;
    const happy = name === 'happy' ? amount : 0;
    const sad = name === 'sad' ? amount : 0;
    const surprise = name === 'surprised' ? amount : 0;
    const thinking = name === 'thinking' ? amount : 0;
    const confident = name === 'confident' ? amount : 0;
    for (const [side, x] of [['left', -66], ['right', 66]]) {
      // "Left" and "right" mean the character's own left/right, as on a face.
      const wink = (side === 'right' && name === 'winkLeft') || (side === 'left' && name === 'winkRight');
      const closure = Math.max(1 - blink, happy, wink ? amount : 0, side === 'right' ? confident : 0);
      const eyeHeight = (1 - 0.92 * closure) * (1 - sad * 0.23);
      ctx.save(); ctx.translate(x + pose.gaze + thinking * 3, -147 + pose.gazeY + thinking * -2);
      ctx.scale(1 + surprise * 0.06, eyeHeight);
      ctx.globalAlpha = 1 - smooth((closure - 0.7) / 0.3);
      this.sprite(side === 'left' ? 'leftEye' : 'rightEye', -42, -35, 84, 70); ctx.restore();
      if (closure > 0.5) {
        ctx.save(); ctx.globalAlpha = smooth((closure - 0.5) / 0.5);
        ctx.strokeStyle = '#264539'; ctx.lineWidth = 4.8; ctx.lineCap = 'round';
        ctx.beginPath(); ctx.moveTo(x - 25, -143);
        ctx.quadraticCurveTo(x, happy || wink || confident ? -167 : -135, x + 25, -143); ctx.stroke(); ctx.restore();
      }
      if (sad + surprise + thinking + confident > 0) {
        ctx.save(); ctx.globalAlpha = Math.max(sad, surprise, thinking, confident) * 0.68;
        ctx.strokeStyle = '#48654b'; ctx.lineWidth = 3.5; ctx.lineCap = 'round';
        const inner = side === 'left' ? 1 : -1;
        const y = -194 - surprise * 7;
        ctx.beginPath(); ctx.moveTo(x - 20 * inner, y + sad * 7);
        ctx.quadraticCurveTo(x, y - thinking * 6, x + 19 * inner, y - sad * 5); ctx.stroke(); ctx.restore();
      }
    }
    this.mouth(mouth, { happy, sad, surprise });
    if (happy + confident > 0) {
      ctx.save(); ctx.globalAlpha = (happy + confident) * 0.11; ctx.fillStyle = '#ea8089';
      for (const x of [-106, 106]) { ctx.beginPath(); ctx.ellipse(x, -75, 24, 12, 0, 0, Math.PI * 2); ctx.fill(); }
      ctx.restore();
    }
  }

  mouth(open, { happy, sad, surprise }) {
    const ctx = this.ctx; ctx.save(); ctx.translate(2, -65);
    ctx.strokeStyle = '#704640'; ctx.fillStyle = '#6e353d'; ctx.lineWidth = 2.8; ctx.lineCap = 'round';
    if (open < 0.05) {
      ctx.beginPath(); ctx.moveTo(-13, -3);
      ctx.quadraticCurveTo(-6, 6 + happy * 3 - sad * 8, 0, 0);
      ctx.quadraticCurveTo(6, 6 + happy * 3 - sad * 8, 13, -3); ctx.stroke();
    } else {
      const width = 13 + open * 6 + happy * 2 - surprise * 4;
      const height = (6 + open * 20) * (1 - sad * 0.4) + surprise * 8;
      ctx.beginPath(); ctx.moveTo(-width, -3);
      ctx.quadraticCurveTo(0, -1 + happy * 4 - surprise * 14 - sad * 7, width, -3);
      ctx.bezierCurveTo(width, height, -width, height, -width, -3); ctx.fill(); ctx.stroke();
      ctx.save(); ctx.clip(); ctx.fillStyle = '#f1a4a5'; ctx.beginPath();
      ctx.ellipse(0, height - 3, width * 0.72, height * 0.36, 0, 0, Math.PI * 2); ctx.fill(); ctx.restore();
    }
    ctx.restore();
  }

  prop(pose) {
    const ctx = this.ctx;
    ctx.save(); ctx.globalAlpha = pose.gestureStrength;
    ctx.font = '600 16px "Segoe UI",sans-serif'; ctx.textAlign = 'center';
    if (pose.gesture === 'happy') {
      heart(ctx, 0, -79, 88);
    } else if (pose.gesture === 'processing') {
      ctx.fillStyle = '#7d8d86'; ctx.beginPath(); ctx.roundRect(-97, -110, 194, 92, 9); ctx.fill();
      ctx.fillStyle = '#c6d2c8'; ctx.fillRect(-111, -21, 222, 7);
      drawCentellaLeaf(ctx, 0, -55, 0.28);
    } else if (pose.gesture === 'sale') {
      ctx.save(); ctx.translate(-154, -124); ctx.rotate(-0.12);
      ctx.fillStyle = '#e79299'; ctx.beginPath(); ctx.roundRect(-68, -22, 136, 49, 8); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.fillText('ƯU ĐÃI', 0, -2); ctx.font = '11px "Segoe UI",sans-serif'; ctx.fillText('BẢNG MẪU', 0, 16); ctx.restore();
    } else if (pose.gesture === 'explaining') {
      ctx.fillStyle = '#fffdf5'; ctx.beginPath(); ctx.roundRect(90, -113, 116, 95, 9); ctx.fill();
      drawCentellaLeaf(ctx, 118, -73, 0.25);
      ctx.strokeStyle = '#8cab8a'; ctx.lineWidth = 4;
      for (let i = 0; i < 3; i += 1) { ctx.beginPath(); ctx.moveTo(146, -91 + i * 19); ctx.lineTo(188, -91 + i * 19); ctx.stroke(); }
    }
    ctx.restore();
  }

  draw(pose, mouth, blink, expression) {
    const ctx = this.ctx;
    const layout = CHARACTER_LAYOUT;
    ctx.save(); ctx.translate(layout.x, layout.y); ctx.scale(layout.scale, layout.scale);
    ctx.fillStyle = 'rgba(28,65,44,.10)'; ctx.beginPath(); ctx.ellipse(0, 45, 136, 16, 0, 0, Math.PI * 2); ctx.fill();
    ctx.save(); ctx.translate(82, -47); ctx.rotate(pose.tail); this.sprite('tail', 0, -100, 153, 145); ctx.restore();
    ctx.save(); ctx.translate(0, pose.breath); ctx.rotate(pose.body);
    this.shoulder(-layout.shoulderX, layout.shoulderY + pose.shoulder);
    this.shoulder(layout.shoulderX, layout.shoulderY - pose.shoulder);
    // The source torso has empty sleeve stubs. A silhouette clip lets the
    // animated shoulders meet the hoodie without displaying a second sleeve.
    ctx.save(); ctx.beginPath(); ctx.moveTo(-99, -231); ctx.lineTo(99, -231);
    ctx.bezierCurveTo(82, -191, 64, -172, 80, -137);
    ctx.lineTo(118, -48); ctx.lineTo(104, 50); ctx.lineTo(-104, 50); ctx.lineTo(-118, -48);
    ctx.bezierCurveTo(-74, -128, -64, -172, -99, -231); ctx.closePath(); ctx.clip();
    this.sprite('body', -128, -225, 256, 269); ctx.restore();
    this.neck(pose);
    // The chin, headset, face and Centella all share this local head pivot.
    ctx.save(); ctx.translate(pose.headX, -226 + pose.headY); ctx.rotate(pose.head);
    // A gentle 2D three-quarter suggestion, with chin pivot fixed to the neck.
    // No image switching: headset, muzzle, eyes and plant turn together.
    const turn = pose.boardLook ?? 0;
    ctx.transform(1 - turn * 0.06, 0, -turn * 0.035, 1, 0, 0);
    this.sprite('head', -190, -368, 380, 368);
    this.plant(pose.plant); this.face(pose, mouth, blink, expression); ctx.restore();
    if (pose.previousProp) this.prop(pose.previousProp);
    this.prop(pose);
    if (turn > 0.01) this.pointer(pose);
    this.arm('leftArm', -layout.shoulderX, layout.shoulderY + pose.shoulder, pose.leftArm, pose.leftBend);
    this.arm('rightArm', layout.shoulderX, layout.shoulderY - pose.shoulder, pose.rightArm, pose.rightBend);
    ctx.restore(); ctx.restore();
  }

  pointer(pose) {
    const ctx = this.ctx, angle = pose.rightArm, layout = CHARACTER_LAYOUT;
    // The same mesh coordinates as the paw, so the pointer stays in its grip.
    const x = -pose.rightBend * 46, y = 114;
    const gripX = layout.shoulderX + x * Math.cos(angle) - y * Math.sin(angle);
    const gripY = layout.shoulderY - pose.shoulder + x * Math.sin(angle) + y * Math.cos(angle);
    // Convert board target back through root/body transforms into this frame.
    const tx = (layout.pointerX - layout.x) / layout.scale;
    const ty = (pose.boardTargetY - layout.y) / layout.scale - pose.breath;
    const targetX = tx * Math.cos(pose.body) + ty * Math.sin(pose.body);
    const targetY = -tx * Math.sin(pose.body) + ty * Math.cos(pose.body);
    ctx.save(); ctx.globalAlpha = smooth((pose.boardLook - 0.3) / 0.7);
    ctx.lineCap = 'round'; ctx.strokeStyle = '#55715b'; ctx.lineWidth = 3.5;
    ctx.beginPath(); ctx.moveTo(gripX, gripY); ctx.lineTo(targetX, targetY); ctx.stroke();
    ctx.fillStyle = '#dfb864'; ctx.beginPath(); ctx.arc(targetX, targetY, 6, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
  }

  sticker(sticker) {
    if (!sticker) return;
    const ctx = this.ctx; ctx.save(); ctx.translate(723, 115 - sticker.rise); ctx.globalAlpha = sticker.opacity;
    ctx.rotate(-0.07); ctx.scale(0.94 + sticker.opacity * 0.06, 0.94 + sticker.opacity * 0.06);
    if (sticker.name === 'heart') heart(ctx, 0, 0, 40);
    else if (sticker.name === 'leaf') drawCentellaLeaf(ctx, 0, 12, 0.48, 0.14);
    else if (sticker.name === 'sparkle') {
      ctx.fillStyle = '#ddbb5c';
      for (const [x, y, s] of [[0, 0, 23], [28, 17, 12], [-25, 22, 8]]) {
        ctx.beginPath(); ctx.moveTo(x, y - s); ctx.quadraticCurveTo(x + 2, y - 2, x + s * 0.6, y);
        ctx.quadraticCurveTo(x + 2, y + 2, x, y + s); ctx.quadraticCurveTo(x - 2, y + 2, x - s * 0.6, y);
        ctx.quadraticCurveTo(x - 2, y - 2, x, y - s); ctx.fill();
      }
    } else {
      ctx.fillStyle = '#24513e'; ctx.beginPath(); ctx.roundRect(-35, -25, 70, 48, 23); ctx.fill();
      ctx.beginPath(); ctx.moveTo(-15, 18); ctx.lineTo(-20, 33); ctx.lineTo(2, 20); ctx.fill();
      ctx.fillStyle = '#fffaf0'; ctx.font = '600 25px "Segoe UI",sans-serif'; ctx.textAlign = 'center';
      ctx.fillText(sticker.name === 'hello' ? 'Hi!' : sticker.name === 'question' ? '?' : '···', 0, 8);
    }
    ctx.restore();
  }
}
