<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Run inside a closure so that no variable leaks into the global scope.
(static function (): void {
    $settings = get_option('community_race_calendar_settings', []);
    $administrator = get_role('administrator');
    if ($administrator) {
        $administrator->remove_cap('manage_community_race_calendar');
    }
    delete_option('community_race_calendar_geoapify_api_key');
    delete_option('community_race_calendar_image_cleanup_queue');
    delete_option('community_race_calendar_image_cleanup_last_scan');
    delete_option('community_race_calendar_season_cache_version');
    global $wpdb;
    $ratePattern = $wpdb->esc_like('crcal_rate_v2_') . '%';
    $cleanupPattern = $wpdb->esc_like('crcal_rate_cleanup_') . '%';
    $seasonPattern = $wpdb->esc_like('_transient_crcal_season_') . '%';
    $seasonTimeoutPattern = $wpdb->esc_like('_transient_timeout_crcal_season_') . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $ratePattern,
        $cleanupPattern,
        $seasonPattern,
        $seasonTimeoutPattern
    )); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    if (empty($settings['delete_data_on_uninstall'])) {
        return;
    }

    $imageIds = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
        '_crcal_managed_course_image'
    ));
    foreach (array_map('absint', $imageIds ?: []) as $imageId) {
        $privatePath = get_attached_file($imageId, true);
        if (is_string($privatePath) && is_file($privatePath)) {
            wp_delete_file($privatePath);
            if (is_file($privatePath)) {
                continue;
            }
        }
        wp_delete_attachment($imageId, true);
    }
    foreach (['community_race_comments', 'community_race_carpools', 'community_race_results', 'community_race_registrations', 'community_race_formats', 'community_race_courses'] as $suffix) {
        $table = $wpdb->prefix . $suffix;
        $wpdb->query("DROP TABLE IF EXISTS `$table`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
    delete_option('community_race_calendar_settings');
    delete_option('community_race_calendar_schema_version');
    delete_option('community_race_calendar_migration_error');
    delete_option('community_race_calendar_media_migration_error');
})();
