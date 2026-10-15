#!/usr/bin/env bash
# BGA Studio から開発用スタブ（_ide_helper.php、bga-framework.d.ts）を tools/bga/ に取得する（仕様書 004 第 2.4 節）。
# スタブは BGA の配布物で再配布の許諾が確認できないため、リポジトリにはコミットせず、CI が実行のたびに取得する。
# 接続方法は scripts/bga-sync/sync.sh と同じ（読み取りのみ）。必要な環境変数（ログに出さない）:
#   BGA_KEY_FILE / BGA_KNOWN_HOSTS_FILE / BGA_SFTP_USER / BGA_SFTP_HOST / BGA_SFTP_PORT / BGA_REMOTE_DIR
# 注意: set -x を使わない。lftp の debug を有効にしない。
set -euo pipefail

for v in BGA_KEY_FILE BGA_KNOWN_HOSTS_FILE BGA_SFTP_USER BGA_SFTP_HOST BGA_SFTP_PORT BGA_REMOTE_DIR; do
  if [ -z "${!v:-}" ]; then
    echo "環境変数 $v が空です" >&2
    exit 2
  fi
done

# lftp -c に文字列として渡すため、許可する文字を絞る（コマンド注入の防止）
[[ "$BGA_SFTP_USER" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "BGA_SFTP_USER に使えない文字があります" >&2; exit 2; }
[[ "$BGA_SFTP_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "BGA_SFTP_HOST に使えない文字があります" >&2; exit 2; }
[[ "$BGA_SFTP_PORT" =~ ^[0-9]+$ ]] || { echo "BGA_SFTP_PORT は数字のみです" >&2; exit 2; }
[[ "$BGA_REMOTE_DIR" =~ ^/[A-Za-z0-9._/-]*$ ]] || { echo "BGA_REMOTE_DIR に使えない文字があります" >&2; exit 2; }
[[ "$BGA_KEY_FILE$BGA_KNOWN_HOSTS_FILE" =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "鍵ファイルのパスに使えない文字があります" >&2; exit 2; }

remote="${BGA_REMOTE_DIR%/}"
dest=tools/bga
mkdir -p "$dest"

lftp -c "
  set cmd:fail-exit yes
  set net:max-retries 3
  set net:timeout 30
  set sftp:auto-confirm no
  set sftp:connect-program 'ssh -a -x -i $BGA_KEY_FILE -o IdentitiesOnly=yes -o UserKnownHostsFile=$BGA_KNOWN_HOSTS_FILE -o StrictHostKeyChecking=yes -o BatchMode=yes'
  open -p $BGA_SFTP_PORT -u $BGA_SFTP_USER,dummy sftp://$BGA_SFTP_HOST
  get -O $dest $remote/_ide_helper.php
  get -O $dest $remote/bga-framework.d.ts
" </dev/null

for f in _ide_helper.php bga-framework.d.ts; do
  [ -s "$dest/$f" ] || { echo "$dest/$f を取得できませんでした" >&2; exit 1; }
done
echo "スタブを $dest/ に取得しました"
