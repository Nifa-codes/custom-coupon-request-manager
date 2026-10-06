<?php
if (!defined('ABSPATH')) exit;

class CRM_Manager_Ajax
{
    private const COOKIE = 'crm_manager_session';

    public static function init(): void
    {
        $actions = [
            'request_otp',
            'verify_otp',
            'logout',
            'get_dashboard',
            'get_requests',
            'approve_request',
            'disapprove_request',
            'set_auto_approve',
            'get_coupons',
            'mark_coupon_used',
        ];
        foreach ($actions as $action) {
            add_action('wp_ajax_crm_manager_' . $action, [__CLASS__, $action]);
            add_action('wp_ajax_nopriv_crm_manager_' . $action, [__CLASS__, $action]);
        }
    }

    public static function session_phone(): ?string
    {
        $token = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : '';
        $phone = CRM_DB::validate_manager_session($token);
        return $phone && CRM_DB::get_events_for_manager_phone($phone) ? $phone : null;
    }

    private static function phone(): string
    {
        check_ajax_referer('crm_manager_nonce', 'nonce');
        $phone = self::session_phone();
        if (!$phone) wp_send_json_error(['message' => 'نشست شما پایان یافته است. دوباره وارد شوید.', 'expired' => true], 401);
        return $phone;
    }

