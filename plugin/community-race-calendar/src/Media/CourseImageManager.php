<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Media;

use CommunityRaceCalendar\Rest\RoutesController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class CourseImageManager
{
    public const MAX_BYTES = 5 * MB_IN_BYTES;
    public const MAX_WIDTH = 1600;
    public const MAX_HEIGHT = 900;
    public const MAX_SOURCE_DIMENSION = 10000;
    public const MAX_SOURCE_PIXELS = 40_000_000;
    public const QUALITY = 82;
    private const PRIVATE_META = '_crcal_private_filename';
    private const SOURCE_HASH_META = '_crcal_source_sha256';
    private const CLEANUP_OPTION = 'community_race_calendar_image_cleanup_queue';
    private const CLEANUP_LAST_SCAN_OPTION = 'community_race_calendar_image_cleanup_last_scan';
    private const CLEANUP_SCAN_INTERVAL = 6 * HOUR_IN_SECONDS;

    /** @var array<int, string> */
    private array $pendingRestImages = [];

    /** @var array<string, string> */
    private const ALLOWED_MIMES = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** @param array<string, mixed> $file */
    public function validate(array $file): bool|WP_Error
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('crcal_image_upload_failed', __('Le fichier du logo n’a pas pu être envoyé.', 'community-race-calendar'));
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) {
            return $this->error('crcal_image_too_large', __('Le logo doit peser moins de 5 Mo.', 'community-race-calendar'));
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if ($path === '' || $name === '' || !is_readable($path) || !is_file($path)) {
            return $this->error('crcal_invalid_image', __('Le fichier du logo est invalide.', 'community-race-calendar'));
        }

        $checked = wp_check_filetype_and_ext($path, $name, self::ALLOWED_MIMES);
        $info = @getimagesize($path);
        $imageType = function_exists('exif_imagetype') ? @exif_imagetype($path) : false;
        $detectedMime = is_int($imageType) ? image_type_to_mime_type($imageType) : (string) ($info['mime'] ?? '');
        if (!is_array($info) || empty($checked['type']) || !in_array($checked['type'], self::ALLOWED_MIMES, true) || $detectedMime !== $checked['type']) {
            return $this->error('crcal_invalid_image_type', __('Utilisez un logo JPG, PNG ou WebP valide.', 'community-race-calendar'));
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION || $width * $height > self::MAX_SOURCE_PIXELS) {
            return $this->error('crcal_image_dimensions_exceeded', __('Les dimensions du logo sont trop importantes.', 'community-race-calendar'));
        }
        $directory = $this->privateDirectory();
        return $directory instanceof WP_Error ? $directory : true;
    }

    /** @param array<string, mixed> $file */
    public function upload(array $file, string $courseName): int|WP_Error
    {
        $valid = $this->validate($file);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $sourceHash = hash_file('sha256', (string) $file['tmp_name']) ?: '';
        $existing = $sourceHash !== '' ? $this->reusableImageByHash($sourceHash) : 0;
        if ($existing > 0) {
            return $existing;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $optimized = $this->optimize($file);
        if (is_wp_error($optimized)) {
            return $optimized;
        }

        $path = (string) $optimized['path'];
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $optimized['mime'],
            'post_title' => $courseName,
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => '',
        ], $path, 0, true);
        if (is_wp_error($attachmentId)) {
            wp_delete_file($path);
            return new WP_Error('crcal_image_upload_failed', __('Le logo n’a pas pu être enregistré.', 'community-race-calendar'), ['status' => 500]);
        }

        update_attached_file((int) $attachmentId, $path);
        update_post_meta((int) $attachmentId, self::PRIVATE_META, basename($path));
        update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $courseName);
        update_post_meta((int) $attachmentId, '_crcal_managed_course_image', '1');
        if ($sourceHash !== '') {
            update_post_meta((int) $attachmentId, self::SOURCE_HASH_META, $sourceHash);
        }
        wp_update_attachment_metadata((int) $attachmentId, [
            'width' => $optimized['width'],
            'height' => $optimized['height'],
            'file' => basename($path),
            'sizes' => [],
            'image_meta' => [],
        ]);
        return (int) $attachmentId;
    }

    /** @param array<string, mixed> $file @return array{path: string, mime: string, width: int, height: int}|WP_Error */
    private function optimize(array $file): array|WP_Error
    {
        $sourcePath = (string) ($file['tmp_name'] ?? '');
        $sourceMime = (string) wp_get_image_mime($sourcePath);
        $editor = wp_get_image_editor($sourcePath, ['mime_type' => $sourceMime]);
        if (is_wp_error($editor)) {
            return new WP_Error('crcal_image_optimization_failed', __('L’image est corrompue ou ne peut pas être décodée.', 'community-race-calendar'), ['status' => 400]);
        }
        $dimensions = $editor->get_size();
        if (($dimensions['width'] ?? 0) > self::MAX_WIDTH || ($dimensions['height'] ?? 0) > self::MAX_HEIGHT) {
            $resized = $editor->resize(self::MAX_WIDTH, self::MAX_HEIGHT, false);
            if (is_wp_error($resized)) {
                return new WP_Error('crcal_image_optimization_failed', __('L’image n’a pas pu être redimensionnée.', 'community-race-calendar'), ['status' => 500]);
            }
        }
        $quality = $editor->set_quality(self::QUALITY);
        if (is_wp_error($quality)) {
            return new WP_Error('crcal_image_optimization_failed', __('L’image n’a pas pu être optimisée.', 'community-race-calendar'), ['status' => 500]);
        }

        $outputMime = wp_image_editor_supports(['mime_type' => 'image/webp']) ? 'image/webp' : $sourceMime;
        $extension = match ($outputMime) {
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => '',
        };
        $directory = $this->privateDirectory();
        if ($extension === '' || is_wp_error($directory)) {
            return is_wp_error($directory) ? $directory : new WP_Error('crcal_image_optimization_failed', __('Format de sortie indisponible.', 'community-race-calendar'), ['status' => 500]);
        }
        $destination = trailingslashit($directory) . wp_unique_filename($directory, wp_generate_password(32, false, false) . '.' . $extension);
        $saved = $editor->save($destination, $outputMime);
        if (is_wp_error($saved) || !is_file($destination)) {
            if (is_file($destination)) {
                wp_delete_file($destination);
            }
            return new WP_Error('crcal_image_optimization_failed', __('L’image n’a pas pu être optimisée.', 'community-race-calendar'), ['status' => 500]);
        }
        $actual = @getimagesize($destination);
        if (!is_array($actual) || ($actual['mime'] ?? '') !== $outputMime) {
            wp_delete_file($destination);
            return new WP_Error('crcal_image_optimization_failed', __('Le fichier image optimisé est invalide.', 'community-race-calendar'), ['status' => 500]);
        }
        return ['path' => $destination, 'mime' => $outputMime, 'width' => (int) $actual[0], 'height' => (int) $actual[1]];
    }

    public function linkToCourse(int $attachmentId, int $courseId): void
    {
        add_post_meta($attachmentId, '_crcal_course_id', $courseId, true);
    }

    public function migrateLegacyImages(): bool|WP_Error
    {
        global $wpdb;
        do {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT managed.post_id FROM {$wpdb->postmeta} managed LEFT JOIN {$wpdb->postmeta} private ON private.post_id = managed.post_id AND private.meta_key = %s WHERE managed.meta_key = %s AND managed.meta_value = '1' AND private.post_id IS NULL LIMIT 500",
                self::PRIVATE_META,
                '_crcal_managed_course_image'
            )) ?: [];
            foreach (array_map('intval', $ids) as $attachmentId) {
                $path = $this->privatePath($attachmentId, true);
                if (is_wp_error($path)) {
                    return $path;
                }
            }
        } while (count($ids) === 500);
        return true;
    }

    public function deleteManaged(int $attachmentId, ?int $courseId = null): bool
    {
        if (!$this->isManaged($attachmentId)) {
            return $attachmentId < 1;
        }
        if ($this->isReferencedByAnyCourse($attachmentId)) {
            return true;
        }
        if ($courseId !== null && (int) get_post_meta($attachmentId, '_crcal_course_id', true) !== $courseId) {
            return false;
        }
        $path = $this->privatePath($attachmentId, false);
        if (!is_wp_error($path) && is_file($path)) {
            wp_delete_file($path);
            if (is_file($path)) {
                return false;
            }
        }
        return wp_delete_attachment($attachmentId, true) !== false;
    }

    public function queueCleanup(int $attachmentId): void
    {
        if ($attachmentId < 1 || !$this->isManaged($attachmentId)) {
            return;
        }
        $queued = array_map('absint', (array) get_option(self::CLEANUP_OPTION, []));
        $queued[] = $attachmentId;
        update_option(self::CLEANUP_OPTION, array_values(array_unique(array_filter($queued))), false);
    }

    public function cleanupOrphanedImages(): bool|WP_Error
    {
        global $wpdb;
        $queued = array_map('absint', (array) get_option(self::CLEANUP_OPTION, []));
        $orphans = [];
        $lastScan = (int) get_option(self::CLEANUP_LAST_SCAN_OPTION, 0);
        if ($queued === [] && $lastScan <= time() - self::CLEANUP_SCAN_INTERVAL) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
            $courses = \CommunityRaceCalendar\Database\Migrator::tables()['courses'];
            $orphanRows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT managed.post_id
                 FROM {$wpdb->postmeta} managed
                 INNER JOIN {$wpdb->posts} posts ON posts.ID = managed.post_id
                 LEFT JOIN %i courses ON courses.image_id = managed.post_id AND courses.status = 'published'
                 WHERE managed.meta_key = %s AND managed.meta_value = '1'
                   AND courses.id IS NULL AND posts.post_date_gmt < %s
                 LIMIT 200",
                $courses,
                '_crcal_managed_course_image',
                $cutoff
            ));
            if (!is_array($orphanRows)) {
                return new WP_Error(
                    'crcal_private_image_cleanup_query_failed',
                    __('Le contrôle des anciennes images privées a échoué.', 'community-race-calendar'),
                    ['status' => 500]
                );
            }
            $orphans = $orphanRows;
            update_option(self::CLEANUP_LAST_SCAN_OPTION, time(), false);
        }
        $pending = array_values(array_unique(array_filter(array_merge($queued, array_map('absint', $orphans)))));
        $failed = [];
        foreach ($pending as $attachmentId) {
            if ($this->isReferencedByPublishedCourse($attachmentId)) {
                continue;
            }
            if (!$this->deleteManaged($attachmentId)) {
                $failed[] = $attachmentId;
            }
        }
        if ($failed === []) {
            delete_option(self::CLEANUP_OPTION);
            return true;
        }
        update_option(self::CLEANUP_OPTION, $failed, false);
        return new WP_Error(
            'crcal_private_image_cleanup_failed',
            __('Certaines anciennes images privées n’ont pas pu être supprimées.', 'community-race-calendar'),
            ['status' => 500]
        );
    }

    public function isManaged(int $attachmentId): bool
    {
        return $attachmentId > 0 && get_post_meta($attachmentId, '_crcal_managed_course_image', true) === '1';
    }

    /** @return array<string, mixed>|null */
    public function representation(int $attachmentId, string $courseName): ?array
    {
        if (!$this->isManaged($attachmentId) || !$this->isReferencedByPublishedCourse($attachmentId)) {
            return null;
        }
        $path = $this->privatePath($attachmentId, true);
        if (is_wp_error($path) || !is_file($path)) {
            return null;
        }
        $metadata = wp_get_attachment_metadata($attachmentId) ?: [];
        $alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
        $url = add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), rest_url(RoutesController::NAMESPACE . '/images/' . $attachmentId));
        return [
            'id' => $attachmentId,
            'url' => $url,
            'cardUrl' => $url,
            'srcSet' => null,
            'alt' => $alt !== '' ? $alt : $courseName,
            'width' => (int) ($metadata['width'] ?? 0),
            'height' => (int) ($metadata['height'] ?? 0),
        ];
    }

    public function imageResponse(int $attachmentId): WP_REST_Response|WP_Error
    {
        if (!$this->isManaged($attachmentId) || !$this->isReferencedByPublishedCourse($attachmentId)) {
            return new WP_Error('crcal_image_not_found', __('Image introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $path = $this->privatePath($attachmentId, true);
        if (is_wp_error($path) || !is_file($path)) {
            return new WP_Error('crcal_image_not_found', __('Image introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $this->pendingRestImages[$attachmentId] = $path;
        $response = new WP_REST_Response(null);
        $response->header('Content-Type', (string) get_post_mime_type($attachmentId));
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public function serveRestImage(bool $served, mixed $result, WP_REST_Request $request): bool
    {
        if ($served || !$result instanceof WP_REST_Response) {
            return $served;
        }
        if (!preg_match('#^/' . preg_quote(RoutesController::NAMESPACE, '#') . '/images/(\d+)$#', $request->get_route(), $matches)) {
            return $served;
        }
        $attachmentId = (int) $matches[1];
        $path = $this->pendingRestImages[$attachmentId] ?? '';
        unset($this->pendingRestImages[$attachmentId]);
        $directory = $this->privateDirectory();
        $resolvedPath = $path !== '' ? realpath($path) : false;
        $resolvedDirectory = !is_wp_error($directory) ? realpath($directory) : false;
        if ($resolvedDirectory === false || $resolvedPath === false) {
            return $served;
        }
        $resolvedPath = wp_normalize_path($resolvedPath);
        $resolvedDirectory = wp_normalize_path($resolvedDirectory);
        if (!str_starts_with($resolvedPath, trailingslashit($resolvedDirectory)) || !is_file($resolvedPath)) {
            return $served;
        }
        $contents = $this->filesystem()->get_contents($resolvedPath);
        if (!is_string($contents)) {
            return $served;
        }
        foreach ($result->get_headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Length: ' . (string) strlen($contents));
        // Raw image bytes: HTML escaping would corrupt the file. The path is confined to the private image directory above.
        echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return true;
    }

    public function blockAttachmentRest(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        if (!preg_match('#^/wp/v2/media(?:/(\d+))?$#', $request->get_route(), $matches)) {
            return $result;
        }
        if (!empty($matches[1]) && $this->isManaged((int) $matches[1]) && (
            !\CommunityRaceCalendar\Plugin::currentUserHasAccess()
            || !$this->isReferencedByPublishedCourse((int) $matches[1])
        )) {
            return new WP_Error('crcal_private_media', __('Ce média est privé.', 'community-race-calendar'), ['status' => 404]);
        }
        return $result;
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function filterAttachmentQuery(array $args): array
    {
        if (\CommunityRaceCalendar\Plugin::currentUserHasAccess()) {
            $orphans = $this->orphanedManagedIds();
            if ($orphans !== []) {
                $args['post__not_in'] = array_values(array_unique(array_merge(
                    array_map('absint', is_array($args['post__not_in'] ?? null) ? $args['post__not_in'] : []),
                    $orphans
                )));
            }
            return $args;
        }
        $args['meta_query'] = array_merge(is_array($args['meta_query'] ?? null) ? $args['meta_query'] : [], [[
            'key' => '_crcal_managed_course_image',
            'compare' => 'NOT EXISTS',
        ]]);
        return $args;
    }

    /** @return array<int, int> */
    private function orphanedManagedIds(): array
    {
        global $wpdb;
        $courses = \CommunityRaceCalendar\Database\Migrator::tables()['courses'];
        return array_map('absint', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT managed.post_id
             FROM {$wpdb->postmeta} managed
             LEFT JOIN %i courses ON courses.image_id = managed.post_id AND courses.status = 'published'
             WHERE managed.meta_key = %s AND managed.meta_value = '1' AND courses.id IS NULL",
            $courses,
            '_crcal_managed_course_image'
        )) ?: []);
    }

    public function protectAttachmentPage(): void
    {
        if (!is_attachment() || \CommunityRaceCalendar\Plugin::currentUserHasAccess()) {
            return;
        }
        $attachmentId = (int) get_queried_object_id();
        if (!$this->isManaged($attachmentId)) {
            return;
        }
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        exit;
    }

    private function privatePath(int $attachmentId, bool $migrateLegacy): string|WP_Error
    {
        $privateName = basename((string) get_post_meta($attachmentId, self::PRIVATE_META, true));
        $directory = $this->privateDirectory();
        if (is_wp_error($directory)) {
            return $directory;
        }
        if ($privateName !== '') {
            return trailingslashit($directory) . $privateName;
        }
        if (!$migrateLegacy) {
            return new WP_Error('crcal_private_image_missing', __('Le fichier privé est absent.', 'community-race-calendar'));
        }
        $legacy = get_attached_file($attachmentId, true);
        if (!$legacy || !is_file($legacy)) {
            return new WP_Error('crcal_private_image_missing', __('Le fichier source est absent.', 'community-race-calendar'));
        }
        $extension = pathinfo($legacy, PATHINFO_EXTENSION);
        $destination = trailingslashit($directory) . wp_unique_filename($directory, wp_generate_password(32, false, false) . '.' . sanitize_key($extension));
        if (!$this->filesystem()->move($legacy, $destination)) {
            if (is_file($destination)) {
                wp_delete_file($destination);
            }
            return new WP_Error('crcal_private_image_migration_failed', __('Impossible de déplacer une image historique vers le stockage privé.', 'community-race-calendar'));
        }
        $metadata = wp_get_attachment_metadata($attachmentId) ?: [];
        $legacyDirectory = dirname($legacy);
        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            $derivative = $legacyDirectory . '/' . basename((string) ($size['file'] ?? ''));
            if (is_file($derivative)) {
                wp_delete_file($derivative);
            }
        }
        $metadata['file'] = basename($destination);
        $metadata['sizes'] = [];
        update_attached_file($attachmentId, $destination);
        update_post_meta($attachmentId, self::PRIVATE_META, basename($destination));
        wp_update_attachment_metadata($attachmentId, $metadata);
        return $destination;
    }

    /** Un logo réutilisable est géré par le plugin et affiché par au moins une course visible. */
    public function isReusable(int $attachmentId): bool
    {
        if (!$this->isManaged($attachmentId)) {
            return false;
        }
        global $wpdb;
        $table = \CommunityRaceCalendar\Database\Migrator::tables()['courses'];
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE image_id = %d AND status = 'published' AND deleted_at IS NULL",
            $table,
            $attachmentId
        )) > 0;
    }

    private function reusableImageByHash(string $sourceHash): int
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 20",
            self::SOURCE_HASH_META,
            $sourceHash
        )) ?: [];
        foreach (array_map('intval', $ids) as $attachmentId) {
            if ($this->isReusable($attachmentId)) {
                $path = $this->privatePath($attachmentId, true);
                if (!is_wp_error($path) && is_file($path)) {
                    return $attachmentId;
                }
            }
        }
        return 0;
    }

    /** Toute course, même dans la corbeille, protège son logo contre la suppression. */
    private function isReferencedByAnyCourse(int $attachmentId): bool
    {
        global $wpdb;
        $table = \CommunityRaceCalendar\Database\Migrator::tables()['courses'];
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE image_id = %d', $table, $attachmentId)) > 0;
    }

    private function isReferencedByPublishedCourse(int $attachmentId): bool
    {
        if ($attachmentId < 1) {
            return false;
        }
        global $wpdb;
        $table = \CommunityRaceCalendar\Database\Migrator::tables()['courses'];
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE image_id = %d AND status = 'published'",
            $table,
            $attachmentId
        )) > 0;
    }

    private function privateDirectory(): string|WP_Error
    {
        $environmentDirectory = getenv('CRCAL_PRIVATE_UPLOAD_DIR');
        $configured = defined('CRCAL_PRIVATE_UPLOAD_DIR')
            ? (string) CRCAL_PRIVATE_UPLOAD_DIR
            : ($environmentDirectory !== false && $environmentDirectory !== '' ? $environmentDirectory : '');
        $requiresPrivateLocation = $configured !== '';
        if (!$requiresPrivateLocation) {
            $uploads = wp_upload_dir();
            if (!empty($uploads['error']) || empty($uploads['basedir'])) {
                return new WP_Error('crcal_image_storage_unavailable', __('Le logo ne peut pas être enregistré pour le moment.', 'community-race-calendar'), ['status' => 503]);
            }
            $configured = trailingslashit((string) $uploads['basedir']) . 'community-race-calendar';
        }
        $directory = wp_normalize_path($configured);
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return new WP_Error('crcal_image_storage_unavailable', __('Le logo ne peut pas être enregistré pour le moment.', 'community-race-calendar'), ['status' => 503]);
        }
        $this->ensureDirectoryIndex($directory);
        $resolved = wp_normalize_path((string) realpath($directory));
        $webRoot = trailingslashit(wp_normalize_path((string) realpath(ABSPATH)));
        if ($resolved === '' || !$this->filesystem()->is_writable($resolved)) {
            return new WP_Error('crcal_image_storage_unavailable', __('Le logo ne peut pas être enregistré pour le moment.', 'community-race-calendar'), ['status' => 503]);
        }
        if ($requiresPrivateLocation && str_starts_with(trailingslashit($resolved), $webRoot)) {
            return new WP_Error('crcal_private_storage_unsafe', __('Le dossier privé configuré pour les logos doit être situé hors de la racine WordPress.', 'community-race-calendar'), ['status' => 503]);
        }
        return $resolved;
    }

    /**
     * Drops an empty index.php in the storage folder so that servers with directory listing enabled
     * do not list the stored logos. Files remain reachable by their exact URL, hence the random names.
     */
    private function ensureDirectoryIndex(string $directory): void
    {
        $index = trailingslashit($directory) . 'index.php';
        if (!is_file($index)) {
            $this->filesystem()->put_contents($index, "<?php\n// Silence is golden.\n", 0644);
        }
    }

    /**
     * Local-disk access through the WordPress filesystem API. The direct driver is used on purpose:
     * these files live on the web server itself and must never go through FTP or SSH credentials.
     */
    private function filesystem(): \WP_Filesystem_Direct
    {
        if (!class_exists('WP_Filesystem_Base')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        }
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
        return new \WP_Filesystem_Direct(null);
    }

    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error($code, $message, ['status' => 400]);
    }
}
