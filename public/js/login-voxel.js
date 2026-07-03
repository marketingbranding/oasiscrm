import * as THREE from 'https://esm.sh/three@0.181.1';
import { OrbitControls } from 'https://esm.sh/three@0.181.1/examples/jsm/controls/OrbitControls.js';

const COLORS = {
    sand: 0xe3bf70,
    dune: 0xc9964b,
    water: 0x5d8e8e,
    waterLight: 0x8fd8d0,
    waterDeep: 0x225d6b,
    wood: 0x4a2f1c,
    woodLight: 0x7a4a27,
    palm: 0x2f7d46,
    palmLight: 0x4f9b53,
    coconut: 0x4d3326,
    flowerRed: 0xe91d2a,
    flowerGold: 0xf1c40f,
    sage: 0xb3bd95,
    stone: 0x7f7f7f,
    fireOrange: 0xe6915d,
    fireYellow: 0xfcc20f,
};

function setBlock(map, x, y, z, color) {
    const rx = Math.round(x);
    const ry = Math.round(y);
    const rz = Math.round(z);
    map.set(`${rx},${ry},${rz}`, { x: rx, y: ry, z: rz, color });
}

function sphere(map, cx, cy, cz, r, color, sy = 1) {
    for (let x = Math.floor(cx - r); x <= Math.ceil(cx + r); x++) {
        for (let y = Math.floor(cy - r * sy); y <= Math.ceil(cy + r * sy); y++) {
            for (let z = Math.floor(cz - r); z <= Math.ceil(cz + r); z++) {
                const dx = x - cx;
                const dy = (y - cy) / sy;
                const dz = z - cz;
                if (dx * dx + dy * dy + dz * dz <= r * r) {
                    setBlock(map, x, y, z, color);
                }
            }
        }
    }
}

function addPalm(map, baseX, baseZ, topY, bendX, bendZ, scale = 1) {
    let topX = baseX;
    let topZ = baseZ;
    const bottomY = -1;

    for (let y = bottomY; y <= topY; y++) {
        const t = (y - bottomY) / (topY - bottomY);
        const x = baseX + t * bendX + Math.sin(t * Math.PI) * 1.2 * scale;
        const z = baseZ + t * bendZ;
        const color = y % 2 === 0 ? COLORS.woodLight : COLORS.wood;

        setBlock(map, x, y, z, color);
        if (y < 2 && scale >= 0.9) {
            setBlock(map, x + 1, y, z, color);
            setBlock(map, x, y, z + 1, color);
        }
        if (y === topY) {
            topX = Math.round(x);
            topZ = Math.round(z);
        }
    }

    [[1, -1, 0], [-1, -1, 1], [0, -1, -1]].forEach(([x, y, z]) => {
        setBlock(map, topX + x, topY + y, topZ + z, COLORS.coconut);
    });

    const dirs = [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, 1], [1, -1], [-1, -1]];
    dirs.forEach(([dx, dz], index) => {
        const length = Math.round((index % 2 === 0 ? 8 : 6) * scale);
        for (let i = 1; i <= length; i++) {
            const t = i / length;
            const y = topY + Math.round(Math.sin(t * Math.PI) * 1.7 - t * t * 1.2);
            const x = topX + dx * i;
            const z = topZ + dz * i;
            setBlock(map, x, y, z, index % 2 === 0 ? COLORS.palm : COLORS.palmLight);

            if (i > 1 && i < length - 1) {
                setBlock(map, x - dz, y - 1, z + dx, COLORS.palmLight);
                setBlock(map, x + dz, y - 1, z - dx, COLORS.palm);
            }
        }
    });
}

