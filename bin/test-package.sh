#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"
version="$(awk '/^ \* Version:/ { print $3; exit }' plugin/community-race-calendar/community-race-calendar.php)"
artifact="build/community-race-calendar-$version.zip"
if [[ ! -f "$artifact" ]]; then
  echo "Construisez d’abord le ZIP avec ./bin/build-zip.sh" >&2
  exit 1
fi
artifact_name="$(basename "$artifact")"
if ! python3 - "$artifact" <<'PY'
import re
import sys
import zipfile

for name in zipfile.ZipFile(sys.argv[1]).namelist():
    if re.search(r'(^|/)(tests?|fixtures?)/|crc-(test|demo)-memberships|\.env($|\.)', name, re.IGNORECASE):
        raise SystemExit(1)
PY
then
  echo "Le ZIP contient une fixture, une source de test ou un fichier d’environnement interdit." >&2
  exit 1
fi
compose=(docker compose --project-name crc-package-test -f compose.package-test.yaml)

"${compose[@]}" up -d package_db package_wordpress
until curl --silent --fail http://localhost:8088/wp-login.php >/dev/null; do sleep 2; done

if ! "${compose[@]}" run --rm -T package_cli wp core is-installed >/dev/null 2>&1; then
  "${compose[@]}" run --rm -T package_cli wp core install \
    --url=http://localhost:8088 \
    --title="CRC package test" \
    --admin_user=package_admin \
    --admin_password=local-package-pass \
    --admin_email=package@example.test \
    --skip-email >/dev/null
fi

"${compose[@]}" run --rm -T package_cli wp plugin install "/artifacts/$artifact_name" --force --activate >/dev/null
"${compose[@]}" run --rm -T package_cli wp eval '
  $stored = get_option(CommunityRaceCalendar\Plugin::SETTINGS_OPTION, []);
  if (!is_array($stored) || ($stored["map_enabled"] ?? null) !== false || ($stored["show_member_names"] ?? null) !== false) {
    throw new RuntimeException("Une nouvelle installation doit démarrer avec la carte désactivée");
  }
  echo "nouvelle-installation-carte=desactivee ";
'
"${compose[@]}" run --rm -T package_cli wp eval '
  if (defined("CRCAL_PRIVATE_UPLOAD_DIR") || getenv("CRCAL_PRIVATE_UPLOAD_DIR")) {
    throw new RuntimeException("Le test autonome ne doit pas disposer d’une configuration serveur pour les logos");
  }
  $png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", true);
  $tmp = wp_tempnam("crc-logo.png");
  if (!is_string($png) || !$tmp || file_put_contents($tmp, $png) === false) {
    throw new RuntimeException("Fixture logo impossible à préparer");
  }
  $manager = new CommunityRaceCalendar\Media\CourseImageManager();
  $attachment_id = $manager->upload([
    "error" => UPLOAD_ERR_OK,
    "size" => filesize($tmp),
    "tmp_name" => $tmp,
    "name" => "crc-logo.png",
  ], "Logo autonome");
  if (is_wp_error($attachment_id)) {
    throw new RuntimeException("Téléversement autonome impossible : " . $attachment_id->get_error_message());
  }
  $path = wp_normalize_path((string) get_attached_file($attachment_id, true));
  $expected = trailingslashit(wp_normalize_path((string) wp_upload_dir()["basedir"])) . "community-race-calendar/";
  if (!str_starts_with($path, $expected) || !is_file($path)) {
    throw new RuntimeException("Le logo autonome n’est pas stocké dans le dossier WordPress attendu");
  }
  if (!is_file(dirname($path) . "/index.php")) {
    throw new RuntimeException("Le dossier de stockage des logos doit contenir un index.php");
  }
  if (!$manager->deleteManaged((int) $attachment_id) || is_file($path)) {
    throw new RuntimeException("Nettoyage du logo autonome impossible");
  }
  echo "logo-autonome=ok ";
