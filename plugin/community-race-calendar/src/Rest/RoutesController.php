<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Rest;

use CommunityRaceCalendar\Access\AccessGate;
use CommunityRaceCalendar\Database\Migrator;
use CommunityRaceCalendar\Domain\CalendarPeriod;
use CommunityRaceCalendar\Domain\MemberName;
use CommunityRaceCalendar\Geocoding\GeocodingProviderInterface;
use CommunityRaceCalendar\Media\CourseImageManager;
use CommunityRaceCalendar\Plugin;
use CommunityRaceCalendar\Repository\CalendarRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class RoutesController
{
    public const NAMESPACE = 'community-race-calendar/v1';
    private const MEMBER_SEARCH_LIMIT_PER_MINUTE = 60;
    private const MAX_FORMATS = 20;
    private const MAX_PAYLOAD_BYTES = 32768;

    public function __construct(
        private AccessGate $gate,
        private CalendarRepository $repository,
        private GeocodingProviderInterface $geocoding,
        private CourseImageManager $images
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/bootstrap', [
            'methods' => 'GET',
            'callback' => [$this, 'bootstrap'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/courses', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listCourses'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createCourse'],
                'permission_callback' => [$this, 'requireCreate'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/deleted', [
            'methods' => 'GET',
            'callback' => [$this, 'listDeletedCourses'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/editions', [
            'methods' => 'GET',
            'callback' => [$this, 'previousEditions'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/duplicates', [
            'methods' => 'GET',
            'callback' => [$this, 'courseDuplicates'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getCourse'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
            [
                'methods' => 'PATCH,POST',
                'callback' => [$this, 'updateCourse'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteCourse'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'restoreCourse'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)/registrations', [
            'methods' => 'POST',
            'callback' => [$this, 'addRegistration'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/registrations/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'deleteRegistration'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)/comments', [
            'methods' => 'POST',
            'callback' => [$this, 'addComment'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/comments/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'deleteComment'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)/carpool', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'saveCarpool'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteCarpool'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/courses/(?P<id>\d+)/result', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'saveResult'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteResult'],
                'permission_callback' => [$this, 'requireAccess'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/season', [
            'methods' => 'GET',
            'callback' => [$this, 'season'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/review', [
            'methods' => 'GET',
            'callback' => [$this, 'review'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/members', [
            'methods' => 'GET',
            'callback' => [$this, 'members'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/locations', [
            'methods' => 'GET',
            'callback' => [$this, 'searchLocations'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'settings'],
                'permission_callback' => [$this, 'requireManage'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'updateSettings'],
                'permission_callback' => [$this, 'requireManage'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/images/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getImage'],
            'permission_callback' => [$this, 'requireAccess'],
        ]);
    }

    public function requireAccess(): bool|WP_Error
    {
        return Migrator::dependencyError() ?: $this->gate->requireAccess();
    }

    public function requireCreate(): bool|WP_Error
    {
        return Migrator::dependencyError() ?: $this->gate->requireCreate();
    }

    public function requireManage(): bool|WP_Error
    {
        return Migrator::dependencyError() ?: $this->gate->requireManage();
    }

    public function bootstrap(): WP_REST_Response
    {
        $authenticated = is_user_logged_in();
        $manager = $authenticated && $this->gate->canManage();
        $providerAvailable = $this->gate->provider()->isAvailable();
        $access = $authenticated && $this->gate->hasAccess();
        $user = wp_get_current_user();
        $settings = Plugin::publicSettings();

        $accessCode = 'granted';
        if (!$authenticated) {
            $accessCode = 'authentication_required';
        } elseif (!$providerAvailable && !$manager && !$access) {
            $accessCode = 'provider_unavailable';
        } elseif (!$access) {
            $accessCode = 'membership_inactive';
        }

        return new WP_REST_Response([
            'authenticated' => $authenticated,
            'accessGranted' => $access,
            'accessCode' => $accessCode,
            'accessMessage' => $this->accessMessage($accessCode),
            'user' => $authenticated ? [
                'id' => (int) $user->ID,
                'displayName' => MemberName::of((int) $user->ID, $user->display_name),
            ] : null,
            'capabilities' => [
                'manage' => $manager,
                'createCourses' => $authenticated && $this->gate->canCreate(),
            ],
            'provider' => [
                'key' => $this->gate->provider()->key(),
                'label' => $this->gate->provider()->label(),
                'available' => $providerAvailable,
            ],
            'geocoding' => [
                'key' => $this->geocoding->key(),
                'label' => $this->geocoding->label(),
                'available' => $this->geocoding->isAvailable(),
                'configured' => $this->geocoding->isAvailable(),
                'operational' => method_exists($this->geocoding, 'operationalStatus') ? $this->geocoding->operationalStatus() : null,
            ],
            'period' => CalendarPeriod::current(),
            'schemaReady' => Migrator::dependencyError() === null,
            'settings' => $settings,
            'loginUrl' => Plugin::accountUrl(),
        ]);
    }

    public function listCourses(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $range = $this->dateRange($request);
        if (is_wp_error($range)) {
            return $range;
        }
        $userId = get_current_user_id();
        $page = max(1, absint($request->get_param('page')) ?: 1);
        $perPage = min(100, max(1, absint($request->get_param('perPage')) ?: 100));
        $result = $this->repository->listCourses(
            $range['from'], $range['to'], $userId, $this->gate->canManage($userId), $this->gate->hasAccess($userId), $page, $perPage
        );
        return new WP_REST_Response([
            'courses' => $this->presentCourses($result['courses']),
            'nextPage' => $result['hasMore'] ? $page + 1 : null,
        ]);
    }

    public function getCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $courseId = absint($request['id']);
        $course = $this->findCourseResponse($courseId);
        return $course ?: new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
    }

    public function listDeletedCourses(): WP_REST_Response
    {
        $userId = get_current_user_id();
        return new WP_REST_Response([
            'courses' => $this->presentCourses($this->repository->listDeletedCourses($userId, $this->gate->hasAccess($userId))),
        ]);
    }

    public function previousEditions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $length = $this->length($search);
        if ($length < 2 || $length > 191) {
            return new WP_Error('crcal_invalid_edition_search', __('Saisissez entre 2 et 191 caractères.', 'community-race-calendar'), ['status' => 400]);
        }
        $userId = get_current_user_id();
        return new WP_REST_Response([
            'editions' => $this->presentCourses($this->repository->findPreviousEditions($search, $userId, $this->gate->hasAccess($userId))),
        ]);
    }

    public function courseDuplicates(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        $eventDate = sanitize_text_field((string) $request->get_param('eventDate'));
        if ($name === '' || $this->length($name) > 191 || !CalendarPeriod::isDate($eventDate)) {
            return new WP_Error('crcal_invalid_duplicate_search', __('Renseignez un nom et une date valides.', 'community-race-calendar'), ['status' => 400]);
        }
        $date = new \DateTimeImmutable($eventDate, wp_timezone());
        return new WP_REST_Response([
            'duplicates' => $this->repository->findDuplicates(
                $name,
                $date->modify('first day of this month')->format('Y-m-d'),
                $date->modify('last day of this month')->format('Y-m-d'),
                absint($request->get_param('exclude'))
            ),
        ]);
    }

    public function getImage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->images->imageResponse(absint($request['id']));
    }

    public function createCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('course_create', 10, HOUR_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $data = $this->validateCoursePayload($request, false);
        if (is_wp_error($data)) {
            return $data;
        }

        $imageFile = $this->imageFile($request);
        if (is_wp_error($imageFile)) {
            return $imageFile;
        }
        $imageId = 0;
        $reusedImageId = $this->reusedImageId($request);
        if (is_wp_error($reusedImageId)) {
            return $reusedImageId;
        }
        if ($imageFile !== null && $reusedImageId > 0) {
            return new WP_Error('crcal_conflicting_image_action', __('Choisissez soit une nouvelle image, soit le logo de l’édition précédente.', 'community-race-calendar'), ['status' => 400]);
        }
        $imageId = $reusedImageId;
        if ($imageFile !== null) {
            $limited = $this->rateLimit('image_upload', 10, HOUR_IN_SECONDS);
            if (is_wp_error($limited)) {
                return $limited;
            }
            $uploaded = $this->images->upload($imageFile, $data['name']);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }
            $imageId = $uploaded;
        }
        $data['imageId'] = $imageId;

        $created = $this->repository->createCourse($data, get_current_user_id());
        if (is_wp_error($created)) {
            if (!$this->images->deleteManaged($imageId)) {
                $this->images->queueCleanup($imageId);
            }
            return $created;
        }
        if ($imageId > 0 && $reusedImageId === 0) {
            $this->images->linkToCourse($imageId, $created);
        }
        $response = $this->findCourseResponse($created);
        $response->set_status(201);
        return $response;
    }

    public function updateCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $courseId = absint($request['id']);
        $course = $this->repository->rawCourse($courseId);
        if (!$course) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        if (!$this->gate->canManage() && (int) $course['created_by'] !== get_current_user_id()) {
            return new WP_Error('crcal_course_edit_forbidden', __('Vous ne pouvez pas modifier cette course.', 'community-race-calendar'), ['status' => 403]);
        }

        $data = $this->validateCoursePayload($request, true, $course);
        if (is_wp_error($data)) {
            return $data;
        }

        $imageFile = $this->imageFile($request);
        if (is_wp_error($imageFile)) {
            return $imageFile;
        }
        if ($imageFile !== null && $data['removeImage']) {
            return new WP_Error('crcal_conflicting_image_action', __('Choisissez soit une nouvelle image, soit la suppression de l’image actuelle.', 'community-race-calendar'), ['status' => 400]);
        }

        $previousImageId = (int) $course['image_id'];
        $newImageId = 0;
        if ($imageFile !== null) {
            $limited = $this->rateLimit('image_upload', 10, HOUR_IN_SECONDS);
            if (is_wp_error($limited)) {
                return $limited;
            }
            $uploaded = $this->images->upload($imageFile, $data['name']);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }
            $newImageId = $uploaded;
        }
        $data['imageId'] = $newImageId > 0 ? $newImageId : ($data['removeImage'] ? 0 : $previousImageId);

        $updated = $this->repository->updateCourse($courseId, $data);
        if (is_wp_error($updated)) {
            if (!$this->images->deleteManaged($newImageId)) {
                $this->images->queueCleanup($newImageId);
            }
            return $updated;
        }
        if ($newImageId > 0) {
            $this->images->linkToCourse($newImageId, $courseId);
        }
        if ($previousImageId > 0 && ($newImageId > 0 || $data['removeImage'])) {
            if (!$this->images->deleteManaged($previousImageId, $courseId)) {
                $this->images->queueCleanup($previousImageId);
            }
        }
        return $this->findCourseResponse($courseId);
    }

    public function deleteCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $courseId = absint($request['id']);
        $course = $this->repository->rawCourse($courseId);
        if (!$course) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $userId = get_current_user_id();
        if ((int) $course['created_by'] !== $userId) {
            return new WP_Error('crcal_course_delete_forbidden', __('Seul l’auteur peut supprimer cette course.', 'community-race-calendar'), ['status' => 403]);
        }
        $deleted = $this->repository->softDeleteCourse($courseId, $userId);
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        return new WP_REST_Response(null, 204);
    }

    public function restoreCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $courseId = absint($request['id']);
        $course = $this->repository->rawDeletedCourse($courseId);
        if (!$course) {
            return new WP_Error('crcal_course_not_found', __('Course supprimée introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $userId = get_current_user_id();
        if ((int) $course['created_by'] !== $userId) {
            return new WP_Error('crcal_course_restore_forbidden', __('Seul l’auteur peut restaurer cette course.', 'community-race-calendar'), ['status' => 403]);
        }
        $restored = $this->repository->restoreCourse($courseId, $userId);
        if (is_wp_error($restored)) {
            return $restored;
        }
        return $this->findCourseResponse($courseId);
    }

    public function addRegistration(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('registration', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $courseId = absint($request['id']);
        if (!$this->repository->rawCourse($courseId)) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $formatId = absint($request->get_param('formatId'));
        $targetUserId = absint($request->get_param('userId')) ?: get_current_user_id();
        if ($formatId < 1) {
            return new WP_Error('crcal_invalid_format', __('Format obligatoire.', 'community-race-calendar'), ['status' => 400]);
        }
        if (!$this->gate->hasAccess($targetUserId)) {
            return new WP_Error('crcal_target_member_inactive', __('Le membre sélectionné n’a pas d’adhésion active.', 'community-race-calendar'), ['status' => 403]);
        }
        $created = $this->repository->addRegistration($courseId, $formatId, $targetUserId, get_current_user_id());
        if (is_wp_error($created)) {
            return $created;
        }
        $response = $this->findCourseResponse($courseId);
        $response->set_status(201);
        return $response;
    }

    public function deleteRegistration(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $registration = $this->repository->registration(absint($request['id']));
        if (!$registration) {
            return new WP_Error('crcal_registration_not_found', __('Inscription introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        if (!$this->gate->canManage() && (int) $registration['user_id'] !== get_current_user_id()) {
            return new WP_Error('crcal_registration_delete_forbidden', __('Vous ne pouvez pas supprimer cette inscription.', 'community-race-calendar'), ['status' => 403]);
        }
        if (!$this->repository->deleteRegistration((int) $registration['id'])) {
            return new WP_Error('crcal_database_error', __('Impossible de supprimer cette inscription.', 'community-race-calendar'), ['status' => 500]);
        }
        return new WP_REST_Response(null, 204);
    }

    public function addComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('comment', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $courseId = absint($request['id']);
        if (!$this->repository->rawCourse($courseId)) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $body = sanitize_textarea_field((string) $request->get_param('body'));
        $length = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
        if ($body === '' || $length > 1000) {
            return new WP_Error('crcal_invalid_comment', __('Le commentaire doit contenir entre 1 et 1 000 caractères.', 'community-race-calendar'), ['status' => 400]);
        }
        $created = $this->repository->addComment($courseId, get_current_user_id(), $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $response = $this->findCourseResponse($courseId);
        $response->set_status(201);
        return $response;
    }

    public function deleteComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $comment = $this->repository->comment(absint($request['id']));
        if (!$comment) {
            return new WP_Error('crcal_comment_not_found', __('Commentaire introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        if (!$this->gate->canManage() && (int) $comment['user_id'] !== get_current_user_id()) {
            return new WP_Error('crcal_comment_delete_forbidden', __('Vous ne pouvez pas supprimer ce commentaire.', 'community-race-calendar'), ['status' => 403]);
        }
        if (!$this->repository->deleteComment((int) $comment['id'])) {
            return new WP_Error('crcal_database_error', __('Impossible de supprimer ce commentaire.', 'community-race-calendar'), ['status' => 500]);
        }
        return new WP_REST_Response(null, 204);
    }

    public function saveCarpool(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('carpool', 20, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $courseId = absint($request['id']);
        $userId = get_current_user_id();
        if (!$this->repository->rawCourse($courseId)) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $type = sanitize_key((string) $request->get_param('type'));
        $seats = absint($request->get_param('seats'));
        $departure = sanitize_text_field((string) $request->get_param('departure'));
        $note = sanitize_textarea_field((string) $request->get_param('note'));
        if (!in_array($type, ['driver', 'passenger'], true)) {
            return new WP_Error('crcal_invalid_carpool_type', __('Choisissez si vous conduisez ou cherchez une place.', 'community-race-calendar'), ['status' => 400]);
        }
        if ($type === 'driver' && ($seats < 1 || $seats > 8)) {
            return new WP_Error('crcal_invalid_carpool_seats', __('Indiquez entre 1 et 8 places disponibles.', 'community-race-calendar'), ['status' => 400]);
        }
        if ($this->length($departure) > 191 || $this->length($note) > 500) {
            return new WP_Error('crcal_carpool_too_long', __('Le départ est limité à 191 caractères et la précision à 500 caractères.', 'community-race-calendar'), ['status' => 400]);
        }
        $saved = $this->repository->saveCarpool($courseId, $userId, $type, $type === 'driver' ? $seats : 0, $departure, $note);
        return is_wp_error($saved) ? $saved : new WP_REST_Response(['carpool' => $saved], 200);
    }

    public function deleteCarpool(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->repository->deleteCarpool(absint($request['id']), get_current_user_id())) {
            return new WP_Error('crcal_carpool_not_found', __('Covoiturage introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        return new WP_REST_Response(null, 204);
    }

    public function saveResult(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('result', 20, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $courseId = absint($request['id']);
        $userId = get_current_user_id();
        if (!$this->repository->rawCourse($courseId)) {
            return new WP_Error('crcal_course_not_found', __('Course introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        $formatId = absint($request->get_param('formatId'));
        if ($formatId < 1) {
            return new WP_Error('crcal_result_forbidden', __('Choisissez l’un des formats auxquels vous êtes inscrit.', 'community-race-calendar'), ['status' => 403]);
        }
        $time = sanitize_text_field((string) $request->get_param('time'));
        $ranking = sanitize_text_field((string) $request->get_param('ranking'));
        $distance = sanitize_text_field((string) $request->get_param('distance'));
        $note = sanitize_textarea_field((string) $request->get_param('note'));
        if ($time === '' && $ranking === '' && $distance === '' && $note === '') {
            return new WP_Error('crcal_empty_result', __('Renseignez au moins une information de résultat.', 'community-race-calendar'), ['status' => 400]);
        }
        if ($this->length($time) > 64 || $this->length($ranking) > 100 || $this->length($distance) > 64 || $this->length($note) > 500) {
            return new WP_Error('crcal_result_too_long', __('Une information du résultat est trop longue.', 'community-race-calendar'), ['status' => 400]);
        }
        $saved = $this->repository->saveResult($courseId, $userId, $formatId, $time, $ranking, $distance, $note);
        return is_wp_error($saved) ? $saved : new WP_REST_Response(['result' => $saved], 200);
    }

    public function deleteResult(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->repository->deleteResult(absint($request['id']), get_current_user_id())) {
            return new WP_Error('crcal_result_not_found', __('Résultat introuvable.', 'community-race-calendar'), ['status' => 404]);
        }
        return new WP_REST_Response(null, 204);
    }

    public function season(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('season', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $year = absint($request->get_param('year')) ?: (int) current_time('Y');
        if ($year < 2000 || $year > 2100) {
            return new WP_Error('crcal_invalid_year', __('L’année demandée est invalide.', 'community-race-calendar'), ['status' => 400]);
        }
        return new WP_REST_Response(['season' => $this->repository->season($year)]);
    }

    public function review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limited = $this->rateLimit('review', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($limited)) {
            return $limited;
        }
        $range = $this->reviewDateRange($request);
        if (is_wp_error($range)) {
            return $range;
        }
        return new WP_REST_Response(['review' => $this->repository->review($range['from'], $range['to'])]);
    }

    public function members(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $length = function_exists('mb_strlen') ? mb_strlen($search) : strlen($search);
        if ($length > 100) {
            return new WP_Error('crcal_invalid_member_search', __('La recherche est trop longue.', 'community-race-calendar'), ['status' => 400]);
        }
        $rateKey = 'crcal_members_rate_' . get_current_user_id() . '_' . gmdate('YmdHi');
        $requests = (int) get_transient($rateKey);
        if ($requests >= self::MEMBER_SEARCH_LIMIT_PER_MINUTE) {
            return new WP_Error('crcal_members_rate_limited', __('Trop de recherches de membres. Réessayez dans une minute.', 'community-race-calendar'), ['status' => 429]);
        }
        set_transient($rateKey, $requests + 1, MINUTE_IN_SECONDS + 5);
        $cursor = min(10000, max(0, absint($request->get_param('cursor'))));
        $members = [];
        $batchSize = 50;
        $scanned = $cursor;
        $hasMore = true;
        while (count($members) < 20 && $hasMore && $scanned < 10000) {
            $userIds = $this->memberCandidateIds($search, $batchSize, $scanned);
            cache_users($userIds);
            MemberName::prime($userIds);
            $processed = 0;
            foreach ($userIds as $userId) {
                $processed++;
                if ($this->gate->hasAccess($userId)) {
                    $user = get_userdata($userId);
                    $publicName = $user ? trim((string) $user->display_name) : '';
                    $fullName = MemberName::of($userId, $publicName);
                    $members[] = [
                        'id' => $userId,
                        'displayName' => $fullName,
                        'label' => $publicName !== '' && !str_contains($publicName, '@') && strcasecmp($publicName, $fullName) !== 0
                            ? sprintf('%s · %s', $fullName, $publicName)
                            : $fullName,
                    ];
                    if (count($members) === 20) {
                        break;
                    }
                }
            }
            $scanned += $processed;
            $hasMore = $processed < count($userIds) || count($userIds) === $batchSize;
        }
        return new WP_REST_Response([
            'members' => $members,
            'nextCursor' => $hasMore ? $scanned : null,
        ]);
    }

    /**
     * Ids of the accounts whose public display name contains the search. When member names are
     * enabled, profile and billing first and last names are searched too. The login name never is.
     *
     * @return array<int, int>
     */
    private function memberCandidateIds(string $search, int $limit, int $offset): array
    {
        global $wpdb;
        if ($search === '') {
            return array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} ORDER BY display_name ASC, ID ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )) ?: []);
        }
        $like = '%' . $wpdb->esc_like($search) . '%';
        if (!MemberName::showRealNames()) {
            return array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s ORDER BY display_name ASC, ID ASC LIMIT %d OFFSET %d",
                $like,
                $limit,
                $offset
            )) ?: []);
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT u.ID FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
             LEFT JOIN {$wpdb->usermeta} bfn ON bfn.user_id = u.ID AND bfn.meta_key = 'billing_first_name'
             LEFT JOIN {$wpdb->usermeta} bln ON bln.user_id = u.ID AND bln.meta_key = 'billing_last_name'
             WHERE u.display_name LIKE %s
                OR CONCAT_WS(' ', fn.meta_value, ln.meta_value) LIKE %s
                OR CONCAT_WS(' ', ln.meta_value, fn.meta_value) LIKE %s
                OR CONCAT_WS(' ', bfn.meta_value, bln.meta_value) LIKE %s
                OR CONCAT_WS(' ', bln.meta_value, bfn.meta_value) LIKE %s
             ORDER BY u.display_name ASC, u.ID ASC
             LIMIT %d OFFSET %d",
            $like, $like, $like, $like, $like,
            $limit,
            $offset
        )) ?: []);
    }

    public function searchLocations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $query = sanitize_text_field((string) $request->get_param('query'));
        $length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
        if ($length < 3 || $length > 160) {
            return new WP_Error('crcal_invalid_location_query', __('Saisissez au moins trois caractères.', 'community-race-calendar'), ['status' => 400]);
        }

        $rateKey = 'crcal_geocoding_rate_' . get_current_user_id() . '_' . gmdate('YmdHi');
        $requests = (int) get_transient($rateKey);
        if ($requests >= 30) {
            return new WP_Error('crcal_geocoding_rate_limited', __('Trop de recherches de lieux. Réessayez dans une minute.', 'community-race-calendar'), ['status' => 429]);
        }
        set_transient($rateKey, $requests + 1, MINUTE_IN_SECONDS + 5);

        $results = $this->geocoding->search($query);
        return is_wp_error($results) ? $results : new WP_REST_Response(['locations' => $results]);
    }

    public function settings(): WP_REST_Response
    {
        return new WP_REST_Response(['settings' => Plugin::settings()]);
    }

    public function updateSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = (array) $request->get_json_params();
        foreach (['map_enabled', 'show_member_names'] as $key) {
            if (!array_key_exists($key, $input)) {
                // A client that predates an option must not switch it off by omission.
                $input[$key] = Plugin::settings()[$key];
            }
        }
        $settings = Plugin::sanitizeSettings($input);
        if (!(bool) (Plugin::settings()['delete_data_on_uninstall'] ?? false) && $settings['delete_data_on_uninstall']) {
            return new WP_Error(
                'crcal_uninstall_confirmation_required',
                __('Activez la suppression définitive depuis l’écran des réglages avec la confirmation demandée.', 'community-race-calendar'),
                ['status' => 400]
            );
        }
        update_option(Plugin::SETTINGS_OPTION, $settings, false);
        return new WP_REST_Response(['settings' => $settings]);
    }

    /** @return array<string, string>|WP_Error */
    private function dateRange(WP_REST_Request $request): array|WP_Error
    {
        $period = CalendarPeriod::current();
        $from = (string) ($request->get_param('from') ?: $period['from']);
        $to = (string) ($request->get_param('to') ?: $period['to']);
        if (!CalendarPeriod::isDate($from) || !CalendarPeriod::isDate($to) || $from > $to || $from < $period['from'] || $to > $period['to']) {
            return new WP_Error('crcal_invalid_date_range', sprintf(
                /* translators: 1: first allowed date, 2: last allowed date. */
                __('La période doit être comprise entre le %1$s et le %2$s.', 'community-race-calendar'),
                $period['from'],
                $period['to']
            ), ['status' => 400]);
        }
        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string, string>|WP_Error */
    private function reviewDateRange(WP_REST_Request $request): array|WP_Error
    {
        $from = sanitize_text_field((string) $request->get_param('from'));
        $to = sanitize_text_field((string) $request->get_param('to'));
        if (!CalendarPeriod::isDate($from) || !CalendarPeriod::isDate($to) || $from > $to) {
            return new WP_Error('crcal_invalid_review_range', __('Choisissez une date de début antérieure ou égale à la date de fin.', 'community-race-calendar'), ['status' => 400]);
        }
        $fromDate = new \DateTimeImmutable($from, wp_timezone());
        $toDate = new \DateTimeImmutable($to, wp_timezone());
        if ((int) $fromDate->diff($toDate)->format('%a') > 366) {
            return new WP_Error('crcal_review_range_too_large', __('La revue de presse est limitée à 367 jours.', 'community-race-calendar'), ['status' => 400]);
        }
        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed>|WP_Error */
    private function validateCoursePayload(WP_REST_Request $request, bool $isUpdate, ?array $existing = null): array|WP_Error
    {
        $json = $this->coursePayload($request);
        if (is_wp_error($json)) {
            return $json;
        }
        foreach (['name', 'eventDate', 'location', 'eventUrl'] as $scalarKey) {
            if (isset($json[$scalarKey]) && !is_scalar($json[$scalarKey])) {
                return new WP_Error('crcal_invalid_course_payload', __('Les données de la course sont invalides.', 'community-race-calendar'), ['status' => 400]);
            }
        }
        $name = sanitize_text_field((string) ($json['name'] ?? ''));
        $eventDate = sanitize_text_field((string) ($json['eventDate'] ?? ''));
        $location = sanitize_text_field((string) ($json['location'] ?? ''));
        $eventUrl = trim((string) ($json['eventUrl'] ?? ''));
        if ($name === '' || $location === '' || !CalendarPeriod::isDate($eventDate)) {
            return new WP_Error('crcal_invalid_course', __('Nom, date et lieu sont obligatoires.', 'community-race-calendar'), ['status' => 400]);
        }
        if ($this->length($name) > 191 || $this->length($location) > 191) {
            return new WP_Error('crcal_course_field_too_long', __('Le nom et le lieu sont limités à 191 caractères.', 'community-race-calendar'), ['status' => 400]);
        }
        if (!CalendarPeriod::contains($eventDate)) {
            $period = CalendarPeriod::current();
            return new WP_Error('crcal_course_date_out_of_range', sprintf(
                /* translators: 1: first allowed date, 2: last allowed date. */
                __('La date doit être comprise entre le %1$s et le %2$s pour rester visible dans le calendrier.', 'community-race-calendar'),
                $period['from'],
                $period['to']
            ), ['status' => 400]);
        }
        if ($eventUrl !== '') {
            if ($this->length($eventUrl) > 2048) {
                return new WP_Error('crcal_invalid_url', __('L’URL est trop longue.', 'community-race-calendar'), ['status' => 400]);
            }
            $eventUrl = esc_url_raw($eventUrl, ['http', 'https']);
            if ($eventUrl === '') {
                return new WP_Error('crcal_invalid_url', __('L’URL doit utiliser HTTP ou HTTPS.', 'community-race-calendar'), ['status' => 400]);
            }
        }

        $hasLatitude = array_key_exists('latitude', $json) && $json['latitude'] !== null && $json['latitude'] !== '';
        $hasLongitude = array_key_exists('longitude', $json) && $json['longitude'] !== null && $json['longitude'] !== '';
        if ($hasLatitude !== $hasLongitude) {
            return new WP_Error('crcal_invalid_coordinates', __('Latitude et longitude doivent être renseignées ensemble.', 'community-race-calendar'), ['status' => 400]);
        }

        if ($hasLatitude) {
            if (!is_numeric($json['latitude']) || !is_numeric($json['longitude'])) {
                return new WP_Error('crcal_invalid_coordinates', __('Les coordonnées GPS sont invalides.', 'community-race-calendar'), ['status' => 400]);
            }
            $latitude = (float) $json['latitude'];
            $longitude = (float) $json['longitude'];
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                return new WP_Error('crcal_invalid_coordinates', __('Les coordonnées GPS sont hors limites.', 'community-race-calendar'), ['status' => 400]);
            }
        } elseif ($isUpdate && $existing !== null && $location === (string) $existing['location']) {
            $latitude = $existing['latitude'] !== null ? (float) $existing['latitude'] : null;
            $longitude = $existing['longitude'] !== null ? (float) $existing['longitude'] : null;
        } else {
            $latitude = null;
            $longitude = null;
        }

        if (!isset($json['formats']) || !is_array($json['formats'])) {
            return new WP_Error('crcal_invalid_formats', __('Les formats doivent être fournis sous forme de liste.', 'community-race-calendar'), ['status' => 400]);
        }
        $rawFormats = $json['formats'];
        if (count($rawFormats) > self::MAX_FORMATS) {
            /* translators: %d: maximum number of formats per race. */
            return new WP_Error('crcal_too_many_formats', sprintf(__('Une course est limitée à %d formats.', 'community-race-calendar'), self::MAX_FORMATS), ['status' => 400]);
        }
        $formats = [];
        foreach ($rawFormats as $rawFormat) {
            if ($isUpdate && is_array($rawFormat)) {
                if (isset($rawFormat['label']) && !is_scalar($rawFormat['label'])) {
                    return new WP_Error('crcal_invalid_formats', __('Un format est invalide.', 'community-race-calendar'), ['status' => 400]);
                }
                $label = sanitize_text_field((string) ($rawFormat['label'] ?? ''));
                if ($label !== '') {
                    $formats[] = ['id' => absint($rawFormat['id'] ?? 0), 'label' => $label];
                }
            } else {
                if (is_array($rawFormat) && isset($rawFormat['label']) && !is_scalar($rawFormat['label'])) {
                    return new WP_Error('crcal_invalid_formats', __('Un format est invalide.', 'community-race-calendar'), ['status' => 400]);
                }
                if (!is_array($rawFormat) && !is_scalar($rawFormat)) {
                    return new WP_Error('crcal_invalid_formats', __('Un format est invalide.', 'community-race-calendar'), ['status' => 400]);
                }
                $label = sanitize_text_field((string) (is_array($rawFormat) ? ($rawFormat['label'] ?? '') : $rawFormat));
                if ($label !== '') {
                    $formats[] = $isUpdate ? ['id' => 0, 'label' => $label] : $label;
                }
            }
        }
        $labels = array_map(static fn ($format): string => is_array($format) ? $format['label'] : $format, $formats);
        if (array_filter($labels, fn (string $label): bool => $this->length($label) > 100) !== []) {
            return new WP_Error('crcal_format_too_long', __('Chaque format est limité à 100 caractères.', 'community-race-calendar'), ['status' => 400]);
        }
        $normalizedLabels = array_map(static fn (string $label): string => strtolower(remove_accents($label)), $labels);
        if ($formats === [] || count($labels) !== count(array_unique($normalizedLabels))) {
            return new WP_Error('crcal_invalid_formats', __('Ajoutez au moins un format avec un nom unique.', 'community-race-calendar'), ['status' => 400]);
        }
        return [
            'name' => $name,
            'eventDate' => $eventDate,
            'location' => $location,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'eventUrl' => $eventUrl,
            'formats' => $formats,
            'removeImage' => $isUpdate && !empty($json['removeImage']),
        ];
    }

    /** @return array<string, mixed>|WP_Error */
    private function coursePayload(WP_REST_Request $request): array|WP_Error
    {
        $rawBody = $request->get_body();
        if (is_string($rawBody) && strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error('crcal_payload_too_large', __('Les données de la course sont trop volumineuses.', 'community-race-calendar'), ['status' => 400]);
        }
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }

        $raw = $request->get_param('payload');
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error('crcal_invalid_course_payload', __('Les données de la course sont invalides.', 'community-race-calendar'), ['status' => 400]);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? $decoded
            : new WP_Error('crcal_invalid_course_payload', __('Les données de la course sont invalides.', 'community-race-calendar'), ['status' => 400]);
    }

    private function reusedImageId(WP_REST_Request $request): int|WP_Error
    {
        $json = $this->coursePayload($request);
        $raw = is_array($json) ? ($json['reuseImageId'] ?? null) : null;
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return 0;
        }
        $imageId = is_numeric($raw) ? (int) $raw : 0;
        if ($imageId < 1 || !$this->images->isReusable($imageId)) {
            return new WP_Error('crcal_invalid_reused_image', __('Le logo de l’édition précédente n’est plus disponible.', 'community-race-calendar'), ['status' => 400]);
        }
        return $imageId;
    }

    /** @return array<string, mixed>|null|WP_Error */
    private function imageFile(WP_REST_Request $request): array|null|WP_Error
    {
        $files = $request->get_file_params();
        if (!isset($files['image'])) {
            return null;
        }
        if (!is_array($files['image'])) {
            return new WP_Error('crcal_invalid_image', __('Le fichier image est invalide.', 'community-race-calendar'), ['status' => 400]);
        }
        $valid = $this->images->validate($files['image']);
        return is_wp_error($valid) ? $valid : $files['image'];
    }

    /** @param array<int, array<string, mixed>> $courses @return array<int, array<string, mixed>> */
    private function presentCourses(array $courses): array
    {
        return array_map(function (array $course): array {
            $course['image'] = $this->images->representation((int) ($course['imageId'] ?? 0), (string) $course['name']);
            unset($course['imageId']);
            return $course;
        }, $courses);
    }

    private function findCourseResponse(int $courseId): ?WP_REST_Response
    {
        $userId = get_current_user_id();
        $course = $this->repository->getCourse($courseId, $userId, $this->gate->canManage($userId), $this->gate->hasAccess($userId));
        if (!$course) {
            return null;
        }
        return new WP_REST_Response(['course' => $this->presentCourses([$course])[0]]);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function rateLimit(string $action, int $limit, int $window): bool|WP_Error
    {
        global $wpdb;
        $userId = get_current_user_id();
        $bucket = (int) floor(time() / $window);
        $prefix = 'crcal_rate_v2_' . $window . '_';
        $key = $prefix . $bucket . '_' . md5($action . ':' . $userId);
        $updated = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'off')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $key
        ));
        if ($updated === false) {
            return new WP_Error('crcal_rate_limit_unavailable', __('La requête ne peut pas être traitée pour le moment.', 'community-race-calendar'), ['status' => 503]);
        }
        wp_cache_delete($key, 'options');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT CAST(option_value AS UNSIGNED)
             FROM {$wpdb->options}
             WHERE option_name = %s",
            $key
        ));
        if ($count < 1) {
            return new WP_Error('crcal_rate_limit_unavailable', __('La requête ne peut pas être traitée pour le moment.', 'community-race-calendar'), ['status' => 503]);
        }
        $cleanupKey = 'crcal_rate_cleanup_' . $window . '_' . $bucket;
        if (add_option($cleanupKey, 1, '', false)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND CAST(SUBSTRING_INDEX(SUBSTRING(option_name, %d), '_', 1) AS UNSIGNED) < %d",
                $wpdb->esc_like($prefix) . '%',
                strlen($prefix) + 1,
                max(0, $bucket - 1)
            ));
            delete_option('crcal_rate_cleanup_' . $window . '_' . ($bucket - 1));
        }
        if ($count > $limit) {
            return new WP_Error('crcal_rate_limited', __('Trop de requêtes. Réessayez plus tard.', 'community-race-calendar'), ['status' => 429]);
        }
        return true;
    }

    private function accessMessage(string $code): string
    {
        return match ($code) {
            'authentication_required' => __('Connectez-vous avec votre compte WordPress pour accéder au calendrier.', 'community-race-calendar'),
            'provider_unavailable' => $this->gate->provider()->unavailableReason(),
            'membership_inactive' => __('Votre adhésion n’est pas active.', 'community-race-calendar'),
            default => '',
        };
    }
}
