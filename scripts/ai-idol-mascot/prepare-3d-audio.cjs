/* Extract existing, intact Syna sentences for the 15-second 3D review. */
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const source = path.join(root, 'frontend/public/ai-idol-mascot-demo/assets');
const output = path.join(root, 'frontend/public/ai-idol-mascot-3d/assets');
const metadata = JSON.parse(fs.readFileSync(path.join(source, 'syna-presentation-v5.json')));
const wav = path.join(output, 'syna-pilot.wav'), json = path.join(output, 'syna-pilot.json');
if (fs.existsSync(wav) || fs.existsSync(json)) throw new Error('Pilot audio exists; refusing to overwrite.');
fs.mkdirSync(output, { recursive: true });
const offset = metadata.cues[2].start - metadata.cues[1].start;
const cues = [metadata.cues[0], ...metadata.cues.slice(2, 4).map((cue) => ({ ...cue,
  start: Number((cue.start - offset).toFixed(4)), end: Number((cue.end - offset).toFixed(4)) }))];
const filter = `[0:a]atrim=end=${metadata.cues[1].start},asetpts=PTS-STARTPTS[a];`
  + `[0:a]atrim=start=${metadata.cues[2].start}:end=${metadata.cues[3].end},asetpts=PTS-STARTPTS[b];`
  + '[a][b]concat=n=2:v=0:a=1,apad,atrim=duration=15[out]';
execFileSync(path.join(root, '.runtime/bin/ffmpeg.exe'), ['-hide_banner', '-loglevel', 'error', '-n',
  '-i', path.join(source, 'syna-presentation-v5.wav'), '-filter_complex', filter,
  '-map', '[out]', '-c:a', 'pcm_s16le', wav], { stdio: 'inherit' });
fs.writeFileSync(json, JSON.stringify({ duration: 15, cues, source: 'Local Syna v5 processed WAV',
  note: 'Intact sentences; timings derived from source cuts. No new TTS or speed change.' }, null, 2));
console.log('Prepared 15-second local Syna audio with three measured sentence cues.');
