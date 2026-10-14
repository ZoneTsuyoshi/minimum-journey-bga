# 005: BGA Studio への自動同期

| 項目 | 内容 |
| --- | --- |
| ステータス | Draft |
| 対応タスク | T-005 |
| 作成日 | 2026-10-13 |
| 関連仕様書 | [003 BGA プロジェクト構成・技術方針](003-bga-architecture.md)、004（CI lint） |

## 目的

`main` にマージされた `src/`（と CI が生成するカードスプライト）を、GitHub Actions が SFTP で BGA Studio のプロジェクトフォルダ `/minimumjourney/` へ自動転送する仕組みを定義する。接続方式、転送ツール、削除の扱い、ワークフローの構成、Secrets、作者の準備手順、同期後の手動操作、運用、段階導入を、実装者がこの仕様書だけで導入できる粒度で決める。

## スコープ

- 対象: 接続仕様、転送方式の選定、初回同期、ワークフロー仕様、Secrets / Variables、作者の準備手順、同期後の手動操作、運用（ロールバック等）、段階導入
- 対象外: lint の内容（004）、スプライト生成スクリプトの設計（T-009）、`src/` の中身（003、006 以降）、ワークフローやスクリプトの実装（本仕様書の承認後に別タスク）
- 本仕様書の作成中に BGA Studio へは接続していない。接続に関する事実は公式ドキュメントの記述のみに基づく。認証情報は一切記載しない。

## 記法

- **【確認】** 公式ドキュメント等で確認した事実（URL 付き）。調査日は 2026-10-13。ページは WebFetch（本文を要約して返すツール）で取得したため要約を介した確認である。
- **【提案】** 本仕様書の提案。
- **【未確認】** 確認を試みたが判断できなかった事項。「導入時の接続テストで確かめる項目」（第 12 節）に集約する。
- 公式でない情報（フォーラム、サードパーティ）はその旨を明記する。

主な参照 URL:

| 略称 | URL |
| --- | --- |
| Tools | https://en.doc.boardgamearena.com/Tools_and_tips_of_BGA_Studio |
| FirstSteps | https://en.doc.boardgamearena.com/First_steps_with_BGA_Studio |
| FileRef | https://en.doc.boardgamearena.com/Studio_file_reference |
| VSCode | https://en.doc.boardgamearena.com/Setting_up_BGA_Development_environment_using_VSCode |
| Forum | https://forum.boardgamearena.com/viewtopic.php?t=13370&start=20（公式ドキュメントではなくコミュニティのフォーラム） |
| GH-concurrency | https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/control-the-concurrency-of-workflows-and-jobs |
| GH-environments | https://docs.github.com/en/actions/managing-workflow-runs-and-deployments/managing-deployments/managing-environments-for-deployment |
| GH-events | https://docs.github.com/en/actions/writing-workflows/choosing-when-your-workflow-runs/events-that-trigger-workflows |
| GH-security | https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions |
| lftp | https://lftp.yar.ru/lftp-man.html |

## 仕様

### 1. 目的と全体の流れ

```text
PR（lint 通過 + 作者承認）──squash merge──▶ main
                                             │ push（src/・生成スクリプト・原本画像の変更時）
                                             ▼
                          ┌─────────────── bga-sync.yml ───────────────┐
                          │ job 1: lint（004 の再利用ワークフロー）      │
                          │    └ 失敗したら以降を実行しない              │
                          │ job 2: sync（environment: bga-studio）       │
                          │    1. checkout                               │
                          │    2. Node 準備 → スプライト生成（T-009）    │
                          │       data/cards/ → src/img/cards_*          │
                          │    3. 画像上限チェック（004 の検査を流用）   │
                          │    4. SSH 鍵・known_hosts を一時ファイルに   │
                          │    5. lftp: src/ → /minimumjourney/ へ転送   │
                          │    6. 転送結果と「今回必要な手動操作」を     │
                          │       Job Summary に出力                     │
                          └──────────────────────────────────────────────┘
                                             │ SFTP（ポート 2022、SSH 鍵）
                                             ▼
                            BGA Studio: /minimumjourney/（1 対 1）
                                             │
                          作者が Studio 側で必要な手動操作（第 8 節）
```

- 手動実行（`workflow_dispatch`）は同じワークフローを使う。
- 同期の方向は常に GitHub → Studio の一方向。Studio 側での直接編集は禁止する（第 9 節）。

### 2. 接続仕様

#### 2.1 確認できた事実

| 項目 | 内容 | 区分と根拠 |
| --- | --- | --- |
| ホスト | `1.studio.boardgamearena.com` | 【確認】Tools、VSCode |
| ポート | `2022`（22 ではない） | 【確認】Tools（「The ssh port is 2022」、`sftp://1.studio.boardgamearena.com:2022`）、VSCode |
| プロトコル | SFTP（SSH 上） | 【確認】VSCode（`"protocol": "sftp"`） |
| 認証 | 開発者登録時に受け取る SFTP パスワード、または SSH 鍵。**鍵をアップロードするとパスワード方式は自動で無効になる** | 【確認】Tools |
| ログイン情報 | SFTP のログイン名・パスワードは開発者アカウントのものとは別（初期メールで通知） | 【確認】FirstSteps |
| 鍵の登録場所 | Studio のコントロールパネル https://studio.boardgamearena.com/controlpanel で**公開鍵**をアップロード | 【確認】VSCode（手順 1）、FirstSteps（「via studio control panel」） |
| リモートパス | ログイン後の home にプロジェクト名のフォルダ。VSCode の設定例は `"remotePath": "/<プロジェクト名>/"` | 【確認】FirstSteps、VSCode |
| 同期の推奨 | 自動同期を設定する（手動 FTP は不可）。lftp `mirror --reverse --parallel=10 --delete /local/path/to/myproject/ myproject/`、rsync `rsync -vlrt $LOCAL/$GAME/ $REMOTE/$GAME`、sshfs + lsyncd、WinSCP | 【確認】FirstSteps、Tools |
| 認証情報の扱い | パスワードを含むファイルをコミットしない（GitHub は自動巡回される） | 【確認】Tools |
| 無視するファイル | VSCode の SFTP 設定例の ignore: `.vscode`、`.git`、`node_modules`、`_ide_helper.php`、`vendor`。`syncOption.delete: true` も例示 | 【確認】VSCode |
| GitHub Actions の実例（公式ではない） | フォーラムに、SamKirkland/FTP-Deploy-Action で `sftp://1.studio.boardgamearena.com` へ同期した例（2020-05、Spukyta）と、`pressidium/lftp-mirror-action@v1`（`onlyNewer: true`）に切り替えて動いた例（2023-09、John Riston）がある。VSCode 拡張では「remotePath は `/` で終わらせない（permission denied になる）」という報告もある | 【確認（公式ではない）】Forum。実例は鵜呑みにせず接続テストで確かめる |

#### 2.2 確認できなかった事項

