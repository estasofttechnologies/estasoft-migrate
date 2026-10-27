#!/usr/bin/env bash
# Build the zip that people install from Plugins > Add New > Upload Plugin (or that goes to wordpress.org).
#
#   bin/build-zip.sh            -> dist/estasoft-migrate-<version>.zip
#
# Only what runs on a site goes in: no tests, docs, tooling or dev scripts. The folder inside the zip is
# always "estasoft-migrate", so WordPress replaces an installed copy on upgrade.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n "s/^ \* Version:[[:space:]]*//p" estasoft-migrate.php | head -1 | tr -d '\r')"
CONST="$(sed -n "s/.*define( 'ESTASOFT_MIGRATE_VERSION', '\([^']*\)'.*/\1/p" estasoft-migrate.php | head -1)"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | tr -d '\r')"

if [ -z "$VERSION" ] || [ "$VERSION" != "$CONST" ] || [ "$VERSION" != "$STABLE" ]; then
  echo "The version must be the same in the plugin header ($VERSION), ESTASOFT_MIGRATE_VERSION ($CONST) and readme.txt Stable tag ($STABLE)." >&2
  exit 1
fi

for f in estasoft-migrate.php uninstall.php readme.txt includes assets views; do
  [ -e "$f" ] || { echo "Missing $f" >&2; exit 1; }
done

OUT="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/estasoft-migrate" "$OUT"

cp estasoft-migrate.php uninstall.php readme.txt "$STAGE/estasoft-migrate/"
cp -R includes assets views "$STAGE/estasoft-migrate/"
mkdir -p "$STAGE/estasoft-migrate/languages"
[ -d languages ] && cp -R languages/. "$STAGE/estasoft-migrate/languages/"
[ -f LICENSE ] && cp LICENSE "$STAGE/estasoft-migrate/license.txt"

# Nothing else that does not belong on a live site.
find "$STAGE" \( -name '.DS_Store' -o -name '*.log' -o -name '.gitkeep' -o -name '*.map' \) -delete

# Every PHP file must at least parse on this PHP.
while IFS= read -r -d '' f; do php -l "$f" > /dev/null; done < <(find "$STAGE" -name '*.php' -print0)
while IFS= read -r -d '' f; do node --check "$f" 2>/dev/null || { echo "JavaScript does not parse: $f" >&2; exit 1; }; done < <(find "$STAGE" -name '*.js' -print0 2>/dev/null) || true

ZIP="$OUT/estasoft-migrate-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" estasoft-migrate)

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1), $(unzip -Z1 "$ZIP" | grep -vc '/$') files)"
