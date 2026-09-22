<?php

namespace Elrayn\AutoNumber;

use DateTimeImmutable;

class NumberFormatter
{
    public static function format(?string $prefix, ?string $period, int $value, int $digits, string $separator): string
    {
        $padded = str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
        $parts = array_filter([$prefix, $period, $padded], function ($p) {
            return $p !== null && $p !== '';
        });

        return implode($separator, $parts);
    }

    public static function periodSuffix(?string $resetPeriod, ?DateTimeImmutable $now = null): ?string
    {
        $now = $now ?? new DateTimeImmutable();

        switch ($resetPeriod) {
            case 'daily':
                return $now->format('Ymd');
            case 'monthly':
                return $now->format('Ym');
            case 'yearly':
                return $now->format('Y');
            default:
                return null;
        }
    }
}