- **【未確認】** 登録できる SSH 公開鍵の数（複数登録できるか、新しい鍵が古い鍵を置き換えるか）。
- **【未確認】** 対応する鍵種別（ed25519 が使えるか。公式の例は `~/.ssh/id_rsa`）。
- **【未確認】** SSH ホスト鍵のフィンガープリント（公式に記載なし）。
- **【未確認】** GitHub-hosted runner の IP からの接続が許可されるか。同時接続数・帯域の制限。
- **【未確認】** サーバーが SFTP 以外（シェル、rsync）を許可するか。Tools の rsync 例は `$REMOTE` が sshfs のマウント先と読めるが、`rsync` の SSH 直接接続が通るかは不明。
- **【未確認】** 鍵を誤って登録してロックアウトされた場合の復旧手段。
- **【未確認】** コントロールパネルの鍵登録画面の詳細（ボタン名、削除の可否）。

#### 2.3 接続パラメーター【提案】

| 項目 | 値 |
| --- | --- |
| ホスト | `1.studio.boardgamearena.com` |
| ポート | `2022` |
| ユーザー | `ZoneTsuyoshi0`（作者が了承した Studio ユーザー名） |
| リモートパス | `/minimumjourney`（末尾スラッシュなし。フォーラムの報告に合わせる。接続テストで `/minimumjourney/` と比較） |
| 認証 | CI 専用の SSH 鍵（パスフレーズなし）。パスワード認証は使わない |
| ホスト鍵の検証 | `StrictHostKeyChecking=yes`。`known_hosts` を Variables に持つ |

#### 2.4 ホスト鍵の固定方法とフィンガープリントの検証【提案】

公式にフィンガープリントの記載がないため、**作者が手元で一度取得して固定**する（初回接続時に信頼する方式）。CI の実行中に `ssh-keyscan` した結果をそのまま信頼してはならない（中間者攻撃を検知できなくなる）。

```bash
# 1. ホスト鍵を取得（作者の手元。ed25519 と rsa の両方）
ssh-keyscan -p 2022 -t ed25519,rsa 1.studio.boardgamearena.com > bga_known_hosts.txt

# 2. フィンガープリントを表示して控える
ssh-keygen -lf bga_known_hosts.txt

# 3. 別の回線（例: スマートフォンのテザリング）から同じ手順を行い、フィンガープリントが一致することを確認
#    （一致しなければ中間者の疑い。固定しない）

# 4. known_hosts の中身を GitHub Variables BGA_SSH_KNOWN_HOSTS に登録（第 6 節）。
#    ホスト鍵は公開情報なので Secrets にする必要はない
cat bga_known_hosts.txt
```

- 取得した行の形式は `[1.studio.boardgamearena.com]:2022 ssh-ed25519 AAAA...`（ポート指定時は角括弧付き）。ssh-keyscan が `-p` 付きで出力する形式をそのまま貼る。
- 可能なら BGA の公式情報（ドキュメントやサポート）で照合する。**【未確認】** 公式に掲載はないため、初回は上記の複数回線での一致確認を代替とする。
- ホスト鍵が変わった場合（サーバー移行など）、CI は接続を拒否して失敗する。これは意図した動作。作者が上記手順で再確認して Variables を更新する。

### 3. 転送方式の比較と推奨

#### 3.1 方式の比較

| 方式 | 長所 | 短所 | 判定 |
| --- | --- | --- | --- |
| **lftp `mirror --reverse`** | 公式ドキュメントが推奨例を載せている（FirstSteps、Tools）。SFTP のみで動く。`--dry-run`、`--exclude`、`--parallel`、`--delete`、再試行設定がある（lftp man） | ランナーに lftp の導入が必要（`apt-get install lftp`）。パスワード入力を求められる挙動があり、鍵認証では設定にコツがいる（第 3.3 節） | **推奨** |
| rsync over ssh | 差分転送が強力。`--delete` と `--exclude`、`--dry-run` が使いやすい | サーバーが SFTP 専用だと動かない可能性が高い。**【未確認】**（公式の rsync 例は sshfs 経由と読める）。接続テストで確かめ、動いても推奨は変えない | 不採用（接続テストで動作を記録） |
| sftp バッチ（`sftp -b`） | OpenSSH 標準で追加導入が不要。確実に SFTP のみで動く | ディレクトリの再帰アップロードや差分判定が弱く、ディレクトリ作成や削除を自前で組む必要がある。`put -R` は全転送 | 予備（lftp が使えない場合）。接続テストの `ls` には使う |
| サードパーティ Action（`pressidium/lftp-mirror-action`、`SamKirkland/FTP-Deploy-Action` など） | 手軽。フォーラムに実例がある（公式ではない、Forum） | 秘密鍵を第三者のコードに渡すことになる（サプライチェーンリスク）。メンテナンス停止や侵害の影響を受ける。SamKirkland 版は「Not a Git project」エラーの報告あり（Forum） | **不採用**。使う場合は以下を守る |

サードパーティ Action を使う場合の条件【提案】: (1) 完全なコミット SHA で固定する（タグではなく。GH-security: 「Pin references to actions to a commit SHA」）、(2) 公式リポジトリ由来の SHA か確認する（フォーク不可）、(3) 秘密鍵を渡す job の権限を最小化し、Dependabot 等でも自動更新しない（更新は人が差分を確認）、(4) 秘密鍵を渡さずに済む構成（SSH 設定だけ自前）を優先。本仕様書では **GitHub 公式 Action（`actions/checkout`、`actions/setup-node`）以外は使わない**。これらも SHA 固定する。

#### 3.2 推奨と設計方針【提案】

**lftp mirror -R を、削除なし・全転送で使う。**

