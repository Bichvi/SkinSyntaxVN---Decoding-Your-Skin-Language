import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pilotPose, armWeights, cueAt, audioEnvelope, mouthAt, DURATION } from '../timeline.js';
import { HEAD, FACE, ARM_LENGTH, BODY_PROFILE, BODY_DEPTH, faceSurface, createSkullGeometry, createFacePatch, fitFacePatch, bodySurface } from '../anatomy.js';

test('pilot waves, turns in real yaw, points and returns within fifteen seconds',()=>{
  assert.ok(pilotPose(1.5).leftArm < -1.8);
  assert.ok(pilotPose(6).headYaw > .5 && pilotPose(6).torsoYaw > .1);
  assert.ok(pilotPose(6).point > .9 && pilotPose(6).rightArm > 1);
  assert.equal(pilotPose(11).headYaw,0); assert.equal(pilotPose(11).point,0);
  assert.equal(pilotPose(11).board,true);assert.equal(pilotPose(11).highlight,false);
});
test('bone weights remain normalized and smoothly transfer through elbow/wrist',()=>{
  let previous=armWeights(0);
  for(let i=0;i<=740;i+=1){
    const weights=armWeights(i/1000);
    assert.ok(weights.every((w)=>w>=0&&w<=1));
    assert.ok(Math.abs(weights.reduce((a,b)=>a+b,0)-1)<1e-9);
    assert.ok(weights.every((w,index)=>Math.abs(w-previous[index])<.02));previous=weights;
  }
  assert.deepEqual(armWeights(0),[1,0,0,0]);assert.ok(armWeights(.74)[2]>.99);
});
test('all joint positions are finite and continuous at 60Hz',()=>{
  let previous=pilotPose(0);
  for(let t=1/60;t<DURATION;t+=1/60){
    const pose=pilotPose(t);
    for(const key of ['headYaw','torsoYaw','leftArm','rightArm','leftElbow','rightElbow','leaf']){
      assert.ok(Number.isFinite(pose[key]));assert.ok(Math.abs(pose[key]-previous[key])<.13,key);
    }previous=pose;
  }
});
test('reduced motion removes gestures, but keeps audio-driven mouth and board',()=>{
  const pose=pilotPose(6,.5,true);
  assert.equal(pose.headYaw,0);assert.equal(pose.point,0);assert.equal(pose.breath,0);
  assert.equal(pose.blink,1);assert.equal(pose.highlight,true);assert.equal(pose.mouth,.5);
});
test('silence/pause close the mouth and irregular blink timing is deterministic',()=>{
  assert.equal(mouthAt(audioEnvelope(new Float32Array(100),1000),.02,true),0);
  const e=audioEnvelope(new Float32Array(100).fill(.4),1000);
  assert.ok(mouthAt(e,.04,true)>.6);assert.equal(mouthAt(e,.04,false),0);
  assert.equal(mouthAt(e,2,true),0);assert.ok(pilotPose(1.8).blink<.01);
  assert.deepEqual(pilotPose(6),pilotPose(6));
});
test('actual fifteen-second WAV and measured captions agree; gaps have no borrowed caption',()=>{
  const data=JSON.parse(readFileSync(new URL('../assets/syna-pilot.json',import.meta.url)));
  const wav=readFileSync(new URL('../assets/syna-pilot.wav',import.meta.url));
  let size=0,rate=0;
  for(let p=12;p+8<wav.length;){const kind=wav.toString('ascii',p,p+4),n=wav.readUInt32LE(p+4);if(kind==='fmt ')rate=wav.readUInt32LE(p+16);if(kind==='data')size=n;p+=n+8+n%2;}
  assert.equal(size/rate,15);assert.equal(data.cues.length,3);
  assert.equal(cueAt(data.cues,data.cues[0].end),null);
  assert.equal(cueAt(data.cues,data.cues[1].start).board,'centella');
  assert.ok(data.cues[2].end<15);assert.equal(cueAt(data.cues,15),null);
});

test('solid skull is round in profile, with integrated muzzle and cheek depth',()=>{
  const geometry=createSkullGeometry(),bounds=geometry.boundingBox;
  const ratio=(bounds.max.z-bounds.min.z)/(bounds.max.x-bounds.min.x);
  assert.ok(ratio>=.90&&ratio<=1.15,`actual skull depth/width ${ratio}`);
  assert.ok(BODY_DEPTH>=.90&&BODY_DEPTH<=1);
  assert.ok(faceSurface(0,-.27)<HEAD.z+.015,'short muzzle must not protrude into a beak');
  const p=geometry.attributes.position;
  for(let i=0;i<p.count;i+=1){
    assert.ok([p.getX(i),p.getY(i),p.getZ(i)].every(Number.isFinite));
    if(p.getZ(i)>.02)assert.ok(Math.abs(p.getZ(i)-faceSurface(p.getX(i),p.getY(i)))<.00001);
  }
  geometry.dispose();
});

test('eye markings follow skull curvature during blink and gaze, without flat floating discs',()=>{
  const geometry=createFacePatch(FACE.eyeX,FACE.eyeY,FACE.eyeWidth,FACE.eyeHeight,.009);
  for(const [blink,gaze] of [[1,0],[.5,.035],[.15,-.035],[1,0]]){
    fitFacePatch(geometry,blink,gaze,0,FACE.eyeY);
    const p=geometry.attributes.position;
    let min=Infinity,max=-Infinity;
    for(let i=0;i<p.count;i+=1){
      const depth=p.getZ(i)-faceSurface(p.getX(i),p.getY(i));
      assert.ok(depth>.0089&&depth<.0091,`surface distance ${depth}`);
      min=Math.min(min,p.getZ(i));max=Math.max(max,p.getZ(i));
    }
    assert.ok(max-min>.09,'eye follows the curved cheek, not a flat Z plane');
  }
  geometry.dispose();
});

test('approved silhouette has lower wide eyes, short arms and a compact round hoodie',()=>{
  assert.ok(FACE.eyeY<-.07&&FACE.eyeX>.35);
  assert.ok(ARM_LENGTH<.62&&ARM_LENGTH>.50);
  assert.ok(BODY_PROFILE.at(-1).y<1.16);
  assert.ok(bodySurface(0,.665)>bodySurface(.27,.665)+.06);
  for(const y of [.6,.665,.77,.835,.96])assert.ok(bodySurface(0,y)>.35);
});
