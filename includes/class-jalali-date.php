<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Convert a Jalali wall-clock date into a Gregorian SQL datetime without timezone shifts. */
final class CRM_Jalali_Date
{
    public static function to_sql(string $value): ?string
    {
        $value = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (!preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $parts)) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = [
            (int) $parts[1], (int) $parts[2], (int) $parts[3],
            (int) $parts[4], (int) $parts[5], isset($parts[6]) ? (int) $parts[6] : 0,
        ];
        if ($year < 1000 || $year > 1700 || $month < 1 || $month > 12 || $day < 1 || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        [$gy, $gm, $gd] = self::gregorian($year, $month, $day);
        $date = DateTimeImmutable::createFromFormat('!Y-n-j H:i:s', sprintf('%d-%d-%d %02d:%02d:%02d', $gy, $gm, $gd, $hour, $minute, $second), new DateTimeZone('UTC'));
        if (!$date || (int) $date->format('G') !== $hour || (int) $date->format('i') !== $minute) {
            return null;
        }
        $next_year = self::gregorian($year + 1, 1, 1);
        $start = self::gregorian($year, 1, 1);
        $first_day = new DateTimeImmutable(sprintf('%04d-%02d-%02d', ...$start));
        $next_first_day = new DateTimeImmutable(sprintf('%04d-%02d-%02d', ...$next_year));
        $leap = $first_day->diff($next_first_day)->days === 366;
        $max_day = $month <= 6 ? 31 : ($month <= 11 ? 30 : ($leap ? 30 : 29));
        return $day <= $max_day ? $date->format('Y-m-d H:i:s') : null;
    }

    private static function gregorian(int $year, int $month, int $day): array
    {
        $jy = $year + 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4)
            + $day + ($month < 7 ? ($month - 1) * 31 : ($month - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = $gy % 4 === 0 && ($gy % 100 !== 0 || $gy % 400 === 0);
        $lengths = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        while ($gm <= 12 && $gd > $lengths[$gm]) {
            $gd -= $lengths[$gm++];
        }
        return [$gy, $gm, $gd];
    }
}
