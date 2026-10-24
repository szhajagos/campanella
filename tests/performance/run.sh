#!/usr/bin/env bash
#
# The performance check (since 0.3.0): a site with many objects (10 000 by default),
# and how fast its busiest pages answer with them.
#
#   1. installs this code into a temporary folder, with its sample content (seed);
#   2. adds a content model and the objects (fixture.php);
#   3. requests the pages several times each and checks them against the limits
#      (measure.php): the time (the median) and the number of SQL queries.
#
#   tests/performance/run.sh [--objects N] [--offline] [--keep]
#
# The database: the CAMPANELLA_DB_* environment variables (as for the tests); the
# tables get the prefix CAMPANELLA_DB_PREFIX (default perf_), dropped before and
# after. --offline: the dependencies are copied from this folder's vendor/ instead of
# `composer install --no-dev` (no network). --keep: the temporary folder and the
# tables are kept, e.g. to run measure.php again by hand:
#
#   php tests/performance/measure.php <site folder>
#
# The limits hold for 10 000 objects; with --objects the times are only shown.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OBJECTS=10000
OFFLINE=0
KEEP=0
while [ $# -gt 0 ]; do
    case "$1" in
        --objects) OBJECTS="${2:-}"; shift ;;
        --offline) OFFLINE=1 ;;
        --keep) KEEP=1 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done
if ! [[ "$OBJECTS" =~ ^[1-9][0-9]*$ ]]; then
    echo "Usage: tests/performance/run.sh [--objects N] [--offline] [--keep]" >&2
    exit 2
fi

export CAMPANELLA_DB_PREFIX="${CAMPANELLA_DB_PREFIX:-perf_}"
export CAMPANELLA_LOCALE=hu
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
        echo "Kept: $SITE, and the tables $CAMPANELLA_DB_PREFIX* (dropped by the next run)"
    else
        drop_tables || true
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

echo "== 1. The site, in $SITE"
drop_tables
mkdir -p "$SITE"
(cd "$ROOT" && git ls-files -co --exclude-standard -z \
    | while IFS= read -r -d '' f; do
        case "$f" in config/local.php|vendor/*|var/*) continue ;; esac
        if [ -f "$f" ]; then printf '%s\0' "$f"; fi
    done \
    | tar --null -T - -cf -) | tar -C "$SITE" -xf -
if [ "$OFFLINE" = 1 ]; then
    cp -a "$ROOT/vendor" "$SITE/vendor"
else
    (cd "$SITE" && composer install --no-dev --no-interaction --no-progress --quiet)
fi
cat > "$SITE/config/local.php" <<'PHP'
<?php
// The performance check's site: the database comes from the CAMPANELLA_DB_* variables.
return ['debug' => false];
PHP
(cd "$SITE" && php bin/campanella install --yes >/dev/null && php bin/campanella seed >/dev/null)

echo "== 2. The content model and $OBJECTS objects"
start=$(date +%s)
php "$ROOT/tests/performance/fixture.php" "$SITE" "$OBJECTS"
echo "  in $(( $(date +%s) - start )) s"

echo "== 3. The pages"
if [ "$OBJECTS" = 10000 ]; then
    php "$ROOT/tests/performance/measure.php" "$SITE"
else
    php "$ROOT/tests/performance/measure.php" "$SITE" --no-limits
fi
