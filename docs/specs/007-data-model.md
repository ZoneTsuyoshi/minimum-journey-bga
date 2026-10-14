# 007: データモデル・通知

| 項目 | 内容 |
| --- | --- |
| ステータス | Draft |
| 対応タスク | T-007 |
| 作成日 | 2026-10-14 |
| 関連仕様書 | 001（ゲームルール）、002（カードデータ）、003（BGA アーキテクチャ）、006（状態遷移）、009（UI・アセット）、010（ドロー時効果の優先度）、011（探査力変動効果の適用順） |

## 目的

サーバー側で永続化するデータ（DB スキーマ、カードの置き場所と状態、プレイヤーごとの値、ラウンド・解決エンジンの一時データ）と、クライアントへ送る情報（`getAllDatas`、各状態の args、通知）を定める。006 の状態機械が読み書きするデータの置き場を決め、実装者がこの仕様書だけで `dbmodel.sql`、データアクセス層、通知を書けるようにする。最重要の要件は、非公開情報（山札の順序と中身、ストップ時の裏向きカードの券面、同時選択の内容、043 の決定内容）をどの経路でも漏らさないこと。

## スコープ

- 対象: 永続データの全体像、`dbmodel.sql` の案、カードの置き場所（location）と位置、グローバル値とプレイヤーごとの値、乱数とシャッフル、`getAllDatas`、状態ごとの args、通知の一覧、ID の扱い（`changeIds()`）、統計（stats）、ゲームオプションとゲーム設定、DB 容量への配慮、テスト観点
- 対象外: ルールそのもの（001・010・011 が正）、状態遷移（006）、カード効果の内部実装とカード定義データ（002・T-008）、画面の見た目（009・T-009）。コードは書かない（SQL と擬似コードは示す）

## 記法

- **【確認】** は BGA 公式ドキュメント（`https://en.doc.boardgamearena.com/` 配下）で確認した事実。URL を添える。ページは WebFetch（本文を要約して返すツール）経由で取得したため、メソッドの細かい挙動は Studio での実機確認を前提とする。
- **【提案】** は本仕様書の設計案。公式ドキュメントでは確認していない。
- **【未確認】** は確認を試みたが公式ドキュメントから判断できなかった事項（「未確定事項」に集約）。代替案を併記する。
- ルールの根拠は `001 §N`、`006 §N`、`010 §N（D2 などの作者回答番号）`、`002 No.N` の形式で示す。
- 表のセルに縦線を書けないため、「または」を意味する値は「／」や「、」で区切る。

確認に使った BGA 公式ページ（調査日 2026-10-14）:

| 略称 | URL |
| --- | --- |
| ItemManager | https://en.doc.boardgamearena.com/ItemManager |
| Deck | https://en.doc.boardgamearena.com/Deck |
| DbModel | https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql |
| GamePhp | https://en.doc.boardgamearena.com/Main_game_logic:_Game.php |
| Stats | https://en.doc.boardgamearena.com/Game_statistics:_stats.json |
| Options | https://en.doc.boardgamearena.com/Options_and_preferences:_gameoptions.json,_gamepreferences.json |
| Replay | https://en.doc.boardgamearena.com/Game_replay |
| Actions | https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php |
| StateDir | https://en.doc.boardgamearena.com/State_classes:_State_directory |
| Studio | https://en.doc.boardgamearena.com/Studio |

## 仕様

### 1. BGA の仕組みの確認結果

#### 1.1 【確認】した事実

| No. | 事実 | 出典 |
| --- | --- | --- |
| D1 | `ItemManager` は `#[Item(?string $tableName)]` を付けたクラスを管理する。テーブル名は省略時にクラスの短い名前。プロパティに `#[ItemField(kind, type, dbField, serialize, locationIndex)]` を付けて永続化する。`kind` は `ItemFieldKind::ID`、`LOCATION`、`ORDER` の 3 つが必須。LOCATION は `locationIndex` 0 と 1 の 2 段（例: `location`（string）と `locationArg`（int／string／null））を宣言できる。それ以外のプロパティは型から列型が決まり、配列・オブジェクト・enum は JSON になる | ItemManager |
| D2 | 生成は `$this->bga->itemManagerFactory->createItemManager(Card::class, locations: [...])`。`new ItemLocation(string／int $name, bool $randomPick = false, $autoReshuffleFrom = null, ?callable $autoReshuffleCallback = null)` で受け付ける location を宣言する。`ItemLocation::getDefaults()` は deck／discard／table／hand。`initDb()` がテーブルを作る | ItemManager |
| D3 | 操作メソッド: `createItems`、`pickItem(s)`（from から to へ）、`moveItem(s)`（`$order`、`$prepend` を取れる）、`moveAllItemsInLocation`、`setItemOrder`、`getItemById`、`getItemsByIds`、`getItemsByFieldName(s)`、`getItemsInLocation(location, reversed, limit, sortByField)`、`getItemOnTop`、`getItemsOnTop(n, location)`、`getAllItems`、`countItemsInLocation`、`updateItem(s)`、`updateAllItems`、`shuffle(location)`、`changeIds`、`changeIdsForLocation` | ItemManager |
| D4 | `changeIds(items)` は渡したアイテムの ID を付け替え、古い ID から新しい ID への対応を返す。ドキュメントの説明は「以前見えていたアイテムが隠れるときに使う」。`changeIdsForLocation(location)` は対応を返さない | ItemManager |
| D5 | `Deck` は旧来のコンポーネントで、ドキュメント冒頭に「ItemManager を使うことを推奨する」とある。Deck のテーブルは `card_id`、`card_type`、`card_type_arg`、`card_location`、`card_location_arg` の 5 列で、特別な location 名は `deck`、`hand`、`discard` | Deck |
| D6 | `dbmodel.sql` は `CREATE TABLE IF NOT EXISTS ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` を使う。`player` テーブルには列を足してよいが、既存の列は変更しない。`global`、`stats`、`gamelog` と `bga_` で始まるテーブルは変更禁止。列名はテーブル名と違う名前にする（リプレイ機能が正規表現で置換するため）。コメントはコードと同じ行に書かない。ゲーム中にスキーマは変えられない。PHP から CREATE／ALTER を呼ぶと暗黙のコミットが起きるので、リリース後の変更は `upgradeTableDb()` を使う | DbModel |
| D7 | DB 全体（システムテーブル含む）が 64 MB を超えると停止する。最大の消費元は gamelog とリプレイ用のテーブル | DbModel |
| D8 | `getAllDatas(int $currentPlayerId = null): array` は魔法の引数 `$currentPlayerId`（または `$currentPlayerNo`）で閲覧者の視点を受け取り、連想配列を返す。ページには `getAllDatas` の戻り値に `_private` キーを入れてよいかの記述はない | GamePhp |
| D9 | `$this->bga->notify->all(type, message, args)` は全プレイヤーと観戦者へ、`notify->player(playerId, type, message, args)` は 1 人（卓のプレイヤー。観戦者は不可）へ送る。公開通知の args に `_private`（プレイヤー ID をキーにした連想配列。値に `NotificationMessage` も使える）を入れると、その本人だけがその値を受け取れる。ログ文では `${_private.xxx}` で参照できる | GamePhp |
| D10 | 通知は「アクションが正常に終わった時点でまとめて送られる」。通知の合計サイズは 128 KB が上限。履歴（ログ）用の通知からは、`i18n`、`player_id`、メッセージ中で参照した引数以外の引数が取り除かれる。残したい引数は `preserve` に列挙する。通知にHTML を入れない | GamePhp |
| D11 | `${player_name}` を色付けするには `player_id` を同じ args に入れる。翻訳対象の文字列は `clienttranslate()` で包み、args の値を翻訳する場合は `i18n` に引数名を列挙する | GamePhp |
| D12 | `bga_rand(int $min, int $max): int` は両端を含む乱数。サイコロなどで使用が必須とされる | GamePhp |
| D13 | `$this->bga->globals` に `set(name, value)`、`get(name, default, class)`、`inc`、`has`、`delete(...names)`。値は `bga_globals` テーブルに保存される | GamePhp |
| D14 | `$this->bga->playerScore->set／inc`、`playerScoreAux->set／inc`（どちらも `PlayerCounter`）。同点処理は `playerScoreAux` | GamePhp |
| D15 | 統計: `tableStats->init／set／inc／get`、`playerStats->init／set／inc／get`。`setupNewGame` で全統計を `init` する | GamePhp |
| D16 | 統計定義は `stats.json`（`.jsonc` も可）。型は `int`、`float`、`bool`。ID は 10 以上で、削除した統計の ID は再利用できない（過去データが壊れる）。テーブル統計とプレイヤー統計は ID を共有しない。キーは英数字のみ。任意属性 `display: "limited"`（開発者・出版社・管理者にのみ表示）、`value_labels`（値に表示名を付ける） | Stats |
| D17 | ゲームオプションは `gameoptions.json`（`.jsonc` も可）。キーは文字列の数値、`name`、`values`（キーは文字列。各値に `name`、`description`、`tmdisplay`、`nobeginner`、`firstgameonly` など）、`default`、`level`（`base`、`major`、`additional`）、`displaycondition`、`startcondition`、`notdisplayedmessage`。ID は 100〜199、200 と 201 は予約（持ち時間、レーティング）。値が `Yes`／`No`（または on／off、enabled／disabled）の 2 つなら、チェックボックスで表示される。ゲーム作成後は変更できない。PHP では `$this->bga->tableOptions->get(int $optionId): ?int`、JS では `this.bga.tableOptions.get(optionId)` | Options、GamePhp |
| D18 | ゲーム設定（プレイヤーごとの好み）は `gamepreferences.json`。ID は 100〜199、`name`、`values`（各値に `name`、`cssPref`）、`default`、`needReload`。PHP では `$this->userPreferences->get($playerId, $prefId)`、JS では `this.bga.userPreferences.get(prefId)` | Options |
| D19 | リプレイは、開始時の静的ファイルを保存し、ブラウザへ送った全ての通知を保存して、再生時に通知を送り直して再現する。このため画面更新は通知経由に限る | Replay |
| D20 | `getArgs()` の `_private` キー、`MULTIPLE_ACTIVE_PLAYER` の API、`zombie()` は 006 §1.1（B1〜B12）で確認済み。args はアクションより先に呼ばれ、args 内で DB を変更してはならない | StateDir（006 §1.1 B5） |
| D21 | DB 操作は `$this->DbQuery(sql)`、`getCollectionFromDB(sql)`（先頭列をキーにした連想配列）、`getObjectFromDB`、`getObjectListFromDB`、`getUniqueValueFromDB` | GamePhp |

#### 1.2 【未確認】のまま設計に影響する点

| No. | 事項 | 設計上の扱い |
| --- | --- | --- |
| V1 | `Location::from()` と `Location::filter()` の引数。ドキュメントには「具体的な location／絞り込み用の location を作る」としかなく、location 名と `locationArg` をどう渡すか（配列 `['deck', $pid]` か、引数か）が不明 | 擬似コードでは `Loc('deck', $pid)` と書き、実装時に `CardRepository`（§3.5）の中だけで実際の API に合わせる。ItemManager の API を呼ぶ箇所を 1 クラスに閉じ込めて影響を限定する |
| V2 | ORDER の向き（数値が大きい方が山の上か）と、`getItemOnTop`、`pickItem` が上をどちらと見なすか | ゲームロジックは数値の向きに依存せず、`peekTop`、`drawTop`、`putOnTop`、`putOnBottom` を `CardRepository` 経由で呼ぶ。Studio で最初に確認する（テスト D1） |
| V3 | ORDER の列が符号付きか。山札の下へ置く（010）には最小値より小さい値が要る | 自前の DDL で符号付き INT にする（§3.1）。`ItemManager` の `initDb()` が作る型は不明なため、`dbmodel.sql` で表を先に作り、`initDb()` を呼ばない案を第一候補にする（V4） |
| V4 | `initDb()` を `setupNewGame` で呼んでよいか（D6 は PHP からの CREATE を暗黙コミットの理由で避けるよう求める）。`dbmodel.sql` で作った表を `ItemManager` が受け付けるか。列名を `dbField` で対応づけられるか | 第一候補: `dbmodel.sql` に `card` 表を書き、`#[ItemField(dbField: ...)]` で列名を合わせ、`initDb()` は呼ばない。だめなら `initDb()` に切り替える。それもだめなら Deck 互換の自前 DAO（§3.6） |
| V5 | `ItemManager::shuffle()` が内部で `bga_rand`／`random_int` を使うか | 山札のシャッフルは自前の Fisher-Yates（`bga_rand`）で `ORDER` を書き換える（§5）。`shuffle()` は使わない |
| V6 | `changeIds()` が付け替える新しい ID の割り当て順（AUTO_INCREMENT の順か、乱数か）。付け替え後に、`location_arg` で他のカード ID を参照している行（付加カード）が自動で更新されるか | 呼ぶ前に付加カードを外すか、呼ぶ順を自前で制御する（§7.4）。渡す配列は乱数で並べ替える |
| V7 | `getAllDatas` に `_private` を返せるか（D8 に記述なし） | 使わない。閲覧者ごとに内容を分岐して返す（§8） |
| V8 | 観戦者が `getAllDatas` に渡される `$currentPlayerId` の値、観戦者へのリプレイ時に `notify->player` や `_private` の通知が他人へ見えるか | 観戦者は「`$currentPlayerId` が卓のプレイヤーに含まれない」で判定する。非公開情報はそもそも通知に載せない設計（§7）にして、見えても漏れないようにする |
| V9 | `player_score_aux` 列の型と範囲 | 符号付き 32 ビット整数に収まる 9 桁以内にする（§11.2） |
| V10 | `player_no` が時計回りの席順に対応するか | 010 D10 は「時計回りは BGA の席順」。`player_no` の昇順を時計回りと扱う。Studio で 4 人卓の席順表示と照合する |
| V11 | args が、`setPlayerNonMultiactive` 済みのプレイヤーにも再送されるか（006 U1 と同根） | 準備選択の「選択済み」表示は args に頼らず、内容を含まない公開通知 `prepMarked` で更新する（§10） |

