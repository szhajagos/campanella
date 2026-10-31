#!/usr/bin/env bash
#
# The upgrade test (since 0.2.0): a real upgrade, played out in a temporary folder.
#
#   1. installs the old version (e.g. 0.1.0) from its source folder, with its sample
#      content, users of every kind and an image shown in an article
#      (old-fixture.php), and takes a snapshot of every table;
#   2. copies the current code over it, as one uploads a new version (config/local.php,
#      public/media and var/ stay), and runs `php bin/campanella install --yes`;
#   3. checks that nothing was lost and the site works as the new version (verify.php).
#
#   tests/upgrade/run.sh <old source folder> [--offline] [--keep]
#
# The old source: e.g. `git worktree add /tmp/campanella-0.1.0 v0.1.0`, or a clone of
# the tag. The database: the CAMPANELLA_DB_* environment variables (as for the
# tests); the tables get the prefix CAMPANELLA_DB_PREFIX (default upg_), dropped
# before and after. --offline: the dependencies are copied from this folder's vendor/
# instead of `composer install` (no network). --keep: the temporary folder and the
# tables are kept, e.g. to run verify.php again by hand.

set -euo pipefail

NEW="$(cd "$(dirname "$0")/../.." && pwd)"
OLD_SRC="${1:-}"
shift || true
OFFLINE=0
KEEP=0
LOCALE=hu
for arg in "$@"; do
    case "$arg" in
        --offline) OFFLINE=1 ;;
        --keep) KEEP=1 ;;
        --locale=hu|--locale=en) LOCALE="${arg#--locale=}" ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done
if [ -z "$OLD_SRC" ] || [ ! -f "$OLD_SRC/bin/campanella" ]; then
    echo "Usage: tests/upgrade/run.sh <old source folder> [--offline] [--keep] [--locale=hu|en]" >&2
    exit 2
fi

export CAMPANELLA_DB_PREFIX="${CAMPANELLA_DB_PREFIX:-upg_}"
# The old site's language (since 0.4.0: --locale=en, an English site of 0.3.7 with its own paths).
export CAMPANELLA_LOCALE="$LOCALE"
WORK="$(mktemp -d)"
SITE="$WORK/site"

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
    if [ "$KEEP" = 1 ]; then
        echo "Kept: $WORK, and the tables $CAMPANELLA_DB_PREFIX* (dropped by the next run)"
    else
        drop_tables || true
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

dependencies() {
    if [ "$OFFLINE" = 1 ]; then
        rm -rf "$SITE/vendor"
        cp -a "$NEW/vendor" "$SITE/vendor"
    else
        (cd "$SITE" && composer install --no-dev --no-interaction --no-progress --quiet)
    fi
}

echo "== 1. The old version, from $OLD_SRC"
drop_tables
mkdir -p "$SITE"
(cd "$OLD_SRC" && tar --exclude=./.git --exclude=./vendor --exclude=./var --exclude=./config/local.php -cf - .) | tar -C "$SITE" -xf -
dependencies
cat > "$SITE/config/local.php" <<'PHP'
<?php
// The upgrade test's site: the database comes from the CAMPANELLA_DB_* variables.
return ['debug' => false];
PHP
(cd "$SITE" && php bin/campanella install >/dev/null && php bin/campanella seed >/dev/null)
php "$NEW/tests/upgrade/old-fixture.php" "$SITE" "$WORK/snapshot.json"

echo "== 2. The upgrade: the new files over the old ones, then install --yes"
(cd "$NEW" && git ls-files -co --exclude-standard -z \
    | while IFS= read -r -d '' f; do [ -f "$f" ] && [ "$f" != config/local.php ] && printf '%s\0' "$f"; done \
    | tar --null -T - -cf -) | tar -C "$SITE" -xf -
dependencies
(cd "$SITE" && php bin/campanella install --yes)
(cd "$SITE" && php bin/campanella schema:check)

echo "== 3. Checks"
php "$NEW/tests/upgrade/verify.php" "$SITE" "$WORK/snapshot.json"
