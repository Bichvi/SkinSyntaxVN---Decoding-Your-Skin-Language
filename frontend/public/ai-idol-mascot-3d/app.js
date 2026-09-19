import { DURATION, pilotPose, cueAt, audioEnvelope, mouthAt, clamp } from './timeline.js?v=6';
import { drawStage } from './stage.js?v=5';
const embedMode=new URLSearchParams(location.search).get('embed')==='1';
document.body.classList.toggle('embed-mode',embedMode);
if(embedMode){
  const embedNote=document.querySelector('.under-player p');
  if(embedNote)embedNote.innerHTML='<strong>Xem thử nhân vật.</strong> Đây là clip minh họa; chọn Syna 3D ở biểu mẫu để tạo video theo sản phẩm thật.';
}
const el=(id)=>document.getElementById(id), stage=el('stage'), ctx=stage.getContext('2d',{alpha:false});
const audio=new Audio(); audio.preload='auto'; audio.volume=.8;
let scene, audioContext, recordingAudio, envelope, cues=[], audioUrl, downloadUrl;
let ready=false, started=false, frameId, lastFrame=0, viewAngle=0, recorder=null, stream=null, cancelled=false;
let frameCount=0, renderTotal=0, statsStart=performance.now();
let lastPaint='', lastPlaying=false, renderedFrames=0;
el('reducedMotion').checked=matchMedia('(prefers-reduced-motion: reduce)').matches;
const stamp=(t)=>`00:${Math.floor(Math.max(0,t)).toString().padStart(2,'0')}`;
function error(message){el('error').textContent=message;el('error').hidden=false;}
function controls(){
  document.querySelectorAll('#play,#restart,#seek,#angle,[data-view],#reducedMotion,#script button').forEach((item)=>{item.disabled=!ready||Boolean(recorder);});
  el('export').disabled=!ready;el('volume').disabled=Boolean(recorder);
  el('export').textContent=recorder?'Hủy xuất video':'Tải video WebM ↓';
}
function transport(){
  el('play').textContent=!audio.paused?'Ⅱ Tạm dừng':audio.ended?'▶ Nghe lại':started?'▶ Nghe tiếp':'▶ Nghe bản thử';
  el('status').textContent=recorder?'Đang ghi video · giữ trang hiển thị':!audio.paused?'Syna đang thuyết trình':audio.ended?'Đã nghe hết bản thử':started?'Đã tạm dừng':'Sẵn sàng · bấm nghe để bắt đầu';
}
function setView(degrees){
  const bounded=Number.isFinite(Number(degrees))?clamp(Number(degrees),-180,180):0;
  viewAngle=bounded*Math.PI/180;el('angle').value=String(bounded);el('angleValue').textContent=`${bounded}°`;
  document.querySelectorAll('[data-view]').forEach((b)=>b.setAttribute('aria-pressed',String(Number(b.dataset.view)===bounded)));
}
async function play(){
  if(!ready)return;await audioContext.resume();
  if(audioContext.state!=='running')throw new Error('Bấm nghe lại để bật âm thanh trong trình duyệt.');
  if(audio.ended)audio.currentTime=0;await audio.play();started=true;transport();
}
function frame(now){
  frameId=requestAnimationFrame(frame);
  if(!ready||document.hidden||now-lastFrame<1000/30-1)return;lastFrame=now;
  const time=audio.currentTime||0, playing=!audio.paused&&!audio.ended;
  const signature=[time,playing,viewAngle,el('reducedMotion').checked,started].join('/');
  // A paused/static model needs no repeated WebGL draws; controls still invalidate it.
  if(!playing&&!recorder&&signature===lastPaint)return;
  lastPaint=signature;
  if(playing!==lastPlaying){statsStart=now;frameCount=0;renderTotal=0;lastPlaying=playing;}
  const mouth=mouthAt(envelope,time,playing), pose=pilotPose(time,mouth,el('reducedMotion').checked);
  const cue=started?cueAt(cues,time):null;
  const before=performance.now(), stats=scene.draw(pose,viewAngle);
  drawStage(ctx,scene,pose,cue?.text??(started?'':'Chào bạn! Syna 3D đã sẵn sàng.'));
  frameCount+=1;renderTotal+=performance.now()-before;
  stage.dataset.renderedFrames=String(++renderedFrames);
  stage.dataset.triangles=String(stats.triangles);stage.dataset.geometries=String(stats.geometries);
  stage.dataset.time=time.toFixed(3);stage.dataset.mouth=mouth.toFixed(3);stage.dataset.headYaw=pose.headYaw.toFixed(3);
  stage.dataset.view=viewAngle.toFixed(3);stage.dataset.board=String(pose.highlight);stage.dataset.phase=pose.phase;
  el('time').textContent=`${stamp(time)} / 00:15`;el('seek').value=String(time);
  el('seek').setAttribute('aria-valuetext',`${stamp(time)} trên 00:15`);
  const note=pose.board?'Chiết xuất rau má · Centella asiatica · hỗ trợ làm dịu (tham khảo).':'Bảng thành phần sẽ sáng lên khi Syna giới thiệu.';
  if(el('boardNote').textContent!==note)el('boardNote').textContent=note;
  document.querySelectorAll('#script button').forEach((b,i)=>{const active=cue===cues[i];b.classList.toggle('active',active);if(active)b.setAttribute('aria-current','true');else b.removeAttribute('aria-current');});
  if(playing&&now-statsStart>2000){
    const measured=frameCount/((now-statsStart)/1000);
    el('stats').textContent=`${stats.triangles.toLocaleString('vi-VN')} tam giác/lượt hiển thị · ${stats.calls} lượt vẽ · ${measured.toFixed(1)} khung/giây quan sát · ${(renderTotal/frameCount).toFixed(1)} ms/vẽ. Đây không phải số đo VRAM.`;
    stage.dataset.fps=measured.toFixed(1);stage.dataset.renderMs=(renderTotal/frameCount).toFixed(1);
    statsStart=now;frameCount=0;renderTotal=0;
  }
  if(!playing)el('stats').textContent='Đang đứng yên · không vẽ lặp lại. Bấm nghe để đo tốc độ khung hình.';
}
function stopRecording(message){
  cancelled=true;audio.pause();if(recorder&&recorder.state!=='inactive')recorder.stop();el('exportNote').textContent=message;
}
async function exportVideo(){
  if(recorder){stopRecording('Đã hủy xuất, không lưu bản ghi dở.');return;}if(!ready)return;
  const mime=['video/webm;codecs=vp8,opus','video/webm;codecs=vp9,opus','video/webm'].find((value)=>window.MediaRecorder?.isTypeSupported(value));
  if(!mime||!stage.captureStream){error('Trình duyệt chưa hỗ trợ xuất WebM. Hãy thử Chrome hoặc Edge.');return;}
  try{
    audio.pause();audio.currentTime=0;setView(0);await audioContext.resume();
    if(audioContext.state!=='running')throw new Error('Chưa bật được âm thanh. Bấm nghe thử trước.');
    stream=new MediaStream([...stage.captureStream(30).getVideoTracks(),...recordingAudio.stream.getAudioTracks().map((track)=>track.clone())]);
    recorder=new MediaRecorder(stream,{mimeType:mime,videoBitsPerSecond:3000000,audioBitsPerSecond:128000});
    cancelled=false;const chunks=[];
    recorder.addEventListener('dataavailable',(event)=>{if(event.data.size)chunks.push(event.data);});
    recorder.addEventListener('stop',()=>{
      stream?.getTracks().forEach((track)=>track.stop());stream=null;recorder=null;controls();transport();
      if(cancelled)return;if(!chunks.length){error('Không có khung hình được ghi. Hãy thử lại.');return;}
      const exportBlob=new Blob(chunks,{type:mime});
      if(downloadUrl)URL.revokeObjectURL(downloadUrl);downloadUrl=URL.createObjectURL(exportBlob);
      const link=document.createElement('a');link.href=downloadUrl;link.download='syna-3d-pilot.webm';document.body.append(link);link.click();link.remove();
      el('exportNote').textContent='Đã xuất video 3D có âm thanh. Bản 2D vẫn được giữ nguyên.';
    },{once:true});
    recorder.addEventListener('error',()=>{stopRecording('Xuất video thất bại.');error('Không ghi được video trong trình duyệt này.');});
    controls();started=true;const pose=pilotPose(0,0,el('reducedMotion').checked);scene.draw(pose);drawStage(ctx,scene,pose,cues[0].text);
    recorder.start(300);await play();
  }catch(reason){
    if(recorder&&recorder.state!=='inactive')stopRecording('Đã dừng xuất video.');
    else{stream?.getTracks().forEach((track)=>track.stop());stream=null;recorder=null;controls();}
    error(`Không xuất được video: ${reason.message}`);
  }
}
async function initialize(){
  try{
    const [{PilotScene},speech,metadata]=await Promise.all([import('./scene.js?v=7'),fetch('./assets/syna-pilot.wav'),fetch('./assets/syna-pilot.json')]);
    if(!speech.ok||!metadata.ok)throw new Error('Thiếu lời thoại 3D mẫu. Bản 2D vẫn sử dụng được.');
    const data=await metadata.json();
    if(data.duration!==DURATION||!Array.isArray(data.cues)||data.cues.length!==3||!data.cues.every((c,i)=>Number.isFinite(c.start)&&Number.isFinite(c.end)&&c.start>=0&&c.end>c.start&&c.end<=DURATION&&(i===0||c.start>=data.cues[i-1].end)&&typeof c.text==='string'))throw new Error('Mốc lời thoại mẫu không hợp lệ.');
    cues=data.cues;
    const AudioContext=window.AudioContext||window.webkitAudioContext;if(!AudioContext)throw new Error('Trình duyệt không hỗ trợ Web Audio.');
    audioContext=new AudioContext();const bytes=await speech.arrayBuffer(),decoded=await audioContext.decodeAudioData(bytes.slice(0));
    if(Math.abs(decoded.duration-DURATION)>.05)throw new Error('Thời lượng lời thoại không khớp bản thử.');
    envelope=audioEnvelope(decoded.getChannelData(0),decoded.sampleRate);
    audioUrl=URL.createObjectURL(new Blob([bytes],{type:'audio/wav'}));audio.src=audioUrl;
    const source=audioContext.createMediaElementSource(audio);source.connect(audioContext.destination);
    recordingAudio=audioContext.createMediaStreamDestination();source.connect(recordingAudio);
    scene=new PilotScene();
    scene.renderer.domElement.addEventListener('webglcontextlost',(event)=>{
      event.preventDefault();audio.pause();if(recorder)stopRecording('Đã hủy ghi vì mất kết nối đồ họa.');ready=false;stage.dataset.ready='false';controls();
      error('Trình duyệt mất kết nối đồ họa. Tải lại trang hoặc trở về bản 2D.');
    });
    el('loading').textContent='Đang chuẩn bị chuyển động và ánh sáng…';
    await scene.warmup([pilotPose(0),pilotPose(1.8,.8),pilotPose(6,.6),pilotPose(0)]);
    scene.draw(pilotPose(0));drawStage(ctx,scene,pilotPose(0),'Chào bạn! Syna 3D đã sẵn sàng.');
    cues.forEach((cue)=>{
      const li=document.createElement('li'),button=document.createElement('button'),time=document.createElement('time'),text=document.createElement('span');
      button.type='button';time.textContent=stamp(cue.start);text.textContent=cue.text;button.append(time,text);
      button.addEventListener('click',async()=>{if(!ready||recorder)return;audio.currentTime=cue.start;try{await play();}catch(reason){error(reason.message);}});
      li.append(button);el('script').append(li);
    });
    statsStart=performance.now();ready=true;controls();transport();el('loading').hidden=true;stage.dataset.ready='true';frameId=requestAnimationFrame(frame);
  }catch(reason){
    scene?.dispose();audioContext?.close();if(audioUrl)URL.revokeObjectURL(audioUrl);
    el('loading').textContent='Chưa mở được bản thử 3D.';error(`${reason.message} Bạn có thể dùng liên kết So sánh với bản 2D ở phía trên.`);
  }
}
el('play').addEventListener('click',async()=>{if(!ready||recorder)return;if(!audio.paused)audio.pause();else try{await play();}catch(reason){error(reason.message);}});
el('restart').addEventListener('click',async()=>{if(!ready||recorder)return;audio.currentTime=0;try{await play();}catch(reason){error(reason.message);}});
el('seek').addEventListener('input',()=>{if(!ready||recorder)return;started=true;audio.currentTime=clamp(Number(el('seek').value),0,DURATION);});
el('angle').addEventListener('input',()=>{if(ready&&!recorder)setView(el('angle').value);});
document.querySelectorAll('[data-view]').forEach((button)=>button.addEventListener('click',()=>{if(ready&&!recorder)setView(button.dataset.view);}));
el('volume').addEventListener('input',()=>{audio.volume=Number(el('volume').value);});el('export').addEventListener('click',exportVideo);
['play','pause'].forEach((name)=>audio.addEventListener(name,transport));
audio.addEventListener('ended',()=>{if(recorder?.state==='recording')recorder.stop();transport();});
audio.addEventListener('error',()=>{if(recorder)stopRecording('Đã hủy vì lỗi âm thanh.');error('Không phát được âm thanh mẫu. Hãy tải lại trang.');});
document.addEventListener('visibilitychange',()=>{if(document.hidden){if(recorder)stopRecording('Đã hủy xuất vì trang bị ẩn.');else audio.pause();}statsStart=performance.now();frameCount=0;renderTotal=0;});
window.addEventListener('pagehide',(event)=>{audio.pause();if(recorder)stopRecording('Đã dừng ghi.');if(event.persisted)return;cancelAnimationFrame(frameId);scene?.dispose();audioContext?.close();if(audioUrl)URL.revokeObjectURL(audioUrl);if(downloadUrl)URL.revokeObjectURL(downloadUrl);});
initialize();
