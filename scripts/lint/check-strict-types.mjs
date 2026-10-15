// P2: src/ 配下のすべての PHP が declare(strict_types=1); で始まることを検査する（仕様書 004 第 2.4 節）。
// `<?php` の次の最初の文（コメントと空白は除く）が declare(strict_types=1); であること。外部依存なし。
// 実行: npm run lint:strict
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

function* walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* walk(full);
    else if (entry.name.endsWith('.php')) yield full;
  }
}

// `<?php` の後ろの、先頭の空白とコメント（/* */、//、#）を取り除く
function stripLeading(code) {
  let rest = code.replace(/^<\?php/, '');
  for (;;) {
    const next = rest
      .replace(/^\s+/, '')
      .replace(/^\/\*[\s\S]*?\*\//, '')
      .replace(/^(\/\/|#(?!\[))[^\n]*/, '');
    if (next === rest) return rest;
    rest = next;
  }
}

const errors = [];
let count = 0;
try {
  for (const file of walk('src')) {
    count++;
    const code = readFileSync(file, 'utf8');
    if (!code.startsWith('<?php')) {
      errors.push(`${file}: 先頭が <?php ではありません`);
    } else if (!/^declare\(\s*strict_types\s*=\s*1\s*\)\s*;/.test(stripLeading(code))) {
      errors.push(`${file}: 最初の文が declare(strict_types=1); ではありません`);
    }
  }
} catch (e) {
  if (e.code !== 'ENOENT') throw e; // src/ が無ければ対象 0 件
}

if (errors.length > 0) {
  console.error(`check-strict-types: ${errors.length} 件の問題があります。`);
  for (const e of errors) console.error(`  ${e}`);
  process.exit(1);
}
console.log(`check-strict-types: OK（PHP ${count} 件）`);
