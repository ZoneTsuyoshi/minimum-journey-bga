# 013: Public Alpha 審査の UI 指摘への対応

| 項目 | 内容 |
| --- | --- |
| ステータス | Approved |
| 対応タスク | T-013 |
| 作成日 | 2026-11-18 |
| 関連仕様書 | 009（UI・アセット設計）、001（ゲームルール）、003（BGA 構成・技術方針）、005（Studio 自動同期）、006（状態遷移）、007（データモデル・通知） |

## 目的

BGA の Public Alpha 審査で「UI/UX が公開アルファに達していない」という指摘を受けた（原文は下の「審査の指摘（原文）」）。指摘を 8 項目（R1〜R8）に分け、BGA 公式の UX ガイドラインと現在の実装に照らして原因を特定し、直し方を決める。承認後、別の PR（I-14〜I-17）で実装し、作者が Studio で確認してから再申請する。

009 の画面設計（§7.4・§8・§9・§11、未確定事項 No.14）の一部を、この仕様書で変更する。

## スコープ

- 対象: Action Bar のボタン、画面構成（配置）、情報の置き場所、プレイヤーの領域、見た目（卓上の表現）、背景、レスポンシブ、アクセシビリティ、検証方法、実装の分け方。
- 対象外: ルール（001）、サーバー（PHP）の変更（不要の見込み）、カード画像・スプライト（009 §3〜§5）、拡大表示とツールチップの仕組み（009 §9.5）、準備場の重ね方（送り 0.58。009 §8.2）、探査コストのバッジ（009 §13 で未実装のまま。別タスク）、チュートリアル（012）。

## 記法と調査の前提

- 記法は 009 と同じ。**【確認】** は公式ドキュメント（または公式 PDF）の原文で確認した事実、**【実測】** は本タスクで実際に計測した値、**【提案】** は本仕様書の提案（色の値・余白などの数値はプレビューで調整してよい基準値）、**【推定】** は実測できず推定した値、**【未確認】** は確認できなかった事項。コードの事実は「ファイル名:行番号」で示す（2026-11-18 時点の `main`）。
- 調査日は 2026-11-18。審査者が示した Wiki（BGA_Studio_Guidelines）は、冒頭に「新しいドキュメントへ移転」とある公式 PDF の非公式な要約だった。ガイドラインの節番号（A.1 など）は Wiki と PDF で共通。本文は PDF のページ画像と Wiki の両方で読んだ。
- 審査者のスクリーンショット（原文の "image"）は受け取っていない。指摘は文章だけから読み取っているので、画面の具体的な印象は作者に確認する余地がある。

参照 URL（以降は略称で記す）:

| 略称 | URL |
| --- | --- |
| UxPdf | https://doc.studio.boardgamearena.com/assets/framework-documentation/guidelines/Guidelines_UX.pdf （公式 UX ガイドライン。新サイトのページ https://doc.studio.boardgamearena.com/framework-documentation/guidelines/ はこの PDF へのリンクだけ） |
| Guidelines | https://en.doc.boardgamearena.com/BGA_Studio_Guidelines （審査者が示した Wiki。UxPdf の要約） |
| StatusBar | https://doc.studio.boardgamearena.com/framework-documentation/front-end/game-interface-logic/status-bar/ |
| PlayerPanels | https://doc.studio.boardgamearena.com/framework-documentation/front-end/game-interface-logic/player-panels/ |
| GameArea | https://doc.studio.boardgamearena.com/framework-documentation/front-end/game-interface-logic/game-area/ |
| CssPage | https://doc.studio.boardgamearena.com/framework-documentation/front-end/game-interface-stylesheet/ |
| JumpTo | https://doc.studio.boardgamearena.com/framework-documentation/front-end/libs/bga-jump-to/ |
| GameArt | https://doc.studio.boardgamearena.com/framework-documentation/front-end/game-art/ |
| Mobile | https://en.doc.boardgamearena.com/Your_game_mobile_version |

## 審査の指摘（原文）

```text
The game is functional, but the current interface still needs a broader UX/UI pass before Public Alpha.

Please review the button color semantics in the Action Bar.
The current “Draw” / “Stop” actions do not communicate their meaning strongly enough through standard BGA conventions.


Please use the expected hierarchy consistently:

blue for normal forward/progression actions;
red for stop/cancel/destructive actions;
white for secondary actions;
grey for unavailable actions.
The overall composition is not properly centered or constrained.
On wide screens, the game remains anchored to one side with a very large amount of unused space.image
Please use a centered composition with sensible max-width limits so related components stay visually connected.

image

There is too much redundant information in the player areas.
Deck size, preparation values, counters, labels and card-state information are spread across several places.


Please simplify the persistent information and avoid repeating values unless each location serves a clearly different gameplay purpose.

The player areas themselves are too tall and too empty.
The current vertical layout creates a large amount of unused space below the actual components.image
Please make these sections content-driven rather than reserving large fixed-height columns.

Please make the actual game components the primary interaction layer.
The current presentation still feels too much like a collection of interface panels around the cards rather than a board-game adaptation.
A proper thematic background would help the presentation.
The current standard wood background makes the implementation feel unfinished and does not support the game’s visual identity.
Please perform a full responsive pass.
The widescreen behavior already shows that the current structure does not adapt naturally to viewport changes. The same hierarchy needs to remain clear on mobile without simply shrinking the desktop layout.
Please review the Studio Guidelines carefully, especially:

A.1 — Layout arrangement
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#A.1_Layout_arrangement
A.2 — Action Bar
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#A.2_Action_Bar
A.3 — Player panels
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#A.3_Player_panels
A.5 — Clarity & Zoom
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#A.5_Clarity_&_Zoom
C.3 — Button colors and styling
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#C.3_Button_colors_and_styling
C.4 — Sizing & responsiveness
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#C.4_Sizing_&_responsiveness
C.5 — Background & extras
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#C.5_Background_&_extras
E.1 — Design for all
https://en.doc.boardgamearena.com/BGA_Studio_Guidelines#E.1_Design_for_all
Not ready for Public Alpha yet. Please fix the Action Bar semantics, center and constrain the layout, reduce redundant information, remove oversized empty player areas, and complete a proper responsive/thematic pass before resubmitting.
```

審査者のスクリーンショットは受け取っていない。指摘を次の 8 項目に分ける（日本語の要旨）。

