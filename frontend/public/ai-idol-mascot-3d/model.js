import * as THREE from './vendor/three.module.min.js';
import { armWeights } from './timeline.js?v=6';
import { HEAD, FACE, ARM_LENGTH, BODY_DEPTH, BODY_PROFILE, faceSurface, bodySurface, createSkullGeometry, createFacePatch, fitFacePatch } from './anatomy.js?v=5';
import { createEyePaint, createBlushPaint, createHeadphoneBadge, createChestLogo } from './face-paint.js?v=9';

const C = { cream: '#ffece5', muzzle: '#fff1e9', forest: '#164b37', trim: '#28674a',
  pink: '#efa6a3', iris: '#267653', pupil: '#082c25', leaf: '#2f8f34' };
function material(color, roughness = 0.88) { return new THREE.MeshStandardMaterial({ color, roughness }); }
function mesh(parent, geometry, mat, position = [0, 0, 0], scale = [1, 1, 1]) {
  const item = new THREE.Mesh(geometry, mat); item.position.set(...position); item.scale.set(...scale);
  item.castShadow = true; item.receiveShadow = true; parent.add(item); return item;
}
const sphere = new THREE.SphereGeometry(1, 32, 24);
function oval(parent, mat, position, scale) { return mesh(parent, sphere, mat, position, scale); }
function tube(parent, points, radius, mat, segments = 24) {
  return mesh(parent, new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points.map((p) => new THREE.Vector3(...p))), segments, radius, 7, false), mat);
}
function extrude(shape, depth, bevel = 0.012) {
  return new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: true, bevelThickness: bevel,
    bevelSize: bevel, bevelSegments: 3, steps: 1, curveSegments: 18 });
}

function earShape() {
  // Big, upright, softly pointed triangle (reference style), symmetric so both sides match.
  const s = new THREE.Shape(); s.moveTo(-0.30, 0);
  s.bezierCurveTo(-0.29, 0.26, -0.12, 0.60, -0.03, 0.73);
  s.bezierCurveTo(-0.01, 0.765, 0.01, 0.765, 0.03, 0.73);
  s.bezierCurveTo(0.12, 0.60, 0.29, 0.26, 0.30, 0);
  s.bezierCurveTo(0.18, -0.07, -0.18, -0.07, -0.30, 0); return s;
}

function plushEar(depth, bevel, offset = [0,0,0], scale = [1,1,1]) {
  const geometry = extrude(earShape(),depth,bevel), position = geometry.attributes.position;
  for(let i=0;i<position.count;i+=1){
    const x=position.getX(i)*scale[0]+offset[0], y=position.getY(i)*scale[1]+offset[1];
    const z=position.getZ(i)*scale[2]+offset[2], t=THREE.MathUtils.clamp((y+.1)/.85,0,1);
    // A rounded base tapers toward a backward-swept tip, including its inner lining.
    position.setXYZ(i,x,y,.11+(z-.11)*(1-.6*t)-.025*t*t);
  }
  geometry.computeVertexNormals();return geometry;
}

function leafShape() {
  const s = new THREE.Shape(); s.moveTo(0, 0);
  // Broad scalloped fan with a basal notch, not a two-petal flower.
  const curves = [
    [-.15,.02,-.22,-.20,-.35,-.16],[-.44,-.16,-.44,-.06,-.48,-.02],
    [-.60,0,-.57,.09,-.57,.16],[-.65,.26,-.59,.34,-.54,.37],
    [-.58,.49,-.50,.53,-.42,.55],[-.40,.65,-.30,.65,-.24,.64],
    [-.17,.74,-.08,.71,-.03,.67],[.06,.74,.15,.70,.20,.65],
    [.31,.69,.39,.60,.40,.54],[.52,.55,.57,.45,.55,.39],
    [.66,.33,.62,.22,.57,.18],[.62,.06,.54,-.01,.47,-.02],
    [.46,-.12,.36,-.14,.27,-.08],[.13,-.04,.09,.01,0,0],
  ];
  curves.forEach((p) => s.bezierCurveTo(...p)); return s;
}

