<?php

use CommunityRaceCalendar\Access\AccessProviderInterface;
use CommunityRaceCalendar\Access\AccessGate;
use CommunityRaceCalendar\Access\WooCommerceMembershipsAccessProvider;
use CommunityRaceCalendar\Admin\SettingsPage;
use CommunityRaceCalendar\Database\Migrator;
use CommunityRaceCalendar\Domain\CalendarPeriod;
use CommunityRaceCalendar\Geocoding\GeocodingProviderInterface;
use CommunityRaceCalendar\Geocoding\GeoapifyGeocodingProvider;
use CommunityRaceCalendar\Media\CourseImageManager;
use CommunityRaceCalendar\Plugin;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException("ÉCHEC [$assertions] $message");
    }
};
$temporaryImage = static function (string $name = 'course.png', int $width = 1, int $height = 1): array {
    $path = wp_tempnam($name);
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 210, 6, 6));
    imagepng($image, $path);
    imagedestroy($image);
    return [
        'name' => $name,
        'type' => 'image/png',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
};
$clearRateLimits = static function (): void {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_crcal_rate_%' OR option_name LIKE '_transient_timeout_crcal_rate_%' OR option_name LIKE 'crcal_rate_v2_%' OR option_name LIKE 'crcal_rate_cleanup_%'");
};

$plugin = 'community-race-calendar/community-race-calendar.php';
$assert(is_plugin_active($plugin), 'le plugin est actif');
$assert(shortcode_exists('community_race_calendar'), 'le shortcode est enregistré');
$provider = new WooCommerceMembershipsAccessProvider();
$assert($provider instanceof AccessProviderInterface, 'l’adaptateur implémente l’interface abstraite');
$assert($provider->isAvailable(), 'le contrat Memberships est disponible dans la fixture');
$geocoding = new GeoapifyGeocodingProvider();
$assert($geocoding instanceof GeocodingProviderInterface, 'l’adaptateur de géocodage implémente son interface abstraite');
$assert($geocoding->isAvailable(), 'le géocodage est disponible dans la fixture locale');
$existingGeoapifyKey = get_option(Plugin::GEOAPIFY_API_KEY_OPTION, null);
$testGeoapifyKey = 'test-geoapify-key-1234567890';
$fallbackGeoapifyKey = 'fallback-geoapify-key-1234567890';
$assert(Plugin::sanitizeGeoapifyApiKey($testGeoapifyKey) === $testGeoapifyKey, 'une clé Geoapify conforme est acceptée');
$assert(Plugin::sanitizeGeoapifyApiKey('clé invalide') === '', 'une clé Geoapify invalide est refusée');
if (!defined('CRCAL_GEOAPIFY_API_KEY')) {
    define('CRCAL_GEOAPIFY_API_KEY', $fallbackGeoapifyKey);
}
update_option(Plugin::GEOAPIFY_API_KEY_OPTION, $testGeoapifyKey, false);
$assert(Plugin::geoapifyApiKey() === $testGeoapifyKey, 'la clé Geoapify enregistrée dans WordPress reste lisible côté serveur');
$assert(Plugin::effectiveGeoapifyApiKey() === $testGeoapifyKey, 'la clé enregistrée dans WordPress remplace la clé technique de secours');
delete_option(Plugin::GEOAPIFY_API_KEY_OPTION);
$assert(Plugin::effectiveGeoapifyApiKey() === CRCAL_GEOAPIFY_API_KEY, 'la clé technique est utilisée comme secours quand WordPress ne contient aucune clé');
update_option(Plugin::GEOAPIFY_API_KEY_OPTION, $testGeoapifyKey, false);
$assert(!array_key_exists('geoapifyApiKey', Plugin::publicSettings()) && !in_array($testGeoapifyKey, Plugin::publicSettings(), true), 'la clé Geoapify n’est jamais incluse dans les réglages publics');
$accessibleColors = Plugin::sanitizeSettings(array_merge(Plugin::defaults(), [
    'brand_color' => '#ffffff',
    'background_color' => '#1f2933',
    'surface_color' => '#1f2933',
]));
$assert($accessibleColors['brand_color'] === Plugin::defaults()['brand_color'], 'une couleur d’action sans contraste suffisant revient à la valeur sûre');
$assert($accessibleColors['background_color'] === Plugin::defaults()['background_color'] && $accessibleColors['surface_color'] === Plugin::defaults()['surface_color'], 'les surfaces sans contraste de texte reviennent aux valeurs sûres');
$assert(Plugin::contrastRatio('#1f2933', '#ffffff') > 4.5, 'le calcul de contraste WCAG détecte une paire lisible');
// Design settings: strict allow-lists.
$design = static fn (array $values): array => Plugin::sanitizeSettings(array_merge(Plugin::defaults(), $values));
$defaultDesign = Plugin::defaults();
$assert($defaultDesign['font_family'] === '' && $defaultDesign['content_max_width'] === '1200px' && $defaultDesign['text_color'] === '#1f2933', 'les réglages de design ont des défauts neutres identiques à l’apparence actuelle');
$validFonts = ['Georgia, serif' => 'Georgia, serif', "'Open Sans', Arial" => "'Open Sans', Arial", '"Source Sans 3",system-ui' => '"Source Sans 3", system-ui', '  -apple-system , sans-serif ' => '-apple-system, sans-serif', 'Éphémère, serif' => 'Éphémère, serif'];
foreach ($validFonts as $input => $expected) {
    $assert($design(['font_family' => $input])['font_family'] === $expected, "police valide acceptée : $input");
}
$invalidFonts = ['Arial; color: red', 'Arial} body{display:none', 'url(https://example.test/x.woff)', 'Arial, expression(alert(1))', '</style><script>alert(1)</script>', "'Open Sans, Arial", 'Open Sans", Arial', "'Open Sans\"", 'Arial\\', '@import "x"', 'a, b, c, d, e, f, g, h, i', str_repeat('a', 201), 'Arial, , serif', "Arial\nserif:color", 'var(--x)', "''"];
foreach ($invalidFonts as $input) {
    $assert($design(['font_family' => $input])['font_family'] === '', 'police invalide ou injection refusée : ' . substr($input, 0, 40));
}
$validWidths = ['1200px' => '1200px', '960px' => '960px', '80rem' => '80rem', '90%' => '90%', '1100.5px' => '1100.5px', '320px' => '320px', '2400px' => '2400px', '100%' => '100%', ' 1000px ' => '1000px'];
foreach ($validWidths as $input => $expected) {
    $assert($design(['content_max_width' => $input])['content_max_width'] === $expected, "largeur valide acceptée : $input");
}
foreach (['319px', '2401px', '19rem', '151rem', '49%', '101%', '1200', 'px', '1200em', '1200px;color:red', '1200px}', 'calc(100% - 2px)', 'url(x)', '-5px', '1e3px', '12000px', '1200 px'] as $input) {
    $assert($design(['content_max_width' => $input])['content_max_width'] === '1200px', "largeur invalide ou hors bornes refusée : $input");
}
$assert($design(['text_color' => '#112233'])['text_color'] === '#112233' && $design(['text_color' => '#1A2B3C'])['text_color'] === '#1a2b3c' && $design(['text_color' => '#123'])['text_color'] === '#123', 'couleur de texte hexadécimale valide acceptée');
foreach (['red', 'rgb(0,0,0)', '#12345', '#gggggg', '#112233;', 'url(x)', '#ffffff'] as $input) {
    $assert($design(['text_color' => $input])['text_color'] === '#1f2933', "couleur de texte invalide ou illisible refusée : $input");
}
$assert($design(['text_color' => '#ffffff', 'background_color' => '#1f2933', 'surface_color' => '#111111'])['text_color'] === '#ffffff', 'un texte clair est accepté sur des fonds sombres');
$published = Plugin::publicSettings();
$assert(array_key_exists('fontFamily', $published) && array_key_exists('contentMaxWidth', $published) && array_key_exists('textColor', $published), 'les réglages de design sont publiés au navigateur');
$previousAccountPageId = get_option('woocommerce_myaccount_page_id', null);
$accountPageId = wp_insert_post([
    'post_title' => 'Mon compte test',
    'post_name' => 'mon-compte-test',
    'post_status' => 'publish',
    'post_type' => 'page',
], true);
$assert(is_int($accountPageId), 'la page Mon compte de test est créée');
update_option('woocommerce_myaccount_page_id', $accountPageId, false);
$assert(Plugin::accountUrl() === get_permalink($accountPageId), 'la connexion utilise la page Mon compte publiée de WooCommerce');
wp_delete_post($accountPageId, true);
if ($previousAccountPageId === null) {
    delete_option('woocommerce_myaccount_page_id');
} else {
    update_option('woocommerce_myaccount_page_id', $previousAccountPageId, false);
}
if ($existingGeoapifyKey === null) {
    delete_option(Plugin::GEOAPIFY_API_KEY_OPTION);
} else {
    update_option(Plugin::GEOAPIFY_API_KEY_OPTION, $existingGeoapifyKey, false);
}