| 番号 | 要旨 |
| --- | --- |
| R1 | Action Bar のボタンの色。Draw / Stop が BGA の慣例で意味を伝えていない。青 = 進む、赤 = 停止・取消・破壊的、白 = 二次的、灰 = 使えない、で統一する |
| R2 | 中央寄せと最大幅。広い画面で片側に寄り、大きな空白ができる。中央に寄せ、関連する部品がつながって見える最大幅を設ける |
| R3 | 情報の重複。山札の枚数、準備の値、カウンター、ラベル、カードの状態が複数箇所に散らばる。常時表示の情報を減らし、役割が違わない限り同じ値を繰り返さない |
| R4 | プレイヤーの領域が縦に長く空っぽ。固定の高さを確保せず、内容に合わせた高さにする |
| R5 | 部品を主役に。カードの周りに UI パネルを並べた印象で、ボードゲームの翻案らしくない。盤面の部品を主な操作の場にする |
| R6 | 背景。BGA 標準の木目は未完成に見え、ゲームの見た目に合わない。テーマに沿った背景にする |
| R7 | レスポンシブ。広い画面で既に破綻している。モバイルでデスクトップ版を縮めるだけでなく、同じ階層が分かるようにする |
| R8 | ガイドライン A.1・A.2・A.3・A.5・C.3・C.4・C.5・E.1 を確認する |

## 仕様

### 1. 指摘・ガイドライン・現状の原因の対応表

ガイドラインの節は UxPdf / Guidelines の番号。**【確認】** 以下の「現状の原因」は、2026-11-18 の `main` のコードを読んで確認した事実（行番号つき）。

| 指摘 | 該当ガイドライン | 現状の原因（事実） | 本仕様の節 |
| --- | --- | --- | --- |
| R1 ボタンの色 | A.2、C.3（「必須」と明記）、B.2 | `src/modules/js/Game.js:104` の `PrepChoice.addChoiceButton` が `color: this.myChoice === choice ? 'primary' : 'secondary'`。選ぶ前は Draw も Stop も白、選んだ方だけ青になり、Stop は一度も赤にならない。`PrepChoiceLate`（049 の後選び。`Game.js:131-138`）は色を渡しておらず、既定の primary（青）で両方青。009 §9.1 は「[Draw]（青、primary）・[Stop]（赤、alert）」と定めており、実装が仕様からずれていた。探査の [Cancel]・[Pass]（`Game.js:323-328`）は白（009 §9.2 は [Pass] を白と定めていた）。ほかにも、選択前の確定ボタンを出さない状態が多い（§2.4） | §2 |
| R2 中央寄せ・最大幅 | A.1、C.4 | `.mj-board`（`src/minimumjourney.css:27-35`）は `margin: 0 auto` だが最大幅がない。探査場（`#mj-explore.mj-scope`、`Game.js:1471`）は全幅の箱で、中身は左詰め。プレイヤーの列は `grid-auto-columns: minmax(0, 1fr)`（`minimumjourney.css:84-91`）で全幅に広がり、各列の中身も左詰め | §3 |
| R3 情報の重複 | A.1（「関連する情報を 3 つの隅に散らさない」）、A.3 | `statsHtml()`（`Game.js:1860-1875`）が同じ数値（準備枚数・探査出力・山札の枚数・お守り・名声・選択済み・準備終了・探査手番）を、領域の見出し（`Game.js:1630-1634`）とプレイヤーパネル（`Game.js:1696`）の 2 か所に出している（009 §8.3 の設計）。山札の枚数は山のバッジ（`Game.js:1669-1670` の `counter`）でも出ており 3 か所。山の下にラベル「Deck」「Face-down」「Charm」「Acquired this round」（`Game.js:1638-1653`）、探査場の見出し「Exploration field」（`Game.js:1548`）、未確認帯域の下のコスト札「10」（`Game.js:1549-1552`。カードに印刷済みのコストと重複）、コスト 8 の山が尽きたときの独自の警告の箱 `#mj-end-warning`（`Game.js:1553`、`Game.js:1610-1619`） | §4 |
| R4 縦に長く空っぽ | A.1、C.4 | 中身がなくても場所を取る。裏向きの山（0 枚でも点線の枠とバッジ「0」）、準備場（`min-height` 1 枚分＋`padding-bottom` 0.42 枚分。`minimumjourney.css:183-185`）、お守りの枠（所持者以外も全員。`minimumjourney.css:321-330`）、獲得カード（`min-height` 1 枚分・`min-width` 1.5 枚分。`minimumjourney.css:321-330`、`minimumjourney.css:458-460`）、各ラベル。ラウンド開始時は山札しか中身がないのに、1 人分が縦に約 850px（カード 120px での **【推定】**。見出し約 30＋山の行約 196＋準備場約 239＋お守りの行約 196＋獲得約 185＋余白） | §5 |
| R5 部品が主役でない | A.1（「透明な区画でまとまりを示す」）、A.2（「部品でできる操作をボタンだけで置き換えない」） | 各領域が `.mj-scope`（白 55% の背景、灰色の枠、角丸。`minimumjourney.css:48-58`）の箱で、見出しとラベルが付く。準備の選択はボタンだけでできる（山札を押す操作がない） | §2.5、§6 |
| R6 背景 | C.5 | 背景を指定しておらず、BGA 既定の木目（`html` 要素に付く）のまま（CSS に `body` や `html` の背景指定なし）。009 の未確定事項 No.12 で「背景は暗い宇宙テーマ（ぼかした画像。軽量）」が確定済みだが未実装 | §7 |
| R7 レスポンシブ | C.4、A.5、Mobile | `src/gameinfos.jsonc:82-86` の `game_interface_width.min` が既定の 740 のまま。これより狭い画面では BGA が CSS zoom で縮小する（390px なら約 53%）＝デスクトップ版を縮めた表示。009 §12 は「狭い画面向けの配置が成立する最小の幅（仮に 360 前後）を実機で測って宣言する」としていたが未実施。820px 未満ではプレイヤーを全幅で縦に積むが、中身は 2 枚幅なので右側が空く（`minimumjourney.css:76-91`） | §3、§8 |
| R8 ガイドラインの確認 | A.1・A.2・A.3・A.5・C.3・C.4・C.5・E.1 | 上記と、アクセシブルな名前の不足（ラベルを消す §4 とあわせて §9 で補う） | §9、§12 |

### 2. Action Bar（A.2・C.3・B.2。R1）

#### 2.1 色の規則

**【確認】** C.3 は色を「BGA 共通の言語。必須」とする（青 = 進む・確定、赤 = 取消・やめる・止める・出る、白 = 任意・脇の操作、灰 = 無効・完了・使えない）。ゲーム固有の意味で青・赤・灰を再定義しない、テーマに合わせてボタンを着色しない、とも書かれている（UxPdf C.3）。`statusBar.addActionButton` の `color` は `primary`（青、既定）・`secondary`（白）・`alert`（赤）、`disabled: true` で灰（StatusBar）。

