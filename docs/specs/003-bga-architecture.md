# 003: BGA プロジェクト構成・技術方針

| 項目 | 内容 |
| --- | --- |
| ステータス | Draft |
| 対応タスク | T-003 |
| 作成日 | 2026-10-10 |
| 関連仕様書 | 後続: T-004（CI lint）、T-005（Studio 自動同期）、T-006（状態遷移）、T-007（データモデル）、T-009（UI・アセット） |

## 目的

MINIMUM JOURNEY を BGA Studio のプロジェクト `minimumjourney` として実装するにあたり、リポジトリの `src/` に何を置くか、BGA フレームワークのどの機能を使うか、どの言語・ツールで書くかを決める。後続の CI（lint）、自動同期、状態遷移設計はこの仕様書に依存する。

## スコープ

- 対象: BGA の現行標準ファイル構成、`src/` 構成案、言語・ツール方針、フレームワーク機能の対応表、アセット方針、Studio への接続方法、ローカル確認方法、後続タスクへの申し送り
- 対象外: ゲームルール（001）、カードデータ（002）、状態遷移の具体設計（006）、DB スキーマ・通知の詳細（007）、カード効果の実装（008）、UI・スプライトの詳細設計（009）、CI / 同期ワークフローの実装（004 / 005）。コードやワークフローはこの仕様書では作成しない

## 記法と調査の前提

- **【確認】** は公式ドキュメント（`https://en.doc.boardgamearena.com/` 配下）で確認した事実。URL を添える。
- **【提案】** は本仕様書の提案・推測。公式ドキュメントでは確認していない。
- **【未確認】** は確認を試みたが公式ドキュメントから判断できなかった事項。「未確定事項」の A・Bにまとめる。
- 調査日は 2026-10-10。ページは WebFetch（本文を要約して返すツール）で取得したため、**要約を介した確認**である。コード例の細部や属性名など、実装段階で最終確認が必要なものは各 URL を再読すること。
- BGA ドキュメント内に新旧の記述が混在している箇所がある（例: `gameoptions.json` と `gameoptions.jsonc` の表記ゆれ）。その場合は両方を併記し、「未確定事項」の A・Bに挙げた。

主な参照 URL（以降は略称で記す）:

| 略称 | URL |
| --- | --- |
| Studio | https://en.doc.boardgamearena.com/Studio |
| FileRef | https://en.doc.boardgamearena.com/Studio_file_reference |
| FirstSteps | https://en.doc.boardgamearena.com/First_steps_with_BGA_Studio |
| Walkthrough | https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough |
| Migration | https://en.doc.boardgamearena.com/BGA_Studio_Migration_Guide |
| GamePhp | https://en.doc.boardgamearena.com/Main_game_logic:_Game.php |
| StateDir | https://en.doc.boardgamearena.com/State_classes:_State_directory |
| StatesInc | https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php |
| GameJs | https://en.doc.boardgamearena.com/Game_interface_logic:_Game.js |
| ItemManager | https://en.doc.boardgamearena.com/ItemManager |
| Deck | https://en.doc.boardgamearena.com/Deck |
| BgaCards | https://en.doc.boardgamearena.com/BgaCards |
| BgaAnimations | https://en.doc.boardgamearena.com/BgaAnimations |
| Hearts | https://en.doc.boardgamearena.com/Tutorial_hearts |
| Reversi | https://en.doc.boardgamearena.com/Tutorial_reversi |
| TsScss | https://en.doc.boardgamearena.com/Using_Typescript_and_Scss |
| Tools | https://en.doc.boardgamearena.com/Tools_and_tips_of_BGA_Studio |
| VSCode | https://en.doc.boardgamearena.com/Setting_up_BGA_Development_environment_using_VSCode |
| Img | https://en.doc.boardgamearena.com/Game_art:_img_directory |
| Checklist | https://en.doc.boardgamearena.com/Pre-release_checklist |
| Guidelines | https://en.doc.boardgamearena.com/BGA_Studio_Guidelines |
| FAQ | https://en.doc.boardgamearena.com/Studio_FAQ |

## 仕様

### 1. BGA Studio の現行標準ファイル構成

#### 1.1 新旧の併存状況（確認した事実）

- **【確認】** 現行標準は `modules/php/Game.php`（サーバー）と `modules/js/Game.js`（クライアント）。状態機械は `modules/php/States/` の状態クラス。FileRef: https://en.doc.boardgamearena.com/Studio_file_reference
- **【確認】** 次は **非推奨（deprecated）**: `states.inc.php`、`material.inc.php`、`<game>.view.php` と `<game>_<game>.tpl`、`<game>.action.php`。Deck（PHP）と Stock（JS）も非推奨。
  - FileRef: https://en.doc.boardgamearena.com/Studio_file_reference
  - StatesInc: https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php
  - action.php: https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php（「autowired action を使うなら不要」）
  - Deck: https://en.doc.boardgamearena.com/Deck（「ItemManager の使用を推奨」）
  - Stock: https://en.doc.boardgamearena.com/Stock
- **【確認】** 設定は JSON 系へ移行済み（`gameinfos.jsonc`、`gameoptions.jsonc`／`gameoptions.json`、`gamepreferences.json(c)`、`stats.jsonc`／`stats.json`）。旧 PHP 版は既存ゲーム向けに残るが、「新規プロジェクトは JSON を使わなければならない」とある。
  - https://en.doc.boardgamearena.com/Options_and_preferences:_gameoptions.json,_gamepreferences.json
  - https://en.doc.boardgamearena.com/Game_statistics:_stats.json
  - https://en.doc.boardgamearena.com/Game_meta-information:_gameinfos.jsonc
- **【確認】** Walkthrough は「現行フレームワーク（2024 年以降）は PHP の状態クラス、TypeScript/JavaScript（任意）、JSON 設定、`#[PossibleAction]` による属性ベースのアクション宣言を使い、`states.inc.php`・`material.inc.php`・重い Dojo 依存は旧式」と述べる。https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough
- **【確認】** 一方、FirstSteps の初期ファイル一覧には `states.inc.php`・`material.inc.php`・`action.php`・`view.php`・`.tpl` がまだ載っている（旧構成を前提にした記述が残っている）。https://en.doc.boardgamearena.com/First_steps_with_BGA_Studio

**新規プロジェクトの採用方針【提案】**: **現行構成のみを採る**。理由は (a) FileRef が現行標準と非推奨を明示している、(b) 新規プロジェクトは JSON 設定の使用が必須とされている、(c) 非推奨ファイルに依存すると将来の削除・移行コストが発生する、(d) 本ゲームは同時手番・割り込み選択が多く、状態クラス（`getArgs` / `zombie` を状態ごとに同居させられる）の方が保守しやすい。Studio が初期生成する非推奨ファイル（`states.inc.php` 等）は、動作確認後に削除または空のスタブにする（削除可否は未確定事項 B の No.3）。

#### 1.2 `minimumjourney` の具体的なファイル・クラス構成【提案】

