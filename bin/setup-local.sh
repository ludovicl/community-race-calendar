#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"

docker compose up -d db wordpress

until curl --silent --fail http://localhost:8087/wp-login.php >/dev/null; do
  sleep 2
done

if ! docker compose run --rm cli wp core is-installed >/dev/null 2>&1; then
  docker compose run --rm cli wp core install \
    --url=http://localhost:8087 \
    --title="Community Race Calendar local" \
    --admin_user=crcal_admin \
    --admin_password=local-admin-pass \
    --admin_email=admin@example.test \
    --skip-email
fi

docker compose run --rm cli wp plugin activate community-race-calendar
docker compose run --rm cli wp language core install fr_FR --activate >/dev/null
docker compose run --rm cli wp rewrite structure '/%postname%/' --hard >/dev/null
docker compose run --rm cli wp rewrite flush --hard >/dev/null

for account in "member_one:member-one@example.test:local-member-one-pass" "member_two:member-two@example.test:local-member-two-pass" "inactive_member:inactive@example.test:local-inactive-pass"; do
  IFS=: read -r login email password <<< "$account"
  if ! docker compose run --rm cli wp user get "$login" --field=ID >/dev/null 2>&1; then
    docker compose run --rm cli wp user create "$login" "$email" --role=subscriber --user_pass="$password" --display_name="${login//_/ }" >/dev/null
  fi
done

for login in crcal_admin member_one member_two; do
  docker compose run --rm cli wp user meta update "$login" crcal_test_active_membership 1 >/dev/null
done
docker compose run --rm cli wp user meta update inactive_member crcal_test_active_membership 0 >/dev/null

page_id="$(docker compose run --rm cli wp post list --post_type=page --name=calendrier --field=ID --format=ids | tr -d '\r')"
if [[ -z "$page_id" ]]; then
  page_id="$(docker compose run --rm cli wp post create --post_type=page --post_status=publish --post_title='Calendrier' --post_name=calendrier --post_content='[community_race_calendar]' --porcelain | tr -d '\r')"
fi
docker compose run --rm cli wp option patch update community_race_calendar_settings calendar_page_id "$page_id" >/dev/null

echo "WordPress local prêt : http://localhost:8087/calendrier/"