| 色 | `color` | 使う場面 |
| --- | --- | --- |
| 青 | `primary` | 進める・確定する主な操作（ドロー、探査、選んだ対象での効果の実行・確定） |
| 赤 | `alert` | 止める・取り消す・やめる（ストップ、パス、キャンセル） |
| 白 | `secondary` | 任意・脇の操作（任意効果を使わない「Do not …」「Keep …」、効果を使い終える「Done」） |
| 灰 | `disabled: true` | 今は使えない、または選び済み（完了） |

- 色だけに頼らず、ボタンは必ず文字ラベルを付ける（E.1）。

#### 2.2 並び

**【確認】** A.2: 主な操作を中央に、二次・無効の操作をその隣に、取り消し系を右に離して置く。取り消しが主な操作と競合して見えないようにする。ボタンは「最大 4 個＋文脈」。BGA の組み込みボタン（リプレイの操作など）の隣に独自のボタンを寄せない。

- 青 → 白 → 赤 の順に追加し、取り消し系（赤）は右端にする。
- 1 つの状態で出すボタンは 4 個まで。§2.4 の変更後は、どの状態も 2 個以内になる。状態 42 の [Local Map: …] は使えるカードの数だけ出る（`Game.js:637-645`）が、探査時の効果を持つカードは 011 広範囲レーダーと 013 局所的な地図の 1 枚ずつしかない（`src/modules/php/Material/CardDefinitions.php:188-196`、`src/modules/php/Flow/ExplorePhase.php:268-293`）ので、最大 1 個。
- BGA の組み込みボタンには寄せない（独自ボタンは `statusBar.addActionButton` だけで追加する）。

#### 2.3 ドロー／ストップ（状態 21 `PrepChoice`）

- [Draw] は常に青、[Stop] は常に赤。選ぶ前も選んだ後も変えない。
- 選択済みの表示: 選んだ方を「✓ Draw」「✓ Stop」の灰（`disabled`。C.3 の「完了」）にし、もう一方は本来の色のまま押せる。押すと選択が切り替わる（全員が選び終えるまで変更可能。009 §9.1・未確定事項 No.6 のまま）。
- タイトルの文言（"You chose to draw. You can change your choice until everyone has chosen" など。`Game.js:79-84`）は現状のまま。
- 049 の後選び（状態 23 `PrepChoiceLate`）も [Draw] 青・[Stop] 赤。山札が 0 枚なら [Draw] は灰にする（灰にするのは既存の挙動。`Game.js:131-135`、状態 21 は `Game.js:87`）。押せない理由（"You have no cards left in your deck" など）をツールチップに出すのは新規（§2.4 の下の注記と同じ）。
- 強制されたとき（054・028・029・山札 0 枚）はボタンを出さない現状のまま（009 §9.1）。

#### 2.4 全ボタンの対応表

`Game.js` の状態クラスから、`addActionButton` を呼ぶ箇所を漏れなく洗った（状態 ID は `src/modules/php/Flow/StateId.php`）。「未選択の間は灰」は、確定ボタンを選択前から出して押せない状態にすることを指す（B.2「存在するが今は使えない操作は灰にする」）。

| 状態 | クラス | ボタン | 現在 | 変更後 |
| --- | --- | --- | --- | --- |
| 21 | `PrepChoice` | Draw | 白（選択時に青） | 青。選び済みは灰「✓ Draw」 |
| 21 | `PrepChoice` | Stop | 白（選択時に青） | 赤。選び済みは灰「✓ Stop」 |
| 23 | `PrepChoiceLate` | Draw | 既定の青 | 青（山札 0 枚は灰） |
| 23 | `PrepChoiceLate` | Stop | 既定の青 | 赤 |
| 43 | `ExploreChoose` | Explore | 選択時だけ青 | 青。未選択の間は灰で出す（ツールチップ「探査するカードか山札を先に選ぶ」の意） |
| 43 | `ExploreChoose` | Cancel | 白（選択中だけ） | 赤（選択中だけ。右端） |
| 43 | `ExploreChoose` | Pass | 白・常時 | 赤。未選択のときだけ出す（選択中は Cancel だけを赤にして、赤の意味を 1 つにする。パスしたいときは Cancel してからパス）。対象がないときの [Pass] だけの表示も赤 |
| 31 | `ReturnPodDecision` | Return my drawn card | 青 | 青 |
| 31 | `ReturnPodDecision` | Keep my drawn card | 白 | 白 |
| 32 | `RowCardChoice`（`CopySourceChoice`） | Copy the selected card | 選択時だけ青 | 青。未選択は灰 |
| 34 | `RowCardChoice`（`LaserSightChoice`） | Reserve the selected card | 選択時だけ青 | 青。未選択は灰 |
| 33 | `WormholeChoice` | Swap with the selected card | 選択時だけ青 | 青。未選択は灰 |
| 33 | `WormholeChoice` | Do not swap (shuffle only) | 白 | 白 |
| 42 | `ExploreOnExploreChoice` | Wide-Area Radar: add the top card of the … pile to the row | 青 | 青 |
| 42 | `ExploreOnExploreChoice` | Cancel（山札を選んだ後） | 白 | 赤 |
| 42 | `ExploreOnExploreChoice` | Local Map: exclude it to lower all explore costs by 1 this turn | 既定の青 | 青 |
| 42 | `ExploreOnExploreChoice` | Done | 白 | 白 |
| 45 | `PrepExcludeChoice`（`AcquireExcludeChoice`） | Exclude the selected card | 選択時だけ青 | 青。未選択は灰 |
| 51 | `PrepExcludeChoice`（`AmuletExcludeChoice`） | Exclude the selected card | 選択時だけ青 | 青。未選択は灰 |
| 51 | `PrepExcludeChoice`（`AmuletExcludeChoice`） | Do not exclude | 白 | 白 |
| 46 | `FreeAcquireChoice` | Acquire the selected card for free | 選択時だけ青 | 青。未選択は灰 |
| 47 | `DeckExcludeChoice` | Exclude the selected cards | 選択時だけ青 | 青。未選択は灰 |
| 47 | `DeckExcludeChoice` | Do not exclude | 白 | 白 |
| 35 | `SimultaneousEffects`（arrangeTop） | Put the selected card on top | 選択時だけ青 | 青。未選択は灰 |
| 35 | 同（arrangeTop） | Keep this order | 白（ただし 1 枚選ぶと消える。`Game.js:1008-1017`） | 白（選択後も残す） |
| 35 | 同（topOrBottom） | Put it back on top | 青 | 青 |
| 35 | 同（topOrBottom） | Put it on the bottom | 白 | 白 |
| 35 | 同（pickOne） | Select a card above to put on top（白のヒントボタン） | 白 | 廃止。「Put the selected card on top」を未選択の間は灰で出す。何を選ぶかはタイトルと選択パネルの見出しで伝える |
| 35 | 同（pickOne） | Put the selected card on top | 選択時だけ青 | 青。未選択は灰 |
| 35 | 同（pickFromDeck） | Put the selected card on top | 選択時だけ青 | 青。未選択は灰 |
| 35 | 同（pickFromDeck） | Do not pick (shuffle only) | 白 | 白 |
| 35 | 同（yesNo） | Return and redraw | 青 | 青 |
| 35 | 同（yesNo） | Do not return | 白 | 白 |

