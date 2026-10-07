<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Geocoding;

use CommunityRaceCalendar\Plugin;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class GeoapifyGeocodingProvider implements GeocodingProviderInterface
{
    private const ENDPOINT = 'https://api.geoapify.com/v1/geocode/autocomplete';
    private const STATUS_TRANSIENT = 'crcal_geoapify_operational';

    public function key(): string
    {
        return 'geoapify';
    }

    public function label(): string
    {
        return 'Geoapify';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey() !== '';
    }

    public function search(string $query): array|WP_Error
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return new WP_Error(
                'crcal_geocoding_unavailable',
                __('La recherche automatique de lieux n’est pas configurée.', 'community-race-calendar'),
                ['status' => 503]
            );
        }

        $cacheKey = 'crcal_geocoding_' . md5(hash('sha256', $apiKey) . '|' . strtolower(remove_accents($query)));
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $results = $this->request($query, $apiKey);
        if (is_wp_error($results)) {
            return $results;
        }
        set_transient($cacheKey, $results, DAY_IN_SECONDS);
        return $results;
    }

    public function verify(): bool|WP_Error
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return new WP_Error(
                'crcal_geocoding_unavailable',
                __('La recherche de lieux n’est pas configurée.', 'community-race-calendar'),
                ['status' => 503]
            );
        }
        $results = $this->request('Lyon', $apiKey);
        if (is_wp_error($results)) {
            return $results;
        }
        if ($results === []) {
            set_transient(self::STATUS_TRANSIENT, '0', 5 * MINUTE_IN_SECONDS);
            return new WP_Error(
                'crcal_geocoding_empty_response',
                __('Geoapify n’a retourné aucun résultat lors du test.', 'community-race-calendar'),
                ['status' => 502]
            );
        }
        return true;
    }

    /** @return array<int, array{label: string, latitude: float, longitude: float}>|WP_Error */
    private function request(string $query, string $apiKey): array|WP_Error
    {
        $url = add_query_arg([
            'text' => $query,
            'format' => 'json',
            'limit' => 5,
            'lang' => 'fr',
            'apiKey' => $apiKey,
        ], self::ENDPOINT);
        $response = wp_remote_get($url, [
            'timeout' => 6,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($status === 401 || $status === 403) {
            set_transient(self::STATUS_TRANSIENT, '0', HOUR_IN_SECONDS);
            return new WP_Error(
                'crcal_geocoding_key_rejected',
                __('La clé Geoapify est configurée mais refusée. Prévenez un administrateur.', 'community-race-calendar'),
                ['status' => 502]
            );
        }
        if (is_wp_error($response) || $status !== 200) {
            set_transient(self::STATUS_TRANSIENT, '0', 5 * MINUTE_IN_SECONDS);
            return new WP_Error(
                'crcal_geocoding_failed',
                __('La recherche de lieux est momentanément indisponible.', 'community-race-calendar'),
                ['status' => 502]
            );
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        $results = [];
        foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $item) {
            if (!is_numeric($item['lat'] ?? null) || !is_numeric($item['lon'] ?? null)) {
                continue;
            }
            $label = sanitize_text_field((string) ($item['formatted'] ?? $item['address_line1'] ?? ''));
            if ($label === '') {
                continue;
            }
            $results[] = [
                'label' => $label,
                'latitude' => (float) $item['lat'],
                'longitude' => (float) $item['lon'],
            ];
        }

        set_transient(self::STATUS_TRANSIENT, '1', HOUR_IN_SECONDS);
        return $results;
    }

    public function operationalStatus(): ?bool
    {
        $status = get_transient(self::STATUS_TRANSIENT);
        return $status === false ? null : $status === '1';
    }

    private function apiKey(): string
    {
        return trim((string) apply_filters(
            'community_race_calendar_geoapify_api_key',
            Plugin::effectiveGeoapifyApiKey()
        ));
    }
}