function generateOasis() {
    const map = new Map();

    for (let x = -12; x <= 12; x++) {
        for (let z = -12; z <= 12; z++) {
            const d = Math.sqrt(x * x + z * z);
            if (d > 12) continue;

            if (d <= 6.4) {
                setBlock(map, x, -5, z, COLORS.sand);
                setBlock(map, x, -4, z, d <= 3.5 ? COLORS.waterDeep : COLORS.water);
                setBlock(map, x, -3, z, d <= 3.5 ? COLORS.waterDeep : COLORS.waterLight);
            } else {
                setBlock(map, x, -4, z, COLORS.sand);
                if (d > 9 && d <= 11) setBlock(map, x, -3, z, COLORS.dune);
            }
        }
    }

    sphere(map, -6, -3, -6, 3.4, COLORS.sand, 0.7);
    sphere(map, -6, -2, -6, 1.9, COLORS.sand, 0.6);
    sphere(map, 7, -3, 5, 2.5, COLORS.sand, 0.7);
    sphere(map, 6, -3, -6, 2.0, COLORS.dune, 0.7);

    addPalm(map, -6, -6, 11, 5.2, 5.1, 1);
    addPalm(map, 7, 5, 6, 2.4, 2.0, 0.7);

    for (let z = -7; z <= -3; z++) {
        setBlock(map, 3, -3, z, COLORS.woodLight);
        setBlock(map, 4, -3, z, COLORS.woodLight);
    }

    [[-1, 2], [3, 1], [-3, -2], [0, -3]].forEach(([x, z], index) => {
        setBlock(map, x, -2, z, COLORS.palm);
        setBlock(map, x + 1, -2, z, COLORS.palm);
        setBlock(map, x, -2, z + 1, COLORS.palmLight);
        setBlock(map, x, -1, z, index % 2 ? COLORS.flowerGold : COLORS.sage);
    });

    [[-8, -2], [-9, 2], [-4, -9], [9, -2], [5, 8], [-1, 10]].forEach(([x, z], index) => {
        setBlock(map, x, -3, z, COLORS.palm);
        setBlock(map, x + 1, -3, z, COLORS.palmLight);
        setBlock(map, x, -2, z, index % 2 ? COLORS.flowerGold : COLORS.flowerRed);
    });

    const fireX = -3;
    const fireZ = 7;
    [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]].forEach(([x, z]) => {
        setBlock(map, fireX + x, -3, fireZ + z, COLORS.stone);
    });
    setBlock(map, fireX, -2, fireZ, COLORS.flowerRed);
    setBlock(map, fireX, -1, fireZ, COLORS.fireOrange);
    setBlock(map, fireX, 0, fireZ, COLORS.fireYellow);

    return Array.from(map.values());
}

class LoginVoxelScene {
    constructor(container) {
        this.container = container;
        this.frame = null;
        this.dummy = new THREE.Object3D();
        this.clock = new THREE.Clock();
        this.state = 'stable';
        this.floorY = -12;
        this.targetVoxels = generateOasis();
        this.voxels = this.targetVoxels.map((voxel, index) => ({
            id: index,
            x: voxel.x,
            y: voxel.y,
            z: voxel.z,
            tx: voxel.x,
            ty: voxel.y,
            tz: voxel.z,
            color: voxel.color,
            vx: 0,
            vy: 0,
            vz: 0,
            rx: 0,
            ry: 0,
            rz: 0,
            rvx: 0,
            rvy: 0,
            rvz: 0,
            delay: 0,
        }));

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(0xf4d28b);
        this.scene.fog = new THREE.Fog(0xf4d28b, 48, 118);

        this.camera = new THREE.PerspectiveCamera(42, 1, 0.1, 1000);
        this.camera.position.set(28, 24, 44);

        this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        this.renderer.domElement.setAttribute('data-login-voxel-canvas', '');
        this.container.appendChild(this.renderer.domElement);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.autoRotate = true;
        this.controls.autoRotateSpeed = 0.55;
        this.controls.enablePan = false;
        this.controls.minDistance = 26;
        this.controls.maxDistance = 72;
        this.controls.target.set(0, 1, 0);

        this.addLights();
        this.addRetroBackdrop();
        this.createMesh();
        this.resize();

        this.resizeFrame = null;
        this.resizeHandler = () => {
            cancelAnimationFrame(this.resizeFrame);
            this.resizeFrame = requestAnimationFrame(() => this.resize());
        };
        if ('ResizeObserver' in window) {
            this.resizeObserver = new ResizeObserver(this.resizeHandler);
            this.resizeObserver.observe(this.container);
        } else {
            window.addEventListener('resize', this.resizeHandler);
        }
        this.animate();
    }

    getState() {
        return this.state;
    }

    setState(state) {
        this.state = state;
        this.container.dispatchEvent(new CustomEvent('voxel-state', { detail: { state } }));
    }

    toggleBreakRebuild() {
        if (this.state === 'stable') {
            this.dismantle();
        } else if (this.state === 'dismantled') {
            this.rebuild();
        }
    }