名前空間とクラス名は Migration の例（`namespace Bga\Games\YourGameName;`、`class Game extends \Bga\GameFramework\Table`、`declare(strict_types=1);`）に基づく。https://en.doc.boardgamearena.com/BGA_Studio_Migration_Guide
名前空間の大文字小文字（`minimumjourney` か `MinimumJourney` か）は **【未確認】**。Studio が初期生成するファイルの名前空間に必ず合わせる（未確定事項 B の No.4）。以下は暫定的に `Bga\Games\minimumjourney` と書く。

| BGA 側パス | 役割 | 根拠 |
| --- | --- | --- |
| `modules/php/Game.php` | クラス `Bga\Games\minimumjourney\Game`。`setupNewGame`、`getAllDatas`、`getGameProgression`、`upgradeTableDb`、共通ヘルパー | GamePhp |
| `modules/php/States/*.php` | 状態クラス。`Bga\GameFramework\States\GameState` を継承し、名前空間はフォルダ構造に対応させる | StateDir |
| `modules/php/Cards/Card.php`、`CardManager.php` ほか | 任意の追加クラス。ItemManager 用の `#[Item('card')]` クラスなど | ItemManager、Reversi（`modules/php/BoardManager.php` の例） |
| `modules/js/Game.js` | クライアント。`constructor(bga)`、`setup(gamedatas)`、`onEnteringState`、`onLeavingState`、`onUpdateActionButtons`、`notif_*` | GameJs |
| `minimumjourney.css` | スタイル（ルート直下） | FileRef（`<gamename>.css` は必須） |
| `dbmodel.sql` | DB スキーマ（ItemManager を使う場合、カード表は `initDb()` が生成するので重複定義しない） | FileRef、ItemManager |
| `gameinfos.jsonc` | ゲームのメタ情報 | FileRef |
| `gameoptions.jsonc`（`.json` 表記もあり） | ゲームオプション | 未確定事項 B の No.5 |
| `gamepreferences.jsonc`（`.json` 表記もあり） | ユーザー設定（任意） | 同上 |
| `stats.jsonc`（`.json` 表記もあり） | 統計定義 | 同上 |
| `img/` | 画像（必須ディレクトリ。ゲーム箱・アイコン等の画像もここ） | FileRef、Img |
| `misc/` | Studio 専用。本番に出ない。上限 1 MB | FileRef |
| `_ide_helper.php` | フレームワーク関数の IDE 補完用。Studio 側が配置・更新する | VSCode |

- **【確認】** 状態 ID は正の整数で、1（初期状態）と 99（終了状態）は予約。状態クラスのコンストラクタ引数は `id` と `type` が必須、`name`・`description`・`descriptionMyTurn`・`transitions`・`updateGameProgression`・`initialPrivate` が任意。StateDir、StatesInc。
- **【確認】** アクションは `act` で始まる public メソッドに `#[PossibleAction]` を付ける（autowire）。`action.php` は不要。GamePhp、StateDir。
- **【確認】** 非推奨の `<game>.game.php` は `Game.php` に置き換わった。Migration。

### 2. `src/` ディレクトリ構成案とBGA側との対応【提案】

コミットされる `src/` の中身が BGA 側プロジェクトフォルダ（`/minimumjourney/`）と 1 対 1 になる。ただしスプライト生成物はコミットしない（確定、No.11 回答、2026-10-13 第2回）ため、**Studio への同期対象は「`src/` ＋ CI が生成したスプライト」**になる。生成物は `src/img/` 配下に出力して BGA 側 `/minimumjourney/img/` と同じ相対位置に揃える【提案】ので、同期の観点では 1 対 1 は保たれる（リポジトリ上は生成物が無いだけ）。

```text
src/
├── gameinfos.jsonc
├── gameoptions.jsonc
├── gamepreferences.jsonc        # 必要になった場合のみ
├── stats.jsonc
├── dbmodel.sql
├── minimumjourney.css
├── modules/
│   ├── php/
│   │   ├── Game.php
│   │   ├── States/              # 状態クラス（内容は T-006 で確定）
│   │   └── Cards/               # Card / CardManager など（T-007・T-008）
│   └── js/
│       └── Game.js
├── img/
│   ├── cards_*.webp / png       # カードスプライト。コミットしない。CI（とローカル）で生成する【提案】（第 5 節、詳細は T-009）
│   └── game_box.png ほか        # BGA 規定の箱・アイコン画像（コミットする。要件は Img を参照）
├── misc/                        # 同期しない方がよいものがあれば置かない
└── （_ide_helper.php は Studio 側に存在。リポジトリには入れない【提案】）
```

対応と同期上の取り決め【提案】:

- `src/X` ↔ BGA `/minimumjourney/X`。リポジトリに無く BGA 側にだけあるファイル（`_ide_helper.php` や Studio 生成ファイル）は、T-005 で「ミラーリング削除しない」を既定とする。VSCode の SFTP 設定例にも `delete: true` と `_ide_helper.php` の無視が出てくる（VSCode: https://en.doc.boardgamearena.com/Setting_up_BGA_Development_environment_using_VSCode）が、これはローカルが正のときの設定であり、CI での削除可否は T-005 が決める。
- `node_modules/`、`package-lock.json`、`.git` 等は `src/` に置かない（リポジトリ直下に置く）。
- スプライト生成物の置き場所【提案】: 生成スクリプトはリポジトリ直下の `scripts/` に置き、原本 `data/cards/` を入力として `src/img/cards_*` に出力する。生成物はリポジトリ直下の `.gitignore` で `src/img/cards_*` を除外し、コミットしない（箱・アイコン画像など手書きの画像は除外しない）。CI では同期の前にこのスクリプトを実行し、`src/` ごと（生成物を含めて）Studio に送る。開発者のローカルでも同じスクリプトで同じ生成物が得られるようにする（手元で試験同期する場合に使う）。

### 3. 言語・ツールの方針

#### 3.1 サーバー側 PHP

- **【確認】** BGA の現行 PHP は **8.4**。Studio ページに「PHP: 8.4」。FAQ に「ゲームは PHP 8.4 互換であること」。https://en.doc.boardgamearena.com/Studio 、https://en.doc.boardgamearena.com/Development_FAQ（FAQ の別 URL。Studio_FAQ からも同内容）
- **【確認】** 新規プロジェクトは strict mode で生成され、`declare(strict_types=1);` を全 PHP ファイルに付ける、`self::` ではなく `$this->` を使う。Migration、および検索結果の要約（https://en.doc.boardgamearena.com/PHP_8_upgrade の移行記述）。なお PHP_8_upgrade の取得要約には「PHP 8.8」という明らかな誤りが含まれたため、8.4 の根拠は Studio ページと FAQ とする。
- **【提案】** PHP 8.4 を前提にし、`declare(strict_types=1);`、型宣言、`enum`、属性を使う。CI の PHP バージョンも 8.4 に揃える（T-004）。
- **【提案】** **状態は状態クラスで定義する**（`states.inc.php` は使わない）。1 状態 1 クラス。共通ロジックは `Game.php` ではなく専用クラス（カード管理、効果解決）に出し、`Game.php` を肥大させない（Reversi のチュートリアルが `BoardManager` を `Game` のコンストラクタで生成する構成を示している）。

