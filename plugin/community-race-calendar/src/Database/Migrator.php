<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Database;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class Migrator
{
    public const OPTION = 'community_race_calendar_schema_version';
    public const ERROR_OPTION = 'community_race_calendar_migration_error';
    private const LOCK_OPTION = 'community_race_calendar_migration_lock';
    private const LOCK_TTL = 300;

    /** @return array<string, string> */
    public static function tables(): array
    {
        global $wpdb;
        return [
            'courses' => $wpdb->prefix . 'community_race_courses',
            'formats' => $wpdb->prefix . 'community_race_formats',
            'registrations' => $wpdb->prefix . 'community_race_registrations',
            'comments' => $wpdb->prefix . 'community_race_comments',
            'carpools' => $wpdb->prefix . 'community_race_carpools',
            'results' => $wpdb->prefix . 'community_race_results',
        ];
    }

    public static function run(): bool|WP_Error
    {
        $lock = (int) get_option(self::LOCK_OPTION, 0);
        if ($lock > 0 && $lock > time() - self::LOCK_TTL) {
            return self::fail('crcal_migration_locked', __('Une migration du calendrier est déjà en cours.', 'community-race-calendar'));
        }
        delete_option(self::LOCK_OPTION);
        if (!add_option(self::LOCK_OPTION, time(), '', false)) {
            return self::fail('crcal_migration_locked', __('Impossible de verrouiller la migration du calendrier.', 'community-race-calendar'));
        }

        try {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $tables = self::tables();
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$tables['courses']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(191) NOT NULL,
                event_date date NOT NULL,
                location varchar(191) NOT NULL,
                latitude decimal(10,7) NULL,
                longitude decimal(10,7) NULL,
                event_url text NULL,
                image_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'published',
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                deleted_at datetime NULL,
                legacy_source varchar(32) NULL,
                legacy_id varchar(191) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_date_status (event_date,status),
                KEY created_by (created_by),
                KEY deleted_creator (deleted_at,created_by),
                UNIQUE KEY legacy_reference (legacy_source,legacy_id)
            ) $charset;

            CREATE TABLE {$tables['formats']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                course_id bigint(20) unsigned NOT NULL,
                label varchar(100) NOT NULL,
                sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY course_label (course_id,label),
                KEY course_order (course_id,sort_order)
            ) $charset;

            CREATE TABLE {$tables['registrations']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                course_id bigint(20) unsigned NOT NULL,
                format_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                registered_by bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY format_user (format_id,user_id),
                KEY course_id (course_id),
                KEY user_id (user_id)
            ) $charset;

            CREATE TABLE {$tables['comments']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                course_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                body text NOT NULL,
                created_at datetime NOT NULL,
                deleted_at datetime NULL,
                PRIMARY KEY  (id),
                KEY course_deleted (course_id,deleted_at),
                KEY user_id (user_id)
            ) $charset;

            CREATE TABLE {$tables['carpools']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                course_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                type varchar(12) NOT NULL,
                seats tinyint(3) unsigned NOT NULL DEFAULT 0,
                departure varchar(191) NULL,
                note varchar(500) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY course_user (course_id,user_id),
                KEY course_type (course_id,type),
                KEY user_id (user_id)
            ) $charset;

            CREATE TABLE {$tables['results']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                course_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                format_id bigint(20) unsigned NULL,
                time_text varchar(64) NULL,
                ranking_text varchar(100) NULL,
                distance_text varchar(64) NULL,
                note varchar(500) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY course_user (course_id,user_id),
                KEY course_id (course_id),
                KEY format_id (format_id),
                KEY user_id (user_id)
            ) $charset;";

            dbDelta($sql);
            $legacySupportTable = $wpdb->prefix . 'community_race_support';
            if ($wpdb->query("DROP TABLE IF EXISTS `$legacySupportTable`") === false) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return self::fail('crcal_legacy_support_removal_failed', __('Impossible de retirer l’ancienne table de ravitaillement.', 'community-race-calendar'));
            }
            $verified = self::verifySchema();
            $verified = apply_filters('community_race_calendar_migration_verification', $verified);
            if (is_wp_error($verified)) {
                return self::fail($verified->get_error_code(), $verified->get_error_message());
            }
            if ($verified !== true) {
                return self::fail('crcal_schema_incomplete', __('Le schéma du calendrier est incomplet après migration.', 'community-race-calendar'));
            }

            if (!update_option(self::OPTION, CRCAL_SCHEMA_VERSION, false)) {
                if (get_option(self::OPTION) !== CRCAL_SCHEMA_VERSION) {
                    return self::fail('crcal_schema_version_write_failed', __('Le schéma est valide mais sa version n’a pas pu être enregistrée.', 'community-race-calendar'));
                }
            }
            delete_option(self::ERROR_OPTION);
            return true;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    public static function verifySchema(): bool|WP_Error
    {
        global $wpdb;
        $expected = [
            'courses' => ['id', 'name', 'event_date', 'location', 'latitude', 'longitude', 'event_url', 'image_id', 'status', 'created_by', 'deleted_at', 'created_at', 'updated_at'],
            'formats' => ['id', 'course_id', 'label', 'sort_order'],
            'registrations' => ['id', 'course_id', 'format_id', 'user_id', 'registered_by', 'created_at'],
            'comments' => ['id', 'course_id', 'user_id', 'body', 'created_at', 'deleted_at'],
            'carpools' => ['id', 'course_id', 'user_id', 'type', 'seats', 'departure', 'note', 'created_at', 'updated_at'],
            'results' => ['id', 'course_id', 'user_id', 'format_id', 'time_text', 'ranking_text', 'distance_text', 'note', 'created_at', 'updated_at'],
        ];
        $indexes = [
            'courses' => ['PRIMARY', 'event_date_status', 'created_by', 'deleted_creator', 'legacy_reference'],
            'formats' => ['PRIMARY', 'course_label', 'course_order'],
            'registrations' => ['PRIMARY', 'format_user', 'course_id', 'user_id'],
            'comments' => ['PRIMARY', 'course_deleted', 'user_id'],
            'carpools' => ['PRIMARY', 'course_user', 'course_type', 'user_id'],
            'results' => ['PRIMARY', 'course_user', 'course_id', 'format_id', 'user_id'],
        ];

        foreach (self::tables() as $key => $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                /* translators: %s: database table name. */
                return new WP_Error('crcal_schema_incomplete', sprintf(__('Table manquante : %s.', 'community-race-calendar'), $table));
            }
            $columns = $wpdb->get_col("DESCRIBE `$table`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $missingColumns = array_diff($expected[$key], $columns ?: []);
            $presentIndexes = $wpdb->get_col("SHOW INDEX FROM `$table`", 2); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $missingIndexes = array_diff($indexes[$key], array_unique($presentIndexes ?: []));
            if ($missingColumns !== [] || $missingIndexes !== []) {
                return new WP_Error('crcal_schema_incomplete', sprintf(
                    /* translators: 1: table name, 2: missing columns, 3: missing indexes. */
                    __('Schéma incomplet pour %1$s. Colonnes manquantes : %2$s. Index manquants : %3$s.', 'community-race-calendar'),
                    $table,
                    implode(', ', $missingColumns) ?: __('aucune', 'community-race-calendar'),
                    implode(', ', $missingIndexes) ?: __('aucun', 'community-race-calendar')
                ));
            }
        }
        return true;
    }

    public static function dependencyError(): ?WP_Error
    {
        static $checked = false;
        static $error = null;
        if ($checked) {
            return $error;
        }
        $checked = true;
        if (get_option(self::OPTION) !== CRCAL_SCHEMA_VERSION) {
            return $error = new WP_Error('crcal_schema_upgrade_required', __('Le calendrier est temporairement indisponible : une migration administrateur est requise.', 'community-race-calendar'), ['status' => 503]);
        }
        $verified = self::verifySchema();
        if (is_wp_error($verified)) {
            return $error = new WP_Error('crcal_schema_incomplete', $verified->get_error_message(), ['status' => 503]);
        }
        return null;
    }

    private static function fail(string $code, string $message): WP_Error
    {
        update_option(self::ERROR_OPTION, ['code' => $code, 'message' => $message, 'time' => time()], false);
        return new WP_Error($code, $message, ['status' => 503]);
    }
}
