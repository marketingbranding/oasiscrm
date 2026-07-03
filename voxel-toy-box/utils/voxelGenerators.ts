/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
*/

import { VoxelData } from '../types';
import { COLORS, CONFIG } from './voxelConstants';

// Helper to prevent overlapping voxels
function setBlock(map: Map<string, VoxelData>, x: number, y: number, z: number, color: number) {
    const rx = Math.round(x);
    const ry = Math.round(y);
    const rz = Math.round(z);
    const key = `${rx},${ry},${rz}`;
    map.set(key, { x: rx, y: ry, z: rz, color });
}

function generateSphere(map: Map<string, VoxelData>, cx: number, cy: number, cz: number, r: number, col: number, sy = 1) {
    const r2 = r * r;
    const xMin = Math.floor(cx - r);
    const xMax = Math.ceil(cx + r);
    const yMin = Math.floor(cy - r * sy);
    const yMax = Math.ceil(cy + r * sy);
    const zMin = Math.floor(cz - r);
    const zMax = Math.ceil(cz + r);

    for (let x = xMin; x <= xMax; x++) {
        for (let y = yMin; y <= yMax; y++) {
            for (let z = zMin; z <= zMax; z++) {
                const dx = x - cx;
                const dy = (y - cy) / sy;
                const dz = z - cz;
                if (dx * dx + dy * dy + dz * dz <= r2) {
                    setBlock(map, x, y, z, col);
                }
            }
        }
    }
}

