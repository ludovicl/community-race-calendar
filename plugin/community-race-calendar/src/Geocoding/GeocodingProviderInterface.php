<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Geocoding;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

interface GeocodingProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function isAvailable(): bool;

    public function verify(): bool|WP_Error;

    /** @return array<int, array{label: string, latitude: float, longitude: float}>|WP_Error */
    public function search(string $query): array|WP_Error;
}
