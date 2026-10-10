# MINIMUM JOURNEY for Board Game Arena

自作カードゲーム **MINIMUM JOURNEY（ミニマム・ジャーニー）** を [Board Game Arena](https://boardgamearena.com/)（BGA）に実装するためのリポジトリです。

> 現在のステータス: **立ち上げ段階**（仕様書の作成から着手します。実装コードはまだありません）

## ゲーム概要

| 項目 | 内容 |
| --- | --- |
| プレイ人数 | 3〜4 人 |
| プレイ時間 | 20〜40 分 |
| 対象年齢 | 10 歳以上 |
| ジャンル | デッキ構築 × バースト系（同時ドロー / ストップ選択） |
| 制作・著作 | だんじり協奏曲（ぞね、まや、ひのき、たづくり） |

各プレイヤーは宇宙探査船を任された宇宙飛行士となり、デッキを強化しながら【未確認帯域】カードの獲得を目指します。

- 初期デッキは【地球】のカード 4 枚（国旗・乗組員 ×2・初期探査船）
- **準備フェイズ**: 全員が同時に「ドロー」か「ストップ」を秘密裏に選び、山札からカードをめくって探査出力を積み上げる
- 1 人だけがドローしてしまうと、そのカードは横向き（無効）になり探査出力が半分になる
- **探査フェイズ**: 準備枚数が少ない順に、探査出力以下のコストのカードを獲得する
- **帰還フェイズ**: お守りの所有権が移動し、デッキを全てシャッフルして次のラウンドへ
- 誰かが【未確認帯域】カードを獲得する、または【ラニアケア超銀河団】の探査山札が尽きたラウンドで終了し、デッキ内の名声合計が最も高いプレイヤーが勝利

正式なルールは [data/rulebook/minimum_journey_rulebook.pdf](data/rulebook/minimum_journey_rulebook.pdf) を参照してください。

## リポジトリ構成

```text
.
├── README.md          # このファイル
├── CLAUDE.md          # Claude Code 向けの開発ルール
├── data/              # 原本アセット（編集しない）
│   ├── cards/         #   カード画像（PNG, 715×999）
│   └── rulebook/      #   ルールブック PDF
├── docs/              # 開発用ドキュメント
│   ├── specs/         #   仕様書（実装前に必ず作成）
│   ├── development/   #   開発ルール（Git 運用・タスクの進め方・CI/CD）
│   └── tasks.md       #   タスク一覧
├── src/               # BGA Studio のプロジェクト minimumjourney に同期するゲーム本体（今後作成）
└── .worktrees/        # タスクごとの作業用ワークツリー（Git 管理外）
```

## 開発の進め方

1. タスクごとに `.worktrees/` 配下へワークツリーを作成する
2. まず `docs/specs/` に仕様書を作成し、PR でレビューする
3. 仕様書のマージ後、別 PR で実装する
4. PR マージ後、ワークツリーとリモートブランチを削除する

詳細は以下を参照してください。

- [docs/README.md](docs/README.md) — ドキュメントの索引
- [docs/development/git-workflow.md](docs/development/git-workflow.md) — Git 運用ルール
- [docs/development/task-workflow.md](docs/development/task-workflow.md) — タスクの進め方（サブエージェントへの委託）
- [docs/development/cicd.md](docs/development/cicd.md) — CI/CD と BGA Studio への同期
- [docs/tasks.md](docs/tasks.md) — タスク一覧

## クレジット

- ゲームデザイン・アートワーク: だんじり協奏曲
- お問い合わせ: <https://x.com/danjiriconcerto>

カード画像・ルールブックを含むゲームコンテンツの著作権は、だんじり協奏曲に帰属します。