| 論点 | 方針 | 理由 |
| --- | --- | --- |
| 差分か全転送か | **全転送を基本とする**。ただし lftp の既定比較（サイズ・時刻）に任せる | CI の checkout では全ファイルの更新時刻が実行時刻になり、スプライトも毎回再生成される（003 §8 T-005 の検討事項 (a)）ため、差分判定は実質無効。総量は上限 15 MB 以内なので許容する。転送量が問題になったら、スプライトの決定的生成（T-009）と `--ignore-time` + サイズ比較、または内容ハッシュ比較を後から検討する |
| **サーバーにしかないファイルの削除** | **既定では削除しない（`--delete` を付けない）** | Studio が生成した初期ファイル、`_ide_helper.php`（Studio が配置・更新）、将来 Studio が追加するファイルを誤って消さないため |
| 削除が必要なファイル（非推奨ファイルの除去、画像の差し替え） | `scripts/bga-sync/remote-delete.txt`（1 行 1 パス、`src/` 相対）に列挙し、転送後に `rm` する。存在しなくてもエラーにしない（冪等） | 意図したファイルだけを明示的に消す。画像の削除はコントロールパネルでの追加操作も必要（FileRef）。第 8 節に記載 |
| `--delete` を有効にする場合 | 今は有効にしない。将来有効にするなら、**除外リスト**（`--exclude`）に `_ide_helper.php`、Studio が生成するファイル、`misc/`、`.svn`（あれば）を必ず入れ、`--dry-run` の出力を人が確認してから。さらに削除対象を `modules/`・`img/` に限定する | 誤削除の影響が大きい。導入時の接続テストで、サーバー上のファイル一覧を取得してから判断する（第 4 節） |
| 転送順序 | 3 段階: (1) `img/`、(2) `modules/` と `*.css`、(3) ルートの設定・スキーマ（`gameinfos.jsonc`、`gameoptions.jsonc`、`stats.jsonc`、`dbmodel.sql`、`gamepreferences.jsonc`）。lftp スクリプト内で `mirror` を順に実行する | 画像が先にあれば、新しい JS・CSS が参照する画像が存在しない時間が最短になる。設定・スキーマは最後（部分失敗時に新設定と旧コードの組み合わせを避ける） |
| 失敗時の中途半端な状態 | 完全なアトミックにはできない（SFTP に一括置換の機構がない）。対処: (1) 転送前に lint と画像チェックで失敗しうる要因を排除、(2) 3 段階の順序で影響を小さく、(3) lftp の再試行（`net:max-retries 3`）、(4) 失敗したらワークフローを再実行（冪等）。Studio は開発環境なので、一時的な中途半端な状態は許容する | 本番ではないため、ブルー/グリーン切り替えのような仕組みは過剰 |
| 冪等性 | 同じコミットを何度同期しても同じ結果になる。`remote-delete.txt` の削除も存在確認不要。再実行・ロールバック（第 9 節）はこの性質に依存する | 失敗時の再実行を安全にするため |
| 並列度 | `--parallel=2`（公式例は 10 だが、同時接続の制限が**【未確認】**のため控えめにする。問題なければ引き上げ） | 安全側 |
| 権限ビット | `--no-perms` を付ける | ランナーの権限ビットを持ち込まない。サーバー側で拒否される場合の失敗を避ける |

#### 3.3 lftp の接続設定の骨子【提案】

実装者は以下を骨子にする。lftp の設定名は man（https://lftp.yar.ru/lftp-man.html ）で最終確認すること。

```bash
# ランナー上の一時ファイル（ログに出さない）
install -m 600 /dev/null "$RUNNER_TEMP/bga_key"
printf '%s\n' "$BGA_SSH_PRIVATE_KEY" > "$RUNNER_TEMP/bga_key"
printf '%s\n' "$BGA_SSH_KNOWN_HOSTS" > "$RUNNER_TEMP/bga_known_hosts"

lftp -c "
  set cmd:fail-exit yes
  set net:max-retries 3
  set net:timeout 30
  set sftp:auto-confirm no
  set sftp:connect-program 'ssh -a -x -i $RUNNER_TEMP/bga_key -o IdentitiesOnly=yes -o UserKnownHostsFile=$RUNNER_TEMP/bga_known_hosts -o StrictHostKeyChecking=yes -o BatchMode=yes'
  open -p $BGA_SFTP_PORT -u $BGA_SFTP_USER,dummy sftp://$BGA_SFTP_HOST
  mirror --reverse --no-perms --parallel=2 --verbose src/img /minimumjourney/img
  ...
"
```

- lftp は SFTP でパスワード欄が空だと入力を求めるため、ダミー値（`dummy`）を渡すのが定石。鍵認証が使われパスワードは送られない。**【未確認】** BGA のサーバーでその挙動になるかは接続テストで確かめる。
- `BatchMode=yes` で、鍵認証に失敗したときに対話を待ってジョブが固まるのを避ける。
- `-o StrictHostKeyChecking=yes` と `UserKnownHostsFile` で、固定したホスト鍵以外を拒否する。
- 終了処理で `shred`（または `rm -f`）により一時鍵を削除する。ホスト型ランナーは使い捨てだが、念のため。

### 4. 初回同期の手順

プロジェクトは作成済みで未デプロイ。サーバー上には Studio が生成した初期ファイルがある。**CI で初回同期をする前に、必ず初期ファイルを取り込む**（取り込まずに同期すると、新旧のファイルが混在して二重定義になりうる。003 のエッジケース）。

#### 4.1 初回だけ手動で行うことの一覧

| 順 | 作業 | 担当 | 時期 |
| --- | --- | --- | --- |
| 1 | 作者の SSH 鍵（手元用）を Studio に登録し、手元の SFTP クライアントを鍵に切り替える（第 7 節 手順 1〜3） | 作者 | 実装タスクの着手前 |
| 2 | サーバー上の初期ファイルを一時ディレクトリへダウンロードして内容を確認する（下記 4.2） | 作者（または作者の手元でエージェント） | 同上 |
| 3 | ダウンロードした初期ファイルを `src/` に取り込む PR を作る。非推奨ファイル（`states.inc.php`、`material.inc.php`、`*.action.php`、`*.view.php`、`*.tpl`）の扱いは 003 の方針（現行構成のみを採用。動作確認後に削除または空のスタブ）に従う。`_ide_helper.php` は取り込まない。名前空間・クラス名・JSON 拡張子は Studio の生成物に合わせる（003 の未確定事項 B の No.3〜5 に依存） | エージェント（作者承認） | 実装の最初 |
| 4 | ホスト鍵の取得・固定（第 2.4 節）、CI 専用鍵の生成・登録、Secrets / Variables 登録（第 7 節） | 作者 | 実装タスクの前 |
| 5 | 接続テストのワークフローを実行（第 10 節の段階 1） | 作者（実行）、エージェント（実装） | 実装中 |
| 6 | 非推奨ファイルをサーバーから削除する場合は `remote-delete.txt` に列挙する（または SFTP で手動削除） | エージェント（作者承認） | 本番同期の前 |
| 7 | 初回の本番同期後、Studio 側の再読込・動作確認（第 8 節） | 作者 | 初回同期後 |

#### 4.2 初期ファイルのダウンロード

```bash
# 作者の手元。リポジトリの外の一時ディレクトリに取得する（誤ってコミットしない）
mkdir -p ~/tmp/bga-initial
lftp -c "
  set sftp:connect-program 'ssh -a -x -i ~/.ssh/<作者の手元用の鍵> -o IdentitiesOnly=yes'
  open -p 2022 -u ZoneTsuyoshi0,dummy sftp://1.studio.boardgamearena.com
  ls
  mirror /minimumjourney ~/tmp/bga-initial
"
find ~/tmp/bga-initial -type f | sort
```

- `ls` で `minimumjourney` フォルダが見えること（home 直下にあること）を確認する。
- 取得したファイル一覧は、第 12 節の【未確認】（初期ファイルの正確な一覧、003 の U3）の記録として PR に貼る。
- 取得物に認証情報が含まれていないことを確認してから取り込む。

### 5. ワークフロー仕様

#### 5.1 基本情報

| 項目 | 仕様【提案】 |
| --- | --- |
| ファイル | `.github/workflows/bga-sync.yml`（スクリプト本体は `scripts/bga-sync/sync.sh` に置き、ローカルでも `--dry-run` で試せるようにする） |
| 名前 | `BGA Studio Sync` |
| トリガー | `push`（`branches: [main]`、`paths` は下記）と `workflow_dispatch` |
| 実行環境 | `ubuntu-latest`（バージョンは実装時に固定を検討）。**【未確認】** ランナー IP からの接続可否（第 12 節） |

