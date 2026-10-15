# テスト

## 結合テスト（MariaDB）

データ層（`CardRepository`・`PlayerValues`・`GlobalValues`）とセットアップ（`GameSetup`）を、実際の MariaDB で動かして確かめる。
BGA のフレームワーク（`Table`）は使わない。

```bash
npm run test:integration
```

必要なもの: Docker だけ。`tests/run-integration.sh` が次を行う。

1. `tests/Dockerfile` から PHP 8.4（`pdo_mysql` と Composer 入り）のイメージ `mj-test-php` を作る。
2. 使い捨てのネットワークと MariaDB（`mariadb:11`）のコンテナを作り、起動を待つ。
3. リポジトリを PHP コンテナにマウントし、`composer install` の後に `composer test:integration`（PHPUnit）を実行する。
4. 終わったらコンテナとネットワークを消す。

`vendor/` はリポジトリ直下に作られる（`.gitignore` 済み）。

## 単体テスト

`Unit/` は BGA にも DB にも依存しない純粋なロジック（`Flow/RoundFlow` の状態遷移の判定と進行度、`Flow/PrepClassifier` の準備フェイズの分類と開示後の計画、`Flow/ExploreOutput` の探査出力、`Flow/ExploreOrder` の探査手番（001 §8-1 の例）、`Flow/ExploreRules` の探査できる対象の判定）のテスト。`composer test:integration` が一緒に実行する（DB 接続は使わない）。

## 構成

| パス | 内容 |
| --- | --- |
| `Unit/` | 状態遷移の純粋な判定の単体テスト（`RoundFlowTest`、`PrepClassifierTest`） |
| `Integration/` | テスト本体。`SetupNewGameTest`（3 人・4 人のセットアップ）、`CardRepositoryTest`（SQL の動作）、`BoardViewTest`（getAllDatas の盤面に非公開情報が出ない）、`PrepPhaseTest`（準備フェイズ 20〜24 を実 DB で回す）、`ExplorePhaseTest`（探査出力・探査手番・探査・パス・自動パス・終了フラグ。準備 → 探査） |
| `Support/PdoDatabase.php` | データ層の `Database` interface を PDO で実装したテスト用クラス |
| `Support/Schema.php` | `src/dbmodel.sql` を流し、BGA が先に作る `player` テーブルを最小限で代用する |
| `Support/ArrayGlobals.php` | BGA の `globals` の代わり（値を JSON にして配列に持つ） |
| `Support/SeededRandom.php` | `bga_rand` の代わりの、種を指定できる乱数 |
| `Support/BgaStubs.php` | BGA の `Globals` クラスの最小のスタブ（`tests/` の中だけで使う） |

## 環境変数

`composer test:integration` を直接実行する場合は、DB への接続を環境変数で渡す。

| 変数 | 既定 |
| --- | --- |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `minimumjourney_test` |
| `DB_USER` | `root` |
| `DB_PASSWORD` | 空 |

テストごとに `card`・`resolve_entry`・`player` テーブルを作り直すので、専用のデータベースを指定すること。

## CI

結合テストは CI に組み込んでいない（組み込むかどうかは I-13 で決める）。
