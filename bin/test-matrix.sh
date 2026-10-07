#!/usr/bin/env bash
# Runs the backend integration suite (and optionally the browser smoke test) on one PHP and WordPress
# combination, using official Docker images, then removes the containers and volumes.
# Usage: bin/test-matrix.sh <label> <wordpress-image> <wp-cli-image> <port> [smoke]
set -euo pipefail

label="$1"; export CRCAL_WP_IMAGE="$2"; export CRCAL_CLI_IMAGE="$3"; export CRCAL_PORT="$4"; smoke="${5:-}"
repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"
compose=(docker compose --project-name "crcmatrix-$label" -f compose.matrix.yaml)
cleanup() { "${compose[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

"${compose[@]}" up -d db wordpress >/dev/null 2>&1
until curl --silent --fail "http://localhost:$CRCAL_PORT/wp-login.php" >/dev/null 2>&1 || curl --silent -o /dev/null -w '%{http_code}' "http://localhost:$CRCAL_PORT/" | grep -q '^30'; do sleep 2; done
cli() { "${compose[@]}" run --rm -T cli "$@" 2>/dev/null; }

cli wp core install --url="http://localhost:$CRCAL_PORT" --title="CRC matrix" --admin_user=crcal_admin \
  --admin_password=local-admin-pass --admin_email=admin@example.test --skip-email >/dev/null
echo "wordpress=$(cli wp core version | tail -1) php_cli=$(cli php -r 'echo PHP_VERSION;' | tail -1)"
cli wp plugin activate community-race-calendar >/dev/null
cli wp rewrite structure '/%postname%/' --hard >/dev/null
for account in "member_one:member-one@example.test:local-member-one-pass" "member_two:member-two@example.test:local-member-two-pass" "inactive_member:inactive@example.test:local-inactive-pass"; do
  IFS=: read -r login email password <<< "$account"
  cli wp user create "$login" "$email" --role=subscriber --user_pass="$password" --display_name="${login//_/ }" >/dev/null
done
for login in crcal_admin member_one member_two; do cli wp user meta update "$login" crcal_test_active_membership 1 >/dev/null; done
cli wp user meta update inactive_member crcal_test_active_membership 0 >/dev/null
page_id="$(cli wp post create --post_type=page --post_status=publish --post_title='Calendrier' --post_name=calendrier --post_content='[community_race_calendar]' --porcelain | tail -1 | tr -d '\r')"
cli wp option patch update community_race_calendar_settings calendar_page_id "$page_id" >/dev/null

find_php() { "${compose[@]}" run --rm --no-deps -T --entrypoint sh cli -c "find /var/www/html/wp-content/plugins/community-race-calendar -name '*.php' -exec php -l '{}' ';'" 2>/dev/null | grep -vc 'No syntax errors' || true; }
echo "php_lint_errors=$(find_php)"
"${compose[@]}" run --rm -T cli wp eval-file /workspace/tests/integration/mvp.php 2>&1 | grep -E "MVP backend|ÉCHEC|Fatal" | head -3

if [[ "$smoke" == "smoke" ]]; then
  # A fresh installation starts with the map disabled; then the setting is switched on.
  CRCAL_BASE_URL="http://localhost:$CRCAL_PORT" node tests/browser/smoke.mjs disabled 2>&1 | grep -E "FAIL|passed|React comes"
  cli wp option patch update community_race_calendar_settings map_enabled true >/dev/null
  CRCAL_BASE_URL="http://localhost:$CRCAL_PORT" node tests/browser/smoke.mjs enabled 2>&1 | grep -E "FAIL|passed"
fi