function centella(parent, position, scale, rotation, leafMat, veinMat) {
  const leaf = new THREE.Group(); parent.add(leaf); leaf.position.set(...position);
  leaf.scale.setScalar(scale); leaf.rotation.set(...rotation);
  mesh(leaf, extrude(leafShape(), .014, .008), leafMat);
  for (const [x, y] of [[-.45,-.03],[-.51,.22],[-.39,.48],[-.17,.60],[.07,.61],[.31,.52],[.47,.32],[.48,.09]]) {
    tube(leaf, [[0,0,.029],[x*.45,y*.28,.032],[x,y,.03]], .008, veinMat, 10);
  }
  return leaf;
}

/** One continuous skinned surface, including upper sleeve, elbow and paw. */
function makeArm(parent, side, mats) {
  const vertices = [], normals = [], indices = [], colors = [], skins = [], weights = [];
  const rows = 32, radial = 20, length = ARM_LENGTH;
  const green = new THREE.Color(C.forest), cream = new THREE.Color(C.cream);
  for (let row = 0; row <= rows; row += 1) {
    const t = row / rows, distance = t * length;
    // Short sleeve, then a round chubby paw that is a little wider than the wrist.
    const paw = .153 + .02 * Math.sin(THREE.MathUtils.clamp((t - .45) / .27, 0, 1) * Math.PI / 2);
    const radius = t < .43 ? .17 - t * .04
      : t < .72 ? paw
        : Math.max(.006, .173 * Math.sqrt(Math.max(0, 1 - ((t - .72) / .28) ** 2)));
    // A crisp sleeve cuff instead of a long green-to-cream smear.
    const blend = THREE.MathUtils.clamp((t - .44) / .035, 0, 1);
    const color = green.clone().lerp(cream, blend);
    for (let j = 0; j <= radial; j += 1) {
      const angle = j / radial * Math.PI * 2;
      vertices.push(Math.cos(angle) * radius + side * .12 * t * t, -distance, Math.sin(angle) * radius);
      normals.push(Math.cos(angle), 0, Math.sin(angle)); colors.push(color.r, color.g, color.b);
      skins.push(0, 1, 2, 0); weights.push(...armWeights(t * .74));
      if (row < rows && j < radial) {
        const a = row * (radial + 1) + j, b = a + radial + 1;
        indices.push(a, a + 1, b, a + 1, b + 1, b);
      }
    }
  }
  // Close both ends of the same skinned surface, so raised sleeves/paws have no holes.
  for (const end of [0, 1]) {
    const center=vertices.length/3, distance=end*length, color=green;
    vertices.push(side*.12*end,-distance,0);normals.push(0,end?-1:1,0);
    colors.push(color.r,color.g,color.b);skins.push(0,1,2,0);weights.push(...armWeights(end*.74));
    const ring=end?rows*(radial+1):0;
    for(let j=0;j<radial;j+=1){
      if(end)indices.push(center,ring+j,ring+j+1);
      else indices.push(center,ring+j+1,ring+j);
    }
  }
  const geometry = new THREE.BufferGeometry(); geometry.setIndex(indices);
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3));
  geometry.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
  geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
  geometry.setAttribute('skinIndex', new THREE.Uint16BufferAttribute(skins, 4));
  geometry.setAttribute('skinWeight', new THREE.Float32BufferAttribute(weights, 4));
  geometry.computeVertexNormals();
  const arm = new THREE.SkinnedMesh(geometry, mats.arm);
  arm.name = side < 0 ? 'left-continuous-arm' : 'right-continuous-arm';
  arm.position.set(side * .345, 1.035, .07); arm.scale.set(1.1, .8, 1.1); parent.add(arm); // short, chubby arms
  const upper = new THREE.Bone(), elbow = new THREE.Bone(), wrist = new THREE.Bone();
  upper.name = `${arm.name}-shoulder`; elbow.name = `${arm.name}-elbow`; wrist.name = `${arm.name}-wrist`;
  upper.add(elbow); elbow.position.y = -.33 * length / .74;
  elbow.add(wrist); wrist.position.y = -.31 * length / .74;
  arm.add(upper); arm.bind(new THREE.Skeleton([upper, elbow, wrist]));
  arm.castShadow = true; arm.receiveShadow = true;
  // A rounded shoulder centred on the arm pivot, matching the sleeve radius, so the
  // sleeve reads as one capsule with the hoodie at every arm angle.
  const bridge = oval(parent, mats.forest, [side * .345, 1.035, .07], [.19, .19, .19]);
  bridge.name = side < 0 ? 'left-sleeve-shoulder-bridge' : 'right-sleeve-shoulder-bridge';
  return { arm, upper, elbow, wrist };
}