#### 3.2 クライアント側: 素の JS か TypeScript か

事実:

- **【確認】** `modules/js/Game.js` が現行標準。TypeScript は任意で、公式手順は `src/ts/Game.ts` を Rollup でバンドルして `modules/js/Game.js` を出力し、SCSS は Dart Sass でルート直下の `<game>.css` に出力する。生成された `.js` と `.css` を自動同期で Studio にアップロードし、`node_modules/` は同期しない。https://en.doc.boardgamearena.com/Using_Typescript_and_Scss
- **【確認】** `tsconfig.json` は `target: ES2020`、`module: ES2022`、`rootDir: "."`、`strict: false`。同上。
- **【確認】** bga-cards の `.d.ts` が配布されている。https://en.doc.boardgamearena.com/BgaCards
- **【確認】** Game.js のコードは Dojo ではなく素の JavaScript で DOM を扱う方向（Dojo は非推奨）。https://en.doc.boardgamearena.com/Game_interface_logic:_Game.js

比較:

| 案 | 長所 | 短所 |
| --- | --- | --- |
| A. 素の JS（`src/modules/js/Game.js` を直接編集） | `src/` が BGA と完全に 1 対 1。ビルド不要。CI は ESLint のみ。生成物の扱いに悩まない | 型がなく、カード約 58 種×効果の状態を持つクライアントでは取り違えが起きやすい。単一ファイルが肥大しやすい |
| B. TypeScript＋ビルド、生成物をコミット | 型チェック。同期は生成物をそのまま送るだけ | `src/` 内に生成物と元 TS が混在せず、リポジトリ直下に元 TS を置く構成になり 1 対 1 が崩れる。生成物の差分が PR に混ざる。ビルド忘れ |
| C. TypeScript＋ビルド、CI で生成して同期 | 生成物をコミットしない。常に最新 | CD ワークフローにビルド工程が必要。手元で Studio へ試験的に同期する場合もビルドが必要 |

**確定（未確定事項 B の No.1 回答、2026-10-13）: 案 A（素の JS）＋ JSDoc 型注釈＋ `// @ts-check`（`tsc --noEmit --checkJs` で型チェックのみ実行）。** 以下の理由は当初の提案時のもので、作者が了承した。

- 理由 1: リポジトリの方針「`src/` の中身が BGA 側と 1 対 1」を最も素直に満たす。
- 理由 2: 開発者のローカルには Node.js があるが PHP は無い。Node だけで ESLint と `tsc --noEmit` が回り、CI に生成工程を足さずに済む。
- 理由 3: 公式 TS 手順は「`Game.ts` 単一入口を Rollup でバンドル」する構成で、`src/ts`（ここでの `src/` は BGA プロジェクト直下の意味）を BGA 側にも置く前提。本リポジトリの `src/` と衝突し、`src/src/ts/` のような不自然な階層になる。
- 理由 4: 本ゲームの UI は「手札／準備場／探査場の表示と選択ボタン」が中心で、複雑なクライアントロジックは少ない（効果判定はサーバー）。型の恩恵より構成の単純さを優先できる。
- 見直し条件: `Game.js` が肥大して分割が必要になった場合。**【未確認】** `modules/js/` 配下の複数ファイルを `import` で束ねられるかは公式ドキュメントで確認できなかった（`importEsmLib` はライブラリ用）。分割が必要になった時点で案 C（`client/` に TS を置き CI でバンドルし `src/modules/js/Game.js` を生成）へ移行する。その場合は生成物を `.gitignore` し、T-005 の同期の前にビルド工程を入れる。

#### 3.3 CSS か SCSS か

- **【確認】** SCSS は公式手順で可能だが、Dart Sass によるビルドが必要。Using_Typescript_and_Scss（上記 URL）。
- **確定（未確定事項 B の No.2 回答、2026-10-13 第2回）: 素の CSS（`src/minimumjourney.css`）、SCSS は使わない。** 作者回答は「CSSはお任せします」で、本仕様書の提案どおりとする。理由は案 A と同じ（ビルド不要・1 対 1）。カードスプライトの `background-position` は CSS の `calc()` と CSS 変数で書ける（Cookbook が `calc(100% / (15 - 1) * (10 - 2))` 形式を示す: https://en.doc.boardgamearena.com/BGA_Studio_Cookbook ）。ダークモード対応が要る（Checklist）ので CSS 変数で配色を持つ。
- **【確認】** CSS を変更した後はブラウザの強制再読込（Ctrl+F5）が必要。FileRef。

### 4. このゲームに必要なフレームワーク機能の対応表

