import { buildEnvelope, clamp, cueAt, envelopeAt, formatTime, makeBlinks, GESTURE_DURATION, GESTURES, EXPRESSIONS } from './motion.js?v=6.1';
import { MascotRenderer } from './renderer.js?v=6.1';
import { DEMO_PRODUCT, presentationAt } from './presentation.js?v=6.1';

const byId = (id) => document.getElementById(id);
const stage = byId('stage');
const playButton = byId('play');
const exportButton = byId('export');
const audio = new Audio();
audio.preload = 'auto';
audio.volume = 0.8;
const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
byId('reducedMotion').checked = motionPreference.matches;

let context, recordingAudio, renderer, envelope, cues = [], blinks = [];
let ready = false, started = false, theme = 'garden', background = null;
let manualGesture = null, uploadVersion = 0, recorder = null, recordingStream = null;
let manualExpression = null;
let lastPose = null;
let recordingCancelled = false, audioUrl = null, downloadUrl = null;
let lastFrame = 0, frameId = 0;
const idleStart = performance.now();

function showError(message) {
  byId('error').textContent = message;
  byId('error').hidden = false;
}

function clearError() { byId('error').hidden = true; }

function setControls() {
  const exporting = Boolean(recorder);
  playButton.disabled = !ready || exporting;
  byId('restart').disabled = !ready || exporting;
  byId('seek').disabled = !ready || exporting;
  exportButton.disabled = !ready;
  exportButton.textContent = exporting ? 'Hủy xuất video' : 'Tải video WebM ↓';
  document.querySelectorAll('[data-gesture], [data-theme], #script button, #backgroundFile, #reducedMotion, #expression, #gestureChoice, #tryGesture, #returnGesture, #stickers')
    .forEach((control) => { control.disabled = !ready || exporting; });
  byId('volume').disabled = exporting;
}

function syncTransport() {
  const playing = !audio.paused && !audio.ended;
  playButton.querySelector('span').textContent = playing ? 'Tạm dừng' : started ? 'Nghe tiếp' : 'Nghe bản mẫu';
  playButton.firstChild.textContent = playing ? 'Ⅱ ' : '▶ ';
  playButton.setAttribute('aria-label', playing ? 'Tạm dừng' : 'Nghe bản mẫu');
  if (recorder) byId('status').textContent = 'Đang xuất video — giữ trang hiển thị';
  else if (audio.ended) {
    byId('status').textContent = 'Đã nghe hết · bấm nghe để phát lại';
    playButton.querySelector('span').textContent = 'Nghe lại';
  } else byId('status').textContent = playing ? 'Syna đang thuyết trình' : started ? 'Đã tạm dừng' : 'Sẵn sàng · bấm nghe để bắt đầu';
}

async function playAudio() {
  if (!ready) return;
  await context.resume();
  if (context.state !== 'running') throw new Error('Trình duyệt chưa cho phép phát âm thanh. Bấm nghe lại nhé.');
  if (audio.ended) audio.currentTime = 0;
  await audio.play();
  started = true;
  syncTransport();
}

async function togglePlayback() {
  if (!ready || recorder) return;
  clearError();
  if (!audio.paused) audio.pause();
  else {
    try { await playAudio(); }
    catch (error) { showError(`Không phát được lời thoại: ${error.message}`); }
  }
}