global $wpdb;
$assert(Migrator::run() === true, 'la migration du schéma courant réussit');
foreach (Migrator::tables() as $name => $table) {
    $assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, "la table $name existe");
}
$assert(get_option(Migrator::OPTION) === CRCAL_SCHEMA_VERSION, 'la version du schéma est enregistrée');
$assert(Migrator::run() === true, 'une migration réussie peut être rejouée');
$previousVersion = get_option(Migrator::OPTION);
update_option(Migrator::OPTION, '1.2.0', false);
$forcedMigrationFailure = static fn () => new WP_Error('crcal_test_migration_failure', 'Échec simulé');
add_filter('community_race_calendar_migration_verification', $forcedMigrationFailure);
$failedMigration = Migrator::run();
$assert(is_wp_error($failedMigration) && get_option(Migrator::OPTION) === '1.2.0', 'une migration en échec simulé ne marque pas le schéma à jour');
remove_filter('community_race_calendar_migration_verification', $forcedMigrationFailure);
$assert(Migrator::run() === true && get_option(Migrator::OPTION) === $previousVersion, 'la migration reprend proprement après un échec');
$courseColumns = $wpdb->get_col('DESCRIBE ' . Migrator::tables()['courses']);
$assert(in_array('latitude', $courseColumns, true) && in_array('longitude', $courseColumns, true), 'la migration ajoute les coordonnées GPS');
$assert(in_array('image_id', $courseColumns, true), 'la migration ajoute l’image principale');
$legacySupportTable = $wpdb->prefix . 'community_race_support';
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacySupportTable)) === null, 'la migration retire l’ancienne table de ravitaillement');

$userIds = [];
foreach (['crcal_admin', 'member_one', 'member_two', 'inactive_member'] as $login) {
    $user = get_user_by('login', $login);
    $assert($user instanceof WP_User, "le compte $login existe");
    $userIds[$login] = (int) $user->ID;
}

// Access filter: sites without WooCommerce Memberships can supply their own rule.
$unavailableProvider = new class implements AccessProviderInterface {
    public function key(): string { return 'stub'; }
    public function label(): string { return 'Stub'; }
    public function isAvailable(): bool { return false; }
    public function unavailableReason(): string { return 'stub unavailable'; }
    public function userHasAccess(int $userId, array $settings): bool { return false; }
};
$grantLoggedIn = static fn (bool $granted, int $userId): bool => $userId > 0 ? true : $granted;
wp_set_current_user($userIds['member_two']);
$plainGate = new AccessGate($unavailableProvider);
$assert(!$plainGate->hasAccess() && $plainGate->requireAccess() instanceof WP_Error && $plainGate->requireAccess()->get_error_code() === 'crcal_access_provider_unavailable', 'sans filtre, un fournisseur indisponible refuse les membres (comportement inchangé)');
add_filter('community_race_calendar_user_has_access', $grantLoggedIn, 10, 2);
$filteredGate = new AccessGate($unavailableProvider);
$assert($filteredGate->hasAccess() && $filteredGate->requireAccess() === true, 'le filtre community_race_calendar_user_has_access peut accorder l’accès sans extension d’adhésion');
$assert($filteredGate->hasAccess($userIds['inactive_member']), 'le filtre est appliqué à chaque utilisateur vérifié');
remove_filter('community_race_calendar_user_has_access', $grantLoggedIn, 10);
$revoke = static fn (bool $granted, int $userId): bool => $userId === $userIds['member_one'] ? false : $granted;
add_filter('community_race_calendar_user_has_access', $revoke, 10, 2);
$revokeGate = new AccessGate($provider);
$assert(!$revokeGate->hasAccess($userIds['member_one']) && $revokeGate->hasAccess($userIds['member_two']), 'le filtre peut aussi refuser l’accès à un membre précis');
$assert($revokeGate->hasAccess($userIds['crcal_admin']), 'un administrateur du calendrier garde toujours l’accès');
remove_filter('community_race_calendar_user_has_access', $revoke, 10);
$defaultGate = new AccessGate($provider);
$assert($defaultGate->hasAccess($userIds['member_one']) && !$defaultGate->hasAccess($userIds['inactive_member']), 'sans filtre, le fournisseur Memberships décide comme avant');
wp_set_current_user($userIds['crcal_admin']);
$settingsController = new SettingsPage(new AccessGate($provider), $geocoding);
$storedSettings = Plugin::settings();
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['delete_data_on_uninstall' => false]), false);
$settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['brand_color' => '#ffffff']));
$colorErrors = array_filter(
    get_settings_errors(Plugin::SETTINGS_OPTION),
    static fn (array $error): bool => ($error['code'] ?? '') === 'crcal_color_contrast_brand_color'
);
$assert($colorErrors !== [], 'le panneau signale explicitement une couleur remplacée pour contraste insuffisant');
$dangerRefused = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['delete_data_on_uninstall' => true]));
$dangerConfirmed = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, [
    'delete_data_on_uninstall' => true,
    'delete_data_confirmation' => 'SUPPRIMER',
]));
$assert($dangerRefused['delete_data_on_uninstall'] === false, 'la suppression à la désinstallation ne s’active pas sans confirmation explicite');
$assert($dangerConfirmed['delete_data_on_uninstall'] === true, 'la confirmation exacte permet d’activer le danger de désinstallation');
$dangerRestRequest = new WP_REST_Request('PUT', '/community-race-calendar/v1/settings');
$dangerRestRequest->set_header('content-type', 'application/json');
$dangerRestRequest->set_body(wp_json_encode(array_merge($storedSettings, ['delete_data_on_uninstall' => true])));
$dangerRestResponse = rest_do_request($dangerRestRequest);
$assert($dangerRestResponse->get_status() === 400 && !(bool) (Plugin::settings()['delete_data_on_uninstall'] ?? false), 'l’API REST ne peut pas contourner la confirmation du danger de désinstallation');
update_option(Plugin::SETTINGS_OPTION, $storedSettings, false);
ob_start();
$settingsController->render();
$settingsPage = (string) ob_get_clean();
$assert(str_contains($settingsPage, 'id="crc-geoapify-api-key"'), 'le champ Geoapify reste disponible même avec une clé technique de secours');
$assert(str_contains($settingsPage, 'Enregistrer les réglages') && !str_contains($settingsPage, 'Save Changes'), 'le panneau utilise des actions explicites en français');
$assert(str_contains($settingsPage, 'crc-settings__danger'), 'la suppression à la désinstallation est présentée dans un bloc danger dédié');
$assert(str_contains($settingsPage, 'crc-map-title') && str_contains($settingsPage, 'tiles.openfreemap.org') && str_contains($settingsPage, 'openfreemap.org/privacy'), 'le panneau explique le service de carte, les données envoyées et ses conditions');
$assert(str_contains($settingsPage, 'id="crc-font-family"') && str_contains($settingsPage, 'id="crc-content-max-width"') && str_contains($settingsPage, 'id="crc-text-color"'), 'le panneau contient les trois réglages de design');
$designSubmitted = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['font_family' => 'Arial; } body { display:none', 'content_max_width' => '9999px', 'text_color' => 'javascript:1']));
$designErrors = array_filter(get_settings_errors(Plugin::SETTINGS_OPTION), static fn (array $error): bool => str_starts_with((string) ($error['code'] ?? ''), 'crcal_design_invalid_'));
$assert($designSubmitted['font_family'] === '' && $designSubmitted['content_max_width'] === '1200px' && $designSubmitted['text_color'] === '#1f2933' && count($designErrors) === 3, 'le formulaire refuse les valeurs de design invalides avec un message explicite');
$designRest = new WP_REST_Request('PUT', '/community-race-calendar/v1/settings');
$designRest->set_header('content-type', 'application/json');
$designRest->set_body(wp_json_encode(array_merge($storedSettings, ['font_family' => 'Roboto</style><script>', 'content_max_width' => '100%;x', 'text_color' => '#123'])));
$designRestResponse = rest_do_request($designRest);
$assert($designRestResponse->get_status() === 200 && Plugin::settings()['font_family'] === '' && Plugin::settings()['content_max_width'] === '1200px' && Plugin::settings()['text_color'] === '#123', 'l’API REST applique la même liste blanche');
update_option(Plugin::SETTINGS_OPTION, $storedSettings, false);