### 2. 永続データの全体像

どの情報をどこに持つかの一覧。「保存先」は §3〜§4 で定義する。

| 分類 | データ | 保存先 | 公開範囲 |
| --- | --- | --- | --- |
| カード（全 58 種ぶんの実体） | 位置（location、locationArg、order）、カード番号、絵柄番号、横向き | `card` テーブル（ItemManager） | location ごと（§3.3 の表） |
| プレイヤーごとの値 | 準備終了、準備選択、半減フラグ、確定した探査出力 | `player` テーブルの追加列 | 準備選択だけ非公開。他は公開 |
| ゲーム全体の値 | ラウンド番号、フェイズ、次の準備選択の制約、探査手番、探査中の一時データ、007 のロック、終了フラグ、進行度の基準値 | `bga_globals`（`$this->bga->globals`） | サーバー内部。公開する値だけ args か通知で送る |
| 解決エンジンの一時データ | `resolve_level`、解決キュー、043 の決定、008 のコピー元 | `globals`（`resolve_level`）と `resolve_entry` テーブル | 非公開（043 の決定は特に厳守） |
| 乱数の種 | 持たない | — | `bga_rand` を呼ぶ都度（§5） |
| 派生値（保存しない） | 準備枚数、現在の探査出力（準備フェイズ中の暫定値）、山札枚数、名声合計、お守りの所持者、探査手番の順位 | 必要なときに計算する | 準備枚数・探査出力・山札枚数は公開。名声合計は §11.3 |
| 表示用スコア | `playerScore`、`playerScoreAux` | `player` テーブル（フレームワーク）。ゲーム終了時（状態 60）にだけ書く | 終了時に公開 |

保存しない（派生値にする）理由は、二重管理による不整合を避けるため。例: お守りの所持者は `charm` の location_arg が唯一の正で、別の列に複製しない。

### 3. カードのデータモデル

#### 3.1 `card` テーブル（`dbmodel.sql`）【提案】

カードの実体は 1 枚 1 行（絵柄違いの初期カードも 1 枚 1 行）。静的な定義（シンボル、コスト、探査力、名声、効果）は持たず、PHP のカード定義（002・T-008）を `card_no` で引く。

```sql
CREATE TABLE IF NOT EXISTS `card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_location` VARCHAR(16) NOT NULL,
  `card_location_arg` INT NOT NULL DEFAULT 0,
  `card_order` INT NOT NULL DEFAULT 0,
  `card_no` SMALLINT UNSIGNED NOT NULL,
  `card_art` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `card_sideways` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY `idx_card_loc` (`card_location`, `card_location_arg`, `card_order`),
  KEY `idx_card_no` (`card_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
```

| 列 | 意味 | ItemManager の属性（案） |
| --- | --- | --- |
| `card_id` | カード ID。`changeIds()` で変わりうる（§7.4）。公開済みのカードの ID はクライアントが DOM のキーに使う | `#[ItemField(kind: ItemFieldKind::ID, dbField: 'card_id')] int $id` |
| `card_location` | 置き場所の種類（§3.2） | `#[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 0, dbField: 'card_location')] string $location` |
| `card_location_arg` | 置き場所の引数（プレイヤー ID、探査コスト、付け先のカード ID）。引数を持たない location は 0 | `#[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 1, dbField: 'card_location_arg')] int $locationArg` |
| `card_order` | location 内の位置（§3.4） | `#[ItemField(kind: ItemFieldKind::ORDER, dbField: 'card_order')] int $order` |
| `card_no` | カード番号 1〜58（002 §4。1＝国旗、2＝乗組員、3＝初期探査船、…、56＝未確認帯域、57／58＝お守り）。カードの同一性を表す | `#[ItemField(dbField: 'card_no')] int $cardNo` |
| `card_art` | 絵柄番号。001 は 1〜4、002 は 1〜8、003 は 1〜4。それ以外は 0（002 §6.1。絵柄は表示専用でゲームに影響しない） | `#[ItemField(dbField: 'card_art')] int $art` |
| `card_sideways` | 横向き（1 人だけドローしたカード。001 §4）。`prep` location のカードだけが 1 になりうる | `#[ItemField(dbField: 'card_sideways')] bool $sideways` |

- 列名はテーブル名（`card`）と異なる名前にする（D6）。
- 実装時の確認事項は V3・V4。`dbField` の綴りや `bool` 型が `TINYINT(1)` に対応することは【未確認】。
- 不変条件: `(card_no, card_art)` は卓内で一意（各カードは 1 枚だけ存在する。002 §1）。このため、**公開されたカードは `card_id` を見なくても `(card_no, card_art)` で同一性が分かる**。ID の追跡防止（§7.4）が守るのは、「カード番号を伏せたまま ID だけが公開される」経路があった場合の漏れだけである。本仕様では伏せたカードの ID を一切送らない（§7.3）ので、`changeIds()` は多重防御の位置づけ。

#### 3.2 location の一覧【提案】

`(card_location, card_location_arg)` の 2 段で表す。ItemManager の LOCATION が 2 段（D1）なので、`deck_<player>` のような連結文字列にはせず、種類と引数を分ける。表の「例」は 006 の論理名との対応。

| location | location_arg | 意味 | order の使い方 | 枚数の目安 |
| --- | --- | --- | --- | --- |
| `deck` | プレイヤー ID | そのプレイヤーの山札（裏向き） | 山の順。上が `drawTop` で取る側 | 4 枚〜約 40 枚 |
| `prep` | プレイヤー ID | 準備場の表向きのカード（横向き含む）。046 の付加カードは含まない | 引いた順の通し番号（1 から。欠番を許す） | 0〜約 15 枚 |
| `attached` | 付け先（046）のカード ID | 046 の付加カード | 046 に付く順（1 枚のみ） | 0〜1 枚 |
| `facedown` | プレイヤー ID | ストップ時に引いた裏向きのカード（別置き場）。準備したカードではない | 重ねた順 | 0〜数枚 |
| `acquired` | プレイヤー ID | このラウンドの獲得カード | 獲得順 | 0〜3 枚程度 |
| `explore_deck` | 探査コスト（2／4／6／8） | 探査山札（裏向き） | 山の順。上が先頭 | 最大 15 枚 |
| `row` | 探査コスト（2／4／6／8） | 探査場の列の表向きのカード | **スロット番号**（0 始まり。取られた位置は空のまま残す） | 規定枚数まで |
| `unknown` | 0 | 未確認帯域カード（56）が探査場にあるとき | 0 | 0〜1 枚 |
| `removed` | 0 | ゲームから除外された公開カード | 除外順 | 増える |
| `setup_removed` | 0 | 3 人プレイの開始時に伏せたまま除外した 8 枚（001 §3-2） | 0 | 0 または 8 枚 |
| `charm` | 所持者のプレイヤー ID | お守り（57 または 58 のどちらか 1 枚だけを作る） | 0 | 1 枚 |

- `deck` と `facedown` は、**どの経路でも ID を含む一覧をクライアントに送らない**。例外は 055 の解決中の本人だけ（§7.5）。
- `setup_removed` は枚数も含めてクライアントに送らない（枚数は人数から導けるが、不要な情報を出さない）。
- 006 §2.2 の「裏向きの別置き場」＝`facedown`、「獲得エリア」＝`acquired`、「除外」＝`removed`。「ストップ時に引いた裏向きのカード」の枚数は、最後にシャッフルで山札へ戻すまで保持する。
- 056 は開始時に `unknown` に置き、獲得されたら `acquired`、帰還フェイズ②で `deck` に入る。
- お守りは 2 枚（57、58）あるが、ゲームオプションで選んだ面の 1 枚だけを作る（001 §3-5）。使わない面は存在しない。
- 011 の「探査山札の一番上を列に加える」で、列の枚数が規定を超えることがある。その場合のスロット番号は既存の最大 + 1 を割り当てる（009 の UI は余りスロットを動的に足す。T-009 への申し送り）。補充（帰還フェイズ③）は「表向きの枚数が規定未満の列だけ」を空きスロットの小さい順に埋める。

#### 3.3 location ごとの公開範囲

「ID」は `card_id`、「券面」は `card_no` と `card_art`。

| location | 全員（観戦者含む） | 持ち主本人 | 例外 |
| --- | --- | --- | --- |
| `deck` | 枚数のみ | 枚数のみ | 「山札」だけを参照する効果で、効果が指定したカード（山札の上など）の ID と券面を `_private` で本人に（§9）。「デッキ」を参照する効果の解決中（055）は山札全体の券面を本人に（§7.5） |
| `prep` | ID、券面、位置、横向き | 同左 | なし |
| `attached` | ID、券面、付け先 | 同左 | なし |
| `facedown` | 枚数のみ（暫定。未確定事項 No.3） | 枚数のみ。ID も券面も送らない（009 No.11） | 055 の解決中だけ本人に ID と券面（§7.5） |
| `acquired` | ID、券面 | 同左 | なし |
| `explore_deck` | 枚数のみ | 枚数のみ | 034 などで山札の上を獲得した瞬間にそのカードを公開（通知） |
| `row` | ID、券面、スロット | 同左 | なし |
| `unknown` | ID、券面 | 同左 | なし |
| `removed` | ID、券面 | 同左 | なし |
| `setup_removed` | 何も送らない | 何も送らない | なし |
| `charm` | ID、券面、所持者 | 同左 | なし |

#### 3.4 位置（order）の規約【提案】

- **山（`deck`、`explore_deck`、`facedown`）**: `ORDER` の数値の向きに依存しない。操作は `CardRepository` の `peekTop(zone, arg, n)`、`drawTop`、`putOnTop`、`putOnBottom`、`shuffle` だけを使う（V2）。符号付き整数なので、`putOnBottom` は最小値 − 1 を割り当てる。
- **`prep`**: 引いた順に 1, 2, 3…。位置依存の効果（「直前に準備されていたカード」「n 枚目」）は、`prep` の中で `card_sideways = 0` のカードを `card_order` の昇順に並べた列（011 の `live`）の順位で判定する。009 や 014 が準備場からカードを戻して新しく準備したカードは、`card_order` の最大 + 1 を持つ（欠番は許す。順位は並べ直して数える）。
- **`row`**: `card_order` がスロット番号。036 の入れ替えでは、取り除く列カードのスロット番号を引き継ぐ。取られたスロットは行を持たないことで空を表す。
- **`attached`**: 付加カードは準備枚数に数えず、「直前に準備されたカード」にもならない（002 No.50）。このため `prep` に入れない。付け先の 046 の `card_id` を `card_location_arg` に持つ。

#### 3.5 データアクセス層 `CardRepository`【提案】

ItemManager の API を呼ぶのはこのクラスだけにする（V1〜V3）。ゲームロジック・状態クラスは `CardRepository` のメソッドを呼ぶ。下は契約（引数と非公開情報の扱い）で、実装は自由。

| メソッド | 動作 | 備考（非公開情報） |
| --- | --- | --- |
| `createAll(playerIds, charmNo, arts)` | 初期カード、漂流カード、056、お守りを作る | `setupNewGame` でのみ。ID は作成直後の値 |
| `count(zone, arg)` | 枚数 | 公開してよい枚数（`deck`、`facedown`、`explore_deck` を含む） |
| `list(zone, arg)` | 一覧（`order` の昇順） | `deck`／`facedown`／`explore_deck`／`setup_removed` は、`DeckView`（下）を除いて呼ばない。呼び出し箇所をコードレビューの検査項目にする |
| `peekTop(zone, arg, n)` | 山の上の n 枚（動かさない） | 戻り値を公開通知に入れない。本人の `_private` args か公開カードの通知（036 など）にだけ使う |
| `drawTopToPrep(pid)` | `deck` の上から 1 枚を `prep` の末尾へ。引いたカードを返す | 通知 `cardDrawn` で ID と券面を公開してよい（表向きに置くため） |
| `drawTopToFacedown(pid)` | `deck` の上から 1 枚を `facedown` へ | 戻り値の券面を通知に入れない |
| `shuffleDeck(pid)` | `deck` を `bga_rand` で並べ替える（§5.2） | 結果の順序をどこにも出さない |
| `returnToDeck(cards, pid, shuffle)` | 公開済みのカードを山札に戻す。戻す直前に付加カードを外し、戻した後に `hideIds` を呼ぶ | 戻した ID は通知に載せる（クライアントが DOM から消す）。新しい ID は載せない |
| `putOnTop(card)`、`putOnBottom(card)` | 山の上、または下へ置く | 010・020・035・050・052・005 |
| `moveToAcquired(card, pid)` | 獲得 | 山の上を探査したときは通知で券面を公開 |
| `removeFromGame(cards)` | `removed` へ移す | 券面が公開される |
| `attach(host, card)` | `attached` へ | 046 |
| `setSideways(card, bool)` | 横向きの設定 | 公開 |
| `hideIds(cards)` | `changeIds()` を、乱数で並べ替えた配列で呼ぶ（§7.4） | 呼ぶタイミングは §7.4 |
| `DeckView::forOwner(pid)` | 山札、準備場、獲得カード、`facedown` の券面を **`card_no` の昇順**（山の順序を出さない）で返す | 055 の `getArgs` だけが呼ぶ（§7.5）。他の呼び出し箇所を作らない |
| `rowFillSlots(cost)` | 空きスロットの小さい順を返す | 帰還フェイズ③ |

#### 3.6 ItemManager が使えない場合の代替

