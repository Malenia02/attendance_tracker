<?php

namespace App\Support;

use Carbon\Carbon;

final class DtrPeriod
{
    public const FIRST_HALF = 'first_half';

    public const SECOND_HALF = 'second_half';

    public const FULL_MONTH = 'full_month';

    public static function values(): array
    {
        return [self::FIRST_HALF, self::SECOND_HALF, self::FULL_MONTH];
    }

    public static function normalize(?string $period): string
    {
        return in_array($period, self::values(), true) ? $period : self::FULL_MONTH;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(Carbon $month, string $period): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return match (self::normalize($period)) {
            self::FIRST_HALF => [$start, $start->copy()->day(15)->endOfDay()],
            self::SECOND_HALF => [$start->copy()->day(16)->startOfDay(), $end],
            default => [$start, $end],
        };
    }

    public static function label(Carbon $month, string $period): string
    {
        [$start, $end] = self::bounds($month, $period);

        return self::normalize($period) === self::FULL_MONTH
            ? $month->format('F Y')
            : $start->format('F j').'–'.$end->format('j, Y');
    }

    public static function fileSuffix(Carbon $month, string $period): string
    {
        [$start, $end] = self::bounds($month, $period);

        return self::normalize($period) === self::FULL_MONTH
            ? $month->format('Y-m').'-Full-Month'
            : $start->format('Y-m-d').'-to-'.$end->format('d');
    }

    public static function periodsCoveringDate(Carbon $date): array
    {
        return [
            self::FULL_MONTH,
            $date->day <= 15 ? self::FIRST_HALF : self::SECOND_HALF,
        ];
    }

    public static function conflictingPeriods(string $period): array
    {
        return self::normalize($period) === self::FULL_MONTH
            ? [self::FIRST_HALF, self::SECOND_HALF]
            : [self::FULL_MONTH];
    }
}
