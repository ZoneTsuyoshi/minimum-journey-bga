// S2: 禁止ファイル・src/ 衛生の検査（仕様書 004 第 2.3 節・第 6.2 節）。
// 外部依存なし。git ls-files の出力を検査する。実行: npm run lint:repo
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const files = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  .split('\0')
  .filter(Boolean);

const errors = [];
const fail = (file, message) => errors.push(`${file}: ${message}`);

// 1. 認証情報などの禁止ファイル（.gitignore と同じ集合）
const forbiddenGlobal = [
  { test: (b) => b === '.env' || (b.startsWith('.env.') && b !== '.env.example'), label: '.env 系ファイル' },
  { test: (b) => b === 'sftp.json', label: 'sftp.json' },
  { test: (b) => b.endsWith('.pem'), label: '*.pem' },
  { test: (b) => b.startsWith('id_rsa'), label: 'id_rsa*' },
  { test: (b) => b.startsWith('id_ed25519'), label: 'id_ed25519*' },
];
for (const file of files) {
  const base = path.posix.basename(file);
  for (const rule of forbiddenGlobal) {
    if (rule.test(base)) fail(file, `コミット禁止のファイルです（${rule.label}）`);
  }
}

// 2. src/ 配下に置いてはいけない開発用ファイル
const devFile = [
  { test: (b) => b === 'package.json' || b === 'package-lock.json', label: 'package.json 系' },
  { test: (b) => b === 'composer.json' || b === 'composer.lock', label: 'composer 系' },
  { test: (b) => /^tsconfig.*\.json$/.test(b), label: 'tsconfig*.json' },
  { test: (b) => /^eslint\.config\./.test(b), label: 'eslint.config.*' },
  { test: (b) => /^stylelint\.config\./.test(b), label: 'stylelint.config.*' },
  { test: (b) => /^\..*rc/.test(b), label: '.*rc* 設定ファイル' },
  { test: (b) => /^phpcs\.xml/.test(b), label: 'phpcs.xml*' },
  { test: (b) => /^phpstan\.neon/.test(b), label: 'phpstan.neon*' },
  { test: (b) => /\.(test|spec)\./.test(b), label: '*.test.* / *.spec.*' },
];
const devDirs = ['node_modules', 'tests'];

// 4. src/ 配下のファイル名に使える文字（空白・括弧・非 ASCII を禁止）
const safeName = /^[A-Za-z0-9_.\-/]+$/;

for (const file of files) {
  if (!file.startsWith('src/')) continue;
  const parts = file.split('/');
  const base = parts[parts.length - 1];

  for (const rule of devFile) {
    if (rule.test(base)) fail(file, `src/ に開発用ファイルは置けません（${rule.label}）`);
  }
  for (const dir of devDirs) {
    if (parts.slice(0, -1).includes(dir)) fail(file, `src/ に開発用ディレクトリは置けません（${dir}/）`);
  }
  // 3. コミット禁止のスプライト生成物
  if (file.startsWith('src/img/cards_')) {
    fail(file, 'スプライト生成物はコミットできません（.gitignore 対象）');
  }
  if (!safeName.test(file)) {
    fail(file, 'ファイル名に空白・括弧・非 ASCII 文字などは使えません（使える文字: A-Z a-z 0-9 _ . -）');
  }
}

if (errors.length > 0) {
  console.error(`check-repo: ${errors.length} 件の問題があります。`);
  for (const e of errors) console.error(`  ${e}`);
  process.exit(1);
}
const srcCount = files.filter((f) => f.startsWith('src/')).length;
console.log(`check-repo: OK（追跡ファイル ${files.length} 件、うち src/ ${srcCount} 件）`);
