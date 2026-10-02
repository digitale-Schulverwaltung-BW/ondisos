#!/usr/bin/env bash
# Builds the installable plugin ZIP (Layout C, self-contained) and its SHA-256 checksum.
#
#   wordpress-plugin/build-zip.sh [output-dir]      (default: dist/)
#
# Result: <output-dir>/ondisos-<version>.zip and ondisos-<version>.zip.sha256
# The ZIP unpacks to a folder "ondisos" (the plugin slug), so WordPress overwrites the same directory on updates.
#
# Only files tracked by git go in (current working-tree content): local files such as .env, messages.local.php
# or caches can never end up in the ZIP. The ZIP is generic: no tenant data, no secrets.
set -euo pipefail

SLUG="ondisos"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
cd "$ROOT"

# Version: plugin header and constant must agree
HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' wordpress-plugin/ondisos.php | head -1 | tr -d '[:space:]')"
CONST_VERSION="$(sed -n "s/^define('ONDISOS_PLUGIN_VERSION', '\(.*\)');/\1/p" wordpress-plugin/ondisos.php | head -1)"
if [ -z "$HEADER_VERSION" ] || [ "$HEADER_VERSION" != "$CONST_VERSION" ]; then
    echo "Version mismatch: header '$HEADER_VERSION' vs ONDISOS_PLUGIN_VERSION '$CONST_VERSION'" >&2
    exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

# Plugin itself (without dev helpers and the git-layout symlink)
git ls-files -z -- wordpress-plugin \
    | grep -zv -e '^wordpress-plugin/SYMLINK-SETUP.sh$' -e '^wordpress-plugin/build-zip.sh$' -e '^wordpress-plugin/frontend-assets$' -e '^wordpress-plugin/README.md$' \
    | while IFS= read -r -d '' f; do
        mkdir -p "$DEST/$(dirname "${f#wordpress-plugin/}")"
        cp -p "$f" "$DEST/${f#wordpress-plugin/}"
    done

# Frontend parts the plugin needs (Layout C): PHP classes, messages, SurveyJS assets, shared JS.
# Not needed: surveys (come from the backend), index.php and the standalone scripts.
git ls-files -z -- frontend/src frontend/config/messages.php frontend/config/messages.example.php frontend/public/assets frontend/public/js/survey-handler-base.js \
    | while IFS= read -r -d '' f; do
        mkdir -p "$DEST/$(dirname "$f")"
        cp -p "$f" "$DEST/$f"
    done

[ -f LICENSE ] && cp LICENSE "$DEST/LICENSE"

# Sanity: the bundled layout must be complete
for need in ondisos.php frontend/src/Services/BackendApiClient.php frontend/config/messages.php frontend/public/assets/survey.core.min.js frontend/public/js/survey-handler-base.js; do
    [ -f "$DEST/$need" ] || { echo "Missing in ZIP: $need" >&2; exit 1; }
done
if find "$DEST" \( -name '.env' -o -name '*.local.php' -o -name '.git*' -o -name 'tests' \) | grep -q .; then
    echo "Unexpected local/dev files in ZIP staging" >&2
    exit 1
fi

mkdir -p "$OUT"
ZIP="$OUT/$SLUG-$HEADER_VERSION.zip"
rm -f "$ZIP"
# Stable order and timestamps so the same sources give the same ZIP
find "$DEST" -exec touch -t 202001010000 {} +
(cd "$STAGE" && find "$SLUG" -type f | LC_ALL=C sort | zip -X -q "$ZIP" -@)
(cd "$OUT" && { shasum -a 256 "$(basename "$ZIP")" 2>/dev/null || sha256sum "$(basename "$ZIP")"; } > "$(basename "$ZIP").sha256")

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
cat "$ZIP.sha256"
