export const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
export const GESTURE_DURATION = 4.2;
export const smooth = (value) => { const t = clamp(value); return t * t * t * (t * (t * 6 - 15) + 10); };

export const EXPRESSIONS = { neutral: 'Bình thường', winkLeft: 'Nháy mắt trái', winkRight: 'Nháy mắt phải',
  happy: 'Cười vui', surprised: 'Bất ngờ', thinking: 'Suy nghĩ', sad: 'Buồn nhẹ', confident: 'Tự tin' };
export const GESTURES = { idle: 'Đứng thư giãn', talking: 'Trò chuyện', talking2: 'Nghiêng đầu trò chuyện',
  thinking: 'Chạm má suy nghĩ', presenting: 'Giới thiệu sản phẩm', sale: 'Cầm bảng ưu đãi',
  greeting: 'Vẫy chào', explaining: 'Giải thích', listening: 'Lắng nghe',
  processing: 'Làm việc với laptop', happy: 'Ôm trái tim', goodbye: 'Tạm biệt' };

/** New preview starts at the actual displayed pose, even on a rapid switch. */
export function blendPreviewPose(pose, override) {
  if (!override?.fromPose) return pose;
  const weight = smooth(override.elapsed / 0.55);
  const result = { ...pose };
  for (const [key, value] of Object.entries(pose)) {
    if (typeof value === 'number' && Number.isFinite(override.fromPose[key])) {
      result[key] = override.fromPose[key] + (value - override.fromPose[key]) * weight;
    }
  }
  result.previousProp = weight < 1 ? { gesture: override.fromPose.gesture,
    gestureStrength: override.fromPose.gestureStrength * (1 - weight) } : null;
  result.gestureStrength = pose.gestureStrength * weight;
  return result;
}

/** Sample the actual narration once; never fabricate movement from text length. */
export function buildEnvelope(samples, sampleRate, step = 0.02) {
  if (!samples.length || sampleRate <= 0 || step <= 0) throw new Error('Invalid audio samples');
  const stride = Math.max(1, Math.round(sampleRate * step));
  const values = [];
  for (let start = 0; start < samples.length; start += stride) {
    const end = Math.min(start + stride, samples.length);
    let sum = 0;
    for (let i = start; i < end; i += 1) sum += samples[i] ** 2;
    const rms = Math.sqrt(sum / (end - start));
    values.push(clamp((rms - 0.012) / 0.12));
  }
  // Smooth the actual speech energy once, so seeking/export have the same
  // mouth shape and peaks do not snap the jaw between fully open and shut.
  let previous = 0;
  const smoothed = values.map((value) => {
    const coefficient = 1 - Math.exp(-(stride / sampleRate) / (value > previous ? 0.025 : 0.055));
    previous += (value - previous) * coefficient;
    return previous < 0.015 ? 0 : previous;
  });
  return { step: stride / sampleRate, values: smoothed };
}

export function envelopeAt(envelope, time, playing) {
  if (!playing || !envelope || time < 0) return 0;
  const position = time / envelope.step;
  const index = Math.floor(position);
  const a = envelope.values[index] ?? 0;
  const b = envelope.values[index + 1] ?? 0;
  return a + (b - a) * (position - index);
}

export function cueAt(cues, time) {
  return cues.find((cue) => time >= cue.start && time < cue.end) ?? null;
}

/** Irregular, seeded timing makes replay/seek reproducible, including exports. */
export function makeBlinks(duration = 180, seed = 47) {
  const result = [];
  let time = 1.6;
  let state = seed >>> 0;
  while (time < duration) {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    result.push(time);
    time += 2.1 + (state / 4294967296) * 3.2;
  }
  return result;
}

export function eyeOpenness(time, blinks) {
  for (const start of blinks) {
    const offset = time - start;
    if (offset >= 0 && offset < 0.22) return 1 - Math.sin(Math.PI * offset / 0.22) ** 2;
  }
  return 1;
}

function gestureWeight(local, duration) {
  return smooth(local / 0.65) * smooth((duration - local) / 0.75);
}