function updateFrame(now) {
  frameId = requestAnimationFrame(updateFrame);
  if (!renderer || document.hidden || now - lastFrame < 1000 / 60 - 1) return;
  lastFrame = now;
  const time = audio.currentTime || 0;
  const playing = !audio.paused && !audio.ended && audio.readyState >= 3;
  const mouth = envelopeAt(envelope, time, playing);
  const currentCue = cueAt(cues, time);
  const motionTime = started ? time : (now - idleStart) / 1000;
  let override = null;
  if (manualGesture) {
    const elapsed = (now - manualGesture.start) / 1000;
    if (elapsed < (manualGesture.returning ? 0.6 : GESTURE_DURATION)) override = { ...manualGesture, elapsed };
    else manualGesture = null;
  }
  const caption = started ? currentCue ?? { text: audio.ended ? 'Hẹn gặp lại trong buổi tiếp theo nhé!' : ' ', gesture: 'idle' } : null;
  const expressionOverride = manualExpression ? { name: manualExpression.name,
    elapsed: (now - manualExpression.start) / 1000 } : null;
  const presentation = presentationAt(cues, started ? time : -1);
  const pose = renderer.draw({ time: motionTime, mouth, cue: caption, blinks, theme, background,
    reducedMotion: byId('reducedMotion').checked, override, expressionOverride,
    stickersEnabled: byId('stickers').checked, presentation });
  lastPose = pose;
  const gestureStatus = override
    ? override.returning ? 'Đang trở lại kịch bản…' : `Đang thử: ${GESTURES[override.name]} · ${Math.max(1, Math.ceil(GESTURE_DURATION - override.elapsed))} giây`
    : 'Theo kịch bản · chọn một hành động để xem ngay';
  if (byId('gestureStatus').textContent !== gestureStatus) byId('gestureStatus').textContent = gestureStatus;
  document.querySelectorAll('[data-gesture]').forEach((button) => {
    button.setAttribute('aria-pressed', String(Boolean(override && !override.returning && override.name === button.dataset.gesture)));
  });
  // Small, non-sensitive observability surface for regression tests and QA.
  stage.dataset.mouth = mouth.toFixed(3);
  stage.dataset.head = pose.head.toFixed(4);
  stage.dataset.gesture = pose.gesture;
  stage.dataset.expression = pose.expression;
  stage.dataset.time = time.toFixed(3);
  stage.dataset.boardActive = presentation.active ?? '';
  stage.dataset.boardVisible = presentation.visible.join(',');
  stage.dataset.boardLook = pose.boardLook.toFixed(3);
  stage.dataset.leftArm = pose.leftArm.toFixed(4);
  stage.dataset.rightArm = pose.rightArm.toFixed(4);
  updateBoardNotes(presentation);
  byId('seek').value = String(time);
  byId('seek').setAttribute('aria-valuetext', `${formatTime(time)} trên ${formatTime(audio.duration)}`);
  byId('time').textContent = `${formatTime(time)} / ${formatTime(audio.duration)}`;
  document.querySelectorAll('#script button').forEach((button, index) => {
    const active = started && currentCue === cues[index];
    button.classList.toggle('active', active);
    if (active) button.setAttribute('aria-current', 'true');
    else button.removeAttribute('aria-current');
  });
}

function updateBoardNotes(presentation) {
  const signature = `${presentation.visible.join(',')}/${presentation.active ?? ''}`;
  const notes = byId('boardNotes');
  if (notes.dataset.state === signature) return;
  notes.dataset.state = signature;
  notes.replaceChildren();
  if (!presentation.visible.length) {
    const text = document.createElement('li'); text.textContent = 'Bấm nghe: từng ý sẽ xuất hiện khi Syna giới thiệu.';
    notes.append(text); return;
  }
  for (const point of DEMO_PRODUCT.points.filter((item) => presentation.visible.includes(item.key))) {
    const item = document.createElement('li');
    item.textContent = `${point.title} · ${point.detail}`;
    if (point.key === presentation.active) { item.className = 'active'; item.setAttribute('aria-current', 'true'); }
    notes.append(item);
  }
}

function renderScript() {
  cues.forEach((cue) => {
    const item = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    const time = document.createElement('span');
    time.textContent = formatTime(cue.start);
    const text = document.createElement('span');
    text.textContent = cue.text;
    const point = DEMO_PRODUCT.points.find((item) => item.key === cue.board);
    if (point) {
      const note = document.createElement('small'); note.className = 'cue-board-note';
      note.textContent = `↗ Nhìn bảng · ${point.title}`; text.append(note);
    }
    button.append(time, text);
    button.addEventListener('click', async () => {
      if (!ready || recorder) return;
      clearError(); manualGesture = null; audio.currentTime = cue.start;
      try { await playAudio(); } catch (error) { showError(error.message); }
    });
    item.append(button); byId('script').append(item);
  });
}

async function changeBackground(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || recorder) return;
  const version = ++uploadVersion;
  clearError();
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 8 * 1024 ** 2) {
    showError('Chọn ảnh PNG, JPG hoặc WebP không quá 8 MB.'); return;
  }
  try {
    const decoded = await createImageBitmap(file);
    if (decoded.width * decoded.height > 20000000) {
      decoded.close(); throw new Error('Ảnh quá lớn. Hãy dùng ảnh dưới 20 megapixel.');
    }
    if (version !== uploadVersion || recorder) { decoded.close(); return; }
    background?.close(); background = decoded;
    document.querySelectorAll('[data-theme]').forEach((button) => {
      button.classList.remove('active'); button.setAttribute('aria-pressed', 'false');
    });
    byId('backgroundNote').textContent = `${file.name} · chỉ dùng trên máy bạn.`;
  } catch (error) { if (version === uploadVersion) showError(`Không đọc được ảnh nền. ${error.message}`); }
}