`push` の `paths`（変更があったときだけ同期する）:

```yaml
on:
  push:
    branches: [main]
    paths:
      - 'src/**'
      - 'data/cards/**'           # スプライトの原本
      - 'scripts/sprites/**'      # 生成スクリプト（T-009 でパスを確定）
      - 'scripts/bga-sync/**'     # 同期スクリプト自身
      - 'package.json'
      - 'package-lock.json'       # 画像ライブラリのバージョン変更
      - '.github/workflows/bga-sync.yml'
  workflow_dispatch:
    inputs:
      mode:
        description: 'test = ls のみ / dry-run = 転送せず差分表示 / sync = 本番'
        type: choice
        options: [test, dry-run, sync]
        default: dry-run
```

- 【提案】`docs/**`、`.github/workflows/` の他のファイルなどだけの変更では同期しない。
- `paths` の判定は、squash merge ではマージコミットの変更ファイルで行われる想定。**導入時に確かめる**（GH-events では `paths` の一般説明のみ確認、squash merge 固有の挙動は未確認）。

#### 5.2 lint の成功を前提にする方法

| 案 | 内容 | 評価 |
| --- | --- | --- |
| **A. 同一ワークフロー内で再利用ワークフローを呼ぶ（`needs`）** | 004 の lint を `workflow_call` 対応にし、`bga-sync.yml` の `lint` job で `uses:` して、`sync` job が `needs: lint` | **推奨**。同じコミットを同じ実行内で検証でき、`paths` の判定とも矛盾しない。lint の二重実行にはなるが数分程度 |
| B. `workflow_run` | lint ワークフローの完了後に起動し、`conclusion == 'success'` を条件に | `workflow_run` は既定ブランチ上のワークフローファイルだけが対象、成功・失敗に関わらず起動する（GH-events）。`paths` が使えず、`head_sha` の扱いが煩雑 |
| C. ブランチ保護に任せる | PR で lint が通らないとマージできないので、`main` は常に lint 通過済みとみなす | 管理者が保護を迂回した場合や、直接 push に弱い。004 が「`main` への push 時にも lint」を要求しているので、冗長な保証がない |

推奨は **A**。T-004 の実装に「lint ワークフローを `on: workflow_call` でも呼べるようにする」要件を申し送る（第 11 節）。A が難しければ C（ブランチ保護）＋ B を併用しない形で、`main` への push で同期前に最低限の検査（JSON 構文、画像上限）だけを同期ワークフロー内で再実行する。

#### 5.3 job と step の順序

1. `lint`（再利用ワークフロー）
2. `sync`（`needs: lint`、`environment: bga-studio`）:
   1. `actions/checkout`（SHA 固定、`persist-credentials: false`）
   2. `actions/setup-node`（バージョン固定）、`npm ci`
   3. **スプライト生成**（T-009 のスクリプト。`data/cards/` → `src/img/cards_*`）。**lint の後、転送の前**。生成物は `.gitignore` 対象で、コミットされない
   4. 画像上限チェック（1 枚 2 MB、総量 15 MB、4096×4096 px。004 のチェックを生成後の `src/img/` に対して実行。003 §8 T-004）
   5. `mode` に応じて、`test`（`ls` のみ）、`dry-run`（`mirror --dry-run`）、`sync`（本番）
   6. `remote-delete.txt` の削除（`sync` のときのみ）
   7. Job Summary に結果と手動操作チェックリストを出力（第 8 節）
   8. 一時鍵の削除（`if: always()`）

#### 5.4 運用パラメーター

| 項目 | 仕様【提案】 |
| --- | --- |
| concurrency | `group: bga-studio-sync`、**`cancel-in-progress: false`**。理由: 転送中のキャンセルは中途半端な状態を作る。既定動作で、待機中の古い実行は最新の 1 件に置き換わる（GH-concurrency: 「any existing `pending` job or workflow in the same concurrency group will be canceled」）ので、最新のコミットが最終的に同期される。`queue: max` は不要 |
| environment | `bga-studio`。Secrets / Variables をここに置く（GH-environments: 環境の Secrets は当該環境を使う job だけが参照できる）。**デプロイ先ブランチを `main` に限定**する。**必須レビュアーは設定しない**（作者 1 人で毎回承認するのは手間で、自分自身の承認を防ぐオプションとも相性が悪い）。private リポジトリで環境を使うには GitHub Pro / Team 以上が必要（GH-environments: 「Users with GitHub Free plans can only configure environments for public repositories」）。**契約を作者に確認**（未確定事項 No.3） |
| permissions | ワークフロー全体は `permissions: {}`（または `contents: read`）。`sync` job は `contents: read` のみ。`GITHUB_TOKEN` に書き込み権限は与えない（GH-security: 最小権限） |
| タイムアウト | `lint` は 004 に従う。`sync` は `timeout-minutes: 15`。lftp の `net:timeout 30`（秒）、`net:max-retries 3` |
| リトライ | lftp 内の再試行に加え、同期ステップが失敗したら**1 回だけ**自動で再実行する（冪等のため安全）。それでも失敗したら job を失敗にする。lint の失敗は再試行しない |
| 失敗時の通知 | GitHub が標準で、失敗した実行の起動者へメールを送る（リポジトリ所有者の通知設定による）。追加の仕組み（Issue 自動作成、Slack 等）は作らない。Job Summary に失敗した段階を記載する |
| ログの注意 | (1) `set -x` を使わない。(2) 秘密鍵を `echo` しない（`printf` でファイルへ）。(3) 秘密鍵をビルド引数・JSON・環境変数の連結で扱わない（GH-security: 構造化データや変換した値は自動マスクされにくい）。(4) lftp の `debug` を有効にしない（認証情報の断片が出うる）。(5) ホスト・ユーザーが Secrets の場合、ログの該当文字列は `***` に置き換わる。(6) 環境変数 `BGA_SSH_PRIVATE_KEY` は鍵を書き出す step だけに渡す |
| fork からの PR | `pull_request_target` は使わない。同期ワークフローは PR で起動させない |

#### 5.5 PR ブランチからの手動同期【提案】

- 動作確認のために PR ブランチを Studio へ同期したい場面はある（Studio でしか確認できないため。003 §7）。ただし**同一のプロジェクトフォルダへの上書き**であり、後勝ちになる。`main` の同期との競合、PR 同士の競合が起きる。
- 初期導入では**許可しない**。`environment: bga-studio` を `main` 限定にすることで技術的に防ぐ（`workflow_dispatch` を PR ブランチで実行しても環境のブランチ制限で拒否される）。
- 必要になったら、別の環境 `bga-studio-preview`（必須レビュアーに作者を設定。同じ Secrets を登録）を作り、`workflow_dispatch` で `ref` を指定できるようにする。その場合の約束: 一度に 1 ブランチだけ。作業が終わったら `main` を再同期して元に戻す。Job Summary に「ブランチ `<name>` を同期しました。main に戻すには main を再同期」と表示。
- ブランチ同期でも生成ステップを必ず通す（スプライトはコミットされないため。003 §8）。