' >/dev/null
"${compose[@]}" run --rm -T package_cli wp eval '
  global $wpdb;
  $tables = CommunityRaceCalendar\Database\Migrator::tables();
  foreach (array_reverse($tables) as $table) {
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
      $wpdb->query("TRUNCATE TABLE `$table`");
    }
  }
  $repo = new CommunityRaceCalendar\Repository\CalendarRepository();
  $created = $repo->createCourse([
    "name" => "Sentinelle mise à jour",
    "eventDate" => gmdate("Y-m-d", strtotime("+45 days")),
    "location" => "Test local",
    "latitude" => 46.6034,
    "longitude" => 1.8883,
    "eventUrl" => "",
    "formats" => ["10 km"],
  ], 1);
  if (!is_int($created)) { throw new RuntimeException("Création sentinelle impossible"); }
  $course_table = $tables["courses"];
  $legacy_support_table = $wpdb->prefix . "community_race_support";
  $wpdb->query("CREATE TABLE IF NOT EXISTS $legacy_support_table (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, course_id bigint(20) unsigned NOT NULL, PRIMARY KEY (id))");
  $wpdb->insert($legacy_support_table, ["course_id" => $created], ["%d"]);
  $wpdb->query("ALTER TABLE $course_table DROP COLUMN latitude, DROP COLUMN longitude, DROP COLUMN image_id");
  $wpdb->query("DROP TABLE IF EXISTS " . $tables["carpools"]);
  $wpdb->query("DROP TABLE IF EXISTS " . $tables["results"]);
  update_option(CommunityRaceCalendar\Database\Migrator::OPTION, "1.0.0", false);
  $legacy_settings = get_option(CommunityRaceCalendar\Plugin::SETTINGS_OPTION, []);
  unset($legacy_settings["map_enabled"], $legacy_settings["show_member_names"]);
  update_option(CommunityRaceCalendar\Plugin::SETTINGS_OPTION, $legacy_settings, false);
' >/dev/null

"${compose[@]}" run --rm -T package_cli wp plugin deactivate community-race-calendar >/dev/null
"${compose[@]}" run --rm -T package_cli wp plugin install "/artifacts/$artifact_name" --force --activate >/dev/null
"${compose[@]}" run --rm -T package_cli wp eval '
  global $wpdb;
  $migration = CommunityRaceCalendar\Database\Migrator::run();
  if ($migration !== true) { throw new RuntimeException("Migration échouée : " . $migration->get_error_message()); }
  $tables = CommunityRaceCalendar\Database\Migrator::tables();
  $table = $tables["courses"];
  $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE name = %s", "Sentinelle mise à jour"));
  if ($count !== 1) { throw new RuntimeException("La mise à jour a perdu la sentinelle"); }
  $columns = $wpdb->get_col("DESCRIBE $table");
  if (!in_array("latitude", $columns, true) || !in_array("longitude", $columns, true) || !in_array("image_id", $columns, true) || !in_array("deleted_at", $columns, true)) { throw new RuntimeException("Migration GPS, image ou suppression réversible absente"); }
  foreach (["carpools", "results"] as $name) {
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tables[$name])) !== $tables[$name]) { throw new RuntimeException("Table $name absente après migration"); }
  }
  $legacy_support_table = $wpdb->prefix . "community_race_support";
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy_support_table)) !== null) { throw new RuntimeException("L’ancienne table de ravitaillement existe encore après migration"); }
  if (get_option(CommunityRaceCalendar\Database\Migrator::OPTION) !== CRCAL_SCHEMA_VERSION) { throw new RuntimeException("Version de schéma incorrecte"); }
  if (CommunityRaceCalendar\Plugin::settings()["map_enabled"] !== true) { throw new RuntimeException("Une mise à jour doit conserver la carte activée"); }
  if (CommunityRaceCalendar\Plugin::settings()["show_member_names"] !== true) { throw new RuntimeException("Une mise à jour doit conserver l’affichage des prénoms et noms"); }
  echo "carte-mise-a-jour=activee noms-mise-a-jour=actives ";
  echo "sentinelle=$count schema=" . CRCAL_SCHEMA_VERSION;
'

echo "ZIP installé puis migré depuis le schéma 1.0.0 sans perte de données : $artifact_name"