- 代替 A（Deck コンポーネント）: D5 のとおり 5 列で、`card_type`＝`card_no`、`card_type_arg`＝`card_art`、`card_location`＝上の location 名、`card_location_arg` は 1 つの整数。`card_order` と `sideways` の列がないため、`sideways` は別表 `card_flag(card_id, sideways)` に出す。位置は `card_location_arg` を使い切るので、`deck`／`prep` のプレイヤー別 location は `deck_<player>` のように連結文字列にして `card_location`（`VARCHAR(16)`）へ入れ、`card_location_arg` を位置にする。
- 代替 B（自前 DAO）: §3.1 の DDL をそのまま使い、`CardRepository`（§3.5）を `DbQuery`／`getCollectionFromDB`（D21）で実装する。ItemManager の機能（`pickItem`、`shuffle`）は使わず、シャッフルは §5.2 の自前実装を使う。**状態クラスとゲームロジックは `CardRepository` の契約だけに依存するので、代替への切替で影響を受けない。** 切替の判断は Studio での最初の検証（テスト D1〜D3）で行う。

### 4. プレイヤーごとの値・グローバル値・解決エンジンの一時データ

名前は 006 で使われているものに合わせ、無いものは提案する。

#### 4.1 `player` テーブルの追加列【提案】

`dbmodel.sql` に追記する。既存の列（`player_id`、`player_no`、`player_score`、`player_score_aux` など）は変更しない（D6）。

```sql
ALTER TABLE `player` ADD `prep_finished` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `prep_choice` VARCHAR(4) NULL DEFAULT NULL;
ALTER TABLE `player` ADD `is_halved` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `player` ADD `explore_output` INT NULL DEFAULT NULL;
```

| 列 | 型 | 意味 | 書く状態 | 公開範囲 |
| --- | --- | --- | --- | --- |
| `prep_finished` | bool | 準備終了（そのラウンドでストップを選んだ、または山札 0 枚で強制ストップ）。054 で打ち消される（001 §5 ①-3、N38） | 24 で立てる、20 で 054 により解除、10 で全員 0 | 公開 |
| `prep_choice` | `'draw'`／`'stop'`／null | 現在の準備選択（006 §4）。null は未選択。**確定するまで本人以外へ送らない** | 21（本人のアクション）、20（強制・確定済みの選択）、22（049 の持ち主が 23 で選ぶ） | **非公開**。開示（24、または 22 の開示通知）まで |
| `is_halved` | bool | 1 人だけドローしたプレイヤー（③-3。探査出力を半減する。001 §6-1） | 24（`soloDraw` で立てる）、10 で 0 | 公開（横向きのカードと一致） |
| `explore_output` | int／null | 確定した探査出力（011 §6-1、1 度だけ計算し以後再計算しない。A2）。null は未確定 | 40 | 公開（確定後） |

`prep_choice` を 24 より後に残してよいが、20 の分類処理の冒頭で全員を null に戻す（前回の選択を持ち越さない）。開示後に残る値は、通知（`choiceRevealed`）で既に公開済みなので漏れではない。

#### 4.2 グローバル値（`$this->bga->globals`）【提案】

D13 の API に保存する（`bga_globals` テーブル。値は PHP の値を保存でき、`class` を指定して取り出す）。キー名は snake_case。「書く状態」は 006 の状態番号。

| キー | 型 | 意味 | 初期値 | 書く状態 | 公開 |
| --- | --- | --- | --- | --- | --- |
| `round_no` | int | ラウンド番号（1 始まり。057 の 1 ラウンド目の探査力 0 に使う） | 0 | 10 | 公開 |
| `phase` | string（`'prep'`／`'explore'`／`'return'`） | 現在のフェイズ。UI の見出し用 | `'prep'` | 10、40、50 | 公開 |
| `prep_selectors` | int 配列 | 現在の準備選択の「要選択」プレイヤー（006 §2.2 の 21） | `[]` | 20 | 公開 |
| `prep_late_player` | int／null | 049 の「後選び」のプレイヤー | null | 20、23 | 公開 |
| `next_choice_records` | 構造体（下） | 028・029・049・054 の確定記録（次の準備選択への制約）。006 §2.1 の `next_choice_lock` と同じもの（食い違い。未確定事項 No.9）。20 で読んで消費する | 空 | 30（P6）、20（消費） | 公開（カードは全て表向きに公開済み） |
| `force_draw_tmp` | bool | 054 の仮記録（P0）。43 で戻されたら取り消す（H2）。P6 で `next_choice_records` に確定 | false | 30 | 公開 |
| `resolve_level` | string | 解決エンジンのカーソル。値は `INIT`、`P0`、`P1`、`P2`、`P2_WAIT`、`P2A`、`COPY`、`COPY_WAIT`、`P3`、`P4`、`P4_WAIT`、`P5_SEQ`、`P5_SIM`、`P6`（006 §5.2 の擬似コードと同じ） | `INIT` | 24（`INIT` に初期化）、30 | 非公開（内部） |
| `explore_order` | int 配列 | 探査手番の順（プレイヤー ID）。探査フェイズ中は固定（001 §6-2、Q-13） | `[]` | 40 | 公開 |
| `explore_index` | int | 次に行う手番の添字 | 0 | 40、48 | 公開 |
| `explore_state` | 構造体（下） | 現在の探査手番の一時データ | null | 41（初期化）、42、43、48（消去） | 一部公開（下） |
| `locked_card` | 構造体／null | 007 のロック `{player_id, card_id}`。ロックされたカードを持ち主以外は獲得できない（002 No.27）。同じ回に 007 と 008 のコピーが重なることはないので 1 件のみ（006 §5.2） | null | 34、10（消去） | 公開（列のカード ID なので公開情報） |
| `game_end_triggered` | bool | 終了条件の成立フラグ（001 §9-1、Q-18）。その場では終了しない | false | 44、53 | 公開 |
| `end_reason` | string／null | `'unknown'`（未確認帯域の獲得）／`'pile8'`（コスト 8 の山の枯渇）。両方起きたら先に起きた方 | null | 44、53 | 公開 |
| `unknown_acquirer` | int／null | 未確認帯域（56）を獲得したプレイヤー。同点処理 1 番目（001 §9-3）。56 が後で除外されても値は変えない（未確定事項 No.8） | null | 44 | 公開 |
| `pile8_initial` | int | 開始時のコスト 8 の探査山札の枚数（列に公開した後）。進行度（006 §8）用 | — | setupNewGame | 内部 |
| `piles_initial_total` | int | 開始時の全探査山札の合計枚数（列に公開した後） | — | setupNewGame | 内部 |

`next_choice_records` の構造:

```json
{
  "force_draw": false,
  "draw_only": [],
  "stop_only": [],
  "late": null
}
```

`force_draw` は 054（全員ドロー固定）、`draw_only` は 028 の持ち主、`stop_only` は 029 の持ち主、`late` は 049 の持ち主（プレイヤー ID）。20 の分類（006 §4.6）はこの構造を読み、054 があれば 028／029／049 の記録を無視する（H3、N38）。

`explore_state` の構造（現在の手番のプレイヤー 1 人分。006 §6.1 の `explore_state[p]` に `cost_mod` を足したもの）:

```json
{
  "player_id": 0,
  "sum_cost": 0,
  "explored": 0,
  "used_on_explore": [],
  "additional_done": false,
  "cost_mod": 0
}
```

| フィールド | 意味 | 公開 |
| --- | --- | --- |
| `player_id` | 手番のプレイヤー | 公開 |
| `sum_cost` | この手番で探査したカードの実効コストの合計（012 の追加探査の判定。006 §6.2） | 公開 |
| `explored` | この手番の探査枚数 | 公開 |
| `used_on_explore` | 使い終えた探査時効果のカード番号（011、013）。各カード 1 枚なので 1 手番に 1 度ずつ | 公開 |
| `additional_done` | 012 の追加探査を行ったか。1 回まで（002 No.32(d)） | 公開 |
| `cost_mod` | 013 で除外したことによる探査コストの補正（-1）。**この手番の中だけ有効**で、48 で消す（002 No.17、006 §6.1） | 公開 |

#### 4.3 派生値（保存しない）

| 値 | 計算 | 公開 |
| --- | --- | --- |
| お守りの所持者 | `card_no` が 57／58 のカードの `card_location_arg` | 公開 |
| 準備枚数 | `prep` のカードの枚数（横向き含む。`attached`、`facedown`、お守りを含まない。009 §8.3、001 §6-2） | 公開 |
| 山札枚数 | `deck` のカードの枚数 | 公開 |
| 裏向きカード枚数 | `facedown` のカードの枚数 | 公開（暫定。未確定事項 No.3） |
| 現在の探査出力（準備フェイズ中） | 011 §6-1 の計算手順を現在の準備場に適用し、お守りの探査力を足す。**半減（`is_halved`）は適用しない**（009 No.18）。準備場やお守りが変わるたびに再計算して `countersUpdate`（§10）で送る | 公開 |
| 探査手番の順位 | `explore_order` の添字 + 1 | 公開 |
| 名声合計 | 001 §9-2。デッキ（山札、準備場、獲得カード、`facedown`、付加カード）の名声 + 名声変動 + お守りの +1 | §11.3 |
| 013 の使用済み | `explore_state.used_on_explore` | 内部 |
| 040 の山札の上への配置 | 帰還フェイズ②の時点で `acquired` にある 040 を山札の上に置く。獲得は全て探査フェイズで起きる（獲得時効果と 034 の無料獲得を含む）ので、006 が言う「探査フェイズで獲得したときの記録」を別に持たなくても、`acquired` にあるかどうかで判定できる | 内部 |

#### 4.4 解決エンジンのキュー（`resolve_entry` テーブル）【提案】

006 §5.2 の解決キュー。1 回の 30 `DrawResolve`（1 回の「2 人以上がドローした回」）の間だけ存在し、30 が `done` で出るときに全行を消す。

```sql
CREATE TABLE IF NOT EXISTS `resolve_entry` (
  `entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_seq` SMALLINT NOT NULL,
  `entry_player` INT UNSIGNED NOT NULL,
  `entry_card_id` INT UNSIGNED NULL DEFAULT NULL,
  `entry_card_no` SMALLINT UNSIGNED NOT NULL,
  `entry_origin` VARCHAR(8) NOT NULL,
  `entry_host` INT UNSIGNED NULL DEFAULT NULL,
  `entry_level` SMALLINT NULL DEFAULT NULL,
  `entry_eff_level` SMALLINT NULL DEFAULT NULL,
  `entry_status` VARCHAR(8) NOT NULL DEFAULT 'pending',
  `entry_copy_no` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `entry_decision` TINYINT NULL DEFAULT NULL,
  PRIMARY KEY (`entry_id`),
  KEY `idx_entry_status` (`entry_status`, `entry_eff_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
```

| 列 | 意味 |
| --- | --- |
| `entry_seq` | キューの順（INIT で、このドローで引かれたカードを席順に並べて 0 から振る。付加カードは 046 の直後に追加） |
| `entry_player` | カードの持ち主（引いたプレイヤー） |
| `entry_card_id` | カードの現在の ID。043 などで山札に戻されたら null（`changeIds()` で ID が変わるため。§7.4） |
| `entry_card_no` | カード番号（戻された後も 039・053 の抑止の記録に使う） |
| `entry_origin` | `'drawn'`（準備選択で引いた）／`'attached'`（046 の付加カード） |
| `entry_host` | 付加カードの付け先の `entry_id` |
| `entry_level` | カード自身の優先度の数値コード（010 §8。`P0`＝0、`P1`＝10、`P2`＝20、`P2A`＝25、`P3`＝30、`P4`＝40、`P5`＝50、`P6`＝60。ドロー時効果を持たないカードは null） |
| `entry_eff_level` | 実効の優先度。008 は選んだコピー元の優先度を持つ（H5）。それ以外は `entry_level` と同じ |
| `entry_status` | `'pending'`（未判定）、`'valid'`、`'invalid'`（P1 で抑止）、`'returned'`（P2 で戻された）、`'done'`（解決済み） |
| `entry_copy_no` | 008 のコピー元のカード番号（K1）。通知で公開済みの情報 |
| `entry_decision` | 043 の対象プレイヤーの決定。null＝未決定、0＝戻さない、1＝戻す。**全員が決めるまで読まない・送らない** |

- 006 の INIT（手順 3）は「ドロー時効果を持たないカードは対象外」とあるが、043 の対象（043 の持ち主以外で、この回にカードを引いた全員。006 §2.3 の 31）には、効果を持たないカード（051、初期カードなど）を引いたプレイヤーも含まれる。このため**このドローで引かれたカードは全て行にし、効果を持たないものは `entry_level` を null、`entry_status` を `'valid'` にする**（006 の記述との相違点。未確定事項 No.10）。
- 1 人のプレイヤーが同時に持つ P5-同時の未解決行は最大 1 件（引くカードは 1 枚で、046 のときの付加カードだけが追加されうる。046 自体は P2A のため P5 にならない）。35 `SimultaneousEffects` の `actXxx` は、そのプレイヤーの未解決行を 1 件に特定できることを不変条件として検査する。
- 31 の `entry_decision` は、状態 31 を出るときに全行を処理した後に null へ戻す必要はない（30 が行ごと消す）。
- 30 のカーソルは `resolve_level` だけで足りる。P5-順番の次の所有者や 008 のコピー元の未選択は、`entry_status`、`entry_eff_level`、`entry_copy_no` が null かどうかから導ける（006 §5.2 の擬似コードにあった追加のカーソルは持たない）。

### 5. 乱数とシャッフル

#### 5.1 使う乱数【提案】

- 乱数は `bga_rand(min, max)`（両端を含む。D12）だけを使う。`rand()`、`mt_rand()`、`shuffle()`、`array_rand()`、SQL の `RAND()` は使わない（006 B12）。
- 乱数の種は保存しない。結果（並び順）が DB に保存される。リプレイは通知の再生なので、乱数を再現する必要はない（D19）。

#### 5.2 シャッフルの実装【提案】

```text
shuffleList(list):                      # Fisher-Yates
    for i from len(list) - 1 down to 1:
        j = bga_rand(0, i)
        swap(list[i], list[j])
    return list

CardRepository.shuffleDeck(pid):
    cards = list('deck', pid)            # ここだけ山札の内容を読む
    ids = shuffleList(cards.map(id))
    for k, id in enumerate(ids): setOrder(id, k)   # 山の順を書き直す
