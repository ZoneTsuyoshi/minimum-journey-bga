# 004: CI（lint チェック）

| 項目 | 内容 |
| --- | --- |
| ステータス | Draft |
| 対応タスク | T-004 |
| 作成日 | 2026-10-13 |
| 関連仕様書 | 003（BGA プロジェクト構成・技術方針）。後続: T-005（Studio 自動同期）、T-009（UI・アセット） |

## 目的

`main` へ入る変更が、構文・書式・型・認証情報の混入・アセット上限といった機械的に検出できる問題を含まないことを、PR ごとに GitHub Actions で自動検査するための仕様を定める。方針は [../development/cicd.md](../development/cicd.md) にあり、本書はそれを具体化し、実装者が本書だけを見てワークフローと設定ファイルを導入できる粒度にする。

## スコープ

- 対象: lint の対象・ツール・設定ファイル・実行コマンド、ワークフロー構成、段階導入の計画、ローカル実行、追加されるファイル、GitHub 側の設定手順、既存ドキュメントへの適用見込み
- 対象外: ワークフローや設定ファイルの実装（本書の承認後に別タスク・別 PR で行う）、CD（`src/` の Studio 同期、仕様書 005）、スプライト生成の詳細設計（T-009）、PHPUnit などの単体テスト（lint ではなくテストであり、必要になった時点で別途仕様化する）

## 記法と調査の前提

- **【確認】** は公式ドキュメントまたはパッケージレジストリで確認した事実。URL（または確認方法）を添える。
- **【提案】** は本書の提案・推測。
- **【未確認】** は確認を試みたが判断できなかった事項。「未確定事項」の A にまとめる。
- 調査日は 2026-10-13。公式ドキュメントのページは WebFetch（本文を要約して返すツール）で取得したため、**要約を介した確認**である。設定キー名や CLI フラグなど実装時に効くものは、実装者が各 URL を再読して最終確認すること。バージョンは npm / Packagist / GitHub Releases の API で取得した値で、実装時点の最新に更新すること。

### 確認済みのバージョン（2026-10-13 時点）

| 対象 | バージョン | 備考と出典 |
| --- | --- | --- |
| actions/checkout | v7 | README の推奨は `actions/checkout@v7`。<https://github.com/actions/checkout> |
| actions/setup-node | v7 | v7 で内部が ESM 化。`package.json` に `packageManager` があると npm キャッシュが自動有効化される。<https://github.com/actions/setup-node> |
| shivammathur/setup-php | v2（ローリングタグ） | `php-version: '8.4'`、`tools: composer, cs2pr`、`coverage: none` の例が README にある。<https://github.com/shivammathur/setup-php> |
| actions/cache | v6.1.0 | GitHub Releases API で取得（setup-php の README 例は v4 だが、これは古い記述と判断） |
| gitleaks/gitleaks-action | v3.0.0 | 個人アカウントのリポジトリはライセンスキー不要、Organization は無料ライセンスキーが必要。`GITHUB_TOKEN` は PR コメント投稿に使う。<https://github.com/gitleaks/gitleaks-action> |
| lycheeverse/lychee-action | v2.9.0 | `args` / `fail` などの入力。<https://github.com/lycheeverse/lychee-action> |
| lychee 本体 | 0.24.2 | `--offline`（ローカルファイルのみ検査）、`--include-fragments`、`--exclude-path`、`lychee.toml` がある。<https://github.com/lycheeverse/lychee> |
| ESLint | 10.12.0 | Node `^20.19.0 \|\| ^22.13.0 \|\| >=24`。設定は flat config（`eslint.config.js` / `.mjs` / `.cjs`）、`languageOptions.globals`、`defineConfig()`。<https://eslint.org/docs/latest/use/configure/configuration-files> |
| @eslint/js / @eslint/json / globals | 10.0.1 / 2.1.1 / 17.13.0 | npm レジストリで取得 |
| TypeScript | 7.0.2 | ネイティブ（Go）実装。JS の型チェックが 6.x より厳しい（`@enum` / `@constructor` 非対応など）。<https://typescriptdocs.com/release-notes/TypeScript%207.0> |
| Stylelint / stylelint-config-standard | 17.16.0 / 40.0.0 | どちらも Node `>=20.19.0`。設定ファイルは `stylelint.config.mjs`、実行は `stylelint "**/*.css"`。<https://stylelint.io/user-guide/get-started> |
| markdownlint-cli2 | 0.23.3（markdownlint 0.41.1） | Node `>=22`。設定は `.markdownlint-cli2.jsonc`（最優先）または `.markdownlint.jsonc`。<https://github.com/DavidAnson/markdownlint-cli2> |
| PHP_CodeSniffer | 4.0.4 | 既定の設定ファイル名は `.phpcs.xml` → `phpcs.xml` → `.phpcs.xml.dist` → `phpcs.xml.dist` の順。<https://github.com/squizlabs/PHP_CodeSniffer/wiki/Advanced-Usage> |
| PHPStan | 2.3.1 | `scanFiles` / `scanDirectories` / `bootstrapFiles` で自動読み込みできないクラスを認識させられる。<https://phpstan.org/user-guide/discovering-symbols> |
| php-parallel-lint | 1.4.0 | Packagist で取得 |
| image-size | 2.0.4 | 純 JS の画像寸法取得（アセット検査用）。npm レジストリで取得 |
| BGA の PHP | 8.4 | <https://en.doc.boardgamearena.com/Studio>（仕様書 003 第 3.1 節でも確認済み） |
| BGA の `Game.js` | ES モジュール | `export` するクラス。グローバルに `_()`、`$()`、`dojo`、`ebg`、`importEsmLib()`、`importDojoLibs()`、`g_gamethemeurl` がある。<https://en.doc.boardgamearena.com/Game_interface_logic:_Game.js> |

## 仕様

### 1. 全体方針

- **【提案】** ワークフロー `lint`（`.github/workflows/lint.yml`）を `pull_request`（対象ブランチ `main`）と `main` への `push` で実行する。1 つでも失敗した PR はマージしない（`main` の保護で必須化、第 7 節）。
- **【提案】** チェックは**ローカルでも同じコマンド**で実行できる。CI のステップは `npm run …` / `composer …` を呼ぶだけにし、コマンドの実体を `package.json` と `composer.json` に一本化する（CI 専用のロジックをワークフロー YAML に書かない）。例外は、実行に Node 以外のバイナリが要る gitleaks と lychee のみ。
- **【提案】** lint は**検査のみ**で、ファイルを書き換えない（自動整形のコミットはしない）。自動修正が欲しい場合の `--fix` 系コマンドはローカル用に別名で用意してよいが、CI では使わない。
- **【提案】** 本書が定義するのは「必須の lint」のみ。外部 URL のリンク切れ検査のように、ネットワーク状況で結果が揺れるものは必須にしない（第 2.2 節）。
- **【提案】** `data/`（原本アセット）、`.worktrees/`、`node_modules/`、`vendor/` は全ツールの対象外とする。

### 2. チェック項目ごとの仕様

対象と実行タイミングの一覧（導入段階は第 3 節）。

| ID | チェック | 対象 | ツール | 段階 |
| --- | --- | --- | --- | --- |
| M1 | Markdown の書式 | リポジトリ内の `*.md`（除外あり） | markdownlint-cli2 | 1 |
| M2 | リンク切れ（内部リンク） | 同上 | lychee（`--offline`） | 1 |
| S1 | 認証情報の混入 | 全履歴（PR の差分範囲）と作業ツリー | gitleaks | 1 |
| S2 | 禁止ファイル・`src/` 衛生 | `git ls-files` | Node スクリプト | 1 |
| P1 | PHP 構文 | `src/**/*.php` | php-parallel-lint（`php -l`） | 2 |
| P2 | PHP コーディング規約・`strict_types` | 同上 | PHP_CodeSniffer、grep | 2 |
| P3 | PHP 静的解析 | 同上 | PHPStan | 2 |
| J1 | JS の lint | `src/modules/js/**/*.js`、`scripts/**/*.mjs`、設定ファイル | ESLint | 2 |
| J2 | JS の型チェック | `src/modules/js/**/*.js` | `tsc --noEmit` | 2 |
| C1 | CSS の lint | `src/**/*.css` | Stylelint | 2 |
| D1 | JSONC の構文 | `src/**/*.{json,jsonc}` | ESLint + @eslint/json | 2 |
| A1 | アセット上限 | 生成後の `src/img/**` | Node スクリプト | 3 |

#### 2.1 Markdown の書式（M1）

- **対象**: `README.md`、`CLAUDE.md`、`docs/**/*.md` ほか、リポジトリ内のすべての `*.md`。除外: `data/`、`.worktrees/`、`node_modules/`、`vendor/`。
- **ツール【提案】**: **markdownlint-cli2**（`markdownlint-cli2` を devDependency に入れ、`npx` ではなくロックファイルで固定する）。
  - 比較: remark-lint は柔軟だがプラグイン構成が重く、日本語文書に対する既定ルールの調整量も同程度。Prettier は整形が目的で lint ではない。markdownlint は既定ルールが実用的で、設定が JSONC 1 ファイルで済む。公式の GitHub Action（`markdownlint-cli2-action`）もあるが、ローカルと CI を同じコマンドにするため使わず、`npm run lint:md` を呼ぶ。
