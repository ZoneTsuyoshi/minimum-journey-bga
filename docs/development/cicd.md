# CI/CD の方針

> ステータス: **lint は導入済み、BGA Studio への同期は導入中**。lint は [../specs/004-ci-lint.md](../specs/004-ci-lint.md)、同期は [../specs/005-bga-sync.md](../specs/005-bga-sync.md) に基づきます。同期は段階導入で、第 1 段階（接続テスト）のワークフローを追加した状態です。このドキュメントは方針を示し、具体的な設計は各仕様書を正とします。

## 全体像

```text
PR 作成・更新 ──▶ CI: lint ──▶（通過 + ユーザー承認）──▶ squash merge
                                                              │
main への push ──▶ CD: src/ を BGA Studio へ SFTP 同期 ◀──────┘
```

## CI: lint チェック

GitHub Actions で PR ごと、および `main` への push 時に実行します。lint が通らない PR はマージしません。

想定しているチェック項目（採用するツールと設定は仕様書で決定）:

| 対象 | チェック内容の候補 |
| --- | --- |
| PHP（ゲームロジック） | 構文チェック（`php -l`）、コーディング規約、静的解析 |
| JavaScript（クライアント） | ESLint |
| CSS | Stylelint |
| Markdown（docs） | markdownlint、リンク切れチェック |
| JSON（ゲームオプション・統計など） | 構文チェック |
| 全体 | 認証情報の混入チェック |

- PHP のバージョンは BGA 本番環境に合わせる（仕様書作成時に公式ドキュメントで確認）。
- ローカルでも同じコマンドで実行できるようにする。

## CD: BGA Studio への自動同期

BGA Studio はゲームのソースを SFTP で受け取ります。`main` へのマージをトリガーに、`src/` 配下を BGA Studio のプロジェクトフォルダへ自動転送します。

- 同期対象は **`src/` のみ**。`docs/`、`data/`、CI 設定などは転送しない。
- 認証情報（秘密鍵・ユーザー名）は GitHub Secrets、ホスト・ポート・パス・known_hosts は Variables で管理し、リポジトリには置かない。
- 手動実行（`workflow_dispatch`）もできるようにする。

仕様書で確定させる事項:

- BGA Studio の SFTP 接続情報（ホスト・ポート・認証方式）と GitHub Actions からの接続可否
- Secrets の名前と登録手順
- 転送方式（差分転送 / ミラーリング、BGA 側にしかないファイルの扱い）
- 同期の単位（`main` のみか、動作確認用に PR ブランチからも手動同期できるようにするか）
- 同期後に BGA Studio 側で必要な操作（DB スキーマや状態定義を変更した場合の再読み込みなど）
- 失敗時の通知とリトライ

## ユーザー側で必要な準備

CD の導入前に、リポジトリオーナー側で以下が必要です。

1. ~~BGA Studio の開発者アカウントを取得する~~（済）
2. ~~BGA Studio でプロジェクトを作成する~~（済。プロジェクト名: `minimumjourney`）
3. ~~SSH 鍵の作成から GitHub への登録まで~~（済、2026-10-14。RSA 4096 の鍵 1 本を手元と CI で共用。SFTP のユーザー名は `ZoneTsuyoshi`。手順は [005 第 7 節](../specs/005-bga-sync.md)）