```

- `ItemManager::shuffle()` は内部の乱数が不明（V5）のため使わない。確認できたら置き換えてよい。
- シャッフルの結果の順序は、通知にも args にも載せない。通知は「混ぜた」事実と枚数だけ（§10 の `deckShuffled`）。
- 帰還フェイズ②（状態 52）は、プレイヤーごとに「準備場（付加カードを含む）、獲得カード、`facedown`、山札」を 1 つの山札にまとめて 1 回シャッフルする。ID の付け替えもここで行う（§7.4）。

#### 5.3 開始時の乱数の使い道（`setupNewGame`）

| 処理 | 方法 | 根拠 |
| --- | --- | --- |
| 各プレイヤーの初期山札 | 4 枚（国旗、乗組員 2、初期探査船）を `shuffleList` | 001 §3-1 |
| 絵柄の割り当て | 001 の絵柄 1〜4、002 の絵柄 1〜8、003 の絵柄 1〜4 をそれぞれ `shuffleList` し、席順（`player_no`）に従って先頭から割り当てる（国旗 1 枚、乗組員 2 枚、初期探査船 1 枚）。3 人プレイでは余る絵柄のカードは作らない | 002 §6.1（ランダム割り当て）。乗組員の 2 枚を同じ組にするかは未確定事項 No.4 |
| 探査山札のシャッフル | コスト 2／4／6／8 の漂流カードを、コストごとに `shuffleList` して `explore_deck` に置く（15／15／12／10 枚） | 001 §3-2 |
| 3 人プレイの除外 | 各探査山札の上から 2 枚を `setup_removed` へ移す（計 8 枚）。シャッフル後に上から取るので、ランダムかつ伏せたままになる。除外したカードは誰にも見せない | 001 §3-2（Q-2 回答） |
| 列の公開 | 各探査山札の上から規定枚数（3 人 3／3／2／2、4 人 4／3／2／2）を `row` のスロット 0 から順に置く。置いたカードの ID と券面は公開する | 001 §3-3 |
| 未確認帯域 | 56 を `unknown` に置く | 001 §3-4 |
| お守りの面 | ゲームオプション 100（§13）の値で 57 か 58 の 1 枚だけを作る | 001 §3-5 |
| 初期所持者 | `bga_rand(0, n - 1)` で席順の添字を選び、そのプレイヤーを `charm` の `card_location_arg` にする。初期所持者が 58（厄除け）でも獲得時効果は発動しない | 001 §3-5（Q-3、Q-4 回答） |

進行度の基準値（`pile8_initial`、`piles_initial_total`）は、列を公開した後の探査山札の枚数から `setupNewGame` で計算して保存する（006 §8）。

### 6. 非公開情報の守り方（方針）

#### 6.1 守るべき情報と経路

| 情報 | 漏れる経路 | 防ぎ方 |
| --- | --- | --- |
| 山札の順序と中身 | `getAllDatas`、args、通知の引数、ログ文、ID の連番、`getAllDatas` を `card` 全行から作ること | 山札の一覧を作るコードを `CardRepository`（§3.5）に閉じ込める。送るのは枚数だけ。本人が効果で見たカードだけを、その状態の `_private` args（§9）で送る |
| ストップ時の裏向きカードの券面 | `facedown` の一覧、`card_id`、引いた直後の通知 | **ID も券面も送らない。枚数だけ**（§7.3）。引いたときの通知に引いたカードの情報を載せない。例外は 055 の解決中の本人だけ（§7.5） |
| 同時選択の内容（準備選択、043 の決定） | `getArgs` の公開部分、通知の引数、`player` の列を丸ごと返すこと | `prep_choice` と `entry_decision` は、確定／全員決定まで本人以外に送らない。「選択済みか」だけを公開する（§9、§10）。`player` 行をそのまま JSON にして返さない |
| 043 の決定内容 | 同上。決定した人の `_private` | 決定の記録は `entry_decision` に限る。全員が決めたら結果（戻されたカード）だけを公開する。決定の「内容」と「戻さなかったこと」は、全員が決めた後に結果として公開してよい（006 §5.4） |
| 3 人プレイの除外カード | `setup_removed` の一覧 | 送らない |
| 探査山札の上のカード | 探査の宣言前の通知 | 山札の上の探査は、確定（`actExplore`）までカードを公開しない（001 §6-3-1）。args に山札の上の券面を入れない |
| 乱数の結果 | ログ、デバッグ出力 | シャッフル後の順序をログにも出さない |
| 他のタブ・再読み込み | args の再送 | `getArgs` は同じ状態の `_private` を毎回返す（006 §9.1）。DB は書かない（D20） |

#### 6.2 一般則: 「山札」を参照する効果と「デッキ」を参照する効果

作者回答（006 §9.1、未確定事項 No.13）より、2 つを区別する。

| 参照 | 例 | 本人に見せるもの | 送り方 |
| --- | --- | --- | --- |
| 「山札」を参照する効果 | 005、010、020、035、036、050、052 | その効果が指定した山札のカードだけ（山札の上の n 枚、または条件に合う候補） | 状態の `_private` args（§9.5）。山札の順序は送らない（候補は `card_no` の昇順で並べる） |
| 「デッキ」を参照する効果 | 055（現行ではこの 1 枚） | デッキ全体（山札、準備場、獲得カード、ストップ時に引いた裏向きのカード）の券面 | 解決中（状態 47）だけ、本人に `_private` で送る（§7.5） |

- 036 を含む他の効果は、公開情報を扱うだけなので特別扱いしない（036 の「表にする」カードは公開）。
- 名声変動、シンボル枚数など、サーバーが山札の中身を使って**計算するだけ**で、プレイヤーに内容を見せない処理は、この一般則の対象外。ただし計算結果（名声合計）が山札の中身を逆算させないように、公開する場合は合計だけを出す（§11.3）。

### 7. 伏せたカードの ID と券面の扱い

#### 7.1 原則【提案】

1. **伏せたカードの ID と券面を、公開の経路に出さない。** 出すのは枚数だけ。
2. **表向きになったカードの ID と券面は、公開通知で出す。** 山札の上から引いた瞬間に表向きになるカードは、その時点の `card_id` と `(card_no, card_art)` を `cardDrawn` に載せる。
3. **表向きだったカードが伏せられる（山札に戻る）ときは、古い ID を通知で伝えて画面から消し、`changeIds()` で ID を付け替える。** 新しい ID は通知に載せない（クライアントは知らない）。
4. **ID 付け替えは多重防御**（§3.1 のとおり、カードは `(card_no, card_art)` で一意に識別できる。本仕様では伏せたカードの ID を送らないので、付け替えが守るのは「何らかの経路で ID が漏れた場合に、過去に見た ID と結びつけられない」ことである）。

#### 7.2 状態の行き来（遷移）ごとの扱い

| 遷移 | 可視性の変化 | 通知での扱い | `changeIds()` |
| --- | --- | --- | --- |
| `deck` → `prep`（ドロー） | 伏せ → 表（公開） | `cardDrawn`（ID と券面） | 不要 |
| `deck` → `facedown`（ストップ時） | 伏せ → 伏せ | `faceDownSet`（ID も券面もなし。枚数は `countersUpdate`） | 不要（ID を送っていない） |
| `prep` → `deck`（009、014、043 で戻す） | 表 → 伏せ | `cardReturned`（古い ID。券面は表向きだったので既知）。続いて `deckShuffled` | 戻した直後に実行 |
| `prep`／`attached`／`acquired` → `deck`（帰還フェイズ②） | 表 → 伏せ | `cardsGatheredToDecks`（プレイヤーごとの古い ID の配列）。続いて `deckShuffled` | シャッフルの前後で全員分 |
| `facedown` → `deck`（帰還フェイズ②） | 伏せ → 伏せ | 枚数のみ（`countersUpdate`）。ID は通知に載せない | シャッフル後に全員分（ID を送っていないので省略してもよい） |
| `deck` → `prep`（009、014、024 の追加準備） | 伏せ → 表 | `cardDrawn`（`reason` つき） | 不要 |
| `deck` の上を表にする（036） | 伏せ → 表（山札の上に表向きのまま。公開） | `wormholeRevealed`（ID と券面） | 入れ替えない場合は、山札に戻す直前に実行 |
| `deck` → `row`（036 の入れ替え。山札の上のカードが列へ） | 表 → 表 | `wormholeSwapped` | 不要 |
| `row` → `deck`（036 の入れ替え。列のカードが山札へ） | 表 → 伏せ | 同上（古い ID） | 実行 |
| `deck` → `deck` の上（005、010、052 の並べ替え） | 伏せ → 伏せ | 公開通知は「見た」「戻した」の事実のみ（ID・券面なし） | 不要 |
| `deck` → `acquired`（034 の無料獲得で `explore_deck` の上を獲得。探査で山札の上を探査） | 伏せ → 表（公開） | `cardAcquired`（ID と券面） | 不要 |
| `row` → `acquired` | 表 → 表 | `cardAcquired` | 不要 |
| 任意 → `removed` | 伏せ／表 → 表（公開） | `cardsRemoved`（ID と券面） | 不要（ゲームから消える） |
| `explore_deck` の上 → `row`（011、帰還フェイズ③の補充） | 伏せ → 表 | `rowCardAdded`／`rowRefilled`（ID と券面） | 不要 |

#### 7.3 伏せたカードの ID を送らない設計【提案】

- クライアントの `Deck` コンポーネント（009 §10）は、`fakeCardGenerator` で裏面を出して枚数のカウンターだけで動く。このため、`deck`、`facedown`、`explore_deck` のカードに**サーバーの ID は不要**。
- 山札から `facedown` へ、山札から準備場へ動くアニメーションは、クライアントが生成する一時 ID（たとえば負の連番）で行う。
- 009 §12 T-007 は「カードを識別するための中身と無関係な ID」を送ると書いているが、本仕様では**送らない**（枚数のみ）。ID 連番や `AUTO_INCREMENT` の順序から山札の順序や券面を推測させるリスクを根本的に除くため。DOM の取り違えは、枚数のカウンターと一時 ID で足りる。（未確定事項 No.3 は枚数を全員に見せるかであり、ID の扱いとは別）

#### 7.4 `changeIds()` を呼ぶ規則【提案】

1. 呼ぶ対象は「表向きだったカードが伏せられた」ときの、そのカードだけ（§7.2 の表の「実行」）。
2. 渡す配列は、乱数で並べ替えてから渡す（V6: 新しい ID の割り当て順が AUTO_INCREMENT の順だった場合に、山札の位置と ID の大小が対応しないようにする）。
3. 付加カードは、呼ぶ前に付け先（046）から外す（`card_location_arg` が 046 の ID のため。V6）。外した付加カードは 046 と同時に処理する。
4. `resolve_entry.entry_card_id`、`locked_card.card_id` など、**カード ID を値で保持している他のデータ**は、そのカードの ID が付け替わる時点で更新するか null にする。付け替えが起きるカードは「表向きから山札へ戻るカード」と「055 の解決後の山札・`facedown` のカード」だけで、前者は `entry_card_id` を null にし（`'returned'` の行。§4.4）、後者は他のデータから参照されない。
5. 付け替えの対応表（古い ID → 新しい ID）は通知に載せない。古い ID だけを `cardReturned` などに載せる。
6. 帰還フェイズ②では、まとめて山札に戻すカード全員分（`prep`、`attached`、`acquired`）について、古い ID の配列をプレイヤーごとに通知に載せてから付け替える。

#### 7.5 デッキを参照する効果（055）の解決中の扱い【提案】

作者回答（006 未確定事項 No.13）の例外を、次のように実装する。

- 状態 47 `DeckExcludeChoice` の `getArgs()` が `DeckView::forOwner(pid)`（§3.5）を呼び、`_private[pid].deckCards` に次の配列を入れる。**配列の順は `card_no` の昇順、同じ `card_no` は `card_art` の昇順**にして、山札の順序と `facedown` の積み順を漏らさない。

```json
{
  "deckCards": [
    { "id": 101, "cardNo": 4, "art": 0, "zone": "deck" },
    { "id": 230, "cardNo": 12, "art": 0, "zone": "facedown" }
  ]
}
```

- `zone` は `'deck'`、`'prep'`、`'acquired'`、`'facedown'` のいずれか。`prep` と `acquired` のカードは公開済みだが、選択肢を 1 つの一覧にそろえるため同じ配列に入れる。お守りは含めない（除外できない。001 §4）。046 の付加カードの扱いは未確定事項 No.5。
- 公開の args（`pending` など）には、候補の数も含めない（候補の枚数から山札の枚数が分かる。山札の枚数は公開情報だが、`facedown` を含む合計までは公開していないため）。
- `actExcludeDeckCards(int[] cardIds)` が処理されたら、除外されたカードを `removed` へ移して `cardsRemoved`（公開。ID と券面）を送る。その後、このプレイヤーの `deck` と `facedown` のカード全てに `changeIds()` を呼ぶ（本人のクライアントが覚えている ID を使えなくする）。
- 状態 47 を出た後は、`deckCards` を二度と送らない。リプレイ・観戦には送らない（`_private` は本人のみ。V8 の懸念から、リプレイで他人に見えても困らないよう、`deckCards` を通知（`notify`）には載せない）。
- 055 を獲得したがデッキが空に近い場合など、候補が 0 枚でも状態 47 に入る（任意効果なので `0 枚可`）。

### 8. `getAllDatas`

ページ再読み込み・途中参加時に 1 回だけ呼ばれ、盤面の全体を返す（D8）。閲覧者の視点は引数 `$currentPlayerId` で受け取る。`getAllDatas` に `_private` を返せるかは【未確認】（V7）のため使わず、閲覧者ごとに内容を分岐する。観戦者は「`$currentPlayerId` が `player` テーブルに含まれない」で判定する（V8）。

#### 8.1 視点ごとの違い

| 項目 | 本人 | 他プレイヤー（本人から見た相手） | 観戦者 |
| --- | --- | --- | --- |
| 盤面（準備場、付加カード、獲得カード、列、未確認帯域、除外、お守り） | ID と券面を送る | 同左 | 同左 |
| 山札 | 枚数のみ | 枚数のみ | 枚数のみ |
| 裏向きカード（`facedown`） | 枚数のみ | 枚数のみ | 枚数のみ |
| `setup_removed` | 送らない | 送らない | 送らない |
| 探査山札 | 枚数のみ | 枚数のみ | 枚数のみ |
| 準備選択の内容（`prep_choice`） | 送らない（現在の状態の args の `_private.myChoice` が復元する） | 送らない | 送らない |
| 準備終了（`prep_finished`）、半減（`is_halved`）、確定した探査出力 | 送る | 送る | 送る |
| 名声合計 | オプション有効時のみ（§11.3） | オプション有効時のみ | **常に送らない**（009 No.15 の回答） |
| 本人にだけ見せる一時的な情報 | 現在の状態の args の `_private` が返す | — | — |

つまり、`getAllDatas` の内容は本人・他プレイヤー・観戦者で**名声合計の有無だけ**が違い、非公開情報は誰にも含まれない。一時的に本人にだけ見せる情報（005 で見た 2 枚など）は、`getAllDatas` ではなく現在の状態の args の `_private` が復元する（006 §9.1「再接続・リロード」）。

#### 8.2 戻り値の構造【提案】

数値は整数で送る（BgaCards の注意。009 §10）。`card` オブジェクトは `{ "id": int, "cardNo": int, "art": int }`。

```json
{
  "options": { "charmCardNo": 57, "showFame": false },
  "round": 1,
  "phase": "prep",
  "playerOrder": [2310001, 2310002, 2310003],
  "rowQuota": { "2": 3, "4": 3, "6": 2, "8": 2 },
  "piles": { "2": 12, "4": 12, "6": 10, "8": 8 },
  "rows": {
    "2": [ { "slot": 0, "card": { "id": 11, "cardNo": 4, "art": 0 } } ],
    "4": [],
    "6": [],
    "8": []
  },
  "unknown": { "id": 77, "cardNo": 56, "art": 0 },
  "removed": [],
  "charm": { "card": { "id": 90, "cardNo": 57, "art": 0 }, "holder": 2310001 },
  "lockedCard": null,
  "exploreOrder": [],
  "exploreIndex": 0,
  "exploreState": null,
  "nextChoice": { "forceDraw": false, "drawOnly": [], "stopOnly": [], "late": null },
  "players": {
    "2310001": {
      "deckCount": 4,
      "facedownCount": 0,
      "acquiredCount": 0,
      "prepCount": 0,
      "prepFinished": false,
      "isHalved": false,
      "outputNow": 0,
      "output": null,
      "prep": [],
      "acquired": [],
      "fame": null
    }
  }
}
```

| キー | 内容 | 補足 |
| --- | --- | --- |
| `options` | `charmCardNo`（57 か 58）、`showFame`（名声表示オプション） | ゲームオプションは JS でも読めるが（D17）、描画に必要な値を 1 か所にまとめる |
| `round`、`phase` | §4.2 | — |
| `playerOrder` | 席順（`player_no` 昇順）のプレイヤー ID | 時計回りの距離の計算にクライアントも使う |
| `rowQuota` | 列の規定枚数 | 3 人 3／3／2／2、4 人 4／3／2／2 |
| `piles` | 探査山札の枚数（コスト別） | 枚数 0 の山は空枠（009 §8.2）。コスト 8 が 0 なら終了条件の注意表示 |
| `rows` | 列のカード（ID、券面、スロット） | 空きスロットは含めない。固定スロットの個数は `rowQuota`（011 で超えたぶんはスロットを足す） |
| `unknown` | 未確認帯域の ID と券面。獲得済みなら null | — |
| `removed` | 除外されたカード（ID と券面） | 公開情報。除外の履歴の表示用 |
| `charm` | お守りのカードと所持者 | — |
| `lockedCard` | 007 のロック `{playerId, cardId}` | 探査フェイズ以外では null |
| `exploreOrder`、`exploreIndex`、`exploreState` | 探査手番。`exploreState` は §4.2 の公開部分 | — |
| `nextChoice` | 次の準備選択への制約（028／029／049／054）。公開情報（効果はカードが表向きに公開された時点で全員が知る） | 消費したら空 |
| `players[pid].prep` | 準備場のカードの配列。各要素は `{ "card": {...}, "order": int, "sideways": bool, "attached": [ {...} ] }` | `order` は準備した順の通し番号。UI の番号の振り方は 009 §8.2 に従う |
| `players[pid].acquired` | このラウンドの獲得カード（ID と券面） | 獲得は公開 |
| `players[pid].outputNow` | 準備中の暫定探査出力（半減を含めない。009 No.18） | `output` が非 null なら同じ値 |
| `players[pid].output` | 確定した探査出力。未確定は null | 半減とお守りの加算を含む |
| `players[pid].fame` | 名声合計。非表示のとき、または観戦者には null | §11.3 |

- 準備選択の「選択済み」は、現在の状態 21 の args（`chosen`）が復元する（V11 のため、通知 `prepMarked` と併用）。
- 山札の中身、`facedown`、`setup_removed`、`prep_choice`、`entry_decision` は戻り値に含まれない。コードレビューでは、`getAllDatas` が `card` テーブルを丸ごと読んで整形していないこと（location で絞った専用の読み出しを使っていること）を確認する。

### 9. 状態ごとの args

006 §2 の状態表を基に、公開部分と `_private` の中身を具体化する。**全ての args で `getArgs()` は DB を読むだけ**（D20）。キーは camelCase。数値は整数。`card` は `{ "id", "cardNo", "art" }`。

#### 9.1 準備フェイズ（20〜24）

| 状態 | 公開 args | `_private[プレイヤー ID]` | 備考 |
| --- | --- | --- | --- |
| 20、22、24（GAME） | なし | なし | args を持たない |
| 21 `PrepChoice` | `selectors`（int 配列。この回の選択者）、`chosen`（int 配列。選択済みの ID。**内容は含めない**）、`forced`（`[{ "playerId", "choice": "draw"／"stop", "reason": "memoryAlbum"／"safetyDevice"／"bigBang"／"emptyDeck" }]`）、`lateOwner`（int／null。049 の持ち主）、`round` | `myChoice`（null／`"draw"`／`"stop"`）、`canDraw`（山札が 1 枚以上か） | `_private` は `selectors` の全員分を作る（V11: 選択済みで非アクティブでも本人に返せるようにする）。`forced` の確定済みの選択は公開情報（006 §4.6） |
| 23 `PrepChoiceLate` | `revealedChoices`（`{ "playerId": "draw"／"stop" }`。22 で公開済みの他プレイヤーの選択）、`canDraw`（持ち主の山札が 1 枚以上か） | なし | 秘密情報はない（開示済み）。選べる選択肢が 1 つだけなら状態に入らない（006 §2.2） |

`reason` の値: `memoryAlbum`（028）、`safetyDevice`（029）、`bigBang`（054）、`emptyDeck`（山札 0 枚）。

#### 9.2 ドロー時効果の解決（30〜35）

| 状態 | 公開 args | `_private[プレイヤー ID]` | 備考 |
| --- | --- | --- | --- |
| 30 `DrawResolve`（GAME） | なし | なし | — |
| 31 `ReturnPodDecision` | `targets`（決める人の ID）、`decided`（決定済みの ID。**内容なし**）、`podOwner`（043 の持ち主） | `myCard`（`card`。自分がこの回に引いたカード。公開済みの情報）、`myDecision`（null／`true`／`false`） | `entry_decision` は本人にだけ返す。他人の `entry_decision` は読んでも args に載せない |
| 32 `CopySourceChoice` | `candidates`（`card` の配列。列の[太陽系]／[天の川銀河]を持つカード）、`copyCardId`（008 の ID） | なし | 対象が 0 枚なら状態に入らない |
| 33 `WormholeChoice` | `revealedCard`（`card`。036 の持ち主の山札の上のカード。表にしたので公開）、`candidates`（`card` の配列。列の同じ探査コストのカード）、`ownerId` | なし | 同コストの列カードが 0 枚なら入れ替え不可（シャッフルのみ自動） |
| 34 `LaserSightChoice` | `candidates`（`card` の配列。列のカード。056 を除く）、`ownerId` | なし | — |
| 35 `SimultaneousEffects` | `pending`（`[{ "playerId", "effectCardId", "effectCardNo" }]`。未完了の者と効果カード。何をするかは効果カードから分かる公開情報） | `effect`（`arrangeTop`／`yesNo`／`topOrBottom`／`pickFromDeck`／`pickOne`）、`seenCards`（`card` の配列。005 は山札の上の 2 枚、010 は 1 枚、052 は 2 枚。山札の上から順）、`candidates`（`card` の配列。020・035・050 の山札内の候補。`card_no` の昇順）、`prevCard`（009 の直前のカード。公開情報） | 山札の中身を見せるのは該当プレイヤーの `_private` のみ。`seenCards` は山札の上から読むだけで山札は動かさない |

- 35 の `seenCards` は、状態に入ってから本人がアクションするまで山札が動かないので、`getArgs` のたびに山札の上から同じ枚数を読み直して返せる（リロードで復元できる。DB は書かない）。
- 31 の `myCard` は、自分が引いた表向きのカードなので公開情報。内容を返しても漏れではないが、`myDecision` は本人だけに返す。

#### 9.3 探査フェイズ（40〜48）

| 状態 | 公開 args | `_private` | 備考 |
| --- | --- | --- | --- |
| 40、41、44、48（GAME） | なし | なし | — |
| 42 `ExploreOnExploreChoice` | `usable`（`[{ "cardId", "cardNo", "effect": "radar"／"localMap", "deckCosts": [2, 4] }]`。`deckCosts` は 011 のとき、枚数 1 以上の探査山札のコスト。013 では省略） | なし | — |
| 43 `ExploreChoose` | `output`（探査出力）、`rowTargets`（`[{ "cardId", "slot", "cost", "effectiveCost" }]`）、`unknownTarget`（`{ "cardId", "effectiveCost" }`／null）、`deckTargets`（`[{ "cost", "effectiveCost", "count" }]`。枚数 0 の山は除く）、`additional`（bool）、`exploredCostSum`、`canPass`（常に true）、`lockedCard`（`{playerId, cardId}`／null） | なし | **探査山札の上の券面や ID は入れない**。山札を選ぶときは `cost` だけを返す（001 §6-3-1）。`effectiveCost` は探査中効果、013 の補正、007 のロック、025／026 の制限を反映済みの値（サーバーが計算。009 §12 T-006）。探査できない対象は一覧に含めない |
| 45 `AcquireExcludeChoice` | `candidates`（`card` の配列。準備場の表向き・横向きのカード。付加カードと `facedown` を含まない。046 は本体のみ） | なし | 006 §2.4 |
| 46 `FreeAcquireChoice` | `rowCandidates`（`card` の配列。[太陽系]または[天の川銀河]のみを持つ列のカード）、`deckCandidates`（int 配列。探査山札のコスト。枚数 1 以上のコスト 2／4 のみ。上のカードの中身は送らない） | なし | 006 §2.4（未確定事項 No.7 の回答）。[未確認帯域]は条件を満たさないので候補にしない |
| 47 `DeckExcludeChoice` | `maxCount`（2） | `deckCards`（§7.5。`{ id, cardNo, art, zone }` の配列。`card_no` の昇順） | 公開の args に候補の数を入れない（§7.5） |

#### 9.4 帰還フェイズ・終了（50〜60）

| 状態 | 公開 args | `_private` | 備考 |
| --- | --- | --- | --- |
| 50、52、53、60（GAME） | なし | なし | — |
| 51 `AmuletExcludeChoice` | `candidates`（`card` の配列。準備場の表向き・横向き。`facedown` と付加カードを含まない）、`maxCount`（1） | なし | — |

#### 9.5 `_private` の作り方【提案】

- `_private` のキーは**その状態で選択が必要なプレイヤー全員**分を作る。キーのないプレイヤー（選択者でない人、観戦者）には何も渡らない。
- `'active'` キーは使わない（プレイヤー ID 形式と併用不可。006 B5）。
- 山札の内容を含む配列（`seenCards`、`candidates`、`deckCards`）は、**山札の順序が分かる並びで返さない**。ただし `seenCards` は「山札の上から n 枚」の順序そのものが意味を持つ（005 の並べ替え、052 の上下）ので、その n 枚の順を保つ。それ以外（020・035・050 の候補、055 の一覧）は `card_no` 昇順にする。

### 10. 通知

#### 10.1 共通の約束

- 送信は `$this->bga->notify->all()`（全員・観戦者）、`$this->bga->notify->player()`（本人のみ。卓のプレイヤーに限る）。D9。通知はアクションが正常に終わった時点でまとめて送られる（D10）。GAME 状態の連鎖（006 §3）で複数の通知を出しても、1 回のリクエストの終わりに順序どおり届く。
- クライアントは `notif_<通知名>` ハンドラを持ち、通知を順番に Promise で処理して画面を更新する。画面の更新は通知か `getAllDatas` だけで行い、アクションの応答には頼らない（D19、003 §4）。
- ログ文言は英語の原文を `clienttranslate()` で包み、日本語化は BGA の翻訳機構で行う（CLAUDE.md）。プレイヤー名は `${player_name}`（`player_id` を同じ args に入れる。D11）。カード名は `${card_name}` に英語名の文字列を入れ、`i18n: ['card_name']` を付ける。カードの画像・アイコンへの置換は JS の `bgaFormatText` 相当の処理に任せ（T-009）、**args に HTML を入れない**（D10）。`card_no` も args に入れる（JS が小さな画像を作るため）。
- 画面だけを更新し、ログに出したくない通知のメッセージは空文字 `''` にする。
- `card` は `{ "id", "cardNo", "art" }`。**args に載せてよいカードは、その時点で表向きに公開されているものだけ**（§7）。
- 履歴（ログ）通知で残る引数は `i18n`、`player_id`、メッセージで参照した引数だけになる（D10）。ログに必要な引数（`card_name` など）はメッセージ内で参照する。リプレイの再生は保存された通知全体を使う（D19）ので、`preserve` は原則使わない。
- 通知 1 件の目安は 2 KB 以内（上限 128 KB。D10）。山札全体の一覧は通知に載せない（§14）。

#### 10.2 通知の一覧

「全員」は `notify->all`（観戦者含む）、「本人」は `notify->player`。以下の 35 種。タイミングの状態番号は 006。

| No. | 通知名 | 送り先 | args | ログ文言（英語原文） | 送る処理 |
| --- | --- | --- | --- | --- | --- |
| N1 | `roundStarted` | 全員 | `round` | `Round ${round} begins` | 10。クライアントは `lockedCard`、`exploreState`、横向き表示などをリセット |
| N2 | `choiceRevealed` | 全員 | `player_id`、`player_name`、`choice`（`draw`／`stop`）、`choice_label`（i18n: `Draw`／`Stop`）、`reason`（null／`memoryAlbum`／`safetyDevice`／`bigBang`／`emptyDeck`） | `${player_name} chooses ${choice_label}` | 22 で 049 の後選びがあるとき（後選び以外の全員分）、それ以外は 24 の冒頭（全員分）。049 の持ち主の分は 23 の確定後に 24 で送る。選択者でなかった準備終了済みのプレイヤーには送らない |
| N3 | `prepMarked` | 全員 | `player_id` | `''`（ログなし） | 21 で `prep_choice` が null から値に変わった最初の 1 回（選択の変更では送らない）。内容は載せない。V11 のため args の `chosen` と併用 |
| N4 | `prepChoiceRecorded` | 本人 | `choice`（`draw`／`stop`） | `''` | 21 の `actSelectPrep`（初回と変更のたび）。同じプレイヤーの他のタブを同期する |
| N5 | `prepFinishedCleared` | 全員 | `player_ids`（int 配列） | `Preparation is no longer finished for ${names}`（`names` は i18n 不要のプレイヤー名の連結文字列） | 20 で 054 の記録により準備終了を打ち消したとき（山札 0 枚の人は含めない。006 §4.6） |
| N6 | `cardDrawn` | 全員 | `player_id`、`player_name`、`card`、`card_name`、`order`、`reason`（`draw`／`pod`／`drill`／`recycler`／`fuel`）、`source_name`（`pod` 以外の追加準備の元のカード名。それ以外は空） | `draw`: `${player_name} draws ${card_name}`。`pod`: `${player_name} prepares ${card_name} after returning the drawn card`。`drill`／`recycler`／`fuel`: `${player_name} prepares ${card_name} (${source_name})` | 24（ドロー）、31 の結果の適用（`pod`。043）、35 の 009（`drill`）・014（`recycler`）、30 の P3（`fuel`。024） |
| N7 | `faceDownSet` | 全員 | `player_id`、`player_name` | `${player_name} sets aside a face-down card` | 24 で、ストップしたプレイヤーが山札の上から 1 枚を `facedown` に取ったとき（ドロー 1 人以上の回のみ）。**カードの情報を載せない** |
| N8 | `cardSideways` | 全員 | `player_id`、`player_name`、`card_id`、`card_name` | `${card_name} is turned sideways: its power and effects are ignored` | 24 で 1 人だけがドローしたとき（③-3）。`is_halved` を立てる |
| N9 | `effectSuppressed` | 全員 | `player_id`、`player_name`、`card_id`、`card_name`、`source_card_id`、`source_name` | `The draw effect of ${card_name} (${player_name}) is cancelled by ${source_name}` | 30 の P1。ドロー時効果を持つカードが 039／053 に止められた各カードについて 1 件 |
| N10 | `podDecided` | 全員 | `player_id`、`player_name` | `''` | 31 の `actDecideReturn`。**決定の内容を載せない** |
| N11 | `podKept` | 全員 | `player_id`、`player_name` | `${player_name} keeps the drawn card` | 31 で全員が決めた後、戻さなかった各プレイヤーについて 1 件 |
| N12 | `cardReturned` | 全員 | `player_id`、`player_name`、`card_id`（**古い ID**）、`card_name`、`reason`（`pod`／`drill`／`recycler`／`wormhole`） | `${player_name} returns ${card_name} to their deck` | 31 の結果（`pod`）、35 の 009・014、33 で入れ替えなかったとき（`wormhole`。表にした山札の上のカードを山札へ戻す）。列から山札へ渡るカード（036）も、`wormholeSwapped` と合わせて古い ID を送る |
| N13 | `deckShuffled` | 全員 | `player_id`、`player_name` | `${player_name} shuffles their deck` | 山札をシャッフルした直後（043 の結果、009、014、020、035、036、050）。**順序や ID は載せない**。帰還フェイズ②は N29 に含め、N13 を重ねて出さない |
| N14 | `attachedCardDrawn` | 全員 | `player_id`、`player_name`、`host_card_id`、`host_name`、`card`、`card_name` | `${player_name} places ${card_name} beside ${host_name}` | 30 の P2a（046） |
| N15 | `copySourceChosen` | 全員 | `player_id`、`player_name`、`card_id`（008）、`source`（`card`）、`card_name`、`source_name` | `${player_name} copies ${source_name} with ${card_name}` | 32 の `actChooseCopySource` |
| N16 | `wormholeRevealed` | 全員 | `player_id`、`player_name`、`card`、`card_name` | `${player_name} reveals ${card_name} from the top of their deck` | 33 に入るとき（`getArgs` の `revealedCard` と同内容）。山札の上のカードを表にするので公開 |
| N17 | `wormholeSwapped` | 全員 | `player_id`、`player_name`、`card`（列に入るカード）、`card_name`、`cost`、`slot`、`row_card_id`（**古い ID**。山札に入る列のカード）、`row_card_name` | `${player_name} swaps ${card_name} with ${row_card_name} in the ${cost}-cost row` | 33 の `actWormhole`（入れ替えるとき）。続いて N13 |
| N18 | `laserSightLocked` | 全員 | `player_id`、`player_name`、`card`、`card_name` | `${player_name} reserves ${card_name} for this round` | 34 の `actChooseLockedCard`（`locked_card` を更新） |
| N19 | `deckLooked` | 全員 | `player_id`、`player_name`、`kind`（`top`／`search`）、`count`（`top` のとき）、`source_name` | `top`: `${player_name} looks at the top ${count} card(s) of their deck (${source_name})`。`search`: `${player_name} searches their deck (${source_name})` | 35 の 005・010・052（`top`）、020・035・050（`search`）の各アクション。**見たカード・選んだカード・置き方（上か下か）は載せない**（006 §5.4） |
| N20 | `cardsRemoved` | 全員 | `player_id`、`player_name`、`card`（公開される券面と ID）、`card_name`、`from`（`prep`／`deck`／`facedown`／`acquired`）、`source_name` | `${player_name} removes ${card_name} from the game (${source_name})` | 45（004）、47（055）、51（058）、42（013 を除外）。複数枚は 1 枚ずつ 1 件。**伏せていたカードの券面は、除外された時点で公開される** |
| N21 | `rowCardAdded` | 全員 | `player_id`、`player_name`、`cost`、`slot`、`card`、`card_name`、`source_name` | `${player_name} adds ${card_name} to the ${cost}-cost row (${source_name})` | 42 の 011（探査山札の一番上を列へ） |
| N22 | `exploreOutputDecided` | 全員 | `player_id`、`player_name`、`output`、`halved`（bool） | `${player_name} has an exploration output of ${output}` | 40（全員分を席順に 1 件ずつ） |
| N23 | `exploreOrderDecided` | 全員 | `order`（int 配列）、`order_names`（プレイヤー名の連結文字列） | `Exploration order: ${order_names}` | 40 |
| N24 | `exploreTurnStarted` | 全員 | `player_id`、`player_name`、`index`（0 始まり） | `${player_name} starts the exploration turn` | 41（手番の開始時） |
| N25 | `cardAcquired` | 全員 | `player_id`、`player_name`、`card`、`card_name`、`from`（`row`／`unknown`／`deck`）、`cost`（探査山札の上の獲得時は山札のコスト）、`slot`（列のとき）、`free`（bool）、`additional`（bool） | `free` でない: `${player_name} explores ${card_name}`。`free`: `${player_name} acquires ${card_name} for free` | 43（`actExplore`。山札の上のときはこの時点で券面と ID を公開）、46（034）。その後 44 が獲得時効果を処理 |
| N26 | `explorePassed` | 全員 | `player_id`、`player_name`、`auto`（bool） | `auto` でない: `${player_name} passes`。`auto`: `${player_name} has nothing to explore and passes automatically` | 43（`actPass`）、自動パス（41／43 に入らず 48 へ進むとき。006 §6.1）。012 の追加探査のパスも含む |
| N27 | `gameEndTriggered` | 全員 | `reason`（`unknown`／`pile8`）、`reason_label`（i18n）、`player_id`（`unknown` のとき獲得者。それ以外は 0）、`player_name` | `The game ends at the end of this round (${reason_label})` | 44（未確認帯域の獲得）、44／53（コスト 8 の山の枯渇） |
| N28 | `amuletMoved` | 全員 | `player_id`（新しい所持者）、`player_name`、`from_player_id`、`card`、`card_name` | `${card_name} moves to ${player_name}` | 50 |
| N29 | `cardsGatheredToDecks` | 全員 | `gathered`（`{ "プレイヤー ID": [古い ID, ...] }`。`prep`、`attached`、`acquired` の表向きだったカードのみ）、`counts`（`{ "プレイヤー ID": 新しい山札の枚数 }`） | `All players shuffle their cards into their decks` | 52。`facedown` の ID は載せず、枚数は `counts` に含める。通知のあとに N30 |
| N30 | `beaconPlaced` | 全員 | `player_id`、`player_name`、`card_name` | `${player_name} puts ${card_name} on top of their deck` | 52（040 を探査フェイズで獲得していたとき）。新しい ID は載せない |
| N31 | `rowRefilled` | 全員 | `cost`、`cards`（`[{ "slot", "card" }]`） | `${count} card(s) are added to the ${cost}-cost row`（`count` は引数） | 53（補充したコストごとに 1 件。補充がないコストは送らない） |
| N32 | `nextChoiceRecorded` | 全員 | `kind`（`forceDraw`／`drawOnly`／`stopOnly`／`late`）、`player_id`、`player_name`、`card_name` | `forceDraw`: `${player_name}'s ${card_name} forces everyone to choose Draw next`。`drawOnly`: `${player_name} must choose Draw next (${card_name})`。`stopOnly`: `${player_name} must choose Stop next (${card_name})`。`late`: `${player_name} will choose after the others next (${card_name})` | 30 の P6（確定時）。43 で戻された 054 は通知を出さない（H2） |
| N33 | `countersUpdate` | 全員 | `players`（`{ "プレイヤー ID": { "deckCount", "facedownCount", "acquiredCount", "prepCount", "outputNow" } }`。変更があった者だけ）、`piles`（`{ "2", "4", "6", "8" }`。変更があったときだけ） | `''` | 各 GAME 状態の処理の最後と、各アクションの最後。山札・裏向き・獲得・準備場・お守りの変化で再計算する |
| N34 | `fameUpdate` | 本人（各プレイヤーに 1 件ずつ送る。観戦者には送らない） | `fame`（`{ "プレイヤー ID": 名声合計 }`） | `''` | ゲームオプション `showFame` が有効なときだけ、N33 と同じ契機（デッキの内容や名声変動の入力が変わるとき） |
| N35 | `finalScore` | 全員 | `player_id`、`player_name`、`fame`、`base`、`charm_bonus`（0 または 1）、`unknown_acquired`（bool）、`symbols`（`{ "laniakea", "virgo", "milkyWay", "solar" }`） | `${player_name} scores ${fame} fame` | 60（終了時に全員分を席順に。名声表示オプションに関わらず公開） |