| 要件 | 使う機能（確認した事実） | 本ゲームでの使い方【提案】 |
| --- | --- | --- |
| 同時選択フェイズ（ドロー／ストップを全員が同時に秘密裏に選ぶ） | `StateType::MULTIPLE_ACTIVE_PLAYER`。複数人が同時にアクティブ。アクティブにするのは状態の「途中」（`onEnteringState` 等）で行い、`setAllPlayersMultiactive()`、`setPlayerNonMultiactive($playerId, $nextState)` などを使う。全員が非アクティブになると遷移する（StatesInc、GamePhp、StateDir） | 準備フェイズの各ステップ（ドロー／ストップ選択）を 1 つの MULTIPLE_ACTIVE_PLAYER 状態にする。選択済みのプレイヤーは `setPlayerNonMultiactive`。詳細は T-006 |
| 非公開情報: 山札の順序 | ItemManager の ORDER フィールド（0 が底、最大が最上）と `shuffle`、`pickItem`。山札の中身は `getAllDatas` で送らず、枚数のみ送る。`bga_rand` / `random_int` 等で乱数。DB 側の `RAND()` は非推奨（GamePhp、ItemManager） | 各プレイヤーの山札は location 値で表す（例: `deck`＋`locationArg`＝プレイヤー ID）。クライアントには枚数だけ送る |
| 非公開情報: ストップ時の裏向きカード、本人にだけ見える情報 | `notify->player()` による個別通知、公開通知内の `_private`（プレイヤー ID ごとの引数・メッセージ）、`getArgs()` / `getAllDatas()` 内の `_private`。ItemManager の `changeIds()`（「以前見えていたアイテムが隠れるとき」に ID を付け替えて追跡を防ぐ）（GamePhp、StateDir、ItemManager、Hearts） | ストップ時に引く裏向きカードは**準備したカードとみなされず、枚数に数えず、中身（探査力・効果・シンボル）は無効**。全員ストップの回は引かない（確定、No.7 の回答と第2回の訂正、2026-10-13）。山札から離れるのか、デジタル実装で裏向きカードを表示するか（演出のみか）は未確定（T-006 / T-009 で決める）。券面を他プレイヤーに見せるかは回答に明示がなく未確定。中身が無効で数にも入らないため、秘匿が必要なら「引いた」事実と枚数以外は送らない方針が取りうる。ID 付け替えの要否は T-007 で判断（未確定事項 B の No.7） |
| カード管理（山札・準備場・探査場・獲得カード） | **Deck は非推奨、ItemManager を推奨**。Card クラスに `#[Item]`・`#[ItemField]` を付け、`createItemManager` で生成。`initDb()` が表を作る。`shuffle`、`pickItem(s)`、`moveItem(s)`、`ItemLocation::getDefaults()`（deck/discard/table/hand）または独自 location（ItemManager、Hearts） | `ItemManager` を採用。Deck は新規採用しない（Deck の `autoreshuffle` 相当は、帰還フェイズで全カードをシャッフルする本ゲームでは不要な可能性が高い。詳細 T-007） |
| カード表示（JS） | **Stock は非推奨、BgaCards（bga-cards）を推奨**。`importEsmLib('bga-cards', '1.x')` と `importEsmLib('bga-animations', '1.x')`。`LineStock`、`HandStock`、`Deck`、`VoidStock`、`SlotStock`、`AllVisibleDeck`。表裏は `setupFrontDiv` / `setupBackDiv` と `isCardVisible`。PHP から送ると数値が文字列になる場合があるため整数化が必要（BgaCards） | 準備場・探査場は LineStock／SlotStock、山札は Deck、横向きカードは bga-cards の回転機能を使う（回転 API の詳細は未確認、未確定事項 B の No.8） |
| 通知とアニメーション | `$this->bga->notify->all(type, message, args)`（`${player_name}` と `player_id`）。通知はアクション終了時にまとめて送られる。JS は `setupPromiseNotifications()` と `async notif_*`。アニメーションは bga-animations（Promise ベース）（GamePhp、GameJs、BgaAnimations） | 通知を Promise で順次処理し、リプレイ・観戦でも崩れないよう、画面更新は通知経由だけにする（Game_replay: https://en.doc.boardgamearena.com/Game_replay ） |
| 効果解決のための追加状態（割り込み的な選択） | 状態クラスで GAME / ACTIVE_PLAYER / MULTIPLE_ACTIVE_PLAYER / PRIVATE を定義。`onEnteringState` の戻り値で別の状態へ。private parallel states: `initialPrivate`、`initializePrivateStateForAllActivePlayers()`、`nextPrivateState()`、`unsetPrivateState()`。クライアント側のみの client states もある（StatesInc、GamePhp、StateDir、Cookbook） | 効果ごとの選択状態を状態クラスにする。全員が各自の効果を並行して解決できる局面は private parallel states 候補。設計は T-006 |
| undo | 既定では無し。`gameinfos.jsonc` の `db_undo_support: true` と `undoSavepoint()` で有効化。Guidelines は「undo は 1 手番に複数アクションがある場合のみ」「不可逆な操作には確認ボタン」（GamePhp、Guidelines） | **使わない**（`db_undo_support` は false）。同時選択・乱数・非公開情報が絡み、巻き戻しが情報漏えい・不整合になりうる。確認ボタンの要否は UI 設計（T-009）で判断 |
| zombie（離脱プレイヤー） | 状態クラスの `zombie(int $playerId)` で自動行動を定義。`getCurrentPlayerId()` は使えない。リリース前チェックで必須（StateDir、GamePhp、Checklist） | ドロー／ストップ状態の既定はストップ（失敗リスクを取らない）【提案、未確定事項 B の No.9】。効果の選択状態は無難な選択（スキップ、可能なら最小）を各状態クラスで定義 |
| ゲーム進行度 | `getGameProgression()` が 0〜100 を返す。状態クラスに `updateGameProgression: true`。リリース前チェックで必須（GamePhp、StateDir、Checklist） | 終了条件（未確認帯域の獲得、ラニアケア超銀河団の探査山札の枯渇）に近づく度合いから算出。式は T-001 の確定後に T-006/T-007 で決める |
| 統計 | `stats.jsonc` に定義（型は int / float / bool、ID は 10 以上、削除後の ID 再利用は禁止）。`playerStats->init/set/inc`、`tableStats->init/set/inc`。リリース前チェックで「意味のある統計」が必須（Game_statistics、GamePhp、Checklist） | 例: 総ラウンド数、ドロー回数、ストップ回数、失敗（横向き）回数、獲得カード数。確定は T-007 |
| ゲームオプション（お守りの面選択など） | `gameoptions.jsonc` に ID・`name`・`values`（キーは文字列）・`default`・`level`（`base`/`major`/`additional`）・`displaycondition`・`startcondition`。PHP では `$this->bga->tableOptions->get(int $optionId): ?int` で読む。ゲーム開始後は変更不可（Options 参照ページ） | お守り（カード 57・58）の面選択はゲームオプションの `values` で選ぶ。デフォルトは航路安全のお守り（確定、未確定事項 B の No.10 回答、2026-10-13 第3回） |
| 同点処理・勝敗 | `playerScore` で名声（勝利点）を更新、`playerScoreAux` で同点処理。終了時に `GameResult` を返す形もある（GamePhp） | 名声＝スコア。同点処理はルールブックに従う（T-001） |
| 乱数 | `bga_rand(min, max)`、配列は `random_int()`＋ `array_slice`。`shuffle()` / `array_rand()` は非推奨（GamePhp） | 山札のシャッフルは ItemManager の `shuffle` を使い、自前で乱数を扱う場合は上記に従う |

### 5. アセットの制約と方針

#### 5.1 BGA の制約（確認した事実）

出典: Img https://en.doc.boardgamearena.com/Game_art:_img_directory 、Checklist https://en.doc.boardgamearena.com/Pre-release_checklist

- 1 ファイルあたり **最大 2 MB**（Img）。一方 Checklist は「各画像は 4 MB まで」と記す。**2 つの値が食い違う**ため、小さい方の 2 MB を上限として扱う。
- 画像の総量は **15 MB を超えない**こと。超えるなら圧縮（Checklist）。
- 画像ファイル数は「十数枚以内」が推奨（Img）。複数画像は **CSS スプライト**にまとめる（Img、Checklist）。
- スプライトシートの最大サイズは **4096×4096 px**（Android 互換のため。超えると表示不具合）（Img）。
- `img/` 直下の画像は既定で先読みされる。拡張や特定プレイヤー用の素材はサブディレクトリに置くと先読みを避けられる（Img）。
- ファイル名に空白・括弧を含めない（Img）。
- 形式: **WebP を推奨**（PNG/JPG はデプロイ時に自動変換される）。透過が必要なものは PNG、不透明な大きい画像は JPG（Img）。
- 表示サイズより高解像度の画像を用意し、CSS の `background-size` で縮小する（ブラウザのズーム対策）（Img）。
- ゲーム箱・アイコン等の画像を変更したときは、コントロールパネルの「Reload game box image」が必要（FAQ: https://en.doc.boardgamearena.com/Studio_FAQ ）。それ以外の画像は即時反映（キャッシュ消去が必要な場合あり）。
- bga-cards はスプライトを使う場合 `setupFrontDiv` で `background-position` を計算する（BgaCards）。

