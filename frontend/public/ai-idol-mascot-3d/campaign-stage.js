import { pilotPose, span, smooth, cueAt, mouthAt, talkingMotion, actionPose } from './timeline.js';

// Speech window inside the audio. Older manifests have none: speech fills the clip.
export function speechWindow(manifest) {
  const start = Number(manifest.speech?.start), end = Number(manifest.speech?.end);
  return Number.isFinite(start) && Number.isFinite(end) && end > start
    ? { start, end } : { start: 0, end: manifest.duration };
}

// Cute talking gestures (all in the frontal plane so paws never pass through the
// hoodie). Arm signs: leftArm < 0 / rightArm > 0 lift outward; leftElbow < 0 /
// rightElbow > 0 fold the forearm up. Values are added on top of the rest pose.
const REST = { leftArm: 0, leftElbow: 0, rightArm: 0, rightElbow: 0, headTilt: 0, both: false };
const TALK_GESTURES = [
  REST,
  { leftArm: -1.35, leftElbow: -1.2, rightArm: 1.35, rightElbow: 1.2, headTilt: 0, both: true },   // paws up by the cheeks
  { leftArm: 0, leftElbow: 0, rightArm: .95, rightElbow: .70, headTilt: -.06, both: false },         // right paw presents
  { leftArm: -.95, leftElbow: -.70, rightArm: 0, rightElbow: 0, headTilt: .06, both: false },        // left paw presents
  { leftArm: 0, leftElbow: 0, rightArm: 1.70, rightElbow: 1.0, headTilt: -.05, both: false },       // right paw up, "nè!"
  { leftArm: -.80, leftElbow: -.40, rightArm: .80, rightElbow: .40, headTilt: 0, both: true },       // little "ta-da"
  { leftArm: -1.70, leftElbow: -1.0, rightArm: 0, rightElbow: 0, headTilt: .05, both: false },      // left paw up
];
const BEAT = 2.1;
function hashBeat(n) { const x = Math.sin(n * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); }
// Deterministic gesture per beat; never the same gesture twice in a row.
function gestureFor(beat, leftOnly) {
  const pool = leftOnly ? [0, 3, 6] : TALK_GESTURES.map((_, i) => i);
  let index = pool[Math.floor(hashBeat(beat) * pool.length)];
  if (beat > 0 && index !== 0 && index === gestureFor(beat - 1, leftOnly).index) index = pool[(pool.indexOf(index) + 1) % pool.length];
  return { index, pose: TALK_GESTURES[index] };
}

/** Flowing, varied paw gestures while Syna talks; blends beat-to-beat without snapping. */
export function talkGesture(time, cue, weight = 1) {
  const none = { leftArm: 0, leftElbow: 0, leftWrist: 0, rightArm: 0, rightElbow: 0, rightWrist: 0, headTilt: 0, bounce: 0 };
  if (!cue || weight <= 0) return none;
  const action = cue.action || inferTalkAction(cue.text);
  if (action !== 'talk' && action !== 'explain') return none;
  // While Syna points at the ingredient board, the right paw is busy: gesture with the left only.
  const leftOnly = (cue.ingredient ?? -1) >= 0 || action === 'explain';
  const beat = Math.floor(time / BEAT), local = time - beat * BEAT;
  const from = gestureFor(beat - 1, leftOnly).pose, to = gestureFor(beat, leftOnly).pose;
  const blend = smooth(local / .7);
  const fade = smooth((time - cue.start) / .75) * smooth((cue.end - time) / .75) * weight;
  const mix = (key) => (from[key] + (to[key] - from[key]) * blend) * fade;
  // Lively secondary motion: paws wiggle and forearms bob a little, never perfectly in sync.
  // Each paw only wiggles while it is actually lifted.
  const wl = Math.min(1, Math.abs(mix('leftArm')) / .5), wr = Math.min(1, Math.abs(mix('rightArm')) / .5);
  return {
    leftArm: mix('leftArm') + Math.sin(time * 4.1 + .3) * .05 * wl,
    leftElbow: mix('leftElbow') + Math.sin(time * 5.3 + 1.1) * .08 * wl,
    leftWrist: Math.sin(time * 6.2) * .22 * wl,
    rightArm: mix('rightArm') + Math.sin(time * 3.7 + 2.0) * .05 * wr,
    rightElbow: mix('rightElbow') + Math.sin(time * 4.9 + .4) * .08 * wr,
    rightWrist: Math.sin(time * 5.8 + 1.7) * .22 * wr,
    headTilt: mix('headTilt'),
    bounce: Math.abs(Math.sin(time * Math.PI / BEAT * 2)) * .012 * fade,
  };
}
function inferTalkAction(text) { return actionPose(0, { start: 0, end: 1, text }).action; }