- N1〜N35 のうち「本人」宛ては N4 と N34 の 2 種（N34 は卓のプレイヤー全員に 1 件ずつ送る）。他は全員宛て。**内容の非公開が必要な通知は、全員宛てに非公開の内容を載せず、本人宛ての N4 と、状態の `_private` args（§9）で本人に渡す**。公開通知に `_private` を併用する設計（D9）は、現状の通知では必要としない（本人だけに見せる情報は args の `_private` で全てまかなえるため）。
- N2 と N3 の役割分担: N3 は選択の途中経過（誰が選び終えたか）、N2 は確定後の内容の公開。N3 は 006 §9.2 の「公開通知は出さない」から外れるが、V11 のため必要と判断した（食い違い。未確定事項 No.9）。

#### 10.3 公開通知に載せてはいけない項目

| 項目 | 理由 |
| --- | --- |
| 山札・`facedown`・探査山札のカードの ID と券面（N6 などで「引いた」ものは除く） | §7 |
| N7 `faceDownSet` に引いたカードの情報 | 本人にも見せない（009 No.11） |
| N10 `podDecided` に決定の内容 | H8 |
| N3 `prepMarked` に選択の内容 | 001 §5 ①、009 §9.1 |
| N13 `deckShuffled` にシャッフル後の順序 | 山札の順序 |
| N19 `deckLooked` に見たカード、選んだカード、上か下か | 006 §5.4 |
| N29 `cardsGatheredToDecks` に `facedown` の ID | §7.3 |
| 全ての通知に `getArgs` の `_private` の中身 | 本人以外に漏れる |

