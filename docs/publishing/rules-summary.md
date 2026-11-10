# ルール概要（Gamehelp）の文面と掲載手順

BGA のゲームページと対局画面に表示される「ルール概要（Rules summary）」の文面。中身は BGA の wiki のページ `Gamehelpminimumjourney` で、読者の言語のページがあればそれを、なければ英語版を表示する。このファイルを原本とし、wiki へは貼り付けて掲載する。

根拠（確認日 2026-11-10）:

- <https://en.doc.boardgamearena.com/Help>（wiki のヘルプ一覧）
- <https://en.doc.boardgamearena.com/Wiki_formatting>（wiki の書式）

ルールの内容は仕様書 [001](../specs/001-game-rules.md)（作者の裁定を含む）とルールブックに基づく。画面の英語は `src/modules/js/Game.js`、`src/modules/php/`、`src/modules/php/Material/CardTexts.php` の文字列に合わせている。

## 載せる場所

| 言語 | URL |
| --- | --- |
| 英語 | <https://en.doc.boardgamearena.com/Gamehelpminimumjourney> |
| 日本語 | <https://ja.doc.boardgamearena.com/Gamehelpminimumjourney> |

## 作者がやる手順

1. BGA のアカウントで wiki にログインする。
2. 英語版の URL を開き、ページを作成する（英語版を先に作る）。
3. 下の「英語版」のコードブロックの中身をそのまま貼る。
4. プレビューで崩れがないか確認し（見出し、箇条書きの代わりの `•`、行間）、保存する。
5. 日本語版の URL でも同様に、「日本語版」の中身を貼って保存する。
6. ゲームページの「ルール概要」と対局画面で表示を確認する。
7. ルールや画面の用語が変わったら、このファイルを先に直してから wiki を更新する。

## 書き方の決まり

BGA での表示に合わせた制約。

- 見出しは `== 見出し ==`（h2）と `==== 見出し ====`（h4）だけを使う。h1 はページのタイトル用、h3 は対局画面で前の節の文に重なるので使わない。
- 箇条書きの `*` `#` と字下げは消える。`•` や `1.` を文字として書き、項目を空行で区切る。
- 表は崩れやすいので使わない。画像も使わない。
- 対局画面では h2 の節ごとに枠が分かれるので、節単位で読んで完結させる。
- 外部リンクは公式サイトだけにし、最後に 1 か所入れる。
- 分量は英語版で 700〜1,100 語を目安にする。全カードの効果は載せない（ゲーム中にカードのツールチップで見られる）。

## 英語版

