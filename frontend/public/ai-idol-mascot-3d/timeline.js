export const DURATION = 15;
export const clamp = (value, low = 0, high = 1) => Math.max(low, Math.min(high, value));
export const smooth = (value) => { const t = clamp(value); return t ** 3 * (10 + t * (-15 + 6 * t)); };
export const span = (time, start, end, fade = 0.7) => smooth((time - start) / fade) * smooth((end - time) / fade);

export function armWeights(distance) {
  const elbow = smooth((distance - 0.18) / 0.26);
  const wrist = smooth((distance - 0.56) / 0.15);
  return [1 - elbow, elbow * (1 - wrist), elbow * wrist, 0];
}

// Speech-driven micro motion keeps Syna alive without tying an animation to a
// particular product image. The audio amplitude only nudges the limbs; the
// slower breathing/idle motion remains present during pauses.
export function speakingLevel(mouth, reduced = false) {
  if (reduced) return 0;
  return clamp((Number(mouth) - .035) / .22);
}

export function talkingMotion(time, mouth = 0, reduced = false) {
  const level = speakingLevel(mouth, reduced);
  if (reduced) return { level: 0, torsoRoll: 0, headNod: 0, earSway: 0,
    leftArm: 0, leftElbow: 0, leftWrist: 0, rightArm: 0, rightElbow: 0,
    rightWrist: 0, leftFoot: 0, rightFoot: 0 };
  const phrase = Math.sin(time * 1.17 + .55);
  const counter = Math.sin(time * 1.43 + 2.1);
  return {
    level,
    torsoRoll: Math.sin(time * .73 + .4) * (.008 + level * .012),
    headNod: Math.sin(time * .61 + 1.2) * (.006 + level * .014),
    earSway: Math.sin(time * 1.29 - .3) * (.018 + level * .012),
    leftArm: level * (.060 * phrase + .025 * counter),
    leftElbow: level * (.070 + .035 * Math.sin(time * .89)),
    leftWrist: level * .090 * Math.sin(time * 1.52 + .7),
    rightArm: level * (.045 * counter - .018 * phrase),
    rightElbow: level * (.055 + .025 * Math.sin(time * .97 + .8)),
    rightWrist: level * .075 * Math.sin(time * 1.36 - .9),
    leftFoot: level * .022 * Math.sin(time * 1.08),
    rightFoot: level * .022 * Math.sin(time * 1.08 + Math.PI),
  };
}