- **設定ファイル**: リポジトリ直下の `.markdownlint-cli2.jsonc`（globs・除外・ルール設定を 1 ファイルに置ける）。
- **ルール方針【提案】**: 既定（全ルール有効）から、日本語の仕様書・表・URL 引用の多い文書に合わない以下を調整する。

| ルール | 方針 | 理由 |
| --- | --- | --- |
| MD013（行長） | **無効** | 日本語は単語区切りがなく、文字数ベースの折り返しが不自然。既存の表も 100 文字超が普通。 |
| MD033（インライン HTML） | 有効。許可要素は `br` のみ | 表のセル内改行用。それ以外の HTML は書かせない。`<game>` のような山括弧をそのまま書くとここで検出されるため、コードスパン（バッククォート）で囲む。 |
| MD034（裸の URL） | **無効** | 仕様書は根拠 URL を表のセルや文中に大量に貼る（仕様書 003 だけで 53 件）。GitHub は裸の URL も自動リンクにする。 |
| MD036（強調を見出し代わりに使う） | **無効** | 仕様書 003 が太字の行を小見出し的に使っている。見出しにするかは書き手の判断に任せる。 |
| MD024（見出しの重複） | 有効。`siblings_only: true` | 「受け入れ条件」などの同名見出しが別の親の下に出るのは許容する。 |
| MD052（参照リンクの定義漏れ） | **無効** | 仕様書 002 が用語を `[探査力][名声]` のように角括弧で連ねて書いており、参照リンクと誤認される。リンク切れは M2 が別途見る。 |
| MD060（表の列スタイル） | **無効** | 新しいルールで、CJK の全角幅の扱いが絡み、既存の表（コンパクト形式 `\| --- \|`）でも書き手によって揺れるため。表の見た目の整列は強制しない。 |
| MD041（先頭行は H1） | 有効 | 仕様書は `# NNN: タイトル` で始める運用と合致する。 |
| MD040（コードブロックの言語指定） | 有効（既定） | 「コードブロックには言語指定」の運用ルールを機械的に守らせる。言語を付けない出力例は `text` を指定する。 |
| その他 | 既定のまま | MD047（末尾改行）、MD031/MD032（空行）、MD001（見出しレベル）、MD004（リスト記号の統一）など。 |

- **実行コマンド**: `npx markdownlint-cli2`（引数なし。globs と除外は設定ファイルに書く）。`package.json` の `lint:md` に割り当てる。

#### 2.2 リンク切れ（M2）

- **対象**: M1 と同じ Markdown。
- **ツール【提案】**: **lychee**（Rust 製の単一バイナリ。GitHub Action `lycheeverse/lychee-action` で CI に導入できる）。
  - 比較: `markdown-link-check`（Node 製）は npm だけで完結するが、リンクごとの逐次処理が遅く、メンテナンス頻度も lychee に劣る【提案、未実測】。lychee は `--offline` で外部通信を完全に止められる。
- **必須チェックの範囲【提案】**: PR の必須チェックでは**内部リンク（相対パスのファイル参照、`#` フラグメント）のみ**を検査する（`--offline`）。外部 URL は、相手サーバーの一時的な不調や BGA ドキュメントの再編で PR と無関係に落ちるため、必須にしない。外部 URL の定期検査は、任意のスケジュール実行ワークフロー（週 1 回、失敗しても通知のみ）として別途追加できる（未確定事項 No.6）。
- **設定ファイル**: リポジトリ直下の `lychee.toml`。主な設定: `offline = true`、`include_fragments`（フラグメント検査。日本語見出しのアンカー生成が GitHub と一致するかは **【未確認】**。誤検出が出る場合は無効にする）、`exclude_path = ['^\.worktrees/', '^node_modules/', '^data/', '^vendor/']`（正規表現。lychee 公式の説明に基づく）。
- **実行コマンド**: `lychee --config lychee.toml './**/*.md'`（CI は `lychee-action` の `args` に同じ引数を渡す）。ローカルで実行するには `brew install lychee` が必要で、`npm run lint` には含めない（`npm run lint:links` として別枠にする。第 5 節）。
- **補足**: `README.md` や `docs/` から `data/rulebook/minimum_journey_rulebook.pdf` などの `data/` 配下を指す相対リンクは、リンク先の存在確認の対象になる（除外するのは「`data/` 内の Markdown を検査対象にしない」ことで、リンク先としては検査される）。除外パターンの意味を実装時に確認すること。

#### 2.3 認証情報の混入と禁止ファイル（S1・S2）

CLAUDE.md の絶対ルール 7（認証情報をコミットしない）を機械的に守らせる。

**S1: gitleaks**

- **ツール【提案】**: **gitleaks**（既定ルールセットに SSH 秘密鍵ヘッダ `BEGIN OPENSSH PRIVATE KEY`、各種トークン、`password=` 型の代入が含まれる）。CI では公式アクション `gitleaks/gitleaks-action` を使う。
  - 比較: GitHub の Secret Scanning / Push Protection は Free の公開リポジトリで無料だが、非公開リポジトリでは有料（Secret Protection）になる可能性がある**【未確認】**うえ、必須チェックとして PR に出せない。gitleaks は無料で、ローカルでも同じ検査ができる（`brew install gitleaks`）。TruffleHog は検証付きで強力だが、本リポジトリ規模では過剰。
- **ライセンス【確認】**: 個人アカウント所有のリポジトリではライセンスキー不要。Organization 所有の場合は無料のライセンスキーを取得し、Secrets `GITLEAKS_LICENSE` に登録する（<https://github.com/gitleaks/gitleaks-action>）。現在のリポジトリ所有者は Git ユーザー名から個人アカウントと推測されるが、**【未確認】**（未確定事項 No.5）。
- **設定【提案】**: リポジトリ直下の `.gitleaks.toml` で既定ルールを継承し（`[extend] useDefault = true`）、誤検出の許可リスト（`data/` のバイナリ、`package-lock.json` の `integrity` ハッシュなど）だけを追加する。独自ルールとして、BGA Studio の接続情報の取りこぼしを防ぐため `sftp.json` 形式（`"password"` キーを含む JSON）を検出するルールを追加することを推奨する。
- **実行【提案】**: ワークフローでは `fetch-depth: 0` でチェックアウトし、PR では PR の差分コミット範囲を検査する。**PR コメントは無効**（`GITLEAKS_ENABLE_COMMENTS: false`）にして `pull-requests: write` を要求しない（権限の最小化、第 4.5 節）。ローカルは `gitleaks git --redact`（`npm run lint` には含めない）。
- **検出時の対応【提案】**: 検出された認証情報は、履歴から消すだけでは不十分で、**必ず失効・再発行する**（BGA 側の SSH 鍵の再登録など）。この手順は 005 で扱う。

**S2: 禁止ファイル・`src/` 衛生（Node スクリプト）**

- **実装【提案】**: `scripts/lint/check-repo.mjs`（外部依存なしの Node スクリプト。`git ls-files` の出力を検査する）。`npm run lint:repo` で実行する。
- **検査内容**:
  1. 追跡されているファイルに、次のパターンがあれば失敗する。`.env`、`.env.*`（`.env.example` は許可）、`sftp.json`、`.vscode/sftp.json`、`*.pem`、`id_rsa*`、`id_ed25519*`（`.gitignore` と同じ集合。`.gitignore` の漏れ・`git add -f` を防ぐ）。
  2. `src/` 配下の追跡ファイルに、開発用ファイルがあれば失敗する（第 6.2 節）。禁止: `node_modules/`、`package.json`、`package-lock.json`、`composer.json`、`composer.lock`、`tsconfig*.json`、`eslint.config.*`、`stylelint.config.*`、`.*rc*`、`phpcs.xml*`、`phpstan.neon*`、`*.test.*`、`*.spec.*`、`tests/`。
  3. `src/` 配下の追跡ファイルに、コミット禁止のスプライト生成物（`src/img/cards_*`、仕様書 003 第 2 節）が含まれていれば失敗する（`.gitignore` 漏れの検出。生成物が決まる段階 3 で有効化する）。
  4. `src/` 配下の追跡ファイル名に空白・括弧・非 ASCII 文字が含まれていれば失敗する（BGA の画像ファイル名制約、仕様書 003 第 5.1 節。画像以外にも適用して単純にする）。

#### 2.4 PHP（P1・P2・P3）

- **対象**: `src/**/*.php`（`src/modules/php/` 配下。`_ide_helper.php` は Studio 側のファイルでリポジトリに入れない方針、仕様書 003 第 2 節）。`src/` の雛形は取り込み済み（2026-10-14、`feat/src-skeleton`）で、第 2 段階の前提を満たす。
- **実行環境**: PHP **8.4**（BGA 本番と同じ。【確認】<https://en.doc.boardgamearena.com/Studio>）。CI では `shivammathur/setup-php` で `php-version: '8.4'`、`coverage: none`、`tools: composer` を指定する。`composer.json` に `config.platform.php = "8.4.0"` を書き、依存解決も 8.4 に固定する。
- **ツールの導入**: ツールは `composer.json` の `require-dev` で入れ、`composer.lock` をコミットして固定する（`vendor/` は `.gitignore` 済み）。PHAR を `tools:` で直接入れる方式は手軽だが、バージョンがロックされず Dependabot も効かないため採らない【提案】。
- **PHP の lint は `composer lint` に集約する【提案】**。`composer.json` の `scripts` に以下を定義し、CI も同じコマンドを呼ぶ。