```text
'''MINIMUM JOURNEY''' is a card game for 3 to 4 players (20-40 minutes). Each round you flip cards from your deck to build up exploration power, use it to acquire new cards from deep space, then shuffle everything together again. The player with the most fame at the end wins.

== Goal ==

Each card has a fame value. When the game ends, the player with the most fame wins. Acquiring the Unknown Region card triggers the end of the game, but it does not win the game by itself.

== Setup ==

• Each player starts with a deck of 4 cards: 1 Flag, 2 Crew and 1 Starter Ship, all with the Earth symbol. Your deck is your draw pile, face down.

• The exploration pile for each exploration cost (2, 4, 6 and 8) is placed in the exploration field, with face-up cards next to it forming a row: the Solar System (cost 2), the Milky Way (cost 4), the Virgo Cluster (cost 6) and the Laniakea Supercluster (cost 8). Row sizes are 3/3/2/2 cards with 3 players and 4/3/2/2 with 4 players. With 3 players, 2 random cards from each pile are removed from the game face down.

• The Unknown Region card (cost 10) lies face up beside the rows.

• One player randomly receives the Charm. The game option "Charm side" chooses Safe Passage Charm (default) or Misfortune-Warding Charm.

A round has three phases: Preparation, Exploration and Return.

== 1. Preparation ==

All players who have not stopped yet choose Draw or Stop at the same time. You can change your choice until everyone has chosen. Then all choices are revealed:

• Players who chose '''Draw''' place the top card of their draw pile face up in their prep area.

• Players who chose '''Stop''' put the top card of their draw pile aside face down and finish preparing. A face-down card is not a prepared card: it does not count, and has no power or effects. If everyone stops, nobody draws.

If two or more players drew, resolve any "When Drawn" effects (the game does this automatically, in a fixed priority) and choose again. A player who has stopped does not choose again. A player with an empty draw pile can only Stop.

If '''only one player drew''' in that step, that card is turned '''sideways''' and preparation ends for everyone (even if others had stopped earlier). A sideways card still counts as a prepared card, but its exploration power and effects are ignored, and that player's exploration output is '''halved''' (rounded down).

== 2. Exploration ==

'''Exploration output.''' Add up the exploration power of the face-up cards in your prep area (including Exploration Power Change effects). Add the Charm's exploration power (1 for the Safe Passage Charm, but 0 in round 1). If you were the only player to draw, halve the result, rounding down. It never goes below 0 and does not change during the Exploration phase.

'''Turn order.''' Players explore one at a time, in this order:

1. Fewer prepared cards (face-up and sideways cards count; face-down cards and the Charm do not).

2. Lower exploration output.

3. Closest clockwise from the Charm holder (the holder goes first).

'''On your turn''' you may acquire one card whose exploration cost is at most your exploration output. Your output is not spent. You can choose:

• a face-up card in a row, or the Unknown Region card (cost 10), or

• the top card of an exploration pile, with the cost of its row. It is revealed when you choose it and you cannot take it back. Effects that forbid or limit exploring do not apply to cards taken from the top of a pile.

You may also pass. "On Exploring" effects happen at the start of your turn, and "While Exploring" effects last only during your turn. "When Acquired" effects happen as soon as you acquire the card, including when an effect gives you a card. Rows are not refilled until the Return phase.

== 3. Return ==

1. '''The Charm moves''' to the player with the lowest exploration output (on a tie, the one who explored later). With the Misfortune-Warding Charm, the new holder, even if it is the same player, may remove up to 1 card in their prep area (sideways cards included) from the game.

2. '''Everyone reshuffles.''' Gather your prepared cards, face-down cards, acquired cards and draw pile, and shuffle them into a new draw pile.

3. '''Refill the rows''' from their exploration piles up to their set size (as many as are left).

== End of the game ==

The game ends at the end of the round in which someone acquired the Unknown Region card, or the cost-8 Laniakea pile ran out (including when refilling in the Return phase). The rest of that round, including the Return phase, is played in full.

== Scoring ==

Add up the fame of every card in your deck (draw pile, prep area and cards acquired this round), including Fame Change effects. The Charm holder (after the Return phase of the last round) gets +1 fame. Cards removed from the game do not count. The player with the most fame wins.

'''Tie-breakers, in order:''' the player who acquired the Unknown Region card; then the player with fewer cards with the Laniakea symbol, then Virgo Cluster, then Milky Way, then Solar System symbol (counting your whole deck). If still tied, the players share the victory.

== Effect timings ==

• '''When Drawn:''' when the card is drawn (face up) into your prep area.

• '''On Exploring:''' at the start of your exploration turn, while the card is in your prep area.

• '''While Exploring:''' only during your own exploration turn.

• '''Exploration Power Change:''' changes exploration power when you calculate your exploration output.

• '''Fame Change:''' changes fame when final scores are counted.

• '''When Acquired:''' as soon as you acquire the card.

== Good to know ==

• "Remove from the game" means the card no longer exists: you lose its fame too.

• Your "prep area" includes sideways cards.

• "Up to N cards" can be 0 cards.

• The Charm is not part of your deck, but it gives its holder +1 fame at the end.

• Tip: the more you draw, the more power you get, but if you are the only one to draw, your output is halved. Watch the other players' choices, and remember that stopping with fewer cards lets you explore earlier.

== Game options ==

• '''Charm side:''' Safe Passage Charm (default) or Misfortune-Warding Charm.

• '''Show total fame during the game:''' shows each player's total fame during the game (off by default).

• '''View your own draw pile:''' lets you see the list of cards in your own draw pile, sorted by card number so the order is not revealed (on by default).

Official site: https://danjiri-concerto.github.io/minimum-journey/
```

