<?php
if (!defined('ABSPATH')) exit;

/**
 * Server-side auto-approval of coupon requests.
 *
 * A manager can switch "auto approve" on from the manager panel. The setting is
 * stored per manager phone. While it is on, every pending request of the
 * events that manager is assigned to is approved by the server itself, so it
 * keeps working when the manager has closed the browser:
 *
 *  1. New requests are approved immediately when they are submitted
 *     (see CRM_Ajax::handle_submit_request()).
 *  2. When the switch is turned on, requests that are already pending are
 *     approved right away (a first batch) and the rest by WP-Cron.
 *  3. A recurring WP-Cron job (every 5 minutes) is a safety net that approves
 *     anything that was missed, e.g. after a temporary database error.
 *
 * Only requests of active events (available and not expired) are auto-approved.
 */
class CRM_Auto_Approve
{
    public const OPTION_PREFIX = 'crm_auto_approve_';
    public const CRON_HOOK = 'crm_auto_approve_sweep';
    public const CRON_CONTINUE_HOOK = 'crm_auto_approve_continue';
    public const CRON_SCHEDULE = 'crm_five_minutes';

    /** Requests approved synchronously when the manager turns the switch on. */
    public const REQUEST_BATCH = 10;
    /** Requests approved per background run (each approval sends SMS messages). */
    private const CRON_BATCH = 10;

    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'add_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);
        add_action(self::CRON_CONTINUE_HOOK, [__CLASS__, 'run_cron']);
        add_action('init', [__CLASS__, 'maybe_schedule']);
    }

    public static function add_schedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes (Coupon Request Manager)',
        ];
        return $schedules;
    }

    public static function maybe_schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_CONTINUE_HOOK);
    }

    /* ---------------------------------------------------------------------
     * Setting storage (one non-autoloaded option per manager phone)
     * ------------------------------------------------------------------ */

    private static function option_name(string $phone): ?string
    {
        $phone = CRM_Phone_Helper::normalize($phone);
        return $phone ? self::OPTION_PREFIX . $phone : null;
    }

    public static function is_enabled(string $phone, bool $fresh = false): bool
    {
        $name = self::option_name($phone);
        if (!$name) return false;
        if ($fresh) wp_cache_delete($name, 'options'); // long-running sweeps must see a toggle-off
        return get_option($name, '0') === '1';
    }

    public static function set_enabled(string $phone, bool $enabled): bool
    {
        $name = self::option_name($phone);
        if (!$name) return false;
        if ($enabled) {
            update_option($name, '1', false);
        } else {
            delete_option($name);
        }
        return true;
    }

    /** All manager phones that currently have auto-approve switched on. */
    public static function enabled_phones(): array
    {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value = '1'",
            $wpdb->esc_like(self::OPTION_PREFIX) . '%'
        ));
        $phones = [];
        foreach ($names ?: [] as $name) {
            $phone = CRM_Phone_Helper::normalize(substr($name, strlen(self::OPTION_PREFIX)));
            if ($phone) $phones[$phone] = $phone;
        }
        return array_values($phones);
    }

    /* ---------------------------------------------------------------------
     * Approval
     * ------------------------------------------------------------------ */

    /** First manager of the event that has auto-approve on, or null. */
    private static function approver_for_event(int $event_id): ?string
    {
        foreach (CRM_DB::get_event_manager_phones($event_id) as $manager_phone) {
            $phone = CRM_Phone_Helper::normalize($manager_phone);
            if ($phone && self::is_enabled($phone)) return $phone;
        }
        return null;
    }

    private static function approve(int $request_id, int $event_id, string $phone): bool
    {
        $result = CRM_DB::approve_request($request_id, 'manager', $phone);
        if (is_wp_error($result)) {
            // "invalid_request" just means someone else processed it first.
            if ($result->get_error_code() !== 'invalid_request') {
                error_log('CRM auto-approve failed for request ' . $request_id . ': ' . $result->get_error_message());
            }
            return false;
        }
        CRM_DB::insert_manager_audit($phone, $event_id, 'auto_approve_request', 'request', $request_id, [
            'actor_type' => 'manager',
            'auto' => true,
        ]);
        return true;
    }

    /**
     * Called right after a customer request is stored. Approves it when one of
     * the event's managers has auto-approve on. Never throws: on any problem
     * the request simply stays pending and the background sweep retries it.
     */
    public static function maybe_approve_new_request(int $request_id): bool
    {
        try {
            $request = CRM_DB::get_request_with_event($request_id);
            if (!$request || $request->status !== 'pending') return false;

            $event_id = (int) $request->event_id;
            $event = CRM_DB::get_event_by_id($event_id);
            if (!$event || (int) $event->isAvailable !== 1 || strcmp((string) $event->expires_at, CRM_DB::sql_now()) <= 0) {
                return false;
            }

            $phone = self::approver_for_event($event_id);
            return $phone ? self::approve($request_id, $event_id, $phone) : false;
        } catch (Throwable $e) {
            error_log('CRM auto-approve error for request ' . $request_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Approve up to $limit pending requests of the events managed by $phone.
     *
     * @return array{approved:int,remaining:int} remaining = still pending afterwards
     */
    public static function approve_pending_for_phone(string $phone, int $limit): array
    {
        global $wpdb;
        $result = ['approved' => 0, 'remaining' => 0];
        $phone = CRM_Phone_Helper::normalize($phone);
        if (!$phone) return $result;

        $ids = CRM_DB::manager_event_ids($phone);
        if (!$ids) return $result;

        $in = implode(',', array_map('intval', $ids));
        $requests = CRM_DB::get_requests_table();
        $events = CRM_DB::get_events_table();
        $from = "FROM {$requests} r INNER JOIN {$events} e ON e.id = r.event_id
                 WHERE r.status = 'pending' AND r.event_id IN ({$in})
                   AND e.isAvailable = 1 AND e.expires_at > NOW()";

        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.id, r.event_id {$from} ORDER BY r.id ASC LIMIT %d", max(1, $limit)));
        foreach ($rows ?: [] as $row) {
            if (!self::is_enabled($phone, true)) break; // switched off while we were working
            try {
                if (self::approve((int) $row->id, (int) $row->event_id, $phone)) $result['approved']++;
            } catch (Throwable $e) {
                error_log('CRM auto-approve error for request ' . (int) $row->id . ': ' . $e->getMessage());
            }
        }

        $result['remaining'] = self::is_enabled($phone, true) ? (int) $wpdb->get_var("SELECT COUNT(*) {$from}") : 0;
        return $result;
    }

    public static function schedule_continue(): void
    {
        // The unique argument stops WordPress from de-duplicating close-together events.
        wp_schedule_single_event(time() + 10, self::CRON_CONTINUE_HOOK, [time()]);
        if (function_exists('spawn_cron')) spawn_cron();
    }

    /** Background job: approve pending requests for every manager that has auto-approve on. */
    public static function run_cron(): void
    {
        global $wpdb;
        $lock = 'crm_auto_approve_sweep';
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) return; // another run is active

        try {
            if (function_exists('set_time_limit')) @set_time_limit(120);
            $budget = self::CRON_BATCH;
            $more = false;
            foreach (self::enabled_phones() as $phone) {
                if ($budget <= 0) {
                    $more = true;
                    break;
                }
                $res = self::approve_pending_for_phone($phone, $budget);
                $budget -= $res['approved'];
                if ($res['approved'] > 0 && $res['remaining'] > 0) $more = true;
            }
            if ($more) self::schedule_continue();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