### 6. Secrets / Variables

登録場所: GitHub リポジトリ Settings → Environments → `bga-studio`（環境の Secrets / Variables）。環境が使えない場合は Settings → Secrets and variables → Actions（リポジトリ単位）。値はこの仕様書に書かない。

| 名前 | 種別 | 内容 | 備考 |
| --- | --- | --- | --- |
| `BGA_SSH_PRIVATE_KEY` | Secret | CI 専用 SSH 秘密鍵の全文（`-----BEGIN OPENSSH PRIVATE KEY-----` から最終行まで、改行を保持） | パスフレーズなし |
| `BGA_SFTP_USER` | Secret | Studio のユーザー名 | CLAUDE.md の「SFTP 情報は Secrets のみ」に従い Secret とする（未確定事項 No.2） |
| `BGA_SFTP_HOST` | Variable | `1.studio.boardgamearena.com` | 公式ドキュメントに公開されている値。Secret にするとログの該当部分が `***` になり調査しにくい。方針を CLAUDE.md に合わせて Secret にしてもよい（No.2） |
| `BGA_SFTP_PORT` | Variable | `2022` | 同上（Secret にすると、ログの「2022」が全て伏字になる） |
| `BGA_REMOTE_DIR` | Variable | `/minimumjourney` | 同上 |
| `BGA_SSH_KNOWN_HOSTS` | Variable | 第 2.4 節で取得した `known_hosts` の行 | 公開情報。改ざんされないよう、環境の Variables を編集できる人を作者のみにする |

- 003 の暫定案（`BGA_SFTP_HOST` 等を Secrets に置く）から、公開情報を Variables に分けた点を変更している。作者が CLAUDE.md の規則 7 を文字どおり適用したい場合は、全て Secrets に置いてよい（実装は `secrets.*` を `vars.*` に替えるだけ）。
- 秘密鍵は Secrets のみ。リポジトリ・Issue・PR・ログ・仕様書には書かない。004 の「認証情報混入チェック」が秘密鍵のヘッダー（`BEGIN OPENSSH PRIVATE KEY` など）を検知する（003 §8 T-004）。

### 7. 作者が行う準備手順

実装タスクの着手前に、リポジトリオーナー（作者）が手元で行う。**順序を守ること**（鍵を登録した瞬間にパスワード認証が無効になる。第 2.1 節）。

> 前提: Studio のコントロールパネル https://studio.boardgamearena.com/controlpanel にログインできること。**【未確認】** 鍵の登録画面の詳細と、複数の鍵を登録できるかは公式に記載がない。手順 3 と 5 で実際に確かめ、結果を PR に記録する。

1. **鍵ペアを 2 本生成する**（手元用と CI 専用。鍵を使い回さない）。

   ```bash
   mkdir -p ~/.ssh && chmod 700 ~/.ssh

   # 手元用（SFTP クライアント・初回ダウンロード用）。パスフレーズ付きを推奨
   ssh-keygen -t ed25519 -C "bga-studio-local-zonetsuyoshi" -f ~/.ssh/bga_studio_local

   # CI 専用。パスフレーズなし（-N ""）。コメントに用途と日付を入れる
   ssh-keygen -t ed25519 -N "" -C "github-actions minimum-journey-bga 2026-10" -f ~/.ssh/bga_studio_ci
   ```

   - 鍵種別は ed25519 を第一候補にする。**【未確認】** BGA が ed25519 を受け付けるか（公式の例は `id_rsa`）。登録が拒否された場合は `-t rsa -b 4096` で作り直す。
   - 秘密鍵（`bga_studio_ci`、`bga_studio_local`）は**絶対にリポジトリ内に置かない**。公開鍵（`.pub`）だけをアップロードする。

2. **ホスト鍵を取得して固定する**（第 2.4 節の手順 1〜3）。複数回線で一致を確認したら、`bga_known_hosts.txt` を手元に保存しておく。

3. **手元用の公開鍵を Studio に登録し、手元から接続できることを確認する。**
   1. https://studio.boardgamearena.com/controlpanel を開き、SSH 公開鍵の登録欄に `cat ~/.ssh/bga_studio_local.pub` の出力を貼ってアップロードする。
   2. 次のコマンドで接続する。`minimumjourney` が見えれば成功。

      ```bash
      sftp -P 2022 -i ~/.ssh/bga_studio_local -o IdentitiesOnly=yes \
        -o UserKnownHostsFile=bga_known_hosts.txt ZoneTsuyoshi0@1.studio.boardgamearena.com
      # sftp> ls
      # sftp> exit
      ```

   3. 手元の SFTP クライアント（VSCode の SFTP 拡張、WinSCP、Cyberduck など）のパスワード認証を、鍵認証（`privateKeyPath`）に切り替える（VSCode は https://en.doc.boardgamearena.com/Setting_up_BGA_Development_environment_using_VSCode の `privateKeyPath` を参照。認証情報はプロジェクトの `sftp.json` ではなくユーザー設定に置く）。

4. **CI 専用の公開鍵を Studio に追加登録する。**
   1. コントロールパネルで `cat ~/.ssh/bga_studio_ci.pub` の出力を登録する。
   2. **登録後、手順 3 の接続確認を手元用の鍵で再度行う。**
      - 手元用の鍵で**接続できる** → 複数鍵を登録できる。問題なし。
      - 手元用の鍵で**接続できない**（CI 用の鍵で置き換わった）→ 鍵は 1 本しか登録できない。後述「登録の順序を間違えた場合の復旧」へ。
   3. CI 用の鍵でも接続できることを確認する（`-i ~/.ssh/bga_studio_ci`）。

5. **GitHub に環境と Secrets / Variables を登録する。**
   1. GitHub のリポジトリ → Settings → Environments → New environment → 名前 `bga-studio`。Deployment branches は「Selected branches and tags」で `main` のみ。必須レビュアーは設定しない。
   2. その環境の Secrets に追加:
      - `BGA_SSH_PRIVATE_KEY`: `cat ~/.ssh/bga_studio_ci` の出力全文（`-----BEGIN` と `-----END` の行を含め、改行を保つ）
      - `BGA_SFTP_USER`: Studio のユーザー名
   3. その環境の Variables に追加: `BGA_SFTP_HOST`、`BGA_SFTP_PORT`、`BGA_REMOTE_DIR`、`BGA_SSH_KNOWN_HOSTS`（値は第 6 節）。
   4. 登録後、`bga_studio_ci`（秘密鍵）が手元に不要なら、パスワードマネージャー等の安全な場所へ移し、通常の `~/.ssh` からは削除してよい（再発行は容易）。
   - コマンドでの登録例（`gh` CLI）:

     ```bash
     gh secret set BGA_SSH_PRIVATE_KEY --env bga-studio < ~/.ssh/bga_studio_ci
     gh secret set BGA_SFTP_USER --env bga-studio
     gh variable set BGA_SSH_KNOWN_HOSTS --env bga-studio < bga_known_hosts.txt
     ```