#### 10.4 流れの例（1 回の「2 人がドロー、うち 1 人が 043」）

```text
21: actSelectPrep (A, B, C)   -> N4 (本人), N3 (全員, 内容なし) × 3, 全員選択済みで 22 へ
22/24:                         N2 × 3 (A=draw, B=draw, C=stop)
24:                            N6 (A の引いたカード), N6 (B の引いたカード), N7 (C 用), N33
30: P1                         N9 (必要なら)
31: actDecideReturn (B)        N10 (B)  ※ 043 を引いたのが A の場合の B の決定
31 終了:                       N12 (B が戻したとき) → N13 → N6 (reason: pod)  /  N11 (戻さないとき)
30: P6                         N32 (028/029/049/054 があれば)
                               N33
```

#### 10.5 通知から見た `changeIds()` の呼び出し位置

| 通知 | `changeIds()` の呼び出し |
| --- | --- |
| N12 `cardReturned`（`pod`、`drill`、`recycler`、`wormhole`） | 戻したカードを山札に入れた直後（シャッフルの前）。通知には古い ID だけ載せる |
| N17 `wormholeSwapped` | 山札に入る列のカードに対して、山札に入れた直後 |
| N29 `cardsGatheredToDecks` | 通知に古い ID を載せた後、シャッフルの前に全員分 |
| 047 の解決後（N20） | 除外が済んだ後に、そのプレイヤーの `deck` と `facedown` の全カードに（§7.5）。通知なし |