#### 5.2 現状（本リポジトリの実測）

- `data/cards/` は PNG 72 枚、合計約 31 MB、1 枚約 0.3〜0.5 MB。裏面 `rev.png` は 716×999 で、他の 715×999 と 1 px 異なる。
- そのまま使うと **総量 15 MB を超え**、ファイル数も推奨を大幅に超える。715×999 のまま 4096 px 以内に収めると 1 シートあたり 5 列×4 行＝ 20 枚で、4 シート必要になる。

#### 5.3 方針（縮小とスプライト化は確定、他は【提案】。詳細設計は T-009）

1. `data/cards/` は原本として触らず、生成物は `src/img/` 配下に出力するが**リポジトリにはコミットしない**（確定、No.11 回答、2026-10-13 第2回。「スプライトはCIで作成しましょう」）。CLAUDE.md の規則 6（加工物は `src/` 側に作る）には、出力先が `src/img/` である点で従う。
2. 原本をそのまま `img/` に置かない。**縮小とスプライト化を行うことは確定**（作者の依頼、2026-10-13）。縮小後の解像度（例: 表示サイズの 2 倍程度、幅 358 px 前後）、形式（WebP / PNG）、シート分割は未定で、T-009 で決める。縮小時は rev.png を含め全カードを同一寸法に揃える。
3. シートは 4096×4096 px 以内、1 枚 2 MB 以内、総量 15 MB 以内を満たす。縮小幅 358×500 なら 4096 px 以内で 11 列×8 行＝ 88 枚（全 72 枚）が 1 枚に収まる計算だが、**2 MB 制限を満たすかと Android での大きなテクスチャの扱いに懸念があるため、複数シート（表面を 2 枚程度＋裏面など）に分ける案も T-009 で比較する**。
4. **確定**（No.11 回答）: スプライトは CI（GitHub Actions）で生成して Studio に同期する。【提案】生成は再現可能な Node スクリプト（リポジトリ直下 `scripts/` 等）で行い、入力は原本 `data/cards/`、出力は `src/img/cards_*`、`.gitignore` で除外する。ローカルでも同じスクリプトで生成できるようにする。CI での生成に伴う検討事項（毎回の転送量、生成の決定性、画像ライブラリの依存）は T-005 / T-009 に申し送る。
5. 画像ファイル名は英数字とアンダースコアのみ。
6. カード券面のテキスト（効果文）は画像に含まれるため、日本語表示は画像のまま。UI 文言のみ翻訳機構を使う（第 5.4）。

#### 5.4 翻訳

- **【確認】** 翻訳は主にクライアント側。ゲームは英語で書き、PHP では `clienttranslate()`、JS では `_()` で文字列を包む。通知の文字列は `clienttranslate()` で包み、翻訳が必要な引数は `i18n` に列挙する。`${param}` 置換を使い文字列連結はしない。オプション・統計の名前には `totranslate()`。ベータに入ると翻訳ツールがコードをスキャンし、コミュニティが翻訳する。https://en.doc.boardgamearena.com/Translations
- **【提案】** 日本語化の経路は上記のみ。日本語訳はベータ入り後に BGA の翻訳ツールで作る（リポジトリに訳ファイルは置かない）。

### 6. Studio への接続方法

| 項目 | 内容 | 根拠 |
| --- | --- | --- |
| ホスト | `1.studio.boardgamearena.com` | VSCode、Tools |
| ポート | **2022**（22 ではない） | 同上 |
| プロトコル | SFTP | 同上 |
| 認証 | 本プロジェクトは **SSH 鍵認証で確定**（未確定事項 B の No.12 回答、2026-10-13）。公式には (a) 開発者登録時に受け取る SFTP パスワード、または (b) SSH 鍵があり、**SSH 鍵をアップロードするとパスワード認証は自動で無効になる** | Tools: https://en.doc.boardgamearena.com/Tools_and_tips_of_BGA_Studio |
| SSH 鍵の登録 | Studio のコントロールパネル（https://studio.boardgamearena.com/controlpanel ）でアップロード | 検索結果の要約（Tools ページ由来）。取得時の要約では確認が弱く、実画面で要確認 |
| プロジェクトフォルダ | ログイン後の home にプロジェクト名のフォルダが表示される。VSCode 設定例は `remotePath: "/<プロジェクト名>/"` → ここでは `/minimumjourney/` | FirstSteps、VSCode |
| ユーザー名 | 開発者アカウントのユーザー名（本プロジェクトでは `ZoneTsuyoshi0`） | 依頼者提供の事実。BGA ドキュメントの SFTP ユーザー名はプレースホルダ |
| 推奨される同期 | 自動同期を設定する（手動 FTP は非推奨）。VSCode の SFTP 拡張、WinSCP、sshfs / rsync、lftp（`mirror --reverse --parallel=10`）が紹介されている | FirstSteps、Tools、VSCode |
| バージョン管理 | Studio 内蔵の SVN がある。外部の GitHub リポジトリの併用が推奨されている（命名例 `bga-<game>`） | Tools |

**同期後に Studio 側で必要な操作（確認した事実）**

- `dbmodel.sql` を変更したとき: 「ゲームの再起動が必要」（FileRef）。
- `gameinfos.jsonc` を変更したとき: コントロールパネルの「Reload game informations」（gameinfos ページ: https://en.doc.boardgamearena.com/Game_meta-information:_gameinfos.jsonc ）。
- `gameoptions` / `stats` / `gamepreferences` を変更したとき: コントロールパネルで「該当する更新操作」（FileRef は「特別な再読込がコントロールパネルで必要」、FAQ は「Control Panel / Manage Games / Your Game の対応するアクション」、Migration は「Reload game options configuration」）。
- `Game.js` を変更したとき: 通常のリロードで反映（FileRef）。CSS は強制再読込（FileRef）。
- ゲーム箱・アイコン画像: 「Reload game box image」（FAQ）。画像の削除はコントロールパネルでの追加操作が必要（FileRef）。
- 他のブラウザタブは手動でリロードする必要がある（Tools）。
- 「Control Panel / Manage Games / `<game>` / Commit my modifications now」で Studio 内蔵 SVN にコミットできる（FirstSteps）。

**CI（GitHub Actions）から接続する際の注意点**