6. **初回ダウンロード**（第 4.2 節）を行い、結果を共有する。
7. 実装タスクの接続テスト（第 10 節の段階 1）の結果を確認する。

#### 登録の順序を間違えた場合の復旧【提案】

| 状況 | 症状 | 復旧 |
| --- | --- | --- |
| 鍵を登録する前にパスワードで作業していた | 鍵の登録直後からパスワードで接続できない | 手元用の鍵を先に登録してあれば、そのまま鍵で接続する。登録した鍵が間違っていた場合は、コントロールパネルから正しい公開鍵を再度アップロード（上書き）する |
| CI 用の鍵を登録したら手元用の鍵が使えなくなった（鍵が 1 本しか持てない） | 手元から接続できない | (a) 手元用の鍵を再登録すると CI の鍵が使えなくなる。**暫定案: 1 本の鍵を手元と CI で共用する**（`bga_studio_ci` を手元でも使う）。パスフレーズなしの鍵が手元にもあることになるため、鍵ファイルの管理（`chmod 600`、バックアップ先）を厳格にする。(b) または手元の編集はすべて GitHub 経由にし、手元からの SFTP は初回ダウンロード時だけ CI 用の鍵を使う |
| どの鍵でも接続できない（鍵の貼り間違い、改行の欠落など） | `Permission denied (publickey)` | 公開鍵を `ssh-keygen -y -f ~/.ssh/bga_studio_ci` で再生成して貼り直す。それでもだめなら BGA のサポート／フォーラムに相談（**【未確認】** パスワード認証を再有効化する公式手順は不明） |
| GitHub に貼った秘密鍵の改行が壊れた | CI で `error in libcrypto` や `invalid format` | `gh secret set ... < ファイル` で再登録（標準入力から読むと改行が保たれる） |
| 鍵の漏えいが疑われる | — | コントロールパネルから公開鍵を差し替え（鍵を再生成して上書き）、GitHub の Secret を更新、旧鍵を破棄する |

### 8. 同期後に Studio 側で必要な手動操作

CI が転送しても、Studio 側の再読込は人手が必要（自動化できる公式手段は確認できなかった。003 §6 U8）。

#### 8.1 変更したファイル種別ごとのチェックリスト

出典: FileRef https://en.doc.boardgamearena.com/Studio_file_reference 、FirstSteps（Control Panel）、gameinfos https://en.doc.boardgamearena.com/Game_meta-information:_gameinfos.jsonc 。

| 変更したファイル | 同期後に必要な操作 | 区分 |
| --- | --- | --- |
| `dbmodel.sql` | **進行中のゲームを破棄して、新しくゲームを開始（再起動）する**。本番公開後は DB 移行（`upgradeTableDb`）が必要 | 【確認】FileRef |
| `gameinfos.jsonc` | コントロールパネルの「Reload game informations」 | 【確認】003 の gameinfos ページ参照 |
| `gameoptions.jsonc`（`.json`）、`gamepreferences.jsonc` | コントロールパネルで該当の再読込（「Reload game options configuration」など。正式な名称は画面で確認） | 【確認】FileRef（「special reloading using control panel」）。ボタン名は【未確認】 |
| `stats.jsonc`（`.json`） | コントロールパネルで該当の再読込（統計の再読込） | 【確認】FileRef。ボタン名は【未確認】 |
| `modules/js/Game.js` | 開いているゲーム画面を再読込（F5）。**他のブラウザタブは手動で再読込**（Tools） | 【確認】FileRef、Tools |
| `minimumjourney.css` | 強制再読込（Ctrl+F5 / Cmd+Shift+R） | 【確認】FileRef |
| `modules/php/**`（`Game.php`、状態クラスなど） | 新しいゲームの開始、または進行中のゲームの再読込。状態クラスの `id` や遷移を変えた場合は、進行中のゲームを破棄して新規開始 | 【提案】（公式に明示なし）。動作確認で確かめる |
| `img/` の追加・変更（ゲーム箱・アイコン以外） | 通常は即時反映。キャッシュ消去が必要な場合あり | 【確認】003 §5 |
| `img/` のゲーム箱・アイコン画像 | コントロールパネルの「Reload game box image」 | 【確認】003 §5（FAQ） |
| `img/` の**ファイルを削除**した | コントロールパネルでの追加操作が必要（FileRef: 「deleting files require extra actions from control panel」）。具体的な操作は【未確認】 | 【確認】FileRef |
| スプライトの再生成・差し替え | 通常は即時反映。旧ファイル名が残るのでスプライトのファイル名を変えたら `remote-delete.txt` に旧名を追加し、上記の削除操作 | 【提案】 |
| 非推奨ファイルの削除（`states.inc.php` など） | `remote-delete.txt` で削除後、新規ゲームで起動できることを確認 | 【提案】 |
| いずれの変更後も | 1 回、Express Start で新しいゲームを開始して動作確認。「Commit my modifications now」（Studio 内蔵 SVN）は任意（第 9 節） | 【提案】 |

#### 8.2 ワークフローのサマリーに「今回必要な手動操作」を自動表示する案【提案】

`sync` job の最後に、同期したコミットの変更ファイル（`git diff --name-only ${{ github.event.before }} ${{ github.sha }}`）から、必要な操作を `$GITHUB_STEP_SUMMARY` に書き出す。`workflow_dispatch` 時や `before` が使えない場合（新規ブランチ、強制 push）は「全項目を確認」のチェックリストを出す。

```bash
# scripts/bga-sync/summary.sh の骨子
changed="$(git diff --name-only "$BEFORE" "$AFTER" -- src/)"
{
  echo "## 今回必要な Studio 側の手動操作"
  grep -q '^src/dbmodel.sql$'          <<<"$changed" && echo "- [ ] dbmodel.sql 変更: 進行中のゲームを破棄して新規開始"
  grep -q '^src/gameinfos.jsonc$'      <<<"$changed" && echo "- [ ] gameinfos 変更: コントロールパネルで Reload game informations"
  grep -qE '^src/(gameoptions|gamepreferences)\.' <<<"$changed" && echo "- [ ] オプション/設定 変更: コントロールパネルで再読込"
  grep -q '^src/stats\.'               <<<"$changed" && echo "- [ ] 統計 変更: コントロールパネルで再読込"
  grep -q '\.css$'                     <<<"$changed" && echo "- [ ] CSS 変更: ブラウザで強制再読込"
  grep -q '^src/modules/js/'           <<<"$changed" && echo "- [ ] JS 変更: 全タブを再読込"
  # 削除された画像（git diff --diff-filter=D の src/img/）があれば、コントロールパネルでの削除操作を表示
} >> "$GITHUB_STEP_SUMMARY"
```

