import * as THREE from './vendor/three.module.min.js';

export const HEAD = Object.freeze({ x: .79, y: .655, z: .745 });
export const FACE = Object.freeze({ eyeX: .365, eyeY: -.105, eyeWidth: .265, eyeHeight: .278 });
export const ARM_LENGTH = .58;
export const BODY_DEPTH = .97;
export const BODY_PROFILE = new THREE.SplineCurve([
  [.30,.385],[.465,.41],[.51,.52],[.49,.75],[.41,.97],[.29,1.13],
].map((p) => new THREE.Vector2(...p))).getPoints(32);

// A fuller lower face makes soft cheeks, rather than a pointed chin/beak.
function crossSection(y) {
  return Math.max(0, 1 - Math.abs(y / HEAD.y) ** (y < 0 ? 3.4 : 2.15));
}

/** Shared by the solid skull AND all facial details, including animated lids. */
export function faceSurface(x, y) {
  const front = Math.sqrt(Math.max(0, crossSection(y) - (x / HEAD.x) ** 2));
  const muzzle = .020 * Math.exp(-((x / .24) ** 2) - (((y + .27) / .19) ** 2));
  const cheeks = .022 * Math.exp(-(((Math.abs(x) - .36) / .21) ** 2) - (((y + .25) / .23) ** 2));
  return HEAD.z * front + (muzzle + cheeks) * front ** 2;
}

export function createSkullGeometry() {
  const geometry = new THREE.SphereGeometry(1, 64, 48), position = geometry.attributes.position;
  for (let i = 0; i < position.count; i += 1) {
    const uy = position.getY(i), y = uy * HEAD.y;
    const section = Math.sqrt(crossSection(y) / Math.max(.000001, 1 - uy * uy));
    const x = position.getX(i) * HEAD.x * section, z = position.getZ(i) * section;
    position.setXYZ(i, x, y, z > 0 ? faceSurface(x, y) : z * HEAD.z);
  }
  geometry.computeVertexNormals(); geometry.computeBoundingBox();
  return geometry;
}

/** Curved surface markings, not floating ellipsoids or a front-facing card. */
export function createFacePatch(cx, cy, rx, ry, offset = .006, bulge = 0) {
  const positions = [], uv = [], indices = [], rings = 6, segments = 40;
  positions.push(0, 0, 0); uv.push(.5, .5);
  for (let ring = 1; ring <= rings; ring += 1) {
    for (let j = 0; j <= segments; j += 1) {
      const a = j / segments * Math.PI * 2, r = ring / rings;
      positions.push(0, 0, 0); uv.push((Math.cos(a) * r + 1) / 2, (Math.sin(a) * r + 1) / 2);
      if (j < segments) {
        const current = 1 + (ring - 1) * (segments + 1) + j;
        if (ring === 1) indices.push(0, current, current + 1);
        else {
          const previous = current - segments - 1;
          indices.push(previous, current, previous + 1, previous + 1, current, current + 1);
        }
      }
    }
  }
  const geometry = new THREE.BufferGeometry(); geometry.setIndex(indices);
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
  geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  geometry.userData.facePatch = { cx, cy, rx, ry, offset, bulge };
  fitFacePatch(geometry); return geometry;
}

export function fitFacePatch(geometry, openness = 1, gaze = 0, yOffset = 0, pivotY = geometry.userData.facePatch.cy) {
  const key = `${openness}/${gaze}/${yOffset}/${pivotY}`;
  if (geometry.userData.lastFit === key) return;
  geometry.userData.lastFit = key;
  const { cx, cy, rx, ry, offset, bulge } = geometry.userData.facePatch;
  const position = geometry.attributes.position, uv = geometry.attributes.uv;
  for (let i = 0; i < position.count; i += 1) {
    const u = uv.getX(i) * 2 - 1, v = uv.getY(i) * 2 - 1;
    const x = cx + u * rx + gaze, y = pivotY + (cy - pivotY + v * ry) * openness + yOffset;
    position.setXYZ(i, x, y, faceSurface(x, y) + offset + bulge * Math.max(0, 1 - u * u - v * v));
  }
  position.needsUpdate = true;
  geometry.computeVertexNormals(); geometry.computeBoundingSphere();
}

export function bodySurface(x, y) {
  let radius = BODY_PROFILE.at(-1).x;
  for (let i = 1; i < BODY_PROFILE.length; i += 1) {
    const a = BODY_PROFILE[i - 1], b = BODY_PROFILE[i];
    if (y >= a.y && y <= b.y) { radius = THREE.MathUtils.lerp(a.x, b.x, (y - a.y) / (b.y - a.y)); break; }
  }
  return Math.sqrt(Math.max(0, radius * radius - x * x)) * BODY_DEPTH;
}
