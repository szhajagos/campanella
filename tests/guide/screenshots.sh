#!/usr/bin/env bash
#
# The site owners' guide's screenshots (since 0.2.1): docs/guide/images/<language>/.
#
#   tests/guide/screenshots.sh [hu|en …]
#
# For each language (both by default) a fresh site is made from this folder's files,
# with that language's interface, and installed from the browser as the guide tells;
# then shots.py goes through the pages the guide shows and saves a PNG of each. Run it
# again after a change to the admin's look, and commit the images that changed.
#
# The database: the CAMPANELLA_DB_* variables; the tables get the prefix gde_ (dropped
# before and after). Needs Python with Playwright, as the browser tests.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
LANGS=("$@")
[ ${#LANGS[@]} -eq 0 ] && LANGS=(hu en)

PORT="${CAMPANELLA_TEST_PORT:-8097}"
SMTP_PORT="${CAMPANELLA_TEST_SMTP_PORT:-2597}"
export CAMPANELLA_DB_PREFIX=gde_
export CAMPANELLA_MAIL_DSN="smtp://127.0.0.1:$SMTP_PORT"
export CAMPANELLA_MAIL_FROM="noreply@pelda.example"
unset CAMPANELLA_DEBUG CAMPANELLA_SITE_URL
WORK="$(mktemp -d)"
SERVER=""
CATCHER=""

drop_tables() {
    php -r '
        $pdo = new PDO("mysql:host=" . (getenv("CAMPANELLA_DB_HOST") ?: "localhost") . ";port=" . (getenv("CAMPANELLA_DB_PORT") ?: 3306) . ";dbname=" . (getenv("CAMPANELLA_DB_NAME") ?: "campanella"),
            getenv("CAMPANELLA_DB_USER") ?: "campanella", getenv("CAMPANELLA_DB_PASSWORD") ?: "");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($pdo->query("SHOW TABLES LIKE " . $pdo->quote("gde\\_%"))->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE `$t`");
        }
    '
}
stop() {
    for pid in $SERVER $CATCHER; do kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; done
    SERVER=""
    CATCHER=""
}
cleanup() {
    stop
    drop_tables || true
    rm -rf "$WORK"
}
trap cleanup EXIT
wait_for_port() {
    for _ in $(seq 1 50); do
        php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$1" && return 0
        sleep 0.2
    done
    echo "Nothing listens on port $1" >&2
    return 1
}

for lang in "${LANGS[@]}"; do
    echo "== $lang"
    SITE="$WORK/$lang/site"
    MAILS="$WORK/$lang/mails"
    mkdir -p "$SITE" "$MAILS" "$ROOT/docs/guide/images/$lang"
    (cd "$ROOT" && git ls-files -co --exclude-standard -z \
        | while IFS= read -r -d '' f; do [ -f "$f" ] && [ "$f" != config/local.php ] && printf '%s\0' "$f"; done \
        | tar --null -T - -cf -) | tar -C "$SITE" -xf -
    cp -a "$ROOT/vendor" "$SITE/vendor"
    drop_tables
    KEY=$(php -r 'echo bin2hex(random_bytes(16));')
    # First without an install key (the page that says how to set one).
    printf '%s\n' '<?php' "return ['debug' => false];" > "$SITE/config/local.php"

    python3 -I "$ROOT/tests/browser/mailcatcher.py" "$SMTP_PORT" "$MAILS" &
    CATCHER=$!
    (cd "$SITE" && CAMPANELLA_LOCALE="$lang" exec php -S "127.0.0.1:$PORT" -t public public/index.php >"$WORK/$lang/server.log" 2>&1) &
    SERVER=$!
    wait_for_port "$SMTP_PORT"
    wait_for_port "$PORT"

    CAMPANELLA_GUIDE_URL="http://127.0.0.1:$PORT" CAMPANELLA_GUIDE_LANG="$lang" CAMPANELLA_GUIDE_SITE="$SITE" \
    CAMPANELLA_GUIDE_KEY="$KEY" CAMPANELLA_GUIDE_MAILS="$MAILS" CAMPANELLA_GUIDE_OUT="$ROOT/docs/guide/images/$lang" \
        python3 "$ROOT/tests/guide/shots.py"
    stop
done
echo "Saved in docs/guide/images/."
