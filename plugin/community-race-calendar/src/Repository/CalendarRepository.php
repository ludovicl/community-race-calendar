<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Repository;

use CommunityRaceCalendar\Database\Migrator;
use CommunityRaceCalendar\Domain\MemberName;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class CalendarRepository
{
    private const SEASON_CACHE_VERSION_OPTION = 'community_race_calendar_season_cache_version';
    private const SEASON_CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    private const MAX_SEASON_PARTICIPATIONS = 5000;

    /** @return array{courses: array<int, array<string, mixed>>, hasMore: bool} */
    public function listCourses(string $from, string $to, int $currentUserId, bool $canManage, bool $hasAccess, int $page = 1, int $perPage = 100): array
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $offset = max(0, $page - 1) * $perPage;
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'published' AND deleted_at IS NULL AND event_date BETWEEN %s AND %s ORDER BY event_date ASC, name ASC LIMIT %d OFFSET %d",
            $from,
            $to,
            $perPage + 1,
            $offset
        ), ARRAY_A) ?: [];
        $hasMore = count($courses) > $perPage;
        if ($hasMore) {
            array_pop($courses);
        }
        return ['courses' => $this->hydrate($courses, $currentUserId, $canManage, $hasAccess, false), 'hasMore' => $hasMore];
    }

    /** @return array<string, mixed>|null */
    public function getCourse(int $courseId, int $currentUserId, bool $canManage, bool $hasAccess): ?array
    {
        $course = $this->rawCourse($courseId);
        if (!$course) {
            return null;
        }
        return $this->hydrate([$course], $currentUserId, $canManage, $hasAccess, true)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listDeletedCourses(int $currentUserId, bool $hasAccess): array
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'published' AND deleted_at IS NOT NULL AND created_by = %d ORDER BY deleted_at DESC, id DESC LIMIT 100",
            $currentUserId
        ), ARRAY_A) ?: [];
        return $this->hydrate($courses, $currentUserId, false, $hasAccess, false);
    }

    /** @param array<int, array<string, mixed>> $courses @return array<int, array<string, mixed>> */
    private function hydrate(array $courses, int $currentUserId, bool $canManage, bool $hasAccess, bool $detailed): array
    {
        if ($courses === []) {
            return [];
        }
        global $wpdb;
        $tables = Migrator::tables();
        $ids = array_map('intval', array_column($courses, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $formats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['formats']} WHERE course_id IN ($placeholders) ORDER BY course_id, sort_order, id",
            ...$ids
        ), ARRAY_A) ?: [];

        $formatsByCourse = [];
        foreach ($formats as $format) {
            $courseId = (int) $format['course_id'];
            $formatId = (int) $format['id'];
            $formatsByCourse[$courseId][$formatId] = [
                'id' => $formatId,
                'course_id' => $courseId,
                'label' => $format['label'],
                'sort_order' => (int) $format['sort_order'],
                'registrationCount' => 0,
                'registrations' => [],
            ];
        }

        if ($detailed) {
            $registrations = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.display_name FROM %i r LEFT JOIN %i u ON u.ID = r.user_id WHERE r.course_id IN ($placeholders) ORDER BY r.created_at, r.id",
                $tables['registrations'],
                $wpdb->users,
                ...$ids
            ), ARRAY_A) ?: [];
        } else {
            $registrations = $wpdb->get_results($wpdb->prepare(
                "SELECT course_id, format_id, COUNT(*) AS registration_count, SUM(user_id = %d) AS own_count, SUM(user_id != %d) AS third_party_count FROM %i WHERE course_id IN ($placeholders) GROUP BY course_id, format_id",
                $currentUserId,
                $currentUserId,
                $tables['registrations'],
                ...$ids
            ), ARRAY_A) ?: [];
        }
        if ($detailed) {
            $registrations = MemberName::applyToRows($registrations);
        }
        $ownByCourse = [];
        $thirdPartyByCourse = [];
        foreach ($registrations as $registration) {
            $courseId = (int) $registration['course_id'];
            $formatId = (int) $registration['format_id'];
            if (!isset($formatsByCourse[$courseId][$formatId])) {
                continue;
            }
            if ($detailed) {
                $formatsByCourse[$courseId][$formatId]['registrationCount']++;
                $formatsByCourse[$courseId][$formatId]['registrations'][] = [
                    'id' => (int) $registration['id'],
                    'userId' => (int) $registration['user_id'],
                    'displayName' => $registration['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
                    'createdAt' => mysql_to_rfc3339($registration['created_at']),
                ];
                $ownByCourse[$courseId] = ($ownByCourse[$courseId] ?? false) || (int) $registration['user_id'] === $currentUserId;
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $registration['user_id'] !== $currentUserId;
            } else {
                $formatsByCourse[$courseId][$formatId]['registrationCount'] = (int) $registration['registration_count'];
                $ownByCourse[$courseId] = ($ownByCourse[$courseId] ?? false) || (int) $registration['own_count'] > 0;
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $registration['third_party_count'] > 0;
            }
        }

        $commentsByCourse = [];
        $carpoolsByCourse = [];
        $resultsByCourse = [];
        if ($detailed) {
            $comments = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
                "SELECT c.*, u.display_name FROM {$tables['comments']} c LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.course_id IN ($placeholders) AND c.deleted_at IS NULL ORDER BY c.created_at, c.id",
                ...$ids
            ), ARRAY_A) ?: []);
            foreach ($comments as $comment) {
                $courseId = (int) $comment['course_id'];
                $commentsByCourse[$courseId][] = [
                    'id' => (int) $comment['id'],
                    'userId' => (int) $comment['user_id'],
                    'displayName' => $comment['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
                    'body' => $comment['body'],
                    'createdAt' => mysql_to_rfc3339($comment['created_at']),
                ];
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $comment['user_id'] !== $currentUserId;
            }

            $carpools = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
                "SELECT c.*, u.display_name FROM {$tables['carpools']} c LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.course_id IN ($placeholders) ORDER BY c.type, c.updated_at, c.id",
                ...$ids
            ), ARRAY_A) ?: []);
            foreach ($carpools as $carpool) {
                $courseId = (int) $carpool['course_id'];
                $carpoolsByCourse[$courseId][] = [
                    'id' => (int) $carpool['id'],
                    'userId' => (int) $carpool['user_id'],
                    'displayName' => $carpool['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
                    'type' => $carpool['type'],
                    'seats' => (int) $carpool['seats'],
                    'departure' => $carpool['departure'] ?: null,
                    'note' => $carpool['note'] ?: null,
                    'updatedAt' => mysql_to_rfc3339($carpool['updated_at']),
                ];
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $carpool['user_id'] !== $currentUserId;
            }

            $results = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
                "SELECT r.*, u.display_name, f.label AS format_label FROM {$tables['results']} r LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id LEFT JOIN {$tables['formats']} f ON f.id = r.format_id WHERE r.course_id IN ($placeholders) ORDER BY r.updated_at, r.id",
                ...$ids
            ), ARRAY_A) ?: []);
            foreach ($results as $result) {
                $courseId = (int) $result['course_id'];
                $resultsByCourse[$courseId][] = $this->presentResult($result);
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $result['user_id'] !== $currentUserId;
            }
        } else {
            $commentStats = $wpdb->get_results($wpdb->prepare(
                "SELECT course_id, SUM(user_id != %d) AS third_party_count FROM {$tables['comments']} WHERE course_id IN ($placeholders) AND deleted_at IS NULL GROUP BY course_id",
                ...array_merge([$currentUserId], $ids)
            ), ARRAY_A) ?: [];
            foreach ($commentStats as $stat) {
                $courseId = (int) $stat['course_id'];
                $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $stat['third_party_count'] > 0;
            }
            foreach (['carpools', 'results'] as $tableKey) {
                $stats = $wpdb->get_results($wpdb->prepare(
                    "SELECT course_id, SUM(user_id != %d) AS third_party_count FROM {$tables[$tableKey]} WHERE course_id IN ($placeholders) GROUP BY course_id",
                    ...array_merge([$currentUserId], $ids)
                ), ARRAY_A) ?: [];
                foreach ($stats as $stat) {
                    $courseId = (int) $stat['course_id'];
                    $thirdPartyByCourse[$courseId] = ($thirdPartyByCourse[$courseId] ?? false) || (int) $stat['third_party_count'] > 0;
                }
            }
        }

        return array_map(static function (array $course) use ($formatsByCourse, $commentsByCourse, $carpoolsByCourse, $resultsByCourse, $ownByCourse, $thirdPartyByCourse, $currentUserId, $canManage, $hasAccess, $detailed): array {
            $courseId = (int) $course['id'];
            $isOwner = (int) $course['created_by'] === $currentUserId;
            $isDeleted = !empty($course['deleted_at']);
            return [
                'id' => $courseId,
                'name' => $course['name'],
                'eventDate' => $course['event_date'],
                'location' => $course['location'],
                'latitude' => $course['latitude'] !== null ? (float) $course['latitude'] : null,
                'longitude' => $course['longitude'] !== null ? (float) $course['longitude'] : null,
                'eventUrl' => $course['event_url'] ?: null,
                'imageId' => (int) $course['image_id'],
                'createdBy' => (int) $course['created_by'],
                'createdAt' => mysql_to_rfc3339($course['created_at']),
                'updatedAt' => mysql_to_rfc3339($course['updated_at']),
                'deletedAt' => $isDeleted ? mysql_to_rfc3339($course['deleted_at']) : null,
                'formats' => array_values($formatsByCourse[$courseId] ?? []),
                'comments' => $detailed ? ($commentsByCourse[$courseId] ?? []) : null,
                'carpools' => $detailed ? ($carpoolsByCourse[$courseId] ?? []) : null,
                'results' => $detailed ? ($resultsByCourse[$courseId] ?? []) : null,
                'detailLoaded' => $detailed,
                'permissions' => [
                    'edit' => !$isDeleted && ($canManage || ($isOwner && $hasAccess)),
                    'delete' => !$isDeleted && $isOwner && $hasAccess,
                    'restore' => $isDeleted && $isOwner && $hasAccess,
                    'register' => !$isDeleted && $hasAccess,
                    'comment' => !$isDeleted && $hasAccess,
                    'carpool' => !$isDeleted && $hasAccess,
                    'result' => !$isDeleted && ($ownByCourse[$courseId] ?? false),
                ],
            ];
        }, $courses);
    }

    /** @param array<string, mixed> $data */
    public function createCourse(array $data, int $userId): int|WP_Error
    {
        global $wpdb;
        $tables = Migrator::tables();
        if ($wpdb->query('START TRANSACTION') === false) {
            return $this->databaseError(__('Impossible de démarrer la création de la course.', 'community-race-calendar'));
        }
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($tables['courses'], [
            'name' => $data['name'], 'event_date' => $data['eventDate'], 'location' => $data['location'],
            'latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'event_url' => $data['eventUrl'] ?: null,
            'image_id' => (int) ($data['imageId'] ?? 0), 'status' => 'published', 'created_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ], ['%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%d', '%s', '%s']);
        if ($inserted !== 1) {
            $wpdb->query('ROLLBACK');
            return $this->databaseError(__('Impossible de créer la course.', 'community-race-calendar'));
        }
        $courseId = (int) $wpdb->insert_id;
        foreach ($data['formats'] as $order => $label) {
            if ($wpdb->insert($tables['formats'], ['course_id' => $courseId, 'label' => $label, 'sort_order' => $order], ['%d', '%s', '%d']) !== 1) {
                $wpdb->query('ROLLBACK');
                return $this->databaseError(__('Impossible de créer les formats.', 'community-race-calendar'));
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return $this->databaseError(__('Impossible de finaliser la création de la course.', 'community-race-calendar'));
        }
        self::invalidateSeasonCache();
        return $courseId;
    }

    /** @return array<string, mixed>|null */
    public function rawCourse(int $courseId): ?array
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'published' AND deleted_at IS NULL", $courseId), ARRAY_A);
        return is_array($course) ? $course : null;
    }

    /** @return array<string, mixed>|null */
    public function rawDeletedCourse(int $courseId): ?array
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'published' AND deleted_at IS NOT NULL", $courseId), ARRAY_A);
        return is_array($course) ? $course : null;
    }

    /** @param array<string, mixed> $data */
    public function updateCourse(int $courseId, array $data): bool|WP_Error
    {
        global $wpdb;
        $tables = Migrator::tables();
        if ($wpdb->query('START TRANSACTION') === false) {
            return $this->databaseError(__('Impossible de démarrer la modification.', 'community-race-calendar'));
        }
        $updated = $wpdb->update($tables['courses'], [
            'name' => $data['name'], 'event_date' => $data['eventDate'], 'location' => $data['location'],
            'latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'event_url' => $data['eventUrl'] ?: null,
            'image_id' => (int) ($data['imageId'] ?? 0), 'updated_at' => current_time('mysql', true),
        ], ['id' => $courseId], ['%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s'], ['%d']);
        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return $this->databaseError(__('Impossible de modifier la course.', 'community-race-calendar'));
        }
        $existingIds = array_map('intval', array_column($wpdb->get_results($wpdb->prepare("SELECT id FROM {$tables['formats']} WHERE course_id = %d", $courseId), ARRAY_A) ?: [], 'id'));
        $keptIds = [];
        foreach ($data['formats'] as $order => $format) {
            $formatId = absint($format['id'] ?? 0);
            if ($formatId && in_array($formatId, $existingIds, true)) {
                $result = $wpdb->update($tables['formats'], ['label' => $format['label'], 'sort_order' => $order], ['id' => $formatId, 'course_id' => $courseId], ['%s', '%d'], ['%d', '%d']);
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('crcal_format_conflict', __('Deux formats ne peuvent pas porter le même nom.', 'community-race-calendar'), ['status' => 409]);
                }
                $keptIds[] = $formatId;
            } else {
                if ($wpdb->insert($tables['formats'], ['course_id' => $courseId, 'label' => $format['label'], 'sort_order' => $order], ['%d', '%s', '%d']) !== 1) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('crcal_format_conflict', __('Deux formats ne peuvent pas porter le même nom.', 'community-race-calendar'), ['status' => 409]);
                }
                $keptIds[] = (int) $wpdb->insert_id;
            }
        }
        $removedIds = array_values(array_diff($existingIds, $keptIds));
        if ($removedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($removedIds), '%d'));
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['registrations']} WHERE format_id IN ($placeholders)", ...$removedIds)) > 0) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('crcal_format_has_registrations', __('Un format avec des inscriptions ne peut pas être supprimé.', 'community-race-calendar'), ['status' => 409]);
            }
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$tables['formats']} WHERE id IN ($placeholders)", ...$removedIds));
            if ($deleted !== count($removedIds)) {
                $wpdb->query('ROLLBACK');
                return $this->databaseError(__('Impossible de supprimer les anciens formats.', 'community-race-calendar'));
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return $this->databaseError(__('Impossible de finaliser la modification.', 'community-race-calendar'));
        }
        self::invalidateSeasonCache();
        return true;
    }

    public function softDeleteCourse(int $courseId, int $userId): bool|WP_Error
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $deleted = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET deleted_at = %s, updated_at = %s WHERE id = %d AND created_by = %d AND deleted_at IS NULL",
            current_time('mysql', true),
            current_time('mysql', true),
            $courseId,
            $userId
        ));
        if ($deleted !== 1) {
            return $this->databaseError(__('Impossible de placer la course dans les éléments supprimés.', 'community-race-calendar'));
        }
        self::invalidateSeasonCache();
        return true;
    }

    public function restoreCourse(int $courseId, int $userId): bool|WP_Error
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $restored = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET deleted_at = NULL, updated_at = %s WHERE id = %d AND created_by = %d AND deleted_at IS NOT NULL",
            current_time('mysql', true),
            $courseId,
            $userId
        ));
        if ($restored !== 1) {
            return $this->databaseError(__('Impossible de restaurer la course.', 'community-race-calendar'));
        }
        self::invalidateSeasonCache();
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    /**
     * Dernière édition connue de chaque course dont le nom contient tous les mots recherchés,
     * toutes dates confondues, pour préremplir une nouvelle édition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPreviousEditions(string $search, int $currentUserId, bool $hasAccess, int $limit = 5): array
    {
        global $wpdb;
        $tokens = array_values(array_filter(explode(' ', self::normalizeCourseName($search)), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return [];
        }
        $table = Migrator::tables()['courses'];
        $args = array_map(static fn (string $token): string => '%' . $wpdb->esc_like($token) . '%', array_slice($tokens, 0, 6));
        $conditions = implode(' AND ', array_fill(0, count($args), 'name LIKE %s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'published' AND deleted_at IS NULL AND $conditions ORDER BY event_date DESC, id DESC LIMIT 100",
            ...$args
        ), ARRAY_A) ?: [];
        $latestByName = [];
        foreach ($rows as $row) {
            $normalizedName = self::normalizeCourseName((string) $row['name']);
            $nameTokens = explode(' ', $normalizedName);
            foreach ($tokens as $token) {
                if (array_filter($nameTokens, static fn (string $nameToken): bool => str_starts_with($nameToken, $token)) === []) {
                    continue 2;
                }
            }
            $latestByName[$normalizedName] ??= $row;
            if (count($latestByName) === $limit) {
                break;
            }
        }
        return $this->hydrate(array_values($latestByName), $currentUserId, false, $hasAccess, false);
    }

    public function findDuplicates(string $name, string $from, string $to, int $excludeCourseId = 0): array
    {
        global $wpdb;
        $table = Migrator::tables()['courses'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, event_date, location FROM %i WHERE status = 'published' AND deleted_at IS NULL AND event_date BETWEEN %s AND %s AND (%d = 0 OR id != %d) ORDER BY event_date, id LIMIT 500",
            $table,
            $from,
            $to,
            $excludeCourseId,
            $excludeCourseId
        ), ARRAY_A) ?: [];
        $normalizedName = self::normalizeCourseName($name);
        $sortedName = self::sortCourseNameTokens($normalizedName);
        $matches = [];
        foreach ($rows as $row) {
            $candidate = self::normalizeCourseName((string) $row['name']);
            $score = max(
                self::courseNameSimilarity($normalizedName, $candidate),
                self::courseNameSimilarity($sortedName, self::sortCourseNameTokens($candidate))
            );
            $threshold = max(strlen($normalizedName), strlen($candidate)) < 10 ? 0.88 : 0.78;
            if ($score >= $threshold) {
                $row['_score'] = $score;
                $matches[] = $row;
            }
        }
        usort($matches, static fn (array $left, array $right): int => ($right['_score'] <=> $left['_score']) ?: strcmp($left['event_date'], $right['event_date']));
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'eventDate' => $row['event_date'],
            'location' => $row['location'],
        ], array_slice($matches, 0, 5));
    }

    public function addRegistration(int $courseId, int $formatId, int $userId, int $registeredBy): int|WP_Error
    {
        global $wpdb;
        $tables = Migrator::tables();
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT course_id FROM {$tables['formats']} WHERE id = %d", $formatId)) !== $courseId) {
            return new WP_Error('crcal_invalid_format', __('Ce format n’appartient pas à la course.', 'community-race-calendar'), ['status' => 400]);
        }
        if (!get_user_by('id', $userId)) {
            return new WP_Error('crcal_user_not_found', __('Membre introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $suppression = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($tables['registrations'], ['course_id' => $courseId, 'format_id' => $formatId, 'user_id' => $userId, 'registered_by' => $registeredBy, 'created_at' => current_time('mysql', true)], ['%d', '%d', '%d', '%d', '%s']);
        $wpdb->suppress_errors($suppression);
        if ($inserted === 1) {
            self::invalidateSeasonCache();
            return (int) $wpdb->insert_id;
        }
        return new WP_Error('crcal_already_registered', __('Ce membre est déjà inscrit à ce format.', 'community-race-calendar'), ['status' => 409]);
    }

    /** @return array<string, mixed>|null */
    public function registration(int $registrationId): ?array
    {
        global $wpdb;
        $table = Migrator::tables()['registrations'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $registrationId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function deleteRegistration(int $registrationId): bool
    {
        global $wpdb;
        $tables = Migrator::tables();
        $registration = $this->registration($registrationId);
        if (!$registration || $wpdb->delete($tables['registrations'], ['id' => $registrationId], ['%d']) !== 1) {
            return false;
        }
        if (!$this->userIsRegistered((int) $registration['course_id'], (int) $registration['user_id'])) {
            $wpdb->delete($tables['results'], [
                'course_id' => (int) $registration['course_id'],
                'user_id' => (int) $registration['user_id'],
            ], ['%d', '%d']);
        }
        self::invalidateSeasonCache();
        return true;
    }

    public function userIsRegistered(int $courseId, int $userId): bool
    {
        global $wpdb;
        $table = Migrator::tables()['registrations'];
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE course_id = %d AND user_id = %d", $courseId, $userId)) > 0;
    }

    public function addComment(int $courseId, int $userId, string $body): int|WP_Error
    {
        global $wpdb;
        $inserted = $wpdb->insert(Migrator::tables()['comments'], ['course_id' => $courseId, 'user_id' => $userId, 'body' => $body, 'created_at' => current_time('mysql', true)], ['%d', '%d', '%s', '%s']);
        return $inserted === 1 ? (int) $wpdb->insert_id : $this->databaseError(__('Impossible d’ajouter le commentaire.', 'community-race-calendar'));
    }

    /** @return array<string, mixed>|null */
    public function comment(int $commentId): ?array
    {
        global $wpdb;
        $table = Migrator::tables()['comments'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND deleted_at IS NULL", $commentId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function deleteComment(int $commentId): bool
    {
        global $wpdb;
        return $wpdb->update(Migrator::tables()['comments'], ['deleted_at' => current_time('mysql', true)], ['id' => $commentId], ['%s'], ['%d']) === 1;
    }

    /** @return array<string, mixed>|WP_Error */
    public function saveCarpool(int $courseId, int $userId, string $type, int $seats, string $departure, string $note): array|WP_Error
    {
        global $wpdb;
        $table = Migrator::tables()['carpools'];
        $now = current_time('mysql', true);
        $wpdb->query('SELECT LAST_INSERT_ID(0)');
        $saved = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (course_id,user_id,type,seats,departure,note,created_at,updated_at)
             VALUES (%d,%d,%s,%d,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID($table.id), type = VALUES(type), seats = VALUES(seats), departure = VALUES(departure), note = VALUES(note), updated_at = VALUES(updated_at)",
            $courseId, $userId, $type, $seats, $departure ?: null, $note ?: null, $now, $now
        ));
        if ($saved === false) {
            return $this->databaseError(__('Impossible d’enregistrer le covoiturage.', 'community-race-calendar'));
        }
        $savedId = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        $row = $wpdb->get_row($wpdb->prepare("SELECT c.*, u.display_name FROM $table c LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.id = %d", $savedId), ARRAY_A);
        $row = is_array($row) ? MemberName::applyToRows([$row])[0] : $row;
        return is_array($row) ? [
            'id' => (int) $row['id'], 'userId' => (int) $row['user_id'],
            'displayName' => $row['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
            'type' => $row['type'], 'seats' => (int) $row['seats'],
            'departure' => $row['departure'] ?: null, 'note' => $row['note'] ?: null,
            'updatedAt' => mysql_to_rfc3339($row['updated_at']),
        ] : $this->databaseError(__('Impossible de relire le covoiturage.', 'community-race-calendar'));
    }

    public function deleteCarpool(int $courseId, int $userId): bool
    {
        global $wpdb;
        return $wpdb->delete(Migrator::tables()['carpools'], ['course_id' => $courseId, 'user_id' => $userId], ['%d', '%d']) === 1;
    }

    public function userIsRegisteredForFormat(int $courseId, int $formatId, int $userId): bool
    {
        global $wpdb;
        $table = Migrator::tables()['registrations'];
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE course_id = %d AND format_id = %d AND user_id = %d", $courseId, $formatId, $userId)) > 0;
    }

    /** @return array<string, mixed>|WP_Error */
    public function saveResult(int $courseId, int $userId, int $formatId, string $time, string $ranking, string $distance, string $note): array|WP_Error
    {
        global $wpdb;
        $table = Migrator::tables()['results'];
        $now = current_time('mysql', true);
        $registrations = Migrator::tables()['registrations'];
        $wpdb->query('SELECT LAST_INSERT_ID(0)');
        $saved = $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (course_id,user_id,format_id,time_text,ranking_text,distance_text,note,created_at,updated_at)
             SELECT %d,%d,%d,%s,%s,%s,%s,%s,%s FROM %i registration
             WHERE registration.course_id = %d AND registration.format_id = %d AND registration.user_id = %d LIMIT 1
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(%i.id), format_id = VALUES(format_id), time_text = VALUES(time_text), ranking_text = VALUES(ranking_text), distance_text = VALUES(distance_text), note = VALUES(note), updated_at = VALUES(updated_at)",
            $table,
            $courseId, $userId, $formatId, $time ?: null, $ranking ?: null, $distance ?: null, $note ?: null, $now, $now,
            $registrations,
            $courseId, $formatId, $userId,
            $table
        ));
        if ($saved === false) {
            return $this->databaseError(__('Impossible d’enregistrer le résultat.', 'community-race-calendar'));
        }
        $savedId = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($savedId < 1) {
            return new WP_Error('crcal_result_forbidden', __('Choisissez l’un des formats auxquels vous êtes inscrit.', 'community-race-calendar'), ['status' => 403]);
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT r.*, u.display_name, f.label AS format_label FROM %i r LEFT JOIN %i u ON u.ID = r.user_id LEFT JOIN %i f ON f.id = r.format_id WHERE r.id = %d", $table, $wpdb->users, Migrator::tables()['formats'], $savedId), ARRAY_A);
        $row = is_array($row) ? MemberName::applyToRows([$row])[0] : $row;
        if (!is_array($row)) {
            return $this->databaseError(__('Impossible de relire le résultat.', 'community-race-calendar'));
        }
        self::invalidateSeasonCache();
        return $this->presentResult($row);
    }

    public function deleteResult(int $courseId, int $userId): bool
    {
        global $wpdb;
        $deleted = $wpdb->delete(Migrator::tables()['results'], ['course_id' => $courseId, 'user_id' => $userId], ['%d', '%d']) === 1;
        if ($deleted) {
            self::invalidateSeasonCache();
        }
        return $deleted;
    }

    /** @return array<string, mixed> */
    public function review(string $from, string $to): array
    {
        global $wpdb;
        $tables = Migrator::tables();
        $rows = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
            "SELECT c.id AS course_id, c.name, c.event_date, c.location,
                    r.user_id, u.display_name, f.label AS format_label,
                    res.id AS result_id, res.format_id AS result_format_id,
                    rf.label AS result_format_label, res.time_text, res.ranking_text,
                    res.distance_text, res.note, res.updated_at
             FROM {$tables['courses']} c
             LEFT JOIN {$tables['registrations']} r ON r.course_id = c.id
             LEFT JOIN {$tables['formats']} f ON f.id = r.format_id
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             LEFT JOIN {$tables['results']} res ON res.course_id = c.id AND res.user_id = r.user_id
             LEFT JOIN {$tables['formats']} rf ON rf.id = res.format_id
             WHERE c.status = 'published' AND c.deleted_at IS NULL AND c.event_date BETWEEN %s AND %s
             ORDER BY c.event_date ASC, c.name ASC, u.display_name ASC, f.sort_order ASC
             LIMIT %d",
            $from,
            $to,
            self::MAX_SEASON_PARTICIPATIONS + 1
        ), ARRAY_A) ?: []);
        $truncated = count($rows) > self::MAX_SEASON_PARTICIPATIONS;
        if ($truncated) {
            array_pop($rows);
        }

        $courses = [];
        foreach ($rows as $row) {
            $courseId = (int) $row['course_id'];
            if (!isset($courses[$courseId])) {
                $courses[$courseId] = [
                    'id' => $courseId,
                    'name' => $row['name'],
                    'eventDate' => $row['event_date'],
                    'location' => $row['location'],
                    'participants' => [],
                ];
            }
            if ($row['user_id'] === null) {
                continue;
            }
            $userId = (int) $row['user_id'];
            if (!isset($courses[$courseId]['participants'][$userId])) {
                $courses[$courseId]['participants'][$userId] = [
                    'userId' => $userId,
                    'displayName' => $row['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
                    'formats' => [],
                    'result' => $row['result_id'] ? $this->presentResult([
                        'id' => $row['result_id'], 'course_id' => $courseId, 'user_id' => $userId,
                        'display_name' => $row['display_name'], 'format_id' => $row['result_format_id'], 'format_label' => $row['result_format_label'],
                        'time_text' => $row['time_text'], 'ranking_text' => $row['ranking_text'], 'distance_text' => $row['distance_text'],
                        'note' => $row['note'], 'updated_at' => $row['updated_at'],
                    ]) : null,
                ];
            }
            $courses[$courseId]['participants'][$userId]['formats'][] = $row['format_label'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'truncated' => $truncated,
            'courses' => array_values(array_map(static function (array $course): array {
                $course['participants'] = array_values($course['participants']);
                return $course;
            }, $courses)),
        ];
    }

    /** @return array<string, mixed> */
    public function season(int $year): array
    {
        $cacheVersion = (int) get_option(self::SEASON_CACHE_VERSION_OPTION, 1);
        $cacheKey = 'crcal_season_' . md5($cacheVersion . ':' . $year);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        global $wpdb;
        $tables = Migrator::tables();
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $today = current_time('Y-m-d');
        $courseWhere = "status = 'published' AND deleted_at IS NULL AND event_date BETWEEN %s AND %s";

        $years = array_map('intval', $wpdb->get_col("SELECT DISTINCT YEAR(event_date) FROM {$tables['courses']} WHERE status = 'published' AND deleted_at IS NULL ORDER BY YEAR(event_date) DESC") ?: []);
        if (!in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
        }

        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT c.id) AS courses_count, COUNT(r.id) AS registrations_count, COUNT(DISTINCT r.user_id) AS participants_count FROM {$tables['courses']} c LEFT JOIN {$tables['registrations']} r ON r.course_id = c.id WHERE c.$courseWhere",
            $from, $to
        ), ARRAY_A) ?: [];
        $months = $wpdb->get_results($wpdb->prepare(
            "SELECT MONTH(event_date) AS month_number, COUNT(*) AS courses_count FROM {$tables['courses']} WHERE $courseWhere GROUP BY MONTH(event_date) ORDER BY month_number",
            $from, $to
        ), ARRAY_A) ?: [];
        $topParticipants = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, u.display_name, COUNT(DISTINCT r.course_id) AS races_count FROM {$tables['registrations']} r INNER JOIN {$tables['courses']} c ON c.id = r.course_id LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id WHERE c.$courseWhere GROUP BY r.user_id, u.display_name ORDER BY races_count DESC, u.display_name ASC LIMIT 8",
            $from, $to
        ), ARRAY_A) ?: []);
        $topCourses = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.name, c.event_date, COUNT(r.id) AS registrations_count FROM {$tables['courses']} c LEFT JOIN {$tables['registrations']} r ON r.course_id = c.id WHERE c.$courseWhere GROUP BY c.id, c.name, c.event_date ORDER BY registrations_count DESC, c.event_date ASC LIMIT 8",
            $from, $to
        ), ARRAY_A) ?: [];

        $rows = MemberName::applyToRows($wpdb->get_results($wpdb->prepare(
            "SELECT c.id AS course_id, c.name, c.event_date, c.location, r.user_id, u.display_name, f.label AS format_label, res.id AS result_id, res.format_id AS result_format_id, rf.label AS result_format_label, res.time_text, res.ranking_text, res.distance_text, res.note, res.updated_at FROM {$tables['courses']} c INNER JOIN {$tables['registrations']} r ON r.course_id = c.id INNER JOIN {$tables['formats']} f ON f.id = r.format_id LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id LEFT JOIN {$tables['results']} res ON res.course_id = c.id AND res.user_id = r.user_id LEFT JOIN {$tables['formats']} rf ON rf.id = res.format_id WHERE c.$courseWhere AND c.event_date <= %s ORDER BY c.event_date DESC, c.name, u.display_name, f.sort_order LIMIT %d",
            $from, $to, $today, self::MAX_SEASON_PARTICIPATIONS + 1
        ), ARRAY_A) ?: []);
        $truncated = count($rows) > self::MAX_SEASON_PARTICIPATIONS;
        if ($truncated) {
            array_pop($rows);
        }

        $weekMap = [];
        foreach ($rows as $row) {
            $date = new \DateTimeImmutable($row['event_date'], wp_timezone());
            $weekKey = $date->format('o-\\WW');
            $courseId = (int) $row['course_id'];
            $userId = (int) $row['user_id'];
            if (!isset($weekMap[$weekKey])) {
                $monday = $date->modify('monday this week');
                $weekMap[$weekKey] = ['key' => $weekKey, 'from' => $monday->format('Y-m-d'), 'to' => $monday->modify('+6 days')->format('Y-m-d'), 'courses' => []];
            }
            if (!isset($weekMap[$weekKey]['courses'][$courseId])) {
                $weekMap[$weekKey]['courses'][$courseId] = ['id' => $courseId, 'name' => $row['name'], 'eventDate' => $row['event_date'], 'location' => $row['location'], 'participants' => []];
            }
            if (!isset($weekMap[$weekKey]['courses'][$courseId]['participants'][$userId])) {
                $weekMap[$weekKey]['courses'][$courseId]['participants'][$userId] = [
                    'userId' => $userId,
                    'displayName' => $row['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
                    'formats' => [],
                    'result' => $row['result_id'] ? $this->presentResult([
                        'id' => $row['result_id'], 'course_id' => $courseId, 'user_id' => $userId,
                        'display_name' => $row['display_name'], 'format_id' => $row['result_format_id'], 'format_label' => $row['result_format_label'],
                        'time_text' => $row['time_text'], 'ranking_text' => $row['ranking_text'], 'distance_text' => $row['distance_text'],
                        'note' => $row['note'], 'updated_at' => $row['updated_at'],
                    ]) : null,
                ];
            }
            $weekMap[$weekKey]['courses'][$courseId]['participants'][$userId]['formats'][] = $row['format_label'];
        }
        $weeks = array_values(array_map(static function (array $week): array {
            $week['courses'] = array_values(array_map(static function (array $course): array {
                $course['participants'] = array_values($course['participants']);
                return $course;
            }, $week['courses']));
            return $week;
        }, $weekMap));

        $season = [
            'year' => $year,
            'years' => $years,
            'truncated' => $truncated,
            'stats' => [
                'coursesCount' => (int) ($counts['courses_count'] ?? 0),
                'registrationsCount' => (int) ($counts['registrations_count'] ?? 0),
                'participantsCount' => (int) ($counts['participants_count'] ?? 0),
                'racesByMonth' => array_map(static fn (array $row): array => ['month' => (int) $row['month_number'], 'count' => (int) $row['courses_count']], $months),
                'topParticipants' => array_map(static fn (array $row): array => ['userId' => (int) $row['user_id'], 'displayName' => $row['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'), 'racesCount' => (int) $row['races_count']], $topParticipants),
                'topCourses' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'eventDate' => $row['event_date'], 'registrationsCount' => (int) $row['registrations_count']], $topCourses),
            ],
            'weeks' => $weeks,
        ];
        set_transient($cacheKey, $season, self::SEASON_CACHE_TTL);
        return $season;
    }

    public static function invalidateSeasonCache(): void
    {
        $version = (int) get_option(self::SEASON_CACHE_VERSION_OPTION, 1);
        update_option(self::SEASON_CACHE_VERSION_OPTION, max(1, $version + 1), false);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentResult(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'displayName' => $row['display_name'] ?: __('Utilisateur supprimé', 'community-race-calendar'),
            'formatId' => $row['format_id'] !== null ? (int) $row['format_id'] : null,
            'formatLabel' => $row['format_label'] ?: null,
            'time' => $row['time_text'] ?: null,
            'ranking' => $row['ranking_text'] ?: null,
            'distance' => $row['distance_text'] ?: null,
            'note' => $row['note'] ?: null,
            'updatedAt' => mysql_to_rfc3339($row['updated_at']),
        ];
    }

    private static function normalizeCourseName(string $name): string
    {
        $normalized = strtolower(remove_accents($name));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?: '';
        return trim(preg_replace('/\s+/', ' ', $normalized) ?: '');
    }

    private static function sortCourseNameTokens(string $name): string
    {
        $tokens = array_values(array_filter(explode(' ', $name)));
        sort($tokens, SORT_STRING);
        return implode(' ', $tokens);
    }

    private static function courseNameSimilarity(string $left, string $right): float
    {
        $longest = max(strlen($left), strlen($right));
        if ($longest === 0) {
            return 1.0;
        }
        return max(0.0, 1 - (levenshtein($left, $right) / $longest));
    }

    private function databaseError(string $message): WP_Error
    {
        return new WP_Error('crcal_database_error', $message, ['status' => 500]);
    }
}