export function campaignPose(time, manifest, envelope) {
  const mouth = mouthAt(envelope, time, time < manifest.duration);
  const speech = speechWindow(manifest);
  const pose = pilotPose(Math.min(time, 15), mouth);
  const cue = cueAt(manifest.cues, time);
  const active = cue?.ingredient ?? -1;
  const look = active >= 0 ? span(time, cue.start - .25, Math.min(cue.end + .3, cue.start + 4.5), .65) : 0;
  // Greet through the music lead-in and lower the arm just after the first words.
  const greetEnd = manifest.greet === false ? 0 : Math.min(Math.max(3.3, speech.start + .8), manifest.duration);
  const greet = manifest.greet === false ? 0 : span(time, .15, greetEnd, .7);
  // After the last word, wave goodbye until the clip ends (span stays up past the end).
  const farewell = speech.end < manifest.duration - .5 ? span(time, speech.end + .15, manifest.duration + 1, .6) : 0;
  const wave = Math.max(greet, farewell);
  const talking = talkingMotion(time, mouth);
  const action = actionPose(time, cue, mouth);
  // Talking gestures start after the greeting arm has settled, and yield to waves.
  const gesture = talkGesture(time, cue, smooth((time - greetEnd - 1.2) / .6) * (1 - wave));
  // Absolute audio time, not a looping 15-second greeting. Arms share the existing skin rig.
  let blink = 1;
  for (let n = 0, start = 1.7; start < manifest.duration; n++, start += 2.8 + ((n * 17) % 13) / 10) {
    if (time >= start && time < start + .2) blink = 1 - Math.sin(Math.PI * (time - start) / .2) ** 2;
  }
  return { ...pose, time, blink, active, board: true, highlight: active >= 0,
    headYaw: look * .55 + action.headYaw, torsoYaw: look * .14, gaze: look * .03 + action.gaze,
    headTilt: Math.sin(time * 1.1) * .025 + action.headTilt + gesture.headTilt, headNod: talking.headNod,
    torsoRoll: talking.torsoRoll, breath: Math.sin(time * 1.6) * .014 + gesture.bounce,
    leaf: Math.sin(time * 1.4 - .3) * .055, tail: Math.sin(time * 1.2 - .5) * .13,
    earSway: talking.earSway,
    leftArm: -.1 - wave * (2.05 + Math.sin(time * 5.2) * .1) + talking.leftArm + action.leftArm + gesture.leftArm,
    leftElbow: wave * .24 + talking.leftElbow + action.leftElbow + gesture.leftElbow,
    leftWrist: wave * Math.sin(time * 5.2 - .4) * .19 + talking.leftWrist + action.leftWrist + gesture.leftWrist,
    // No product-specific lift/point animation. The product card is a
    // reusable canvas layer; only the ingredient-board cue changes the pose.
    rightArm: .1 + look * 1.22 + talking.rightArm + action.rightArm + gesture.rightArm,
    rightElbow: -look * .17 + talking.rightElbow + action.rightElbow + gesture.rightElbow,
    rightWrist: -look * .13 + talking.rightWrist + action.rightWrist + gesture.rightWrist,
    leftFoot: talking.leftFoot, rightFoot: talking.rightFoot,
    point: action.point,
    pointTarget: action.pointTarget, action: action.action,
    sticker: action.sticker ?? (farewell > .35 ? 'heart' : null),
  };
}

function box(ctx, x, y, w, h, fill, radius = 18) {
  ctx.fillStyle = fill; ctx.beginPath(); ctx.roundRect(x, y, w, h, radius); ctx.fill();
}
function label(ctx, value, x, y, size = 18, color = '#284c3a', weight = 400) {
  ctx.fillStyle = color; ctx.font = `${weight} ${size}px "Segoe UI",sans-serif`; ctx.fillText(value, x, y);
}
function wrapLines(ctx, value, width, size = 22, max = 3, weight = 500) {
  ctx.font = `${weight} ${size}px "Segoe UI",sans-serif`;
  const result = []; let line = '';
  for (const word of String(value).split(/\s+/)) {
    if (line && ctx.measureText(`${line} ${word}`).width > width) { result.push(line); line = word; }
    else line = line ? `${line} ${word}` : word;
  }
  if (line) result.push(line);
  return result.slice(0, max).map((item, i) => (i === max - 1 && result.length > max ? item.slice(0, -3) + '…' : item));
}
function lines(ctx, value, x, y, width, size = 22, max = 3, weight = 500) {
  wrapLines(ctx, value, width, size, max, weight).forEach((item, i) => {
    label(ctx, item, x, y + i * (size + 7), size, '#284c3a', weight);
  });
}
export function captionBaselines(lineCount, centerY, size = 22, gap = 7) {
  const block = lineCount * size + (lineCount - 1) * gap, top = centerY - block / 2;
  return Array.from({ length: lineCount }, (_, i) => top + size * .8 + i * (size + gap));
}
export function drawCaption(ctx, text) {
  box(ctx, 35, 625, 1210, 77, 'rgba(255,254,249,.97)', 14);
  const rows = wrapLines(ctx, text, 1158, 22, 2, 500), ys = captionBaselines(rows.length, 663.5, 22);
  ctx.save(); ctx.textAlign = 'center';
  rows.forEach((item, i) => label(ctx, item, 640, ys[i], 22, '#284c3a', 500));
  ctx.restore();
}
function fitted(ctx, image, x, y, w, h, cover = false) {
  const scale = (cover ? Math.max : Math.min)(w / image.width, h / image.height);
  ctx.save(); ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
  ctx.drawImage(image, x + (w - image.width * scale) / 2, y + (h - image.height * scale) / 2, image.width * scale, image.height * scale);
  ctx.restore();
}

