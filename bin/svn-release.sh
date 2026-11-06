#!/usr/bin/env bash
# Publish a release to the WordPress.org plugin repository (Subversion).
#
#   bin/svn-release.sh [--zip <file>] [--no-commit] [--yes]
#
# What it does, in a throw-away working copy:
#   1. checks out the plugin repository,
#   2. makes trunk/ an exact copy of the plugin folder inside the zip (the zip that was reviewed, not the git tree),
#   3. creates tags/<version>/ from trunk (and stops if that tag already exists: a tag is never overwritten),
#   4. puts the contents of .wordpress-org/ (icons, banners, screenshots) into assets/,
#   5. shows what will change and asks before it commits.
#
# Needs: svn, rsync, unzip. Your SVN password is asked by svn itself; it is never stored or written by this script.
# It is the SVN password from your WordPress.org profile (Account & Security), not the login password.
#
# Settings through the environment: SVN_URL, SVN_USER.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="estasoft-migration-database-sanitizer"
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/$SLUG}"
SVN_USER="${SVN_USER:-estasoft}"

zip_file=""
commit=1
assume_yes=0
while [ $# -gt 0 ]; do
  case "$1" in
    --zip)       zip_file="${2:-}"; shift 2 ;;
    --no-commit) commit=0; shift ;;
    --yes)       assume_yes=1; shift ;;
    -h|--help)   sed -n '2,16p' "$0"; exit 0 ;;
    *)           echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

for tool in svn rsync unzip; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Missing tool: $tool" >&2; exit 1; }
done

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN_DIR/estasoft-migrate.php" | head -1 | tr -d '\r')"
[ -n "$header_version" ] || { echo "Could not read the version from estasoft-migrate.php" >&2; exit 1; }
[ -n "$zip_file" ] || zip_file="$PLUGIN_DIR/dist/$SLUG-$header_version.zip"
[ -f "$zip_file" ] || { echo "No zip at $zip_file. Build it first: bin/build-zip.sh" >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/unzipped" "$work/wc"
unzip -q "$zip_file" -d "$work/unzipped"

tops=("$work"/unzipped/*/)
[ "${#tops[@]}" -eq 1 ] || { echo "The zip must hold exactly one top-level folder." >&2; exit 1; }
src="${tops[0]%/}"
[ "$(basename "$src")" = "$SLUG" ] || { echo "The folder in the zip is '$(basename "$src")', expected '$SLUG'." >&2; exit 1; }

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$src/estasoft-migrate.php" | head -1 | tr -d '\r')"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$src/readme.txt" | head -1 | tr -d '\r')"
[ -n "$version" ] && [ "$version" = "$stable" ] || { echo "Plugin header version ('$version') and readme Stable tag ('$stable') differ." >&2; exit 1; }

echo "Plugin $SLUG $version from $(basename "$zip_file")"
echo "Repository $SVN_URL as $SVN_USER"

svn checkout --quiet --username "$SVN_USER" "$SVN_URL" "$work/wc"
cd "$work/wc"

for d in trunk tags assets; do
  [ -d "$d" ] || { svn mkdir --quiet "$d"; }
done

if [ -e "tags/$version" ]; then
  echo "tags/$version already exists. A tag is never changed: raise the version and build a new zip." >&2
  exit 1
fi

# 2. trunk = the plugin folder of the zip.
rsync -a --delete --exclude='.svn' "$src/" trunk/
svn add --force --quiet trunk
svn status trunk | sed -n 's/^![[:space:]]*//p' | while IFS= read -r gone; do svn rm --quiet "$gone"; done

# 3. tag.
svn copy --quiet trunk "tags/$version"

# 4. store assets.
if [ -d "$PLUGIN_DIR/.wordpress-org" ]; then
  rsync -a --delete --exclude='.svn' "$PLUGIN_DIR/.wordpress-org/" assets/
  svn add --force --quiet assets
  svn status assets | sed -n 's/^![[:space:]]*//p' | while IFS= read -r gone; do svn rm --quiet "$gone"; done
  for f in assets/*.png; do [ -e "$f" ] && svn propset --quiet svn:mime-type image/png "$f"; done
  for f in assets/*.svg; do [ -e "$f" ] && svn propset --quiet svn:mime-type image/svg+xml "$f"; done
fi

echo
echo "What will be committed:"
svn status | sed 's/^/  /' | awk 'NR<=40 { print } NR==41 { print "  ... (list shortened; the rest is the same kind of change)" }'
echo "  $(svn status | wc -l | tr -d ' ') changes in total"

if [ "$commit" -eq 0 ]; then
  echo "Stopped before the commit (--no-commit)."
  exit 0
fi

if [ "$assume_yes" -ne 1 ]; then
  printf 'Commit release %s now? [y/N] ' "$version"
  read -r answer
  [ "$answer" = "y" ] || [ "$answer" = "Y" ] || { echo "Nothing was committed."; exit 0; }
fi

svn commit --username "$SVN_USER" -m "Release $version"
echo "Done. The plugin page is https://wordpress.org/plugins/$SLUG/ (it can take a few minutes to appear)."
