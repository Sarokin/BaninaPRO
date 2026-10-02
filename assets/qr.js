/* =====================================================================
   BaninaPRO – kompakt QR-kód generátor (byte mód, M hibajavítás, 1–10 verzió)
   Kimenet: SVG szöveg. Nincs külső függőség.
   ===================================================================== */
'use strict';
window.BaninaQR = (function () {
  // ---- GF(256) aritmetika a Reed–Solomon hibajavításhoz
  const EXP = new Uint8Array(512), LOG = new Uint8Array(256);
  (function () { let x = 1; for (let i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11D; } for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255]; })();
  const mul = (a, b) => (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];
  function rsGenerator(n) { let g = [1]; for (let i = 0; i < n; i++) { const ng = new Array(g.length + 1).fill(0); for (let j = 0; j < g.length; j++) { ng[j] ^= g[j]; ng[j + 1] ^= mul(g[j], EXP[i]); } g = ng; } return g; }
  function rsRemainder(data, n) { const g = rsGenerator(n); const res = new Array(n).fill(0); for (const d of data) { const f = d ^ res[0]; res.shift(); res.push(0); if (f !== 0) for (let j = 0; j < n; j++) res[j] ^= mul(g[j + 1], f); } return res; }

  // ---- verziótábla (M szint): [összes kódszó, EC kódszó/blokk, blokkok száma]
  const VER = { 1: [26, 10, 1], 2: [44, 16, 1], 3: [70, 26, 1], 4: [100, 18, 2], 5: [134, 24, 2], 6: [172, 16, 4], 7: [196, 18, 4], 8: [242, 22, 4], 9: [292, 22, 5], 10: [346, 26, 5] };
  const ALIGN = { 1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50] };

  function utf8(str) { return Array.from(new TextEncoder().encode(str)); }

  function encode(text) {
    const bytes = utf8(text);
    let ver = 0;
    for (let v = 1; v <= 10; v++) {
      const [tot, ec, nb] = VER[v];
      const dataBits = (tot - ec * nb) * 8;
      const need = 4 + (v < 10 ? 8 : 16) + bytes.length * 8;
      if (need <= dataBits) { ver = v; break; }
    }
    if (!ver) throw new Error('A szöveg túl hosszú a QR-kódhoz.');
    const [tot, ecLen, numBlocks] = VER[ver];
    const dataLen = tot - ecLen * numBlocks;
    // bitfolyam
    const bits = [];
    const push = (val, n) => { for (let i = n - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    push(4, 4); push(bytes.length, ver < 10 ? 8 : 16);
    bytes.forEach((b) => push(b, 8));
    push(0, Math.min(4, dataLen * 8 - bits.length));
    while (bits.length % 8) bits.push(0);
    for (let pad = 0xEC; bits.length < dataLen * 8; pad ^= 0xEC ^ 0x11) push(pad, 8);
    const data = [];
    for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2));
    // blokkok + hibajavítás + átfésülés
    const shortLen = Math.floor(tot / numBlocks);
    const numShort = numBlocks - tot % numBlocks;
    const blocks = [];
    for (let i = 0, k = 0; i < numBlocks; i++) {
      const len = shortLen - ecLen + (i < numShort ? 0 : 1);
      const dat = data.slice(k, k + len); k += len;
      const ecc = rsRemainder(dat, ecLen);
      if (i < numShort) dat.push(-1);
      blocks.push(dat.concat(ecc));
    }
    const out = [];
    for (let i = 0; i < blocks[0].length; i++) for (let j = 0; j < blocks.length; j++) { if (i === shortLen - ecLen && j < numShort) continue; out.push(blocks[j][i]); }
    return { ver, codewords: out };
  }

  function build(text) {
    const { ver, codewords } = encode(text);
    const size = 17 + 4 * ver;
    const mod = Array.from({ length: size }, () => new Uint8Array(size));
    const fn = Array.from({ length: size }, () => new Uint8Array(size));
    const set = (x, y, d) => { mod[y][x] = d ? 1 : 0; fn[y][x] = 1; };
    const finder = (x, y) => { for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) { const xx = x + dx, yy = y + dy; if (xx < 0 || yy < 0 || xx >= size || yy >= size) continue; const dist = Math.max(Math.abs(dx), Math.abs(dy)); set(xx, yy, dist !== 2 && dist !== 4); } };
    const align = (x, y) => { for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(x + dx, y + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1); };
    // időzítés
    for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
    const ap = ALIGN[ver];
    for (let i = 0; i < ap.length; i++) for (let j = 0; j < ap.length; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === ap.length - 1) || (i === ap.length - 1 && j === 0)) continue;
      align(ap[i], ap[j]);
    }
    // formátum-információ helyének foglalása (később kitöltjük)
    const drawFormat = (mask) => {
      const dataBits = (0 << 3) | mask; // M szint = 00
      let rem = dataBits;
      for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      const b = ((dataBits << 10) | rem) ^ 0x5412;
      const bit = (i) => (b >>> i) & 1;
      for (let i = 0; i <= 5; i++) set(8, i, bit(i));
      set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
      for (let i = 9; i < 15; i++) set(14 - i, 8, bit(i));
      for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
      for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
      set(8, size - 8, 1);
    };
    drawFormat(0);
    if (ver >= 7) {
      let rem = ver;
      for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      const b = (ver << 12) | rem;
      for (let i = 0; i < 18; i++) { const bit = (b >>> i) & 1; const a = size - 11 + (i % 3), c = Math.floor(i / 3); set(a, c, bit); set(c, a, bit); }
    }
    // adat elhelyezése cikkcakkban
    let i = 0; const total = codewords.length * 8;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
        const x = right - j; const upward = ((right + 1) & 2) === 0; const y = upward ? size - 1 - vert : vert;
        if (!fn[y][x] && i < total) { mod[y][x] = (codewords[i >>> 3] >>> (7 - (i & 7))) & 1; i++; }
      }
    }
    // maszk kiválasztása
    const MASKS = [
      (x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x, y) => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
      (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => (x * y) % 2 + (x * y) % 3 === 0,
      (x, y) => ((x * y) % 2 + (x * y) % 3) % 2 === 0, (x, y) => ((x + y) % 2 + (x * y) % 3) % 2 === 0,
    ];
    const applyMask = (m) => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fn[y][x] && MASKS[m](x, y)) mod[y][x] ^= 1; };
    const penalty = () => {
      let p = 0;
      for (let y = 0; y < size; y++) { let run = 1; for (let x = 1; x < size; x++) { if (mod[y][x] === mod[y][x - 1]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
      for (let x = 0; x < size; x++) { let run = 1; for (let y = 1; y < size; y++) { if (mod[y][x] === mod[y - 1][x]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
      for (let y = 0; y < size - 1; y++) for (let x = 0; x < size - 1; x++) { const c = mod[y][x]; if (c === mod[y][x + 1] && c === mod[y + 1][x] && c === mod[y + 1][x + 1]) p += 3; }
      let dark = 0; for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) dark += mod[y][x];
      p += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
      return p;
    };
    let best = 0, bestP = Infinity;
    for (let m = 0; m < 8; m++) { applyMask(m); drawFormat(m); const p = penalty(); if (p < bestP) { bestP = p; best = m; } applyMask(m); }
    applyMask(best); drawFormat(best);
    return { size, mod };
  }

  /** SVG QR-kód (4 modulos csendes zónával) */
  function svg(text, opts) {
    opts = opts || {};
    const { size, mod } = build(text);
    const q = 4, dim = size + 2 * q;
    let d = '';
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (mod[y][x]) d += `M${x + q} ${y + q}h1v1h-1z`;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${dim} ${dim}" shape-rendering="crispEdges" role="img" aria-label="QR-kód"><rect width="${dim}" height="${dim}" fill="${opts.bg || '#fff'}"/><path d="${d}" fill="${opts.fg || '#171717'}"/></svg>`;
  }
  return { svg, build };
})();