    private static function cookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => COOKIEPATH ?: '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function request_otp(): void
    {
        check_ajax_referer('crm_manager_nonce', 'nonce');
        $phone = CRM_Phone_Helper::normalize(isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '');
        if (!$phone || !CRM_DB::get_events_for_manager_phone($phone)) {
            wp_send_json_error(['message' => 'این شماره به رویدادی اختصاص ندارد.'], 403);
        }
        global $wpdb;
        $lock = 'crm_manager_otp_' . substr(hash('sha256', $phone), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) {
            wp_send_json_error(['message' => 'لطفاً دوباره تلاش کنید.'], 429);
        }
        try {
            $limits = CRM_DB::manager_otp_limits($phone);
            if ($limits['count'] >= 7) {
                wp_send_json_error(['message' => 'سقف هفت درخواست در ساعت پر شده است.'], 429);
            }
            if ($limits['latest'] && strtotime($limits['latest']) > strtotime(CRM_DB::sql_now()) - 60) {
                wp_send_json_error(['message' => 'برای ارسال دوباره، ۶۰ ثانیه صبر کنید.'], 429);
            }
            $body_id = (string) get_option('crm_melipayamak_body_id_manager_otp', '');
            if (!ctype_digit($body_id) || (int) $body_id <= 0) {
                wp_send_json_error(['message' => 'کد متن ورود مدیر در تنظیمات پیامک ثبت نشده است.'], 503);
            }
            $code = (string) random_int(100000, 999999);
            $id = CRM_DB::create_manager_otp($phone, hash_hmac('sha256', $code, wp_salt('auth')));
            if (!$id) wp_send_json_error(['message' => 'ثبت کد ورود ناموفق بود.'], 500);
            // Reorder this array to match the configured Melipayamak template.
            $args = [$code];
            $sent = (new CRM_Melipayamak())->sendOtp($phone, $body_id, $args);
            if (!$sent) {
                CRM_DB::invalidate_manager_otps($phone, $id);
                $events = CRM_DB::get_events_for_manager_phone($phone);
                CRM_DB::insert_manager_audit($phone, $events ? (int) $events[0]->id : null, 'sms_failed', 'manager', null, [
                    'manager_phone' => $phone,
                    'context' => 'manager_otp',
                ]);
                wp_send_json_error(['message' => 'ارسال پیامک ناموفق بود.'], 502);
            }
            wp_send_json_success(['message' => 'کد ورود ارسال شد.']);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function verify_otp(): void
    {
        check_ajax_referer('crm_manager_nonce', 'nonce');
        $phone = CRM_Phone_Helper::normalize(isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '');
        $code = isset($_POST['code']) ? trim((string) wp_unslash($_POST['code'])) : '';
        $code = strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (!$phone || !preg_match('/^\d{6}$/', $code) || !CRM_DB::get_events_for_manager_phone($phone)) {
            wp_send_json_error(['message' => 'شماره یا کد ورود معتبر نیست.'], 400);
        }
        $otp = CRM_DB::get_latest_valid_manager_otp($phone);
        if (!$otp) wp_send_json_error(['message' => 'کد منقضی شده یا تعداد تلاش‌ها تمام شده است.'], 400);
        CRM_DB::increment_manager_otp_attempts((int) $otp->id);
        if (!hash_equals($otp->otp_hash, hash_hmac('sha256', $code, wp_salt('auth')))) {
            wp_send_json_error(['message' => 'کد ورود نادرست است.'], 400);
        }
        if (!CRM_DB::consume_manager_otp($phone, (int) $otp->id)) {
            wp_send_json_error(['message' => 'کد قبلاً استفاده شده یا منقضی شده است.'], 400);
        }
        $token = bin2hex(random_bytes(32));
        if (!CRM_DB::create_manager_session($phone, $token)) wp_send_json_error(['message' => 'ایجاد نشست ناموفق بود.'], 500);
        self::cookie($token, time() + DAY_IN_SECONDS);
        $events = CRM_DB::get_events_for_manager_phone($phone);
        CRM_DB::insert_manager_audit($phone, $events ? (int) $events[0]->id : null, 'manager_login', 'manager', null, ['manager_phone' => $phone]);
        wp_send_json_success(['message' => 'ورود موفق بود.']);
    }

    public static function logout(): void
    {
        check_ajax_referer('crm_manager_nonce', 'nonce');
        $phone = self::session_phone();
        $token = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : '';
        CRM_DB::delete_manager_session($token);
        self::cookie('', time() - HOUR_IN_SECONDS);
        if ($phone) {
            $events = CRM_DB::get_events_for_manager_phone($phone);
            CRM_DB::insert_manager_audit($phone, $events ? (int) $events[0]->id : null, 'manager_logout', 'manager', null, ['manager_phone' => $phone]);
        }
        wp_send_json_success(['message' => 'خارج شدید.']);
    }

    public static function get_dashboard(): void
    {
        $phone = self::phone();
        wp_send_json_success(['counts' => CRM_DB::get_manager_dashboard($phone)]);
    }

    public static function get_requests(): void
    {
        $phone = self::phone();
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        if ($date !== '') {
            // The picker sends "1405-07-09 23:59"; to_sql() needs a time, so add one if it's missing.
            $sql_date = CRM_Jalali_Date::to_sql(strpos($date, ':') === false ? $date . ' 00:00' : $date);
            if ($sql_date === null) {
                wp_send_json_error(['message' => 'تاریخ شمسی معتبر نیست.'], 400);
            }
            $date = substr($sql_date, 0, 10); // Gregorian Y-m-d for the existing SQL filter
        }
        wp_send_json_success([
            'rows' => CRM_DB::get_manager_requests($phone, $search, $status, $date),
            'auto_approve' => CRM_Auto_Approve::is_enabled($phone, true),
        ]);
    }

    private static function request_target(string $phone): int
    {
        $id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
        $request = $id ? CRM_DB::get_request_with_event($id) : null;
        if (!$request || !CRM_DB::is_phone_manager_of_event($phone, (int) $request->event_id) || $request->status !== 'pending') {
            wp_send_json_error(['message' => 'درخواست در دسترس نیست یا قبلاً پردازش شده است.'], 403);
        }
        return $id;
    }

    public static function approve_request(): void
    {
        $phone = self::phone();
        $result = CRM_DB::approve_request(self::request_target($phone), 'manager', $phone);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
        wp_send_json_success(['message' => 'درخواست تأیید شد.']);
    }

    public static function disapprove_request(): void
    {
        $phone = self::phone();
        $result = CRM_DB::disapprove_request(self::request_target($phone), 'manager', $phone);
        if (!$result) wp_send_json_error(['message' => 'رد درخواست ناموفق بود.'], 400);
        wp_send_json_success(['message' => 'درخواست رد شد.']);
    }

    public static function set_auto_approve(): void
    {
        $phone = self::phone();
        $enabled = isset($_POST['enabled']) && (string) wp_unslash($_POST['enabled']) === '1';
        if (!CRM_Auto_Approve::set_enabled($phone, $enabled)) {
            wp_send_json_error(['message' => 'ذخیره تنظیمات ناموفق بود.'], 500);
        }
        $events = CRM_DB::get_events_for_manager_phone($phone);
        CRM_DB::insert_manager_audit($phone, $events ? (int) $events[0]->id : null, $enabled ? 'auto_approve_on' : 'auto_approve_off', 'manager', null, ['manager_phone' => $phone]);

        $approved = 0;
        $remaining = 0;
        if ($enabled) {
            // Approve a first batch of the already-pending requests now; the rest
            // is finished in the background by WP-Cron.
            $res = CRM_Auto_Approve::approve_pending_for_phone($phone, CRM_Auto_Approve::REQUEST_BATCH);
            $approved = $res['approved'];
            $remaining = $res['remaining'];
            if ($remaining > 0) CRM_Auto_Approve::schedule_continue();
        }
        wp_send_json_success(['enabled' => $enabled, 'approved' => $approved, 'remaining' => $remaining]);
    }

    public static function get_coupons(): void
    {
        $phone = self::phone();
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $used = isset($_POST['used']) ? sanitize_text_field(wp_unslash($_POST['used'])) : '';
        wp_send_json_success(['rows' => CRM_DB::get_manager_coupons($phone, $search, $used)]);
    }

    public static function mark_coupon_used(): void
    {
        $phone = self::phone();
        $id = isset($_POST['coupon_id']) ? absint($_POST['coupon_id']) : 0;
        $coupon = $id ? CRM_DB::get_coupon_details_by_id($id) : null;
        if (!$coupon || !CRM_DB::is_phone_manager_of_event($phone, (int) $coupon->available_event_id) || (int) $coupon->is_used) {
            wp_send_json_error(['message' => 'کوپن در دسترس نیست یا قبلاً استفاده شده است.'], 403);
        }
        $updated = CRM_DB::mark_coupon_as_used($id, CRM_DB::sql_now(), $phone, 'manager');
        if (!$updated) wp_send_json_error(['message' => 'ثبت استفاده ناموفق بود.'], 400);
        CRM_DB::insert_manager_audit($phone, (int) $coupon->available_event_id, 'mark_coupon_used', 'coupon', $id, ['actor_type' => 'manager']);
        wp_send_json_success(['message' => 'کوپن استفاده‌شده ثبت شد.']);
    }
}
