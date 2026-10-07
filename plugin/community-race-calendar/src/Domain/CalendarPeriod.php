<?php

declare(strict_types=1);

namespace CommunityRaceCalendar\Domain;

use DateTimeImmutable;

if (!defined('ABSPATH')) {
    exit;
}

final class CalendarPeriod
{
    public const MONTHS_BEFORE = 1;
    public const MONTHS_AFTER = 10;
    public const MAX_QUERY_DAYS = 370;

    /** @return array{from: string, to: string} */
    public static function current(): array
    {
        $now = new DateTimeImmutable('now', wp_timezone());
        return [
            'from' => $now->modify('first day of -' . self::MONTHS_BEFORE . ' month')->format('Y-m-d'),
            'to' => $now->modify('last day of +' . self::MONTHS_AFTER . ' months')->format('Y-m-d'),
        ];
    }

    public static function contains(string $date): bool
    {
        $period = self::current();
        return self::isDate($date) && $date >= $period['from'] && $date <= $period['to'];
    }

    public static function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
