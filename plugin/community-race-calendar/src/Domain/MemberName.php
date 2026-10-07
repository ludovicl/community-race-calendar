<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nom affiché d'un membre : prénom et nom du profil WordPress, sinon ceux de
 * la facturation WooCommerce, sinon le nom public du compte.
 */
final class MemberName
{
    /** @param array<int, int|string> $userIds */
    public static function prime(array $userIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids !== []) {
            update_meta_cache('user', $ids);
        }
    }

    public static function of(int $userId, ?string $displayName, ?bool $showRealNames = null): string
    {
        $showRealNames ??= self::showRealNames();
        if ($userId > 0 && $showRealNames) {
            foreach ([['first_name', 'last_name'], ['billing_first_name', 'billing_last_name']] as [$firstKey, $lastKey]) {
                $fullName = self::join(
                    (string) get_user_meta($userId, $firstKey, true),
                    (string) get_user_meta($userId, $lastKey, true)
                );
                if ($fullName !== '') {
                    return $fullName;
                }
            }
        }
        $displayName = trim((string) $displayName);
        if (str_contains($displayName, '@')) {
            $displayName = trim(strstr($displayName, '@', true) ?: '');
        }
        return $displayName !== '' ? $displayName : __('Utilisateur supprimé', 'community-race-calendar');
    }

    /**
     * Remplace `display_name` de chaque ligne par le nom complet du membre.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function applyToRows(array $rows): array
    {
        $showRealNames = self::showRealNames();
        if ($showRealNames) {
            self::prime(array_column($rows, 'user_id'));
        }
        foreach ($rows as $index => $row) {
            if (array_key_exists('user_id', $row) && $row['user_id'] !== null) {
                $rows[$index]['display_name'] = self::of((int) $row['user_id'], $row['display_name'] ?? null, $showRealNames);
            }
        }
        return $rows;
    }

    /** Whether profile and billing names may be shown (setting "show_member_names"). */
    public static function showRealNames(): bool
    {
        return (bool) (\CommunityRaceCalendar\Plugin::settings()['show_member_names'] ?? false);
    }

    private static function join(string $first, string $last): string
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', trim($first) . ' ' . trim($last)) ?? '');
        return wp_strip_all_tags($fullName);
    }
}