| スクリプト | 内容 |
| --- | --- |
| `lint:syntax` | `parallel-lint src`（P1） |
| `lint:cs` | `phpcs`（P2。引数なしで `phpcs.xml.dist` を読む） |
| `lint:strict` | `declare(strict_types=1);` を持たない `src/**/*.php` があれば失敗する grep（P2） |
| `lint:stan` | `phpstan analyse --memory-limit=1G`（P3。引数なしで `phpstan.neon.dist` を読む） |
| `lint` | 上記 4 つを順に実行する |

**P1: 構文（`php -l`）**

- **ツール【提案】**: **php-parallel-lint**（内部で `php -l` を並列実行し、エラーを見やすく整形する）。素の `find src -name '*.php' -exec php -l {} \;` でも同じ検査ができるが、並列化と出力の整形があるため採用する。構文の解釈は CI の PHP 8.4 バイナリ自身が行うので、8.4 の新構文（プロパティフック等）も正しく扱われる。

**P2: コーディング規約**

- **ツール【提案】**: **PHP_CodeSniffer**（検査専用。`phpcbf` による自動修正はローカルで任意）。
  - 比較: PHP-CS-Fixer（3.95 系）は `--dry-run` で検査にも使えるが、設定が PHP コードで、規約の検査というより整形ツール色が強い。PHPCS は XML のルールセットで「このルールだけ除外」が書きやすい。どちらも BGA 固有のルールは持たない。
- **設定ファイル**: リポジトリ直下 `phpcs.xml.dist`（PHPCS が自動で読む名前。【確認】上記の優先順）。
- **ルール方針【提案】**: ベースは **PSR-12**。調整する点:
  - 行長は警告ではなくエラーとし、上限は 140 文字（BGA の通知文字列は長くなりがち）。絶対上限は設けない。
  - `PSR1.Methods.CamelCapsMethodName` は、`modules/php/Game.php` に限り除外する（BGA の `debug_` で始まるデバッグ用メソッドが Studio のデバッグメニューに出る仕様のため。【確認】仕様書 003 第 7.1 節）。
  - 名前空間の大文字小文字（`Bga\Games\minimumjourney` か `MinimumJourney` か）は PSR-12 では検査されないため、規約側では強制しない（仕様書 003 の U1）。
  - 警告（warning）が出た場合も CI を失敗させる。PHPCS は既定で警告があっても終了コードが非 0 になる（**【未確認】**: 今回は公式ドキュメントで確認していない）ので、`ignore_warnings_on_exit` は設定しない。警告を許す運用はしない。
- **`declare(strict_types=1);` の強制**: PHPCS の標準ルールだけでは強制できるか **【未確認】**（Slevomat Coding Standard に `DeclareStrictTypes` があるが、PHPCS 4.x との互換は未確認）。依存を増やさず確実に行うため、`lint:strict` を grep ベースで実装する。仕様: `src/**/*.php` のすべてのファイルが、`<?php` の直後（コメントを除く最初の文）に `declare(strict_types=1);` を持つこと。厳密な位置判定が必要なら Node スクリプトに移してよい。
- **実行コマンド**: `composer lint:cs`（実体は `vendor/bin/phpcs`）。

**P3: 静的解析**

- **ツール【提案】**: **PHPStan**（2.x）。比較対象の Psalm もあるが、PHPStan は設定の例と情報が多く、`scanFiles` でスタブを足す方法が公式に説明されている（【確認】<https://phpstan.org/user-guide/discovering-symbols>）。
- **設定ファイル**: リポジトリ直下 `phpstan.neon.dist`。方針:
  - `level: 5` から始め、状態遷移・データモデルの実装（T-006 / T-007）が固まった時点で引き上げを検討する。最初から最大レベルにしない。
  - `paths: [src/modules/php]`、`phpVersion: 80400`。
  - クラスの発見は Composer のオートローダーに頼らず `scanDirectories: [src/modules/php]` で行う（名前空間の確定前でも動く。`autoload-dev` に名前空間を書くと U1 が確定するまで書き換えが生じる）。
- **BGA フレームワークのクラスが解決できない問題への対処【提案】**: ゲームコードは `Bga\GameFramework\Table`、`Bga\GameFramework\States\GameState`、属性 `#[PossibleAction]`、グローバル関数 `clienttranslate()` / `totranslate()` などに依存する。これらの定義はリポジトリにも Composer にも無いため、何もしないと「クラスが見つからない」「メソッドが未定義」というエラーで全ファイルが落ちる。

| 案 | 内容 | 評価 |
| --- | --- | --- |
| A. 静的解析を導入しない | `php -l` と PHPCS のみ | 最も簡単だが、型の取り違え・未定義変数・到達不能コードを CI で見つけられない。ローカルに PHP が無いため、実行時エラーに Studio で初めて気づくことになる。 |
| B. エラー ID で無視する | `ignoreErrors` で `class.notFound`、`method.notFound` などを丸ごと除外 | 手間は最小だが、除外が広すぎて検査の意味が薄れる。誤ったメソッド名（タイポ）も見逃す。 |
| C. 手書きの最小スタブ（**推奨**） | 実際に使うフレームワークのクラス・メソッド・関数だけを宣言したスタブを `tools/phpstan/bga-stubs.php` に置き、`scanFiles` で読み込む | 小さく始められ、使うたびに追記するので、タイポは検出できる。スタブはリポジトリ直下の `tools/` に置き、`src/` には入れない（BGA に同期されない）。 |
| D. Studio の `_ide_helper.php` を取り込む | Studio 側が配置する補完用ファイルを `tools/bga/`（2026-10-14 に `tools/phpstan/` から変更。JS の型定義と同居させる）にコピーし `scanFiles` に渡す | 網羅的だが、BGA の著作物の再配布にあたらないか、PHPStan が読める形式か、更新への追随が **【未確認】**（仕様書 003 U9）。 |

**推奨は C から始める**。D は作者が `_ide_helper.php` の取り込みを許可した場合に検討する（未確定事項 No.3）。2026-10-14 に Studio の初期ファイルで `_ide_helper.php`（PHP の補完・静的解析用スタブ）と `bga-framework.d.ts`（JS の型定義）の存在を確認した。付属の `LICENCE_BGA` は開発者のコードの扱いを定めるもので、BGA 配布のスタブの再配布を許諾する文面はないため、リポジトリにはコミットしない（`tools/bga/` は `.gitignore` 済みで、各自がローカルに置く）。CI で使うには、許諾の確認か、CI での Studio からの取得が必要。BGA 公式のスタブ配布は確認できなかった（**【未確認】**、仕様書 003 U9）。

- フレームワークに依存しない純粋なルール計算（探査出力の計算など）は、仕様書 003 第 7.2 節の方針どおり `Game` から独立したクラスに切り出す。この部分はスタブ無しでフルに解析されるため、静的解析の価値が高い。
- 解析の誤検出を一時的に許容する場合は、`phpstan-baseline.neon` に記録して管理する（無制限な `ignoreErrors` の追加は避ける）。
- **実行コマンド**: `composer lint:stan`。

#### 2.5 JavaScript の lint（J1）

- **対象**: `src/modules/js/**/*.js`（`Game.js` ほか）、およびリポジトリの Node スクリプト（`scripts/**/*.mjs`）と設定ファイル（`*.config.mjs`）。
- **ツール【提案】**: **ESLint 10**（flat config）。JS の lint 標準で他に選択肢は実質無い（Biome は Stylelint・JSONC まで一本化できる利点があるが、ESLint ほど `no-undef` とグローバルの扱いが成熟しておらず、BGA 向けの調整実績も確認できない【未確認】）。
- **設定ファイル**: リポジトリ直下 `eslint.config.mjs`（【確認】`eslint.config.js` / `.mjs` / `.cjs` が使え、設定オブジェクトの配列を export する。`package.json` に `"type": "module"` を入れずに済む `.mjs` を採る）。
- **構成【提案】**（概念。実装者が `defineConfig()` で書く）:
  - 全体: `@eslint/js` の `recommended`。ignores に `node_modules/`、`vendor/`、`data/`、`.worktrees/`、`src/img/`（生成物）。
  - `src/modules/js/**/*.js`: `languageOptions.sourceType: "module"`（【確認】`Game.js` は ES モジュール）、`ecmaVersion: "latest"`、`globals` に `globals.browser` と BGA のグローバルを追加する。
  - `scripts/**/*.mjs`、`*.config.mjs`: `globals.node`。
- **BGA のグローバルの扱い【提案】**: `no-undef` を有効にしたまま、`languageOptions.globals` に次を `readonly` で宣言する。

| グローバル | 根拠 |
| --- | --- |
| `_`、`$` | 【確認】`Game.js` ページ。翻訳関数と DOM 取得 |
| `dojo`、`ebg`、`importDojoLibs`、`importEsmLib`、`g_gamethemeurl` | 【確認】同上 |
| `getLibUrl` | 仕様書 003 の申し送りに挙がっているが、公式ページでは今回確認できなかった（**【未確認】**）。使う時点で再確認する |
| `g_gamethemeurl` 以外の `g_*` 系変数 | **【未確認】**。コードで必要になった時点で追加する |

グローバルの定義は 1 か所（`eslint.config.mjs`）に置き、型チェック側の宣言（`types/bga-globals.d.ts`、第 2.6 節）と重複するため、片方を変えたら他方も更新する。

