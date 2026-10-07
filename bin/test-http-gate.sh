#!/usr/bin/env bash
set -euo pipefail

base_url="http://localhost:8087"
api_url="$base_url/wp-json/community-race-calendar/v1"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

login() {
  local login_name="$1"
  local password="$2"
  local cookie_jar="$3"
  curl --silent --show-error --cookie-jar "$cookie_jar" "$base_url/wp-login.php" >/dev/null
  curl --silent --show-error --location --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --data-urlencode "log=$login_name" \
    --data-urlencode "pwd=$password" \
    --data-urlencode "wp-submit=Log In" \
    --data-urlencode "redirect_to=$base_url/calendrier/" \
    --data-urlencode "testcookie=1" \
    "$base_url/wp-login.php" >"$tmp_dir/$login_name.html"
}

nonce_from_page() {
  local cookie_jar="$1"
  curl --silent --show-error --cookie "$cookie_jar" "$base_url/calendrier/" \
    | tr -d '\n' \
    | sed -n 's/.*data-nonce="\([^"]*\)".*/\1/p'
}

login member_one local-member-one-pass "$tmp_dir/member-one.cookies"
login member_two local-member-two-pass "$tmp_dir/member-two.cookies"
login inactive_member local-inactive-pass "$tmp_dir/inactive.cookies"

nonce_one="$(nonce_from_page "$tmp_dir/member-one.cookies")"
nonce_two="$(nonce_from_page "$tmp_dir/member-two.cookies")"
nonce_inactive="$(nonce_from_page "$tmp_dir/inactive.cookies")"
[[ -n "$nonce_one" && -n "$nonce_two" && -n "$nonce_inactive" ]]

course_name="Course HTTP $(date +%s)"
if event_date="$(date -v+30d +%Y-%m-%d 2>/dev/null)"; then
  :
else
  event_date="$(date -d '+30 days' +%Y-%m-%d)"
fi
payload="$(node -e 'process.stdout.write(JSON.stringify({name:process.argv[1],eventDate:process.argv[2],location:"Lyon",latitude:45.764,longitude:4.8357,eventUrl:"https://example.test/http",formats:["20 km"]}))' "$course_name" "$event_date")"

create_status="$(curl --silent --show-error --output "$tmp_dir/create.json" --write-out '%{http_code}' \
  --cookie "$tmp_dir/member-one.cookies" \
  --header "X-WP-Nonce: $nonce_one" \
  --header 'Content-Type: application/json' \
  --data "$payload" \
  "$api_url/courses/")"
[[ "$create_status" == "201" ]]

list_status="$(curl --silent --show-error --output "$tmp_dir/list.json" --write-out '%{http_code}' \
  --cookie "$tmp_dir/member-two.cookies" \
  --header "X-WP-Nonce: $nonce_two" \
  "$api_url/courses/")"
[[ "$list_status" == "200" ]]
node -e 'const fs=require("fs");const data=JSON.parse(fs.readFileSync(process.argv[1]));if(!data.courses.some(c=>c.name===process.argv[2]))process.exit(1)' "$tmp_dir/list.json" "$course_name"

inactive_status="$(curl --silent --show-error --output "$tmp_dir/inactive.json" --write-out '%{http_code}' \
  --cookie "$tmp_dir/inactive.cookies" \
  --header "X-WP-Nonce: $nonce_inactive" \
  "$api_url/courses/")"
[[ "$inactive_status" == "403" ]]
node -e 'const fs=require("fs");const data=JSON.parse(fs.readFileSync(process.argv[1]));if(data.code!=="crcal_membership_inactive")process.exit(1)' "$tmp_dir/inactive.json"

no_nonce_status="$(curl --silent --show-error --output "$tmp_dir/no-nonce.json" --write-out '%{http_code}' \
  --cookie "$tmp_dir/member-one.cookies" \
  --header 'Content-Type: application/json' \
  --data "$payload" \
  "$api_url/courses/")"
[[ "$no_nonce_status" == "401" ]]

echo "Gate HTTP validée : création session 1, lecture session 2, refus serveur 403, mutation sans nonce 401."
