# BGA のライセンス申請（作者 = 権利者の場合）

MINIMUM JOURNEY を BGA で公開するための、ライセンス（権利者からの許諾）の手続き。作者（だんじり協奏曲）がデザイナー兼出版社なので、**作者自身が BGA に許諾を出す**形になる。姉妹リポジトリ `../project-edgeworth-bga` の `docs/publishing/bga-license.md`（PROJECT EDGEWORTH、2026-10-21 申請）と同じ手続き。

## BGA の決まり（2026-10-20 時点の公式ドキュメント）

- アルファ（本番サーバーへの移行）には **BGA がそのゲームのライセンスを持っていること** が必須。ベータには **出版社による実装の承認** が必須（[Pre-release checklist](https://en.doc.boardgamearena.com/Pre-release_checklist)）。
- ライセンスは無償。多くの場合は BGA と正式な契約を結ぶ（[BGA Game licences](https://en.doc.boardgamearena.com/BGA_Game_licences)、契約の雛形は [General terms and conditions of license](https://en.doc.boardgamearena.com/images/e/e0/BGA_TC_Pub_en.pdf)）。
- BGA が公開するのは「どこかで買える、または近く買えるようになる」ゲームだけ。
- **申請は BGA の申請フォームから行う**（作者の確認、2026-10-22。メールではない）。

## 権利者であることの示し方

個人の制作者には会社の登記のような証明がないので、**だんじり協奏曲として公開している場所（公式サイト・X・BGG）と、申請する本人を結び付ける**。

1. **公式サイト（2026-10-22 公開済み）**: MINIMUM JOURNEY のページ（<https://danjiri-concerto.github.io/minimum-journey/>）に「オンライン版（Board Game Arena）」の節を追加した。日本語と英語で、BGA Studio のプロジェクト `minimumjourney`（開発者 ZoneTsuyoshi）が制作者による公式の実装であること、BGA に公開を許諾すること、BGA との連絡はお問い合わせページのアドレスから行うこと、BGG のゲーム・出版社のページへのリンクを載せている。
2. **公式の X（@danjiriconcerto）**: 下の文面を投稿し、その URL をフォームに入れる。
3. **BGG**: ゲーム <https://boardgamegeek.com/boardgame/481895/minimum-journey>、出版社 <https://boardgamegeek.com/boardgamepublisher/64662/danjiri-concerto>。
4. **販売の実績**: 通販・イベントのページ（**URL は作者に確認**）。
5. **原本のデータ**: BGA 用の画像は印刷用の原本から作っている。求められれば原本の一部を渡せる。

### X のポストの文面

日本語（X の文字数で 233 / 280）:

```text
【お知らせ】MINIMUM JOURNEY のオンライン版を Board Game Arena（BGA）向けに開発中です。BGA Studio の「minimumjourney」（開発：ぞね／BGA ID: ZoneTsuyoshi）が、だんじり協奏曲による公式の実装です。
https://danjiri-concerto.github.io/minimum-journey/
#BoardGameArena
```

英語（BGA の担当者が読めるように。日本語のポストへの返信のスレッドにするとよい。261 / 280）:

```text
MINIMUM JOURNEY is coming to Board Game Arena! As its designer & publisher, Danjiri Concerto is building the official adaptation: BGA Studio project "minimumjourney" by our member Zone (BGA: ZoneTsuyoshi). We authorize BGA to publish it.
https://danjiri-concerto.github.io/minimum-journey/
```

## 手順

1. **（済）BGG のゲームページ**: BGG_ID 481895（`src/gameinfos.jsonc` に設定済み）。
2. **（済）公式サイトの告知**（2026-10-22）。
3. **X に投稿する**: 上の文面。
4. **申請フォームを送る**: 下の「フォームへの記入例」を各欄に貼る。メールアドレスの欄には、お問い合わせページのサークルのアドレスを使う。
5. **BGA からの返信を待つ**: 契約書への署名などを求められたら、エージェントに共有してもらえれば内容の確認と返信の下書きをする。
6. **アルファ申請**: Studio のコントロールパネルの「Request ALPHA status」から申請し、届く確認メールにライセンスの承認（BGA からの返信）を添えて返信する。

## フォームへの記入例（英語）

フォームの欄の名前に合わせて、近いものを貼る。合わない欄があればエージェントに欄の一覧を共有してもらえれば書き直す。

| 欄 | 記入 |
| --- | --- |
| Game name | MINIMUM JOURNEY |
| Publisher | Danjiri Concerto（designer and publisher） |
| Designer | Danjiri Concerto |
| BoardGameGeek | <https://boardgamegeek.com/boardgame/481895/minimum-journey> |
| Publisher website | <https://danjiri-concerto.github.io/minimum-journey/> |
| Players / duration | 3–4 players, 20–40 minutes, age 10+ |
| Released | 2024 (Game Market 2024 Autumn, Japan). Revised edition: Game Market 2025 Autumn / 2026 Spring |
| Where to buy | （作者に確認） |
| BGA username of the developer | ZoneTsuyoshi（Studio project: `minimumjourney`） |
| Contact | サークルのアドレス（お問い合わせページ）、<https://x.com/danjiriconcerto> |

自由記述の欄（Message / Comments など）:

```text
We are Danjiri Concerto, the designer and publisher of MINIMUM JOURNEY, and we hold all rights to the game (rules, card texts, and artwork). We authorize Board Game Arena to publish an adaptation of the game and to use its materials for that purpose, and we are happy to sign your standard license agreement.

The adaptation is being developed by our own team in BGA Studio (project "minimumjourney", developer ZoneTsuyoshi). All graphics are made by us from the original print files. As the publisher, we also approve this implementation for the Alpha and Beta phases.

To confirm that this request comes from the rights holder:
- Our official website states that the BGA adaptation is ours: https://danjiri-concerto.github.io/minimum-journey/ (section "オンライン版（Board Game Arena）", with an English paragraph). We contact BGA from the address on our contact page: https://danjiri-concerto.github.io/contact.html
- We announced it on our official X account: [URL of the post]
- BoardGameGeek: https://boardgamegeek.com/boardgame/481895/minimum-journey (publisher: https://boardgamegeek.com/boardgamepublisher/64662/danjiri-concerto)

We are also applying for our other game, PROJECT EDGEWORTH (Studio project "projectedgeworth"), in the same way.
```

## 記録

| 日付 | 内容 |
| --- | --- |
| 2026-10-22 | BGG のゲームページが承認された（BGG_ID 481895）。公式サイトに BGA 版の告知を公開。この手順と文面を作成 |
