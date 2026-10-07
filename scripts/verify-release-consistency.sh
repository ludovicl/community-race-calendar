#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_main="$repo_dir/plugin/community-race-calendar/community-race-calendar.php"

fail() {
  echo "Gate release : $1" >&2
  exit 1
}

canonical_version="$(awk -F ': *' '/^[[:space:]]*\*[[:space:]]*Version:/ { print $2; exit }' "$plugin_main" | tr -d '\r')"
[[ "$canonical_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "version canonique invalide dans l’en-tête du plugin."

constant_version="$(sed -n "s/^define('CRCAL_VERSION', '\([^']*\)');/\1/p" "$plugin_main")"
stable_tag="$(awk -F ': *' '/^Stable tag:/ { print $2; exit }' "$repo_dir/plugin/community-race-calendar/readme.txt" | tr -d '\r')"
package_version="$(node -p "require('$repo_dir/frontend/package.json').version")"
lock_version="$(node -p "require('$repo_dir/frontend/package-lock.json').packages[''].version")"

for entry in \
  "CRCAL_VERSION:$constant_version" \
  "Stable tag:$stable_tag" \
  "frontend/package.json:$package_version" \
  "frontend/package-lock.json:$lock_version"
do
  label="${entry%%:*}"
  version="${entry#*:}"
  [[ "$version" == "$canonical_version" ]] || fail "$label=$version, attendu $canonical_version."
done

echo "Versions cohérentes : $canonical_version."