function bodyDecal(parent, texture, width, height, centerY) {
  const mat = new THREE.MeshStandardMaterial({ map: texture, transparent: true, roughness: .85, depthWrite: false });
  const geometry = new THREE.PlaneGeometry(width, height, 16, 8), position = geometry.attributes.position;
  for (let i = 0; i < position.count; i += 1) {
    const x = position.getX(i), y = position.getY(i) + centerY;
    position.setXYZ(i, x, y, bodySurface(x, y) + .009);
  }
  geometry.computeVertexNormals();
  const decal = mesh(parent, geometry, mat); decal.castShadow = false; return decal;
}

function chestLabel(parent) {
  const canvas = document.createElement('canvas'); canvas.width = 512; canvas.height = 128;
  const ctx = canvas.getContext('2d'); ctx.fillStyle = '#e9f4db'; ctx.font = '500 78px "Segoe UI",sans-serif';
  ctx.textAlign = 'center'; ctx.fillText('SkinSyntax', 256, 90);
  const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
  return bodyDecal(parent, texture, .54, .135, .665);
}

export class Syna3D {
  constructor() {
    this.root = new THREE.Group(); this.root.name = 'Syna-3D-pilot'; this.root.position.x = -.52;
    this.root.scale.setScalar(1.06);
    this.body = new THREE.Group(); this.root.add(this.body);
    this.mats = { cream: material(C.cream), muzzle: material(C.muzzle), forest: material(C.forest),
      trim: material(C.trim), pink: material(C.pink), leaf: material(C.leaf), vein: material('#25702b'),
      pupil: material(C.pupil, .75), white: new THREE.MeshBasicMaterial({ color: '#ffffff' }),
      iris: material(C.iris, .75), gold: material('#d7b664', .65),
      arm: new THREE.MeshStandardMaterial({ vertexColors: true, roughness: .9 }) };
    this.buildBody(); this.buildHead();
    this.left = makeArm(this.body, -1, this.mats); this.right = makeArm(this.body, 1, this.mats);
  }

  buildBody() {
    const m = this.mats;
    const torso = mesh(this.body, new THREE.LatheGeometry(BODY_PROFILE, 48), m.forest, [0,0,0], [1,1,BODY_DEPTH]);
    torso.name = 'syna-rounded-torso';
    oval(this.body, m.forest, [0,.40,0], [.49,.05,.47]);
    oval(this.body, m.forest, [0,1.085,-.085], [.35,.14,.32]);
    mesh(this.body, new THREE.CapsuleGeometry(.165,.18,8,24), m.cream, [0,1.225,0]);
    tube(this.body, [[-.245,1.145,.15],[-.15,1.12,.25],[0,1.055,.33],[.15,1.12,.25],[.245,1.145,.15]], .042, m.forest);
    this.drawstrings = new THREE.Group(); this.drawstrings.name = 'syna-hoodie-drawstrings'; this.body.add(this.drawstrings);
    for (const side of [-1, 1]) {
      tube(this.drawstrings, [[side * .075, 1.135, .315], [side * .078, 1.055, .375], [side * .073, .985, .420]], .010, m.cream, 12);
      oval(this.drawstrings, m.cream, [side * .073, .978, .425], [.021, .026, .015]);
    }
    tube(this.body, [[-.22,.555],[-.18,.515],[0,.49],[.18,.515],[.22,.555]].map(([x,y])=>[x,y,bodySurface(x,y)+.008]), .005, m.trim);
    const logo = bodyDecal(this.body, createChestLogo(), .2, .2, .84);
    logo.name = 'syna-chest-sprout-logo';
    chestLabel(this.body);
    this.feet = [];
    for (const side of [-1,1]) {
      // Feet belong to the same torso hierarchy so breathing, a tiny body roll,
      // and head/shoulder gestures never make the paws look detached.
      const foot = new THREE.Group(); foot.position.set(side*.19,.24,0); this.body.add(foot);
      // Short, close-set legs tucked under the hem, with chunky boots that meet them.
      mesh(foot, new THREE.CapsuleGeometry(.17,.06,8,24), m.cream);
      oval(foot, m.forest, [0,-.11,.04], [.19,.14,.21]);
      this.feet.push({ node: foot, side });
    }
    this.tail = new THREE.Group(); this.tail.position.set(.30,.59,-.31); this.body.add(this.tail);
    const path = [[0,0,0],[.16,-.10,-.18],[.31,-.06,-.32],[.43,.13,-.36],[.40,.27,-.30]];
    tube(this.tail, path, .12, m.cream, 24); oval(this.tail, m.cream, path.at(-1), [.123,.14,.125]);
  }

