// プレビューのスクリーンショットを撮る。実行: npm run preview:shots（絞り込みの例は README.md）
// Chrome の場所は環境変数 CHROME_PATH（既定は macOS の Google Chrome）
import { chromium } from 'playwright-core';
import { mkdir, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { startServer } from './serve.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const MOBILE_MAX_WIDTH = 980;
const SIZES = ['360x780', '390x844', '768x1024', '1024x768', '1280x800', '1366x768', '1440x900', '1920x1080', '2560x1440'];
const THEMES = ['light', 'dark'];
const CHROME_PATH = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

/** @returns {{ fixture: string[], size: string[], theme: string[], spectator: boolean }} */
function parseArgs() {
  const result = { fixture: /** @type {string[]} */ ([]), size: /** @type {string[]} */ ([]), theme: /** @type {string[]} */ ([]), spectator: false };
  const argv = process.argv.slice(2);
  for (let i = 0; i < argv.length; i++) {
    const key = argv[i];
    if (key === '--spectator') {
      result.spectator = true;
    } else if (key === '--fixture' || key === '--size' || key === '--theme') {
      const value = argv[++i];
      if (value === undefined) {
        throw new Error(`${key} に値がありません`);
      }
      result[key.slice(2)].push(...value.split(','));
    } else {
      throw new Error(`不明な引数: ${key}（--fixture / --size / --theme（カンマ区切り可）、--spectator）`);
    }
  }
  return result;
}

const args = parseArgs();
const allFixtures = (await readdir(path.join(here, 'fixtures')))
  .filter((f) => f.endsWith('.json') && f !== 'card-info.json')
  .map((f) => f.replace(/\.json$/, ''));
const fixtures = args.fixture.length > 0 ? args.fixture : allFixtures;
const sizes = args.size.length > 0 ? args.size : SIZES;
const themes = args.theme.length > 0 ? args.theme : THEMES;
for (const [list, known, label] of [[fixtures, allFixtures, 'fixture'], [sizes, SIZES, 'size'], [themes, THEMES, 'theme']]) {
  for (const item of list) {
    if (label === 'size' ? !/^\d+x\d+$/.test(item) : !known.includes(item)) {
      throw new Error(`不明な ${label}: ${item}`);
    }
  }
}

const { server, url } = await startServer(0);
const browser = await chromium.launch({ executablePath: CHROME_PATH, headless: true });
/** @type {string[]} */
const errors = [];
let count = 0;
try {
  for (const fixture of fixtures) {
    const dir = path.join(here, 'out', args.spectator ? `${fixture}-spectator` : fixture);
    await mkdir(dir, { recursive: true });
    for (const size of sizes) {
      const [width, height] = size.split('x').map(Number);
      for (const theme of themes) {
        const label = `${fixture} ${size} ${theme}`;
        const mobile = width < MOBILE_MAX_WIDTH;
        const context = await browser.newContext({
          viewport: { width, height },
          hasTouch: mobile,
          isMobile: false,
          deviceScaleFactor: 1,
          colorScheme: theme,
        });
        const page = await context.newPage();
        page.on('pageerror', (e) => errors.push(`[${label}] pageerror: ${e.message}`));
        page.on('console', (m) => {
          if (m.type() === 'error') {
            errors.push(`[${label}] console.error: ${m.text()} ${m.location().url ?? ''}`);
          }
        });
        page.on('requestfailed', (r) => errors.push(`[${label}] requestfailed: ${r.url()} ${r.failure()?.errorText ?? ''}`));
        const query = new URLSearchParams({ fixture, theme });
        if (args.spectator) {
          query.set('spectator', '1');
        }
        await page.goto(`${url}/scripts/preview/index.html?${query}`);
        try {
          await page.waitForFunction(() => document.documentElement.dataset.ready === '1' || document.documentElement.dataset.error !== undefined, null, { timeout: 30000 });
        } catch {
          errors.push(`[${label}] data-ready になりませんでした（タイムアウト）`);
        }
        const pageError = await page.evaluate(() => document.documentElement.dataset.error);
        if (pageError !== undefined) {
          errors.push(`[${label}] data-error: ${pageError.trim()}`);
        }
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForTimeout(300);
        const base = path.join(dir, `${size}-${theme}`);
        await page.screenshot({ path: `${base}.png` });
        await page.screenshot({ path: `${base}-full.png`, fullPage: true });
        count += 2;
        await context.close();
      }
    }
  }
} finally {
  await browser.close();
  server.close();
}

console.log(`${count} 枚を scripts/preview/out/ に保存しました`);
if (errors.length > 0) {
  console.error(`\nエラーが ${errors.length} 件ありました:`);
  for (const e of new Set(errors)) {
    console.error(`  ${e}`);
  }
  process.exit(1);
}