// Map consent (external tile service): off for new installs, on for installs that predate the setting.
$schemaOptionBackup = get_option(Migrator::OPTION, null);
$restoreSettingsState = static function () use ($storedSettings, $schemaOptionBackup): void {
    update_option(Plugin::SETTINGS_OPTION, $storedSettings, false);
    if ($schemaOptionBackup === null) {
        delete_option(Migrator::OPTION);
    } else {
        update_option(Migrator::OPTION, $schemaOptionBackup, false);
    }
};
$assert(Plugin::defaults()['map_enabled'] === false, 'la carte est désactivée par défaut dans les valeurs par défaut');
// 1. Fresh install: no settings option and no schema version.
delete_option(Plugin::SETTINGS_OPTION);
delete_option(Migrator::OPTION);
Plugin::activate();
$freshStored = get_option(Plugin::SETTINGS_OPTION, []);
$assert(array_key_exists('map_enabled', $freshStored) && $freshStored['map_enabled'] === false && Plugin::settings()['map_enabled'] === false, 'une nouvelle installation démarre avec la carte désactivée');
$assert(Plugin::publicSettings()['mapEnabled'] === false, 'les réglages publics annoncent la carte désactivée');
// 2. Update from a version without the option: settings saved without the key.
$legacySettings = $storedSettings;
unset($legacySettings['map_enabled']);
update_option(Plugin::SETTINGS_OPTION, $legacySettings, false);
update_option(Migrator::OPTION, '1.7.0', false);
$assert(Plugin::settings()['map_enabled'] === true && Plugin::publicSettings()['mapEnabled'] === true, 'une mise à jour sans option enregistrée garde la carte activée');
Plugin::activate();
$upgradedStored = get_option(Plugin::SETTINGS_OPTION, []);
$assert(($upgradedStored['map_enabled'] ?? null) === true, 'la réactivation d’une installation existante enregistre la carte activée');
// 3. Existing install whose settings option is missing but whose schema exists.
delete_option(Plugin::SETTINGS_OPTION);
update_option(Migrator::OPTION, '1.7.0', false);
Plugin::activate();
$assert((get_option(Plugin::SETTINGS_OPTION, [])['map_enabled'] ?? null) === true, 'une installation existante sans réglages enregistrés garde la carte activée');
// 4. Explicit values are respected and not overwritten by activation.
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['map_enabled' => false]), false);
Plugin::activate();
$assert(Plugin::settings()['map_enabled'] === false, 'un choix explicite « désactivée » survit à la réactivation');
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['map_enabled' => true]), false);
$assert(Plugin::settings()['map_enabled'] === true && Plugin::publicSettings()['mapEnabled'] === true, 'un choix explicite « activée » est publié au navigateur');
// 5. Form submission and REST: checkbox off, checkbox on, and omission through REST.
$offSubmitted = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['map_enabled' => '0']));
$onSubmitted = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['map_enabled' => '1']));
$assert($offSubmitted['map_enabled'] === false && $onSubmitted['map_enabled'] === true, 'le formulaire enregistre la case Carte cochée ou décochée');
$restPayload = $storedSettings;
unset($restPayload['map_enabled']);
$restOmit = new WP_REST_Request('PUT', '/community-race-calendar/v1/settings');
$restOmit->set_header('content-type', 'application/json');
$restOmit->set_body(wp_json_encode($restPayload));
$assert(rest_do_request($restOmit)->get_status() === 200 && Plugin::settings()['map_enabled'] === true, 'un client REST qui omet l’option ne désactive pas la carte');
$restOff = new WP_REST_Request('PUT', '/community-race-calendar/v1/settings');
$restOff->set_header('content-type', 'application/json');
$restOff->set_body(wp_json_encode(array_merge($restPayload, ['map_enabled' => false])));
$assert(rest_do_request($restOff)->get_status() === 200 && Plugin::settings()['map_enabled'] === false, 'l’API REST peut désactiver la carte explicitement');
$bootstrapMapOff = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/bootstrap'))->get_data();
$assert(($bootstrapMapOff['settings']['mapEnabled'] ?? null) === false, 'le bootstrap public expose mapEnabled à false');
ob_start();
$settingsController->render();
$assert(preg_match('/aria-describedby="crc-map-description"\s+checked=/', (string) ob_get_clean()) !== 1, 'la case Carte est décochée dans le panneau quand la carte est désactivée');
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['map_enabled' => true]), false);
ob_start();
$settingsController->render();
$assert(preg_match('/aria-describedby="crc-map-description"\s+checked=/', (string) ob_get_clean()) === 1, 'la case Carte est cochée dans le panneau quand la carte est activée');
$restoreSettingsState();
$assert(str_contains($settingsPage, 'Page d’adhésion') && str_contains($settingsPage, 'id="crc-membership-url"') && str_contains($settingsPage, 'type="url"'), 'la cible du bouton d’adhésion est modifiable avec un champ URL explicite');
$assert(!str_contains($settingsPage, 'Page WordPress'), 'la gestion technique de la page WordPress reste masquée');
$assert(!str_contains($settingsPage, 'Adhésions autorisées') && !str_contains($settingsPage, 'Aucune offre d’adhésion'), 'le filtre d’adhésion reste masqué sans choix utile');
$assert(!str_contains($settingsPage, 'Page du calendrier'), 'la gestion de page reste masquée quand le calendrier est déjà associé');
$assert(str_contains($settingsPage, '[calendar_page_id]') && str_contains($settingsPage, '[membership_url]'), 'les réglages de page et d’adhésion sont préservés à l’enregistrement');

foreach (array_reverse(Migrator::tables()) as $table) {
    $wpdb->query("TRUNCATE TABLE `$table`"); // local integration database only.
}
$clearRateLimits();

$period = CalendarPeriod::current();
$assert(CalendarPeriod::contains($period['from']) && CalendarPeriod::contains($period['to']), 'les deux bornes métier sont acceptées');
$assert(!CalendarPeriod::contains(gmdate('Y-m-d', strtotime($period['from'] . ' -1 day'))) && !CalendarPeriod::contains(gmdate('Y-m-d', strtotime($period['to'] . ' +1 day'))), 'les jours immédiatement hors période sont refusés');

wp_set_current_user($userIds['member_one']);
$locations = new WP_REST_Request('GET', '/community-race-calendar/v1/locations');
$locations->set_param('query', 'Lyon');
$locationResponse = rest_do_request($locations);
$locationResults = $locationResponse->get_data()['locations'] ?? [];
$assert($locationResponse->get_status() === 200 && count($locationResults) === 1, 'un membre actif recherche un lieu via le serveur');
$assert(($locationResults[0]['latitude'] ?? null) === 45.764 && ($locationResults[0]['longitude'] ?? null) === 4.8357, 'le géocodage retourne des coordonnées normalisées');
$assert($geocoding->verify() === true, 'un administrateur peut vérifier la connexion Geoapify depuis les réglages');

$freeLocation = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$freeLocation->set_header('content-type', 'application/json');
$freeLocation->set_body(wp_json_encode([
    'name' => 'Course sans point',
    'eventDate' => gmdate('Y-m-d', strtotime('+20 days')),
    'location' => 'Lyon',
    'eventUrl' => '',
    'formats' => ['10 km'],
]));
$freeLocationResponse = rest_do_request($freeLocation);
$freeLocationData = $freeLocationResponse->get_data()['course'] ?? [];
$assert(
    $freeLocationResponse->get_status() === 201,
    sprintf(
        'une nouvelle course peut enregistrer un lieu libre sans coordonnées (HTTP %d, code %s)',
        $freeLocationResponse->get_status(),
        (string) (($freeLocationResponse->get_data()['code'] ?? 'aucun'))
    )
);
$assert(($freeLocationData['latitude'] ?? null) === null && ($freeLocationData['longitude'] ?? null) === null, 'un lieu libre ne reçoit aucune coordonnée inventée');
$assert((new CommunityRaceCalendar\Repository\CalendarRepository())->softDeleteCourse((int) ($freeLocationData['id'] ?? 0), get_current_user_id()) === true, 'la course sans coordonnées utilisée par le test est nettoyée');