  buildHead() {
    const m = this.mats;
    this.neck = new THREE.Group(); this.neck.position.y = 1.22; this.body.add(this.neck);
    this.head = new THREE.Group(); this.head.position.y = .635; this.neck.add(this.head);
    this.head.scale.set(1.07, 1.0, 1.04); // a touch wider and chubbier, like the reference art
    const skull = mesh(this.head, createSkullGeometry(), m.cream); skull.name = 'syna-solid-skull';
    this.ears = [];
    for (const side of [-1,1]) {
      const ear = new THREE.Group(); ear.position.set(side*.47,.40,-.10);
      ear.scale.set(.92,.78,.9); ear.rotation.z = -side*.30; ear.rotation.y = side*.12; this.head.add(ear);
      this.ears.push({ node: ear, side, baseZ: -side * .30 });
      mesh(ear, plushEar(.22,.060), m.cream);
      mesh(ear, plushEar(.010,.022,[0,.075,.262],[.68,.72,1]), material('#a3b8ad', .92));
    }
    this.buildFace(); this.buildHeadset();
    this.plant = new THREE.Group(); this.plant.position.set(0,.55,-.005); this.head.add(this.plant);
    this.plant.scale.set(.95,.82,.95); // sprout hugs the crown instead of towering over it
    tube(this.plant, [[0,0,0],[-.03,.17,.018],[-.13,.38,.03]], .014, m.vein, 16);
    tube(this.plant, [[.02,0,0],[.08,.13,.04],[.22,.24,.05]], .012, m.vein, 16);
    centella(this.plant, [-.13,.38,.03], .47, [.04,-.23,-.10], m.leaf, m.vein);
    centella(this.plant, [.22,.24,.05], .28, [.04,.48,-.25], m.leaf, m.vein);
  }

  buildFace() {
    const m = this.mats; this.eyes = [];
    const patch = (parent, mat, x, y, rx, ry, lift, bulge=0) => mesh(parent, createFacePatch(x,y,rx,ry,lift,bulge), mat);
    const curve = (points, lift=.008) => points.map(([x,y])=>[x,y,faceSurface(x,y)+lift]);
    const blushMat = new THREE.MeshBasicMaterial({ map: createBlushPaint(), transparent: true, depthWrite: false, toneMapped: false });
    // "Baby face" layout: eyes sit low and wide, features cluster in the lower half.
    const eyeX = .375, eyeY = -.16, eyeW = .27, eyeH = .285;
    for (const side of [-1,1]) {
      const paint = createEyePaint(side);
      const eyeMat = new THREE.MeshBasicMaterial({ map: paint.texture, transparent: true, depthWrite: false, toneMapped: false });
      const eye = patch(this.head, eyeMat, side * eyeX, eyeY, eyeW, eyeH, .009);
      eye.name = side < 0 ? 'syna-left-painted-eye' : 'syna-right-painted-eye';
      eye.castShadow = false; eye.receiveShadow = false; eye.renderOrder = 2; this.eyes.push(paint);
      const blush = patch(this.head,blushMat,side*.53,-.385,.17,.10,.007);
      blush.castShadow = false; blush.receiveShadow = false; blush.renderOrder = 1;
      for (const offset of [-.025,.025]) {
        tube(this.head, curve([[side*.63,-.335+offset],[side*.68,-.33+offset],[side*.73,-.31+offset]]), .004, material('#76564e'), 14);
      }
    }
    oval(this.head,m.pink,[0,-.30,faceSurface(0,-.30)+.007],[.042,.025,.022]);
    this.openMouth = patch(this.head,material('#713c3d'),0,-.40,.05,.06,.012);
    this.tongue = patch(this.head,m.pink,0,-.432,.031,.022,.020);
    this.smile = tube(this.head, curve([[-.064,-.343],[-.047,-.364],[-.024,-.368],[0,-.350],[.024,-.368],[.047,-.364],[.064,-.343]],.014), .0065, material('#654139'), 32);
    tube(this.head, curve([[0,-.318],[0,-.334],[0,-.350]], .012), .0055, material('#654139'), 8);
  }

