// ローカルのプレビュー用の静的サーバー（依存なし）。リポジトリのルートを配信する。
// 実行: npm run preview（環境変数 PREVIEW_PORT でポートを変える。既定 8765）
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webp': 'image/webp',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
};

/**
 * @param {number} [port] 0 なら空いているポート
 * @returns {Promise<{ server: import('node:http').Server, port: number, url: string }>}
 */
export function startServer(port = Number(process.env.PREVIEW_PORT ?? 8765)) {
  const server = createServer(async (req, res) => {
    try {
      const { pathname } = new URL(req.url ?? '/', 'http://localhost');
      let rel = decodeURIComponent(pathname);
      if (rel === '/favicon.ico') {
        res.writeHead(204).end();
        return;
      }
      if (rel === '/') {
        rel = '/scripts/preview/index.html';
      }
      const file = path.resolve(root, `.${rel}`);
      if (file !== root && !file.startsWith(root + path.sep)) {
        res.writeHead(403).end('Forbidden');
        return;
      }
      const info = await stat(file);
      if (!info.isFile()) {
        res.writeHead(404).end('Not found');
        return;
      }
      const body = await readFile(file);
      res.writeHead(200, {
        'Content-Type': MIME[path.extname(file)] ?? 'application/octet-stream',
        'Cache-Control': 'no-store',
      });
      res.end(body);
    } catch {
      res.writeHead(404).end('Not found');
    }
  });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => {
      const address = /** @type {import('node:net').AddressInfo} */ (server.address());
      resolve({ server, port: address.port, url: `http://127.0.0.1:${address.port}` });
    });
  });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const { url } = await startServer();
  const fixtures = ['4p-prep-start', '4p-prep-late', '4p-explore', '3p-prep-mid', '4p-end'];
  console.log('プレビューを起動しました。次の URL をブラウザで開いてください（theme=dark、spectator=1 も指定できます）');
  for (const name of fixtures) {
    console.log(`  ${url}/scripts/preview/index.html?fixture=${name}`);
  }
}