$outOfRange = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$outOfRange->set_header('content-type', 'application/json');
$outOfRange->set_body(wp_json_encode([
    'name' => 'Course invisible',
    'eventDate' => gmdate('Y-m-d', strtotime($period['to'] . ' +1 day')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'formats' => ['10 km'],
]));
$outOfRangeResponse = rest_do_request($outOfRange);
$assert($outOfRangeResponse->get_status() === 400 && $outOfRangeResponse->as_error()?->get_error_code() === 'crcal_course_date_out_of_range', 'une date juste après la période est refusée avec un code stable');

$tooManyFormats = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$tooManyFormats->set_header('content-type', 'application/json');
$tooManyFormats->set_body(wp_json_encode([
    'name' => 'Course excessive',
    'eventDate' => gmdate('Y-m-d', strtotime('+20 days')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'formats' => array_fill(0, 21, '10 km'),
]));
$tooManyFormatsResponse = rest_do_request($tooManyFormats);
$assert($tooManyFormatsResponse->get_status() === 400 && $tooManyFormatsResponse->as_error()?->get_error_code() === 'crcal_too_many_formats', 'plus de vingt formats sont refusés avant écriture');

$longName = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$longName->set_header('content-type', 'application/json');
$longName->set_body(wp_json_encode([
    'name' => str_repeat('a', 192),
    'eventDate' => gmdate('Y-m-d', strtotime('+20 days')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'formats' => ['10 km'],
]));
$assert(rest_do_request($longName)->as_error()?->get_error_code() === 'crcal_course_field_too_long', 'le nom est limité à 191 caractères');
$clearRateLimits();

$invalidImagePath = wp_tempnam('course.txt');
file_put_contents($invalidImagePath, 'pas une image');
$invalidImage = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$invalidImage->set_body_params(['payload' => wp_json_encode([
    'name' => 'Course avec faux fichier',
    'eventDate' => gmdate('Y-m-d', strtotime('+20 days')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'eventUrl' => '',
    'formats' => ['10 km'],
])]);
$invalidImage->set_file_params(['image' => [
    'name' => 'course.txt',
    'type' => 'text/plain',
    'tmp_name' => $invalidImagePath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($invalidImagePath),
]]);
$invalidImageResponse = rest_do_request($invalidImage);
$assert(
    $invalidImageResponse->get_status() === 400,
    sprintf(
        'un faux fichier image est refusé (status %d, code %s)',
        $invalidImageResponse->get_status(),
        $invalidImageResponse->as_error()?->get_error_code() ?? 'aucun'
    )
);
$assert($invalidImageResponse->as_error()?->get_error_code() === 'crcal_invalid_image_type', 'le refus du type image possède un code stable');
if (is_file($invalidImagePath)) {
    unlink($invalidImagePath);
}

$extremeImage = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$extremeImage->set_body_params(['payload' => wp_json_encode([
    'name' => 'Course image extrême',
    'eventDate' => gmdate('Y-m-d', strtotime('+20 days')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'formats' => ['10 km'],
])]);
$extremeImage->set_file_params(['image' => $temporaryImage('extreme.png', 10001, 1)]);
$extremeImageResponse = rest_do_request($extremeImage);
$assert($extremeImageResponse->as_error()?->get_error_code() === 'crcal_image_dimensions_exceeded', 'une dimension source extrême est refusée avant décodage complet');

$truncatedPath = wp_tempnam('truncated.png');
file_put_contents($truncatedPath, "\x89PNG\r\n\x1a\n");
$truncatedImage = clone $extremeImage;
$truncatedImage->set_file_params(['image' => [
    'name' => 'truncated.png', 'type' => 'image/png', 'tmp_name' => $truncatedPath,
    'error' => UPLOAD_ERR_OK, 'size' => filesize($truncatedPath),
]]);
$truncatedImageResponse = rest_do_request($truncatedImage);
$assert($truncatedImageResponse->get_status() === 400, 'une image tronquée est refusée');
if (is_file($truncatedPath)) {
    unlink($truncatedPath);
}
$clearRateLimits();

foreach (['from', 'to'] as $boundary) {
    $boundaryRequest = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
    $boundaryRequest->set_header('content-type', 'application/json');
    $boundaryRequest->set_body(wp_json_encode([
        'name' => "Course borne $boundary",
        'eventDate' => $period[$boundary],
        'location' => 'Lyon',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'formats' => ['10 km'],
    ]));
    $boundaryResponse = rest_do_request($boundaryRequest);
    $boundaryCourseId = (int) ($boundaryResponse->get_data()['course']['id'] ?? 0);
    $assert($boundaryResponse->get_status() === 201 && $boundaryCourseId > 0, "la borne métier $boundary est acceptée par l’API");
    $assert((new CommunityRaceCalendar\Repository\CalendarRepository())->softDeleteCourse($boundaryCourseId, get_current_user_id()) === true, "la course de test sur la borne $boundary est nettoyée");
}
$clearRateLimits();

$create = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$create->set_body_params(['payload' => wp_json_encode([
    'name' => 'Trail partagé',
    'eventDate' => gmdate('Y-m-d', strtotime('+30 days')),
    'location' => 'Lyon',
    'latitude' => 45.764,
    'longitude' => 4.8357,
    'eventUrl' => 'https://example.test/trail',
    'formats' => ['25 km', '50 km'],
])]);
$create->set_file_params(['image' => $temporaryImage('course.png', 2400, 1350)]);
$created = rest_do_request($create);
$assert($created->get_status() === 201, 'un membre actif crée une course');
$course = $created->get_data()['course'] ?? [];
$assert(($course['name'] ?? '') === 'Trail partagé', 'la course créée est retournée');
$assert(count($course['formats'] ?? []) === 2, 'les formats sont persistés');
$assert(($course['latitude'] ?? null) === 45.764 && ($course['longitude'] ?? null) === 4.8357, 'les coordonnées GPS sont persistées et retournées');
$firstImageId = (int) ($course['image']['id'] ?? 0);
$privateImagePath = get_attached_file($firstImageId, true);
$assert($firstImageId > 0 && str_contains((string) ($course['image']['url'] ?? ''), '/community-race-calendar/v1/images/'), 'l’image privée est retournée par la route contrôlée');
$assert(str_contains((string) ($course['image']['url'] ?? ''), '_wpnonce='), 'l’URL privée inclut le nonce REST nécessaire au chargement direct par le navigateur');
$assert(is_string($privateImagePath) && is_file($privateImagePath) && !str_starts_with(wp_normalize_path($privateImagePath), wp_normalize_path(ABSPATH)), 'le fichier original est stocké hors de la racine web');
$storageIndex = trailingslashit(dirname((string) $privateImagePath)) . 'index.php';
$assert(is_file($storageIndex) && str_contains((string) file_get_contents($storageIndex), 'Silence is golden'), 'le dossier de stockage des logos contient un index.php vide contre le listage des fichiers');
$firstImageMetadata = wp_get_attachment_metadata($firstImageId);
$assert(($firstImageMetadata['width'] ?? 0) <= 1600 && ($firstImageMetadata['height'] ?? 0) <= 900, 'l’image est redimensionnée avant stockage');
$assert(get_post_mime_type($firstImageId) === 'image/webp', 'l’image est convertie en WebP lorsque le serveur le permet');
$privateImageResponse = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/images/$firstImageId"));
$assert($privateImageResponse->get_status() === 200 && $privateImageResponse->get_data() === null, 'un membre actif obtient la réponse privée sans exposition du chemin serveur');
$imageManager = new CourseImageManager();
$orphanImageId = $imageManager->upload($temporaryImage('orpheline.png'), 'Image orpheline');
$assert(is_int($orphanImageId) && rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/images/$orphanImageId"))->get_status() === 404, 'une image gérée mais non référencée par une course publiée reste inaccessible');
$filteredMediaQuery = $imageManager->filterAttachmentQuery([]);
$assert(in_array((int) $orphanImageId, $filteredMediaQuery['post__not_in'] ?? [], true), 'une image privée orpheline est exclue de la collection REST des médias');
update_option('community_race_calendar_image_cleanup_last_scan', time(), false);
$assert($imageManager->cleanupOrphanedImages() === true && get_post((int) $orphanImageId) !== null, 'le scan global des médias orphelins est limité dans le temps');
$imageManager->queueCleanup((int) $orphanImageId);
$assert($imageManager->cleanupOrphanedImages() === true && get_post((int) $orphanImageId) === null, 'le nettoyage différé supprime une image privée orpheline');
delete_option('community_race_calendar_image_cleanup_last_scan');
$pendingImages = new ReflectionProperty(CourseImageManager::class, 'pendingRestImages');
$pendingImages->setAccessible(true);
$pendingImages->setValue($imageManager, [999999 => '/etc/hosts']);
$foreignResponse = new WP_REST_Response(null);
$assert($imageManager->serveRestImage(false, $foreignResponse, new WP_REST_Request('GET', '/community-race-calendar/v1/images/999999')) === false, 'le diffuseur refuse un chemin situé hors du stockage privé');
$assert($imageManager->serveRestImage(false, new WP_REST_Response(null), new WP_REST_Request('GET', '/unrelated/v1/file')) === false, 'le diffuseur privé refuse toute autre route REST');
$servedResponse = $imageManager->imageResponse($firstImageId);
$assert($servedResponse instanceof WP_REST_Response, 'une image référencée prépare une réponse privée');
ob_start();
$servedOk = $imageManager->serveRestImage(false, $servedResponse, new WP_REST_Request('GET', "/community-race-calendar/v1/images/$firstImageId"));
$servedBytes = (string) ob_get_clean();
$assert($servedOk === true && $servedBytes !== '' && hash('sha256', $servedBytes) === hash_file('sha256', (string) $privateImagePath), 'le diffuseur privé envoie exactement les octets du fichier stocké');
$legacyUploads = wp_upload_dir();
$legacyFile = trailingslashit($legacyUploads['path']) . 'crc-legacy-' . wp_generate_password(8, false, false) . '.png';
$legacySource = $temporaryImage('legacy.png', 4, 4);
copy($legacySource['tmp_name'], $legacyFile);
$legacyAttachmentId = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Logo historique', 'post_status' => 'inherit'], $legacyFile);
update_post_meta($legacyAttachmentId, '_crcal_managed_course_image', '1');
$legacyHash = hash_file('sha256', $legacyFile);
$assert($imageManager->migrateLegacyImages() === true, 'la migration des logos historiques réussit');
$migratedPath = (string) get_attached_file($legacyAttachmentId, true);
$assert(!is_file($legacyFile) && is_file($migratedPath) && hash_file('sha256', $migratedPath) === $legacyHash && !str_starts_with(wp_normalize_path($migratedPath), wp_normalize_path(ABSPATH)), 'un logo historique est déplacé intact vers le stockage privé');
wp_delete_file($migratedPath);
wp_delete_attachment($legacyAttachmentId, true);
$assert(!is_file($legacyFile), 'le logo historique de test est nettoyé');
$shortcodeHtml = do_shortcode('[community_race_calendar]');
ob_start();
wp_script_modules()->print_enqueued_script_modules();
$moduleHtml = (string) ob_get_clean();
$assert(str_contains($shortcodeHtml, 'data-community-race-calendar') && str_contains($moduleHtml, 'type="module"') && str_contains($moduleHtml, 'assets/app.js'), 'le shortcode charge l’application comme module de script WordPress');
$assert(!wp_script_is('community-race-calendar', 'enqueued'), 'aucun script classique n’est ajouté pour l’application');

$replacement = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}");
$replacement->set_body_params(['payload' => wp_json_encode([
    'name' => $course['name'],
    'eventDate' => $course['eventDate'],
    'location' => $course['location'],
    'latitude' => $course['latitude'],
    'longitude' => $course['longitude'],
    'eventUrl' => $course['eventUrl'],
    'formats' => array_map(static fn (array $format): array => ['id' => $format['id'], 'label' => $format['label']], $course['formats']),
])]);
$replacement->set_file_params(['image' => $temporaryImage('remplacement.png')]);
$replaced = rest_do_request($replacement);
$replacedCourse = $replaced->get_data()['course'] ?? [];
$secondImageId = (int) ($replacedCourse['image']['id'] ?? 0);
$assert($replaced->get_status() === 200 && $secondImageId > 0 && $secondImageId !== $firstImageId, 'le propriétaire remplace l’image');
$assert(get_post($firstImageId) === null, 'l’ancienne image remplacée est supprimée');

$removeImage = new WP_REST_Request('PATCH', "/community-race-calendar/v1/courses/{$course['id']}");
$removeImage->set_header('content-type', 'application/json');
$removeImage->set_body(wp_json_encode([
    'name' => $course['name'],
    'eventDate' => $course['eventDate'],
    'location' => $course['location'],
    'latitude' => $course['latitude'],
    'longitude' => $course['longitude'],
    'eventUrl' => $course['eventUrl'],
    'formats' => array_map(static fn (array $format): array => ['id' => $format['id'], 'label' => $format['label']], $course['formats']),
    'removeImage' => true,
]));
$removed = rest_do_request($removeImage);
$removedCourse = $removed->get_data()['course'] ?? [];
$assert($removed->get_status() === 200 && array_key_exists('image', $removedCourse) && $removedCourse['image'] === null, 'le propriétaire retire l’image');
$assert(get_post($secondImageId) === null, 'l’image retirée est supprimée de la médiathèque');

$replacement->set_file_params(['image' => $temporaryImage('image-finale.png')]);
$reuploaded = rest_do_request($replacement);
$course = $reuploaded->get_data()['course'] ?? $course;
$finalImageId = (int) ($course['image']['id'] ?? 0);
$finalImagePath = get_attached_file($finalImageId, true);
$assert($reuploaded->get_status() === 200 && $finalImageId > 0, 'une nouvelle image peut être ajoutée après retrait');

$clearRateLimits();
$editionPayload = static fn (array $overrides = []): string => wp_json_encode(array_merge([
    'name' => 'Trail partagé',
    'eventDate' => gmdate('Y-m-d', strtotime('+60 days')),
    'location' => 'Lyon',
    'formats' => ['25 km'],
], $overrides));
$editionsSearch = new WP_REST_Request('GET', '/community-race-calendar/v1/courses/editions');
$editionsSearch->set_param('search', 'trail part');
$editions = rest_do_request($editionsSearch);
$edition = ($editions->get_data()['editions'] ?? [])[0] ?? [];
$assert($editions->get_status() === 200 && ($edition['id'] ?? 0) === $course['id'] && (int) ($edition['image']['id'] ?? 0) === $finalImageId, 'la recherche d’éditions retrouve la course avec son logo');
$assert(count($edition['formats'] ?? []) === 2 && ($edition['eventUrl'] ?? '') === 'https://example.test/trail', 'l’édition trouvée fournit formats et site pour préremplir la suivante');
$editionsSearch->set_param('search', 'glieres introuvable');
$assert(($editions = rest_do_request($editionsSearch))->get_status() === 200 && ($editions->get_data()['editions'] ?? null) === [], 'une recherche sans correspondance ne renvoie aucune édition');

$nextEdition = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$nextEdition->set_body_params(['payload' => $editionPayload(['reuseImageId' => $finalImageId])]);
$nextEditionResponse = rest_do_request($nextEdition);
$nextEditionCourse = $nextEditionResponse->get_data()['course'] ?? [];
$assert($nextEditionResponse->get_status() === 201 && (int) ($nextEditionCourse['image']['id'] ?? 0) === $finalImageId, 'une nouvelle édition réutilise le logo existant sans le dupliquer');
$assert((int) get_post_meta($finalImageId, '_crcal_course_id', true) === $course['id'], 'le logo partagé garde sa course d’origine');

$duplicateUpload = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$duplicateUpload->set_body_params(['payload' => $editionPayload(['name' => 'Trail partagé bis'])]);
$duplicateUpload->set_file_params(['image' => $temporaryImage('copie-identique.png')]);
$duplicateUploadResponse = rest_do_request($duplicateUpload);
$duplicateCourse = $duplicateUploadResponse->get_data()['course'] ?? [];
$assert($duplicateUploadResponse->get_status() === 201 && (int) ($duplicateCourse['image']['id'] ?? 0) === $finalImageId, 'un fichier identique déjà présent réutilise le logo existant');

$conflictingReuse = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$conflictingReuse->set_body_params(['payload' => $editionPayload(['reuseImageId' => $finalImageId])]);
$conflictingReuse->set_file_params(['image' => $temporaryImage('conflit.png', 2, 2)]);
$assert(rest_do_request($conflictingReuse)->get_status() === 400, 'un nouveau fichier et un logo réutilisé ne peuvent pas être envoyés ensemble');
$unavailableReuse = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$unavailableReuse->set_body_params(['payload' => $editionPayload(['reuseImageId' => 999999])]);
$assert(rest_do_request($unavailableReuse)->get_status() === 400, 'un logo inexistant ou non affiché ne peut pas être réutilisé');

$replacement->set_file_params(['image' => $temporaryImage('nouveau-logo-origine.png', 3, 3)]);
$originReplaced = rest_do_request($replacement);
$course = $originReplaced->get_data()['course'] ?? $course;
$assert($originReplaced->get_status() === 200 && (int) ($course['image']['id'] ?? 0) !== $finalImageId, 'la course d’origine change de logo');
$assert(get_post($finalImageId) !== null && is_file((string) get_attached_file($finalImageId, true)), 'le logo encore utilisé par d’autres éditions n’est pas supprimé');
$assert(rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/images/$finalImageId"))->get_status() === 200, 'les autres éditions affichent toujours le logo partagé');

$removeSharedLogo = static function (array $edition): int {
    $request = new WP_REST_Request('PATCH', "/community-race-calendar/v1/courses/{$edition['id']}");
    $request->set_header('content-type', 'application/json');
    $request->set_body(wp_json_encode([
        'name' => $edition['name'],
        'eventDate' => $edition['eventDate'],
        'location' => $edition['location'],
        'formats' => array_map(static fn (array $format): array => ['id' => $format['id'], 'label' => $format['label']], $edition['formats']),
        'removeImage' => true,
    ]));
    return rest_do_request($request)->get_status();
};
$assert($removeSharedLogo($nextEditionCourse) === 200 && get_post($finalImageId) !== null, 'retirer le logo d’une édition le conserve pour les autres');
$softDelete = rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$duplicateCourse['id']}"));
$imageManager->queueCleanup($finalImageId);
$assert($softDelete->get_status() === 204 && $imageManager->cleanupOrphanedImages() === true && get_post($finalImageId) !== null, 'une édition dans la corbeille protège encore son logo');
$restore = rest_do_request(new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$duplicateCourse['id']}/restore"));
$assert($restore->get_status() === 200 && $removeSharedLogo($duplicateCourse) === 200, 'la dernière édition utilisatrice retire le logo');
$imageManager->cleanupOrphanedImages();
$assert(get_post($finalImageId) === null, 'le logo n’est supprimé qu’une fois plus aucune course ne l’utilise');
foreach ([$nextEditionCourse['id'], $duplicateCourse['id']] as $editionId) {
    $assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/$editionId"))->get_status() === 204, 'les éditions de test sont retirées du calendrier');
}
$finalImageId = (int) ($course['image']['id'] ?? 0);
$finalImagePath = get_attached_file($finalImageId, true);
$clearRateLimits();

wp_set_current_user($userIds['member_two']);
$listed = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/courses'));
$assert($listed->get_status() === 200, 'la seconde session active lit les courses');
$listedCourses = $listed->get_data()['courses'] ?? [];
$assert(count($listedCourses) === 1 && $listedCourses[0]['id'] === $course['id'], 'la même course est partagée entre les deux sessions');
$assert(array_key_exists('comments', $listedCourses[0]) && $listedCourses[0]['comments'] === null && ($listedCourses[0]['formats'][0]['registrationCount'] ?? -1) === 0, 'la liste retourne un résumé sans commentaires ni participants complets');
$detail = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/courses/{$course['id']}"));
$assert($detail->get_status() === 200 && ($detail->get_data()['course']['detailLoaded'] ?? false) === true, 'le détail ciblé retourne la ressource complète sans relire toute la période');

wp_set_current_user($userIds['inactive_member']);
$denied = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/courses'));
$assert($denied->get_status() === 403, 'le membre inactif est refusé côté serveur');
$assert(($denied->as_error()?->get_error_code()) === 'crcal_membership_inactive', 'le refus serveur possède un code stable');
$deniedImage = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/images/$finalImageId"));
$assert($deniedImage->get_status() === 403, 'un membre inactif ne peut pas lire une image privée');
$deniedMedia = rest_do_request(new WP_REST_Request('GET', "/wp/v2/media/$finalImageId"));
$assert($deniedMedia->get_status() === 404, 'l’API média WordPress masque la pièce jointe gérée au membre inactif');

wp_set_current_user(0);
$anonymousImage = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/images/$finalImageId"));
$assert($anonymousImage->get_status() === 401, 'un visiteur ne peut pas lire une image privée');

wp_set_current_user($userIds['member_one']);
$register = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/registrations");
$register->set_header('content-type', 'application/json');
$register->set_body(wp_json_encode(['formatId' => $course['formats'][0]['id']]));
$registered = rest_do_request($register);
$assert($registered->get_status() === 201, 'le membre s’inscrit à un format');
$duplicate = rest_do_request($register);
$assert($duplicate->get_status() === 409, 'la double inscription est refusée');

$carpool = new WP_REST_Request('PUT', "/community-race-calendar/v1/courses/{$course['id']}/carpool");
$carpool->set_header('content-type', 'application/json');
$carpool->set_body(wp_json_encode([
    'type' => 'driver',
    'seats' => 3,
    'departure' => 'Lyon Part-Dieu',
    'note' => 'Départ à 6 h.',
]));
$carpoolResponse = rest_do_request($carpool);
$assert($carpoolResponse->get_status() === 200 && ($carpoolResponse->get_data()['carpool']['seats'] ?? 0) === 3, 'un inscrit publie une proposition de covoiturage');

$result = new WP_REST_Request('PUT', "/community-race-calendar/v1/courses/{$course['id']}/result");
$result->set_header('content-type', 'application/json');
$result->set_body(wp_json_encode([
    'formatId' => $course['formats'][0]['id'],
    'time' => '3 h 42',
    'ranking' => '24e / 380',
    'distance' => '25 km',
    'note' => 'Une belle journée en montagne.',
]));
$seasonCacheVersionBefore = (int) get_option('community_race_calendar_season_cache_version', 1);
$resultResponse = rest_do_request($result);
$assert($resultResponse->get_status() === 200 && ($resultResponse->get_data()['result']['time'] ?? '') === '3 h 42', 'un inscrit publie son résultat');
$assert((int) get_option('community_race_calendar_season_cache_version', 1) > $seasonCacheVersionBefore, 'une mutation de résultat invalide le cache du bilan');

$contributionDetail = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/courses/{$course['id']}"));
$contributionCourse = $contributionDetail->get_data()['course'] ?? [];
$assert(count($contributionCourse['carpools'] ?? []) === 1 && count($contributionCourse['results'] ?? []) === 1, 'la fiche détaillée restitue covoiturage et résultat');

$pastDate = gmdate('Y-m-d', strtotime('-5 days'));
$wpdb->update(Migrator::tables()['courses'], ['event_date' => $pastDate], ['id' => $course['id']], ['%s'], ['%d']);
$seasonRequest = new WP_REST_Request('GET', '/community-race-calendar/v1/season');
$seasonRequest->set_param('year', (int) gmdate('Y', strtotime($pastDate)));
$seasonResponse = rest_do_request($seasonRequest);
$season = $seasonResponse->get_data()['season'] ?? [];
$assert($seasonResponse->get_status() === 200 && ($season['stats']['coursesCount'] ?? 0) === 1, 'le bilan annuel compte les courses de la saison');
$assert(($season['weeks'][0]['courses'][0]['participants'][0]['result']['ranking'] ?? '') === '24e / 380', 'la revue hebdomadaire restitue les participants et leur résultat');
$assert(($season['truncated'] ?? true) === false, 'le bilan signale explicitement si sa limite de sécurité est atteinte');

$reviewRequest = new WP_REST_Request('GET', '/community-race-calendar/v1/review');
$reviewRequest->set_param('from', $pastDate);
$reviewRequest->set_param('to', $pastDate);
$reviewResponse = rest_do_request($reviewRequest);
$review = $reviewResponse->get_data()['review'] ?? [];
$assert($reviewResponse->get_status() === 200 && ($review['from'] ?? '') === $pastDate && ($review['to'] ?? '') === $pastDate, 'la revue utilise une plage de dates explicite');
$assert(($review['courses'][0]['id'] ?? 0) === $course['id'] && ($review['courses'][0]['participants'][0]['result']['time'] ?? '') === '3 h 42', 'la revue restitue les courses et résultats associés aux dates choisies');
$reviewParticipantId = (int) ($review['courses'][0]['participants'][0]['userId'] ?? 0);
update_option(Plugin::SETTINGS_OPTION, array_merge(Plugin::settings(), ['show_member_names' => true]), false);
update_user_meta($reviewParticipantId, 'first_name', 'Camille');
update_user_meta($reviewParticipantId, 'last_name', 'Durand');
$namedReview = rest_do_request($reviewRequest)->get_data()['review'] ?? [];
$assert($reviewParticipantId > 0 && ($namedReview['courses'][0]['participants'][0]['displayName'] ?? '') === 'Camille Durand', 'la revue affiche le prénom et le nom du participant');
delete_user_meta($reviewParticipantId, 'first_name');
delete_user_meta($reviewParticipantId, 'last_name');

$duplicatesRequest = new WP_REST_Request('GET', '/community-race-calendar/v1/courses/duplicates');
$duplicatesRequest->set_param('name', $course['name']);
$duplicatesRequest->set_param('eventDate', $pastDate);
$duplicatesResponse = rest_do_request($duplicatesRequest);
$assert($duplicatesResponse->get_status() === 200 && count($duplicatesResponse->get_data()['duplicates'] ?? []) === 1, 'la détection signale une course du même nom dans le même mois');

$approximateDuplicatesRequest = new WP_REST_Request('GET', '/community-race-calendar/v1/courses/duplicates');
$approximateDuplicatesRequest->set_param('name', 'Traill partage');
$approximateDuplicatesRequest->set_param('eventDate', $pastDate);
$approximateDuplicatesResponse = rest_do_request($approximateDuplicatesRequest);
$assert($approximateDuplicatesResponse->get_status() === 200 && count($approximateDuplicatesResponse->get_data()['duplicates'] ?? []) === 1, 'la détection tolère les accents et une petite faute d’orthographe');

$distinctDuplicatesRequest = new WP_REST_Request('GET', '/community-race-calendar/v1/courses/duplicates');
$distinctDuplicatesRequest->set_param('name', 'Trail sauvage');
$distinctDuplicatesRequest->set_param('eventDate', $pastDate);
$distinctDuplicatesResponse = rest_do_request($distinctDuplicatesRequest);
$assert($distinctDuplicatesResponse->get_status() === 200 && count($distinctDuplicatesResponse->get_data()['duplicates'] ?? []) === 0, 'la détection approximative ne confond pas deux noms distincts');

$duplicateCourseRequest = new WP_REST_Request('POST', '/community-race-calendar/v1/courses');
$duplicateCourseRequest->set_body_params(['payload' => wp_json_encode([
    'name' => $course['name'],
    'eventDate' => $pastDate,
    'location' => 'Autre départ',
    'eventUrl' => '',
    'formats' => ['10 km'],
])]);
$duplicateCourseResponse = rest_do_request($duplicateCourseRequest);
$duplicateCourseId = (int) ($duplicateCourseResponse->get_data()['course']['id'] ?? 0);
$assert($duplicateCourseResponse->get_status() === 201 && $duplicateCourseId > 0, 'la détection de doublon reste non bloquante');
$assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/$duplicateCourseId"))->get_status() === 204, 'la course de contrôle du doublon est placée dans les éléments supprimés');

$commentRateKey = 'crcal_rate_v2_' . MINUTE_IN_SECONDS . '_' . (int) floor(time() / MINUTE_IN_SECONDS) . '_' . md5('comment:' . get_current_user_id());
$hourlyRateSentinel = 'crcal_rate_v2_' . HOUR_IN_SECONDS . '_' . (int) floor(time() / HOUR_IN_SECONDS) . '_integration-sentinel';
delete_option('crcal_rate_cleanup_' . MINUTE_IN_SECONDS . '_' . (int) floor(time() / MINUTE_IN_SECONDS));
update_option($commentRateKey, 30, false);
update_option($hourlyRateSentinel, 7, false);
$limitedComment = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/comments");
$limitedComment->set_header('content-type', 'application/json');
$limitedComment->set_body(wp_json_encode(['body' => 'Trop de commentaires']));
$limitedCommentResponse = rest_do_request($limitedComment);
$assert($limitedCommentResponse->get_status() === 429 && $limitedCommentResponse->as_error()?->get_error_code() === 'crcal_rate_limited', 'la limite d’abus retourne un statut 429 stable');
$assert((int) get_option($hourlyRateSentinel) === 7, 'le nettoyage d’une fenêtre de débit ne réinitialise pas les autres fenêtres');
delete_option($commentRateKey);
delete_option($hourlyRateSentinel);
$clearRateLimits();

$comment = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/comments");
$comment->set_header('content-type', 'application/json');
$comment->set_body(wp_json_encode(['body' => 'Rendez-vous sur la ligne de départ.']));
$commented = rest_do_request($comment);
$assert($commented->get_status() === 201, 'un inscrit ajoute un commentaire');
$registrationId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . Migrator::tables()['registrations'] . ' WHERE course_id = %d AND user_id = %d',
    $course['id'],
    $userIds['member_one']
));
$commentId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . Migrator::tables()['comments'] . ' WHERE course_id = %d AND user_id = %d',
    $course['id'],
    $userIds['member_one']
));

wp_set_current_user($userIds['member_two']);
$nonRegistrantComment = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/comments");
$nonRegistrantComment->set_header('content-type', 'application/json');
$nonRegistrantComment->set_body(wp_json_encode(['body' => 'Je serai accompagnant sur place.']));
$nonRegistrantCommentResponse = rest_do_request($nonRegistrantComment);
$assert($nonRegistrantCommentResponse->get_status() === 201, 'un adhérent non inscrit peut commenter');
$nonRegistrantCarpool = new WP_REST_Request('PUT', "/community-race-calendar/v1/courses/{$course['id']}/carpool");
$nonRegistrantCarpool->set_header('content-type', 'application/json');
$nonRegistrantCarpool->set_body(wp_json_encode(['type' => 'passenger', 'seats' => 0, 'note' => 'Accompagnant sans dossard.']));
$nonRegistrantCarpoolResponse = rest_do_request($nonRegistrantCarpool);
$assert($nonRegistrantCarpoolResponse->get_status() === 200, 'un adhérent non inscrit peut participer au covoiturage');
$nonRegistrantDetail = rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/courses/{$course['id']}"));
$nonRegistrantCourse = $nonRegistrantDetail->get_data()['course'] ?? [];
$assert(($nonRegistrantCourse['permissions']['comment'] ?? false) && ($nonRegistrantCourse['permissions']['carpool'] ?? false) && !($nonRegistrantCourse['permissions']['result'] ?? true), 'les permissions ouvrent les échanges mais réservent le résultat aux inscrits');
$forbiddenResult = new WP_REST_Request('PUT', "/community-race-calendar/v1/courses/{$course['id']}/result");
$forbiddenResult->set_header('content-type', 'application/json');
$forbiddenResult->set_body(wp_json_encode(['formatId' => $course['formats'][0]['id'], 'time' => '1 h']));
$assert(rest_do_request($forbiddenResult)->get_status() === 403, 'un non-inscrit ne publie pas de résultat');
$assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/registrations/$registrationId"))->get_status() === 403, 'un membre ne supprime pas l’inscription d’un tiers');
$assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/comments/$commentId"))->get_status() === 403, 'un membre ne supprime pas le commentaire d’un tiers');
$nonRegistrantCommentId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . Migrator::tables()['comments'] . ' WHERE course_id = %d AND user_id = %d AND deleted_at IS NULL',
    $course['id'],
    $userIds['member_two']
));
$assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/comments/$nonRegistrantCommentId"))->get_status() === 204, 'le non-inscrit peut supprimer son propre commentaire');
$assert(rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$course['id']}/carpool"))->get_status() === 204, 'le non-inscrit peut supprimer son propre covoiturage');

