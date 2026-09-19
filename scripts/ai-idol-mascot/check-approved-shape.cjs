const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const output = path.resolve('report/ai-idol-syna-3d-approved');
  await fs.mkdir(output, { recursive: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
    await page.goto('http://localhost:8080/ai-idol-mascot-3d/index.html?rev=syna-3d-v3');
    await page.waitForSelector('#stage[data-ready="true"]');
    const result = await page.evaluate(async () => {
      const [{ Syna3D }, anatomy, { pilotPose }, THREE, { createEyePaint }] = await Promise.all([
        import('./model.js?v=4'), import('./anatomy.js?v=4'), import('./timeline.js?v=4'),
        import('./vendor/three.module.min.js'), import('./face-paint.js?v=4'),
      ]);
      const model = new Syna3D(), eye = createEyePaint(-1);
      const initial = eye.texture.version; eye.update(1, 0);
      const cached = eye.texture.version === initial;
      const pixels = () => eye.texture.image.getContext('2d').getImageData(0, 0, 384, 384).data;
      const opaque = (data) => { let n = 0; for (let i = 3; i < data.length; i += 4) if (data[i] > 128) n++; return n; };
      const openCount = opaque(pixels()); eye.update(.5, 0); const halfCount = opaque(pixels());
      eye.update(0, 0); const closedCount = opaque(pixels());
      let finite = true, largestShoulderOffset = 0, maxChestLift = 0, shoulderEmbed = Infinity;
      const v = new THREE.Vector3(), local = new THREE.Vector3();
      for (const time of [0, 1.5, 1.75, 1.8, 6, 11, 15]) {
        model.update(pilotPose(time, .65)); model.root.updateMatrixWorld(true);
        for (const limb of [model.left, model.right]) {
          limb.arm.skeleton.update();
          const origin = limb.arm.position;
          shoulderEmbed = Math.min(shoulderEmbed, anatomy.bodySurface(origin.x, origin.y) - origin.z);
          largestShoulderOffset = Math.max(largestShoulderOffset, limb.upper.position.length());
          const positions = limb.arm.geometry.attributes.position;
          for (let i = 0; i < positions.count; i += 3) {
            v.fromBufferAttribute(positions, i); limb.arm.applyBoneTransform(i, v);
            finite &&= [v.x, v.y, v.z].every(Number.isFinite);
          }
        }
      }
      const emblem = model.root.getObjectByName('syna-chest-centella');
      const hasDrawstrings = Boolean(model.root.getObjectByName('syna-hoodie-drawstrings'));
      const sleeveBridges = Boolean(model.root.getObjectByName('left-sleeve-shoulder-bridge')) &&
        Boolean(model.root.getObjectByName('right-sleeve-shoulder-bridge'));
      model.body.updateWorldMatrix(true, true);
      for (const part of emblem.children) {
        const positions = part.geometry.attributes.position;
        for (let i = 0; i < positions.count; i++) {
          local.fromBufferAttribute(positions, i).applyMatrix4(part.matrixWorld);
          model.body.worldToLocal(local);
          maxChestLift = Math.max(maxChestLift, Math.abs(local.z - anatomy.bodySurface(local.x, local.y)));
        }
      }
      const paintedEyes = [];
      model.root.traverse((part) => { if (/painted-eye$/.test(part.name)) paintedEyes.push(part.name); });
      eye.texture.dispose();
      return { cached, openCount, halfCount, closedCount, finite, largestShoulderOffset,
        maxChestLift, shoulderEmbed, hasDrawstrings, sleeveBridges, paintedEyes, armLength: anatomy.ARM_LENGTH, eyeY: anatomy.FACE.eyeY };
    });
    assert.equal(result.cached, true); assert.equal(result.finite, true);
    assert.equal(result.largestShoulderOffset, 0);
    assert.equal(result.hasDrawstrings, true, 'hoodie must have cream drawstrings');
    assert.equal(result.sleeveBridges, true, 'both sleeve roots must overlap the hoodie');
    assert.ok(result.shoulderEmbed > .02, 'shoulder pivot must lie inside hoodie, not float beside it');
    assert.ok(result.openCount > result.halfCount && result.halfCount > result.closedCount);
    assert.ok(result.closedCount < result.openCount * .18);
    assert.ok(result.maxChestLift < .02, JSON.stringify(result));
    assert.equal(result.paintedEyes.length, 2); assert.ok(result.armLength < .62 && result.eyeY < 0);
    for (const [name, angle] of [['front', 0], ['three-quarter', 35], ['profile', 90]]) {
      await page.locator(`[data-view="${angle}"]`).click();
      await page.waitForFunction((a) => Math.abs(Number(document.querySelector('#stage').dataset.view) - a * Math.PI / 180) < .001, angle);
      await page.locator('#stage').screenshot({ path: path.join(output, `${name}.png`) });
    }
    await fs.writeFile(path.join(output, 'shape-results.json'), JSON.stringify(result, null, 2));
    console.log('PASS: curved chest emblem, two painted eyes, cached texture updates, closing eyelid aperture, finite connected skinned arms');
    console.log(JSON.stringify(result));
  } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