- 【提案】ポートは 2022。SFTP クライアント（`lftp`、`rsync -e "ssh -p 2022"` など）の設定でポートを必ず指定する。
- **確定**（未確定事項 B の No.12 回答、2026-10-13）: SSH 鍵を登録し、パスワード認証を無効にする。鍵認証はパスワードを Secrets に置くより鍵のローテーションと失効が容易。鍵を登録するとパスワード認証が無効になるため、手元の SFTP クライアントも鍵に切り替える必要がある。
- 【提案】接続先のホスト鍵は初回接続で固定（`known_hosts` に事前登録）し、`StrictHostKeyChecking=no` に頼らない。ホスト鍵のフィンガープリントは公式ドキュメントに記載がなく、**【未確認】**。
- 【提案】Secrets 名の案: `BGA_SFTP_HOST`、`BGA_SFTP_PORT`、`BGA_SFTP_USER`、`BGA_SFTP_KEY`（または `BGA_SFTP_PASSWORD`）。認証情報、ホストのパスワード、鍵はリポジトリ・ログ・仕様書に書かない。
- 【提案】同期ジョブは同時実行しない（`concurrency` グループ）。Studio への並列アップロード制限は公式ドキュメントに記載がなく **【未確認】**。lftp の `--parallel=10` が例示されているので低並列度での実行は許容と推測できるが保証はない。
- 【提案】GitHub-hosted runner の IP が Studio 側で制限されるかは **【未確認】**。T-005 の最初に接続テストを行う。
- 【提案】`dbmodel.sql` / `gameinfos` / `gameoptions` / `stats` を変更した main マージ後は、CI が同期しても Studio 側の再読込操作は人手が必要（自動化できる公式手段は確認できなかった）。T-005 の運用手順に「変更ファイル別の手動操作チェックリスト」を入れる。

### 7. ローカル開発・動作確認の方法

#### 7.1 Studio 上での確認（確認した事実と提案）

- **【確認】** 「Express Start」でゲームを開始し、赤い矢印ボタンでテスト用アカウントを切り替えて複数プレイヤーを 1 人で試す。https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough
- **【確認】** `debug_` で始まる Game.php のメソッドが Studio のデバッグメニューに出る。ゲーム状態の保存／復元で再現しやすくできる。サーバーログ（`$this->dump()`、`debug()`、`trace()`）はコンストラクタ、`setupNewGame` と開始直後の状態では動かない。https://en.doc.boardgamearena.com/Practical_debugging
- **【確認】** 初回に「hello world」を出してクライアント同期を確認する、最初からバージョン管理する。Walkthrough。
- **【確認】** リリース前に確認する項目: 最小化（minify）した JS/CSS での動作、観戦モード、Chrome/Firefox、モバイル、ダークモード、リアルタイムモード、デバッグログの削除、ソースの著作権ヘッダー。Checklist。
- **【提案】** 手順: 変更 → 同期 → Express Start → 3 人・4 人それぞれで、全員ドロー／全員ストップ／1 人だけドロー、離脱（zombie）、リロード後の復元、観戦、リプレイを確認。標準テスト表を T-006 以降の仕様書に含める。

#### 7.2 ローカルでできること【提案】

| 対象 | できること | 備考 |
| --- | --- | --- |
| PHP | `php -l` と静的解析（PHPStan 等）、PHPUnit の単体テスト | **ローカルに PHP が無い**ため、手元では実行できない。CI（PHP 8.4）で実行する。手元で確認したい場合は Docker（任意、未決: 未確定事項 B の No.13） |
| PHP 補完 | `_ide_helper.php` を Studio から取得すると IDE 補完が効く（Intelephense 推奨） | VSCode ページ。取得は一度手動（SFTP ダウンロード） |
| JS | ESLint、`tsc --noEmit --checkJs`（型チェック） | Node のみで可能 |
| CSS | Stylelint | Node のみで可能 |
| JSON | JSONC の構文チェック | Node のみで可能（`.jsonc` を解釈できるパーサを使う） |
| 画像 | スプライト生成スクリプトの実行（CI と同じスクリプト。生成物はコミットしない） | Node のみで可能（画像ライブラリ依存。ネイティブ依存の場合 CI との環境差に注意） |
| ゲーム進行 | **ローカルでは不可**。BGA フレームワーク本体が必要なため、Studio で確認する | PHPUnit で書けるのは、フレームワークに依存しない純粋なルール計算（探査出力の計算など）に限る。そのためルール計算は `Game` から独立したクラスに切り出す |

公式ドキュメントには ESLint・PHPStan・PHPUnit の公式設定は載っていない（VSCode ページに「phpunit は composer で入れる」とだけある）。lint ルールは T-004 で決める。

### 8. 後続タスクへの申し送り

#### T-004（CI lint）

- PHP は **8.4** で `php -l`、`declare(strict_types=1);` 必須チェック、静的解析。BGA フレームワーク（`Bga\GameFramework\…`）のスタブが無いと解析が未定義クラスで失敗する可能性が高い。`_ide_helper.php` の取り込み可否と静的解析の許容度を決める（【未確認】: フレームワーク本体をスタブとして配布する公式手段）。
- JS は Dojo ではなく素の ES（`async/await`、ESM の `importEsmLib` はグローバル関数）。ESLint のグローバル定義（`importEsmLib`、`getLibUrl`、`_`、`$` 等）を明示する。型チェックは `tsc --noEmit --checkJs` を候補にする。
- JSONC（`gameinfos.jsonc` ほか）はコメント付き。通常の JSON パーサでは失敗するので JSONC 対応の検査器を使う。
- CSS は素の CSS（Stylelint）。
- 画像は 1 枚 2 MB、総量 15 MB、スプライト最大 4096×4096 px の上限チェックを入れられる。スプライトは CI で生成するため（No.11）、上限チェックは生成後の `src/img/` に対して行う。生成スクリプトの実行を lint ジョブ側に置くかは T-004 / T-005 で調整する。
- 認証情報混入チェックの対象に SSH 秘密鍵ヘッダ（`BEGIN OPENSSH PRIVATE KEY` 等）を含める。

#### T-005（Studio 自動同期）

- 接続情報は第 6 節。ポート 2022、認証は SSH 鍵認証で確定（No.12 回答、2026-10-13。鍵登録でパスワード認証は無効になる）。
- 同期対象は `src/` のみ。BGA 側にのみ存在するファイル（`_ide_helper.php` など）を削除しない設定を既定にする。
- 変更ファイル別に Studio 側での手動操作が要る（第 6 節）。自動化できない部分を運用手順に書く。
- 画像は容量が大きい（上限 15 MB）ので差分転送が望ましい。
- スプライトは CI で生成して同期する（確定、No.11 回答、2026-10-13 第2回）。同期対象は `src/` ＋ CI 生成物（`src/img/cards_*`、コミットされない）。同期ジョブの前に生成ステップを置く。
- CI 生成に伴う検討事項: (a) 毎回生成すると画像のバイト列が変わり、差分転送が毎回全転送になりうる（転送量。上限 15 MB の範囲だが、生成の決定性を確保するか、転送側でサイズ・更新時刻ではなく内容比較にするか）、(b) 生成の決定性（同じ原本から同じバイト列になるか。ライブラリのバージョン固定、メタデータ出力の抑制）、(c) 画像ライブラリの依存（sharp など。バージョン固定とキャッシュ、CI ランナーでの導入可否）。
- 生成物はコミットされないため、PR ブランチからの手動同期でも生成ステップを通す。
- PR ブランチからの手動同期（`workflow_dispatch`）を許す場合、同一プロジェクトフォルダへの同期は後勝ちになる（複数ブランチを同時に同期すると上書きし合う）点に注意。