  buildHeadset() {
    const m = this.mats, arc = [];
    for (let i = 0; i <= 32; i += 1) { const a = i / 32 * Math.PI; arc.push([Math.cos(a)*HEAD.x*.96,Math.sin(a)*HEAD.y*.97,0]); }
    // Wide, rounded band along the crown, not a single thin wire.
    const band = tube(this.head, arc, .031, m.forest, 48);
    band.scale.z = 2.1; band.position.z = .21;
    const badgeMat = new THREE.MeshBasicMaterial({ map: createHeadphoneBadge(), transparent: true, depthWrite: false, toneMapped: false });
    const ringMat = new THREE.MeshBasicMaterial({ color: '#5ff0b4', toneMapped: false });
    for (const side of [-1,1]) {
      tube(this.head, [[side*.758,0,.21],[side*.775,-.025,.17],[side*.792,-.055,.11]], .028, m.forest, 12);
      const cup = new THREE.Group(); cup.position.set(side*.79, -.085, .03); cup.rotation.y = -side*.42; this.head.add(cup);
      oval(cup, m.pupil, [-side*.04,0,0], [.12,.255,.23]);          // soft cushion against the head
      oval(cup, m.forest, [side*.03,0,0], [.115,.235,.215]);        // outer shell
      const ring = mesh(cup, new THREE.TorusGeometry(.165,.011,10,40), ringMat, [side*.115,0,0]); ring.rotation.y = Math.PI/2; ring.castShadow = false;
      const badge = mesh(cup, new THREE.PlaneGeometry(.2,.28), badgeMat, [side*.15,0,0]); badge.rotation.y = side*Math.PI/2; badge.castShadow = false;
    }
  }

  update(pose, viewAngle = 0) {
    this.root.rotation.y = viewAngle;
    this.body.position.y = pose.breath-.035; this.body.rotation.y = pose.torsoYaw;
    this.body.rotation.z = pose.torsoRoll ?? 0;
    this.neck.rotation.x = pose.headNod ?? 0;
    this.neck.rotation.y = pose.headYaw; this.neck.rotation.z = pose.headTilt;
    this.tail.rotation.z = pose.tail; this.plant.rotation.z = pose.leaf;
    for (const ear of this.ears) ear.node.rotation.z = ear.baseZ + ear.side * (pose.earSway ?? 0);
    for (const foot of this.feet) foot.node.rotation.z = pose[foot.side < 0 ? 'leftFoot' : 'rightFoot'] ?? 0;
    this.left.upper.rotation.z = pose.leftArm; this.left.elbow.rotation.z = pose.leftElbow;
    this.left.wrist.rotation.z = pose.leftWrist;
    this.right.upper.rotation.z = pose.rightArm; this.right.elbow.rotation.z = pose.rightElbow;
    this.right.wrist.rotation.z = pose.rightWrist;
    for (const eye of this.eyes) eye.update(pose.blink, pose.gaze);
    // At rest Syna keeps a small open ":3" smile with a peek of tongue; speech opens it further.
    const mouth = Math.max(pose.mouth, .2);
    this.openMouth.visible = true; this.tongue.visible = true;
    this.smile.visible = true;
    // Keep the mouth on the centre line; speech changes openness only.
    // The opening hangs from the ω upper lip (same width), so it never looks like a moustache.
    const open = .15 + mouth * .75, tongueOpen = .3 + mouth * .7;
    fitFacePatch(this.openMouth.geometry, open, 0, 0, -.36);
    // The tongue rests on the inner lower lip, whatever the opening.
    const lowerLip = -.36 - .10 * open, tongueY = lowerLip + .022 * tongueOpen * .75;
    fitFacePatch(this.tongue.geometry, tongueOpen, 0, tongueY + .432, -.432);
  }
}
