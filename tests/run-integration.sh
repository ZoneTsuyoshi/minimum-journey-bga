#!/usr/bin/env sh
# MariaDB と PHP 8.4 の Docker コンテナを立てて、結合テスト（PHPUnit）を実行する。
# 実行: npm run test:integration（または sh tests/run-integration.sh）。Docker だけが必要。
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SUFFIX="$$"
NETWORK="mj-test-net-$SUFFIX"
DB="mj-test-db-$SUFFIX"

cleanup() {
  docker rm -f "$DB" >/dev/null 2>&1
  docker network rm "$NETWORK" >/dev/null 2>&1
}
trap cleanup EXIT INT TERM

docker build -q -t mj-test-php "$ROOT/tests" >/dev/null || exit 1
docker network create "$NETWORK" >/dev/null || exit 1
docker run -d --name "$DB" --network "$NETWORK" \
  -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=minimumjourney_test \
  mariadb:11 >/dev/null || exit 1

echo "MariaDB の起動を待っています..."
i=0
until docker exec "$DB" mariadb -uroot -ptest -e 'SELECT 1' minimumjourney_test >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "MariaDB が起動しませんでした" >&2
    exit 1
  fi
  sleep 1
done

docker run --rm --network "$NETWORK" -v "$ROOT":/app -w /app \
  -e DB_HOST="$DB" -e DB_NAME=minimumjourney_test -e DB_USER=root -e DB_PASSWORD=test \
  mj-test-php sh -c 'composer install --no-interaction --no-progress --quiet && composer test:integration'
