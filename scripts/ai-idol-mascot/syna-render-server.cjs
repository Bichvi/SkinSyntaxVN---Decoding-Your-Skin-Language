'use strict';
// Local-only renderer. No user-supplied URLs, executable arguments or filesystem paths.
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { randomUUID } = require('node:crypto');
const { once } = require('node:events');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const assets = path.join(root, 'frontend/public/ai-idol-mascot-3d');
const temporary = path.join(root, '.runtime/syna-render');
const ffmpeg = process.env.SYNA_FFMPEG || path.join(root, '.runtime/bin/ffmpeg.exe');
const ffprobe = process.env.SYNA_FFPROBE || 'ffprobe.exe';
const port = 7862, sessions = new Map();
let busy = false;
const MAX_BYTES = 64 * 1024 * 1024;

function send(res, status, value) {
  if (res.destroyed || res.writableEnded) return;
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(value));
}
function decode(value, limit, name) {
  if (typeof value !== 'string' || value.length > Math.ceil(limit / 3) * 4 || !/^[A-Za-z0-9+/]*={0,2}$/.test(value)) throw new Error(`Invalid ${name}`);
  const bytes = Buffer.from(value, 'base64');
  if (!bytes.length || bytes.length > limit) throw new Error(`Invalid ${name} size`);
  return bytes;
}
async function probe(file) {
  const child = spawn(ffprobe, ['-v', 'error', '-show_entries', 'format=duration', '-of', 'json', file], { windowsHide: true });
  let stdout = ''; child.stdout.on('data', data => { stdout += data; });
  const timer = setTimeout(() => child.kill(), 10000);
  try {
    const [code] = await once(child, 'close');
    if (code !== 0) throw new Error('Cannot read audio duration');
    return Number(JSON.parse(stdout).format.duration);
  } finally { clearTimeout(timer); }
}
async function render(payload, req, res) {
  const manifest = payload.manifest;
  if (!manifest || manifest.format !== '16:9' || !manifest.product ||
      typeof manifest.product.name !== 'string' || manifest.product.name.length > 500 ||
      !Number.isFinite(manifest.product.price) || manifest.product.price < 0 ||
      !Array.isArray(manifest.ingredients) || manifest.ingredients.length > 3 ||
      manifest.ingredients.some(x => typeof x !== 'string' || x.length > 160) ||
      !Array.isArray(manifest.cues) || manifest.cues.length > 1500) throw new Error('Invalid Syna manifest');
  const audio = decode(payload.audio, 24 * 1024 * 1024, 'audio');
  if (audio.toString('ascii', 0, 4) !== 'RIFF' || audio.toString('ascii', 8, 12) !== 'WAVE') throw new Error('Audio must be WAV');
  const id = randomUUID();
  await fs.mkdir(temporary, { recursive: true });
  const folder = await fs.mkdtemp(path.join(temporary, 'job-'));
  let browser, encoder, timer, cancelled = false;
  const abort = () => { if (!res.writableEnded) { cancelled = true; encoder?.kill(); browser?.close().catch(() => {}); } };
  res.on('close', abort);
  try {
    const audioPath = path.join(folder, 'audio.wav'), output = path.join(folder, 'video.mp4');
    await fs.writeFile(audioPath, audio);
    const duration = await probe(audioPath);
    if (!Number.isFinite(duration) || duration <= 0 || duration > 180) throw new Error('Syna supports 0–180 seconds per product');
    if (Math.abs(duration - manifest.duration) > .1) throw new Error('Audio duration mismatch');
    for (const cue of manifest.cues) {
      if (!Number.isFinite(cue.start) || !Number.isFinite(cue.end) || cue.start < 0 || cue.end > duration + .1 || cue.end <= cue.start ||
          typeof cue.text !== 'string' || cue.text.length > 500 || !Number.isInteger(cue.ingredient) || cue.ingredient < -1 || cue.ingredient >= manifest.ingredients.length) throw new Error('Invalid subtitle cue');
    }
    for (const [field, filename, flag] of [['product_image', 'product.img', 'hasProductImage'], ['background', 'background.img', 'hasBackground']]) {
      manifest[flag] = Boolean(payload[field]);
      if (payload[field]) await fs.writeFile(path.join(folder, filename), decode(payload[field], 15 * 1024 * 1024, field));
    }
    await fs.writeFile(path.join(folder, 'manifest.json'), JSON.stringify(manifest));
    sessions.set(id, folder);
    if (cancelled) throw new Error('Render cancelled');
    browser = await chromium.launch({ channel: 'chrome', headless: true });
    timer = setTimeout(abort, 14 * 60 * 1000);
    const page = await browser.newPage({ viewport: { width: 1280, height: 720 } });
    // Block any external asset loading by the page. Job data are never interpreted as URLs.
    await page.route('**/*', route => new URL(route.request().url()).origin === `http://127.0.0.1:${port}` ? route.continue() : route.abort());
    await page.goto(`http://127.0.0.1:${port}/render.html?job=${id}`);
    await page.waitForFunction(() => document.body.dataset.ready || document.body.dataset.error, null, { timeout: 60000 });
    const error = await page.evaluate(() => document.body.dataset.error);
    if (error) throw new Error(error);
    encoder = spawn(ffmpeg, ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'image2pipe', '-framerate', '25', '-vcodec', 'mjpeg', '-i', 'pipe:0',
      '-i', audioPath, '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-threads', '2',
      '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2', '-t', String(duration), '-movflags', '+faststart', output], { windowsHide: true });
    let encodeError = '', encodeCode;
    encoder.stderr.on('data', data => { encodeError = (encodeError + data).slice(-2000); });
    const finished = once(encoder, 'close').then(([code]) => { encodeCode = code; }).catch(error => { encodeError = error.message; encodeCode = -1; });
    encoder.stdin.on('error', () => {});
    for (let frame = 0; frame < Math.ceil(duration * 25); frame++) {
      if (cancelled || encodeCode !== undefined) throw new Error('Syna render interrupted');
      const jpeg = await page.evaluate(t => window.synaRenderFrame(t), frame / 25);
      if (!encoder.stdin.write(Buffer.from(jpeg, 'base64'))) await once(encoder.stdin, 'drain');
    }
    encoder.stdin.end(); await finished;
    if (encodeCode !== 0) throw new Error(`Syna encoding failed: ${encodeError}`);
    await browser.close(); browser = null;
    const video = await fs.readFile(output);
    if (Math.abs(await probe(output) - duration) > .15) throw new Error('Output duration mismatch');
    res.writeHead(200, { 'Content-Type': 'video/mp4', 'Content-Length': video.length, 'Cache-Control': 'no-store' });
    res.end(video);
  } finally {
    clearTimeout(timer); res.off('close', abort); sessions.delete(id);
    encoder?.kill(); await browser?.close().catch(() => {});
    // Only delete the exact mkdtemp directory created by this request, inside our temp root.
    if (path.dirname(folder) === temporary && path.basename(folder).startsWith('job-')) await fs.rm(folder, { recursive: true, force: true, maxRetries: 3 });
  }
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);
    if (req.method === 'GET' && url.pathname === '/health') return send(res, 200, { ok: true, renderer: 'syna-native-v4', busy });
    if (req.method === 'POST' && url.pathname === '/render') {
      // No CORS and no browser-origin POSTs: only the internal worker calls this endpoint.
      if (req.headers.origin || req.headers['content-type'] !== 'application/json') return send(res, 403, { error: 'Internal JSON clients only' });
      if (busy) return send(res, 503, { error: 'Syna đang tạo video khác. Thử lại khi hoàn tất.' });
      busy = true;
      try {
        let size = 0; const chunks = [];
        for await (const chunk of req) {
          size += chunk.length; if (size > MAX_BYTES) { send(res, 413, { error: 'Render payload too large' }); return; }
          chunks.push(chunk);
        }
        await render(JSON.parse(Buffer.concat(chunks).toString('utf8')), req, res);
      } finally { busy = false; }
      return;
    }
    if (req.method !== 'GET') return send(res, 405, { error: 'Method not allowed' });
    const job = url.pathname.match(/^\/job\/([a-f0-9-]{36})\/(manifest\.json|audio\.wav|product\.img|background\.img)$/);
    let file;
    if (job && sessions.has(job[1])) file = path.join(sessions.get(job[1]), job[2]);
    else {
      const relative = decodeURIComponent(url.pathname).slice(1);
      if (!/^(render\.html|render\.js|campaign-stage\.js|scene\.js|timeline\.js|model\.js|[\w-]+\.js|vendor\/[\w.-]+\.js)$/.test(relative)) return send(res, 404, { error: 'Not found' });
      file = path.join(assets, relative);
    }
    const data = await fs.readFile(file);
    const type = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.json': 'application/json', '.wav': 'audio/wav' }[path.extname(file)] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' }); res.end(data);
  } catch (error) { send(res, error.code === 'ENOENT' ? 404 : 422, { error: error.code === 'ENOENT' ? 'Not found' : error.message }); }
});
server.requestTimeout = 16 * 60 * 1000;
server.listen(port, '127.0.0.1', () => process.stdout.write('Syna renderer listening on 127.0.0.1:7862\n'));
