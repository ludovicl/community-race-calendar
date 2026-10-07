<?php

declare(strict_types=1);

namespace CommunityRaceCalendar;

use CommunityRaceCalendar\Access\AccessGate;
use CommunityRaceCalendar\Access\WooCommerceMembershipsAccessProvider;
use CommunityRaceCalendar\Admin\SettingsPage;
use CommunityRaceCalendar\Database\Migrator;
use CommunityRaceCalendar\Geocoding\GeoapifyGeocodingProvider;
use CommunityRaceCalendar\Media\CourseImageManager;
use CommunityRaceCalendar\Repository\CalendarRepository;
use CommunityRaceCalendar\Rest\RoutesController;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    public const SETTINGS_OPTION = 'community_race_calendar_settings';
    public const GEOAPIFY_API_KEY_OPTION = 'community_race_calendar_geoapify_api_key';

    /** Settings added after the first release: off for new installs, on for existing ones. */
    private const LEGACY_ON_SETTINGS = ['map_enabled', 'show_member_names'];

    private static ?AccessGate $gate = null;

    public static function activate(): void
    {
        // Detect an existing installation before the migration writes the schema version option.
        $existingInstall = get_option(Migrator::OPTION, null) !== null;
        $migrated = Migrator::run();
        if (is_wp_error($migrated)) {
            throw new \RuntimeException(esc_html($migrated->get_error_message()));
        }
        self::ensureCapabilities();
        $stored = get_option(self::SETTINGS_OPTION, null);
        $settings = self::settings();
        foreach (self::LEGACY_ON_SETTINGS as $key) {
            if (!is_array($stored) || !array_key_exists($key, $stored)) {
                // Fresh installs start with these options off; installations that predate an option
                // keep the behavior they already had.
                $settings[$key] = $existingInstall || (is_array($stored) && $stored !== []);
            }
        }
        update_option(self::SETTINGS_OPTION, $settings, false);
    }

    public static function boot(): void
    {
        self::ensureCapabilities();

        self::$gate = new AccessGate(new WooCommerceMembershipsAccessProvider());
        $geocoding = new GeoapifyGeocodingProvider();
        $images = new CourseImageManager();
        $routes = new RoutesController(self::$gate, new CalendarRepository(), $geocoding, $images);
        add_action('rest_api_init', [$routes, 'register']);
        add_action('admin_init', [self::class, 'maybeMigrate']);
        add_action('admin_notices', [self::class, 'migrationNotice']);
        add_shortcode('community_race_calendar', [self::class, 'shortcode']);
        add_filter('wp_robots', [self::class, 'noIndexCalendar']);
        add_filter('wp_headers', [self::class, 'protectCalendarReferrer']);
        add_action('delete_user', [self::class, 'handleDeletedUser']);
        add_filter('rest_pre_serve_request', [$images, 'serveRestImage'], 10, 3);
        add_filter('rest_pre_dispatch', [$images, 'blockAttachmentRest'], 10, 3);
        add_filter('rest_attachment_query', [$images, 'filterAttachmentQuery']);
        add_action('template_redirect', [$images, 'protectAttachmentPage'], 0);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerPrivacyExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerPrivacyEraser']);

        (new SettingsPage(self::$gate, $geocoding))->register();
    }

    public static function maybeMigrate(): void
    {
        if (current_user_can('manage_options') && get_option(Migrator::OPTION) !== CRCAL_SCHEMA_VERSION) {
            Migrator::run();
        }
        if (current_user_can('manage_options') && get_option(Migrator::OPTION) === CRCAL_SCHEMA_VERSION) {
            $manager = new CourseImageManager();
            $migrated = $manager->migrateLegacyImages();
            if (is_wp_error($migrated)) {
                update_option('community_race_calendar_media_migration_error', $migrated->get_error_message(), false);
            } else {
                $cleaned = $manager->cleanupOrphanedImages();
                if (is_wp_error($cleaned)) {
                    update_option('community_race_calendar_media_migration_error', $cleaned->get_error_message(), false);
                } else {
                    delete_option('community_race_calendar_media_migration_error');
                }
            }
        }
    }

    public static function migrationNotice(): void
    {
        $error = get_option(Migrator::ERROR_OPTION);
        $mediaError = get_option('community_race_calendar_media_migration_error');
        if (!current_user_can('manage_options')) {
            return;
        }
        if (is_array($error) && !empty($error['message'])) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Community Race Calendar : migration incomplète.', 'community-race-calendar') . '</strong> ' . esc_html((string) $error['message']) . '</p></div>';
        }
        if (is_string($mediaError) && $mediaError !== '') {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Community Race Calendar : images historiques non protégées.', 'community-race-calendar') . '</strong> ' . esc_html($mediaError) . '</p></div>';
        }
    }

    public static function currentUserHasAccess(): bool
    {
        return self::$gate instanceof AccessGate && self::$gate->hasAccess();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'brand_name' => 'Calendrier des courses',
            'tagline' => 'Les prochaines courses de la communauté',
            'brand_color' => '#1d4ed8',
            'background_color' => '#f5f5f5',
            'surface_color' => '#ffffff',
            'membership_plan_ids' => [],
            'members_can_create' => true,
            'membership_url' => home_url('/mon-compte/'),
            'calendar_page_id' => 0,
            'font_family' => '',
            'content_max_width' => '1200px',
            'text_color' => '#1f2933',
            'map_enabled' => false,
            'show_member_names' => false,
            'delete_data_on_uninstall' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        $stored = get_option(self::SETTINGS_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $merged = array_merge(self::defaults(), $stored);
        foreach (self::LEGACY_ON_SETTINGS as $key) {
            if ($stored !== [] && !array_key_exists($key, $stored)) {
                // Settings saved by a version without this option: keep the earlier behavior.
                $merged[$key] = true;
            }
        }
        return self::sanitizeSettings($merged);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function sanitizeSettings(array $input): array
    {
        $defaults = self::defaults();
        $brandColor = sanitize_hex_color((string) ($input['brand_color'] ?? '')) ?: $defaults['brand_color'];
        $backgroundColor = sanitize_hex_color((string) ($input['background_color'] ?? '')) ?: $defaults['background_color'];
        $surfaceColor = sanitize_hex_color((string) ($input['surface_color'] ?? '')) ?: $defaults['surface_color'];
        if (self::contrastRatio($brandColor, '#ffffff') < 4.5) {
            $brandColor = $defaults['brand_color'];
        }
        $textColor = self::sanitizeTextColor((string) ($input['text_color'] ?? ''), $defaults['text_color']);
        if (self::contrastRatio($backgroundColor, $textColor) < 4.5) {
            $backgroundColor = $defaults['background_color'];
        }
        if (self::contrastRatio($surfaceColor, $textColor) < 4.5) {
            $surfaceColor = $defaults['surface_color'];
        }
        if (self::contrastRatio($backgroundColor, $textColor) < 4.5 || self::contrastRatio($surfaceColor, $textColor) < 4.5) {
            // The text color cannot be read on the (default) surfaces: fall back to the default text color.
            $textColor = $defaults['text_color'];
        }
        $membershipUrl = esc_url_raw((string) ($input['membership_url'] ?? ''), ['http', 'https']);

        return [
            'brand_name' => sanitize_text_field((string) ($input['brand_name'] ?? $defaults['brand_name'])),
            'tagline' => sanitize_text_field((string) ($input['tagline'] ?? $defaults['tagline'])),
            'brand_color' => $brandColor,
            'background_color' => $backgroundColor,
            'surface_color' => $surfaceColor,
            'text_color' => $textColor,
            'font_family' => self::sanitizeFontFamily((string) ($input['font_family'] ?? '')),
            'content_max_width' => self::sanitizeContentWidth((string) ($input['content_max_width'] ?? ''), $defaults['content_max_width']),
            'membership_plan_ids' => array_values(array_filter(array_map('absint', is_array($input['membership_plan_ids'] ?? null) ? $input['membership_plan_ids'] : []))),
            'members_can_create' => self::toBool($input['members_can_create'] ?? false),
            'membership_url' => $membershipUrl ?: $defaults['membership_url'],
            'calendar_page_id' => absint($input['calendar_page_id'] ?? 0),
            'map_enabled' => self::toBool($input['map_enabled'] ?? false),
            'show_member_names' => self::toBool($input['show_member_names'] ?? false),
            'delete_data_on_uninstall' => self::toBool($input['delete_data_on_uninstall'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public static function publicSettings(): array
    {
        $settings = self::settings();
        return [
            'brandName' => $settings['brand_name'],
            'tagline' => $settings['tagline'],
            'brandColor' => $settings['brand_color'],
            'backgroundColor' => $settings['background_color'],
            'surfaceColor' => $settings['surface_color'],
            'textColor' => $settings['text_color'],
            'fontFamily' => $settings['font_family'],
            'contentMaxWidth' => $settings['content_max_width'],
            'membershipUrl' => $settings['membership_url'],
            'mapEnabled' => (bool) $settings['map_enabled'],
        ];
    }

    public static function geoapifyApiKey(): string
    {
        return self::sanitizeGeoapifyApiKey((string) get_option(self::GEOAPIFY_API_KEY_OPTION, ''));
    }

    public static function effectiveGeoapifyApiKey(): string
    {
        $storedKey = self::geoapifyApiKey();
        if ($storedKey !== '') {
            return $storedKey;
        }
        return defined('CRCAL_GEOAPIFY_API_KEY')
            ? self::sanitizeGeoapifyApiKey((string) CRCAL_GEOAPIFY_API_KEY)
            : '';
    }

    public static function sanitizeGeoapifyApiKey(string $key): string
    {
        $key = trim($key);
        return preg_match('/\A[A-Za-z0-9_-]{20,200}\z/D', $key) === 1 ? $key : '';
    }

    public static function calendarUrl(): string
    {
        $pageId = (int) (self::settings()['calendar_page_id'] ?? 0);
        $url = $pageId ? get_permalink($pageId) : false;
        return $url ?: home_url('/calendrier/');
    }

    public static function accountUrl(): string
    {
        $pageId = absint(get_option('woocommerce_myaccount_page_id', 0));
        $url = $pageId > 0 && get_post_status($pageId) === 'publish' ? get_permalink($pageId) : false;
        return is_string($url) && $url !== '' ? $url : wp_login_url(self::calendarUrl());
    }

    public static function shortcode(): string
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();

        $scriptPath = CRCAL_PLUGIN_DIR . 'assets/app.js';
        $stylePath = CRCAL_PLUGIN_DIR . 'assets/app.css';
        if (!is_readable($scriptPath) || !is_readable($stylePath)) {
            return '<div class="crc-fallback" role="alert">' . esc_html__('Les assets du calendrier sont absents. Recréez le ZIP du plugin.', 'community-race-calendar') . '</div>';
        }

        wp_enqueue_style('community-race-calendar', CRCAL_PLUGIN_URL . 'assets/app.css', [], (string) filemtime($stylePath));
        // React comes from WordPress: the application module expects the window.React, ReactDOM and ReactJSXRuntime globals.
        wp_enqueue_script('wp-element');
        wp_enqueue_script('react-jsx-runtime');
        wp_enqueue_script_module('community-race-calendar', CRCAL_PLUGIN_URL . 'assets/app.js', [], (string) filemtime($scriptPath));
        return '<div class="crc-app" data-community-race-calendar data-version="' . esc_attr(CRCAL_VERSION) . '" data-rest-root="' . esc_url(rest_url(RoutesController::NAMESPACE . '/')) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '"><p class="crc-app__loading">' . esc_html__('Chargement du calendrier…', 'community-race-calendar') . '</p></div>';
    }

    /** @param array<string, bool> $robots @return array<string, bool> */
    public static function noIndexCalendar(array $robots): array
    {
        $pageId = (int) (self::settings()['calendar_page_id'] ?? 0);
        if ($pageId && is_page($pageId)) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function protectCalendarReferrer(array $headers): array
    {
        $pageId = (int) (self::settings()['calendar_page_id'] ?? 0);
        if ($pageId && is_page($pageId)) {
            $headers['Referrer-Policy'] = 'no-referrer';
        }
        return $headers;
    }

    public static function handleDeletedUser(int $userId): void
    {
        global $wpdb;
        $tables = Migrator::tables();
        $wpdb->delete($tables['registrations'], ['user_id' => $userId], ['%d']);
        $wpdb->delete($tables['carpools'], ['user_id' => $userId], ['%d']);
        $wpdb->delete($tables['results'], ['user_id' => $userId], ['%d']);
        $wpdb->update($tables['comments'], ['user_id' => 0], ['user_id' => $userId], ['%d'], ['%d']);
        $wpdb->update($tables['courses'], ['created_by' => 0], ['created_by' => $userId], ['%d'], ['%d']);
        CalendarRepository::invalidateSeasonCache();
    }

    /** @param array<string, mixed> $exporters @return array<string, mixed> */
    public static function registerPrivacyExporter(array $exporters): array
    {
        $exporters['community-race-calendar'] = [
            'exporter_friendly_name' => __('Community Race Calendar', 'community-race-calendar'),
            'callback' => [self::class, 'exportPersonalData'],
        ];
        return $exporters;
    }

    /** @return array{data: array<int, array<string, mixed>>, done: bool} */
    public static function exportPersonalData(string $emailAddress, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $emailAddress);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $limit = 50;
        $offset = max(0, $page - 1) * $limit;
        $tables = Migrator::tables();
        $data = [];
        $registrations = $wpdb->get_results($wpdb->prepare("SELECT id, course_id, format_id, created_at FROM {$tables['registrations']} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, $limit, $offset), ARRAY_A) ?: [];
        $comments = $wpdb->get_results($wpdb->prepare("SELECT id, course_id, body, created_at FROM {$tables['comments']} WHERE user_id = %d AND deleted_at IS NULL ORDER BY id LIMIT %d OFFSET %d", $user->ID, $limit, $offset), ARRAY_A) ?: [];
        $carpools = $wpdb->get_results($wpdb->prepare("SELECT id, course_id, type, seats, departure, note, created_at, updated_at FROM {$tables['carpools']} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, $limit, $offset), ARRAY_A) ?: [];
        $results = $wpdb->get_results($wpdb->prepare("SELECT id, course_id, format_id, time_text, ranking_text, distance_text, note, created_at, updated_at FROM {$tables['results']} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, $limit, $offset), ARRAY_A) ?: [];
        $courses = $wpdb->get_results($wpdb->prepare("SELECT id, name, event_date, location, created_at FROM {$tables['courses']} WHERE created_by = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, $limit, $offset), ARRAY_A) ?: [];
        foreach ([__('Inscriptions aux courses', 'community-race-calendar') => $registrations, __('Commentaires de courses', 'community-race-calendar') => $comments, __('Covoiturages', 'community-race-calendar') => $carpools, __('Résultats de courses', 'community-race-calendar') => $results, __('Courses créées', 'community-race-calendar') => $courses] as $group => $rows) {
            foreach ($rows as $row) {
                $itemId = (int) $row['id'];
                $data[] = [
                    'group_id' => 'community-race-calendar',
                    'group_label' => $group,
                    'item_id' => 'crc-' . sanitize_key($group) . '-' . $itemId,
                    'data' => array_map(static fn (string $key, mixed $value): array => ['name' => $key, 'value' => (string) $value], array_keys($row), array_values($row)),
                ];
            }
        }
        return ['data' => $data, 'done' => count($registrations) < $limit && count($comments) < $limit && count($carpools) < $limit && count($results) < $limit && count($courses) < $limit];
    }

    /** @param array<string, mixed> $erasers @return array<string, mixed> */
    public static function registerPrivacyEraser(array $erasers): array
    {
        $erasers['community-race-calendar'] = [
            'eraser_friendly_name' => __('Community Race Calendar', 'community-race-calendar'),
            'callback' => [self::class, 'erasePersonalData'],
        ];
        return $erasers;
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
    public static function erasePersonalData(string $emailAddress, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $emailAddress);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $tables = Migrator::tables();
        $limit = 50;
        $registrationIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['registrations']} WHERE user_id = %d ORDER BY id LIMIT %d", $user->ID, $limit)) ?: []);
        $commentIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['comments']} WHERE user_id = %d ORDER BY id LIMIT %d", $user->ID, $limit)) ?: []);
        $carpoolIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['carpools']} WHERE user_id = %d ORDER BY id LIMIT %d", $user->ID, $limit)) ?: []);
        $resultIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['results']} WHERE user_id = %d ORDER BY id LIMIT %d", $user->ID, $limit)) ?: []);
        $courseIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['courses']} WHERE created_by = %d ORDER BY id LIMIT %d", $user->ID, $limit)) ?: []);
        $removed = false;
        foreach ($registrationIds as $id) {
            $removed = $wpdb->delete($tables['registrations'], ['id' => $id], ['%d']) === 1 || $removed;
        }
        foreach ($commentIds as $id) {
            $removed = $wpdb->update($tables['comments'], ['user_id' => 0], ['id' => $id], ['%d'], ['%d']) === 1 || $removed;
        }
        foreach ($carpoolIds as $id) {
            $removed = $wpdb->delete($tables['carpools'], ['id' => $id], ['%d']) === 1 || $removed;
        }
        foreach ($resultIds as $id) {
            $removed = $wpdb->delete($tables['results'], ['id' => $id], ['%d']) === 1 || $removed;
        }
        foreach ($courseIds as $id) {
            $removed = $wpdb->update($tables['courses'], ['created_by' => 0], ['id' => $id], ['%d'], ['%d']) === 1 || $removed;
        }
        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['registrations']} WHERE user_id = %d", $user->ID))
            + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['comments']} WHERE user_id = %d", $user->ID))
            + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['carpools']} WHERE user_id = %d", $user->ID))
            + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['results']} WHERE user_id = %d", $user->ID))
            + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['courses']} WHERE created_by = %d", $user->ID));
        if ($removed) {
            CalendarRepository::invalidateSeasonCache();
        }
        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => [__('Les inscriptions, covoiturages et résultats sont supprimés ; les commentaires et courses sont anonymisés afin de préserver l’historique communautaire.', 'community-race-calendar')],
            'done' => $remaining === 0,
        ];
    }

    private static function ensureCapabilities(): void
    {
        $role = get_role('administrator');
        if ($role && !$role->has_cap(AccessGate::MANAGE_CAPABILITY)) {
            $role->add_cap(AccessGate::MANAGE_CAPABILITY);
        }
    }

    /**
     * Strict allow-list for the font stack: at most 8 families separated by commas, each one either
     * bare (letters, digits, spaces, hyphens) or wrapped in a matching pair of single or double quotes
     * around the same characters. Anything else, including ; { } ( ) : \ / and url( or expression(, is
     * refused and the setting falls back to the theme font (empty string).
     */
    public static function sanitizeFontFamily(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 200) {
            return '';
        }
        $families = explode(',', $value);
        if (count($families) > 8) {
            return '';
        }
        $clean = [];
        foreach ($families as $family) {
            $family = trim($family);
            if (preg_match('/\A(?:([\'"])([\p{L}\p{N} \-]{1,60})\1|([\p{L}\p{N}\-][\p{L}\p{N} \-]{0,59}))\z/u', $family, $matches) !== 1) {
                return '';
            }
            $name = trim($matches[2] !== '' ? $matches[2] : $matches[3]);
            if ($name === '') {
                return '';
            }
            $clean[] = $matches[2] !== '' ? $matches[1] . $name . $matches[1] : $name;
        }
        return implode(', ', $clean);
    }

    /** Width as a number and a unit among px (320 to 2400), rem (20 to 150) and % (50 to 100). */
    public static function sanitizeContentWidth(string $value, string $default): string
    {
        if (preg_match('/\A(\d{1,4}(?:\.\d{1,2})?)(px|rem|%)\z/', trim($value), $matches) !== 1) {
            return $default;
        }
        $number = (float) $matches[1];
        $bounds = ['px' => [320.0, 2400.0], 'rem' => [20.0, 150.0], '%' => [50.0, 100.0]][$matches[2]];
        if ($number < $bounds[0] || $number > $bounds[1]) {
            return $default;
        }
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') . $matches[2];
    }

    public static function sanitizeTextColor(string $value, string $default): string
    {
        $color = sanitize_hex_color(trim($value));
        return is_string($color) && $color !== '' ? strtolower($color) : $default;
    }

    public static function contrastRatio(string $first, string $second): float
    {
        $firstLuminance = self::relativeLuminance($first);
        $secondLuminance = self::relativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $color): float
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = implode('', array_map(static fn (string $part): string => $part . $part, str_split($hex)));
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 0.0;
        }
        $channels = array_map(static function (string $channel): float {
            $value = hexdec($channel) / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