wp_set_current_user($userIds['crcal_admin']);
$settings = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/settings'));
$assert($settings->get_status() === 200, 'l’administrateur lit les réglages');
$beyondUserId = 0;
for ($index = 1; $index <= 25; $index++) {
    $login = sprintf('crcal_bulk_inactive_%02d', $index);
    $bulkUser = get_user_by('login', $login);
    if (!$bulkUser) {
        $createdUserId = wp_create_user($login, wp_generate_password(24), "$login@example.test");
        $bulkUser = get_user_by('id', $createdUserId);
    }
    wp_update_user(['ID' => $bulkUser->ID, 'display_name' => sprintf('AAA Inactif %02d', $index)]);
    update_user_meta($bulkUser->ID, 'crcal_test_active_membership', '0');
}
$beyondUser = get_user_by('login', 'crcal_active_beyond_twenty');
if (!$beyondUser) {
    $beyondUser = get_user_by('id', wp_create_user('crcal_active_beyond_twenty', wp_generate_password(24), 'crcal_active_beyond_twenty@example.test'));
}
wp_update_user(['ID' => $beyondUser->ID, 'display_name' => 'ZZZ Actif au-delà de vingt']);
update_user_meta($beyondUser->ID, 'crcal_test_active_membership', '1');
$beyondUserId = (int) $beyondUser->ID;
wp_set_current_user($userIds['member_one']);
$members = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/members'));
$assert($members->get_status() === 200, 'un adhérent recherche les membres actifs');
$memberIds = array_map('intval', array_column($members->get_data()['members'] ?? [], 'id'));
$assert(in_array($beyondUserId, $memberIds, true), 'un membre actif situé après plus de vingt comptes inactifs reste trouvable');
$assert(!in_array($userIds['inactive_member'], $memberIds, true), 'la recherche exclut les membres inactifs');
$beyondLabel = (string) (($members->get_data()['members'][array_search($beyondUserId, $memberIds, true)]['label'] ?? ''));
$assert($beyondLabel === 'ZZZ Actif au-delà de vingt', 'sans prénom ni nom, la recherche affiche le nom public');
$assert(!str_contains($beyondLabel, '@'), 'la recherche n’expose ni identifiant ni email');
update_user_meta($beyondUserId, 'first_name', 'Zoé');
update_user_meta($beyondUserId, 'last_name', 'Vingtième');
$nameSearch = new WP_REST_Request('GET', '/community-race-calendar/v1/members');
$nameSearch->set_param('search', 'Vingtième');
$nameMembers = rest_do_request($nameSearch)->get_data()['members'] ?? [];
$assert(count($nameMembers) === 1 && (int) $nameMembers[0]['id'] === $beyondUserId, 'la recherche trouve un membre par son nom de famille');
$assert(($nameMembers[0]['displayName'] ?? '') === 'Zoé Vingtième' && ($nameMembers[0]['label'] ?? '') === 'Zoé Vingtième · ZZZ Actif au-delà de vingt', 'la recherche affiche prénom et nom, puis le nom public pour distinguer les homonymes');
$nameSearch->set_param('search', 'Zoé Vingt');
$assert(count(rest_do_request($nameSearch)->get_data()['members'] ?? []) === 1, 'la recherche accepte prénom et nom saisis ensemble');
update_user_meta($beyondUserId, 'first_name', '');
update_user_meta($beyondUserId, 'last_name', '');
update_user_meta($beyondUserId, 'billing_first_name', 'Zia');
update_user_meta($beyondUserId, 'billing_last_name', 'Facturée');
$assert(\CommunityRaceCalendar\Domain\MemberName::of($beyondUserId, 'pseudo') === 'Zia Facturée', 'à défaut du profil, le nom de facturation WooCommerce est utilisé');
delete_user_meta($beyondUserId, 'billing_first_name');
delete_user_meta($beyondUserId, 'billing_last_name');
$assert(\CommunityRaceCalendar\Domain\MemberName::of(0, null) === 'Utilisateur supprimé', 'un compte supprimé reste signalé');
$assert(\CommunityRaceCalendar\Domain\MemberName::of(0, 'jean.dupont@example.test') === 'jean.dupont', 'sans prénom ni nom, le domaine d’un email n’est jamais affiché');
wp_update_user(['ID' => $beyondUserId, 'display_name' => 'zoe.vingt@example.test']);
update_user_meta($beyondUserId, 'first_name', 'Zoé');
update_user_meta($beyondUserId, 'last_name', 'Vingtième');
$nameSearch->set_param('search', 'Vingtième');
$assert((rest_do_request($nameSearch)->get_data()['members'][0]['label'] ?? '') === 'Zoé Vingtième', 'un nom public en forme d’email n’est jamais affiché');
update_user_meta($beyondUserId, 'first_name', '');
update_user_meta($beyondUserId, 'last_name', '');
wp_update_user(['ID' => $beyondUserId, 'display_name' => 'ZZZ Actif au-delà de vingt']);
$loginSearchUser = wp_update_user(['ID' => $beyondUserId, 'display_name' => 'Pseudo Public Sans Login']);
$assert(is_int($loginSearchUser) && (string) get_userdata($beyondUserId)->user_login === 'crcal_active_beyond_twenty', 'le compte de contrôle garde son identifiant de connexion');
$nameSearch->set_param('search', 'crcal_active_beyond');
$assert(count(rest_do_request($nameSearch)->get_data()['members'] ?? []) === 0, 'la recherche ne permet pas d’énumérer les identifiants de connexion');
$nameSearch->set_param('search', 'Pseudo Public');
$assert(count(rest_do_request($nameSearch)->get_data()['members'] ?? []) === 1, 'la recherche trouve toujours le nom public modifié');
wp_update_user(['ID' => $beyondUserId, 'display_name' => 'ZZZ Actif au-delà de vingt']);
// Simple per-user rate limit on member search.
$memberRateKey = 'crcal_members_rate_' . get_current_user_id() . '_' . gmdate('YmdHi');
set_transient($memberRateKey, 59, MINUTE_IN_SECONDS + 5);
$lastAllowed = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/members'));
$limitedMembers = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/members'));
$assert($lastAllowed->get_status() === 200 && $limitedMembers->get_status() === 429 && $limitedMembers->as_error()?->get_error_code() === 'crcal_members_rate_limited', 'la recherche de membres est limitée à 60 requêtes par minute et par utilisateur');
wp_set_current_user($userIds['member_two']);
$otherUserMembers = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/members'));
$assert($otherUserMembers->get_status() === 200, 'la limite de recherche de membres est propre à chaque utilisateur');
wp_set_current_user($userIds['member_one']);
delete_transient($memberRateKey);