- 除外（破壊的に見える操作）の確定ボタン（状態 45・47・51 の「Exclude …」）を赤にしない理由: それは効果が求める主な前進の操作（確定）であり、C.3 の赤は「取り消し・止める・やめる」を指すため。取り消し系（パス・キャンセル）だけを赤にする。
- 灰のボタンのツールチップに、押せない理由（「Select a card first」などの短い英文。`_()` で翻訳）を付ける（D.1「失敗は理由を言う」）。

#### 2.5 盤面の部品からの操作（A.2。R5）

**【確認】** A.2: 盤面の部品でできる操作を、Action Bar のボタンだけで置き換えない（UxPdf A.2）。

- 準備の選択中（状態 21・23）、自分の山札（デッキの山）を押すと [Draw] と同じ選択になる（山札が 0 枚なら押せない）。押せることは選択可能の見た目（既存の `mj-selectable` 系）で示す。ボタンは残す（ストップに対応する部品はない）。→ 未確定事項 No.6
- 状態 42 で、強調表示した 013 局所的な地図のカードを押すと、その効果を使う（ボタンも残す）。
- 探査の対象（列・未確認帯域・探査山札）を押して選ぶ現状は維持する。

### 3. 画面構成（A.1・C.4。R2・R7）

**【確認】** A.1: 遊びの中心を中央に置き、盤面の周りに操作のない余白を残す。C.4: メインの領域は全デバイスで中央、固定幅にしない、デスクトップ・タブレットでは盤面全体を一度に見せることを優先、モバイル（縦）では二次的な要素を主な操作の下に積む（UxPdf A.1・C.4）。

#### 3.1 3 つの配置

```text
wide:      [ EXPLORATION FIELD ] [ YOU ][ B ][ C ][ D ]      全体を中央寄せ

standard:            [ EXPLORATION FIELD ]
               [ YOU ][ B ][ C ][ D ]                       それぞれ中央寄せ

narrow:    [ EXPLORATION FIELD ]
           [ YOU ][ B ]
           [ C   ][ D ]
```

| 配置 | 構成 |
| --- | --- |
| 広い（wide） | 左に探査場、右にプレイヤーの領域を人数ぶん横 1 行。全体を中央寄せ |
| 標準（standard） | 探査場（中央寄せ）の下に、プレイヤーの領域を横 1 行（中央寄せ）。2026-10-17 の作者指示「PC なら 4 人の準備場が横にある方が見やすい」（009 §7.4）を満たす |
| 狭い（narrow。スマホ縦など） | 探査場の下に、プレイヤーの領域を 2 列（4 人は 2×2、3 人は 2＋1）。並びは自分が先頭、席順（観戦者は席順のまま） |

- 広い配置の目的: 1920×1080 の PC で盤面全体（探査場と全員の準備場）をスクロールなしで見られるようにする（C.4「デスクトップでは盤面全体を一度に見せることを優先」）。現状はカード 120px で探査場だけで高さ約 900px あり（**【推定】**。段 4 つ＋未確認帯域の 5 行×168px＋見出し・余白）、プレイヤーの領域は画面の外になる。
- ただし 009 §7.4 の「探査場は常にプレイヤーの上」（作者指示 2026-10-17）を変える。作者が了承した（2026-11-18。未確定事項 No.2）。

#### 3.2 配置とカード幅の決め方【提案】

数値は I-14 のプレビューで調整してよい基準値。

- 記号: W = 盤面（`.mj-board`）の内側の幅、n = 人数、S = 探査場の 1 段の最大スロット数（4 人 4、3 人 3。011 で増えたら増やす）、c = カード幅（≤ 120px。高さは c × 419 / 300）。
- 探査場の幅 E(c) ≈ (1 + S)·c + 44（山札＋S 枚＋余白）。プレイヤーの 1 列の幅 P(c) ≈ 1.5c + 22（裏向きの山を小さくする場合。No.3 が否なら 2c + 24）。k 列の幅 Row(k, c) = k·P(c) + (k − 1)·12。
- 判定は広い → 標準 → 狭いの順で、条件を満たす最初の配置を使い、c はその配置で収まる最大値（上限 120）にする。

| 配置 | 条件 |
| --- | --- |
| 広い | E(c) + 24 + Row(n, c) ≤ W かつ c ≥ 100 |
| 標準 | max(E(c), Row(n, c)) ≤ W かつ c ≥ 96 |
| 狭い | max(E(c), Row(2, c)) ≤ W（c の下限 48） |

- 広い配置では、高さも見る。探査場の高さ（未確認帯域と 4 段の 5 行ぶん。約 7c + 52）が、見えている高さ H（`window.innerHeight` から盤面より上にある BGA のトップバー・ステータスバーの高さを引いた値。実装時に測る）に収まるよう、c をさらに小さくする。ただし広い配置の下限（c = 100）より小さくはしない（収まらない分は少し縦にスクロールする）。標準・狭い配置では高さで c を抑えない（もともと縦に積むため）。例: 1920×1080 の画面で H が約 800px なら c は約 106px。
- 目安（4 人・裏向きの山を小さくした場合）: 広い配置は W ≥ 約 1300px（c = 100）、W ≥ 約 1510px で c = 120（高さで抑えない場合）。標準配置で c = 120 は W ≥ 約 844px、c = 96 は W ≥ 約 700px。3 人は広い配置が W ≥ 約 1010px（c = 100）から使える。
- 狭い配置のカード幅は、W を画面幅（余白なし）とすると 360px で約 63px、390px で約 69px（E(c) ≤ W から決まる）。BGA のゲーム領域の左右の余白で W が小さくなる分は **【未確認】**（Studio で測る。例えば W = 画面幅 − 16px なら 390px で約 66px、360px で約 60px）。
- 実装: `Game.js` が `ResizeObserver` で W を測り、盤面に `data-mj-layout="wide|standard|narrow"` と `--mj-w` を入れる。CSS はこの属性で並べ方を切り替える。現在の container query（820px・992px。`minimumjourney.css:76-91`）と `setupBoardWidth()`（`Game.js:1509` 以降）の `--mj-bw`・`--mj-pcw` の計算はこれに置き換える。ブラウザのズーム・画面の回転・ウィンドウの変更で再計算する。
- 注意: 広い配置への切り替わりの境目（W 約 1300px）では、標準配置の c = 120 から広い配置の c = 100 にカードが小さくなる。配置が変わるので許容するが、プレビューで違和感があれば広い配置の下限（c ≥ 100）を上げる。

