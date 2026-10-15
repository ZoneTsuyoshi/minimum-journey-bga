// 002（カードデータ仕様）§4 の一覧表と、PHP のカード定義（CardDefinitions.php）の整合性を確かめる。
// PHP は実行せず、正規表現で読む簡易なチェック。実行: npm run check:cards
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const spec = readFileSync(path.join(root, 'docs/specs/002-card-data.md'), 'utf8');
const php = readFileSync(path.join(root, 'src/modules/php/Material/CardDefinitions.php'), 'utf8');

const errors = [];
const fail = (m) => errors.push(m);

// --- 002 §4 の一覧表 ---
const specCards = new Map();
for (const line of spec.split('\n')) {
  const m = line.match(/^\| (0\d\d) \|/);
  if (!m) continue;
  const c = line.split('|').slice(1, -1).map((s) => s.trim());
  if (c.length !== 10) continue;
  const list = (s) => (s === '—' ? [] : s.split('<br>').map((x) => x.replace(/`/g, '')));
  specCards.set(c[0], {
    id: c[2].replace(/`/g, ''),
    symbols: list(c[3]),
    cost: c[4] === 'なし' ? null : Number(c[4]),
    power: Number(c[5]),
    fame: Number(c[6]),
    timings: list(c[7]),
    count: Number(c[8]),
  });
}
if (specCards.size !== 58) fail(`002 の一覧表のカード数が 58 ではありません: ${specCards.size}`);

// --- PHP ---
const symVar = { earth: 'EARTH', solar: 'SOLAR_SYSTEM', milky: 'MILKY_WAY', virgo: 'VIRGO_CLUSTER', lani: 'LANIAKEA' };
const symCase = { Earth: 'EARTH', SolarSystem: 'SOLAR_SYSTEM', MilkyWay: 'MILKY_WAY', VirgoCluster: 'VIRGO_CLUSTER', Laniakea: 'LANIAKEA', Unknown: 'UNKNOWN', Charm: 'CHARM' };
const timingVar = { draw: 'ON_DRAW', explore: 'ON_EXPLORE', during: 'DURING_EXPLORE', pmod: 'POWER_MOD', fmod: 'FAME_MOD', acq: 'ON_ACQUIRE' };

const phpCards = new Map();
const chunks = php.split("self::card('").slice(1);
for (const chunk of chunks) {
  const m = chunk.match(/^(\d{3})', '([a-z_]+)', ("[^"]*"|'[^']*'), '[^']*', (\$\w+|\[[^\]]*\]), (null|\d+), (\d+), (\d+)/s);
  if (!m) { fail(`PHP のカード定義を読めません: ${chunk.slice(0, 60)}`); continue; }
  const [, no, id, , symRaw, cost, power, fame] = m;
  const symbols = symRaw.startsWith('$')
    ? [symVar[symRaw.slice(1)]]
    : [...symRaw.matchAll(/Symbol::(\w+)/g)].map((x) => symCase[x[1]]);
  const timings = [...chunk.matchAll(/new EffectDefinition\(\$(\w+),/g)].map((x) => timingVar[x[1]]);
  const countM = chunk.match(/\], (\d+)\),\n/);
  const isStarter = Number(no) <= 3;
  phpCards.set(no, {
    id, symbols, cost: cost === 'null' ? null : Number(cost), power: Number(power), fame: Number(fame), timings,
    count: isStarter && countM ? Number(countM[1]) : 1,
  });
}
if (phpCards.size !== 58) fail(`PHP のカード数が 58 ではありません: ${phpCards.size}`);

for (const [no, s] of specCards) {
  const p = phpCards.get(no);
  if (!p) { fail(`${no}: PHP に定義がありません`); continue; }
  for (const key of ['id', 'cost', 'power', 'fame', 'count']) {
    if (s[key] !== p[key]) fail(`${no}: ${key} が不一致（002=${s[key]}, PHP=${p[key]}）`);
  }
  for (const key of ['symbols', 'timings']) {
    if (JSON.stringify(s[key]) !== JSON.stringify(p[key])) {
      fail(`${no}: ${key} が不一致（002=${s[key].join(',')}, PHP=${p[key].join(',')}）`);
    }
  }
}
for (const no of phpCards.keys()) if (!specCards.has(no)) fail(`${no}: 002 にありません`);

// --- 検算 ---
const drift = [...phpCards].filter(([no]) => Number(no) >= 4 && Number(no) <= 55).map(([, c]) => c);
const byCost = (n) => drift.filter((c) => c.cost === n).length;
const costCounts = [2, 4, 6, 8].map(byCost);
if (drift.length !== 52) fail(`漂流カードが 52 枚ではありません: ${drift.length}`);
if (costCounts.join('/') !== '15/15/12/10') fail(`コスト別枚数が 15/15/12/10 ではありません: ${costCounts.join('/')}`);
// 057/058 は両面 1 枚なので、お守りは合わせて 1 枚と数える
const total = 1 + [...phpCards].filter(([no]) => Number(no) <= 56).reduce((a, [, c]) => a + c.count, 0);
if (total !== 70) fail(`総枚数が 70 ではありません: ${total}`);
// 002 §7.2 の探査力・名声の合計（コスト 2/4/6/8 の順）
const sums = { power: [28, 38, 32, 34], fame: [22, 37, 35, 41] };
[2, 4, 6, 8].forEach((cost, i) => {
  for (const k of ['power', 'fame']) {
    const sum = drift.filter((c) => c.cost === cost).reduce((a, c) => a + c[k], 0);
    if (sum !== sums[k][i]) fail(`コスト ${cost} の ${k} の合計が 002 §7.2 と不一致: ${sum} != ${sums[k][i]}`);
  }
});

if (errors.length > 0) {
  console.error(errors.join('\n'));
  console.error(`NG: ${errors.length} 件`);
  process.exit(1);
}
console.log(`OK: 58 種すべて 002 と一致（番号・識別子・コスト・探査力・名声・シンボル・タイミング・枚数）`);
console.log(`検算: 漂流 ${drift.length} 枚（コスト 2/4/6/8 = ${costCounts.join('/')}）、合計 ${total} 枚`);