// Setting "Noms des membres" switched off: only the public display name is shown and searched.
update_option(Plugin::SETTINGS_OPTION, array_merge(Plugin::settings(), ['show_member_names' => false]), false);
update_user_meta($beyondUserId, 'first_name', 'Zoé');
update_user_meta($beyondUserId, 'last_name', 'Vingtième');
update_user_meta($beyondUserId, 'billing_first_name', 'Zia');
update_user_meta($beyondUserId, 'billing_last_name', 'Facturée');
$offSearch = new WP_REST_Request('GET', '/community-race-calendar/v1/members');
$offSearch->set_param('search', 'Vingtième');
$assert(count(rest_do_request($offSearch)->get_data()['members'] ?? []) === 0, 'réglage désactivé : pas de recherche par nom de famille');
$offSearch->set_param('search', 'Facturée');
$assert(count(rest_do_request($offSearch)->get_data()['members'] ?? []) === 0, 'réglage désactivé : pas de recherche par nom de facturation');
$offSearch->set_param('search', 'au-delà de vingt');
$offMembers = rest_do_request($offSearch)->get_data()['members'] ?? [];
$assert(count($offMembers) === 1 && ($offMembers[0]['displayName'] ?? '') === 'ZZZ Actif au-delà de vingt' && ($offMembers[0]['label'] ?? '') === 'ZZZ Actif au-delà de vingt', 'réglage désactivé : recherche et affichage par le nom public uniquement');
$assert(\CommunityRaceCalendar\Domain\MemberName::of($beyondUserId, 'pseudo') === 'pseudo', 'réglage désactivé : aucun repli sur le nom de facturation ni sur le profil');
$offReview = rest_do_request($reviewRequest)->get_data()['review'] ?? [];
$assert(!str_contains((string) ($offReview['courses'][0]['participants'][0]['displayName'] ?? ''), 'Durand') && ($offReview['courses'][0]['participants'][0]['displayName'] ?? '') !== 'Camille Durand', 'réglage désactivé : la revue affiche le nom public');
$assert(\CommunityRaceCalendar\Domain\MemberName::of(0, null) === 'Utilisateur supprimé', 'réglage désactivé : un compte supprimé reste signalé');
delete_user_meta($beyondUserId, 'first_name');
delete_user_meta($beyondUserId, 'last_name');
delete_user_meta($beyondUserId, 'billing_first_name');
delete_user_meta($beyondUserId, 'billing_last_name');
// Update path for the names setting: settings saved before the option existed keep the names.
$legacyNames = Plugin::settings();
unset($legacyNames['show_member_names'], $legacyNames['map_enabled']);
update_option(Plugin::SETTINGS_OPTION, $legacyNames, false);
$assert(Plugin::settings()['show_member_names'] === true, 'mise à jour : une installation existante garde l’affichage des prénoms et noms');
delete_option(Plugin::SETTINGS_OPTION);
delete_option(Migrator::OPTION);
Plugin::activate();
$assert((get_option(Plugin::SETTINGS_OPTION, [])['show_member_names'] ?? null) === false, 'nouvelle installation : prénoms et noms désactivés');
update_option(Migrator::OPTION, '1.7.0', false);
delete_option(Plugin::SETTINGS_OPTION);
Plugin::activate();
$assert((get_option(Plugin::SETTINGS_OPTION, [])['show_member_names'] ?? null) === true, 'installation existante sans réglages : prénoms et noms conservés');
$namesOffSubmitted = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['show_member_names' => '0']));
$namesOnSubmitted = $settingsController->sanitizeSubmittedSettings(array_merge($storedSettings, ['show_member_names' => '1']));
$assert($namesOffSubmitted['show_member_names'] === false && $namesOnSubmitted['show_member_names'] === true, 'le formulaire enregistre la case Noms des membres');
$namesPayload = $storedSettings;
unset($namesPayload['show_member_names']);
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['show_member_names' => true]), false);
wp_set_current_user($userIds['crcal_admin']);
$namesRest = new WP_REST_Request('PUT', '/community-race-calendar/v1/settings');
$namesRest->set_header('content-type', 'application/json');
$namesRest->set_body(wp_json_encode($namesPayload));
$assert(rest_do_request($namesRest)->get_status() === 200 && Plugin::settings()['show_member_names'] === true, 'un client REST qui omet l’option ne désactive pas les noms');
ob_start();
$settingsController->render();
$assert(str_contains((string) ob_get_clean(), 'crc-names-title'), 'le panneau présente le réglage Noms des membres');
wp_set_current_user($userIds['member_one']);
update_option(Plugin::SETTINGS_OPTION, array_merge($storedSettings, ['show_member_names' => true]), false);
$restoreSettingsState();

