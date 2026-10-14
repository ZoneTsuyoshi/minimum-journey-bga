# CLAUDE.md

自作カードゲーム MINIMUM JOURNEY を Board Game Arena（BGA）に実装するリポジトリ。
ユーザーとのやり取り、ドキュメント、コミットメッセージ、PR は日本語で書く。

## 絶対に守るルール

1. **main に直接コミットしない。** すべての変更はタスク用ブランチ → PR → マージで行う。
2. **タスクごとにワークツリーを `.worktrees/` 配下に作成して作業する。** リポジトリ直下の作業ツリーは常に `main` のままにする。
3. **仕様書が先。** 実装の前に `docs/specs/` に仕様書を作成し、PR でユーザーの承認を得る。仕様書のない実装はしない。
4. **各タスクはサブエージェント（sonnet）に委託する。** メインエージェントは分解・指示・レビュー・Git 操作の統括を担当する。
5. **PR マージ後は、該当のワークツリー・ローカルブランチ・リモートブランチを削除する。**
6. **`data/` は原本。編集・移動・削除しない。** 加工が必要なら `src/` 側に生成物を作る。
7. **認証情報をコミットしない。** BGA Studio の認証情報（SSH 秘密鍵とユーザー名）は GitHub Secrets のみに置く。ホスト・ポート・リモートパス・known_hosts のような公開情報は GitHub Variables に置いてよい。

## タスクの進め方（要約）

詳細は [docs/development/task-workflow.md](docs/development/task-workflow.md) と [docs/development/git-workflow.md](docs/development/git-workflow.md)。

```bash
# 1. ワークツリー作成（メインエージェントが実行）
git fetch origin
git worktree add .worktrees/<slug> -b <type>/<slug> origin/main

# 2. サブエージェント（sonnet）に委託 → コミット → push → PR 作成
git -C .worktrees/<slug> push -u origin <type>/<slug>
gh pr create --base main --head <type>/<slug>

# 3. マージ後の後始末
git worktree remove .worktrees/<slug>
git branch -D <type>/<slug>
git push origin --delete <type>/<slug>   # GitHub 側で自動削除済みなら不要
git fetch --prune && git pull --ff-only origin main
```

- ブランチ名: `<type>/<slug>`（`type` は `docs` / `feat` / `fix` / `ci` / `chore` / `refactor`）。ワークツリーのディレクトリ名は `<slug>`。
- コミットは Conventional Commits（例: `docs(specs): ゲームルール仕様を追加`）。
- 1 タスク = 1 ブランチ = 1 ワークツリー = 1 PR。仕様書 PR と実装 PR は分ける。
- PR は squash merge。マージはユーザーの承認後に行う。

### サブエージェントへの委託

- Agent ツールを `model: "sonnet"` で起動する。
- Agent ツールの `isolation: "worktree"` は使わない（`.worktrees/` 配下にならないため）。メインエージェントが `git worktree add` で作成し、その**絶対パス**を指示に含める。
- 指示には、目的・作業ディレクトリ（絶対パス）・読むべき資料（ルールブック、関連仕様書）・成果物のパス・やらないこと・完了条件を明記する。
- サブエージェントの報告は鵜呑みにせず、メインエージェントが差分を確認してから PR を作成する。
- 互いに独立したタスクは、別々のワークツリーで並列に委託してよい。

## ドキュメント

- 開発用ドキュメントはすべて `docs/` で管理する。索引は [docs/README.md](docs/README.md)。
- 仕様書は `docs/specs/NNN-<slug>.md`（3 桁連番）。[docs/specs/_template.md](docs/specs/_template.md) から作成し、[docs/specs/README.md](docs/specs/README.md) の一覧に追記する。
- タスク一覧は [docs/tasks.md](docs/tasks.md)。タスクの着手・完了時に更新する。
- ルールの一次情報は [data/rulebook/minimum_journey_rulebook.pdf](data/rulebook/minimum_journey_rulebook.pdf) とカード画像 `data/cards/`。仕様書とルールブックが食い違う場合はルールブックを正とし、ルールブックに書かれていない点は推測で埋めずユーザー（作者）に確認する。

## ゲームの用語

仕様書・コードでは以下の用語に統一する（英語識別子は仕様書 002 で確定させる）。

| 用語 | 意味 |
| --- | --- |
| デッキ | プレイヤーが所有する全カード（山札＋準備場＋このラウンドの獲得カード） |
| 山札 | デッキのうち、裏向きで積まれている部分 |
| 準備場 | 準備フェイズでめくったカードを置く場所。横向きのカードも枚数に含む |
| 探査場 | 探査山札・探査場の列・未確認帯域カードからなる共有の場 |
| 探査力 | カード個別の値（歯車アイコン） |
| 探査出力 | 準備場の探査力の合計に効果を適用した、そのラウンドの値 |
| 探査コスト | カードの獲得に必要な探査出力（2 / 4 / 6 / 8、未確認帯域は 10） |
| 名声 | 勝利点 |
| シンボル | 地球 / 太陽系 / 天の川銀河 / 乙女座銀河団 / ラニアケア超銀河団 / 未確認帯域 / お守り |
| 効果タイミング | ドロー時 / 探査時 / 探査中 / 探査力変動 / 名声変動 / 獲得時 |

## カード画像のファイル名

`data/cards/` のファイル名はカード番号（カード右下の 3 桁の番号）に対応する。

- `01_1`〜`01_4`: 国旗（プレイヤーごとの絵柄違い）
- `02_1`〜`02_8`: 乗組員（1 人 2 枚 × 4 人分）
- `03_1`〜`03_4`: 初期探査船
- `04`〜`55`: 漂流カード 52 枚
- `56`: 未確認帯域カード（Unknown）
- `57`, `58`: お守りカード
- `rev`: 裏面

## BGA 実装の方針

- BGA Studio に同期するゲーム本体は `src/` 配下に置き、`src/` の中身が BGA 側プロジェクトフォルダと 1 対 1 に対応するようにする。`docs/` や `data/` は同期しない。
- BGA フレームワークの構成（ファイル構成、状態クラス、通知、PHP バージョンなど）は更新が速い。記憶に頼らず、仕様書作成時に [BGA Studio の公式ドキュメント](https://en.doc.boardgamearena.com/Studio) で最新を確認する。
- BGA Studio のプロジェクト名は **`minimumjourney`**（作成済み・変更不可）。BGA のファイル名・クラス名・名前空間はこの名前に従う。
- UI 文言は英語を原文とし、BGA の翻訳機構で日本語化する（BGA の標準作法）。

## CI/CD

方針は [docs/development/cicd.md](docs/development/cicd.md)。lint（CI）は導入済み。BGA Studio への同期（CD）は仕様書 [005](docs/specs/005-bga-sync.md) に基づいて段階的に導入中（第 1 段階は接続テストのみ）。

- CI: PR ごとに lint（PHP / JS / CSS / Markdown など）を実行し、通らない PR はマージしない。
- CD: `main` へのマージをトリガーに `src/` を BGA Studio へ SFTP で自動同期する。
