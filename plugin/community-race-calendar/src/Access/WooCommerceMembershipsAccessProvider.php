<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Access;

if (!defined('ABSPATH')) {
    exit;
}

final class WooCommerceMembershipsAccessProvider implements AccessProviderInterface
{
    public function key(): string
    {
        return 'woocommerce_memberships';
    }

    public function label(): string
    {
        return 'WooCommerce Memberships';
    }

    public function isAvailable(): bool
    {
        return function_exists('wc_memberships_get_user_active_memberships')
            && function_exists('wc_memberships_is_user_active_member');
    }

    public function unavailableReason(): string
    {
        return __('WooCommerce Memberships doit être actif pour autoriser les membres.', 'community-race-calendar');
    }

    public function userHasAccess(int $userId, array $settings): bool
    {
        if ($userId < 1 || !$this->isAvailable()) {
            return false;
        }

        $planIds = array_values(array_filter(array_map(
            'absint',
            is_array($settings['membership_plan_ids'] ?? null) ? $settings['membership_plan_ids'] : []
        )));

        if ($planIds === []) {
            return !empty(wc_memberships_get_user_active_memberships($userId));
        }

        foreach ($planIds as $planId) {
            if (wc_memberships_is_user_active_member($userId, $planId)) {
                return true;
            }
        }

        return false;
    }
}
