import * as THREE from './vendor/three.module.min.js';
import { Syna3D } from './model.js?v=6';

export class PilotScene {
  constructor() {
    this.renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'low-power' });
    this.renderer.setSize(1280, 720); this.renderer.setPixelRatio(1);
    this.renderer.setClearColor(0, 0); this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping; this.renderer.toneMappingExposure = 1.05;
    this.renderer.shadowMap.enabled = true; this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(33, 1280/720, .1, 35);
    this.camera.position.set(0,1.73,7.2); this.camera.lookAt(0,1.6,0); this.camera.updateMatrixWorld();
    this.scene.add(new THREE.HemisphereLight('#fff8f3','#d5bdb4',2.0));
    this.scene.add(new THREE.AmbientLight('#fff0e7', .45));
    const key = new THREE.DirectionalLight('#fff6ed', 2.1); key.position.set(-3,7,4); key.castShadow = true;
    key.shadow.mapSize.set(1024,1024); key.shadow.camera.left = -3; key.shadow.camera.right = 3;
    key.shadow.camera.top = 4; key.shadow.camera.bottom = -2; key.shadow.normalBias = .035;
    this.scene.add(key);
    const rim = new THREE.DirectionalLight('#fff3e9',1.5); rim.position.set(4,3,-3); this.scene.add(rim);
    const fill = new THREE.DirectionalLight('#ffffff',1.4); fill.position.set(3,2,5); this.scene.add(fill);
    const ground = new THREE.Mesh(new THREE.PlaneGeometry(30,30), new THREE.ShadowMaterial({ opacity:.055 }));
    ground.rotation.x = -Math.PI/2; ground.position.y = -.015; ground.receiveShadow = true; this.scene.add(ground);
    this.syna = new Syna3D(); this.scene.add(this.syna.root);
  }

  async warmup(poses) {
    // Prepare face, blink and skinning shader variants before speech starts.
    for (const pose of poses) {
      this.syna.update(pose);
      await this.renderer.compileAsync(this.scene, this.camera);
      this.draw(pose);
      await new Promise(requestAnimationFrame);
    }
    if (this.renderer.getContext().isContextLost()) throw new Error('Mất kết nối đồ họa khi chuẩn bị Syna.');
  }

  draw(pose, angle = 0) {
    this.syna.update(pose, angle); this.scene.updateMatrixWorld(true);
    this.renderer.render(this.scene,this.camera);
    return { triangles: this.renderer.info.render.triangles, calls: this.renderer.info.render.calls,
      geometries: this.renderer.info.memory.geometries, textures: this.renderer.info.memory.textures };
  }

  dispose() {
    const geometry = new Set(), materials = new Set(), textures = new Set();
    this.scene.traverse((item) => {
      if (item.geometry) geometry.add(item.geometry);
      for (const mat of [item.material].flat().filter(Boolean)) { materials.add(mat); if (mat.map) textures.add(mat.map); }
      item.skeleton?.dispose();
    });
    geometry.forEach((item)=>item.dispose()); materials.forEach((item)=>item.dispose()); textures.forEach((item)=>item.dispose());
    this.renderer.dispose();
  }
}
