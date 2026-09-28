<?php
if (!defined('ABSPATH')) {
    exit;
}

class CRM_Coupon_Generator
{

    public static function generate(): string
    {
        global $wpdb;
        $table = CRM_DB::get_coupons_table();
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $char_len = strlen($characters);
        $max_attempts = 10;

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $characters[random_int(0, $char_len - 1)];
            }

            $query = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE coupon_code = %s", $code);
            $exists = (int) $wpdb->get_var($query);

            if ($exists === 0) {
                return $code;
            }
        }

        throw new Exception('در تولید کد تخفیف یکتا خطایی رخ داد. لطفاً مجدداً تلاش کنید.');
    }
}
