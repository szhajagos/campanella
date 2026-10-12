#!/usr/bin/env bash
#
# Builds the installation package (since 0.2.0), as the release does: the files of the
# repository without those marked `export-ignore` in .gitattributes (the tests, the CI
# settings, PHPStan's), plus the vendor/ folder without the development packages.
#
#   tests/package/build.sh <output folder> [--offline]
#
# Makes <output folder>/campanella-<version>/, campanella-<version>.zip and its
# .sha256. The files are those of the working tree that git tracks or would track
# (in CI: the commit's), so it can be checked before a commit too. --offline: vendor/
# is copied from this folder (without version-control folders), and Composer only
# removes the development packages (no network needed).

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-}"
shift || true
OFFLINE=0
for arg in "$@"; do
    case "$arg" in
        --offline) OFFLINE=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done
if [ -z "$OUT" ]; then
    echo "Usage: tests/package/build.sh <output folder> [--offline]" >&2
    exit 2
fi
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

VERSION=$(php -r 'require $argv[1]; echo Campanella\Core\Version::CAMPANELLA;' "$ROOT/src/Core/Version.php")
NAME="campanella-$VERSION"
DIR="$OUT/$NAME"
rm -rf "$DIR" "$OUT/$NAME.zip" "$OUT/$NAME.zip.sha256"
mkdir -p "$DIR"

# The files: those git tracks or would track (a temporary index, so the working tree
# counts, uncommitted changes and new files too), archived as the release does:
# `git archive` leaves out what .gitattributes marks export-ignore.
INDEX="$(mktemp -d)/index"
TREE=$(cd "$ROOT" && GIT_INDEX_FILE="$INDEX" git add -A . && GIT_INDEX_FILE="$INDEX" git write-tree)
rm -rf "$(dirname "$INDEX")"
(cd "$ROOT" && git archive --format=tar "$TREE") | tar -C "$DIR" -xf -

if [ "$OFFLINE" = 1 ]; then
    mkdir -p "$DIR/vendor"
    (cd "$ROOT/vendor" && tar --exclude-vcs -cf - .) | tar -C "$DIR/vendor" -xf -
fi
composer install --working-dir="$DIR" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet

(cd "$OUT" && rm -f "$NAME.zip" && zip -qrX "$NAME.zip" "$NAME" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")
echo "$DIR"
