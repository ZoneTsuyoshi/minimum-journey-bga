// J2: JavaScript の型チェック（tsc --noEmit。仕様書 004 第 2.6 節）。実行: npm run lint:types
// 型定義 tools/bga/bga-framework.d.ts は BGA の配布物でコミットしない（.gitignore 済み）。
// 無い場合は、既定ではスキップして成功する（ローカル用）。--require を付けると失敗にする（CI で取得後に使う）。
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const stub = 'tools/bga/bga-framework.d.ts';
if (!existsSync(stub)) {
  if (process.argv.includes('--require')) {
    console.error(`check-types: ${stub} がありません（--require 指定）`);
    process.exit(1);
  }
  console.warn(`check-types: ${stub} が無いためスキップします（Studio の初期ファイルから tools/bga/ にコピーすると実行されます）`);
  process.exit(0);
}

const result = spawnSync('npx', ['tsc', '--noEmit', '-p', 'tsconfig.json'], { stdio: 'inherit' });
process.exit(result.status ?? 1);
