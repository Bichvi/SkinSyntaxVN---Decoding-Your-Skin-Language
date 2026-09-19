/* Deterministic visual contact sheets: use the real renderer and real assets. */
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const output = path.resolve('report/ai-idol-syna-v6');
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
    await page.goto('http://localhost:8080/ai-idol-mascot-demo/index.html');
    await page.waitForSelector('#stage[data-ready="true"]');
    await page.screenshot({ path: path.join(output, 'desktop-review.png'), fullPage: true });
    const pictures = await page.evaluate(async () => {
      const { MascotRenderer } = await import('./renderer.js?v=6.1');
      const { EXPRESSIONS, GESTURES } = await import('./motion.js?v=6.1');
      const { presentationAt } = await import('./presentation.js?v=6.1');
      const narration = await (await fetch('./assets/syna-presentation-v5.json')).json();
      const atlas = new Image(); atlas.src = './assets/syna-key-atlas-v4.png'; await atlas.decode();
      const frame = document.createElement('canvas'); frame.width = 1280; frame.height = 720;
      const renderer = new MascotRenderer(frame, atlas);
      const result = {};
      for (const [kind, entries] of [['expressions', Object.entries(EXPRESSIONS)], ['actions', Object.entries(GESTURES)]]) {
        const grid = document.createElement('canvas'); grid.width = 1120; grid.height = Math.ceil(entries.length / 4) * 350;
        const ctx = grid.getContext('2d'); ctx.fillStyle = '#f4f8f1'; ctx.fillRect(0, 0, grid.width, grid.height);
        entries.forEach(([name, label], i) => {
          renderer.draw({ time: 2, mouth: 0.38, cue: null, blinks: [],
            expressionOverride: kind === 'expressions' ? { name, elapsed: 1 } : null,
            override: kind === 'actions' ? { name, elapsed: 1.7 } : null });
          const x = i % 4 * 280, y = Math.floor(i / 4) * 350;
          ctx.drawImage(frame, 335, 70, 440, 560, x + 10, y + 8, 260, 320);
          ctx.fillStyle = '#244837'; ctx.font = '15px "Segoe UI", sans-serif'; ctx.textAlign = 'center';
          ctx.fillText(label, x + 140, y + 343);
        });
        result[kind] = grid.toDataURL('image/png').split(',')[1];
      }
      const cue = narration.cues.find((item) => item.board === 'glycerin');
      const time = cue.start + 1.4;
      renderer.draw({ time, mouth: 0.4, cue, blinks: [], presentation: presentationAt(narration.cues, time) });
      result.stage = frame.toDataURL('image/png').split(',')[1];
      return result;
    });
    for (const [name, data] of Object.entries(pictures)) await fs.writeFile(path.join(output, `${name}.png`), Buffer.from(data, 'base64'));
    console.log('Saved real-renderer expression/action contact sheets and stage preview.');
  } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