- `dojo` は非推奨で、新規コードでの使用は避ける方針（仕様書 003 第 1.1 節）。lint で使用を禁じたければ `no-restricted-globals` に `dojo` を入れる。ただし既存の BGA 初期生成コードが使う可能性があるため、初期は警告にとどめる**【提案】**。
- ルールは `recommended` に加え、`eqeqeq`、`no-var`、`prefer-const`、`no-shadow`、`no-unused-vars`（`_` 始まりの引数は許可）を有効にする。整形系（インデント・セミコロン）は強制しない。
- **実行コマンド**: `npx eslint .`（`package.json` の `lint:js`）。

#### 2.6 JavaScript の型チェック（J2）

- **対象**: `src/modules/js/**/*.js`（各ファイルの先頭に `// @ts-check` を書く運用。仕様書 003 第 3.2 節で確定）。
- **ツール**: **TypeScript の `tsc --noEmit`**（確定。ビルドはしない）。
- **設定ファイル【提案】**: リポジトリ直下 `tsconfig.json`。`compilerOptions`: `allowJs: true`、`checkJs: true`、`noEmit: true`、`target: "ES2022"`、`module: "ES2022"`、`moduleResolution: "Bundler"`、`lib: ["ES2022", "DOM"]`、`strict: true`、`skipLibCheck: true`。`include`: `["src/modules/js/**/*.js", "types/**/*.d.ts"]`。
  - 仕様書 003 第 3.2 節が引く公式の TypeScript 手順は `strict: false` だが、これは TS ソースをバンドルする場合の既定である。本リポジトリは JS を JSDoc で型付けするので、`strict: true` で始め、`@ts-check` の恩恵を最大にする**【提案】**。詳細な厳しさは実装の進捗に応じて作者が調整する。
- **フレームワーク型の追記（2026-10-14）**: Studio の初期ファイルに `bga-framework.d.ts` が含まれていた。`tools/bga/` にローカルで置けば、`include` に加えて `this.bga` などの型に使える（コミットは第 2.4 節の理由で保留）。下の手書きの `types/` は、このファイルが使えない CI でも通るための代替になる。
- **BGA のグローバルとフレームワーク型【提案】**: `types/bga-globals.d.ts`（リポジトリ直下 `types/`。`src/` には入れない）に `_`、`$`、`g_gamethemeurl` などを `declare` する。`this.bga` の型は公式の型定義が配布されているか **【未確認】**（bga-cards の `.d.ts` が配布されていることは【確認】、仕様書 003 第 3.2 節。それ以外は未確認）。無い場合は最小の `interface` を手書きし、不足分は `any` で受ける。
- **TypeScript のバージョン【提案】**: 2026-10-13 時点の最新は 7.0.2（ネイティブ版）。JS のチェックが 6.x より厳格になっている（`@enum` / `@constructor` 非対応、`Object` を `any` として扱わない等。【確認】<https://typescriptdocs.com/release-notes/TypeScript%207.0> の検索要約）。新規コードなので 7.x を採り、BGA 側のライブラリ型で問題が出る場合は 6.x に下げる判断を実装者に委ねる。ロックファイルで固定する。
- **実行コマンド**: `npx tsc --noEmit -p tsconfig.json`（`lint:types`）。`include` が 1 ファイルにも一致しないと `tsc` は失敗する（TS18003）ため、`Game.js` の雛形が入る段階 2 で有効化する（第 3 節のガード）。

#### 2.7 CSS（C1）

- **対象**: `src/**/*.css`（`src/minimumjourney.css` ほか）。
- **ツール【提案】**: **Stylelint 17** + `stylelint-config-standard`。比較: Biome や lightningcss は lint ルールが少なく、CSS 向けの実績が Stylelint に劣る**【未確認、一般的な見解】**。
- **設定ファイル**: リポジトリ直下 `stylelint.config.mjs`（【確認】）。`extends: ["stylelint-config-standard"]` を基本とする。
- **ルール方針【提案】**:
  - **BGA 由来のクラス名・ID を許容する**: BGA の標準 DOM は `#page-title`、`.bgabutton`、`.player-board` など。`selector-class-pattern` / `selector-id-pattern` は標準の kebab-case 強制のままだと、自前のクラス名を `snake_case` にしたい場合に邪魔になる。自前のクラスは kebab-case に統一する運用とし、`selector-class-pattern` は `null`（無効）にするかどうかを未確定事項 No.7 とする。暫定は無効にしない（標準のまま）。
  - CSS 変数（ダークモード対応の配色。仕様書 003 第 3.3 節）の名前は `custom-property-pattern` の標準（kebab-case）に従う。
  - `src/**/*.css` が 1 つも無い間も失敗しないよう、`--allow-empty-input` を付ける。
- **実行コマンド**: `npx stylelint "src/**/*.css" --allow-empty-input`（`lint:css`）。

#### 2.8 JSONC の構文（D1）

- **対象**: `src/**/*.json`、`src/**/*.jsonc`（`gameinfos.jsonc`、`gameoptions.jsonc`、`gamepreferences.jsonc`、`stats.jsonc`）。拡張子が `.json` か `.jsonc` かは公式ドキュメントで揺れている（仕様書 003 の U6）ため、両方を JSONC として検査する。
- **ツール【提案】**: **ESLint + `@eslint/json`**（ESLint 公式の JSON / JSONC 言語プラグイン）を、J1 と同じ `eslint.config.mjs` に設定する。`language: "json/jsonc"` を `src/**/*.{json,jsonc}` に割り当て、`json/no-duplicate-keys` などの `recommended` ルールを有効にする。
  - 比較: (a) `jsonc-parser` で構文だけを検査する自作スクリプトは依存が軽いが、保守対象が増える。(b) `jq` / `python -m json.tool` はコメントを許さず使えない。(c) ESLint に統合すれば、実行コマンドが `eslint .` に一本化され、重複キー検出も得られる。よって (c) を推奨する。
- **範囲**: **構文の正しさ**（コメントを許した JSON として読めるか、重複キーが無いか）のみを検査する。`gameinfos.jsonc` のキー内容の妥当性（必須キー、値の型）は、BGA 公式の JSON スキーマが確認できていないため対象外（**【未確認】**）。実装時に公式スキーマが見つかれば追加を検討する。
- **末尾カンマ【提案】**: 許可しない（既定）。BGA が末尾カンマを受け付けるかは **【未確認】**なので、安全側に倒す。
- **実行コマンド**: J1 と同じ `npx eslint .`（`lint:js` が JSONC も含む）。個別に見たい場合は `npx eslint "src/**/*.{json,jsonc}"`。

#### 2.9 アセットの上限（A1）