function drawSticker(ctx, type, time) {
  if (!type) return;
  const pulse = 1 + Math.sin(time * 4.2) * .06;
  ctx.save(); ctx.translate(type === 'sparkle' ? 277 : 661, type === 'sparkle' ? 535 : 235);
  ctx.scale(pulse, pulse);
  if (type === 'heart') {
    ctx.fillStyle = '#ed8f9b'; ctx.beginPath(); ctx.moveTo(0, 18);
    ctx.bezierCurveTo(-34, -5, -26, -28, -9, -25); ctx.bezierCurveTo(0, -23, 0, -12, 0, -12);
    ctx.bezierCurveTo(0, -12, 0, -23, 9, -25); ctx.bezierCurveTo(26, -28, 34, -5, 0, 18); ctx.fill();
  } else if (type === 'question') {
    ctx.fillStyle = '#6d9b63'; ctx.font = '700 34px "Segoe UI",sans-serif'; ctx.fillText('?', 0, 0);
  } else {
    ctx.fillStyle = '#d9b45e'; ctx.beginPath();
    ctx.moveTo(0,-25); ctx.lineTo(6,-6); ctx.lineTo(25,0); ctx.lineTo(6,6);
    ctx.lineTo(0,25); ctx.lineTo(-6,6); ctx.lineTo(-25,0); ctx.lineTo(-6,-6); ctx.closePath(); ctx.fill();
    ctx.fillStyle = '#fff4c4'; ctx.beginPath(); ctx.arc(0,0,5,0,Math.PI*2); ctx.fill();
  }
  ctx.restore();
}

export function drawCampaign(ctx, scene, manifest, images, time, pose) {
  ctx.clearRect(0, 0, 1280, 720);
  ctx.fillStyle = '#edf2e8'; ctx.fillRect(0, 0, 1280, 720);
  if (images.background) {
    fitted(ctx, images.background, 0, 0, 1280, 720, true);
    ctx.fillStyle = 'rgba(250,250,240,.30)'; ctx.fillRect(0, 0, 1280, 720);
  }
  box(ctx, 24, 22, 1232, 64, 'rgba(255,254,249,.94)');
  label(ctx, 'SkinSyntax', 45, 62, 24, '#244b35', 650);
  label(ctx, 'Understand. Care. Glow.', 220, 60, 17, '#63745c');
  label(ctx, 'SYNA · GIỚI THIỆU SẢN PHẨM', 922, 60, 15, '#63745c', 600);
  box(ctx, 26, 146, 290, 458, '#fffefa');
  label(ctx, 'ĐANG GIỚI THIỆU', 45, 179, 13, '#788370', 600);
  box(ctx, 44, 196, 254, 189, '#f1f4ec');
  if (images.product) fitted(ctx, images.product, 52, 204, 238, 173);
  else label(ctx, 'Chưa có ảnh sản phẩm', 63, 295, 17, '#788370');
  lines(ctx, manifest.product.name, 45, 418, 250, 21, 4, 600);
  const price = manifest.product.price;
  // price_from: null = never show a price (evergreen product video); a number = show from that second.
  const priceFrom = manifest.product.price_from === undefined ? 0 : manifest.product.price_from;
  if (priceFrom !== null && time >= priceFrom) {
    label(ctx, price > 0 ? new Intl.NumberFormat('vi-VN').format(price) + ' đ' : 'Giá đang cập nhật', 45, 567, price > 0 ? 29 : 20, '#244b35', 650);
  }
  box(ctx, 772, 146, 476, 458, '#fffdf5');
  label(ctx, 'BẢNG GHI CHÚ', 796, 181, 13, '#788370', 600);
  label(ctx, 'Thành phần chính', 796, 222, 28, '#284c3a', 650);
  manifest.ingredients.forEach((item, index) => {
    const top = 246 + index * 97, active = pose.active === index;
    box(ctx, 794, top, 432, 85, active ? '#dceccb' : '#f0f3e8', 13);
    label(ctx, String(index + 1).padStart(2, '0'), 809, top + 30, 15, '#678057', 600);
    lines(ctx, item, 847, top + 32, 355, 21, 2, active ? 650 : 500);
  });
  if (!manifest.ingredients.length) lines(ctx, 'Chưa có dữ liệu thành phần trong hồ sơ sản phẩm.', 797, 294, 406, 22, 3);
  label(ctx, 'Theo hồ sơ sản phẩm · không tự suy diễn công dụng', 796, 582, 13, '#788370');
  ctx.drawImage(scene.renderer.domElement, 0, 0);
  drawSticker(ctx, pose.sticker, time);
  const caption = cueAt(manifest.cues, time)?.text;
  if (caption) drawCaption(ctx, caption);
}