    dismantle() {
        if (this.state !== 'stable') return;

        this.voxels.forEach((voxel) => {
            const outward = Math.atan2(voxel.z, voxel.x);
            voxel.vx = Math.cos(outward) * (0.14 + Math.random() * 0.26) + (Math.random() - 0.5) * 0.24;
            voxel.vy = 0.25 + Math.random() * 0.38;
            voxel.vz = Math.sin(outward) * (0.14 + Math.random() * 0.26) + (Math.random() - 0.5) * 0.24;
            voxel.rvx = (Math.random() - 0.5) * 0.18;
            voxel.rvy = (Math.random() - 0.5) * 0.18;
            voxel.rvz = (Math.random() - 0.5) * 0.18;
        });

        this.setState('dismantling');
    }

    rebuild() {
        if (this.state !== 'dismantled') return;

        this.voxels.forEach((voxel) => {
            const heightDelay = Math.max(0, (voxel.ty - this.floorY) / 20);
            voxel.delay = heightDelay * 55 + Math.random() * 12;
        });

        this.rebuildStart = performance.now();
        this.setState('rebuilding');
    }

    addLights() {
        this.scene.add(new THREE.AmbientLight(0xfff2d0, 1.25));

        const sun = new THREE.DirectionalLight(0xffffff, 1.75);
        sun.position.set(45, 70, 35);
        sun.castShadow = true;
        sun.shadow.mapSize.width = 1024;
        sun.shadow.mapSize.height = 1024;
        this.scene.add(sun);

        const warm = new THREE.PointLight(0xe6915d, 1.2, 70);
        warm.position.set(-12, 13, 22);
        this.scene.add(warm);
    }

    addRetroBackdrop() {
        const canvas = document.createElement('canvas');
        canvas.width = 16;
        canvas.height = 16;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#f4d28b';
        ctx.fillRect(0, 0, 16, 16);
        ctx.fillStyle = 'rgba(0,0,0,0.08)';
        ctx.fillRect(0, 0, 16, 1);

        const texture = new THREE.CanvasTexture(canvas);
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.repeat.set(32, 32);

        const plane = new THREE.Mesh(
            new THREE.PlaneGeometry(180, 180),
            new THREE.MeshBasicMaterial({ map: texture, transparent: true, opacity: 0.26 })
        );
        plane.position.set(0, 22, -46);
        this.scene.add(plane);
    }

    createMesh() {
        const geometry = new THREE.BoxGeometry(0.92, 0.92, 0.92);
        const material = new THREE.MeshStandardMaterial({ roughness: 0.78, metalness: 0.04 });
        this.mesh = new THREE.InstancedMesh(geometry, material, this.voxels.length);
        this.mesh.castShadow = true;
        this.mesh.receiveShadow = true;
        this.scene.add(this.mesh);

        this.voxels.forEach((voxel, index) => {
            this.dummy.position.set(voxel.x, voxel.y, voxel.z);
            this.dummy.rotation.set(voxel.rx, voxel.ry, voxel.rz);
            this.dummy.updateMatrix();
            this.mesh.setMatrixAt(index, this.dummy.matrix);

            const color = new THREE.Color(voxel.color);
            color.offsetHSL(0, 0, (Math.random() * 0.08) - 0.04);
            this.mesh.setColorAt(index, color);
        });
        this.mesh.instanceMatrix.needsUpdate = true;
        this.mesh.instanceColor.needsUpdate = true;
    }

    draw() {
        if (!this.mesh) return;

        this.voxels.forEach((voxel, index) => {
            this.dummy.position.set(voxel.x, voxel.y, voxel.z);
            this.dummy.rotation.set(voxel.rx, voxel.ry, voxel.rz);
            this.dummy.updateMatrix();
            this.mesh.setMatrixAt(index, this.dummy.matrix);
        });

        this.mesh.instanceMatrix.needsUpdate = true;
    }

