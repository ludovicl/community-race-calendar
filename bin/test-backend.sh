#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"

docker compose run --rm --no-deps -T --entrypoint sh cli -c \
  "find /var/www/html/wp-content/plugins/community-race-calendar -name '*.php' -exec php -l '{}' ';'"

docker compose run --rm -T cli wp eval-file /workspace/tests/integration/mvp.php
