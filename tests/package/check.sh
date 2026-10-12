#!/usr/bin/env bash
#
# The release package, checked as a site owner gets it (since 0.2.0):
#
#   1. builds it as the release does (build.sh), and unpacks the zip into an empty
#      folder: the "clean host";
#   2. checks its contents (contents.php): what a site needs is there; no tests, local
#      settings, uploads, backups, development packages or version control; only
#      MIT-compatible licences; LF line endings; the PHP files' syntax;
#   3. installs it from the browser (install_flow.py) on an empty database, as one
#      does without a command line: first without an install key, then with one;
#   4. runs the browser tests (tests/browser/run.sh --site) on another copy of it.
#
#   tests/package/check.sh [--offline] [--keep]
#
# The database: the CAMPANELLA_DB_* variables; the tables get the prefix pkg_ (and
# brw_ for the browser tests), dropped before and after. Needs what the browser tests
# need (Python with Playwright), and zip and unzip. --offline: see build.sh. --keep:
# the temporary folder is kept.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BUILD_ARGS=()
KEEP=0
for arg in "$@"; do
    case "$arg" in
        --offline) BUILD_ARGS+=(--offline) ;;
        --keep) KEEP=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

PORT="${CAMPANELLA_TEST_PORT:-8098}"
WORK="$(mktemp -d)"
SERVER=""

drop_tables() {   # prefix
    CAMPANELLA_DB_PREFIX="$1" php -r '
        $host = getenv("CAMPANELLA_DB_HOST") ?: "localhost";
        $port = (int) (getenv("CAMPANELLA_DB_PORT") ?: 3306);
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=" . (getenv("CAMPANELLA_DB_NAME") ?: "campanella"),
            getenv("CAMPANELLA_DB_USER") ?: "campanella", getenv("CAMPANELLA_DB_PASSWORD") ?: "");
        $prefix = getenv("CAMPANELLA_DB_PREFIX");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($pdo->query("SHOW TABLES LIKE " . $pdo->quote(addcslashes($prefix, "_%\\") . "%"))->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE `$t`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    '
}
stop_server() {
    if [ -n "$SERVER" ]; then kill "$SERVER" 2>/dev/null || true; wait "$SERVER" 2>/dev/null || true; SERVER=""; fi
}
cleanup() {
    local status=$?
    stop_server
    if [ "$status" != 0 ] && [ -s "$WORK/server.log" ]; then
        echo "== The web server's log (the end)"
        tail -n 30 "$WORK/server.log"
    fi
    drop_tables pkg_ || true
    if [ "$KEEP" = 1 ]; then echo "Kept: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT
start_server() {
    (cd "$HOST" && exec php -S "127.0.0.1:$PORT" -t public public/index.php >>"$WORK/server.log" 2>&1) &
    SERVER=$!
    for _ in $(seq 1 50); do
        php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$PORT" && return 0
        sleep 0.2
    done
    echo "The web server did not start" >&2
    return 1
}

echo "== 1. The package, unpacked on a clean host"
DIR=$(bash "$ROOT/tests/package/build.sh" "$WORK/build" "${BUILD_ARGS[@]}" | tail -n 1)
NAME=$(basename "$DIR")
(cd "$WORK/build" && sha256sum -c "$NAME.zip.sha256" >/dev/null)
mkdir -p "$WORK/host"
unzip -q "$WORK/build/$NAME.zip" -d "$WORK/host"
HOST="$WORK/host/$NAME"
ls -l "$WORK/build/$NAME.zip" | awk '{print "   " $5 " bytes: " $9}'

echo "== 2. Its contents"
php "$ROOT/tests/package/contents.php" "$HOST"

echo "== 3. Installing it from the browser"
drop_tables pkg_
KEY=$(php -r 'echo bin2hex(random_bytes(16));')
# What a site owner writes into config/local.php: here the database comes from the
# CAMPANELLA_DB_* variables, with its own prefix.
cat > "$HOST/config/local.php" <<'PHP'
<?php
return ['debug' => false];
PHP
export CAMPANELLA_DB_PREFIX=pkg_
start_server
CAMPANELLA_TEST_URL="http://127.0.0.1:$PORT" CAMPANELLA_TEST_STEP=nokey python3 "$ROOT/tests/package/install_flow.py"
stop_server
cat > "$HOST/config/local.php" <<PHP
<?php
return ['debug' => false, 'install' => ['key' => '$KEY']];
PHP
start_server
CAMPANELLA_TEST_URL="http://127.0.0.1:$PORT" CAMPANELLA_TEST_INSTALL_KEY="$KEY" CAMPANELLA_TEST_STEP=install python3 "$ROOT/tests/package/install_flow.py"
stop_server
(cd "$HOST" && php bin/campanella status >/dev/null) || { echo "php bin/campanella status reports an error on the fresh installation" >&2; exit 1; }
unset CAMPANELLA_DB_PREFIX

echo "== 4. The browser tests, on the package"
rm -f "$HOST/config/local.php"
bash "$ROOT/tests/browser/run.sh" --site "$HOST"