function cueText(value) {
  return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

// Keep the product card generic: only language cues decide whether Syna
// explains, reacts to a price, or closes with a CTA. There is deliberately no
// "hold this product" action because every product image has a different shape.
export function inferAction(text) {
  const value = cueText(text);
  if (/quay lai|nhin len bang|xem bang|nhin sang bang|nhin camera/.test(value)) return 'transition';
  if (/mua|dat hang|chot don|gio hang|them vao|nhan vao|uu dai/.test(value)) return 'cta';
  if (/gia|khuyen mai|giam gia|giam con|dong|nghin|voucher/.test(value)) return 'price';
  if (/phan van|suy nghi|de minh|hmm|chua biet|can nhac/.test(value)) return 'thinking';
  if (/lam diu|phuc hoi|chua lanh|loi ich|cong dung|ho tro|giup/.test(value)) return 'benefit';
  if (/bang|thanh phan|giai thich|dong so|xem dong|tiep theo/.test(value)) return 'explain';
  return 'talk';
}

// Small, reusable gestures are layered on top of speech motion. Values are
// additive so the continuous arm surface never detaches at the shoulder.
export function actionPose(time, cue, mouth = 0, reduced = false) {
  const action = cue?.action || inferAction(cue?.text);
  const neutral = { action, sticker: null, point: 0, pointTarget: 'board',
    leftArm: 0, leftElbow: 0, leftWrist: 0, rightArm: 0, rightElbow: 0,
    rightWrist: 0, headYaw: 0, headTilt: 0, gaze: 0 };
  if (!cue || reduced) return neutral;
  const duration = Math.max(.25, Number(cue.end) - Number(cue.start));
  const fade = Math.min(.55, Math.max(.16, duration / 3));
  const strength = smooth((time - cue.start) / fade) * smooth((cue.end - time) / fade);
  const sway = Math.sin(time * 1.55 + .6) * strength;
  if (action === 'explain') {
    neutral.rightArm = .30 * strength; neutral.rightElbow = -.12 * strength;
    neutral.rightWrist = .09 * sway;
  } else if (action === 'benefit') {
    neutral.leftArm = -.58 * strength; neutral.leftElbow = .28 * strength;
    neutral.leftWrist = -.11 * sway; neutral.headTilt = -.018 * strength;
  } else if (action === 'price') {
    neutral.rightArm = .68 * strength; neutral.rightElbow = -.18 * strength;
    neutral.rightWrist = -.08 * sway; neutral.point = .72 * strength;
    neutral.pointTarget = 'price'; neutral.headYaw = -.12 * strength;
    neutral.gaze = -.025 * strength; neutral.sticker = 'sparkle';
  } else if (action === 'cta') {
    neutral.leftArm = -.72 * strength; neutral.leftElbow = .34 * strength;
    neutral.rightArm = .72 * strength; neutral.rightElbow = -.34 * strength;
    neutral.leftWrist = .12 * sway; neutral.rightWrist = -.12 * sway;
    neutral.sticker = 'heart';
  } else if (action === 'thinking') {
    neutral.headYaw = -.20 * strength; neutral.headTilt = .055 * strength;
    neutral.gaze = -.035 * strength; neutral.sticker = 'question';
  }
  return neutral;
}

export function pilotPose(time, mouth = 0, reduced = false) {
  const t = clamp(Number.isFinite(time) ? time : 0, 0, DURATION);
  const wave = span(t, 0.15, 3.35);
  const point = span(t, 4.55, 8.9, 0.85);
  const look = span(t, 3.9, 9.15, 1.05);
  const returnSmile = span(t, 10, 14.8);
  const talking = talkingMotion(t, mouth, reduced);
  let blink = 1;
  for (const start of [1.7, 4.12, 7.63, 11.38, 13.88]) {
    if (t >= start && t < start + 0.2) blink = 1 - Math.sin(Math.PI * (t - start) / 0.2) ** 2;
  }
  return {
    time: t, phase: t < 3.8 ? 'Chào bạn' : t < 4.8 ? 'Quay sang bảng' : t < 8.3 ? 'Giới thiệu thành phần' : t < 10 ? 'Quay lại với bạn' : 'Cùng trò chuyện',
    board: t >= 3.7985, highlight: t >= 3.7985 && t < 8.8877,
    mouth: clamp(mouth), blink: reduced ? 1 : blink,
    headYaw: reduced ? 0 : look * 0.62,
    headTilt: reduced ? 0 : Math.sin(t * 1.1) * 0.025 + returnSmile * -0.025,
    headNod: talking.headNod,
    torsoRoll: talking.torsoRoll,
    torsoYaw: reduced ? 0 : look * 0.17,
    breath: reduced ? 0 : Math.sin(t * 1.6) * 0.014,
    tail: reduced ? 0 : Math.sin(t * 1.2 - 0.5) * 0.13,
    leaf: reduced ? 0 : Math.sin(t * 1.4 - 0.3) * 0.055,
    earSway: talking.earSway,
    leftArm: reduced ? -0.10 : -0.10 - wave * (2.05 + Math.sin(t * 5.2) * 0.1) + talking.leftArm,
    leftElbow: reduced ? 0 : wave * 0.24 + talking.leftElbow,
    leftWrist: reduced ? 0 : wave * Math.sin(t * 5.2 - 0.4) * 0.19 + talking.leftWrist,
    rightArm: reduced ? 0.10 : 0.10 + point * 1.22 + talking.rightArm,
    rightElbow: reduced ? 0 : -point * 0.17 + talking.rightElbow,
    rightWrist: reduced ? 0 : -point * 0.13 + talking.rightWrist,
    leftFoot: talking.leftFoot,
    rightFoot: talking.rightFoot,
    gaze: reduced ? 0 : look * 0.035,
    point: reduced ? 0 : point,
  };
}

export function cueAt(cues, time) { return cues.find((cue) => time >= cue.start && time < cue.end) ?? null; }

export function audioEnvelope(samples, rate) {
  if (!samples.length || !(rate > 0)) throw new Error('Invalid audio samples');
  const stride = Math.max(1, Math.round(rate * 0.02)), values = [];
  let value = 0;
  for (let start = 0; start < samples.length; start += stride) {
    const end = Math.min(start + stride, samples.length);
    let sum = 0;
    for (let i = start; i < end; i += 1) sum += samples[i] ** 2;
    const energy = clamp((Math.sqrt(sum / (end - start)) - 0.01) / 0.14);
    value += (energy - value) * (energy > value ? 0.58 : 0.35);
    values.push(value < 0.015 ? 0 : value);
  }
  return { values, step: stride / rate };
}

export function mouthAt(envelope, time, playing) {
  if (!playing || !envelope || time < 0) return 0;
  const position = time / envelope.step, index = Math.floor(position), alpha = position - index;
  return (envelope.values[index] ?? 0) * (1 - alpha) + (envelope.values[index + 1] ?? 0) * alpha;
}