#### T-006（状態遷移）

- 状態は状態クラスで定義（`id` は 2〜98 と 100 以上を使用可。1 と 99 は予約）。
- 準備フェイズの同時選択は MULTIPLE_ACTIVE_PLAYER。全員が選択済みになったら遷移する。選択内容は確定まで他のプレイヤーに通知しない。
- 効果解決の割り込みは、(a) 状態クラスの追加、(b) private parallel states、(c) クライアント側のみの client states のどれで表すかを、効果ごとに決める。
- すべての状態クラスに `zombie()` を実装する（リリース前チェックの必須項目）。
- `updateGameProgression` を立てる状態を決める。
- 状態遷移は `transitions` マップか `onEnteringState` の戻り値（クラス名／状態 ID）で表す。
- undo は使わない前提で設計する。
- 探査はパスできる。探査できるカードがなければ何も手に入れられない（仕様書 001 の作者回答）。
- ゲーム終了条件の成立後も、残りの探査手番と帰還フェイズをラウンド終了まで行う（仕様書 001 の作者回答）。
- 1 人だけドローの判定は、すでにストップ済みの人がいても、山札 0 枚で実質 1 人しかドローできない場合も同様に、横向き・半減になる（仕様書 001 の作者回答）。
- ストップ時に引く裏向きカードは準備したカードとみなされず、枚数に数えず、中身は無効。位置依存の効果（直前のカード、n 枚目など）の対象にも並びにも入らない。全員ストップの回は引かない。横向きカード（1 人だけドローしたカード）は準備したカード枚数に数え、探査手番の決定でも数える（No.7 の訂正、2026-10-13 第2回）。裏向きカードは山札の上から実際に引いて裏向きで重ねる（山札から離れ、山札の一番上は次のカードになる。確定、2026-10-13 第3回）。状態としても山札と別の置き場に持つ必要がある。表示方法は T-006 / T-009 で決める。
- 探査出力の計算順: お守りの探査力を先に加算する。探査出力 = floor((準備場の探査力の合計（探査力変動を反映）＋お守りの探査力) / 2)。半減は 1 人だけドローしたプレイヤーのみ。半減しないプレイヤーは 合計＋お守りの探査力（仕様書 001 の作者回答）。
- お守りの名声は +1 のみ（印字の名声と「所持者は名声 +1」は同じもの。合計 +2 ではない）。最終ラウンドの帰還フェイズでお守りが移動した場合、名声 +1 を得るのは移動後の所有者（仕様書 001 の作者回答）。
- 3 人プレイで除外するカード（各探査山札から 2 枚ずつ）はランダムに選ぶ（仕様書 001 の作者回答）。

#### T-007（データモデル・通知）

- カードは ItemManager（`#[Item]` クラス＋ ORDER フィールド）。Deck は使わない。
- 山札の中身はクライアントに送らない。ストップ時に引く裏向きカードは準備したカードとみなされず、枚数に数えず、中身は無効。全員ストップの回は引かない（No.7 の回答と第2回の訂正、2026-10-13）。準備場の枚数・探査手番の決定・位置依存の効果の対象に含めない（横向きカードは数える）。券面を他プレイヤーに見せるか、表示方法は未確定で、確定後に決める。 **第3回回答（2026-10-13）で確定**: 裏向きカードは山札の上から実際に引いて裏向きで重ねる（山札から離れる）。山札と別の置き場として持つ。
- お守りはデッキではないが名声・シンボル枚数の計算に含まれ、準備場の外でも探査力が探査出力に加算される（仕様書 001 の作者回答）。計算対象の集合を設計する際に区別する。お守りの名声は +1 のみで、最終ラウンドの帰還フェイズで移動した場合は移動後の所有者が得る。探査出力は お守りの探査力を合計に加えてから半減する（T-006 の同項目、仕様書 001 の作者回答）。
- 通知は公開通知＋ `_private`、または `notify->player` の個別通知。ItemManager の `changeIds()` を使うかを判断する。
- DB は 64 MB 制限があり、gamelog/replay が主な消費元。通知の引数に山札全体を載せない（dbmodel ページ: https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql ）。
- 統計 ID は 10 以上。削除後の再利用は禁止なので、最初に慎重に採番する。

#### T-009（UI・アセット）

- bga-cards（`importEsmLib('bga-cards', '1.x')`）を使う。Stock は使わない。
- 縮小とスプライト化は確定（2026-10-13）。縮小後の解像度・形式（WebP / PNG）・シート分割は未定で、第 5.3 の制約内で比較して決める。
- スプライトは CI で生成し、コミットしない（確定、No.11 回答、2026-10-13 第2回）。生成スクリプト（`scripts/` 等）の設計では、原本 `data/cards/` を入力に `src/img/cards_*` へ出力すること、ローカルと CI で同じ結果になる決定的な生成（ライブラリのバージョン固定）、`.gitignore` への登録を満たす。
- 裏向きカード（ストップ時に引くカード）は山札から実際に取り除いて裏向きで重ねる（確定、2026-10-13 第3回）。その表示方法を T-006 と協議して決める（準備したカードではなく、中身は無効。No.7 の訂正、2026-10-13 第2回）。

## エッジケース

- Studio が初期生成する非推奨ファイル（`states.inc.php` など）が残っていると、状態クラスと二重定義になる可能性がある。初回同期の前に、Studio の初期ファイルをいったんダウンロードして `src/` に取り込み、残す／消すを判断する（【提案】、未確定事項 B の No.3）。
- 同期で `gameinfos.jsonc` などの JSON 設定が不正だと、Studio 側のリロード操作で初めてエラーに気づく。CI の JSON 検査で先に防ぐ。
- SSH 鍵を登録した瞬間にパスワードが無効になる。CI の鍵が未設定の状態で手元の SFTP がパスワードのままだと接続できなくなる。登録の順序に注意する。
- 画像の削除は SFTP で消すだけでは完結せず、コントロールパネルでの追加操作が要る（FileRef）。スプライトを差し替えて旧画像を削除する場合は手動操作が発生する。

## 未確定事項

### A. 公式ドキュメントで確認できなかった事項

