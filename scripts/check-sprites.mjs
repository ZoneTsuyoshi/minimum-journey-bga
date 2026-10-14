// スプライト生成物の検査（仕様書 009 第 5.4 節）。生成はしない。
// 使い方: npm run check:sprites [-- --out <dir>] [--manifest <path>]
import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import {
  CARD_KEYS, TIERS, ICON_FILE, ICON_SHEET, cardPosition, sheetSize, iconCells,
} from './lib/sprite-layout.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const MAX_FILE_BYTES = 2_000_000; // 1 ファイル 2 MB（A1。004 §2.9）
const MAX_TOTAL_BYTES = 15_000_000; // 総量 15 MB
const WARN_TOTAL_BYTES = 6_000_000; // 目標 6 MB
const MAX_DIM = 4096;
const WARN_FILE_COUNT = 10; // img/README「fewer than 10 files」。Typically なので警告（009 §4.6）
const NAME_RE = /^[A-Za-z0-9_]+\.webp$/;
const IMAGE_EXT_RE = /\.(webp|png|jpe?g|gif|svg)$/i;

async function listFiles(dir, base = dir) {
  const out = [];
  let entries;
  try {
    entries = await readdir(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...(await listFiles(p, base)));
    else out.push(path.relative(base, p).split(path.sep).join('/'));
  }
  return out.sort();
}

