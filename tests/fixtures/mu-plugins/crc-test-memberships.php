<?php
/**
 * Test fixture only. Simulates the two public WooCommerce Memberships helpers
 * consumed by the production adapter without shipping another access provider.
 */

if (wp_get_environment_type() === 'production') {
    return;
}

if (!function_exists('wc_memberships_get_user_active_memberships')) {
    function wc_memberships_get_user_active_memberships($user_id = null): array
    {
        $user_id = $user_id ?: get_current_user_id();
        return get_user_meta((int) $user_id, 'crcal_test_active_membership', true) === '1'
            ? [(object) ['id' => 9001, 'name' => 'Adhésion test']]
            : [];
    }
}

if (!function_exists('wc_memberships_is_user_active_member')) {
    function wc_memberships_is_user_active_member($user_id = null, $membership_plan = null): bool
    {
        $active = wc_memberships_get_user_active_memberships($user_id);
        return $active !== [] && ($membership_plan === null || (int) $membership_plan === 9001);
    }
}

if (!function_exists('wc_memberships_get_membership_plans')) {
    function wc_memberships_get_membership_plans(): array
    {
        return [];
    }
}

add_filter('community_race_calendar_geoapify_api_key', static fn (): string => 'local-fixture-key');

add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (!str_starts_with($url, 'https://api.geoapify.com/v1/geocode/autocomplete')) {
        return $preempt;
    }

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $text = sanitize_text_field((string) ($query['text'] ?? 'Lieu test'));
    $normalized = strtolower(remove_accents($text));
    $coordinates = str_contains($normalized, 'paris')
        ? [48.8566, 2.3522]
        : (str_contains($normalized, 'lyon') ? [45.7640, 4.8357] : [46.6034, 1.8883]);

    return [
        'headers' => ['content-type' => 'application/json'],
        'body' => wp_json_encode(['results' => [[
            'formatted' => $text . ', France',
            'lat' => $coordinates[0],
            'lon' => $coordinates[1],
        ]]]),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
}, 10, 3);