| No. | 事項 | 状況 |
| --- | --- | --- |
| U1 | 名前空間の大文字小文字（`Bga\Games\minimumjourney` か `Bga\Games\MinimumJourney` か） | Migration は `Bga\Games\YourGameName` としか書かない |
| U2 | `modules/js/` 内の複数ファイルを相対 `import` で束ねられるか | 記述なし。`importEsmLib` はライブラリ向けとして確認 |
| U3 | Studio が新規プロジェクトで実際に生成する初期ファイルの正確な一覧 | FirstSteps は旧ファイルも列挙しており、現行の生成内容と一致するか不明 |
| U4 | bga-cards でカードを横向き（90 度回転）にする具体的 API | BgaCards は「回転に対応」としか確認できず、API 名は未確認 |
| U5 | SSH 鍵アップロード手順の画面上の詳細、ホスト鍵フィンガープリント、同時接続数・帯域の制限、GitHub-hosted runner からの接続可否 | 記述なし |
| U6 | JSON 設定ファイルの正式な拡張子（`gameoptions.json` と `gameoptions.jsonc`、`stats.json` と `stats.jsonc`） | ドキュメント間で表記が揺れている（FileRef は `.jsonc`、Options/Stats ページと Migration は `.json`） |
| U7 | 画像 1 枚あたりの上限（2 MB か 4 MB か） | Img は 2 MB、Checklist は 4 MB。本仕様書は 2 MB を採用 |
| U8 | 同期後に Studio 側の再読込を自動化する公式手段 | 記述なし |
| U9 | フレームワークのスタブ（静的解析用）の公式配布 | `_ide_helper.php` のみ確認 |
| U10 | PHP_8_upgrade ページの取得結果に「PHP 8.8」という誤記があり、そのページ単独では PHP バージョンを確認できなかった | PHP 8.4 は Studio ページと FAQ で確認 |

### B. 作者への質問

| No. | 質問 | 暫定案 | 回答 |
| --- | --- | --- | --- |
| 1 | クライアントは素の JS＋ JSDoc 型チェックで進めてよいか。TypeScript にしたいか | 素の JS ＋ `// @ts-check`（第 3.2 節） | 「それでも構いません」（2026-10-13）。素の JS ＋ JSDoc ＋ `// @ts-check` で確定 |
| 2 | CSS は素の CSS でよいか（SCSS は使わない） | 素の CSS | 「CSSはお任せします」（2026-10-13 第2回）。提案どおり素の CSS（SCSS は使わない）で確定 |
| 3 | Studio が最初に生成する非推奨ファイル（`states.inc.php`、`material.inc.php`、`action.php`、`view.php`、`.tpl`）を、初回同期後に削除してよいか。最初に Studio から初期ファイルをダウンロードして確認してよいか | ダウンロードして取り込み、非推奨ファイルは削除 | |
| 4 | 初期生成ファイルの実際の名前空間・クラス名を、そのまま採用してよいか | Studio の生成物に合わせる | |
| 5 | JSON 設定の拡張子は、Studio が生成したものに合わせてよいか | 生成物に合わせる | |
| 6 | 約 58 種のカードの扱い（マテリアル）は、PHP の `material.inc.php`（非推奨）ではなく PHP の定数／クラスで持ってよいか | `modules/php/` 内の PHP クラスで持つ（T-002・T-008 で確定） | |
| 7 | ストップ時の裏向きカードや準備場の裏向きカードの券面は、他のプレイヤーに公開されるか。山札の順序以外に秘匿が必要な情報はあるか（ルールブックの記述で判断できない場合） | 判断保留。ルール確定（T-001）後に T-007 で決める | 一部回答（2026-10-13）。「引いたカードは準備場に置きます。枚数に数えます。全員ストップなら引きません」。**第2回（2026-10-13）で訂正**: 「裏向きカードの中身は無効です。裏向きカードは準備したカードとみなされません。先程の回答は横向きカードと勘違いしました。」→ 裏向きカードは準備したカードではなく、枚数に数えず、中身は無効。全員ストップの回は引かない（この部分は有効）。横向きカードは枚数に数える。残る論点: 裏向きカードがラウンド中に山札から離れるのか（山札の一番上が次のカードに変わるのか。暫定案: デジタル実装では引かず山札の上に残っているものとして扱う）、デジタル実装で裏向きカードを表示するか（演出のみか。T-006 / T-009 で決める）、券面を他プレイヤーに見せるか、山札の順序以外に秘匿が必要な情報はあるか **第3回回答（2026-10-13）**: 「はい、離れています。そのため、山札の上に残りません。」「アナログ同様、山札の上から引いて、裏向きで重ねる。」ストップ時の裏向きカードは実際に山札の上から取り除き、裏向きで重ねて持つ（山札の一番上は次のカードになる）。デジタル実装でも山札から取り除く必要がある（暫定案だった「引かずに山札の上に残す」は不採用）。 |
| 8 | 横向きカード（無効カード）の表現は、カードを 90 度回転させる形でよいか | 回転表示 | |
| 9 | 離脱プレイヤー（zombie）の既定行動は「ストップ」でよいか | ストップ。効果の選択は無難な選択肢を自動選択 | |
| 10 | お守りの面選択は、ゲーム開始前のオプションか、ゲーム中の選択か | ルール確定後に決める | 「テーブル設計でオプション選択可能。デフォルトは航路安全のお守り。」（2026-10-13 第3回、仕様書 001 の Q-3 回答）。ゲーム開始前のゲームオプション（`gameoptions`）で確定。初期所持者はランダム |
| 11 | スプライト生成物を `src/img/` にコミットしてよいか（CI で生成せず） | コミットする（第2回で逆に確定: コミットしない） | 「スプライトはCIで作成しましょう。」（2026-10-13 第2回）。コミットせず CI で生成して Studio に同期すると確定（従来の暫定案と逆）。なお 2026-10-13 第1回の回答は縮小とスプライト化の依頼のみ（「画像容量は縮小とスプライト化お願いします」） |
| 12 | Studio へ SSH 鍵を登録してパスワード認証を無効化してよいか（CI は鍵認証の想定） | 鍵認証へ移行 | 「はい、大丈夫です」（2026-10-13）。SSH 鍵登録とパスワード認証の無効化を了承。鍵認証で確定 |
| 13 | ローカルに PHP が無い問題について、手元での PHP 確認用に Docker を使う運用を許容するか | 許容しない（CI のみで検証） | |
| 14 | BGA のゲーム公開（アルファ以降）に、権利者の許諾や BGA との契約が絡む。この点は作者側で別途確認済みか | 本仕様書の範囲外。作者が BGA の公開手順を確認する（Guidelines は著作権・ライセンスに触れていない） | |

## 受け入れ条件

- [ ] 各【確認】の記述に公式ドキュメントの URL が添えられている
- [ ] 確認できなかった事項が「未確定事項」の Aに列挙されている
- [ ] `src/` の構成案が BGA 側のパスと 1 対 1 で対応している
- [ ] 言語・ツール方針ごとに推奨案が 1 つ示され、理由が書かれている
- [ ] 要件ごとのフレームワーク機能の対応表がある
- [ ] SFTP のホスト・ポート・認証方式が記載され、認証情報そのものは含まれていない
- [ ] T-004 / T-005 / T-006 への申し送りがある
- [ ] 「未確定事項」の Bの質問に作者が回答し、回答に合わせて本仕様書が更新されたうえでステータスが Approved になる
