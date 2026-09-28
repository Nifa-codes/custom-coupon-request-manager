<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CRM_Phone_Helper
{
    /**
     * Normalize an Iranian mobile number to 09xxxxxxxxx.
     *
     * Accepted input forms:
     * - 09xxxxxxxxx
     * - 9xxxxxxxxx
     * - +989xxxxxxxxx
     * - 00989xxxxxxxxx
     *
     * Persian and Arabic digits are accepted. Spaces, hyphens, and
     * parentheses are ignored. Invalid input returns null.
     */
    public static function normalize($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $phone = strtr((string) $value, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);

        if (!is_string($phone) || !preg_match('/^(?:\+98|0098|0)?9\d{9}$/', $phone)) {
            return null;
        }

        if (strpos($phone, '0098') === 0) {
            $phone = '0' . substr($phone, 4);
        } elseif (strpos($phone, '+98') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strlen($phone) === 10 && strpos($phone, '9') === 0) {
            $phone = '0' . $phone;
        }

        return preg_match('/^09\d{9}$/', $phone) ? $phone : null;
    }
}
