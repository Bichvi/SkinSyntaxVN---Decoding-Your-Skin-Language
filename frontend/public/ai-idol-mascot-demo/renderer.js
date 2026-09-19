import { clamp, eyeOpenness, poseAt, expressionAt, stickerAt, blendPreviewPose } from './motion.js?v=6.1';
import { SynaCharacter, drawCentellaLeaf } from './character.js?v=6.1';
import { DEMO_PRODUCT, presentationAt, presentationPose } from './presentation.js?v=6.1';

export const THEMES = {
  garden: { top: '#eff7f0', bottom: '#dceee3', accent: '#bed9c5', ink: '#173f32' },
  paper: { top: '#fffdf7', bottom: '#eee8d9', accent: '#e3d8bd', ink: '#514933' },
  rose: { top: '#fff5f3', bottom: '#f3dedc', accent: '#e8c5c4', ink: '#624141' },
};


function rounded(ctx, x, y, w, h, radius, fill) {
  ctx.beginPath();
  ctx.roundRect(x, y, w, h, radius);
  ctx.fillStyle = fill;
  ctx.fill();
}

function label(ctx, text, x, y, size = 20, color = '#173f32', weight = 400) {
  ctx.fillStyle = color;
  ctx.font = `${weight} ${size}px "Segoe UI", sans-serif`;
  ctx.fillText(text, x, y);
}

function linesFor(ctx, text, width) {
  const lines = [];
  let line = '';
  for (const word of text.split(/\s+/)) {
    const next = line ? `${line} ${word}` : word;
    if (line && ctx.measureText(next).width > width) {
      lines.push(line);
      line = word;
    } else line = next;
  }
  if (line) lines.push(line);
  return lines;
}