## 日本語版

```text
'''MINIMUM JOURNEY''' は 3〜4 人で遊ぶカードゲームです（20〜40 分）。毎ラウンド、山札からカードをめくって探査力を稼ぎ、その力で宇宙のカードを獲得し、最後に全カードを混ぜ直します。ゲーム終了時に名声がもっとも多い人の勝ちです。

== 目的 ==

カードには名声が書かれています。ゲーム終了時に名声の合計が最も多いプレイヤーが勝ちます。未確認帯域カードの獲得はゲーム終了のきっかけですが、それだけで勝つわけではありません。

== 準備 ==

• 各プレイヤーは、国旗 1 枚・乗組員 2 枚・初期探査船 1 枚の計 4 枚（すべて地球シンボル）を初期デッキとし、裏向きの山札にします。

• 探査コスト 2 / 4 / 6 / 8 ごとに探査山札を作り、それぞれの横に表向きのカードを並べて列にします。コスト 2 が太陽系、4 が天の川銀河、6 が乙女座銀河団、8 がラニアケア超銀河団です。列の枚数は 3 人なら 3 / 3 / 2 / 2 枚、4 人なら 4 / 3 / 2 / 2 枚です。3 人のときは、各探査山札からランダムに 2 枚を伏せたままゲームから除外します。

• 未確認帯域カード（コスト 10）を表向きで置きます。

• お守りはランダムに決めた 1 人が持ちます。ゲームオプション「Charm side」で、航路安全のお守り（初期設定）か厄除けのお守りを選びます。

1 ラウンドは、準備・探査・帰還の 3 つのフェイズです。

== 1. 準備フェイズ ==

まだストップしていない全員が、同時に「Draw（ドロー）」か「Stop（ストップ）」を選びます。全員が選び終わるまでは選び直せます。その後、選択が一斉に公開されます。

• '''ドロー'''を選んだ人は、山札の一番上を表向きで準備場に置きます。

• '''ストップ'''を選んだ人は、山札の一番上を裏向きで脇に置き、このラウンドの準備を終えます。裏向きのカードは準備したカードではなく、枚数にも数えず、探査力も効果もありません。全員がストップなら、誰もカードを引きません。

2 人以上がドローしたら、ドロー時効果を解決し（複数の効果が重なるときはゲームが決まった優先度で自動的に処理します）、もう一度選びます。ストップした人は、もう選びません。山札が 0 枚の人はストップしか選べません。

その回にドローしたのが'''1 人だけ'''なら、そのカードは'''横向き'''になり、準備フェイズが終わります（ほかの人がすでにストップしていても同じです）。横向きのカードは準備したカードの枚数には数えますが、探査力と効果は無視されます。さらに、その人の探査出力は'''半分'''（切り捨て）になります。

== 2. 探査フェイズ ==

'''探査出力。''' 準備場の表向きのカードの探査力を合計します（探査力変動の効果を含みます）。そこにお守りの探査力（航路安全のお守りは 1。ただし 1 ラウンド目は 0）を足します。1 人だけドローした人は、その結果を半分にします（切り捨て）。0 未満にはならず、探査フェイズの間は変わりません。

'''探査の順番。''' 1 人ずつ、次の順で探査します。

1. 準備したカードが少ない人（表向きと横向きを数えます。裏向きのカードとお守りは数えません）。

2. 探査出力が小さい人。

3. お守りの持ち主から時計回りに近い人（持ち主が最優先）。

'''自分の番''' には、探査コストが自分の探査出力以下のカードを 1 枚獲得できます。探査出力は消費しません。選べるのは次のカードです。

• 列の表向きのカード、または未確認帯域カード（コスト 10）。

• 探査山札の一番上のカード（コストはその列と同じ）。宣言した時点で公開して獲得し、取り消せません。探査の禁止や制限の効果は、山札の一番上から探査したカードには及びません。

パスもできます。探査時効果は自分の番の始めに、探査中効果は自分の番の間だけ働きます。獲得時効果は、カードを獲得したらすぐに働きます（効果でカードを獲得したときも同じです）。列は帰還フェイズまで補充しません。

== 3. 帰還フェイズ ==

1. '''お守りが移動します。''' 探査出力がもっとも小さい人へ移ります（同点なら探査の順番が遅い人）。厄除けのお守りのときは、新しい持ち主（持ち主が変わらない場合も）が、自分の準備場（横向きのカードを含む）のカードを最大 1 枚ゲームから除外できます。

2. '''全員が混ぜ直します。''' 準備場のカード、裏向きのカード、獲得したカード、山札をすべて混ぜて、新しい山札にします。

3. '''列を補充します。''' 各列を、規定の枚数になるまで探査山札から補充します（足りなければあるだけ）。

== ゲームの終了 ==

誰かが未確認帯域カードを獲得したラウンド、またはコスト 8 のラニアケアの探査山札がなくなったラウンド（帰還フェイズの補充でなくなった場合も含む）の終わりにゲームが終わります。そのラウンドの残りの探査と帰還フェイズは、最後まで行います。

== 得点 ==

自分のデッキ（山札・準備場・このラウンドに獲得したカード）の全カードの名声を合計します。名声変動の効果も含めます。最後のラウンドの帰還フェイズの後にお守りを持っている人は名声 +1 です。ゲームから除外したカードは数えません。名声がもっとも多い人の勝ちです。

'''同点の場合は''' 次の順で決めます。未確認帯域カードを獲得した人。次に、ラニアケア、乙女座銀河団、天の川銀河、太陽系の順に、そのシンボルを持つカード（デッキ全体）が少ない人。それでも同じなら、勝利を分け合います。

== 効果タイミング ==

• '''ドロー時:''' カードを引いて（表向きで）準備場に置いたとき。

• '''探査時:''' 準備場にあるカードの効果で、自分の探査の番の始めに働きます。

• '''探査中:''' 自分の探査の番の間だけ働きます。

• '''探査力変動:''' 探査出力を計算するときに、探査力を変えます。

• '''名声変動:''' ゲーム終了時の名声の計算で、名声を変えます。

• '''獲得時:''' そのカードを獲得したときにすぐ働きます。

== 知っておくと役立つこと ==

• 「ゲームから除外する」は、そのカードがなくなるということです。名声も失います。

• 「準備場」には横向きのカードも含まれます。

• 「最大 N 枚」は 0 枚でもかまいません。

• お守りはデッキには入りませんが、持ち主はゲーム終了時に名声 +1 です。

• コツ: ドローするほど探査力は増えますが、1 人だけドローすると探査出力が半分になってしまいます。ほかのプレイヤーの動きを見ましょう。準備したカードが少ないほど、探査の順番が早くなります。

== ゲームオプション ==

• '''Charm side（お守りの面）:''' 航路安全のお守り（初期設定）か、厄除けのお守りか。

• '''Show total fame during the game（ゲーム中に名声の合計を表示）:''' ゲーム中、各プレイヤーの名声の合計を表示します（初期設定は表示しない）。

• '''View your own draw pile（自分の山札を見る）:''' 自分の山札のカード一覧を見られます。カード番号順に並べるので、山札の順番はわかりません（初期設定は有効）。

公式サイト: https://danjiri-concerto.github.io/minimum-journey/
```

## 記録

| 日付 | 内容 |
| --- | --- |
| 2026-11-10 | 英語版・日本語版の文面と掲載手順を作成。wiki への掲載は未 |