export const Generators = {
    Oasis: (): VoxelData[] => {
        const map = new Map<string, VoxelData>();
        const sandColor = COLORS.SAND;
        const waterColor = COLORS.WATER;
        const shallowWaterColor = COLORS.SHALLOW_WATER;
        const deepWaterColor = COLORS.DEEP_WATER;
        const woodColor = COLORS.WOOD;
        const palmGreenColor = COLORS.PALM_GREEN;
        const greenColor = COLORS.GREEN;
        const coconutColor = COLORS.COCONUT;
        const flowerRed = COLORS.FLOWER_RED;
        const flowerPink = COLORS.FLOWER_PINK;
        const flowerYellow = COLORS.FLOWER_YELLOW;
        const stoneGrey = COLORS.STONE_GREY;
        const reedGreen = COLORS.REED_GREEN;
        const fireOrange = COLORS.FIRE_ORANGE;
        const fireYellow = COLORS.FIRE_YELLOW;
        const fireRed = COLORS.FIRE_RED;

        // 1. Generate Terrain Sandbox Base at y = -5, y = -4 and y = -3
        // Base structure: circular disk of radius 12.
        for (let x = -12; x <= 12; x++) {
            for (let z = -12; z <= 12; z++) {
                const distToCenter = Math.sqrt(x * x + z * z);
                if (distToCenter <= 12) {
                    // Water pool in the middle
                    if (distToCenter <= 6.5) {
                        // Base bed under water is sand at y = -5
                        setBlock(map, x, -5, z, sandColor);
                        
                        // Water layers
                        if (distToCenter <= 3.5) {
                            // Deep pool center
                            setBlock(map, x, -4, z, deepWaterColor);
                            setBlock(map, x, -3, z, deepWaterColor);
                        } else {
                            // Shallow/regular pool
                            setBlock(map, x, -4, z, waterColor);
                            setBlock(map, x, -3, z, shallowWaterColor);
                        }
                    } else {
                        // Sandy beach layers
                        setBlock(map, x, -4, z, sandColor);
                        // Make sand dunes have wavy elevation
                        if (distToCenter > 9 && distToCenter <= 11) {
                            setBlock(map, x, -3, z, sandColor);
                        }
                    }
                }
            }
        }

        // 2. Sand Dunes (to support trees and give organic terrain height)
        // Dune 1 (Big dune at the bottom-left corner for the main tree)
        const dune1X = -6;
        const dune1Z = -6;
        generateSphere(map, dune1X, -3, dune1Z, 3.5, sandColor, 0.7);
        generateSphere(map, dune1X, -2, dune1Z, 2.0, sandColor, 0.6);
        generateSphere(map, dune1X, -1, dune1Z, 1.2, sandColor, 0.5);

        // Dune 2 (Medium dune at top-right corner for the smaller tree)
        const dune2X = 7;
        const dune2Z = 5;
        generateSphere(map, dune2X, -3, dune2Z, 2.5, sandColor, 0.7);
        generateSphere(map, dune2X, -2, dune2Z, 1.5, sandColor, 0.6);

        // Dune 3 (Small decorative dune at bottom-right corner)
        generateSphere(map, 6, -3, -6, 2.0, sandColor, 0.7);

        // 3. TREE 1: Mighty Curved Palm Tree
        // Start from dune 1 at Y = -1, goes up to Y = 11.
        const trunk1BottomY = -1;
        const trunk1TopY = 11;
        let topTx1 = dune1X;
        let topTz1 = dune1Z;

        for (let y = trunk1BottomY; y <= trunk1TopY; y++) {
            const t = (y - trunk1BottomY) / (trunk1TopY - trunk1BottomY);
            // Elegantly curve towards the center of the oasis (0,0)
            const tx = dune1X + t * 5.0 + Math.sin(t * Math.PI) * 1.5;
            const tz = dune1Z + t * 5.0;

            // Textured wood rings (alternating COLORS.WOOD and COLORS.LIGHT/darker shades for a real bark feel)
            const segmentColor = y % 2 === 0 ? COLORS.LIGHT : woodColor;
            
            // Build tapered trunk
            if (y < 2) {
                // Wide base
                setBlock(map, tx, y, tz, segmentColor);
                setBlock(map, tx + 1, y, tz, segmentColor);
                setBlock(map, tx - 1, y, tz, segmentColor);
                setBlock(map, tx, y, tz + 1, segmentColor);
                setBlock(map, tx, y, tz - 1, segmentColor);
            } else if (y < 7) {
                setBlock(map, tx, y, tz, segmentColor);
                // Diagonal collar bits for organic look
                if (y % 2 === 0) {
                    setBlock(map, tx + 1, y, tz, segmentColor);
                    setBlock(map, tx, y, tz - 1, segmentColor);
                } else {
                    setBlock(map, tx - 1, y, tz, segmentColor);
                    setBlock(map, tx, y, tz + 1, segmentColor);
                }
            } else {
                // Single block top trunk
                setBlock(map, tx, y, tz, segmentColor);
            }

            if (y === trunk1TopY) {
                topTx1 = tx;
                topTz1 = tz;
            }
        }

        const rTopTx1 = Math.round(topTx1);
        const rTopTy1 = trunk1TopY;
        const rTopTz1 = Math.round(topTz1);

        // 4. TREE 2: Small Angled Palm Tree
        // Starts from dune 2 at Y = -2, goes up to Y = 6, bending outwards
        const trunk2BottomY = -2;
        const trunk2TopY = 6;
        let topTx2 = dune2X;
        let topTz2 = dune2Z;

        for (let y = trunk2BottomY; y <= trunk2TopY; y++) {
            const t = (y - trunk2BottomY) / (trunk2TopY - trunk2BottomY);
            // Bend outwards for natural spacing
            const tx = dune2X + t * 2.5;
            const tz = dune2Z + t * 2.0;

            const segmentColor = y % 2 === 0 ? COLORS.LIGHT : woodColor;
            setBlock(map, tx, y, tz, segmentColor);
            if (y < 1) {
                setBlock(map, tx + 1, y, tz, segmentColor);
                setBlock(map, tx, y, tz - 1, segmentColor);
            }

            if (y === trunk2TopY) {
                topTx2 = tx;
                topTz2 = tz;
            }
        }

        const rTopTx2 = Math.round(topTx2);
        const rTopTy2 = trunk2TopY;
        const rTopTz2 = Math.round(topTz2);

        // 5. Coconuts for both trees
        // Under Tree 1 crown
        const coco1Offsets = [
            [1, -1, 0],
            [-1, -1, 1],
            [0, -1, -1],
            [1, -2, 1]
        ];
        coco1Offsets.forEach(([ox, oy, oz]) => {
            setBlock(map, rTopTx1 + ox, rTopTy1 + oy, rTopTz1 + oz, coconutColor);
        });

        // Under Tree 2 crown
        const coco2Offsets = [
            [-1, -1, 0],
            [0, -1, 1],
            [1, -1, -1]
        ];
        coco2Offsets.forEach(([ox, oy, oz]) => {
            setBlock(map, rTopTx2 + ox, rTopTy2 + oy, rTopTz2 + oz, coconutColor);
        });

        // 6. Detailed Radiating Palm Leaves (Master and Small Trees)
        const compassDirections = [
            { dx: 1, dz: 0 },    // E
            { dx: -1, dz: 0 },   // W
            { dx: 0, dz: 1 },    // S
            { dx: 0, dz: -1 },   // N
            { dx: 1, dz: 1 },    // SE
            { dx: -1, dz: 1 },   // SW
            { dx: 1, dz: -1 },   // NE
            { dx: -1, dz: -1 },  // NW
        ];

        // Tree 1 Leaf Generation (Long, lush fronds)
        compassDirections.forEach((dir, dirIdx) => {
            const length = dirIdx % 2 === 0 ? 9 : 7; // Alternate for organic flair
            for (let i = 1; i <= length; i++) {
                const t = i / length;
                const dy = Math.sin(t * Math.PI) * 2.2 - (t * t * 1.5);
                const lx = rTopTx1 + dir.dx * i;
                const ly = rTopTy1 + Math.round(dy);
                const lz = rTopTz1 + dir.dz * i;

                // Center vein of leaf
                setBlock(map, lx, ly, lz, palmGreenColor);

                // Leaflets on the side
                if (i >= 2 && i < length) {
                    const px = -dir.dz;
                    const pz = dir.dx;
                    setBlock(map, lx + px, ly - 1, lz + pz, greenColor);
                    setBlock(map, lx - px, ly - 1, lz - pz, greenColor);

                    // Extra layer of fullness in the middle of the frond
                    if (i === 3 || i === 4 || i === 5) {
                        setBlock(map, lx + px * 2, ly - 2, lz + pz * 2, palmGreenColor);
                        setBlock(map, lx - px * 2, ly - 2, lz - pz * 2, palmGreenColor);
                    }
                }
            }
        });

        // Tree 2 Leaf Generation (Slightly smaller, angled fronds)
        compassDirections.forEach((dir, dirIdx) => {
            const length = dirIdx % 2 === 0 ? 6 : 5;
            for (let i = 1; i <= length; i++) {
                const t = i / length;
                const dy = Math.sin(t * Math.PI) * 1.5 - (t * t * 1.0);
                const lx = rTopTx2 + dir.dx * i;
                const ly = rTopTy2 + Math.round(dy);
                const lz = rTopTz2 + dir.dz * i;

                setBlock(map, lx, ly, lz, greenColor);

                if (i >= 2 && i < length) {
                    const px = -dir.dz;
                    const pz = dir.dx;
                    setBlock(map, lx + px, ly - 1, lz + pz, palmGreenColor);
                    setBlock(map, lx - px, ly - 1, lz - pz, palmGreenColor);
                }
            }
        });

        // 7. A Cozy Lakeside Campfire
        // Campfire at X=-3, Z=7. Height sitting on top of the sand (Y=-3)
        const fireX = -3;
        const fireZ = 7;
        const fireBaseY = -3; // sand surface

        // Stone ring around the fire
        const stoneOffsets = [
            [1, 0, 0], [1, 0, 1], [0, 0, 1], [-1, 0, 1],
            [-1, 0, 0], [-1, 0, -1], [0, 0, -1], [1, 0, -1]
        ];
        stoneOffsets.forEach(([ox, oy, oz]) => {
            setBlock(map, fireX + ox, fireBaseY, fireZ + oz, stoneGrey);
        });

        // Campfire logs crossed in the center
        setBlock(map, fireX, fireBaseY, fireZ, woodColor);
        setBlock(map, fireX + 1, fireBaseY, fireZ - 1, woodColor);
        setBlock(map, fireX - 1, fireBaseY, fireZ + 1, woodColor);

        // Glowing fire layers rising up
        setBlock(map, fireX, fireBaseY + 1, fireZ, fireRed);
        setBlock(map, fireX, fireBaseY + 2, fireZ, fireOrange);
        setBlock(map, fireX, fireBaseY + 3, fireZ, fireYellow);
        // Small surrounding flame sparks
        setBlock(map, fireX + 1, fireBaseY + 1, fireZ, fireOrange);
        setBlock(map, fireX - 1, fireBaseY + 1, fireZ, fireOrange);
        setBlock(map, fireX, fireBaseY + 1, fireZ + 1, fireRed);
        setBlock(map, fireX, fireBaseY + 1, fireZ - 1, fireRed);

        // 8. Shoreline Boardwalk / Pier
        // Wooden deck boards extending over the water
        // Starts at X=3, Z=-7 (sand shore) and goes to Z=-3 (water)
        const pierX = 3;
        const pierBaseY = -3;
        for (let z = -7; z <= -3; z++) {
            // Wood planks
            setBlock(map, pierX, pierBaseY, z, COLORS.LIGHT);
            setBlock(map, pierX + 1, pierBaseY, z, COLORS.LIGHT);
        }
        // Pier post pillars
        setBlock(map, pierX - 1, pierBaseY, -7, woodColor);
        setBlock(map, pierX + 2, pierBaseY, -7, woodColor);
        setBlock(map, pierX - 1, pierBaseY + 1, -7, COLORS.ROPE_BEIGE);
        setBlock(map, pierX + 2, pierBaseY + 1, -7, COLORS.ROPE_BEIGE);

        setBlock(map, pierX - 1, pierBaseY, -4, woodColor);
        setBlock(map, pierX + 2, pierBaseY, -4, woodColor);
        setBlock(map, pierX - 1, pierBaseY + 1, -4, COLORS.ROPE_BEIGE);
        setBlock(map, pierX + 2, pierBaseY + 1, -4, COLORS.ROPE_BEIGE);

        // 9. Floating Water Lily Pads & Pink Lotus Flowers
        // Placed at water level Y = -2
        const lilyPads = [
            { x: -1, z: 2, fx: -1, fz: 2 },
            { x: 3, z: 1, fx: 3, fz: 1 },
            { x: -3, z: -2, fx: -3, fz: -2 },
            { x: 0, z: -3, fx: 0, fz: -3 }
        ];

        lilyPads.forEach(pad => {
            // green flat pad
            setBlock(map, pad.x, -2, pad.z, greenColor);
            setBlock(map, pad.x + 1, -2, pad.z, greenColor);
            setBlock(map, pad.x, -2, pad.z + 1, greenColor);
            // tiny flower top
            setBlock(map, pad.fx, -1, pad.fz, flowerPink);
        });

        // 10. Reeds (Tall shoreline plants)
        // Tall green stalks with brown cattail tops near the water border
        const reedPositions = [
            { x: -5, z: 1 }, { x: -5, z: 2 },
            { x: 4, z: -2 }, { x: 5, z: -1 },
            { x: -2, z: -5 }, { x: -1, z: -5 }
        ];

        reedPositions.forEach(pos => {
            // 2 block stalk
            setBlock(map, pos.x, -3, pos.z, reedGreen);
            setBlock(map, pos.x, -2, pos.z, reedGreen);
            // Cattail tip
            setBlock(map, pos.x, -1, pos.z, coconutColor);
        });

        // 11. Tropical Flowers & Lush Bushes
        // Placed on sand around the shoreline
        const shorePlants = [
            { x: -8, z: -2, col: greenColor, fl: flowerRed },
            { x: -9, z: 2, col: greenColor, fl: flowerPink },
            { x: -4, z: -9, col: greenColor, fl: flowerYellow },
            { x: 9, z: -2, col: greenColor, fl: flowerRed },
            { x: 5, z: 8, col: greenColor, fl: flowerYellow },
            { x: -1, z: 10, col: greenColor, fl: flowerPink }
        ];

        shorePlants.forEach(plant => {
            setBlock(map, plant.x, -3, plant.z, plant.col);
            setBlock(map, plant.x + 1, -3, plant.z, plant.col);
            setBlock(map, plant.x, -3, plant.z + 1, plant.col);
            
            // Flower bloom on top
            setBlock(map, plant.x, -2, plant.z, plant.fl);
        });

        return Array.from(map.values());
    }
};
