import { clamp, smooth, GESTURE_DURATION } from './motion.js?v=6.1';

// Design fixture only, never represented as catalog data or a real formulation.
export const DEMO_PRODUCT = Object.freeze({
  title: 'Gel dưỡng rau má',
  price: '199.000 đ',
  size: '50 ml · mẫu thiết kế',
  note: 'SẢN PHẨM MINH HỌA · KHÔNG PHẢI CÔNG THỨC THẬT',
  points: [
    { key: 'centella', title: 'Chiết xuất rau má', detail: 'Hỗ trợ làm dịu', y: 287 },
    { key: 'glycerin', title: 'Glycerin', detail: 'Hỗ trợ giữ ẩm', y: 379 },
    { key: 'panthenol', title: 'Panthenol', detail: 'Hỗ trợ hàng rào bảo vệ da', y: 471 },
  ],
});

/** Pure audio-clock state: safe for replay, silence gaps, backwards seek/export. */
export function presentationAt(cues, time) {
  const clock = Number.isFinite(time) ? time : 0;
  const eligible = cues.filter((cue) => Number.isFinite(cue.start) && Number.isFinite(cue.end)
    && cue.end > cue.start && cue.start <= clock).sort((a, b) => a.start - b.start);
  const visible = new Set();
  let lastPoint = null;
  for (const cue of eligible) {
    const point = DEMO_PRODUCT.points.find((item) => item.key === cue.board);
    if (point) { visible.add(point.key); lastPoint = point; }
  }
  const cue = eligible.find((item) => clock < item.end);
  const current = DEMO_PRODUCT.points.find((point) => point.key === cue?.board);
  const local = cue ? clock - cue.start : 0;
  // Occasional glance during a whole measured ingredient sentence. No guessed
  // word timestamps: the board highlight spans that actual spoken sentence.
  const look = current && cue.lookAtBoard
    ? smooth((local - 0.25) / 0.7) * smooth((Math.min(cue.end - cue.start - 0.45, 3.8) - local) / 0.85) : 0;
  return { visible: [...visible], active: current?.key ?? null,
    retained: lastPoint?.key ?? null, look: clamp(look), targetY: current?.y ?? 379,
    summary: cue?.board === 'summary' || (!cue && visible.size === DEMO_PRODUCT.points.length),
    progress: cue ? clamp(local / (cue.end - cue.start)) : 0 };
}

/** Blend the entire presenter chain, not isolated eye or arm teleports. */
export function presentationPose(pose, board, reducedMotion = false, manual = null) {
  const manualWeight = manual ? smooth(manual.elapsed / 0.65)
    * smooth((GESTURE_DURATION - manual.elapsed) / 0.75) : 0;
  const weight = reducedMotion ? 0 : (board?.look ?? 0) * (1 - manualWeight);
  const next = { ...pose, boardLook: weight, boardTargetY: board?.targetY ?? 379 };
  if (!weight) return next;
  const target = { head: 0.07, headX: 12, headY: -2, gaze: 10,
    gazeY: -2, body: 0.025, shoulder: 2.2,
    rightArm: -1.65 + ((board.targetY - 287) / 184) * 0.42,
    rightBend: 0.12, leftArm: 0.18, leftBend: -0.08 };
  for (const [key, value] of Object.entries(target)) next[key] += (value - next[key]) * weight;
  return next;
}
