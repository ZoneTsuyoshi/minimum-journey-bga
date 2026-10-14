// カード画像とアイコンのスプライト生成（仕様書 009 第 4・5 節）。
// 使い方: npm run build:sprites [-- --out <dir>] [--manifest <path>] [--quality <n>] [--verify-determinism]
// 入力: data/cards/*.png（72 枚）、data/icons/*.svg（18 点）。data/ は読み取りのみ。
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import {
  CARD_KEYS, TIERS, ICON_FILE, ICON_SCALE, ICON_DENSITY, ICON_SHEET,
  cardPosition, sheetSize, iconCells,
} from './lib/sprite-layout.mjs';
import { checkSprites } from './check-sprites.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CARDS_DIR = path.join(ROOT, 'data/cards');
const ICONS_DIR = path.join(ROOT, 'data/icons');
const DEFAULT_QUALITY = 80;

function parseArgs(argv) {
  const opts = {
    out: path.join(ROOT, 'src/img'),
    manifest: path.join(ROOT, 'build/sprites/manifest.json'),
    quality: DEFAULT_QUALITY,
    verifyDeterminism: false,
  };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--out') opts.out = path.resolve(argv[++i]);
    else if (a === '--manifest') opts.manifest = path.resolve(argv[++i]);
    else if (a === '--quality') opts.quality = Number(argv[++i]);
    else if (a === '--verify-determinism') opts.verifyDeterminism = true;
    else throw new Error(`不明なオプション: ${a}`);
  }
  if (!Number.isInteger(opts.quality) || opts.quality < 1 || opts.quality > 100) {
    throw new Error('--quality は 1〜100 の整数で指定してください');
  }
  return opts;
}

// ---- 入力の検査（第 5.2 節 1） ----
async function readCardInputs() {
  const files = (await readdir(CARDS_DIR)).filter((f) => f.endsWith('.png'));
  const keys = files.map((f) => f.slice(0, -4)).sort();
  const expected = [...CARD_KEYS].sort();
  const missing = expected.filter((k) => !keys.includes(k));
  const extra = keys.filter((k) => !expected.includes(k));
  if (missing.length || extra.length) {
    throw new Error(`data/cards/ が 72 個のスプライトキーと一致しません。欠け: [${missing}] 余り: [${extra}]`);
  }
  const inputs = new Map();
  for (const key of CARD_KEYS) {
    const file = path.join(CARDS_DIR, `${key}.png`);
    const meta = await sharp(file).metadata();
    if (meta.channels !== 4 || !meta.hasAlpha) throw new Error(`${key}.png が RGBA ではありません`);
    if (meta.width < 715 || meta.width > 716 || meta.height < 998 || meta.height > 999) {
      throw new Error(`${key}.png の寸法が範囲外です: ${meta.width}x${meta.height}（想定 715〜716 x 998〜999）`);
    }
    inputs.set(key, file);
  }
  return inputs;
}