$registerOther = new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/registrations");
$registerOther->set_header('content-type', 'application/json');
$registerOther->set_body(wp_json_encode(['formatId' => $course['formats'][1]['id'], 'userId' => $userIds['member_two']]));
$assert(rest_do_request($registerOther)->get_status() === 201, 'un adhérent inscrit un autre membre');

wp_set_current_user($userIds['member_two']);
$foreignDelete = rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$course['id']}"));
$assert($foreignDelete->get_status() === 403, 'un membre ne supprime pas la course d’un tiers');

wp_set_current_user($userIds['crcal_admin']);
$adminDelete = rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$course['id']}"));
$assert($adminDelete->get_status() === 403, 'un administrateur ne supprime pas la course d’un tiers');

wp_set_current_user($userIds['member_one']);
$ownerDelete = rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$course['id']}"));
$assert($ownerDelete->get_status() === 204, 'l’auteur supprime sa course même avec des contributions tierces');
$assert(rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/courses/{$course['id']}"))->get_status() === 404, 'une course supprimée disparaît du calendrier et de son détail public');
$deletedList = rest_do_request(new WP_REST_Request('GET', '/community-race-calendar/v1/courses/deleted'));
$deletedCourses = $deletedList->get_data()['courses'] ?? [];
$deletedCourse = array_values(array_filter($deletedCourses, static fn (array $item): bool => (int) $item['id'] === (int) $course['id']))[0] ?? [];
$assert($deletedList->get_status() === 200 && ($deletedCourse['deletedAt'] ?? null) !== null, 'l’auteur retrouve sa course dans les éléments supprimés');
$assert(($deletedCourse['formats'][0]['registrationCount'] ?? 0) > 0 && get_post($finalImageId) !== null && is_file((string) $finalImagePath), 'la suppression réversible conserve inscriptions et logo privé');
$restored = rest_do_request(new WP_REST_Request('POST', "/community-race-calendar/v1/courses/{$course['id']}/restore"));
$assert($restored->get_status() === 200 && ($restored->get_data()['course']['id'] ?? 0) === $course['id'], 'l’auteur restaure sa course');
$assert(rest_do_request(new WP_REST_Request('GET', "/community-race-calendar/v1/courses/{$course['id']}"))->get_status() === 200, 'la course restaurée réapparaît dans le calendrier');

$export = CommunityRaceCalendar\Plugin::exportPersonalData('member-one@example.test', 1);
$assert($export['data'] !== [] && $export['done'] === true, 'l’exporteur WordPress restitue les données calendrier paginées');
$erased = CommunityRaceCalendar\Plugin::erasePersonalData('member-one@example.test', 1);
$assert($erased['items_removed'] && $erased['done'], 'l’effaceur supprime les inscriptions et anonymise commentaires et courses');
$assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Migrator::tables()['carpools'] . ' WHERE user_id = %d', $userIds['member_one'])) === 0, 'l’effaceur supprime les covoiturages personnels');
$assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Migrator::tables()['results'] . ' WHERE user_id = %d', $userIds['member_one'])) === 0, 'l’effaceur supprime les résultats personnels');
$anonymizedCourse = $wpdb->get_row($wpdb->prepare('SELECT created_by FROM ' . Migrator::tables()['courses'] . ' WHERE id = %d', $course['id']), ARRAY_A);
$assert((int) ($anonymizedCourse['created_by'] ?? -1) === 0, 'la course est conservée avec un auteur anonymisé selon la politique documentée');

wp_set_current_user($userIds['crcal_admin']);
$adminDeleteAfterErase = rest_do_request(new WP_REST_Request('DELETE', "/community-race-calendar/v1/courses/{$course['id']}"));
$assert($adminDeleteAfterErase->get_status() === 403, 'l’administrateur ne contourne pas la règle auteur après anonymisation');
$assert(get_post($finalImageId) !== null && is_file((string) $finalImagePath), 'la course conservée après anonymisation garde son logo privé');

echo "MVP backend validé : $assertions assertions.\n";
