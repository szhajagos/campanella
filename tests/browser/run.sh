#!/usr/bin/env bash
#
# The browser tests (since 0.2.0): what the 0.1.x series was checked by hand, played
# out in a real browser (Playwright, headless Chromium) against a fresh installation.
# They serve security first: a protection that silently stops working fails the build.
#
#   tests/browser/run.sh [--keep] [--headed] [--site <folder>]
#
# What it does:
#   1. copies this folder's files (as `git ls-files` lists them, with vendor/) to a
#      temporary site, with its own config/local.php (debug off, as a live site);
#   2. installs it on the CAMPANELLA_DB_* database with the prefix CAMPANELLA_DB_PREFIX
#      (default brw_; those tables are dropped before and after), with the sample
#      content and three users (an administrator, an editor, one for the lock-out);
#   3. starts a mail catcher (mailcatcher.py) and PHP's built-in web server, the mail
#      settings pointing at the catcher;
#   4. runs the tests (test_site.py).
#
# Needs: PHP, Composer's vendor/ in this folder, Python 3 with Playwright
# (`pip install -r tests/browser/requirements.txt`, then
# `python3 -m playwright install chromium`). Ports: CAMPANELLA_TEST_PORT (8099) and
# CAMPANELLA_TEST_SMTP_PORT (2599). CAMPANELLA_TEST_GDB=1 (with gdb installed, as on CI;
# since 0.3.2): the web server runs under gdb, so if PHP itself crashes, its backtrace
# is printed. --keep: the temporary site and the tables are kept (the site's folder is
# printed). --headed: the browser is shown. --site: the
# site is a copy of that folder (with its own vendor/), e.g. the unpacked release
# package (tests/package/check.sh), instead of this folder's files.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
KEEP=0
HEADED=0
SOURCE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --keep) KEEP=1 ;;
        --headed) HEADED=1 ;;
        --site) SOURCE="${2:-}"; shift
            if [ ! -f "$SOURCE/bin/campanella" ] || [ ! -f "$SOURCE/vendor/autoload.php" ]; then
                echo "--site: not a Campanella folder with vendor/: $SOURCE" >&2; exit 2
            fi ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

PORT="${CAMPANELLA_TEST_PORT:-8099}"
SMTP_PORT="${CAMPANELLA_TEST_SMTP_PORT:-2599}"
export CAMPANELLA_DB_PREFIX="${CAMPANELLA_DB_PREFIX:-brw_}"
export CAMPANELLA_LOCALE=hu
export CAMPANELLA_SITE_URL="http://127.0.0.1:$PORT"
export CAMPANELLA_MAIL_DSN="smtp://127.0.0.1:$SMTP_PORT"
export CAMPANELLA_MAIL_FROM="noreply@campanella.test"
unset CAMPANELLA_DEBUG

WORK="$(mktemp -d)"
SITE="$WORK/site"
MAILS="$WORK/mails"
PIDS=()