async function readIconInputs() {
  const cells = iconCells();
  const inputs = new Map();
  for (const [name, info] of Object.entries(cells)) {
    const file = path.join(ICONS_DIR, `${info.src}.svg`);
    let svg;
    try {
      svg = await readFile(file, 'utf8');
    } catch {
      throw new Error(`アイコンの原本がありません: data/icons/${info.src}.svg（${name}）`);
    }
    if (!/viewBox\s*=/.test(svg)) throw new Error(`${info.src}.svg に viewBox がありません`);
    for (const bad of [/<script/i, /<image/i, /<text/i, /(?:href|src)\s*=\s*["'](?!#)/i, /url\(\s*["']?(?!#)/i]) {
      if (bad.test(svg)) throw new Error(`${info.src}.svg に使用できない要素・外部参照があります: ${bad}`);
    }
    inputs.set(name, file);
  }
  return inputs;
}

// ---- 生成 ----
async function buildCardSheets(inputs, outDir, quality, manifestTiers) {
  for (const [tierName, tier] of Object.entries(TIERS)) {
    const { w: cw, h: ch } = tier.cell;
    // 1 枚ずつ縮小して raw バッファにする（キー順）
    const cellBuf = new Map();
    for (const key of CARD_KEYS) {
      const { data, info } = await sharp(inputs.get(key))
        .resize(cw, ch, { fit: 'fill', kernel: 'lanczos3' })
        .raw()
        .toBuffer({ resolveWithObject: true });
      cellBuf.set(key, { data, info });
    }
    manifestTiers[tierName] = { cell: { w: cw, h: ch }, sheets: {} };
    for (const [sheetName, sheet] of Object.entries(tier.sheets)) {
      const size = sheetSize(tierName, sheetName);
      const composites = [];
      for (const key of CARD_KEYS) {
        const pos = cardPosition(key, tierName);
        if (pos.sheet !== sheetName) continue;
        const { data, info } = cellBuf.get(key);
        composites.push({
          input: data,
          raw: { width: info.width, height: info.height, channels: 4 },
          left: pos.col * cw,
          top: pos.row * ch,
        });
      }
      const dest = path.join(outDir, sheet.file);
      await mkdir(path.dirname(dest), { recursive: true });
      await sharp({
        create: { width: size.w, height: size.h, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
      })
        .composite(composites)
        .webp({ quality, effort: 6, alphaQuality: 100, smartSubsample: true })
        .toFile(dest);
      manifestTiers[tierName].sheets[sheetName] = {
        file: sheet.file, w: size.w, h: size.h, cols: sheet.cols, rows: sheet.rows,
      };
    }
  }
}

async function buildIcons(inputs, outDir) {
  const cells = iconCells();
  const composites = [];
  const items = {};
  for (const [name, cell] of Object.entries(cells)) {
    const { data, info } = await sharp(inputs.get(name), { density: ICON_DENSITY })
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });
    if (info.width > cell.w || info.height > cell.h) {
      throw new Error(`アイコン ${name} の出力 ${info.width}x${info.height} がセル ${cell.w}x${cell.h} に収まりません`);
    }
    const left = cell.x + Math.floor((cell.w - info.width) / 2);
    const top = cell.y + Math.floor((cell.h - info.height) / 2);
    composites.push({ input: data, raw: { width: info.width, height: info.height, channels: 4 }, left, top });
    items[name] = {
      cell: { x: cell.x, y: cell.y, w: cell.w, h: cell.h },
      image: { x: left, y: top, w: info.width, h: info.height },
    };
  }
  await mkdir(outDir, { recursive: true });
  await sharp({
    create: { width: ICON_SHEET.w, height: ICON_SHEET.h, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
  })
    .composite(composites)
    .webp({ lossless: true, effort: 6 })
    .toFile(path.join(outDir, ICON_FILE));
  return { file: ICON_FILE, w: ICON_SHEET.w, h: ICON_SHEET.h, scale: ICON_SCALE, items };
}

export async function buildAll({ out, manifest, quality }) {
  const cardInputs = await readCardInputs();
  const iconInputs = await readIconInputs();
  const tiers = {};
  await buildCardSheets(cardInputs, out, quality, tiers);
  const icons = await buildIcons(iconInputs, out);
  const cards = {};
  for (const key of CARD_KEYS) {
    cards[key] = { base: cardPosition(key, 'base'), zoom: cardPosition(key, 'zoom') };
  }
  const doc = { version: 1, source: { count: CARD_KEYS.length }, options: { quality }, tiers, cards, icons };
  await mkdir(path.dirname(manifest), { recursive: true });
  await writeFile(manifest, `${JSON.stringify(doc, null, 2)}\n`);
  return doc;
}

async function hashTree(dir) {
  const result = new Map();
  async function walk(d) {
    for (const e of (await readdir(d, { withFileTypes: true })).sort((a, b) => (a.name < b.name ? -1 : 1))) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) await walk(p);
      else result.set(path.relative(dir, p), createHash('sha256').update(await readFile(p)).digest('hex'));
    }
  }
  await walk(dir);
  return result;
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const t0 = Date.now();
  await buildAll(opts);
  console.log(`build-sprites: 生成完了（${((Date.now() - t0) / 1000).toFixed(1)} 秒）→ ${path.relative(ROOT, opts.out)}`);
  const ok = await checkSprites({ out: opts.out, manifest: opts.manifest });
  if (!ok) process.exit(1);

  if (opts.verifyDeterminism) {
    const tmp = await mkdtemp(path.join(tmpdir(), 'mj-sprites-'));
    try {
      await buildAll({ out: tmp, manifest: path.join(tmp, 'manifest.json'), quality: opts.quality });
      const a = await hashTree(opts.out);
      const b = new Map([...(await hashTree(tmp))].filter(([k]) => k !== 'manifest.json'));
      const bad = [...b].filter(([k, h]) => a.get(k) !== h).map(([k]) => k);
      if (bad.length) {
        console.error(`build-sprites: 決定性の検査に失敗（2 回の出力が一致しません）: ${bad.join(', ')}`);
        process.exit(1);
      }
      console.log(`build-sprites: 決定性 OK（${b.size} ファイルが 2 回の生成で一致）`);
    } finally {
      await rm(tmp, { recursive: true, force: true });
    }
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => {
    console.error(`build-sprites: ${e.message}`);
    process.exit(1);
  });
}