function cancelExport(message) {
  recordingCancelled = true;
  audio.pause();
  if (recorder && recorder.state !== 'inactive') recorder.stop();
  byId('exportNote').textContent = message;
}

function finishRecording(chunks, mime) {
  recordingStream?.getTracks().forEach((track) => track.stop());
  recordingStream = null; recorder = null;
  setControls(); syncTransport();
  if (recordingCancelled) return;
  if (!chunks.length) { showError('Trình duyệt không ghi được video. Bạn vẫn có thể nghe bản mẫu.'); return; }
  if (downloadUrl) URL.revokeObjectURL(downloadUrl);
  downloadUrl = URL.createObjectURL(new Blob(chunks, { type: mime }));
  const link = document.createElement('a');
  link.href = downloadUrl; link.download = 'syna-thuyet-trinh-v6.webm';
  document.body.append(link); link.click(); link.remove();
  byId('exportNote').textContent = 'Đã xuất WebM có âm thanh. Đây là bản mẫu, chưa phải luồng phát theo lịch.';
}

async function exportVideo() {
  if (recorder) { cancelExport('Đã hủy xuất video. Không lưu bản ghi dở.'); return; }
  if (!ready) return;
  clearError();
  const mime = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm']
    .find((type) => window.MediaRecorder?.isTypeSupported(type));
  if (!mime || !stage.captureStream) {
    showError('Trình duyệt này chưa hỗ trợ xuất WebM. Hãy thử Chrome hoặc Edge; phần nghe thử vẫn dùng được.'); return;
  }
  try {
    audio.pause(); audio.currentTime = 0; manualGesture = null;
    await context.resume();
    if (context.state !== 'running') throw new Error('Chưa bật được âm thanh. Bấm nghe thử trước rồi xuất lại.');
    const video = stage.captureStream(30);
    recordingStream = new MediaStream([...video.getVideoTracks(), ...recordingAudio.stream.getAudioTracks().map((track) => track.clone())]);
    recorder = new MediaRecorder(recordingStream, { mimeType: mime, videoBitsPerSecond: 2500000, audioBitsPerSecond: 128000 });
    recordingCancelled = false;
    const chunks = [];
    recorder.addEventListener('dataavailable', (event) => { if (event.data.size) chunks.push(event.data); });
    recorder.addEventListener('stop', () => finishRecording(chunks, mime), { once: true });
    recorder.addEventListener('error', () => { cancelExport('Xuất video thất bại.'); showError('Trình duyệt không ghi được video. Thử lại khi máy ít tác vụ hơn.'); });
    setControls();
    byId('exportNote').textContent = 'Đang ghi trọn bản mẫu. Không chuyển tab; bạn có thể bấm Hủy xuất video.';
    started = true;
    renderer.draw({ time: 0, cue: cues[0], blinks, theme, background, reducedMotion: byId('reducedMotion').checked,
      expressionOverride: manualExpression ? { name: manualExpression.name, elapsed: 1 } : null,
      stickersEnabled: byId('stickers').checked, presentation: presentationAt(cues, 0) });
    recorder.start(500);
    await playAudio();
  } catch (error) {
    if (recorder?.state !== 'inactive' && recorder) cancelExport('Đã dừng xuất video.');
    else {
      recordingStream?.getTracks().forEach((track) => track.stop());
      recordingStream = null; recorder = null; setControls();
    }
    showError(`Không xuất được video: ${error.message}`);
  }
}