- 手動操作が不要なら「手動操作なし」と表示する。
- スプライトは生成物でコミットされない。スプライトの内容が変わったか（原本 `data/cards/` や生成スクリプトの変更）も判定に含める。

### 9. 運用

| 論点 | 方針【提案】 |
| --- | --- |
| ロールバック | 戻したいコミットを `git revert` した PR を `main` に入れる（履歴を残す）。急ぐ場合は `workflow_dispatch` で `ref` を指定して再同期できるようにしてもよいが、初期導入では許可しない（第 5.5 節）ため、revert の PR で行う。同期は冪等なので、戻すコミットも同じ手順で転送される。**ただしサーバーにあって `src/` に無いファイルは戻らない**（削除しない方針）。`remote-delete.txt` で削除したものは、戻すと再アップロードされる |
| Studio 側での直接編集 | **禁止**。次の同期で上書きされる（全転送のため）。やむを得ず Studio 側で試す場合は、同じ内容を直ちに GitHub に反映する |
| 直接編集の検知 | 手動実行用の `drift-check` モード（`workflow_dispatch` の `mode`）を追加できる: サーバーを一時ディレクトリにダウンロードして `src/` とバイト比較し、差分のあるファイル名だけを Summary に出す（`_ide_helper.php`、非推奨で残したファイルなどは除外リストで無視）。定期実行はしない（Studio への負荷と、失敗通知のノイズを避ける）。導入は段階 3 以降の任意 |
| Studio 内蔵の SVN との関係 | Studio には内蔵 SVN があり、コントロールパネルの「Commit my modifications now」でサーバー上の現在のファイルをコミットできる（FirstSteps、Tools。外部の GitHub 併用を推奨）。**SVN のコミットは GitHub の履歴と独立**する。運用: GitHub が正、SVN は Studio 側の保険（マイルストーン時に作者が手動でコミット）。SVN から GitHub への逆同期はしない。SVN の履歴から復元する場合は、復元結果を GitHub に PR で反映してから再同期する |
| 秘密鍵のローテーション | 年 1 回、または漏えいが疑われるとき。第 7 節の手順 1・4・5 を繰り返し、古い公開鍵を差し替える |
| 権限 | 環境 `bga-studio` の Secrets / Variables を編集できる人は作者のみ |

### 10. 段階導入

実装は 3 段階で、各段階を別の PR とし、作者が結果を確認してから次に進む（CLAUDE.md の「1 タスク = 1 PR」に従い、実装タスクを分ける）。

| 段階 | 内容 | 完了条件 |
| --- | --- | --- |
| 1. 接続テスト | `workflow_dispatch` のみのワークフロー。鍵と `known_hosts` を設定し、`ls /minimumjourney`（`sftp -b` または lftp）を実行して、一覧を Summary に出す。**書き込みはしない**。第 12 節の【未確認】項目のうち接続に関するものを確認し、結果を PR に記録 | `ls` が成功する。複数鍵、ed25519、ランナー IP、`/minimumjourney` と `/minimumjourney/` の違い、rsync の可否が分かる |
| 2. dry-run | `mode: dry-run` を追加。lint とスプライト生成、画像チェックまで実行し、`lftp mirror --dry-run` で転送予定のファイル一覧を Summary に出す。**サーバーは変更しない** | 転送予定の一覧が想定どおり。サーバーにしかないファイルが触られないことを確認 |
| 3. 本番同期 | `push` トリガーと `mode: sync` を有効化。`remote-delete.txt`、手動操作サマリー（第 8.2 節）を追加。初回の `src/` 取り込み（第 4 節）が済んでいること | `main` へのマージで同期が成功し、Express Start でゲームが起動する |

- 段階 1・2 の間は `push` トリガーを入れない。
- 段階 3 の最初の同期は、作者が見ている状態で `workflow_dispatch` で実行し、成功を確認してから `push` トリガーを有効にする。

### 11. 後続への申し送り

#### T-004（CI lint）

- lint ワークフローを `on: workflow_call` でも呼べるようにする（第 5.2 節 案 A）。
- 画像上限チェック（1 枚 2 MB、総量 15 MB、4096×4096 px）を、スプライト生成後の `src/img/` に対して実行できる形で用意する。
- 認証情報混入チェックに `BEGIN OPENSSH PRIVATE KEY` などを含める。

#### T-009（スプライト生成）

- 接続点: 同期ワークフローが、`npm ci` の後・転送の前に、**1 つのコマンド**（例: `npm run build:sprites`）で `data/cards/` → `src/img/cards_*` を生成できること。終了コードで成否が分かること。
- 決定的な生成（同じ原本から同じバイト列。画像ライブラリのバージョン固定、メタデータ出力の抑制）にして、将来の差分転送に備える。
- 生成物は `.gitignore`。ファイル名を変更した場合は、旧名を `scripts/bga-sync/remote-delete.txt` に追加し、第 8 節の画像削除の操作を行う。
- `push` の `paths` に生成スクリプトと依存定義のパスを追加する（第 5.1 節）。

#### 実装タスク（本仕様書の承認後）

- `.github/workflows/bga-sync.yml`、`scripts/bga-sync/sync.sh`、`summary.sh`、`remote-delete.txt`（空で作成）
- 段階 1 → 2 → 3 の順に別 PR。段階 1 の前に作者の準備（第 7 節）が済んでいること
- 全ての Action を SHA 固定。lftp は `apt-get install` 後に `lftp --version` をログに残す
- 003 の Q3〜Q5（初期ファイルの取り込み・名前空間・拡張子）の回答待ち。第 4 節の取り込みは実装の前提

### 12. 導入時の接続テストで確かめる項目（【未確認】一覧）

| No. | 項目 | 確認方法 | 結果の影響 |
| --- | --- | --- | --- |
| C1 | 複数の SSH 公開鍵を登録できるか | 第 7 節 手順 4 | できない場合は鍵を 1 本共用（第 7 節の復旧表） |
| C2 | ed25519 が使えるか | 手順 3 | 使えなければ RSA 4096 |
| C3 | GitHub-hosted runner の IP から接続できるか | 段階 1 | 不可なら self-hosted runner か運用の見直し（作者に相談） |
| C4 | lftp の鍵認証（ダミーパスワード、`connect-program`）が動くか | 段階 1 | 動かなければ `sftp -b` で実装 |
| C5 | rsync が使えるか | 段階 1（`rsync --dry-run`） | 使えても本仕様書は lftp 推奨を変えない（差分転送が必要になったときの選択肢） |
| C6 | `remotePath` の末尾スラッシュの有無で挙動が変わるか | 段階 1 | 変わる場合はどちらかに固定 |
| C7 | 初期ファイルの正確な一覧、`_ide_helper.php` の位置、`.svn` 等の隠しファイルの有無 | 第 4.2 節 | `--delete` を有効にしないため影響は小さい |
| C8 | 同時接続数・帯域の制限、`--parallel` の上限 | 段階 2〜3 | 超えたら 1 に下げる |
| C9 | ホスト鍵のフィンガープリントの公式な出典 | BGA サポート／フォーラム | なければ複数回線の一致確認で代替 |
| C10 | `push` の `paths` が squash merge で期待どおり動くか | 段階 3 の最初のマージ | 動かなければ `paths` を外し全 `main` push で同期 |
| C11 | 各再読込ボタンの正式名称、画像削除の具体的操作、`modules/php` 変更後の再読込要否 | 初回同期後に作者が確認 | 第 8.1 節を更新 |
| C12 | 鍵登録の取り消し・パスワード認証の再有効化の方法 | コントロールパネルの画面確認 | 復旧表（第 7 節）を更新 |
| C13 | 鍵がサーバー側で有効になるまでの遅延 | 登録直後の接続 | 遅延があれば待機手順を追加 |