drop_tables() {
    php -r '
        $host = getenv("CAMPANELLA_DB_HOST") ?: "localhost";
        $port = (int) (getenv("CAMPANELLA_DB_PORT") ?: 3306);
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=" . (getenv("CAMPANELLA_DB_NAME") ?: "campanella"),
            getenv("CAMPANELLA_DB_USER") ?: "campanella", getenv("CAMPANELLA_DB_PASSWORD") ?: "");
        $prefix = getenv("CAMPANELLA_DB_PREFIX");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $tables = $pdo->query("SHOW TABLES LIKE " . $pdo->quote(addcslashes($prefix, "_%\\") . "%"))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) { $pdo->exec("DROP TABLE `$t`"); }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    '
}
cleanup() {
    local status=$?
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    if [ "$status" != 0 ] && [ -s "$WORK/server.log" ]; then
        if grep -q 'received signal SIG' "$WORK/server.log"; then
            echo "== The web server (PHP itself) crashed: the last requests, and the backtrace"
            line=$(grep -n 'received signal SIG' "$WORK/server.log" | head -n 1 | cut -d: -f1)
            sed -n "$(( line > 15 ? line - 15 : 1 )),$(( line + 70 ))p" "$WORK/server.log"
        else
            echo "== The web server's log (the end)"
            tail -n 40 "$WORK/server.log"
        fi
    fi
    if [ "$KEEP" = 1 ]; then
        echo "Kept: $SITE (server log: $WORK/server.log, mails: $MAILS), and the tables $CAMPANELLA_DB_PREFIX*"
    else
        drop_tables || true
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

wait_for_port() {
    for _ in $(seq 1 50); do
        if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$1"; then return 0; fi
        sleep 0.2
    done
    echo "Nothing listens on port $1" >&2
    return 1
}

echo "== The site, in $SITE"
drop_tables
mkdir -p "$SITE" "$MAILS"
if [ -n "$SOURCE" ]; then
    cp -a "$SOURCE/." "$SITE/"
    rm -f "$SITE/config/local.php"
else
    (cd "$ROOT" && git ls-files -co --exclude-standard -z \
        | while IFS= read -r -d '' f; do [ -f "$f" ] && [ "$f" != config/local.php ] && printf '%s\0' "$f"; done \
        | tar --null -T - -cf -) | tar -C "$SITE" -xf -
    cp -a "$ROOT/vendor" "$SITE/vendor"
fi
cat > "$SITE/config/local.php" <<'PHP'
<?php
// The browser tests' site: the database and the mail come from the CAMPANELLA_* variables.
// The tests stand behind 127.0.0.1 as a proxy: each browser context is another visitor,
// with an X-Forwarded-For address of its own (as the limits count by address).
return ['debug' => false, 'trusted_proxies' => ['127.0.0.1']];
PHP
(cd "$SITE" && php bin/campanella install >/dev/null && php bin/campanella seed >/dev/null)
create_user() {   # e-mail, password, role
    (cd "$SITE" && printf '%s\n' "$2" | php bin/campanella user:create "$1" --name="$4" --role="$3" >/dev/null)
}
create_user browser-admin@example.test browser-admin-jelszo-1 administrator "Böngésző Admin"
create_user browser-editor@example.test browser-editor-jelszo-1 editor "Böngésző Szerkesztő"
create_user browser-lock@example.test browser-lock-jelszo-1 editor "Kizárási Próba"

echo "== The mail catcher (port $SMTP_PORT) and the web server (port $PORT), $(php -r 'echo "PHP ", PHP_VERSION;')"
python3 -I "$ROOT/tests/browser/mailcatcher.py" "$SMTP_PORT" "$MAILS" &
PIDS+=($!)
# With CAMPANELLA_TEST_GDB=1 and gdb installed (as on CI), the web server runs under gdb,
# so a crash of PHP itself (a segmentation fault) leaves its backtrace in the server log.
if [ "${CAMPANELLA_TEST_GDB:-}" = 1 ] && command -v gdb >/dev/null 2>&1; then
    (cd "$SITE" && exec gdb -q -batch -ex 'handle SIGPIPE nostop noprint pass' -ex run -ex 'echo \n== PHP crashed: the backtrace\n' -ex 'bt 60' \
        --args php -S "127.0.0.1:$PORT" -t public public/index.php >"$WORK/server.log" 2>&1) &
else
    (cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t public public/index.php >"$WORK/server.log" 2>&1) &
fi
SERVER=$!
PIDS+=($SERVER)
wait_for_port "$SMTP_PORT"
wait_for_port "$PORT"

echo "== The tests"
CAMPANELLA_TEST_URL="http://127.0.0.1:$PORT" CAMPANELLA_TEST_MAILS="$MAILS" CAMPANELLA_TEST_HEADED="$HEADED" \
    python3 "$ROOT/tests/browser/test_site.py"
