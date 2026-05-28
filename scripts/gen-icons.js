/**
 * Génère les icônes PNG de la PWA (icon-192.png, icon-512.png) à partir
 * du design SVG (rect arrondi #667eea + lettre "F" blanche).
 *
 * Pur Node — aucune dépendance (zlib intégré). Lance : node scripts/gen-icons.js
 * À relancer si la charte graphique de l'icône change.
 */
const zlib = require('zlib');
const fs = require('fs');
const path = require('path');

const BG = [0x66, 0x7e, 0xea];
const FG = [0xff, 0xff, 0xff];
const OUT_DIR = path.join(__dirname, '..', 'assets', 'icons');

function renderIcon(size) {
    const px = Buffer.alloc(size * size * 4); // RGBA

    const set = (x, y, [r, g, b]) => {
        if (x < 0 || y < 0 || x >= size || y >= size) return;
        const i = (y * size + x) * 4;
        px[i] = r; px[i + 1] = g; px[i + 2] = b; px[i + 3] = 0xff;
    };

    const radius = Math.round(size * 32 / 192);
    const inCorner = (x, y) => {
        // Hors des coins arrondis -> transparent
        const corners = [
            [radius, radius], [size - radius, radius],
            [radius, size - radius], [size - radius, size - radius],
        ];
        const nearLeft = x < radius, nearRight = x >= size - radius;
        const nearTop = y < radius, nearBot = y >= size - radius;
        if ((nearLeft || nearRight) && (nearTop || nearBot)) {
            const [cx, cy] = nearTop
                ? (nearLeft ? corners[0] : corners[1])
                : (nearLeft ? corners[2] : corners[3]);
            const dx = x - (cx - 0.5), dy = y - (cy - 0.5);
            return (dx * dx + dy * dy) <= radius * radius;
        }
        return true;
    };

    // Fond arrondi
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            if (inCorner(x, y)) set(x, y, BG);
        }
    }

    // Lettre "F" en rectangles blancs
    const t = Math.round(size * 0.11);      // épaisseur trait
    const x0 = Math.round(size * 0.34);     // bord gauche du F
    const yTop = Math.round(size * 0.26);
    const yBot = Math.round(size * 0.74);
    const topArm = Math.round(size * 0.34);
    const midArm = Math.round(size * 0.27);
    const yMid = Math.round(size * 0.47);

    const rect = (rx, ry, rw, rh) => {
        for (let y = ry; y < ry + rh; y++)
            for (let x = rx; x < rx + rw; x++) set(x, y, FG);
    };

    rect(x0, yTop, t, yBot - yTop);          // tige verticale
    rect(x0, yTop, topArm, t);               // bras haut
    rect(x0, yMid, midArm, t);               // bras milieu

    return px;
}

function encodePng(size, rgba) {
    const crcTable = (() => {
        const tbl = [];
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
            tbl[n] = c >>> 0;
        }
        return tbl;
    })();
    const crc32 = (buf) => {
        let c = 0xffffffff;
        for (let i = 0; i < buf.length; i++) c = crcTable[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (type, data) => {
        const len = Buffer.alloc(4);
        len.writeUInt32BE(data.length, 0);
        const typeBuf = Buffer.from(type, 'ascii');
        const crcBuf = Buffer.alloc(4);
        crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
        return Buffer.concat([len, typeBuf, data, crcBuf]);
    };

    const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(size, 0);
    ihdr.writeUInt32BE(size, 4);
    ihdr[8] = 8;   // bit depth
    ihdr[9] = 6;   // color type RGBA
    // ihdr[10..12] = 0 (deflate, no filter, no interlace)

    // Filtre 0 par scanline
    const stride = size * 4;
    const raw = Buffer.alloc((stride + 1) * size);
    for (let y = 0; y < size; y++) {
        raw[y * (stride + 1)] = 0;
        rgba.copy(raw, y * (stride + 1) + 1, y * stride, y * stride + stride);
    }
    const idat = zlib.deflateSync(raw, { level: 9 });

    return Buffer.concat([
        sig,
        chunk('IHDR', ihdr),
        chunk('IDAT', idat),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

for (const size of [192, 512]) {
    const png = encodePng(size, renderIcon(size));
    const out = path.join(OUT_DIR, `icon-${size}.png`);
    fs.writeFileSync(out, png);
    console.log(`wrote ${out} (${png.length} bytes)`);
}
