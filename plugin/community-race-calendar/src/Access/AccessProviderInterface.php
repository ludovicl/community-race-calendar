<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Access;

if (!defined('ABSPATH')) {
    exit;
}

interface AccessProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function isAvailable(): bool;

    public function unavailableReason(): string;

    /**
     * @param array<string, mixed> $settings
     */
    public function userHasAccess(int $userId, array $settings): bool;
}