- **対象**: 生成後の `src/img/**`（スプライト生成物と手書きの箱・アイコン画像）。**スプライトは CI で生成する**（コミットしない。仕様書 003 第 5.3 節の確定事項）ため、検査は生成スクリプトの実行後に行う。
- **ツール【提案】**: Node スクリプト `scripts/lint/check-assets.mjs`（寸法の取得に `image-size` を使う。純 JS で WebP / PNG / JPEG に対応し、`sharp` のようなネイティブ依存を持たない）。生成スクリプト自体は T-009 が `scripts/` に定義する（本書の対象外）。
- **検査内容と上限【確認】【提案】**: 上限の出典は仕様書 003 第 5.1 節（BGA 公式 [Img](https://en.doc.boardgamearena.com/Game_art:_img_directory) と [Checklist](https://en.doc.boardgamearena.com/Pre-release_checklist)）。

| 検査 | 失敗条件 | 備考 |
| --- | --- | --- |
| 1 ファイルのサイズ | 2,000,000 バイト超 | 公式は「2 MB」（Img）と「4 MB」（Checklist）で食い違う（仕様書 003 U7）。厳しい方の 2 MB を、MB の定義の曖昧さを避けて 10 進（2,000,000 バイト）で採る |
| `src/img/` の総量 | 15,000,000 バイト超 | 公式は「15 MB 以内」（Checklist）。同様に 10 進で採る |
| 画像の寸法 | 幅または高さが 4096 px 超 | スプライトシートの上限（Img、Android 互換） |
| ファイル名 | `[A-Za-z0-9_.-]` 以外を含む | 仕様書 003 第 5.1 節（空白・括弧禁止）と第 5.3 節（英数字とアンダースコア） |
| 形式 | `.webp` / `.png` / `.jpg` / `.jpeg` 以外 | 例外が必要になったら許可リストに足す |
| ファイル数 | `src/img/` 直下が 20 超で**警告** | 公式は「十数枚以内」を推奨（Img）。警告のみで失敗にはしない |

- **実行コマンド**: `node scripts/lint/check-assets.mjs`（`npm run lint:assets`）。`src/img/` が存在しない間は成功とする。生成を伴うため `npm run lint` には含めず、`npm run lint:assets` を単独で実行する（生成は `npm run build:sprites`。スクリプト名は T-009 が確定する）。

### 3. 段階導入の計画

今は `src/` が無く、実装コードは 1 行も無い。ツールの多くは「対象ファイルが 0 件」だと失敗する（`tsc` は TS18003、ESLint は対象 0 件のパターンエラー、`php-parallel-lint` は存在しないディレクトリでエラー）。そこで、**段階ごとに別の PR でジョブを追加し**、さらに**段階内でも対象が揃うまではステップをスキップする**二重の安全策を取る【提案】。

| 段階 | 追加するチェック | 前提（この時点で存在するもの） | 追加するジョブ |
| --- | --- | --- | --- |
| 第 1 段階 | M1 Markdown、M2 内部リンク、S1 認証情報、S2 禁止ファイル | `docs/` 等の Markdown のみ。`src/` は無くてよい | `markdown`、`secrets`、`gate` |
| 第 2 段階 | P1〜P3 PHP、J1 ESLint、J2 型、C1 CSS、D1 JSONC | `src/` の雛形（`Game.php`、`Game.js`、`minimumjourney.css`、`gameinfos.jsonc` など）がマージ済み | `php`、`node` |
| 第 3 段階 | A1 アセット上限 | スプライト生成スクリプト（T-009）がマージ済み、`src/img/` の手書き画像が入った | `assets` |

- **各段階は別 PR で実装する【提案】**: 第 1 段階は本仕様書の承認後すぐに実装する（検査対象の Markdown が既にあり、実ファイルで動作確認できる）。第 2 段階は `src/` の雛形が `main` に入った後、その雛形に対して設定が通ることを確かめながら実装する（雛形の PR に同梱してもよい）。第 3 段階は T-009 の生成スクリプトが入った後に実装する。実ファイルの無い設定を先に書くと、検証できないまま誤った設定が残るため、この順にする。
- **`gate` ジョブは第 1 段階で作り、以降は不変にする【提案】**: ブランチ保護の必須チェックは `gate` の 1 つだけにする（第 4.3 節）。段階が進んでジョブが増えても、保護設定を作者が触り直す必要が無い。
- **段階内のガード【提案】**: 第 2・3 段階のジョブは、対象が揃っていない間もステップを成功扱いでスキップする。具体的には、各検査ステップに `if: ${{ hashFiles('src/**/*.php') != '' }}` のような条件を付ける（PHP は `src/**/*.php`、JS・型は `src/modules/js/**/*.js`、CSS は `src/**/*.css`、アセットは `src/img/**`）。`hashFiles` は対象が無いと空文字列を返し、条件が偽になる。**ジョブ全体を `if` でスキップせず、ステップで制御する**のは、スキップされたジョブが必須チェックの扱いでどう見えるかを避けるためと、`hashFiles` がジョブレベルの `if` では使えない**【未確認】**ためである。ローカルのコマンドは、`--allow-empty-input`（Stylelint）、ESLint が常に設定ファイル自身を対象に持つこと、で失敗しない。`tsc` のみ、`include` 一致が 0 件だと失敗するため、第 2 段階で `Game.js` の雛形が入ってから `lint:types` を追加する。
- **移行時の見直し**: `src/` の雛形は Studio の初期生成物として取り込み済み（2026-10-14）。非推奨ファイル（`states.inc.php` など）は含まれていなかったので整理は不要。ただし雛形の `Game.php` 等は Studio のサンプルコードなので、PHPCS・PHPStan が大量にエラーを出す可能性がある。その場合は、規則側を調整するか、雛形を整理する PR を先に入れる。

#### 3.1 第 2 段階の実装記録（2026-10-15）

第 2 段階を `ci/lint-stage2` で実装した。仕様書の本文からの差分と判断を記録する（本文は変更していない）。

- **スタブの扱い（第 2.4・2.6 節の案 C・D の代わり）**: 手書きスタブ（案 C）は採らず、PHPStan と `tsc` は **CI が Studio から SFTP でスタブ（`_ide_helper.php`、`bga-framework.d.ts`）を取得して実行**する。スタブはコミットせず、取得はジョブ内で完結する（成果物としてアップロードしない）。取得は専用ジョブ `static`（表示名 `Static analysis (BGA stubs)`）で行い、`php` と `node` のジョブは Secrets を使わない。接続方法は `scripts/bga-sync/sync.sh` と同じで、Secrets / Variables も 005 第 6 節と共通（`scripts/lint/fetch-bga-stubs.sh`）。Secrets / Variables が無いとき（フォークや Dependabot の PR、Secrets を渡さない `workflow_call` 経由）は警告つきでスキップして成功する。このとき PHPStan と `tsc` は実行されない。
- **`types/bga-globals.d.ts` は作らない**: `bga-framework.d.ts` が同じグローバルを宣言するため、併置すると二重宣言になる。スタブ無しでは `tsc` を実行しない（`npm run lint:types` はスタブが無ければ警告してスキップ。`--require` を付けると失敗にする。CI の `static` は `--require`）。
- **`lint:strict` は Node スクリプト**（`scripts/lint/check-strict-types.mjs`）にして `node` ジョブで実行する（Composer の `lint` には含めない）。PHP が無いローカルでも実行できるため。
- **`composer lint` は構文（`lint:syntax`）と規約（`lint:cs`）のみ**。`lint:stan` は別スクリプトで、スタブが必要なため `static` ジョブで実行する。
- **雛形の扱い**: 書式の機械的な修正（`phpcbf` の整形、メソッドへの `public` の明示、`Game.php` の CRLF から LF への変換、`Game.js` の未使用引数への `_` 接頭辞）のみ行い、動作は変えていない。中身の書き換えが要るものは次のとおり一時的に除外した。雛形の置き換え（実装タスク）で外すこと。
  - `tsconfig.json` の `exclude` に `src/modules/js/Game.js`（JSDoc が無く、`strict` で 12 件の暗黙 any と 1 件の null 参照が出る）
  - `phpstan-baseline.neon`（`Game.php` の `$query_values` 未定義、`NextPlayer.php` の常に偽の条件）
- ESLint は Node 23.11.0 のローカルでも動作した（警告なし）。CI の Node は `.nvmrc` の 24。

### 4. ワークフロー構成

#### 4.1 ファイルとトリガー

- **ファイル**: `.github/workflows/lint.yml`（ワークフロー名 `Lint`）。
- **トリガー【提案】**:

```yaml
on:
  pull_request:
    branches: [main]
  push:
    branches: [main]
  workflow_dispatch:
  workflow_call:
```

- `workflow_call` は、BGA Studio への同期ワークフロー（[005-bga-sync.md](005-bga-sync.md)、`bga-sync.yml`）が lint を再利用ワークフローとして呼び出し、`needs` で成功を前提にするために用意する（005 §5.2）。呼び出し側に渡す入力・シークレットは無い。

- `pull_request_target` は使わない（PR の差分を信頼しないコンテキストで実行する用途が無く、権限昇格のリスクだけが増える。【確認】actions/checkout v7 は、`pull_request_target` / `workflow_run` でフォーク PR のコードを既定で取得しない旨を README に記す）。
- マージキュー（`merge_group`）は、単独開発で PR が同時に複数並ばないため当面使わない。導入する場合は `merge_group` トリガーを足す。

#### 4.2 ジョブ構成

並列に動く独立ジョブ + 結果を集約する `gate`。

| ジョブ ID | 表示名（`name:`） | 段階 | 内容 | 実行環境 |
| --- | --- | --- | --- | --- |
| `markdown` | `Markdown` | 1 | checkout → setup-node → `npm ci` → `npm run lint:md` → lychee | `ubuntu-latest` |
| `secrets` | `Secrets` | 1 | checkout（`fetch-depth: 0`）→ gitleaks → setup-node → `npm run lint:repo`（`npm ci` は不要） | `ubuntu-latest` |
| `php` | `PHP` | 2 | checkout → setup-php（8.4）→ Composer キャッシュ → `composer install` → `composer lint` | `ubuntu-latest` |
| `node` | `JS / CSS / JSONC` | 2 | checkout → setup-node → `npm ci` → `npm run lint:js` → `npm run lint:types` → `npm run lint:css`（各ステップは `if: ${{ !cancelled() }}` を付け、先行ステップが失敗しても後続を実行して、1 回の実行で全エラーを見えるようにする） | `ubuntu-latest` |
| `assets` | `Assets` | 3 | checkout → setup-node → `npm ci` → `npm run build:sprites` → `npm run lint:assets` | `ubuntu-latest` |
| `gate` | `Lint gate` | 1 | 上記すべてを `needs` に持ち、すべて `success` でなければ失敗する | `ubuntu-latest` |

- `markdown` と `node` は両方 `npm ci` を行う。ジョブを分けるとキャッシュ復元が重複するが、並列実行でエラーの切り分けが速く、`npm ci` は小さな依存セットでキャッシュ済みなら数秒のため、分割を優先する**【提案】**。
- `gate` の挙動（`needs` が複数ある `if: always()` のジョブ）:

```yaml
gate:
  name: Lint gate
  if: ${{ always() }}
  needs: [markdown, secrets]   # 段階が進むたびに php, node, assets を追加する
  runs-on: ubuntu-latest
  steps:
    - name: Require all lint jobs to succeed
      env:
        RESULTS: ${{ toJSON(needs.*.result) }}
      run: |
        echo "$RESULTS"
        echo "$RESULTS" | grep -qE '"(failure|cancelled|skipped)"' && exit 1 || exit 0
```

  `always()` を付けないと、先行ジョブが失敗したとき `gate` は「スキップ」になり、第 4.3 節のとおり必須チェックの扱いが不安定になる。`skipped` も失敗扱いにするのは、どのジョブも意図してスキップしない設計だからである。

#### 4.3 paths フィルタと必須チェックの相性

- **【確認】** GitHub のドキュメントによれば、ワークフローが `paths` / `branches` フィルタでスキップされると、関連する必須チェックは「Pending」のままになりマージをブロックする。一方、ジョブが `if` 条件でスキップされた場合は「Success」として報告される。<https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/collaborating-on-repositories-with-code-quality-features/troubleshooting-required-status-checks>
- **【提案】** したがって `lint.yml` には**ワークフローレベルの `paths` フィルタを付けない**。ドキュメントだけの PR でも常に全ジョブが走る（Markdown の PR で PHP ジョブが走っても、ガードにより数秒で終わる）。PR の多くが 1 人の作者による小さな変更で、実行時間も短いため、フィルタで節約するメリットより、必須チェックが Pending で詰まるリスクの回避を優先する。
- **【提案】** 必須チェックには `gate`（表示名 `Lint gate`）のみを指定する。個々のジョブ名を必須にすると、ジョブの追加・改名のたびに保護設定の更新が必要になる。
- 将来、実行時間が問題になったら、`paths` フィルタを使う代わりに「変更ファイルを検出して各ステップを制御する」方式（`dorny/paths-filter` 等）に移行できるが、現時点では導入しない。

#### 4.4 キャッシュと concurrency

- **npm キャッシュ**: `actions/setup-node` の `cache: npm` を使う（`package-lock.json` のハッシュがキー）。【確認】setup-node v7 は `package.json` の `packageManager` フィールドでも自動有効化されるが、明示的に `cache: npm` を指定して意図を明確にする。権限の少ない本ワークフローではキャッシュの無効化は不要（【確認】README は、昇格した権限や機密情報を扱うワークフローでは自動キャッシュの無効化を勧めている）。
- **Composer キャッシュ**: `composer config cache-files-dir` で得たディレクトリを `actions/cache` で保存し、キーは `composer.lock` のハッシュ。【確認】setup-php の README は **`vendor/` をキャッシュしない**よう注意している。
- **gitleaks・lychee**: アクション内でバイナリを取得する。キャッシュは設定しない（遅ければ後で検討）。
- **concurrency【提案】**:

```yaml
concurrency:
  group: lint-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}
```

  同じ PR に続けて push したとき、古い実行を取り消して最新のコミットだけを検査する。`main` への push の実行は取り消さない（squash merge の直後に別のマージが続いても、各コミットの結果を残す）。【確認】`concurrency` の `group` / `cancel-in-progress` の構文と、条件付き取り消しの例は <https://docs.github.com/en/actions/using-jobs/using-concurrency>。

#### 4.5 権限・バージョン固定・実行時間

- **permissions の最小化【提案】**: ワークフローのトップレベルに `permissions: { contents: read }` を置き、ジョブごとの追加は原則しない。gitleaks は PR コメントを無効化する（`GITLEAKS_ENABLE_COMMENTS: false`）ので `pull-requests: write` は不要。【確認】GitHub は、`GITHUB_TOKEN` をコンテンツ読み取りの最小権限にし、必要なジョブだけ昇格させるよう勧めている（<https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions>）。リポジトリ設定の既定の Workflow permissions も Read に揃える（第 7 節）。
- **アクションのバージョン固定【提案】**: すべてのサードパーティ・公式アクションを**フルレングスのコミット SHA で固定**し、行末コメントに対応するバージョンを書く（例: `uses: actions/checkout@<40 桁の SHA> # v7.x.y`）。【確認】GitHub は、タグは付け替えられうるため、SHA 固定を最も強い対策として勧めている（上記ドキュメント）。SHA は実装時に各アクションの公式リポジトリ（フォークでないもの）の該当リリースから取得すること。本書は実在確認のできない SHA を記載しない。更新は Dependabot（`.github/dependabot.yml` の `github-actions` / `npm` / `composer` エコシステム、週次、グループ化）に任せる**【提案】**。Dependabot が SHA 固定のアクションの更新に追随できることは **【未確認】**（一般には対応しているが、公式ドキュメントでは今回確認していない）。
- **ツールのバージョン方針【提案】**: Node ツールは `package.json` に `^`（メジャー固定）で書き、`package-lock.json` をコミットして CI は `npm ci` で再現する。PHP ツールも同様に `composer.json`＋`composer.lock`。PHP 本体は `8.4`（マイナー固定、パッチは最新）。Node は `.nvmrc` に `24`（偶数メジャー）を書き、`setup-node` の `node-version-file: .nvmrc` で参照する。
  - 【確認】ESLint 10 の対応 Node は `^20.19.0 || ^22.13.0 || >=24`、Stylelint 17 は `>=20.19.0`、markdownlint-cli2 0.23 は `>=22`。このため **Node 22.13 以上の LTS（偶数メジャー）**が前提になる。本書の作成時にこの環境で確認した Node は 23.11.0 で、ESLint 10 の対応範囲外（奇数メジャーの 23 は含まれない）。作者のローカル Node を 22 または 24 にそろえる必要がある（未確定事項 No.2）。`package.json` の `engines.node` は `^22.13.0 || >=24` にする。
  - Node 24 が現行 LTS であること自体は、本書の作成時に公式で確認していない（**【未確認】**。実装時に <https://nodejs.org/en/about/previous-releases> で確認する）。
- **タイムアウト**: 各ジョブに `timeout-minutes: 10` を付ける（ハングによる分数の浪費を防ぐ）。
- **実行時間の目安【提案】（未計測の見積もり）**: `markdown` 約 30〜60 秒（lychee のバイナリ取得を含む）、`secrets` 約 20〜40 秒、`node` 約 40〜90 秒、`php` 約 60〜120 秒（setup-php と `composer install`）、`assets` はスプライト生成の重さに依存し 1〜3 分程度。並列実行のため全体は最も遅いジョブで決まり、**第 2 段階完了時で 2 分前後**を目標にする。実測は導入後に記録し、5 分を超えるようならキャッシュ・分割を見直す。

### 5. ローカル実行

#### 5.1 Node 系（`npm run lint` で一括）

**【提案】** `package.json` の `scripts` 案（段階が進むごとに `lint` の連結が増える）。

```jsonc
{
  "private": true,
  "engines": { "node": "^22.13.0 || >=24" },
  "scripts": {
    "lint": "npm run lint:md && npm run lint:repo && npm run lint:js && npm run lint:types && npm run lint:css",
    "lint:md": "markdownlint-cli2",
    "lint:repo": "node scripts/lint/check-repo.mjs",
    "lint:js": "eslint .",
    "lint:types": "tsc --noEmit -p tsconfig.json",
    "lint:css": "stylelint \"src/**/*.css\" --allow-empty-input",
    "lint:links": "lychee --config lychee.toml \"./**/*.md\"",
    "lint:assets": "node scripts/lint/check-assets.mjs"
  }
}
```

- 第 1 段階では `lint` は `lint:md` と `lint:repo` のみ。`lint:js` / `lint:types` / `lint:css` は第 2 段階で足す。
- `lint:links`（lychee）と、gitleaks（`gitleaks git --redact`）は Node 以外のバイナリが必要なため `npm run lint` に含めない。CI では必須だが、ローカルでは任意（`brew install lychee gitleaks`）。
- `lint:assets` はスプライト生成（T-009 の `build:sprites`）が前提のため単独で実行する。
- `npm run lint` は `&&` 連結のため最初の失敗で止まる。全エラーを一度に見たい場合は各 `lint:*` を個別に実行する。依存を増やさないためにこの形にした（`npm-run-all2` 9 系は Node `^22.22.2 || ^24.15.0 || >=26` を要求し、対応範囲が狭いため採らない。npm レジストリで確認）。
- 自動修正は `lint:md -- --fix`、`eslint . --fix`、`stylelint --fix` をローカルでのみ使う（CI では使わない）。

#### 5.2 PHP（ローカルは任意）

開発者のローカルには PHP が無く（前提）、PHP の検査は CI に任せる。手元で確認したい場合の任意の手段:

| 手段 | コマンドの例 | 備考 |
| --- | --- | --- |
| Homebrew | `brew install php@8.4 composer` → `composer install` → `composer lint` | macOS のみ。PHP の他バージョンと共存するので `PATH` の順序に注意 |
| Docker | `docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c '…'` | `php:8.4-cli` に Composer を足す手順が必要（公式イメージ `composer` が 8.4 を含むかは **【未確認】**）。Docker を使う運用を許すかは仕様書 003 の未確定事項 B No.13 と共通 |
| 使わない | PR を出して CI の結果を見る | 既定の運用。フィードバックに数分かかる |

- 既定は「使わない」とし、`composer.json` の `scripts` が CI とローカル（導入した場合）で同一になっていれば足りる。

#### 5.3 pre-commit フック

- **【提案】導入しない。** 理由: (1) 本リポジトリの作業は Claude Code（サブエージェント）がワークツリー上で行い、コミット前に `npm run lint` を実行する運用で足りる、(2) フックの導入（husky 等）はツールと設定を増やし、ワークツリーごとに `core.hooksPath` の挙動を確認する必要が出る、(3) 最終的な防波堤は CI の必須チェックであり、フックはバイパスできる。
- 代わりに `docs/development/git-workflow.md` と `task-workflow.md` の「PR 作成前に `npm run lint` を実行する」記述を、実装タスクで追記する（本書では編集しない）。
- 見直し条件: ローカルで lint 失敗の PR が繰り返し発生する場合（未確定事項 No.8）。

### 6. リポジトリに追加されるファイル

#### 6.1 一覧【提案】

| パス | 段階 | 内容 |
| --- | --- | --- |
| `.github/workflows/lint.yml` | 1 | ワークフロー本体 |
| `.github/dependabot.yml` | 1 | `github-actions` / `npm` / `composer` の週次更新 |
| `package.json`、`package-lock.json` | 1 | Node の devDependencies と `scripts`。`package-lock.json` はコミットする |
| `.nvmrc` | 1 | Node のメジャーバージョン（`24`） |
| `.markdownlint-cli2.jsonc` | 1 | M1 の設定 |
| `lychee.toml` | 1 | M2 の設定 |
| `.gitleaks.toml` | 1 | S1 の設定 |
| `scripts/lint/check-repo.mjs` | 1 | S2。`src/` 衛生を含む |
| `eslint.config.mjs` | 2 | J1・D1 の設定 |
| `tsconfig.json`、`types/bga-globals.d.ts` | 2 | J2 の設定とグローバル宣言 |
| `stylelint.config.mjs` | 2 | C1 の設定 |
| `composer.json`、`composer.lock` | 2 | PHP の devDependencies と `scripts`。**必要**（上記の理由で PHAR 直指定にしない） |
| `phpcs.xml.dist` | 2 | P2 の設定 |
| `phpstan.neon.dist`、`tools/phpstan/bga-stubs.php` | 2 | P3 の設定とフレームワークのスタブ |
| `scripts/lint/check-assets.mjs` | 3 | A1。`image-size` を使う |
| `.gitignore` の追記 | 3 | `src/img/cards_*`（スプライト生成物）。T-009 が行う可能性があるので重複に注意 |

- `.editorconfig` は本書では追加しない（lint の対象でなく、必要性は実装段階で作者が判断する）。
- ESLint・Stylelint・markdownlint の設定は、`package.json` 内ではなく専用ファイルに置く（JSONC のコメントで根拠を残せるため）。

#### 6.2 `src/` に開発用ファイルを置かない原則

- **`src/` は BGA の `/minimumjourney/` と 1 対 1 に対応し、そのまま Studio へ同期される**（仕様書 003 第 2 節）。したがって、lint・型・テスト・ビルドのための設定ファイルや生成物は**すべてリポジトリ直下（または `scripts/`、`tools/`、`types/`、`tests/`）に置き、`src/` には入れない**。
- 具体的に `src/` に置かないもの: `package.json`、`node_modules/`、`composer.json`、`vendor/`、`tsconfig.json`、各ツールの設定、スタブ、テストコード、`*.d.ts`。
- 上記は S2（`check-repo.mjs`）が機械的に検査する（第 2.3 節）。例外が必要になったら、スクリプトの許可リストに明示して追加する。
- 逆に、`src/` 内のファイルを検査するための「検査対象の指定」は設定ファイル側（リポジトリ直下）に書く。

### 7. GitHub 側の設定手順（作者が行う作業）

**リポジトリオーナー（作者）**が行う。実装者（エージェント）は行えない。順序に注意する。

1. **ワークフローを一度走らせる**: 第 1 段階の実装 PR を出し、`Lint gate` を含むチェックが 1 回以上実行されてから、ステータスチェックの設定に進む（未実行のチェック名は、設定画面の検索候補に出ない場合がある。公式ドキュメントでは今回確認していない **【未確認】**）。
2. **Actions の既定権限**: Settings → Actions → General → Workflow permissions を **Read repository contents and packages permissions** にする。
3. **`main` の保護**: Settings → Branches（または Rulesets）で `main` を保護する（[../development/git-workflow.md](../development/git-workflow.md) の推奨設定）。
   - Require a pull request before merging: 有効
   - Require status checks to pass before merging: 有効。**必須にするチェックは `Lint gate` のみ**（個別のジョブ名は選ばない。第 4.3 節）
   - Require branches to be up to date before merging: 任意（単独開発のため無効を推奨。有効にすると、`main` が進むたびに PR の更新が要る）
   - Do not allow bypassing the above settings: 作者が管理者として緊急時にバイパスできるようにするかを決める（未確定事項 No.4）
   - Block force pushes: 有効
4. **マージ方法**: Squash merging のみ許可、Automatically delete head branches を有効化（git-workflow.md の推奨設定と同じ）。
5. **gitleaks のライセンス**（Organization 所有の場合のみ）: gitleaks.io で無料キーを取得し、Settings → Secrets and variables → Actions に `GITLEAKS_LICENSE` を登録する。個人アカウントの場合は不要。
6. **リポジトリの公開範囲の確認**: 非公開リポジトリでは、ブランチ保護や Actions の無料枠に制約がある場合がある**【未確認】**。公開範囲を作者が確認し、必須チェックが設定できない場合は未確定事項 No.5 に回答する。
7. **Dependabot**: Settings → Code security で Dependabot version updates を有効化する（`dependabot.yml` の設置だけで動くかは公式で未確認）。

### 8. 既存ドキュメントが提案ルールで通るかの見込み

提案した `.markdownlint-cli2.jsonc`（MD013・MD034・MD036・MD052・MD060 を無効、MD033 は `br` のみ許可、MD024 は `siblings_only`）で、`markdownlint-cli2` 0.23.3 を使い、スクラッチ領域にコピーした現在の Markdown に対して実行した（リポジトリへの変更は無し）。結果:

| 対象 | 結果 |
| --- | --- |
| `main` の既存 Markdown（`README.md`、`CLAUDE.md`、`docs/` の 7 ファイル） | **0 件**。ルール方針はそのまま通る |
| 同じ設定で既定ルールだけを使った場合 | MD013（行長 80 文字）のみ 15 件以上が検出される。MD013 を無効にする根拠 |
| 他ブランチの仕様書（001・002・003・005・010・011 の各ドラフト、いずれも未マージ） | 計 9 件（下表） |
| 仕様書 003 を MD034・MD036 無効にせず検査した場合 | MD034 が 53 件、MD036 が 2 件 |

| ファイル | ルール | 内容 | 対処案 |
| --- | --- | --- | --- |
| `002-card-data.md` 1266 行付近 | MD060 | 表の列スタイル | 提案どおり MD060 を無効にするため、指摘されない |
| `002-card-data.md` 676・690・704・822・890・928・951 行付近 | MD052 | `[探査力][名声]` のように用語を角括弧で連ねた書き方が参照リンクと解釈される | 提案どおり MD052 を無効にするため、指摘されない。有効にするなら全角括弧 `［］` か `` `探査力` `` に直す |
| `003-bga-architecture.md` 250 行付近 | MD033 | 山括弧の `<game>` が HTML 要素と解釈される | バッククォートで囲む（`` `<game>` ``）。MD033 の許可要素を増やす対応はしない |

- 見込み: MD033 の 1 件だけは、各仕様書の PR（または第 1 段階の実装 PR）で修正が必要。ほかは設定で通る。**ただし、仕様書 001・002・003・005・010・011 は他の PR で変更されうるため、第 1 段階の実装時点の `main` で実際に実行して確認すること**。
- 今後の書き手が引っかかりやすい書き方: (1) 言語指定のないコードブロック（MD040。出力例は `text` を付ける）、(2) 山括弧の裸の記述 `<name>`（MD033。コードスパンで囲む）、(3) 見出しレベルの飛び（`##` の次に `####`、MD001）、(4) 末尾改行の欠落（MD047）、(5) コードブロック・表・リストの前後の空行の欠落（MD031/MD032/MD058）。
- リンク切れ（M2）: 現在の `README.md`・`docs/` の内部リンクは、実在するファイルへの相対リンクのみで、問題は見当たらない（lychee 自体は実行していない。**【未確認】**）。`docs/tasks.md` などが `specs/001-game-rules.md` のようにまだ存在しないファイルへのリンクを持つ場合は、各仕様書がマージされるまで M2 が失敗する。リンク先が未マージ仕様書を指すときの扱いは、実装時に `main` で確認する。

### 9. 後続への申し送り

- **T-004 の実装（本書の承認後）**: 段階ごとに別 PR（第 3 節）。第 1 段階を最初に実装し、`Lint gate` が緑になることを実ファイルで確認してから、作者に必須チェックの設定（第 7 節）を依頼する。SHA 固定のため、各アクションの実際のコミット SHA を公式リポジトリから取得する。バージョンは第 2 節冒頭の表を実装時点で更新する。
- **T-005（Studio 自動同期）**:
  - 同期ワークフローも、**スプライト生成を CI で行う**。lint の `assets` ジョブ（第 3 段階）と同期ワークフローで生成が二重に走る。生成物を artifact 経由で共有するか、別々に生成するかは T-005 / T-009 が決める。本書は「検査が生成後の `src/img/` に対して行われること」だけを要求する。
  - `main` への push でも `lint` を実行するが、同期ワークフローは lint の成功を待たない構成も、`workflow_run` で待つ構成もありうる。マージ前に PR で lint が必須になっているため、同期側で lint を再実行する必要は薄い。
  - 同期は `src/` 配下のみ。第 2.3 節の S2 により、`src/` に開発用ファイルは混入しない。
  - 認証情報（SSH 鍵）の混入は S1 が防ぐ。Secrets の名前と登録手順は 005。
- **T-009（UI・アセット）**:
  - スプライト生成スクリプトの名前と、`package.json` の `build:sprites` の定義、出力先 `src/img/cards_*`、`.gitignore` への追記を定義する。`sharp` などネイティブ依存を使う場合は、`npm ci` で Linux ランナー向けのバイナリが入ることを確認する（**【未確認】**）。
  - A1 の上限（1 枚 2,000,000 バイト、総量 15,000,000 バイト、4096 px）は、生成物の設計の制約になる。縮小後の解像度・シート分割は、この上限を満たすように決める。
  - 生成の決定性（同じ入力から同じバイト列）は、キャッシュキーの設計に影響する。
- **T-006 / T-007（状態遷移・データモデル）**: PHP は PSR-12＋`strict_types` 強制。静的解析のスタブ（`tools/phpstan/bga-stubs.php`）は、使うフレームワーク API が増えるたびに実装者が追記する。名前空間の大文字小文字（仕様書 003 の U1）が決まったら、`phpcs.xml.dist` と `phpstan.neon.dist` の設定に影響がないか確認する。
- **仕様書 003 への影響**: 003 の第 8 節 T-004 の申し送りのうち、(a) PHP 8.4、(b) `strict_types` の検査、(c) スタブの扱い、(d) ESLint のグローバル、(e) JSONC、(f) アセット上限、(g) 秘密鍵ヘッダ検出、はすべて本書で扱った。(c) の公式スタブは未確認のまま、手書きスタブで進める。
- **ドキュメント更新（実装タスクで行う）**: `docs/development/cicd.md` の「未導入」記述、`docs/README.md` の索引、`CLAUDE.md` の「CI/CD」節、`docs/development/git-workflow.md` の「PR 作成前に `npm run lint`」。本書では編集しない。

## エッジケース

- **対象ファイルが 0 件**: 第 3 節のガードで成功扱い。Stylelint は `--allow-empty-input`、`tsc` は `lint:types` を第 2 段階まで追加しない。
- **ドキュメントだけの PR**: `paths` フィルタを使わないため、全ジョブが走る（第 4.3 節）。第 2・3 段階のジョブは、ガードでステップをスキップして素早く終わる。
- **フォークからの PR**: 想定しない（個人プロジェクト）。`pull_request` なので Secrets は渡らず、本ワークフローは Secrets を必要としない（Organization 所有の gitleaks ライセンスを除く。その場合、フォーク PR では gitleaks が実行できない）。
- **`.worktrees/` 内の複製が検査対象になる**: ローカルでの `npm run lint` が、`.worktrees/` 配下にある他のタスクのコピーまで検査してしまう。全ツールの除外に `.worktrees/` を入れる（第 1 節）。CI のチェックアウトには `.worktrees/` は無い。
- **スプライトを生成する前の `lint:assets`**: `src/img/` に手書き画像しか無い状態で実行すると、スプライトの上限は検査されない。生成後に実行すること。
- **gitleaks の誤検出**: `.gitleaks.toml` の許可リストで個別に除外する。除外理由をコメントに残す。広い除外（ディレクトリ全体）は避ける。
- **ガードの抜け**: `hashFiles` の対象パターンと、実際に検査するパターンがずれると、「対象はあるのにスキップされる」状態になる。ガードのパターンは、検査コマンドの対象と同一の glob を使う。
- **Node のバージョン不一致**: ローカルが 23.x の場合、ESLint 10 が起動しない・警告を出す可能性がある（`engines` 違反）。`.nvmrc` に従う。
- **Stylelint・ESLint の新メジャー**: 破壊的変更で CI が突然壊れるのを避けるため、Dependabot のメジャー更新は PR で個別に検証する（`^` で自動に上がらないよう、メジャー更新は PR 経由のみ）。

## 未確定事項

### A. 確認できなかった事項

| No. | 事項 | 状況 |
| --- | --- | --- |
| U1 | `src/` が空の間の ESLint の挙動 | `eslint .` は設定ファイル自身を対象に持つため失敗しない見込みだが、実機で未確認 |
| U2 | `hashFiles` がジョブレベルの `if` で使えないこと | 一般に知られた制約だが、今回の調査では公式ドキュメントで確認していない。ステップレベルのガードなら問題ない |
| U3 | `@eslint/json` の `jsonc` 言語が BGA の `.jsonc` を受け付けること、末尾カンマを BGA が許すか | BGA の JSONC の細則は公式ドキュメントに見当たらない |
| U4 | BGA フレームワークの公式スタブ（PHP）と、`this.bga` の公式型定義（JS） | 仕様書 003 の U9 と同じ。bga-cards の `.d.ts` のみ確認済み |
| U5 | `getLibUrl` ほか `Game.js` のグローバルの完全な一覧 | `Game.js` ページで確認できた範囲は第 2.5 節の表のとおり |
| U6 | lychee の `exclude_path` と「リンク先としての `data/` 配下」の関係、日本語見出しのフラグメント検査 | 実装時に実機で確認 |
| U7 | `declare(strict_types=1);` を PHPCS 標準ルールだけで強制できるか | 見つけられなかったため grep で代替 |
| U8 | Node 24 が現行 LTS であること | 実装時に公式で確認 |
| U9 | 非公開リポジトリでの必須ステータスチェック・Actions の制約 | 公開範囲を作者が確認 |
| U10 | Dependabot が SHA 固定アクションの更新に追随すること | 実装時に公式で確認 |
| U11 | `sharp`（T-009）が GitHub のランナーで動くこと | T-009 で確認 |

### B. 作者への質問

| No. | 質問 | 暫定案 | 回答 |
| --- | --- | --- | --- |
| 1 | 提案した段階導入（第 1 段階を承認後すぐ、第 2 段階は `src/` 雛形の後、第 3 段階はスプライト生成の後）でよいか。第 2 段階の実装を `src/` 雛形の PR に同梱してよいか | 段階ごとに別 PR。第 2 段階は雛形の直後の別 PR | |
| 2 | ローカルの Node を 22.13 以上の LTS（22 または 24）に揃えられるか（現状は 23.11.0 で ESLint 10 の対応範囲外）。CI の Node は 24 でよいか | CI は 24。ローカルも 24 に揃える（`.nvmrc` で `nvm use`） | |
| 3 | PHP の静的解析で、Studio の `_ide_helper.php` を取得して `tools/phpstan/` に置いてよいか（BGA の著作物の再配布にあたらないか）。許可しない場合は手書きの最小スタブで進める | 当面は手書きスタブ。`_ide_helper.php` はコミットしない | |
| 4 | `main` の保護で、管理者（作者）のバイパスを許可するか | 許可しない（lint の失敗はマージ不可、緊急時は設定を一時的に外す） | |
| 5 | リポジトリの所有者は個人アカウントか Organization か。公開か非公開か | 個人アカウントと推測（gitleaks のライセンス不要）。公開範囲は不明 | 一部確認（2026-10-13）。リポジトリは **非公開**（未認証の API 照会が 404 を返すことで確認）で、個人アカウント `ZoneTsuyoshi` の所有。非公開のまま維持する方針をメインエージェントが推奨。GitHub のプラン（Free / Pro）は未回答。Free の場合、非公開リポジトリでは `main` の保護（必須チェック）を強制できないため、第 7 節の 3 は運用ルール（CLAUDE.md）で代替する |
| 6 | 外部 URL のリンク切れを、必須ではないスケジュール実行（週 1 回）で検査してよいか | 追加しない（必要になってから） | |
| 7 | CSS のクラス名に `snake_case` を使いたいか（Stylelint の `selector-class-pattern` を無効にするか） | 標準の kebab-case に統一し、ルールは有効のまま | |
| 8 | pre-commit フックを導入しない方針でよいか | 導入しない（第 5.3 節） | |
| 9 | 外部 URL を除くリンク切れは PR の必須チェックにするが、未マージの仕様書へのリンクで一時的に失敗しうる。その間は仕様書側のリンクを避けるか、除外してよいか | 未マージ仕様書へのリンクは書かない運用 | |
| 10 | スプライトを CI で二重に生成すること（lint の `assets` と T-005 の同期）を許容するか。許容しない場合は、`assets` を同期ワークフロー側に統合する | 初期は許容し、遅ければ T-005 で統合 | |

## 受け入れ条件

- [ ] 各【確認】の記述に公式ドキュメントの URL（またはレジストリ名）が添えられている
- [ ] 確認できなかった事項が「未確定事項」の A に列挙されている
- [ ] チェック項目ごとに、対象・ツール・選定理由・設定ファイル名・実行コマンドが書かれている
- [ ] 段階導入の計画があり、対象ファイルが無い間もジョブが失敗しない設計になっている
- [ ] ジョブ構成、トリガー、paths フィルタの扱い、キャッシュ、concurrency、権限、バージョン固定方針が書かれている
- [ ] ローカル実行の方法（`npm run lint`、PHP の任意手段、pre-commit の方針）が書かれている
- [ ] 追加されるファイルの一覧と、`src/` に開発用ファイルを置かない原則が書かれている
- [ ] GitHub 側の設定手順が、作者の作業として分けて書かれている
- [ ] 既存ドキュメントが提案ルールで通るかの見込みが書かれている
- [ ] 「未確定事項」の B に作者が回答し、回答に合わせて本仕様書が更新されたうえでステータスが Approved になる
