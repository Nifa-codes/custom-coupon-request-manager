<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CRM_Reports_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'report', 'plural' => 'reports', 'ajax' => false]);
    }

    public static function action_labels(): array
    {
        return [
            'sms_failed' => 'پیامک ناموفق',
            'manager_login' => 'ورود مدیر',
            'manager_logout' => 'خروج مدیر',
            'approve_request' => 'تایید درخواست',
            'reject_request' => 'رد درخواست',
            'mark_coupon_used' => 'استفاده از کوپن',
            'retry_sms' => 'ارسال مجدد پیامک به مشتری',
            'event_created' => 'ایجاد رویداد',
            'event_updated' => 'ویرایش رویداد',
            'auto_approve_request' => 'تایید خودکار درخواست',
            'auto_approve_on' => 'فعال‌سازی تایید خودکار',
            'auto_approve_off' => 'غیرفعال‌سازی تایید خودکار',
        ];
    }

    public function get_columns(): array
    {
        return [
            'created_at' => 'تاریخ و ساعت',
            'actor_identifier' => 'شخص',
            'action' => 'عملیات',
            'event_name' => 'رویداد',
            'target' => 'هدف',
            'details' => 'جزئیات',
        ];
    }

    private static function valid_date(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $audit = CRM_DB::get_manager_audit_table();
        $events = CRM_DB::get_events_table();
        $managers = CRM_DB::get_event_managers_table();
        $coupons = CRM_DB::get_coupons_table();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $action = isset($_GET['audit_action']) ? sanitize_key(wp_unslash($_GET['audit_action'])) : '';
        $from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(e.name LIKE %s OR e.event_manager_phone LIKE %s OR a.actor_identifier LIKE %s
                OR EXISTS (SELECT 1 FROM {$managers} m WHERE m.event_id = a.event_id AND m.manager_phone LIKE %s))";
            array_push($params, $like, $like, $like, $like);
        }
        if (array_key_exists($action, self::action_labels())) {
            $where[] = 'a.action = %s';
            $params[] = $action;
        }
        if (self::valid_date($from)) {
            $where[] = 'a.created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if (self::valid_date($to)) {
            $where[] = 'a.created_at < DATE_ADD(%s, INTERVAL 1 DAY)';
            $params[] = $to . ' 00:00:00';
        }
        $where_sql = implode(' AND ', $where);
        $from_sql = "FROM {$audit} a LEFT JOIN {$events} e ON e.id = a.event_id";
        $count_sql = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
        $per_page = 20;
        $page = max(1, $this->get_pagenum());
        $query = "SELECT a.*, e.name AS event_name, c.coupon_code AS target_coupon_code
            {$from_sql} LEFT JOIN {$coupons} c ON a.target_type = 'coupon' AND c.id = a.target_id
            WHERE {$where_sql} ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, ($page - 1) * $per_page]);
        $this->items = $wpdb->get_results($wpdb->prepare($query, $query_params)) ?: [];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    private static function target($item): string
    {
        $details = json_decode((string) $item->details, true);
        if (!is_array($details)) {
            $details = [];
        }
        if ($item->target_type === 'request' && (int) $item->target_id > 0) {
            $id = (int) $item->target_id;
            $url = add_query_arg(['page' => 'coupon-request-manager', 's' => (string) $id, 'audit_request_id' => $id], admin_url('admin.php'));
            return '<a href="' . esc_url($url) . '">' . esc_html('#' . $id) . '</a>';
        }
        if ($item->target_type === 'event' && (int) $item->target_id > 0) {
            $id = (int) $item->target_id;
            $url = add_query_arg(['page' => 'crm-events', 's' => (string) $id, 'audit_event_id' => $id], admin_url('admin.php'));
            return '<a href="' . esc_url($url) . '">' . esc_html('#' . $id) . '</a>';
        }
        if ($item->target_type === 'coupon') {
            if (empty($item->target_coupon_code)) {
                return esc_html('کوپن حذف‌شده');
            }
            $code = (string) $item->target_coupon_code;
            $url = add_query_arg(['page' => 'crm-coupons', 's' => $code], admin_url('admin.php'));
            return '<a href="' . esc_url($url) . '"><code>' . esc_html($code) . '</code></a>';
        }
        if ($item->target_type === 'manager') {
            $phone = $details['manager_phone'] ?? $item->actor_identifier;
            return esc_html(is_scalar($phone) ? (string) $phone : '—');
        }
        return esc_html($item->target_type ?: '—');
    }

    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'created_at':
                return esc_html((string) $item->created_at);
            case 'actor_identifier':
                return esc_html((string) $item->actor_identifier);
            case 'action':
                $labels = self::action_labels();
                return esc_html($labels[$item->action] ?? $item->action);
            case 'event_name':
                return esc_html($item->event_name ?: '—');
            case 'target':
                return self::target($item);
            case 'details':
                $details = json_decode((string) $item->details, true);
                if (is_array($details)) {
                    unset($details['manager_phone']);
                    if (!$details) return '—';
                    return '<span class="crm-report-details" dir="auto">' . esc_html(wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</span>';
                }
                return $item->details ? esc_html((string) $item->details) : '—';
        }
        return '';
    }
}