付け替えを通知の前に行うと古い ID が分からなくなるため、**通知の args を組み立ててから付け替える**（`changeIds()` の戻り値の対応表は通知に載せない）。

### 11. スコア・進行度・名声の表示

#### 11.1 `playerScore` の更新方針【提案】

- `playerScore->set／inc` は BGA の標準のスコア欄を更新する（D14）。名声の合計は既定で非表示（009 No.5）なので、**ゲーム中は `playerScore` を更新しない**（0 のまま）。`playerScore` を更新する通知は観戦者にも届くため、名声合計を観戦者に見せない要件（009 No.15）に反する。
- 状態 60 `FinalScoring` で、全員の最終名声を `playerScore->set` で書き、同点処理の値を `playerScoreAux->set` で書く。
- ゲーム中に BGA のスコア欄の「0」が見えてしまう問題は、スコア欄の DOM を CSS で隠す方針（未確定事項 No.6）。

#### 11.2 同点処理の値（`playerScoreAux`）【提案】

001 §9-3 の順（未確認帯域の獲得 → ラニアケア少 → 乙女座少 → 天の川少 → 太陽系少）を、1 つの整数に符号化する。BGA は `playerScore` が同点のとき `playerScoreAux` の大きい方を上位とする（D14 の「同点処理」）。

```text
aux = unknownAcquired * 100000000
    + (99 - laniakeaCount) * 1000000
    + (99 - virgoCount)    * 10000
    + (99 - milkyWayCount) * 100
    + (99 - solarCount)
```

- `unknownAcquired` は `unknown_acquirer` が自分なら 1、そうでなければ 0。
- 各シンボルの枚数は、デッキ全体（山札、準備場、獲得カード、`facedown`）のそのシンボルを持つカードの枚数。複数シンボルのカードは各シンボルで 1 枚と数える（001 §9-3 Q-23）。お守りは 4 つのシンボルを持たないので結果に影響しない。地球は同点処理に使わない。
- 最大値は 199 999 999 で、符号付き 32 ビット整数に収まる（V9）。枚数が 99 を超えることはない（全カード 58 枚）。
- 全ての項が同じプレイヤーは `playerScore` と `playerScoreAux` が同じになり、勝利を分かち合う（001 §9-3）。

#### 11.3 名声合計の表示

| 条件 | 本人 | 他プレイヤー | 観戦者 |
| --- | --- | --- | --- |
| ゲームオプション `showFame` が無効（既定） | 見せない（暫定。未確定事項 No.2） | 見せない | 見せない |
| `showFame` が有効 | 見せる | 見せる | **見せない**（009 No.15 の回答） |
| ゲーム終了（60 の後） | 全員の最終名声を N35 で公開（暫定。未確定事項 No.2） | 同左 | 同左 |

- 名声合計 = デッキ（山札、準備場、付加カード、獲得カード、`facedown` の全カード）の名声を各 1 回ずつ足した合計 + 名声変動の効果の反映 + お守りの +1（所持者のみ。001 §9-2）。付加カードの名声は 046 に付加される（002 No.50）が、ゲーム終了時に付加カード自身の名声と二重には数えない（002 No.63）ので、付加カードは自身の名声で 1 回だけ数え、046 への付加ぶんは足さない。名声変動の計算は T-008。
- 名声合計は、山札を含む全デッキから計算するが、各プレイヤーのデッキの構成（山札の順序を除く）は公開情報（初期カード、獲得、除外、036 の入れ替えが全て公開）から導ける。このため合計だけを出しても山札の順序は漏れない。ただし、合計を計算する際に山札の中身を通知や args に載せない。
- `showFame` が有効のとき、`fameUpdate`（N34）は卓の各プレイヤーに `notify->player` で 1 件ずつ送る。`notify->all` は観戦者に届くため使わない。観戦者がリプレイで N34 を見られるかは V8。
- `showFame` が無効のとき、`getAllDatas` の `fame` は null、N34 は送らない。

#### 11.4 進行度（`getGameProgression`）

式は 006 §8 のとおり。入力は `pile8_initial`、`piles_initial_total`（§4.2）と、現在の `explore_deck` の枚数。

```text
g8       = 1 - count(explore_deck, 8) / pile8_initial
gAll     = 1 - sum(count(explore_deck, c)) / piles_initial_total
progress = min(99, floor(100 * max(g8, 0.8 * gAll)))
```

`updateGameProgression: true` は 10 と 53 に付ける（006 §8）。DB を読むだけで書かない。

### 12. 統計（`stats.json`）

#### 12.1 方針

- ファイル名: Studio の索引は `stats.jsonc`、統計の説明ページは `stats.json`（`.jsonc` も可）とする（D16。003 U6 と同じ表記ゆれ）。Studio が最初に生成したファイル名に合わせる。
- **ID は 10 以上で、一度公開した ID は再利用できない**（D16）。削除や型の変更は過去データの破壊につながる。このため最初に慎重に採番する: 各統計の目的が確定していること、名前が一般的で後から変えにくいものは避けること、テーブル統計とプレイヤー統計で ID を共有しないこと。**本表は作者の確認後に確定する**（未確定事項 No.14）。確定前でも Studio での試作は可能だが、公開（アルファ以降）後は変更しない。
- 採番: テーブル統計 10〜19、プレイヤー統計 20〜39。未使用の番号は空けたままにする（欠番は問題ない）。
- 全ての統計を `setupNewGame` で `init` する（D15）。
- 型は `int`、`float`、`bool`（D16）。キーは英数字とアンダースコア。

#### 12.2 テーブル統計

| ID | キー | 型 | 名前（英語原文） | 更新契機 |
| --- | --- | --- | --- | --- |
| 10 | `rounds_played` | int | Number of rounds | 53 でラウンド終了ごとに +1 |
| 11 | `prep_steps` | int | Number of Draw/Stop selections | 24 の開示ごとに +1（選択者がいる回） |
| 12 | `solo_draw_rounds` | int | Rounds ended by a lone drawer | 24 の `soloDraw` ごとに +1 |
| 13 | `all_stop_rounds` | int | Rounds where everyone stopped at once | 24 の `allStop`（③-1）ごとに +1 |
| 14 | `end_reason` | int | Game end condition (1 = Unknown Band, 2 = Laniakea pile) | 44／53 で終了フラグを立てたときに設定。`value_labels` で表示名を付ける（書式は【未確認】） |

#### 12.3 プレイヤー統計

| ID | キー | 型 | 名前（英語原文） | 更新契機 |
| --- | --- | --- | --- | --- |
| 20 | `cards_acquired` | int | Cards acquired | 獲得の都度 +1（034 の無料獲得を含む） |
| 21 | `draw_count` | int | Times chose Draw | 24 でドローを選んだ回 |
| 22 | `stop_count` | int | Times chose Stop | 24 でストップを選んだ回（山札 0 枚の強制ストップを含まない） |
| 23 | `forced_choice_count` | int | Times forced to Draw or Stop | 20 で 028／029／054 または山札 0 枚により確定した回 |
| 24 | `solo_draw_count` | int | Times drew alone (halved) | 24 の `soloDraw` |
| 25 | `cards_removed` | int | Own cards removed from the game | `cardsRemoved` の都度 +1 |
| 26 | `max_output` | int | Highest exploration output | 40 で最大値を更新 |
| 27 | `average_output` | float | Average exploration output | 53 で再計算（ラウンドごとの平均） |
| 28 | `pass_count` | int | Times passed | 43 のパス、自動パス |
| 29 | `first_turn_count` | int | Times first in exploration order | 40 |
| 30 | `charm_rounds` | int | Rounds holding the charm | 50（移動後の所持者に +1） |
| 31 | `unknown_acquired` | bool | Acquired the Unknown Band | 44 |
| 32 | `deck_size_final` | int | Final deck size | 60 |

- 名声の最終値は `playerScore` として BGA が扱うので、統計には載せない。
- 統計の更新は DB のカウンタだけなので、通知は要らない。

### 13. ゲームオプション（`gameoptions.json`）とゲーム設定（`gamepreferences.json`）

#### 13.1 ゲームオプション【提案】

ID は 100〜199（D17）。値のキーは文字列。既定値をデフォルトにする。ゲーム作成後は変更できない（D17）。

| ID | 名前（英語原文） | 値 | 既定 | 備考 |
| --- | --- | --- | --- | --- |
| 100 | Charm side | 1＝Safe Passage Charm（057）、2＝Misfortune-Warding Charm（058） | 1 | 001 §3-5（Q-3）。使う面の 1 枚だけを `card` に作る |
| 101 | Show total fame during the game | 1＝No、2＝Yes | 1 | 009 No.5・No.15（全員共通のゲームオプション。既定は非表示）。観戦者には常に非表示。値が `No`／`Yes` の 2 つなのでチェックボックスで表示される（D17） |

```jsonc
{
  "100": {
    "name": "Charm side",
    "values": {
      "1": { "name": "Safe Passage Charm", "tmdisplay": "Safe Passage Charm" },
      "2": { "name": "Misfortune-Warding Charm", "tmdisplay": "Misfortune-Warding Charm" }
    },
    "default": 1,
    "level": "base"
  },
  "101": {
    "name": "Show total fame during the game",
    "values": {
      "1": { "name": "No" },
      "2": { "name": "Yes", "tmdisplay": "Total fame shown" }
    },
    "default": 1,
    "level": "additional"
  }
}
```

- 読み出し: PHP `$this->bga->tableOptions->get(100)`（`?int`）、JS `this.bga.tableOptions.get(100)`（D17）。`getAllDatas` の `options` にも入れる（§8.2）。
- 3 人と 4 人の違いはオプションでなく人数から決める（001 §3）。人数による `displaycondition` は不要。
- `level` の指定（`base`／`additional`）は暫定。お守りの面を `major` にするかは任意。

#### 13.2 ゲーム設定（プレイヤーごとの好み）【提案】

ID は 100〜199（D18）。名声合計の表示は**ゲーム設定にしない**（009 No.15 で全員共通のゲームオプションに確定。一部のプレイヤーだけが見ると不公平になるため）。ゲームの進行に影響しない表示だけを設定にする。

| ID | 名前（英語原文） | 値 | 既定 | `cssPref` | 備考 |
| --- | --- | --- | --- | --- | --- |
| 100 | Opponents' preparation area | 1＝Collapse after 3 rows、2＝Always expanded | 1 | `mj-opp-collapsed`／`mj-opp-expanded` | 009 No.9。`needReload` は false。必要なければ作らない |

- 設定は必須ではなく、009 のレイアウトが確定した後に追加してよい。ID 100 は予約のみ。

### 14. DB 容量（64 MB）への配慮

DB 全体が 64 MB を超えると停止する。最大の消費元は gamelog とリプレイ用のテーブル（D7）。通知は gamelog にもリプレイのアーカイブにも保存される（D10、D19）ので、通知のサイズが直接効く。

| 規則 | 内容 |
| --- | --- |
| 山札全体を載せない | 山札・`facedown`・探査山札の一覧を、通知にも履歴にも載せない（非公開情報の保護と容量の両方） |
| 通知は事実だけ | 公開通知は「誰が何をしたか」と、変化した公開情報（引いたカード、列の変化）だけ。盤面全体の再送は `getAllDatas` に任せる |
| 帰還フェイズの通知 | N29 の `gathered` は古い ID の配列のみ（1 人あたり約 20 件以下）。カードの券面は載せない（クライアントは既に知っている） |
| カウンタの通知 | N33 は変化があったプレイヤーと山だけを載せる |
| 履歴から外れる引数 | メッセージで参照しない引数は履歴から取り除かれる（D10）。ログに要る値だけメッセージで参照する。`preserve` は使わない |
| 通知 1 件のサイズ | 目安 2 KB 以内、最大でも 8 KB 以内（上限は 128 KB。D10） |
| 状態の args を保存しない | args は DB に書かない（D20）。大きな配列は通知でなく args で返す |
| 解決キュー | `resolve_entry` は 1 回の解決の間だけ存在し、終了時に全行を削除する。行数は最大でも 2 人 〜 4 人 + 付加カードの数行 |
| グローバル値 | JSON は小さく保つ（`explore_order`、`explore_state` など）。履歴は持たない |

概算【提案】: 1 ゲーム 15 ラウンド、1 ラウンドあたり 80 件の通知、1 件あたり平均 0.6 KB とすると約 0.7 MB。gamelog とリプレイの二重保存と、他のテーブルを加えても数 MB で、64 MB に対して十分な余裕がある。実装後に Studio で 1 ゲームを通して `gamelog` の大きさを測り、10 MB を超える場合は通知を見直す（テスト D8）。

### 15. テスト観点

Studio で確認する項目。006 §10 のテスト T1〜T20 と合わせて実施する。