#### 3.3 中央寄せと最大幅

- 盤面は常に中央寄せ。各まとまり（探査場、プレイヤーの行）は中身の幅で、空いた幅を左右に均等に残す。
- カード幅の上限（120px）で構成全体の幅は自然に上限を持つ（4 人の広い配置で約 1510px）。念のため `.mj-board` に `max-width`（例 1600px）を付ける。
- 盤面の周りに操作のない余白を残す（A.1）。ページ全体が横スクロールしないこと。

#### 3.4 3 人と 4 人の違い

S と n が変わるだけで規則は同じ（3 人: S = 3、n = 3。4 人: S = 4、n = 4）。3 人は幅が狭く済むので、広い配置を使える画面が増える。

### 4. 情報の整理（A.1・A.3・B.1。R3）

**【確認】** A.3: パネルは「手番順や獲得した資源をひと目で見る場所」で、コンパクトにする（4 人でもモバイルで画面の 1/4 以内）。A.1: 関連する情報を複数の隅に散らさない（UxPdf A.1・A.3）。

原則: **1 つの値は 1 か所に出す。** 役割が違うもの（強調と値など）は重複に数えない。

| 情報 | 現在の場所 | 変更後 | 理由 |
| --- | --- | --- | --- |
| 山札の枚数 | 山のバッジ・見出し・パネル | 山のバッジだけ | 部品の厚みと同じ情報で、比べる必要が低い |
| 裏向きの山の枚数 | 山のバッジ（0 でも表示） | 山のバッジだけ。0 枚なら山ごと出さない | §5.2 |
| 準備枚数・現在の探査出力（半減時の ÷2 を含む） | 見出し・パネル | パネルだけ | 全員を 1 か所で比べられる。A.3 はパネルを「手番順や獲得した資源をひと目で見る場所」とする。→ 未確定事項 No.4 |
| 選択済み（✓）、準備終了（■ Stopped / Drew alone）、探査手番（#N） | 見出し・パネル | パネルだけ | 同上。探査中の手番の人の領域は枠の強調と ▶ 印にする（どこを見ればよいかを示すためで、値の重複ではない） |
| お守りの所持 | 見出しのアイコン・パネルのアイコン・領域のカード | 領域のカードだけ | お守りのカード自体が所持者の領域にある。→ 未確定事項 No.5（009 No.14「パネルにも所持アイコン」の変更） |
| 名声（ゲームオプション 101 が有効のとき） | 見出し・パネル | パネルだけ | 表示の条件（009 §8.3、No.15）は変更しない |
| 自分の探査出力 | ステータスバーのタイトル | 現状のまま | A.2 の「ボタン＋文脈」の文脈なので重複に数えない |
| ラベル「Deck」「Face-down」「Charm」「Acquired this round」、見出し「Exploration field」 | 表示 | 表示をやめ、アクセシブルな名前とツールチップに移す（§9） | 部品の見た目だけで分かる。R5 |
| 未確認帯域の下のコスト札「10」 | 表示 | 削除 | カードにコストが印刷されている |
| 探査山札のコスト札（2 / 4 / 6 / 8） | 山の下の行 | 残す。山（裏面）からはコストが分からないため。山の左上に重ねる小さな札にして、下の行を無くす | R4 |
| コスト 8 の山が尽きたときの独自の警告の箱 | `#mj-end-warning` | BGA 標準のバナー `this.bga.gameArea.addLastTurnBanner(message)`（GameArea）に置き換える | 独自の箱を無くす。ページ読み込み時にコスト 8 の山が 0 枚でゲーム中なら出す。文言例 "This is the last round: the 8-cost exploration pile is empty"。ゲーム終了条件は 001 §9-1（尽きたラウンドで終了） |

A.1・A.3 には「いつでも見られるべき情報（得点・手番・目標）をパネル『だけ』に置かない」という注意もある（UxPdf A.1・A.3）。準備枚数と探査出力は得点や目標ではなく、全員の値を比べるための値なので、パネルに置く。手番は、探査中の手番の人の領域の強調（上の表）と、BGA 標準のパネルの手番表示でも分かる。自分の探査出力は、自分の探査手番中はステータスバーのタイトルにも出る。

