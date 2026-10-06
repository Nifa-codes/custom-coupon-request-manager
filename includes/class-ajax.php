<?php

/**
 * AJAX handlers for the Coupon Request Manager plugin.
 *
 * @package CouponRequestManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CRM_Ajax
{
    private static bool $initialized = false;

    /**
     * Register all AJAX hooks.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Frontend actions (public + logged-in)
        add_action('wp_ajax_crm_submit_request', [__CLASS__, 'handle_submit_request']);
        add_action('wp_ajax_nopriv_crm_submit_request', [__CLASS__, 'handle_submit_request']);

        // Admin actions (logged-in only)
        add_action('wp_ajax_crm_retry_otp', [__CLASS__, 'handle_retry_otp']);
        add_action('wp_ajax_crm_mark_coupon_used', [__CLASS__, 'handle_mark_coupon_used']);
    }

    /**
     * Handle frontend coupon request submissions.
     */
    public static function handle_submit_request(): void
    {
        check_ajax_referer('crm_frontend_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

        if ($phone === '') {
            wp_send_json_error(['message' => 'شماره موبایل الزامی است.']);
        }

        // Convert Persian/Arabic digits to English digits.
        $normalized_phone = CRM_Phone_Helper::normalize($phone);

        if ($normalized_phone === null) {
            wp_send_json_error(['message' => 'فرمت شماره موبایل معتبر نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹']);
        }

        $phone = $normalized_phone;

        // Normalize to 09xxxxxxxxx
        if (strpos($phone, '0098') === 0) {
            $phone = '0' . substr($phone, 4);
        } elseif (strpos($phone, '+98') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strlen($phone) === 10 && strpos($phone, '9') === 0) {
            $phone = '0' . $phone;
        }

        if (!preg_match('/^09\d{9}$/', $phone)) {
            wp_send_json_error(['message' => 'فرمت شماره موبایل معتبر نیست.']);
        }

        if ($event_id <= 0) {
            wp_send_json_error(['message' => 'شناسه رویداد نامعتبر است.']);
        }

        // CRM_DB has get_event_by_id(), not get_active_event_by_id()
        $event = CRM_DB::get_event_by_id($event_id);

        if (!$event || !isset($event->isAvailable) || (int) $event->isAvailable !== 1) {
            wp_send_json_error([
                'message' => 'رویداد انتخابی معتبر نیست، غیرفعال شده یا تاریخ آن منقضی شده است.',
            ]);
        }

        // CRM_DB::has_pending_request() only accepts ($phone)
        if (CRM_DB::has_pending_request($phone)) {
            wp_send_json_error(['message' => 'درخواست شما قبلاً ثبت شده و در انتظار بررسی است.']);
        }

        // CRM_DB has insert_request(), not insert_pending_request()
        $request_id = CRM_DB::insert_request($phone, $event_id);
        if ($request_id === false) {
            wp_send_json_error(['message' => 'خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.']);
        }

        // If one of the event's managers has auto-approve switched on, approve right
        // away. This never fails the submission: on any problem the request stays
        // pending and the background sweep retries it.
        if (class_exists('CRM_Auto_Approve')) {
            CRM_Auto_Approve::maybe_approve_new_request((int) $request_id);
        }

        wp_send_json_success([
            'message'    => 'درخواست شما با موفقیت ثبت شد و پس از بررسی، کد تخفیف پیامک خواهد شد.',
            'request_id' => (int) $request_id,
        ]);
    }

    /**
     * Admin: Retry sending SMS OTP for a coupon.
     */
    public static function handle_retry_otp(): void
    {
        check_ajax_referer('crm_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }

        $coupon_id = isset($_POST['coupon_id']) ? absint($_POST['coupon_id']) : 0;
        if ($coupon_id <= 0) {
            wp_send_json_error(['message' => 'شناسه کوپن نامعتبر است.']);
        }

        if (!class_exists('CRM_Admin') || !method_exists('CRM_Admin', 'retry_send_otp')) {
            wp_send_json_error(['message' => 'متد ارسال مجدد پیامک در نسخه فعلی افزونه یافت نشد.']);
        }

        $sent = CRM_Admin::retry_send_otp($coupon_id);

        if ($sent) {
            wp_send_json_success(['message' => 'پیامک با موفقیت مجدداً ارسال شد.']);
        }

        wp_send_json_error(['message' => 'خطا در ارسال پیامک. لطفاً اعتبار پنل یا شماره‌های ثبت‌شده را بررسی نمایید.']);
    }

    /**
     * Admin: Mark a coupon as used.
     */
    public static function handle_mark_coupon_used(): void
    {
        check_ajax_referer('crm_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }

        $coupon_id = isset($_POST['coupon_id']) ? absint($_POST['coupon_id']) : 0;
        if ($coupon_id <= 0) {
            wp_send_json_error(['message' => 'شناسه کوپن نامعتبر است.']);
        }

        $now = CRM_DB::sql_now();
        $success = CRM_DB::mark_coupon_as_used($coupon_id, $now);

        if (!$success) {
            wp_send_json_error(['message' => 'خطا در به‌روزرسانی وضعیت کوپن.']);
        }

        $coupon_data = CRM_DB::get_coupon_details_by_id($coupon_id);
        $current_user = wp_get_current_user();
        $actor_identifier = $current_user->display_name ?: $current_user->user_login;
        CRM_DB::insert_manager_audit(
            $actor_identifier,
            $coupon_data ? (int) $coupon_data->available_event_id : null,
            'mark_coupon_used',
            'coupon',
            $coupon_id,
            ['actor_type' => 'admin']
        );

        wp_send_json_success([
            'message'        => 'کوپن با موفقیت به‌عنوان استفاده‌شده علامت‌گذاری شد.',
            'used_at'        => $now,
            'formatted_date' => $now,
        ]);
    }
}