export class MascotRenderer {
  constructor(canvas, atlas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: false });
    if (!this.ctx) throw new Error('Trình duyệt không hỗ trợ Canvas 2D.');
    this.character = new SynaCharacter(this.ctx, atlas);
  }

  background(theme, image) {
    const ctx = this.ctx;
    const gradient = ctx.createLinearGradient(0, 0, 0, 720);
    gradient.addColorStop(0, theme.top);
    gradient.addColorStop(1, theme.bottom);
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 1280, 720);
    if (image) {
      const scale = Math.max(1280 / image.width, 720 / image.height);
      ctx.drawImage(image, (1280 - image.width * scale) / 2,
        (720 - image.height * scale) / 2, image.width * scale, image.height * scale);
      ctx.fillStyle = 'rgba(250,253,250,.2)';
      ctx.fillRect(0, 0, 1280, 720);
    } else {
      ctx.globalAlpha = 0.3;
      ctx.fillStyle = theme.accent;
      ctx.beginPath(); ctx.ellipse(270, 415, 295, 340, -0.3, 0, Math.PI * 2); ctx.fill();
      ctx.globalAlpha = 0.2;
      ctx.beginPath(); ctx.ellipse(1210, 190, 210, 270, 0.3, 0, Math.PI * 2); ctx.fill();
      ctx.globalAlpha = 1;
    }
    rounded(ctx, 34, 25, 145, 36, 18, 'rgba(255,255,255,.9)');
    label(ctx, 'SkinSyntax', 53, 50, 20, '#173f32', 650);
    label(ctx, 'BẢN MẪU 2D · KHÔNG PHÁT TRỰC TIẾP', 805, 48, 15, theme.ink, 500);
  }

  product(theme, board) {
    const ctx = this.ctx;
    rounded(ctx, 34, 145, 290, 437, 22, 'rgba(255,255,255,.97)');
    label(ctx, 'ĐANG GIỚI THIỆU', 58, 180, 14, '#65796e', 650);
    label(ctx, 'Gel dưỡng rau má', 58, 216, 25, theme.ink, 650);
    rounded(ctx, 58, 238, 242, 197, 16, '#eff3e5');
    const bottle = ctx.createLinearGradient(120, 0, 226, 0);
    bottle.addColorStop(0, '#a3c38d'); bottle.addColorStop(0.5, '#e3edca'); bottle.addColorStop(1, '#a7c18e');
    ctx.fillStyle = 'rgba(35,74,39,.09)'; ctx.beginPath(); ctx.ellipse(179, 416, 65, 9, 0, 0, Math.PI * 2); ctx.fill();
    rounded(ctx, 135, 282, 88, 129, 15, bottle);
    rounded(ctx, 143, 259, 72, 31, 7, '#204c35');
    rounded(ctx, 145, 309, 68, 81, 5, '#fffdf4');
    label(ctx, 'SYNA', 159, 331, 14, '#355f47', 650);
    drawCentellaLeaf(ctx, 179, 363, 0.28);
    label(ctx, 'DEMO', 162, 379, 11, '#65796e');
    label(ctx, DEMO_PRODUCT.size, 59, 461, 17, '#65796e');
    label(ctx, DEMO_PRODUCT.price, 58, 503, 33, theme.ink, 700);
    label(ctx, 'GIÁ MINH HỌA', 59, 527, 12, '#65796e', 650);
    label(ctx, 'Ảnh, tên và giá luôn hiển thị.', 58, 558, 15, '#65796e');
    this.board(theme, board);
  }

  board(theme, board) {
    const ctx = this.ctx;
    rounded(ctx, 814, 562, 11, 62, 5, '#87a691');
    rounded(ctx, 1181, 562, 11, 62, 5, '#87a691');
    rounded(ctx, 778, 140, 470, 443, 23, 'rgba(25,63,45,.09)');
    rounded(ctx, 772, 133, 470, 443, 23, '#fffdf7');
    rounded(ctx, 947, 123, 120, 19, 7, '#b7cdb9');
    label(ctx, 'GHI CHÚ CỦA SYNA', 797, 177, 15, '#65796e', 650);
    label(ctx, 'Thành phần nổi bật', 797, 215, 28, theme.ink, 650);
    for (const [index, point] of DEMO_PRODUCT.points.entries()) {
      const revealed = board.visible.includes(point.key);
      const active = board.active === point.key;
      const y = point.y - 34;
      rounded(ctx, 793, y, 428, 78, 13, active ? '#e4efdb' : '#f3f4eb');
      if (active) rounded(ctx, 793, y + 10, 4, 58, 2, '#52804b');
      rounded(ctx, 808, y + 21, 32, 35, 17, active ? theme.ink : '#e3e9dc');
      label(ctx, String(index + 1).padStart(2, '0'), 813, y + 45, 15, active ? '#ffffff' : '#60735d', 650);
      label(ctx, revealed ? point.title : 'Syna sắp bật mí…', 852, y + 33,
        revealed ? 23 : 21, revealed ? theme.ink : '#73836f', revealed ? 650 : 400);
      label(ctx, revealed ? point.detail : 'Cùng nghe theo từng ý nhé', 852, y + 59, 17, '#65796e');
      if (active) rounded(ctx, 852, y + 69, Math.max(1, 349 * board.progress), 2, 1, '#709d5b');
    }
    label(ctx, board.summary ? 'Cùng ghi nhớ 3 ý nhỏ nhé!' : 'Ý chính trên bảng · lời kể ở phụ đề', 797, 551, 17, '#65796e');
    label(ctx, 'Công dụng tham khảo của thành phần.', 797, 603, 13, theme.ink);
    label(ctx, 'Sản phẩm & giá minh họa; không phải công thức thật.', 34, 615, 14, theme.ink);
  }

  caption(text, theme) {
    const ctx = this.ctx;
    ctx.font = '500 23px "Segoe UI", sans-serif';
    const lines = linesFor(ctx, text, 1120);
    const height = Math.max(46, lines.length * 29 + 18);
    rounded(ctx, 50, 708 - height, 1180, height, 14, 'rgba(255,255,255,.96)');
    ctx.textAlign = 'center';
    lines.forEach((line, index) => label(ctx, line, 640, 708 - height + 30 + index * 29,
      23, theme.ink, 500));
    ctx.textAlign = 'left';
  }

  draw({ time = 0, mouth = 0, cue = null, blinks = [], theme = 'garden',
    background = null, reducedMotion = false, override = null, expressionOverride = null,
    stickersEnabled = true, presentation = null }) {
    const palette = THEMES[theme] ?? THEMES.garden;
    const board = presentation ?? presentationAt([], 0);
    const activeOverride = override?.returning ? null : override;
    const targetPose = presentationPose(poseAt(time, cue, mouth, reducedMotion, activeOverride), board, reducedMotion, activeOverride);
    const pose = reducedMotion ? targetPose : blendPreviewPose(targetPose, override);
    this.ctx.clearRect(0, 0, 1280, 720);
    this.background(palette, background);
    this.product(palette, board);
    let expression = expressionAt(time, cue, expressionOverride);
    if (activeOverride && !expressionOverride) {
      const mood = { greeting: 'winkLeft', goodbye: 'winkRight', happy: 'happy',
        thinking: 'thinking', presenting: 'confident', sale: 'happy', listening: 'neutral',
        processing: 'thinking', talking2: 'surprised' };
      expression = { name: mood[activeOverride.name] ?? 'neutral', amount: pose.gestureStrength };
    }
    this.character.draw(pose, clamp(mouth), reducedMotion ? 1 : eyeOpenness(time + (activeOverride?.elapsed ?? 0), blinks), expression);
    if (stickersEnabled && !reducedMotion) this.character.sticker(stickerAt(time, cue, activeOverride));
    this.caption(cue?.text ?? 'Syna đã sẵn sàng. Cùng nghe thử nhé!', palette);
    return { ...pose, expression: expression.name };
  }
}
