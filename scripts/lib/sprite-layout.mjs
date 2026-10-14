// スプライトの並び順の規則（仕様書 009 第 4.1・4.2・4.5 節）。build-sprites.mjs と check-sprites.mjs が共用する純関数。
// 副作用（ファイル読み書き）は持たない。

/** start シートの並び（4 列×5 行。左上から右へ行ごとに詰める） */
export const START_KEYS = [
  '01_1', '01_2', '01_3', '01_4',
  '02_1', '02_2', '02_3', '02_4',
  '02_5', '02_6', '02_7', '02_8',
  '03_1', '03_2', '03_3', '03_4',
  '56', '57', '58', 'rev',
];

/** 漂流カード 004〜055 のキー（2 桁ゼロ埋め） */
export const DRIFT_KEYS = Array.from({ length: 52 }, (_, i) => String(i + 4).padStart(2, '0'));

/** スプライトキー 72 個（原本のファイル名から拡張子を除いたもの）。出力順もこの順 */
export const CARD_KEYS = [...START_KEYS, ...DRIFT_KEYS];

/** 解像度ごとのセル寸法とシート定義（シートの寸法は cols/rows とセルから決まる） */
export const TIERS = {
  base: {
    cell: { w: 300, h: 419 },
    sheets: {
      start: { file: 'cards_start.webp', cols: 4, rows: 5 },
      drift: { file: 'cards_drift.webp', cols: 13, rows: 4 },
    },
  },
  zoom: {
    cell: { w: 500, h: 699 },
    sheets: {
      start: { file: 'zoom/cards_zoom_start.webp', cols: 4, rows: 5 },
      drift_a: { file: 'zoom/cards_zoom_drift_a.webp', cols: 6, rows: 5 },
      drift_b: { file: 'zoom/cards_zoom_drift_b.webp', cols: 6, rows: 4 },
    },
  },
};

/** シートの寸法（px） */
export function sheetSize(tier, sheet) {
  const t = TIERS[tier];
  const s = t.sheets[sheet];
  return { w: s.cols * t.cell.w, h: s.rows * t.cell.h };
}

/**
 * スプライトキー → 指定解像度でのシート・列・行。
 * @param {string} key スプライトキー（'01_1'、'04'、'rev' など）
 * @param {'base'|'zoom'} tier
 * @returns {{sheet: string, col: number, row: number}}
 */
export function cardPosition(key, tier) {
  const i = START_KEYS.indexOf(key);
  if (i >= 0) return { sheet: 'start', col: i % 4, row: Math.floor(i / 4) };
  if (!/^\d{2}$/.test(key)) throw new Error(`未知のスプライトキー: ${key}`);
  const n = Number(key) - 4;
  if (!Number.isInteger(n) || n < 0 || n >= 52) throw new Error(`未知のスプライトキー: ${key}`);
  if (tier === 'base') return { sheet: 'drift', col: n % 13, row: Math.floor(n / 13) };
  if (tier === 'zoom') {
    if (n < 30) return { sheet: 'drift_a', col: n % 6, row: Math.floor(n / 6) };
    const m = n - 30;
    return { sheet: 'drift_b', col: m % 6, row: Math.floor(m / 6) };
  }
  throw new Error(`未知の解像度: ${tier}`);
}

// ---- アイコン（第 4.5 節） ----

export const ICON_FILE = 'icons.webp';
/** SVG の viewBox の 1 単位 = 5 px（sharp の density 360 = 5 倍） */
export const ICON_SCALE = 5;
export const ICON_DENSITY = 360;

/** 記号セル 96×88 を 6 列×2 行（576×176）。ラベルセル 240×64 を 2 列×3 行（480×192）。全体 576×368 */
export const ICON_SYMBOL_CELL = { w: 96, h: 88, cols: 6, rows: 2 };
export const ICON_LABEL_CELL = { w: 240, h: 64, cols: 2, rows: 3 };
export const ICON_SHEET = { w: 576, h: 368 };

/** [出力名, 原本のファイル名（拡張子なし）]。並びがそのままセルの順序 */
export const ICON_SYMBOLS = [
  ['sym_earth', 'lv0'],
  ['sym_solar', 'lv1'],
  ['sym_galaxy', 'lv2'],
  ['sym_virgo', 'lv3'],
  ['sym_laniakea', 'lv4'],
  ['sym_unknown', 'lv6'],
  ['charm', 'ammulet'],
  ['power', 'power'],
  ['fame', 'fame'],
  ['cost', 'cost'],
  ['flag', 'flag'],
  ['crew', 'crew'],
];

export const ICON_LABELS = [
  ['tm_draw', 'ドロー時'],
  ['tm_explore', '探査時'],
  ['tm_exploring', '探査中'],
  ['tm_power_mod', '探査力変動'],
  ['tm_fame_mod', '名声変動'],
  ['tm_acquire', '獲得時'],
];

/** アイコン 18 点のセル位置（スプライト内の px）。 */
export function iconCells() {
  const out = {};
  ICON_SYMBOLS.forEach(([name, src], i) => {
    const c = ICON_SYMBOL_CELL;
    out[name] = { src, kind: 'symbol', x: (i % c.cols) * c.w, y: Math.floor(i / c.cols) * c.h, w: c.w, h: c.h };
  });
  const baseY = ICON_SYMBOL_CELL.rows * ICON_SYMBOL_CELL.h;
  ICON_LABELS.forEach(([name, src], i) => {
    const c = ICON_LABEL_CELL;
    out[name] = { src, kind: 'label', x: (i % c.cols) * c.w, y: baseY + Math.floor(i / c.cols) * c.h, w: c.w, h: c.h };
  });
  return out;
}