async function initialize() {
  try {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) throw new Error('Trình duyệt chưa hỗ trợ Web Audio. Hãy mở bằng Chrome hoặc Edge.');
    const atlas = new Image(); atlas.src = './assets/syna-key-atlas-v4.png';
    const [speechResponse, metadataResponse] = await Promise.all([
      fetch('./assets/syna-presentation-v5.wav'), fetch('./assets/syna-presentation-v5.json'), atlas.decode(),
    ]);
    if (!speechResponse.ok || !metadataResponse.ok) throw new Error('Thiếu file lời thoại mẫu. Kiểm tra thư mục assets của bản mẫu.');
    const metadata = await metadataResponse.json();
    if (!Array.isArray(metadata.cues) || !metadata.cues.every((cue) => Number.isFinite(cue.start) && Number.isFinite(cue.end) && cue.end > cue.start && typeof cue.text === 'string')) {
      throw new Error('Mốc lời thoại mẫu không hợp lệ.');
    }
    cues = metadata.cues;
    context = new AudioContext();
    const bytes = await speechResponse.arrayBuffer();
    const decoded = await context.decodeAudioData(bytes.slice(0));
    envelope = buildEnvelope(decoded.getChannelData(0), decoded.sampleRate);
    audioUrl = URL.createObjectURL(new Blob([bytes], { type: 'audio/wav' }));
    audio.src = audioUrl;
    const source = context.createMediaElementSource(audio);
    source.connect(context.destination);
    recordingAudio = context.createMediaStreamDestination();
    source.connect(recordingAudio);
    byId('seek').max = String(decoded.duration);
    blinks = makeBlinks(Math.max(180, decoded.duration + 5));
    renderer = new MascotRenderer(stage, atlas);
    for (const [value, text] of Object.entries(GESTURES)) byId('gestureChoice').add(new Option(text, value));
    byId('gestureChoice').value = 'greeting';
    for (const [value, text] of Object.entries(EXPRESSIONS)) byId('expression').add(new Option(text, value));
    renderScript(); ready = true; setControls(); syncTransport();
    byId('loading').hidden = true; stage.dataset.ready = 'true';
    frameId = requestAnimationFrame(updateFrame);
  } catch (error) {
    byId('loading').textContent = 'Chưa chuẩn bị được bản mẫu.';
    showError(error.message); byId('status').textContent = 'Cần kiểm tra tài nguyên bản mẫu';
  }
}

playButton.addEventListener('click', togglePlayback);
byId('restart').addEventListener('click', async () => {
  if (!ready || recorder) return;
  clearError(); manualGesture = null; audio.currentTime = 0;
  try { await playAudio(); } catch (error) { showError(error.message); }
});
byId('seek').addEventListener('input', (event) => {
  if (!ready || recorder) return;
  started = true; manualGesture = null; audio.currentTime = clamp(Number(event.target.value), 0, audio.duration);
});
byId('volume').addEventListener('input', (event) => { audio.volume = Number(event.target.value); });
byId('backgroundFile').addEventListener('change', changeBackground);
document.querySelectorAll('[data-theme]').forEach((button) => button.addEventListener('click', () => {
  if (recorder) return;
  uploadVersion += 1; background?.close(); background = null; theme = button.dataset.theme;
  byId('backgroundNote').textContent = 'Ảnh ≤ 8 MB · chỉ xử lý trong trình duyệt.';
  document.querySelectorAll('[data-theme]').forEach((item) => {
    item.classList.toggle('active', item === button); item.setAttribute('aria-pressed', String(item === button));
  });
}));
function previewGesture(name) {
  if (!ready || recorder) return;
  audio.pause();
  byId('gestureChoice').value = name;
  manualGesture = { name, start: performance.now(), fromPose: lastPose };
}
document.querySelectorAll('[data-gesture]').forEach((button) => button.addEventListener('click', () => previewGesture(button.dataset.gesture)));
byId('gestureChoice').addEventListener('change', () => previewGesture(byId('gestureChoice').value));
byId('tryGesture').addEventListener('click', () => previewGesture(byId('gestureChoice').value));
byId('returnGesture').addEventListener('click', () => {
  if (!ready || recorder) return;
  manualGesture = { name: lastPose?.gesture ?? 'idle', start: performance.now(), fromPose: lastPose, returning: true };
});
byId('expression').addEventListener('change', () => {
  if (!ready || recorder) return;
  manualExpression = byId('expression').value === 'auto' ? null : { name: byId('expression').value, start: performance.now() };
});
exportButton.addEventListener('click', exportVideo);
['play', 'pause'].forEach((event) => audio.addEventListener(event, syncTransport));
audio.addEventListener('ended', () => {
  if (recorder?.state === 'recording') recorder.stop();
  syncTransport();
});
audio.addEventListener('error', () => {
  if (recorder) cancelExport('Đã hủy xuất do lỗi âm thanh.');
  showError('Không đọc được âm thanh mẫu. Tải lại trang để thử lại.');
});
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    if (recorder) cancelExport('Đã hủy xuất vì trang bị ẩn; không lưu video có hình đứng.');
    else audio.pause();
  }
});
window.addEventListener('pagehide', (event) => {
  audio.pause();
  if (recorder) cancelExport('Đã dừng xuất video.');
  // A bfcache return reuses this page and its listeners: keep reusable resources.
  if (event.persisted) return;
  cancelAnimationFrame(frameId);
  if (audioUrl) URL.revokeObjectURL(audioUrl);
  if (downloadUrl) URL.revokeObjectURL(downloadUrl);
  background?.close();
  context?.close();
});
initialize();