    updatePhysics() {
        if (this.state === 'dismantling') {
            let settled = 0;

            this.voxels.forEach((voxel) => {
                voxel.vy -= 0.024;
                voxel.x += voxel.vx;
                voxel.y += voxel.vy;
                voxel.z += voxel.vz;
                voxel.rx += voxel.rvx;
                voxel.ry += voxel.rvy;
                voxel.rz += voxel.rvz;

                if (voxel.y < this.floorY + 0.55) {
                    voxel.y = this.floorY + 0.55;
                    voxel.vy *= -0.34;
                    voxel.vx *= 0.86;
                    voxel.vz *= 0.86;
                    voxel.rvx *= 0.82;
                    voxel.rvy *= 0.82;
                    voxel.rvz *= 0.82;
                }

                if (Math.abs(voxel.vx) + Math.abs(voxel.vy) + Math.abs(voxel.vz) < 0.045 && voxel.y <= this.floorY + 0.65) {
                    settled++;
                }
            });

            if (settled > this.voxels.length * 0.86) {
                this.setState('dismantled');
            }
        }

        if (this.state === 'rebuilding') {
            const elapsed = performance.now() - this.rebuildStart;
            let done = true;

            this.voxels.forEach((voxel) => {
                if (elapsed < voxel.delay) {
                    done = false;
                    return;
                }

                const speed = 0.13;
                voxel.x += (voxel.tx - voxel.x) * speed;
                voxel.y += (voxel.ty - voxel.y) * speed;
                voxel.z += (voxel.tz - voxel.z) * speed;
                voxel.rx += (0 - voxel.rx) * speed;
                voxel.ry += (0 - voxel.ry) * speed;
                voxel.rz += (0 - voxel.rz) * speed;

                const dist = (voxel.tx - voxel.x) ** 2 + (voxel.ty - voxel.y) ** 2 + (voxel.tz - voxel.z) ** 2;
                if (dist > 0.015 || Math.abs(voxel.rx) + Math.abs(voxel.ry) + Math.abs(voxel.rz) > 0.02) {
                    done = false;
                } else {
                    voxel.x = voxel.tx;
                    voxel.y = voxel.ty;
                    voxel.z = voxel.tz;
                    voxel.rx = 0;
                    voxel.ry = 0;
                    voxel.rz = 0;
                }
            });

            if (done) {
                this.setState('stable');
            }
        }
    }

    resize() {
        const rect = this.container.getBoundingClientRect();
        const width = Math.max(1, rect.width);
        const height = Math.max(1, rect.height);
        if (width < 2 || height < 2) return;

        const aspect = width / height;
        const distance = aspect < 1.05 ? 66 : aspect < 1.35 ? 58 : 52;
        const direction = this.camera.position.clone().normalize();

        this.camera.position.copy(direction.multiplyScalar(distance));
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height, true);
    }

    animate() {
        this.frame = requestAnimationFrame(() => this.animate());
        const elapsed = this.clock.getElapsedTime();
        if (this.mesh) {
            this.mesh.position.y = this.state === 'stable' ? Math.sin(elapsed * 1.2) * 0.08 : 0;
        }
        this.updatePhysics();
        this.draw();
        this.controls.update();
        this.renderer.render(this.scene, this.camera);
    }

    destroy() {
        cancelAnimationFrame(this.frame);
        cancelAnimationFrame(this.resizeFrame);
        if (this.resizeObserver) this.resizeObserver.disconnect();
        else window.removeEventListener('resize', this.resizeHandler);
        this.controls.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}

function initLoginVoxelScene() {
    const container = document.querySelector('[data-login-voxel]');
    const button = document.querySelector('[data-login-voxel-toggle]');
    if (!container) return;

    let scene = null;
    const start = () => {
        if (scene || window.matchMedia('(max-width: 767px)').matches) return;
        scene = new LoginVoxelScene(container);
        if (button) {
            button.disabled = false;
            button.textContent = 'BREAK';
        }
    };

    const stop = () => {
        if (!scene) return;
        scene.destroy();
        scene = null;
        if (button) button.disabled = true;
    };

    const handleMedia = () => {
        if (window.matchMedia('(max-width: 767px)').matches) stop();
        else start();
    };

    start();
    window.addEventListener('resize', handleMedia);

    if (button) {
        button.addEventListener('click', () => scene?.toggleBreakRebuild());
        container.addEventListener('voxel-state', (event) => {
            const state = event.detail.state;
            button.disabled = state === 'dismantling' || state === 'rebuilding';
            button.textContent = state === 'dismantled' ? 'REBUILD' : 'BREAK';
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLoginVoxelScene);
} else {
    initLoginVoxelScene();
}
