# Git 運用ルール

## 基本方針

- `main` は常に動作する状態を保つ。`main` への直接コミット・直接 push は禁止。
- すべての変更は **タスク用ブランチ → Pull Request → squash merge** で取り込む。
- **1 タスク = 1 ブランチ = 1 ワークツリー = 1 PR**。
- リポジトリ直下の作業ツリーは常に `main` をチェックアウトしたままにし、作業は `.worktrees/` 配下で行う。

例外: リポジトリ立ち上げ時の初回コミット（README / CLAUDE.md / docs の雛形）のみ `main` に直接コミットする。

## ブランチ

`origin/main` から作成し、`<type>/<slug>` と命名する。

| type | 用途 | 例 |
| --- | --- | --- |
| `docs` | 仕様書・ドキュメント | `docs/spec-001-game-rules` |
| `feat` | 機能の実装 | `feat/preparation-phase` |
| `fix` | 不具合の修正 | `fix/exploration-order-tiebreak` |
| `ci` | CI/CD | `ci/lint` |
| `chore` | 設定・雑務 | `chore/editorconfig` |
| `refactor` | 挙動を変えない整理 | `refactor/card-definitions` |

`slug` は英小文字とハイフンのみ。

## ワークツリー

タスクごとに `.worktrees/<slug>` を作成する（`.worktrees/` は `.gitignore` 済み）。

```bash
git fetch origin
git worktree add .worktrees/<slug> -b <type>/<slug> origin/main
```

- ワークツリーのディレクトリ名は、ブランチ名から `<type>/` を除いた `<slug>` とする。
- 並行するタスクは別々のワークツリーで進める。
- 現在のワークツリーは `git worktree list` で確認する。

## コミット

[Conventional Commits](https://www.conventionalcommits.org/ja/) に従い、説明は日本語で書く。

```text
<type>(<scope>): <要約>

<本文（任意）: なぜその変更が必要か>
```

- `type` はブランチと同じ語彙（`docs` / `feat` / `fix` / `ci` / `chore` / `refactor`）に `test` を加えたもの。
- `scope` は任意（例: `specs`, `states`, `cards`, `ui`, `ci`）。
- 意味のある単位で小さくコミットする。

## Pull Request

```bash
git -C .worktrees/<slug> push -u origin <type>/<slug>
gh pr create --base main --head <type>/<slug> --title "<コミットと同じ形式の要約>" --body "..."
```

- タイトルは Conventional Commits 形式（squash merge 時のコミットメッセージになる）。
- 本文には、目的・変更内容・確認方法・関連する仕様書 / タスク ID を書く。
- CI（lint）が通っていること。
- **マージはユーザー（リポジトリオーナー）の承認後**に行う。マージ方法は squash merge。
- 仕様書の PR と実装の PR は分ける。実装の PR は、対応する仕様書がマージ済みであること。

## マージ後の後始末

PR がマージされたら、必ず該当のワークツリー・ローカルブランチ・リモートブランチを削除する。

```bash
git worktree remove .worktrees/<slug>
git branch -D <type>/<slug>              # squash merge のため -d では削除できない
git push origin --delete <type>/<slug>   # GitHub 側で自動削除済みならスキップ
git fetch --prune
git pull --ff-only origin main
```

- 削除前に `git -C .worktrees/<slug> status` で未コミットの変更がないことを確認する。
- 後始末が済んだら [../tasks.md](../tasks.md) のタスクを完了に更新する。

## GitHub 側の推奨設定

リポジトリオーナーが設定する項目です。

- Settings → General → **Automatically delete head branches** を有効化（リモートブランチの削除漏れ防止）
- Settings → General → マージ方法は **Squash merging** のみ許可
- Settings → Branches（または Rulesets）で `main` を保護: PR 必須、ステータスチェック（lint）必須、force push 禁止
