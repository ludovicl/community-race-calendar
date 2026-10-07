#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(awk '/^ \* Version:/ { print $3; exit }' "$repo_dir/plugin/community-race-calendar/community-race-calendar.php")"
artifact_dir="$repo_dir/build"
artifact="$artifact_dir/community-race-calendar-$version.zip"

cd "$repo_dir/frontend"
npm ci
npm run check

mkdir -p "$artifact_dir"
cd "$repo_dir/plugin"
zip -FSqr "$artifact" community-race-calendar -x '*.DS_Store' '*/tests/*' '*/fixtures/*' '*.env' '*.env.*'

echo "$artifact"