function gestureOffsets(name, local) {
  const wave = Math.sin(local * 5.6) * Math.exp(-Math.max(0, local - 2.5));
  const result = { head: 0, headX: 0, headY: 0, body: 0, shoulder: 0,
    leftArm: 0, rightArm: 0, leftBend: 0, rightBend: 0, gaze: 0, gazeY: 0 };
  if (name === 'greeting' || name === 'goodbye') Object.assign(result,
    { leftArm: 2.12 + wave * 0.16, leftBend: -0.3 + Math.sin(local * 5.6 - 0.6) * 0.23,
      head: 0.05, headX: 2.3, headY: -1.2, shoulder: -1.4, body: 0.008 });
  else if (name === 'presenting') Object.assign(result,
    { rightArm: -1.23, rightBend: 0.32, leftArm: 0.15, head: 0.045, headX: 3.2, gaze: 4.5, shoulder: 1.2 });
  else if (name === 'explaining' || name === 'talking') Object.assign(result,
    { rightArm: -0.58 - Math.sin(local * 2.3) * 0.15, rightBend: 0.28,
      leftArm: 0.37 + Math.sin(local * 2.3 - 0.5) * 0.1, leftBend: -0.2, head: Math.sin(local * 2) * 0.035 });
  else if (name === 'thinking' || name === 'talking2') Object.assign(result,
    { rightArm: -2.65, rightBend: -0.75, head: -0.065, headX: -2.5,
      gaze: name === 'thinking' ? -4 : 1, gazeY: -2.5, shoulder: 1.5 });
  else if (name === 'listening') Object.assign(result,
    { leftArm: -0.6, rightArm: 0.6, leftBend: -0.5, rightBend: 0.5,
      head: Math.sin(local * 2.8) * 0.025, headY: 1.4 });
  else if (name === 'happy') Object.assign(result,
    { leftArm: -0.4, rightArm: 0.4, leftBend: -0.6, rightBend: 0.6,
      headY: -2.8, head: -0.025, body: -0.012 });
  else if (name === 'processing') Object.assign(result,
    { leftArm: -0.35, rightArm: 0.35, leftBend: 0.6, rightBend: -0.6,
      headY: 2.5, gazeY: 6, head: 0.018 });
  else if (name === 'sale') Object.assign(result,
    { leftArm: 1.25, leftBend: -0.38, rightArm: -0.85, head: -0.035, headX: -2 });
  return result;
}

export function poseAt(time, cue, mouth, reducedMotion = false, override = null) {
  if (!Number.isFinite(cue?.start) || !Number.isFinite(cue?.end)) cue = null;
  const gesture = override?.name ?? cue?.gesture ?? 'idle';
  const pose = { head: 0, headX: 0, headY: 0, body: 0, breath: 0, tail: 0, leftArm: 0.12,
    rightArm: -0.12, leftBend: 0, rightBend: 0, gaze: 0, gazeY: 0,
    shoulder: 0, plant: 0, gesture, gestureStrength: 0 };
  if (reducedMotion) return pose;
  const local = Math.max(0, time - (cue?.start ?? 0));
  const baseWeight = cue ? gestureWeight(local, cue.end - cue.start) : 0;
  const auto = gestureOffsets(cue?.gesture ?? 'idle', local);
  const manualWeight = override ? gestureWeight(override.elapsed, GESTURE_DURATION) : 0;
  const manual = gestureOffsets(override?.name, override?.elapsed ?? 0);
  for (const key of Object.keys(auto)) pose[key] += auto[key] * baseWeight * (1 - manualWeight) + manual[key] * manualWeight;
  pose.gestureStrength = override ? manualWeight : baseWeight;
  // The body follows phrases, not individual audio samples: this avoids
  // high-frequency jaw peaks shaking the whole head and neck.
  const idleClock = time + (override?.elapsed ?? 0) * manualWeight;
  pose.head += Math.sin(idleClock * 1.15) * 0.018 + Math.sin(idleClock * 2.1) * 0.012 * baseWeight;
  pose.headY += Math.sin(idleClock * 1.6 - 0.4) * 0.65;
  pose.body += Math.sin(idleClock * 0.9 - 0.3) * 0.012;
  pose.breath = Math.sin(idleClock * 1.7) * 1.3;
  pose.shoulder += Math.sin(idleClock * 1.7 - 0.5) * 0.65;
  pose.tail = Math.sin(idleClock * 1.25 - 0.5) * 0.085;
  pose.plant = Math.sin(idleClock * 1.3 - 0.8) * 0.018 - pose.head * 0.2;
  pose.gaze += Math.sin(idleClock * 0.45) * 1.2;
  pose.leftArm += Math.sin(idleClock * 1.4 - 0.4) * 0.023;
  pose.rightArm -= Math.sin(idleClock * 1.4 - 0.8) * 0.023;
  return pose;
}

export function expressionAt(time, cue, override = null) {
  if (!Number.isFinite(cue?.start) || !Number.isFinite(cue?.end)) cue = null;
  const name = override?.name ?? cue?.expression ?? 'neutral';
  const amount = override ? smooth(override.elapsed / 0.3)
    : cue ? gestureWeight(time - cue.start, cue.end - cue.start) : 0;
  return { name: EXPRESSIONS[name] ? name : 'neutral', amount };
}

export function stickerAt(time, cue, override = null) {
  const mapping = { greeting: 'hello', goodbye: 'heart', happy: 'heart', thinking: 'question',
    presenting: 'sparkle', sale: 'sparkle', explaining: 'leaf', processing: 'dots' };
  const local = override ? override.elapsed : time - (cue?.start ?? 0);
  const name = override ? mapping[override.name] : cue?.sticker;
  const remaining = override ? GESTURE_DURATION - local : (cue?.end ?? 0) - time;
  // One brief sticker per phrase; never an endless random particle shower.
  if (!name || local < 0.9 || local > 2.8 || remaining < 0.3) return null;
  const age = local - 0.9;
  return { name, opacity: smooth(age / 0.25) * smooth((1.9 - age) / 0.4),
    rise: smooth(age / 1.9) * 15 };
}

export function formatTime(seconds) {
  const whole = Math.floor(Math.max(0, Number.isFinite(seconds) ? seconds : 0));
  return `${Math.floor(whole / 60).toString().padStart(2, '0')}:${(whole % 60).toString().padStart(2, '0')}`;
}