## エッジケース

- **鍵の貼り付けミス**: 秘密鍵の改行が壊れると `invalid format`。`gh secret set < ファイル` で登録する（第 7 節）。
- **同時に 2 回マージされた**: concurrency により 1 件目は実行中、2 件目は待機。3 件目が来たら 2 件目は取り消され、3 件目だけが残る。最後のコミットが同期されるので問題ない。
- **lint が通るのにスプライト生成が失敗**: `sync` job が失敗し、転送は行われない（サーバーは前回のまま）。
- **転送の途中で失敗**: 再実行する（冪等）。画像は転送済み・JS は未転送、のような状態は再実行で解消する。
- **Studio 側のメンテナンスやホスト鍵の変更**: 接続失敗または鍵不一致。ホスト鍵不一致のときは自動で回避せず、作者が第 2.4 節で再確認する。
- **`remote-delete.txt` に誤ったパス**: 存在しないファイルは無視するが、`src/` 外へのパス（`..` を含む）や絶対パスはスクリプトが拒否する。ディレクトリの再帰削除は対応しない（ファイルのみ）。
- **PR ブランチを同期したまま main の同期を忘れた**: Studio が PR ブランチの状態のまま。第 5.5 節の約束と Job Summary の注意書きで防ぐ。
- **スプライトのファイル名が変わった**: サーバーに旧画像が残る（削除しない方針）。`remote-delete.txt` と画像削除操作（第 8 節）が必要。残っても動作には影響しないが、総量 15 MB の制限（Checklist）に影響する。
- **BGA が鍵認証を拒否する設定に変わった**: 接続テスト（段階 1）で早期に検出。公式ドキュメントの更新を確認する。

## 未確定事項

| No. | 質問 | 暫定案 | 回答 |
| --- | --- | --- | --- |
| 1 | `main` への push のたびに自動で同期してよいか（承認なし）。Studio は開発環境で、進行中のテストゲームがあると壊れる可能性がある | 自動同期する。必須レビュアーは設定しない。必要なら `workflow_dispatch` のみに切り替え可能 | 「ok」（2026-10-13）。暫定案どおり確定: `main` への push で承認なしに自動同期する。必須レビュアーは設定しない |
| 2 | ホスト・ポート・リモートパス・known_hosts を Variables（公開情報）に置いてよいか。CLAUDE.md の規則 7 は「SFTP 情報は Secrets のみ」 | ユーザー名と秘密鍵のみ Secrets。ホスト鍵・ホスト・ポート・パスは Variables。全て Secrets にする方針なら可 | 「ok」（2026-10-13）。暫定案どおり確定: Secrets はユーザー名と秘密鍵のみ。ホスト・ポート・リモートパス・known_hosts は Variables。CLAUDE.md の規則 7 の文言（「SFTP 情報は Secrets のみ」）は「認証情報（秘密鍵・ユーザー名）は Secrets のみ」に改める（導入タスクで更新） |
| 3 | GitHub のプランとリポジトリの公開範囲（private で Free プランだと Environments が使えない） | Environments を使う。使えなければリポジトリ単位の Secrets とし、ブランチ制限は `if: github.ref == 'refs/heads/main'` で代替 | 一部確認（2026-10-13）。リポジトリは現在 **private**（未認証の API 照会が 404 を返すことで確認）。作者から「どちらが良いか」と質問があり、メインエージェントは private の維持を推奨（原本のカード画像が印刷に近い解像度で含まれるため）。GitHub のプラン（Free / Pro）は未回答。Free の場合は暫定案の代替（リポジトリ単位の Secrets と `if: github.ref == 'refs/heads/main'`）を使う |
| 4 | CI 専用の鍵と手元用の鍵を分けてよいか。Studio が鍵を 1 本しか登録できない場合は、1 本を共用してよいか | 分ける。1 本のみなら共用（鍵の保管を厳格に） | |
| 5 | サーバーにしかないファイルは削除しない運用（`--delete` なし）でよいか。不要ファイルは `remote-delete.txt` で明示削除 | 削除しない | |
| 6 | PR ブランチからの手動同期を初期は禁止してよいか（後から別環境で許可） | 禁止 | |
| 7 | 失敗時の通知は GitHub 標準のメールのみでよいか | メールのみ | |
| 8 | lint の前提をどの方式にするか（再利用ワークフロー `needs` を推奨） | 案 A（T-004 に `workflow_call` 対応を依頼） | |
| 9 | 初回のサーバー上の初期ファイルのダウンロードと取り込みを、誰がいつ行うか（作者の手元で実行するか、結果を共有してもらうか） | 作者がダウンロード結果（ファイル一覧）を共有、取り込みはエージェントが PR で | 「ok」（2026-10-13）。暫定案どおり確定: 作者がダウンロードしてファイル一覧を共有し、取り込みはエージェントが PR で行う |
| 10 | Studio 側でのテストゲームを同期中に破棄してよいか（`dbmodel.sql` 変更時） | 開発中は破棄可 | |

## 受け入れ条件

- [ ] 接続のホスト・ポート・認証方式が出典 URL つきで書かれ、確認できなかった事項が【未確認】として第 12 節に一覧されている
- [ ] 転送方式が比較され、推奨（lftp mirror -R、削除なし、全転送）と理由がある。サーバーにしかないファイルを削除しない設計と、削除を有効にする場合の除外リストがある
- [ ] 初回同期の手順と担当、初回だけ手動で行うことの一覧がある
- [ ] ワークフローのファイル名、トリガー、lint 前提、スプライト生成の位置、concurrency、environment、permissions、タイムアウト、リトライ、通知、ログの注意、PR ブランチの扱いが決まっている
- [ ] Secrets / Variables の一覧がある（値は書かれていない）
- [ ] 作者の準備手順が番号つき・コマンドつきで書かれ、登録順序を誤った場合の復旧がある
- [ ] 変更ファイル種別ごとの手動操作チェックリストと、サマリー自動表示の案がある
- [ ] ロールバック、Studio 側直接編集、内蔵 SVN との関係が書かれている
- [ ] 段階導入（接続テスト → dry-run → 本番）の計画がある
- [ ] T-004・T-009・実装タスクへの申し送りがある
- [ ] 「未確定事項」に作者が回答し、回答に合わせて更新されたうえでステータスが Approved になる
