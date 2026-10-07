<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Access;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class AccessGate
{
    public const MANAGE_CAPABILITY = 'manage_community_race_calendar';

    public function __construct(private AccessProviderInterface $provider)
    {
    }

    /** @var array<int, bool> */
    private array $accessCache = [];

    /** @var array<int, bool> */
    private array $manageCache = [];

    public function provider(): AccessProviderInterface
    {
        return $this->provider;
    }

    public function canManage(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();
        return $this->manageCache[$userId] ??= user_can($userId, self::MANAGE_CAPABILITY);
    }

    public function hasAccess(?int $userId = null): bool
    {
        $userId = $userId ?: get_current_user_id();

        if ($userId < 1) {
            return false;
        }

        if (isset($this->accessCache[$userId])) {
            return $this->accessCache[$userId];
        }
        if ($this->canManage($userId)) {
            return $this->accessCache[$userId] = true;
        }
        $settings = \CommunityRaceCalendar\Plugin::settings();
        $granted = $this->provider->userHasAccess($userId, $settings);
        /**
         * Filters whether a member may use the calendar.
         *
         * Lets a site grant or refuse access with its own rules, for example any logged-in user or a given role,
         * without WooCommerce Memberships. Administrators who manage the calendar always keep access.
         *
         * @param bool                 $granted  Result of the configured access provider.
         * @param int                  $user_id  User being checked.
         * @param array<string, mixed> $settings Calendar settings.
         */
        return $this->accessCache[$userId] = (bool) apply_filters('community_race_calendar_user_has_access', $granted, $userId, $settings);
    }

    public function canCreate(?int $userId = null): bool
    {
        if (!$this->hasAccess($userId)) {
            return false;
        }

        return $this->canManage($userId) || (bool) (\CommunityRaceCalendar\Plugin::settings()['members_can_create'] ?? true);
    }

    public function requireAccess(): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error('crcal_authentication_required', __('Connexion requise.', 'community-race-calendar'), ['status' => 401]);
        }

        if (!$this->provider->isAvailable() && !$this->canManage() && !$this->hasAccess()) {
            return new WP_Error('crcal_access_provider_unavailable', $this->provider->unavailableReason(), ['status' => 503]);
        }

        if (!$this->hasAccess()) {
            return new WP_Error('crcal_membership_inactive', __('Votre adhésion ne permet pas d’accéder au calendrier.', 'community-race-calendar'), ['status' => 403]);
        }

        return true;
    }

    public function requireCreate(): bool|WP_Error
    {
        $access = $this->requireAccess();
        if (is_wp_error($access)) {
            return $access;
        }

        if (!$this->canCreate()) {
            return new WP_Error('crcal_creation_forbidden', __('La création de courses est réservée aux administrateurs.', 'community-race-calendar'), ['status' => 403]);
        }

        return true;
    }

    public function requireManage(): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error('crcal_authentication_required', __('Connexion requise.', 'community-race-calendar'), ['status' => 401]);
        }

        if (!$this->canManage()) {
            return new WP_Error('crcal_admin_required', __('Droits administrateur requis.', 'community-race-calendar'), ['status' => 403]);
        }

        return true;
    }
}