| No. | 観点 | 手順と期待結果 |
| --- | --- | --- |
| D1 | ItemManager の API（V1〜V4） | `Location` の渡し方、ORDER の向き、`dbField` 指定と `dbmodel.sql` の表の併用、`initDb()` の要否を、1 人の卓で確かめる。うまくいかなければ代替 B（自前 DAO。§3.6）に切り替える |
| D2 | 山札の上と下 | 010（上／下）、005（並べ替え）、052、020 のあとで、山の順が期待どおり（`peekTop`、`putOnBottom`） |
| D3 | シャッフル | 同じ山札に対して 100 回シャッフルして順序が偏らない（簡易統計）。`ItemManager::shuffle()` を使う場合は、乱数が `bga_rand`／`random_int` かを確認 |
| D4 | 非公開情報の漏れ | 4 人卓で、ブラウザの開発者ツールで全通知と args と `getAllDatas` を収集する。山札・`facedown`・`setup_removed` の ID と券面、他人の `prep_choice`、`entry_decision`、見たカードが含まれない。観戦者・別プレイヤーでも同様 |
| D5 | 準備選択の秘匿 | 21 で選択と変更を繰り返し、公開通知は N3（内容なし）だけ。N2 で開示されるまで `choice` が他人に見えない。リロードで `myChoice` が復元 |
| D6 | 043 の秘匿 | 31 で他人の決定が見えない。N10 に内容がない。決定後の N11／N12 だけ |
| D7 | 055 のデッキ参照 | 47 で本人に `deckCards`（山札・準備場・獲得・`facedown`）。他人には届かない。47 を出た後の args に `deckCards` がない。順序が `card_no` 昇順。除外後に本人の ID が付け替わる |
| D8 | DB 容量 | 1 ゲームを通して `gamelog`／リプレイの大きさを測定 |
| D9 | `getAllDatas` の視点 | 本人・他プレイヤー・観戦者で取得し、§8.1 の表のとおり（名声合計の有無だけが違う） |
| D10 | 名声オプション | `showFame` 有効で N34 が卓のプレイヤーにだけ届く。観戦者には届かず、`getAllDatas` の `fame` が null |
| D11 | 絵柄の割り当て | 3 人と 4 人で、絵柄が重複しない。数値は同じ |
| D12 | 3 人プレイの除外 | 8 枚が `setup_removed` に入り、どこにも送られない。列が 3／3／2／2 |
| D13 | 統計 | 全ての統計が `setupNewGame` で `init` され、終了時に値が入っている。ID が重複しない |
| D14 | `changeIds()` | 戻した/表向きから伏せたカードの ID が付け替わり、`resolve_entry`、`locked_card` に古い ID が残らない。付加カードの付け先が壊れない |
| D15 | 同点処理 | `playerScoreAux` の符号化が 001 §9-3 の順序どおり（4 つの同点ケース） |

### 16. 後続への申し送り

| 宛先 | 内容 |
| --- | --- |
| T-008（カード効果） | (1) カード定義（PHP の定数・クラス）は `card_no` をキーにして、`CardRepository` と分離する。(2) 効果クラスは `CardRepository` 以外の方法で `card` テーブルを読まない。山札の一覧が必要な効果（020・035・050・055）は、`DeckView` と `peekTop` だけを使う。(3) 効果の中で山札・準備場を動かしたら、必ず `countersUpdate` を送る。(4) 各効果の通知は §10.2 の N6〜N21 を使い、新しい通知名を作るときは「公開通知に載せてよい情報」（§10.3）を満たす。(5) 名声変動の計算（§11.3）、探査出力の計算（011 §6-1）は、`card` テーブルから読み取った準備場の並び（`live` 列）を入力にする純関数にする。(6) 付加カードが離れる場合の扱い（未確定事項 No.5）が決まったら、`attached` の更新規則を足す |
| 実装（状態クラス） | (1) `getArgs()` は §9 の構造で DB を読むだけ。(2) `prep_choice` と `entry_decision` を args の公開部分に入れない。(3) 通知は §10.2 の名前と args を使い、`'private'` が要るものは `notify->player` か args の `_private`。(4) `changeIds()` は §10.5 の位置で、通知の組み立て後に呼ぶ。(5) `CardRepository` 以外から ItemManager を呼ばない。(6) `setupNewGame` で全統計を `init` する。(7) `getAllDatas` は §8.1 の表に従う |
| T-009（UI） | (1) 山札・`facedown`・探査山札は `Deck` の枚数だけで描く。サーバーの ID が無いので、アニメーションには一時 ID を使う。(2) `prepMarked` でチェックマークを更新する。(3) `countersUpdate` でパネルの数値を更新する。(4) `deckCards` は 47 の間だけ表示する。(5) 011 で列が規定枚数を超えるときの余りスロット（§3.2）。(6) 名声合計は `fame` が非 null のときだけ表示する。(7) BGA 標準のスコア欄の扱い（未確定事項 No.6） |
| 006（状態遷移） | 食い違いの反映が必要: `next_choice_lock` を `next_choice_records` に統一、解決キューに効果なしのカードも入れる（§4.4）、`prepMarked` の追加（未確定事項 No.9、No.10）。006 の修正は別の PR で行う |
| 009（UI・アセット） | §12 T-007 の「中身と無関係な ID を送る」を「裏向きカードの ID を送らない（枚数のみ）」に更新する必要がある（未確定事項 No.13）。名声合計の本人表示・終了時の扱い（未確定事項 No.2） |

## エッジケース

| No. | 状況 | 挙動 |
| --- | --- | --- |
| E1 | 山札が 0 枚のプレイヤーが `drawTopToPrep` を呼ばれた | 呼ばない（20 の分類でストップ確定にする）。呼ばれたら例外にし、DAO は null を返さない |
| E2 | 帰還フェイズ②で `facedown` と `acquired` が空 | そのまま山札だけをシャッフル。N29 の `gathered` は空配列 |
| E3 | 036 を引いたが山札が 0 枚 | 表にするカードがない。N16 を送らず、シャッフルも不要（空）。状態 33 に入らない |
| E4 | 043 で戻したカードが 039 | 抑止は P1 で確定済み（`entry_status` は他の行に反映済み）。`entry_card_id` は null、`entry_card_no` は残す。行の `entry_status` は `'returned'` |
| E5 | 007 のロックしたカードが 036 などで入れ替わる | 007 は P5-順番（036 は P4 で先に処理）なので、ロック後に入れ替わることはない。探査フェイズ中に列から取られた場合は `locked_card` を維持してよい（取った人は持ち主だけ） |
| E6 | 帰還フェイズで 040 が山札の上に来た後、本人が最初にドローするカードが公開 | 040 が山札の上にあることは公開情報（獲得は公開）。N30 で通知済み。`changeIds()` により ID は変わる |
| E7 | 3 人プレイで探査山札のカードが不足し補充できない | あるだけ補充（001 Q-24）。`rowRefilled` はある分だけ。`countersUpdate.piles` で 0 を通知 |
| E8 | 同じリクエストで同じプレイヤーの `countersUpdate` が複数回 | 許容する。最後の値が有効。クライアントは上書きするだけ |
| E9 | 離脱（zombie）プレイヤーの山札・準備場 | 他プレイヤーと同じデータで保持される。通知の送り先は卓のプレイヤーなので、離脱者宛ての `notify->player` が失敗しても進行しないようにする |
| E10 | 観戦者が途中参加 | `getAllDatas` が §8.1 の観戦者向けを返す。N34 は受け取らないので `fame` は null |

## 未確定事項

ルールブックや公式ドキュメントから判断できなかった点。推測で確定させず、作者への質問と、実装時に Studio で確認する項目を分けて示す。No.1、No.6、No.7、No.12、No.14 は主に実装時の確認事項（作者への確認は不要、または軽微）。他は作者への質問。

| No. | 質問・確認事項 | 暫定案 | 回答 |
| --- | --- | --- | --- |
| 1 | 【BGA で未確認】ItemManager の `Location` の渡し方、ORDER の向き・符号、`dbmodel.sql` で作った表との併用、`initDb()` の要否、`shuffle()` の乱数（V1〜V6） | `CardRepository` に閉じ込め、Studio で最初に検証（テスト D1）。うまくいかなければ自前 DAO（§3.6 代替 B） | |
| 2 | 名声合計のゲームオプション（009 No.15）が無効のとき、自分の名声合計を本人にだけ見せるか。ゲーム終了時の最終得点は常に表示するか（009 No.15 の残り） | 本人にも見せない。終了時は N35 と BGA 標準の最終得点を表示 | |
| 3 | ストップ時に引いた裏向きカードの**枚数**を全員に見せるか（009 No.11 の残り）。本仕様では ID も送らず枚数のみ | 枚数は全員に見せる（実物でも山の高さで分かる） | |
| 4 | 初期カードの絵柄の割り当てで、乗組員の 2 枚は同じ組（例: 02_1 と 02_2）で固定か、8 枚から任意に 2 枚か。国旗・初期探査船も含めたランダム割り当ての単位 | 8 枚をシャッフルして席順に 2 枚ずつ。国旗と初期探査船も別々にシャッフル | |
| 5 | 046 の付加カードについて、(a) 付け先の 046 が準備場から離れる（009 で戻す、004／058／055／013 で除外、014 で戻す）とき付加カードはどうなるか、(b) 055 の候補に付加カードを含めるか、(c) 009 の「直前に準備されたカード」が 046 のときの扱い | (a) 046 と一緒に動かさず、付加カードは `prep` の末尾に通常のカードとして残す（効果は発動済み）。(b) 含めない（004 の候補と同じ。046 本体のみ）。(c) 046 が「直前のカード」になる（付加カードは数えない） | |
| 6 | 名声合計を既定で非表示にするとき、BGA 標準のスコア欄（`playerScore` の 0 表示）を CSS で隠してよいか、BGA の規約上許されるか | ゲーム中は `playerScore` を更新せず、スコア欄を CSS で隠す。許されない場合は、スコア欄に別の値（準備枚数など）を出す案を T-009 で検討 | |
| 7 | 【BGA で未確認】観戦者の `$currentPlayerId` の値、リプレイで `notify->player` や `_private` が他人に見えるか、`getAllDatas` に `_private` を返せるか（V7・V8） | 非公開情報を通知に載せない設計で防ぐ。観戦者は「卓のプレイヤーに含まれない」で判定 | |
| 8 | 未確認帯域（56）を獲得したプレイヤーが、後で 56 を除外した場合（004、055、058 など）も、同点処理 1 番目の「獲得したプレイヤー」に数えるか | 数える（`unknown_acquirer` は獲得した事実を保持） | |
| 9 | **食い違い**: 006 §2.1 は `next_choice_lock`、§11 は `next_choice_records` と別名で書いている。006 §9.2 は準備選択中に公開通知を出さないとするが、009 §8.3 はチェックマークを求める | `next_choice_records` に統一。選択済みを知らせる内容なしの通知 N3 を追加（V11） | |
| 10 | **食い違い**: 006 §5.2 の INIT は「ドロー時効果を持たないカードは対象外」だが、043 の対象（この回に引いた全員）には、効果を持たないカードを引いた人も含まれる | このドローで引かれたカード全てを `resolve_entry` に入れ、効果を持たないものは `entry_level` を null にする（§4.4） | |
| 11 | 011 で列に加えたカードで、列の枚数が規定を超えたときの表示。固定スロットを動的に増やすか | スロット番号は最大 + 1。補充は規定未満の列だけ（001 §7-3）。UI は T-009 | |
| 12 | 【BGA で未確認】`player_no` が時計回りの席順か（V10） | 昇順を時計回りとする。Studio で 4 人卓を確認 | |
| 13 | 009 §12 T-007 は「カードを識別するための、中身と無関係な ID を送る」としているが、本仕様は裏向きカード・山札・探査山札の ID を一切送らない（枚数のみ） | ID を送らない（漏れの経路を根本から除く） | |
| 14 | 統計（§12）の項目と ID の採番を確認したい。ID は 10 以上で、公開後は再利用できず、型も後から変えられない | §12 の案。作者が項目を確認してから確定する | |
| 15 | 【BGA で未確認】`player_score_aux` 列の型と範囲（V9）、統計の `value_labels` の書式 | 9 桁以内に収める。`value_labels` は Studio で書式を確認 | |

## 受け入れ条件

- [ ] 永続データの全体像（§2）が、カード、`player` の追加列、グローバル値、解決エンジンの一時データを含み、006 で使われている名前（`prep_finished`、`prep_choice`、`next_choice_records`、`resolve_level`、`explore_order`、`explore_index`、`explore_state`、`locked_card`、`game_end_triggered`）が対応している。
- [ ] `dbmodel.sql` の案（§3.1、§4.1、§4.4）に、列名・型・制約・インデックスがある。
- [ ] location の一覧（§3.2）と、位置の規約（§3.4）、横向き・付加・ロック・013 の補正の持ち方がある。ItemManager の属性の対応と、使えない場合の代替がある。
- [ ] 乱数とシャッフル（§5）に、`bga_rand` の使い方、山札のシャッフル、3 人プレイの除外、初期所持者、絵柄の割り当てがある。
- [ ] `getAllDatas`（§8）が本人・他プレイヤー・観戦者の違いを表で示し、非公開カードの ID を含まない。
- [ ] 状態ごとの args（§9）が公開と `_private` の中身を具体化している。
- [ ] 通知の一覧（§10）に、通知名・送り先・引数・ログ文言（英語）・送る処理があり、非公開カードが公開・非公開を行き来するときの ID の扱い（§7、§10.5）がある。
- [ ] 統計（§12）の ID が 10 以上で、最初に慎重に採番する理由が書かれている。
- [ ] ゲームオプションとゲーム設定（§13）の ID と値がある。
- [ ] DB 容量（§14）への配慮が書かれている。
- [ ] 各【確認】に URL がある。確認できなかった点が §1.2 と未確定事項にある。
- [ ] 作者への質問が暫定案つきの表になっている。
