# ローカルのプレビュー環境

BGA Studio に上げなくても、実際のクライアント（`src/modules/js/Game.js` と `src/minimumjourney.css`）を手元のブラウザで表示し、各画面幅のスクリーンショットを撮るための開発用ツール。BGA へは同期しない（仕様書 013 §11.1）。

## 前提

- `npm ci` 済みであること。
- `npm run build:sprites` でカード画像のスプライト（`src/img/`）を生成済みであること（コミットしない）。
- ネットワークに接続できること。bga-cards と bga-animations を BGA の CDN（`x.boardgamearena.net`）から読み込む。
- スクリーンショットには Google Chrome が必要。場所は環境変数 `CHROME_PATH`（既定 `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`）。ブラウザのダウンロードはしない。

## 使い方

```bash
npm run preview        # サーバーを起動し、開く URL を表示（ポートは PREVIEW_PORT、既定 8765）
npm run preview:shots  # 全 fixture × 全サイズ × 2 テーマを撮影（約 3 分）
npm run preview:shots -- --fixture 4p-prep-late --size 1920x1080 --theme dark
```

- URL の引数: `fixture`（固定データの名前）、`theme`（`light` / `dark`）、`spectator=1`（観戦者）、`animations=1`（アニメーションを有効に。既定は無効）、`touch=0/1`（タッチ端末の扱いを固定）。
- `preview:shots` の引数は `--fixture` / `--size` / `--theme`（いずれもカンマ区切りで複数可）と `--spectator`。サイズは 360x780、390x844、768x1024、1024x768、1280x800、1366x768、1440x900、1920x1080、2560x1440。
- 出力は `scripts/preview/out/<fixture>/<W>x<H>-<theme>.png`（見えている範囲）と `...-full.png`（ページ全体）。`out/` は `.gitignore` 済み。
- ページのエラー（`pageerror`、`console.error`、リクエスト失敗、`data-error`）があると一覧を出して終了コード 1 になる。読み込みが終わると `<html data-ready="1">` が立つ。
- 操作の記録: `performAction` は `console.log` され、`window.__mjActions` に積まれる。

## 固定データ

`fixtures/*.json` は `{ preview, gamedatas }`。`gamedatas` は `getAllDatas` と同じ形、`preview` は自分の ID、プレイヤー名と色、入る状態（名前・args・自分がアクティブか）、`replay`（`setup` の後に呼ぶ `notif_*`）を持つ。`card-info.json` はツールチップ用のカード情報（名前・コスト・探査力・名声・シンボルのみ。効果文は空）で、各 fixture が `cardInfo` を持たないときに使う。

| 名前 | 内容 |
| --- | --- |
| `4p-prep-start` | 4 人、1 ラウンド目の準備の開始。自分は未選択 |
| `4p-prep-late` | 4 人、3 ラウンド目の準備の後半。46 と付加カード、横向き、選択済み、ストップ済み |
| `4p-explore` | 4 人、探査フェイズ。自分の手番（`ExploreChoose`）、獲得カード、お守り |
| `3p-prep-mid` | 3 人、準備の途中（列 3/3/2/2） |
| `4p-pile8-empty` | 4 人、探査フェイズ。コスト 8 の探査山札が 0 枚（最終ラウンドのバナー） |
| `4p-row-compact` | 4 人。列に穴がある状態（011 で伸びた列と、中ほどが空いた列）に、`replay` で `rowCompacted`（左詰め）を流す |
| `4p-end` | 4 人、ゲーム終了（`finalDecks`・`finalSummaries`） |

## BGA の画面との違い

模している範囲: 上のトップバー（ダミー）、ステータスバー（タイトルとボタン。青・赤・白枠・灰の 4 色）、ゲーム領域、プレイヤーパネル（デスクトップは右に幅 260px の列、モバイルは 2 列の格子をゲーム領域の上）。`body` の `id="ebd-body"`、`desktop_version` / `mobile_version`、`touch-device` / `notouch-device`、`html[data-theme]`、`html` の木目風の背景（ゲームの CSS が `body` に背景を指定するとそちらが前に出る）。

違い・模していない点:

- モバイルの境目は `MOBILE_MAX_WIDTH = 980`（`preview.js` と `shoot.mjs` の定数）。BGA の実際の境目は未確認。
- `touch-device` は実際のタッチ検出（`navigator.maxTouchPoints`）で決める。幅を変えても切り替わらない（`touch=` で固定可）。撮影は 980px 未満でタッチ端末として開く。
- トップバー・ステータスバーは固定表示（sticky）にしていない。ボタンやパネルの寸法・フォント・色は目安で、BGA の実物と一致しない。
- パネルの中身は `Game.js` が入れた要素だけ（BGA のアバター、時計、スコア欄などは無い）。
- 翻訳（`_()`）は原文をそのまま返す。ログ、通知、手番の進行、サーバー側の処理は無い。`gamedatas.gamestate` は fixture から作る。
- 画像・ライブラリは手元の `src/img/` と CDN の最新（1.x）を使う。