パネルの 1 行の中身【提案】: [探査出力のアイコン＋値] [準備枚数（小さなカード形のインライン SVG アイコン＋値）] [状態: ✓ / ■ Stopped / Drew alone / #N] [名声（オプション時）]。各項目にツールチップとアクセシブルな名前を付ける。4 人でもモバイルで画面の 1/4 を超えない（A.3）。

### 5. プレイヤーの領域（R4）

#### 5.1 構成

上から: 名前 → [山札][裏向きの山] → 準備場（新しいカードが上。009 §8.2 のまま）→ お守り（所持者だけ。準備場の下は 2026-10-19 の作者指示のまま）→ このラウンドの獲得カード（1 枚以上のときだけ）→（ゲーム終了時）最終デッキ（§5.6）。

#### 5.2 中身がないものは場所を取らない

- 裏向きの山は 0 枚なら出さない。
- 準備場は 0 枚なら高さ 0（`min-height` と下の余白を外す）。
- お守りの枠は所持者だけ。
- 獲得カードは 0 枚なら出さない。
- ラウンド開始時の領域は「名前＋山札の行」だけの高さになる。
- アニメーションの注意: カードを移す先が非表示のままだと移動先の位置が取れないので、カードを加える直前に表示状態にする（裏向き 0→1、獲得 0→1、お守りの移動）。

#### 5.3 裏向きの山を小さく表示する

約 0.5 倍。山札の右横に並べ、枚数バッジとツールチップを付ける。中身は誰にも見えないので、小さくしても失う情報はない。009 §8.2 は「山札の右横に小さく」と書いていたが、§8.1 の表は全員同じ 120×168 としており、実装は全面サイズだった（`Game.js:1670`）→ 未確定事項 No.3。

#### 5.4 山札の一覧ボタン

ゲームオプション 102 の「View (N)」ボタン（009 §8.2.2。`Game.js:1763-1782`）は、山札の下の行をやめ、山札の右上に重ねるアイコンボタン（32×32px 以上、ツールチップ「View my deck (N)」）にする。

#### 5.5 名前の色

名前はプレイヤーカラーで出す。暗い背景で読みにくい色（黒・青・緑・紫など）には明るい縁取りを付ける（C.3・E.2）。領域の縁にプレイヤーカラーの細い帯を付けてよい。

#### 5.6 ゲーム終了時のデッキ一覧

009 §8.2.1 のデッキ一覧は、細い列の中では縦に長くなりすぎるので、卓の下に全幅の段としてプレイヤーごとにまとめて出す（同点処理の内訳の 1 行も一緒に）。中身・並び順は 009 §8.2.1 のまま。

#### 5.7 列の幅

列の幅は P(c) で固定する（裏向きの山が出たり消えたりしても隣の列が動かない）。横向きのカード（幅 1.4c）は列に収まる。046 の組（2 枚幅）がはみ出すときは準備場の中で横にスクロールする現状のまま（009 §7.4）。

### 6. 部品を主役にした見た目（A.1・R5）

- UI パネルの箱（白い背景・灰色の枠・見出し）をやめ、半透明の薄い「マット」（卓に敷いた布のような区画。例: 白 5〜8% の面と細い明るい縁、角丸）で、探査場・各プレイヤーの領域・本人にだけ見せる公開エリア（`#mj-choice`）をまとめる（A.1「透明な区画でまとまりを示す」）【提案】。
- 見出しとラベルは出さない（§4）。名前だけが領域の見出し。
- 暗い背景の上でカードが沈まないよう、カードに細い明るい縁と影を付ける。影は Safari の性能のため `drop-shadow` でなく `box-shadow` を使う（CssPage）。
- 選択可能・選択中・選択不可の見た目（枠の太さ・「+」・暗さ。009 §9.2）は形を保ったまま、暗いマットの上で見える色に調整する。

### 7. 背景（C.5。R6）

**【確認】** C.5: 背景は卓の表面。テーマに沿った現代的な背景で、わずかな質感と少しのぼかしで部品を主役にし、部品とのコントラストを優先する。単色は避ける。派手で細かい背景は避ける。A.1: ゲームの部品でないロゴやブランド要素を入れない（UxPdf A.1・C.5）。

009 の未確定事項 No.12 で「暗い宇宙テーマ」が確定済みなので、ここで実装する。

#### 7.1 素材の案

| 案 | 内容 |
| --- | --- |
| A（推奨） | 作者提供のバナー `data/banner/thumbnail_banner.png`（3930×1134）の、箱・カード・ロゴを含まない宇宙の部分を切り出し、ぼかして暗くし、WebP で `src/img/background.webp` を生成する。**【実測】** 右端の約 x ≥ 2850px の範囲は星雲と惑星（x ≈ 2750〜2790 にカードの角が入るので避ける）、左端の約 x < 950px は星雲と小さな衛星。生成は既存の `scripts/build-sprites.mjs`（sharp）に足し、スプライトと同じく生成物はコミットしない（`.gitignore`、`check-sprites.mjs` で容量を検査。目安 300KB 以下、上限 2MB、4096px 以下。GameArt）。`data/` は原本なので変更しない。005 の同期ワークフローの `paths` に `data/banner/**` を足す |
| B | 画像を使わず、CSS のグラデーションと細かい星の模様だけで作る（読み込みなし。質感は弱い） |
| C | 作者が背景専用の画像（文字・ロゴなし、横 1920px 以上）を用意し、案 A と同じ手順で変換する |

→ 未確定事項 No.1

#### 7.2 CSS

- `body` に背景を指定する（BGA の既定の背景は `html` にあり、`body` の背景がそれを覆う。CssPage）。基本色 `#0b0e1a` 程度を下に敷き、`cover` にする【提案】。
- スクロールしても画面を覆い、モバイルで極端に引き伸ばされない方法にする（例: `body::before` を `position: fixed` で敷く）。
- ライト・ダークの両方で同じ暗い背景を使い、盤面の文字色は暗い背景に合わせて固定する（背景色を指定した要素には文字色も指定する。CssPage の注意）。拡大表示・山札一覧のオーバーレイは現状どおりテーマに従う（009 §11）。
- ライトテーマで BGA 側の要素（ログ・パネル・ゲームの下の欄）が読めることを Studio で確かめ、読めなければライト用に明るめの版を作る。

### 8. レスポンシブ（C.4・A.5・Mobile。R7）

- `game_interface_width.min` を 740 → 360 にする（`autoscale` は既定の true のまま。360px 未満の端末だけ縮小される）。**【確認】** Mobile は「宣言した幅で本当に使えること」「自動縮小は狭い画面への対応の代わりにならない」と明記している。360px で §3 の狭い配置が成立し、文字・ボタンが縮小されないことを確認する。
- 狭い配置での並び: ステータスバー → 探査場 → 自分 → 他のプレイヤー（C.4「モバイルでは二次的な要素を主な操作の下に積む」）。BGA はモバイルでプレイヤーパネルを盤面の上に移す（`mobile_version` クラス。Mobile）。
- ジャンプリンク: bga-jump-to（1.x。JumpTo）で「Exploration field」と各プレイヤーの領域へ移れるようにする（A.3・C.4）。lib の説明は「画面に収まらない盤面が多いときだけ使う」なので、広い配置で全体が収まるときは出さない。出し分けの方法は実装時に lib の API で確認する。
- タップ領域は 32×32px 以上（E.3。ボタンは 40〜44px を目安）。360px でもカード幅は約 60〜63px（§3.2）。
- ズームボタンは付けない（009 No.13 のまま。A.5）。ブラウザのズームで崩れないこと。

### 9. アクセシビリティ（E.1・E.2・E.3）

- ラベルをやめた部品（山札・裏向きの山・お守り・獲得カード・探査山札・未確認帯域）に、アクセシブルな名前とツールチップを付ける。例: "Your deck (12 cards)"、"Exploration pile: cost 4, 9 cards left"、"Face-down cards drawn when stopping: not counted, not revealed"。必要な情報をホバーだけにしない（タッチで開けるツールチップ。Mobile）。BGA のツールチップの API は実装時に公式ドキュメントで確認する。
- 文字とアイコンは背景・マットに対して 4.5:1 以上（E.1）。バッジも同様。
- 色だけに頼らない（ボタンの文字、✓、選択の枠の形、横向きの斜線、錠前）。点滅させない（E.1）。

### 10. 009 との関係

| 009 の該当箇所 | 009 の内容 | 013 での変更 |
| --- | --- | --- |
| §7.4 | 探査場は常にプレイヤーの上。1088 / 992 / 820px の段階、`--mj-pcw` | §3 の 3 配置と §3.2 の決め方に置き換える（No.2） |
| §8.1 | カードは全部 120×168（裏向きの山を含む） | 上限 120 は同じ。幅の決め方は §3.2。裏向きの山は No.3 |
| §8.2 | 探査山札のコスト札、裏向きの山、お守り、獲得カードの表示 | §4・§5 |
| §8.2.1 | 終了時のデッキ一覧の位置（各領域の最下部） | §5.6（卓の下の全幅の段） |
| §8.3 | パネルと見出しに同じ数値 | §4（1 か所に出す） |
| §9.1 | ボタンの色（Draw 青・Stop 赤。実装がずれていた） | 色は同じ。選び済みの見せ方を変更（§2.3） |
| §9.2 | [Pass] は白 | 赤（No.7） |
| §11 | ダークモードで盤面の色を切り替える | 盤面は暗い背景に固定（§7） |
| §12 | `game_interface_width.min` を実機で測って宣言 | 360 に宣言（§8） |
| 未確定事項 No.12 | 背景は暗い宇宙テーマ | §7 で実装 |
| 未確定事項 No.14 | お守りの所持アイコンをパネルにも出す | No.5 |

### 11. 検証方法

#### 11.1 ローカルのプレビュー（I-14 で新設）

開発用で `src/` の外、`scripts/preview/` に置く。BGA へ同期しない。

- 静的な HTML で `src/modules/js/Game.js` と `src/minimumjourney.css` をそのまま読み込む。BGA の API を模したオブジェクトを用意する: `states.register`、`gameArea.getElement`・`addLastTurnBanner`、`players.*`、`playerPanels.getElement`、`statusBar.setTitle`・`addActionButton`・`removeActionButtons`、`actions.performAction`（記録だけ）、`notifications.setupPromiseNotifications`、`images.dontPreloadImages`、`gameui.bgaAnimationsActive` など `Game.js` が使うもの。グローバルの `_`・`importEsmLib` も用意する。
- `importEsmLib(name, ver)` は BGA の CDN の ESM を読む: `https://x.boardgamearena.net/data/game-libs/${name}/${ver}/dist/${name}.esm.js`。**【実測】** 2026-11-18: bga-cards の 1.x は 1.0.15、bga-animations は 1.0.3、bga-jump-to は 1.0.1 に 302 で転送され、`Access-Control-Allow-Origin: *` で読み込める。
- BGA の画面を模した枠: デスクトップは上にステータスバー、右に幅約 260px のパネルの列。モバイル幅ではパネルを上に置く。`html[data-theme]` の切り替えを付ける。
- 固定データ（`scripts/preview/fixtures/`）: 3 人・4 人 × 準備フェイズの開始（準備場が空）、準備フェイズの後半（6〜8 枚、横向き、046 の組、裏向きの山）、探査フェイズ（獲得カード、お守り、手番の強調）、ゲーム終了（デッキ一覧）。
- スプライトは事前に `npm run build:sprites` で作る。
- スクリーンショット: Chrome のヘッドレス（`--headless=new --screenshot --window-size=W,H`。Chrome の場所は環境変数で指定）で、360×780、390×844、768×1024、1024×768、1280×800、1366×768、1440×900、1920×1080、2560×1440 の各幅、ライト・ダークを撮る。出力は `.gitignore` 済みのフォルダに置き、PR には代表的な画像を添える。

#### 11.2 Studio での確認（作者）

`main` へのマージで自動同期された後、Express Start の 3 人・4 人、ライト・ダーク、実機のスマホで、§2〜§9 を確認する。

### 12. 実装の分け方

各 PR は 1 タスク 1 PR。再提出（審査への再申請）は I-14〜I-17 のマージと、作者の Studio での確認の後に行う。

| ID | 内容 | 依存 |
| --- | --- | --- |
| I-14 | プレビュー環境（§11.1） | 013 の承認 |
| I-15 | Action Bar のボタン（§2.1〜§2.4）と部品からの操作（§2.5） | 013 の承認（I-14 と並行可） |
| I-16 | 画面構成・情報の整理・プレイヤーの領域・部品の見た目・レスポンシブ・アクセシビリティ（§3〜§6・§8・§9）と `game_interface_width.min`、背景を表示する CSS と暗い卓の配色（§7.2） | I-14 |
| I-17 | 背景画像の生成（§7.1） | 013 の承認（I-16 と並行可） |

実際の分け方: 暗い卓の配色と背景を表示する CSS は I-16 に入れ、I-17 は画像の生成だけにした（I-16 と並行して進め、I-17 を先にマージした）。

### 13. 実装との対応（I-14〜I-17 で確定した点）

§3.2 の基準値などを、プレビュー（§11.1）で確かめて次のとおりにした。値は `src/modules/js/Game.js` の定数と `src/minimumjourney.css` で一致させており、片方を変えたらもう片方も直す。

| 項目 | 実装 |
| --- | --- |
| 探査場の幅 E(c) | (1 + S)·c + 6·S + 22（スロットの間 6px、マットの padding 10px と border 1px の両側で 22px） |
| プレイヤーの 1 列の幅 P(c) | 1.5c + 28（山札と裏向きの山の間 6px＋マットの 22px）。列の間 12px、広い配置の探査場とプレイヤーの間 24px |
| 広い配置の高さの条件 | 探査場の高さ 5 × (c × 419 / 300) + 4 × 12 + 22 が、見えている高さ H に収まる最大の c（下限 100） |
| 盤面 | `max-width: 1600px`、左右の padding 8px。狭い配置だけ、左に bga-jump-to の分の余白 44px を取り、配置の計算の幅からも引く |
| 再計算 | `ResizeObserver` と `resize` の変更を `requestAnimationFrame` で 1 回にまとめる。大きい配置・大きいカード幅へ変えるのは、幅が 24px 狭くても同じ結果になるときだけ（縦スクロールバーの出入りで行き来しないため）。小さい方へはすぐ変える |
| ジャンプリンク | bga-jump-to 1.x。「Exploration field」と各プレイヤー（アバター 32px とプレイヤーカラー）。開閉は `localStorageFoldedKey` で端末に覚える。広い配置では隠す |
| 背景 | `body::before` を `position: fixed` で敷き、`img/background.webp`（I-17。バナーの x 2850〜3930 を切り出し、ぼかし 10・明るさ 0.55・彩度 0.85、WebP 品質 70。約 6.6KB）→ グラデーション → `#0b0e1a` の順に重ねる |

あわせて直した既存の不具合:

- 横向きのカードが上下逆さまだった。bga-cards 1.x の `getCardRotation` は戻り値を 90° 単位の回数として扱う（`rotation * 90deg`）のに、90 を返していたため 8100°（= 180°）回っていた。1 を返すようにした。縁・影・斜線・選択枠は、回転する内側の面に付ける。
- 探査フェイズ中に再読み込みすると、手番の順（#N）と手番の人の強調が消えていた。`setup()` が `getAllDatas` の `exploreOrder`・`exploreIndex` を読むようにした（BGA ガイドライン F.2）。
- 状態 35 の arrangeTop で、カードを選ぶと [Keep this order] が消えていた（I-15）。

作者の Studio での確認（§11.2）で見てほしい点（プレビューでは確かめられないもの）: カードが飛ぶアニメーション（獲得、裏向きの山、お守りの移動）の行き先、BGA 実物のツールチップ・ジャンプリンク・最終ラウンドのバナーの見た目、ライトテーマで BGA 側の欄（ログ、パネル、ゲームの下の欄）が背景の上で読めるか、実機のスマホでの操作。

## エッジケース

| 状況 | 挙動 |
| --- | --- |
| 準備場が長い | 縦に伸びる。広い配置でもページの縦スクロール |
| 046 の組が列より広い | 準備場の中で横スクロール（現状のまま） |
| 011 で列のスロットが増える | S が増え、配置を再計算 |
| お守りの移動 | 移す先を表示してから加える（§5.2） |
| 裏向き 0→1・1→0、獲得 0→1・ラウンド終わりに 0 | 表示の切り替え（§5.2）。列の幅は変えない（§5.7） |
| 観戦者・リプレイ | 自分の領域なし、席順、ボタンなし（009 §11） |
| ウィンドウ幅の変更・ブラウザのズーム・画面の回転 | 配置とカード幅を再計算（§3.2） |
| 2560px 以上の超横長 | カード 120px で頭打ち、中央寄せ（§3.3） |
| 3 人 | 幅が狭く済み、広い配置になりやすい（§3.4） |
| ゲーム終了 | デッキ一覧を卓の下の段に出す（§5.6） |
| 360px 未満 | BGA の自動縮小（§8） |

## 未確定事項

作者に決めてもらう点。**作者回答（2026-11-18）「全てそれで進めて」により、No.1〜7 はすべて暫定案のとおり確定した。**

| No. | 質問 | 暫定案 | 回答 |
| --- | --- | --- | --- |
| 1 | 背景の素材は何にするか（§7.1） | 案 A: バナーの宇宙の部分をぼかして生成する。作者が背景専用の画像を用意するなら案 C に差し替える。理由: 追加の素材なしで、ゲームの絵柄に合った宇宙の背景がすぐ作れる | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 2 | 広い画面（盤面の幅 約 1300px 以上）で、探査場を左、プレイヤーを右に横に並べるか。2026-10-17 の「探査場は常にプレイヤーの上」（009 §7.4）を変える。4 人横 1 行は保つ | 並べる。理由: 1920×1080 の PC で盤面全体をスクロールなしで見られる（C.4）。現状は探査場だけで高さ約 900px あり、プレイヤーの領域が画面外になる | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 3 | 裏向きの山（ストップ時に引いた山）を他のカードの約半分の大きさで表示するか | 小さくする。理由: 009 §8.2 の「山札の右横に小さく」に合う。中身は誰にも見えず失う情報がない。列の幅が約 25% 狭くなり、広い配置が使える画面が増える | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 4 | 準備枚数・現在の探査出力・状態（選択済み・準備終了・探査手番）を、プレイヤーパネルだけに出すか（領域の見出しからは消す） | パネルだけ。理由: 全員を 1 か所で比べられ、重複が消える。代案: 各領域の名前の横だけに出し、パネルからは消す（ただし A.3 はパネルを手番順や獲得した資源を見る場所とするので、パネルが空になる点に注意） | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 5 | お守りの所持アイコンをパネルに出すのをやめるか（009 No.14 の変更） | やめる。理由: お守りのカード自体が所持者の領域にあり、アイコンは重複になる | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 6 | 準備の選択中、自分の山札を押すと [Draw] を選べるようにするか（ボタンも残す） | 入れる。理由: 審査の「部品を主な操作の場に」に沿う。全員が選び終えるまでは変更できるので、誤操作の影響は小さい | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |
| 7 | 探査の [Pass] を赤にするか（009 §9.2 は白） | 赤。理由: 探査をやめる＝stop で、C.3 の赤は「取り消し・止める・やめる」。選択中は [Cancel] だけを赤にし、[Pass] は未選択のときだけ出す | 確定（2026-11-18）。暫定案のとおり（作者回答「全てそれで進めて」） |

## 受け入れ条件

- [ ] [Draw] は常に青、[Stop] は常に赤（状態 21・23）。選び済みは灰の「✓ …」で、もう一方は押せて切り替えられる（§2.3）
- [ ] §2.4 の全ボタンが表のとおりの色・表示条件になっている
- [ ] 1 つの状態のボタンが 4 個以内（§2.2 の例外を除く）。青 → 白 → 赤 の順で、取り消し系が右端
- [ ] 準備の選択中に自分の山札を押すと [Draw] が選べる（No.6 の回答に従う。§2.5）
- [ ] 360〜2560px のどの幅でも盤面が中央寄せで、ページが横スクロールしない（§3）
- [ ] 広い配置で、1920×1080 の 4 人で探査場と全員の準備場（ラウンド開始時）がスクロールなしで見える（No.2 の回答に従う）
- [ ] 1 つの値が 1 か所にしか出ていない（§4）。コスト 8 の山の警告が BGA のバナーになっている
- [ ] ラウンド開始時のプレイヤーの領域が、名前＋山札の行だけの高さ（§5.2）
- [ ] UI パネルの箱・見出し・ラベルがない。マットとカードの縁・影で区画が分かる（§6）
- [ ] 背景が木目でなく、ライト・ダークの両方で盤面とログ・パネルが読める（§7）
- [ ] `game_interface_width.min` が 360 で、360px で縮小されずに遊べる（§8）
- [ ] 狭い配置で、盤面に収まらないときだけジャンプリンクが出る（§8）
- [ ] §9 のアクセシブルな名前とツールチップがあり、文字・アイコンが 4.5:1 以上のコントラスト
- [ ] §11.1 の各幅のスクリーンショットを PR に添付した
- [ ] 作者が Studio で確認した（§11.2）