export async function checkSprites({ out, manifest }) {
  const errors = [];
  const warnings = [];
  const rows = [];

  // 期待するファイル（第 4.1 節の 5 枚と icons.webp）
  const expected = [];
  for (const t of Object.values(TIERS)) for (const s of Object.values(t.sheets)) expected.push(s.file);
  expected.push(ICON_FILE);

  let m = null;
  try {
    m = JSON.parse(await readFile(manifest, 'utf8'));
  } catch (e) {
    errors.push(`マニフェストを読めません: ${path.relative(ROOT, manifest)}（${e.message}）`);
  }

  // ファイルの存在・余計な画像・ファイル名
  const present = await listFiles(out);
  for (const f of expected) if (!present.includes(f)) errors.push(`ファイルがありません: ${f}`);
  const generatedLike = (f) => /^cards_[^/]*\.webp$/.test(f) || f === ICON_FILE || /(^|\/)cards_/.test(f);
  for (const f of present) {
    if (expected.includes(f)) continue;
    if (!IMAGE_EXT_RE.test(f)) continue;
    if (generatedLike(f)) errors.push(`余計な生成物があります: ${f}`);
    else warnings.push(`想定外の画像があります（手書き画像なら問題なし）: ${f}`);
  }
  for (const f of present) {
    if (!IMAGE_EXT_RE.test(f)) continue;
    const ok = !f.includes('/') && NAME_RE.test(f);
    // 手書き画像は他形式（png 等）もありうるので、生成物の命名規則だけをエラーにする
    if (!ok && generatedLike(f)) errors.push(`ファイル名が規則に合いません: ${f}`);
  }

  // 容量・寸法
  let total = 0;
  for (const f of present) {
    if (!IMAGE_EXT_RE.test(f)) continue;
    const size = (await stat(path.join(out, f))).size;
    total += size;
    let w = null;
    let h = null;
    try {
      const meta = await sharp(path.join(out, f)).metadata();
      w = meta.width;
      h = meta.height;
    } catch (e) {
      errors.push(`画像を読めません: ${f}（${e.message}）`);
    }
    if (size > MAX_FILE_BYTES) errors.push(`${f}: ${size} バイトが 1 ファイルの上限 ${MAX_FILE_BYTES} を超えています`);
    if (w > MAX_DIM || h > MAX_DIM) errors.push(`${f}: ${w}x${h} が ${MAX_DIM}px を超えています`);
    rows.push({ f, w, h, size });
  }
  if (total > MAX_TOTAL_BYTES) errors.push(`総量 ${total} バイトが上限 ${MAX_TOTAL_BYTES} を超えています`);
  else if (total > WARN_TOTAL_BYTES) warnings.push(`総量 ${total} バイトが目標 ${WARN_TOTAL_BYTES} を超えています`);
  // img/README: サブディレクトリ禁止（エラー）、ファイル数はおおむね 10 個未満（警告）
  for (const f of present) if (f.includes('/')) errors.push(`img/ にサブディレクトリは使えません（BGA の制約）: ${f}`);
  if (present.length >= WARN_FILE_COUNT) warnings.push(`img/ に ${present.length} 個のファイルがあります（BGA の目安は 10 個未満）`);

  // マニフェストの整合
  if (m) {
    const dims = new Map(rows.map((r) => [r.f, r]));
    if (m.version !== 1) errors.push(`manifest.version が 1 ではありません: ${m.version}`);
    if (m.source?.count !== 72) errors.push(`manifest.source.count が 72 ではありません: ${m.source?.count}`);
    for (const [tierName, tier] of Object.entries(TIERS)) {
      const mt = m.tiers?.[tierName];
      if (!mt) { errors.push(`manifest.tiers.${tierName} がありません`); continue; }
      if (mt.cell?.w !== tier.cell.w || mt.cell?.h !== tier.cell.h) errors.push(`tiers.${tierName}.cell が規則と違います`);
      for (const [sheetName, sheet] of Object.entries(tier.sheets)) {
        const ms = mt.sheets?.[sheetName];
        if (!ms) { errors.push(`tiers.${tierName}.sheets.${sheetName} がありません`); continue; }
        const size = sheetSize(tierName, sheetName);
        if (ms.file !== sheet.file || ms.cols !== sheet.cols || ms.rows !== sheet.rows || ms.w !== size.w || ms.h !== size.h) {
          errors.push(`tiers.${tierName}.sheets.${sheetName} が規則と違います`);
        }
        const d = dims.get(sheet.file);
        if (d && (d.w !== ms.w || d.h !== ms.h)) {
          errors.push(`${sheet.file}: 実寸 ${d.w}x${d.h} がマニフェスト ${ms.w}x${ms.h} と一致しません`);
        }
      }
    }
    const mkeys = Object.keys(m.cards ?? {});
    // 注: JSON.parse は整数形のキー（'56' など）を先頭に並べ替えるので、順序ではなく集合で比べる
    const keySet = new Set(mkeys);
    if (mkeys.length !== 72 || CARD_KEYS.some((k) => !keySet.has(k))) {
      errors.push('manifest.cards のキー（72 個）が規則と一致しません');
    }
    const seen = new Set();
    for (const key of CARD_KEYS) {
      for (const tierName of Object.keys(TIERS)) {
        const exp = cardPosition(key, tierName);
        const got = m.cards?.[key]?.[tierName];
        if (!got || got.sheet !== exp.sheet || got.col !== exp.col || got.row !== exp.row) {
          errors.push(`manifest.cards.${key}.${tierName} が並び順の規則（sprite-layout）と一致しません`);
          continue;
        }
        const sheet = TIERS[tierName].sheets[got.sheet];
        if (!sheet || got.col < 0 || got.col >= sheet.cols || got.row < 0 || got.row >= sheet.rows) {
          errors.push(`manifest.cards.${key}.${tierName} がシートの範囲外です`);
        }
        const id = `${tierName}/${got.sheet}/${got.col}/${got.row}`;
        if (seen.has(id)) errors.push(`セルが重複しています: ${id}（${key}）`);
        seen.add(id);
      }
    }
    // アイコン
    const mi = m.icons;
    const cells = iconCells();
    if (!mi) errors.push('manifest.icons がありません');
    else {
      if (mi.file !== ICON_FILE || mi.w !== ICON_SHEET.w || mi.h !== ICON_SHEET.h) errors.push('manifest.icons のファイル・寸法が規則と違います');
      const d = dims.get(ICON_FILE);
      if (d && (d.w !== mi.w || d.h !== mi.h)) errors.push(`${ICON_FILE}: 実寸 ${d.w}x${d.h} がマニフェストと一致しません`);
      const names = Object.keys(mi.items ?? {});
      if (names.length !== 18 || names.some((n, i) => n !== Object.keys(cells)[i])) errors.push('manifest.icons.items のキー（数・順序）が規則と一致しません');
      for (const [name, c] of Object.entries(cells)) {
        const it = mi.items?.[name];
        if (!it) continue;
        if (it.cell.x !== c.x || it.cell.y !== c.y || it.cell.w !== c.w || it.cell.h !== c.h) errors.push(`icons.${name}.cell が規則と違います`);
        const im = it.image;
        if (im.x < c.x || im.y < c.y || im.x + im.w > c.x + c.w || im.y + im.h > c.y + c.h) errors.push(`icons.${name} の画像がセルからはみ出しています`);
      }
    }
  }

  for (const r of rows) console.log(`  ${r.f}  ${r.w}x${r.h}  ${r.size} バイト`);
  console.log(`  合計 ${total} バイト`);
  for (const w of warnings) console.warn(`check-sprites: 警告: ${w}`);
  for (const e of errors) console.error(`check-sprites: エラー: ${e}`);
  if (errors.length) {
    console.error(`check-sprites: 失敗（エラー ${errors.length} 件）`);
    return false;
  }
  console.log(`check-sprites: OK（警告 ${warnings.length} 件）`);
  return true;
}

function parseArgs(argv) {
  const opts = { out: path.join(ROOT, 'src/img'), manifest: path.join(ROOT, 'build/sprites/manifest.json') };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--out') opts.out = path.resolve(argv[++i]);
    else if (argv[i] === '--manifest') opts.manifest = path.resolve(argv[++i]);
    else throw new Error(`不明なオプション: ${argv[i]}`);
  }
  return opts;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  checkSprites(parseArgs(process.argv.slice(2)))
    .then((ok) => process.exit(ok ? 0 : 1))
    .catch((e) => {
      console.error(`check-sprites: ${e.message}`);
      process.exit(1);
    });
}
