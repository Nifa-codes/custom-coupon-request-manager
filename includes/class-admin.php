<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// Request List Table Class
class CRM_Requests_List_Table extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct([
            'singular' => 'request',
            'plural'   => 'requests',
            'ajax'     => false
        ]);
    }

    public function get_columns(): array
    {
        return [
            'id'             => '#',
            'customer_phone' => 'شماره مشتری',
            'event_name'     => 'نام رویداد',
            'status'         => 'وضعیت',
            'created_at'     => 'تاریخ ثبت',
            'actions'        => 'عملیات'
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $req_table = CRM_DB::get_requests_table();
        $evt_table = CRM_DB::get_events_table();

        $per_page = 15;
        $current_page = $this->get_pagenum();

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $audit_request_id = isset($_GET['audit_request_id']) ? absint($_GET['audit_request_id']) : 0;

        $where = ['1=1'];
        $params = [];

        if (in_array($status, ['pending', 'approved', 'disapproved'], true)) {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }

        if ($audit_request_id > 0) {
            $where[] = 'r.id = %d';
            $params[] = $audit_request_id;
        }

        if ($search !== '') {
            $where[] = '(r.customer_phone LIKE %s OR e.name LIKE %s' . (ctype_digit($search) ? ' OR r.id = %d' : '') . ')';
            $like_search = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like_search;
            $params[] = $like_search;
            if (ctype_digit($search)) {
                $params[] = (int) $search;
            }
        }

        $where_clause = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$req_table} r JOIN {$evt_table} e ON r.event_id = e.id WHERE {$where_clause}";
        if (!empty($params)) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total_items = (int) $wpdb->get_var($count_sql);
        }

        $offset = ($current_page - 1) * $per_page;
        $query_sql = "SELECT r.*, e.name as event_name 
                      FROM {$req_table} r 
                      JOIN {$evt_table} e ON r.event_id = e.id 
                      WHERE {$where_clause} 
                      ORDER BY r.id DESC 
                      LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $this->items = $wpdb->get_results($wpdb->prepare($query_sql, $params));

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);

        $this->_column_headers = [$this->get_columns(), [], []];
    }

    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'id':
                return esc_html((string)$item->id);
            case 'customer_phone':
                return esc_html($item->customer_phone);
            case 'event_name':
                return esc_html($item->event_name);
            case 'status':
                if ($item->status === 'pending') {
                    return '<span class="crm-badge crm-badge-pending">در انتظار</span>';
                } elseif ($item->status === 'approved') {
                    return '<span class="crm-badge crm-badge-approved">تأیید شده</span>';
                } else {
                    return '<span class="crm-badge crm-badge-disapproved">رد شده</span>';
                }
            case 'created_at':
                return '<time class="crm-jalali-datetime" data-sql-datetime="' . esc_attr($item->created_at) . '">' . esc_html($item->created_at) . '</time>';
            case 'actions':
                if ($item->status === 'pending') {
                    $approve_nonce = wp_create_nonce('crm_approve_req_' . $item->id);
                    $disapprove_nonce = wp_create_nonce('crm_disapprove_req_' . $item->id);

                    $approve_url = add_query_arg([
                        'action'     => 'crm_approve_request',
                        'request_id' => $item->id,
                        '_wpnonce'   => $approve_nonce
                    ], admin_url('admin.php?page=coupon-request-manager'));

                    $disapprove_url = add_query_arg([
                        'action'     => 'crm_disapprove_request',
                        'request_id' => $item->id,
                        '_wpnonce'   => $disapprove_nonce
                    ], admin_url('admin.php?page=coupon-request-manager'));

                    return sprintf(
                        '<a href="%s" class="button button-primary button-small">%s</a> <a href="%s" class="button button-secondary button-small">%s</a>',
                        esc_url($approve_url),
                        esc_html('تأیید'),
                        esc_url($disapprove_url),
                        esc_html('رد')
                    );
                }
                return '—';
            default:
                return '';
        }
    }
}

// Events List Table Class
class CRM_Events_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(
            [
                'singular' => 'event',
                'plural'   => 'events',
                'ajax'     => false,
            ]
        );
    }

    public function get_columns(): array
    {
        return [
            'id'                  => '#',
            'name'                => 'نام رویداد',
            'discount_value'      => 'میزان تخفیف (%)',
            'event_manager_phone' => 'شماره مدیر رویداد',
            'isAvailable'         => 'وضعیت در دسترس',
            'expires_at'          => 'تاریخ انقضا',
            'created_at'          => 'تاریخ ایجاد',
            'actions'             => 'عملیات',
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;

        $table_name = CRM_DB::get_events_table();

        $per_page = 15;
        $current_page = max(1, $this->get_pagenum());
        $offset = ($current_page - 1) * $per_page;

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $audit_event_id = isset($_GET['audit_event_id']) ? absint($_GET['audit_event_id']) : 0;
        $managers_table = CRM_DB::get_event_managers_table();
        $conditions = [];
        $params = [];
        if ($audit_event_id > 0) {
            $conditions[] = 'e.id = %d';
            $params[] = $audit_event_id;
        }
        if ($search !== '') {
            $conditions[] = "(e.name LIKE %s OR e.event_manager_phone LIKE %s OR EXISTS
                (SELECT 1 FROM {$managers_table} m WHERE m.event_id = e.id AND m.manager_phone LIKE %s)" . (ctype_digit($search) ? ' OR e.id = %d)' : ')');
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
            if (ctype_digit($search)) {
                $params[] = (int) $search;
            }
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $count_sql = "SELECT COUNT(*) FROM {$table_name} e{$where}";
        $total_items = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $query_sql = "SELECT e.* FROM {$table_name} e{$where} ORDER BY e.id DESC LIMIT %d OFFSET %d";
        $this->items = $wpdb->get_results($wpdb->prepare($query_sql, array_merge($params, [$per_page, $offset])));

        $this->set_pagination_args(
            [
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($total_items / $per_page),
            ]
        );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            [],
        ];
    }

    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'id':
                return esc_html((string) $item->id);

            case 'name':
                return esc_html((string) $item->name);

            case 'discount_value':
                return esc_html((string) $item->discount_value);

            case 'event_manager_phone':
                $phones = [];
                if (method_exists('CRM_DB', 'get_event_manager_phones')) {
                    $phones = CRM_DB::get_event_manager_phones((int) $item->id);
                }
                if (empty($phones) && !empty($item->event_manager_phone)) {
                    $phones = [trim((string) $item->event_manager_phone)];
                }

                if (empty($phones)) {
                    return '—';
                }

                return esc_html(implode('، ', $phones));

            case 'isAvailable':
                if ((int) $item->isAvailable === 1) {
                    return '<span class="crm-text-green">فعال</span>';
                }

                return '<span class="crm-text-red">غیرفعال</span>';

            case 'expires_at':
                if (
                    empty($item->expires_at)
                    || $item->expires_at === '0000-00-00'
                    || $item->expires_at === '0000-00-00 00:00:00'
                ) {
                    return 'بدون انقضا';
                }

                return '<time class="crm-jalali-datetime" data-sql-datetime="' . esc_attr($item->expires_at) . '">' . esc_html((string) $item->expires_at) . '</time>';

            case 'created_at':
                if (empty($item->created_at)) {
                    return '—';
                }

                return '<time class="crm-jalali-datetime" data-sql-datetime="' . esc_attr($item->created_at) . '">' . esc_html((string) $item->created_at) . '</time>';

            case 'actions':
                $edit_url = add_query_arg(
                    [
                        'page'   => 'crm-events',
                        'action' => 'edit',
                        'id'     => (int) $item->id,
                    ],
                    admin_url('admin.php')
                );

                return sprintf(
                    '<a href="%1$s" class="button button-small">%2$s</a>
                    <form method="post" action="%3$s" class="crm-event-delete-form"
                       onsubmit="return confirm(\'آیا از حذف این رویداد اطمینان دارید؟\');">
                        <input type="hidden" name="crm_delete_event" value="%4$d">
                        <input type="hidden" name="_wpnonce" value="%5$s">
                        <button type="submit" class="button button-small button-link-delete">%6$s</button>
                    </form>',
                    esc_url($edit_url),
                    esc_html('ویرایش'),
                    esc_url(admin_url('admin.php?page=crm-events')),
                    (int) $item->id,
                    esc_attr(wp_create_nonce('crm_delete_event_' . (int) $item->id)),
                    esc_html('حذف')
                );

            default:
                return '';
        }
    }
}


class CRM_Coupons_List_Table extends WP_List_Table
{
    /**
     * Initialize coupons list table.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'coupon',
            'plural'   => 'coupons',
            'ajax'     => false,
        ]);
    }

    /**
     * Define table columns.
     *
     * @return array
     */
    public function get_columns(): array
    {
        return [
            'id'                => '#',
            'customer_phone'    => 'شماره درخواست‌کننده',
            'coupon_code'       => 'کد تخفیف',
            'event_name'        => 'نام رویداد',
            'is_used'           => 'استفاده شده',
            'used_at'           => 'تاریخ استفاده',
            'expires_at'        => 'تاریخ انقضا',
            'discount_value'    => 'میزان تخفیف (%)',
            'otp_status'        => 'وضعیت پیامک',
            'accepted_by_admin' => 'تأییدکننده',
            'actions'           => 'عملیات',
        ];
    }

    /**
     * Prepare coupon records for display.
     */
    public function prepare_items(): void
    {
        global $wpdb;

        $coupons_table  = CRM_DB::get_coupons_table();
        $requests_table = CRM_DB::get_requests_table();
        $events_table   = CRM_DB::get_events_table();

        $per_page     = 15;
        $current_page = $this->get_pagenum();
        $offset       = ($current_page - 1) * $per_page;

        $search = isset($_GET['s'])
            ? sanitize_text_field(wp_unslash($_GET['s']))
            : '';

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(
                r.customer_phone LIKE %s
                OR c.coupon_code LIKE %s
                OR e.name LIKE %s
            )';

            $like = '%' . $wpdb->esc_like($search) . '%';

            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        /*
         * Count all matching coupons.
         */
        $count_sql = "
            SELECT COUNT(*)
            FROM {$coupons_table} c
            INNER JOIN {$requests_table} r
                ON c.pending_req_id = r.id
            INNER JOIN {$events_table} e
                ON c.available_event_id = e.id
            WHERE {$where_clause}
        ";

        if (!empty($params)) {
            $total_items = (int) $wpdb->get_var(
                $wpdb->prepare($count_sql, $params)
            );
        } else {
            $total_items = (int) $wpdb->get_var($count_sql);
        }

        /*
         * Retrieve current page records.
         */
        $query_sql = "
            SELECT
                c.*,
                r.customer_phone,
                e.name AS event_name,
                e.discount_value,
                e.expires_at
            FROM {$coupons_table} c
            INNER JOIN {$requests_table} r
                ON c.pending_req_id = r.id
            INNER JOIN {$events_table} e
                ON c.available_event_id = e.id
            WHERE {$where_clause}
            ORDER BY c.id DESC
            LIMIT %d OFFSET %d
        ";

        $query_params   = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $this->items = $wpdb->get_results(
            $wpdb->prepare($query_sql, $query_params)
        );

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            [],
        ];
    }

    /**
     * Render table column values.
     *
     * @param object $item
     * @param string $column_name
     *
     * @return string
     */
    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'id':
                return esc_html((string) $item->id);

            case 'customer_phone':
                return esc_html((string) $item->customer_phone);

            case 'coupon_code':
                return '<code>' . esc_html((string) $item->coupon_code) . '</code>';

            case 'event_name':
                return esc_html((string) $item->event_name);

            case 'is_used':
                if ((int) $item->is_used === 1) {
                    return '<span class="crm-text-green">بله</span>';
                }

                return 'خیر';

            case 'used_at':
                $used_at = isset($item->used_at)
                    ? (string) $item->used_at
                    : '';

                if (
                    $used_at === ''
                    || $used_at === '0000-00-00 00:00:00'
                    || $used_at === '0000-00-00'
                ) {
                    return '—';
                }

                return esc_html($used_at);

            case 'expires_at':
                if (
                    empty($item->expires_at)
                    || $item->expires_at === '0000-00-00 00:00:00'
                    || $item->expires_at === '0000-00-00'
                ) {
                    return 'بدون انقضا';
                }

                return '<time class="crm-jalali-datetime" data-sql-datetime="' . esc_attr($item->expires_at) . '">' . esc_html((string) $item->expires_at) . '</time>';

            case 'discount_value':
                return esc_html((string) $item->discount_value);

            case 'otp_status':
                if ((int) $item->otp_sent === 1) {
                    return '<span class="crm-text-green">ارسال شد ✓</span>';
                }

                return sprintf(
                    '<span class="crm-text-red">ناموفق</span> 
                    <button
                        type="button"
                        class="button button-small crm-retry-otp-btn"
                        data-id="%1$d"
                    >%2$s</button>',
                    (int) $item->id,
                    esc_html('ارسال مجدد')
                );

            case 'accepted_by_admin':
                if (!empty($item->accepted_by_admin)) {
                    return esc_html((string) $item->accepted_by_admin);
                }

                return '—';

            case 'actions':
                if ((int) $item->is_used !== 1) {
                    return sprintf(
                        '<button
                            type="button"
                            class="button button-small mark-used-btn"
                            data-id="%1$d"
                        >%2$s</button>',
                        (int) $item->id,
                        esc_html('علامت‌گذاری به‌عنوان استفاده‌شده')
                    );
                }

                return '—';

            default:
                return '';
        }
    }
}



// Main Admin Management Class
class CRM_Admin
{

    private static ?CRM_Admin $instance = null;

    public static function get_instance(): CRM_Admin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            'مدیریت درخواست‌های تخفیف',
            'درخواست‌های تخفیف',
            'manage_options',
            'coupon-request-manager',
            [$this, 'render_requests_page'],
            'dashicons-tickets-alt',
            25
        );

        add_submenu_page(
            'coupon-request-manager',
            'لیست درخواست‌ها',
            'لیست درخواست‌ها',
            'manage_options',
            'coupon-request-manager',
            [$this, 'render_requests_page']
        );

        add_submenu_page(
            'coupon-request-manager',
            'لیست رویدادها',
            'لیست رویدادها',
            'manage_options',
            'crm-events',
            [$this, 'render_events_page']
        );

        add_submenu_page(
            'coupon-request-manager',
            'کدهای تخفیف صادر شده',
            'کدهای تخفیف صادر شده',
            'manage_options',
            'crm-coupons',
            [$this, 'render_coupons_page']
        );

        add_submenu_page(
            'coupon-request-manager',
            'گزارش ها',
            'گزارش ها',
            'manage_options',
            'crm-reports',
            [$this, 'render_reports_page']
        );

        add_submenu_page(
            'coupon-request-manager',
            'تنظیمات ملی‌پیامک',
            'تنظیمات ملی‌پیامک',
            'manage_options',
            'crm-settings',
            [$this, 'render_settings_page']
        );
    }

    public function handle_admin_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

        if ($action === 'crm_approve_request') {
            $req_id = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
            check_admin_referer('crm_approve_req_' . $req_id);
            $this->process_approve_request($req_id);
        }

        if ($action === 'crm_disapprove_request') {
            $req_id = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
            check_admin_referer('crm_disapprove_req_' . $req_id);
            $this->process_disapprove_request($req_id);
        }

        if (isset($_POST['crm_delete_event']) && isset($_GET['page']) && $_GET['page'] === 'crm-events') {
            $event_id = absint($_POST['crm_delete_event']);
            check_admin_referer('crm_delete_event_' . $event_id);
            $this->process_delete_event($event_id);
        }

        if (isset($_POST['crm_save_event'])) {
            check_admin_referer('crm_save_event_nonce');
            $this->process_save_event();
        }

        if (isset($_POST['crm_save_settings'])) {
            check_admin_referer('crm_save_settings_nonce');
            $this->process_save_settings();
        }
    }

    private function process_approve_request(int $req_id): void
    {
        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?: $current_user->user_login;
        $result = CRM_DB::approve_request($req_id, 'admin', $admin_name);

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'invalid_request') {
                $this->redirect_with_notice('coupon-request-manager', 'درخواست نامعتبر است یا قبلاً پردازش شده است.', 'error');
            }

            $this->redirect_with_notice('coupon-request-manager', 'خطا در تأیید درخواست: ' . $result->get_error_message(), 'error');
        }

        $this->redirect_with_notice('coupon-request-manager', 'درخواست با موفقیت تأیید شد و کد تخفیف صادر گردید.', 'success');
    }
    /**
     * ارسال مجدد پیامک OTP به مشتری و تمامی مدیران رویداد
     *
     * @param int $coupon_id
     * @return bool
     */
    public static function retry_send_otp(int $coupon_id): bool
    {
        if ($coupon_id <= 0) {
            return false;
        }

        $coupon_data = CRM_DB::get_coupon_details_by_id($coupon_id);
        if (!$coupon_data) {
            return false;
        }

        $event_id = isset($coupon_data->available_event_id) ? (int) $coupon_data->available_event_id : 0;
        if ($event_id <= 0) {
            return false;
        }

        $sms = new CRM_Melipayamak();
        $body_id_cust = (string) get_option('crm_melipayamak_body_id_customer', '0');
        $body_id_mgr  = (string) get_option('crm_melipayamak_body_id_manager', '0');

        $coupon_code    = (string) ($coupon_data->coupon_code ?? '');
        $event_name     = (string) ($coupon_data->event_name ?? '');
        $customer_phone = (string) ($coupon_data->customer_phone ?? '');

        // 1) Send OTP to customer
        $sent_customer = $sms->sendOtp(
            $customer_phone,
            $body_id_cust,
            [$coupon_code, $event_name]
        );

        // 2) Get all manager phones for this event
        $manager_phones = CRM_DB::get_event_manager_phones($event_id);

        // Fallback for legacy records
        if (empty($manager_phones) && !empty($coupon_data->event_manager_phone)) {
            $manager_phones = [(string) $coupon_data->event_manager_phone];
        }

        $normalized_manager_phones = [];

        foreach ($manager_phones as $manager_phone) {
            $normalized_phone = CRM_Phone_Helper::normalize($manager_phone);

            if ($normalized_phone !== null) {
                $normalized_manager_phones[$normalized_phone] = $normalized_phone;
            }
        }

        $manager_phones = array_values($normalized_manager_phones);


        // 3) Send OTP to all assigned managers
        $any_manager_sent = false;
        foreach ($manager_phones as $manager_phone) {
            if ($manager_phone === '') {
                continue;
            }

            $sent_mgr = $sms->sendOtp(
                $manager_phone,
                $body_id_mgr,
                [$customer_phone, $coupon_code]
            );

            if ($sent_mgr) {
                $any_manager_sent = true;
            }
        }

        $otp_sent = ($sent_customer && $any_manager_sent) ? 1 : 0;
        CRM_DB::update_coupon_otp_status($coupon_id, $otp_sent);

        $current_user = wp_get_current_user();
        $actor_identifier = $current_user->display_name ?: $current_user->user_login;
        if (!$sent_customer || !$any_manager_sent) {
            CRM_DB::insert_manager_audit($actor_identifier, $event_id, 'sms_failed', 'coupon', $coupon_id, [
                'actor_type' => 'admin', 'context' => 'retry_sms',
                'customer_failed' => !$sent_customer, 'manager_failed' => !$any_manager_sent,
            ]);
        }

        if ($sent_customer) {
            CRM_DB::insert_manager_audit(
                $actor_identifier,
                $event_id,
                'retry_sms',
                'coupon',
                $coupon_id,
                ['actor_type' => 'admin', 'manager_sms_sent' => $any_manager_sent]
            );
        }

        return (bool) $otp_sent;
    }


    private function process_disapprove_request(int $req_id): void
    {
        $current_user = wp_get_current_user();
        $admin_name = $current_user->display_name ?: $current_user->user_login;
        $updated = CRM_DB::disapprove_request($req_id, 'admin', $admin_name);
        if ($updated) {
            $this->redirect_with_notice('coupon-request-manager', 'درخواست رد شد.', 'success');
        } else {
            $this->redirect_with_notice('coupon-request-manager', 'خطا در تغییر وضعیت درخواست.', 'error');
        }
    }

    private function process_save_event(): void
    {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'coupon-request-manager'));
        }

        $event_id       = isset($_POST['event_id']) ? (int) $_POST['event_id'] : 0;
        $name           = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $discount_value = isset($_POST['discount_value']) ? (int) $_POST['discount_value'] : 0;
        $is_available   = isset($_POST['isAvailable']) ? 1 : 0;
        $expires_raw    = isset($_POST['expires_at']) ? sanitize_text_field(wp_unslash($_POST['expires_at'])) : '';

        // --- جمع‌آوری شماره‌های مدیران (تک یا چندتایی) ---
        $raw_phones = isset($_POST['event_manager_phones']) ? wp_unslash($_POST['event_manager_phones']) : '';
        if (is_array($raw_phones)) {
            $phone_lines = $raw_phones;
        } else {
            // تفکیک با کاما، سمی‌کالن یا خط جدید
            $phone_lines = preg_split('/[\r\n,;]+/', (string) $raw_phones);
        }

        $manager_phones = [];
        $invalid_manager_phone = false;

        foreach ((array) $phone_lines as $phone) {
            if (trim((string) $phone) === '') {
                continue;
            }

            $normalized_phone = CRM_Phone_Helper::normalize($phone);

            if ($normalized_phone === null) {
                $invalid_manager_phone = true;
                continue;
            }

            $manager_phones[$normalized_phone] = $normalized_phone;
        }

        $manager_phones = array_values($manager_phones);


        // --- اعتبارسنجی ---

        if ($invalid_manager_phone || empty($name) || empty($manager_phones)) {
            wp_safe_redirect(add_query_arg([
                'page'    => 'crm-events',
                'message' => 'missing_fields',
            ], admin_url('admin.php')));
            exit;
        }

        $table  = CRM_DB::get_events_table();
        $format = ['%s', '%d', '%s', '%d'];
        $previous = $event_id > 0 ? CRM_DB::get_event_by_id($event_id) : null;
        if ($event_id > 0 && !$previous) {
            $this->redirect_with_notice('crm-events', 'رویداد یافت نشد.', 'error');
        }
        $previous_phones = $previous ? CRM_DB::get_event_manager_phones($event_id) : [];

        $data = [
            'name'                => $name,
            'discount_value'      => $discount_value,
            // سازگاری با کدهای قدیمی: اولین مدیر در ستون قدیمی ذخیره می‌شود
            'event_manager_phone' => $manager_phones[0],
            'isAvailable'         => $is_available,
        ];

        // --- رفع باگ قدیمی format شامل null ---
        if ($expires_raw !== '') {
            $expires_sql = CRM_Jalali_Date::to_sql($expires_raw);
            if ($expires_sql === null) {
                $this->redirect_with_notice('crm-events', 'تاریخ شمسی انقضا معتبر نیست.', 'error');
            }
            $data['expires_at'] = $expires_sql;
            $format[] = '%s';
        } else {
            // بدون انقضا → حذف صریح مقدار قبلی
            $data['expires_at'] = '2099-12-31 23:59:59';
            $format[] = '%s';
        }

        if ($event_id > 0) {
            $saved = $wpdb->update($table, $data, ['id' => $event_id], $format, ['%d']);
        } else {
            $saved = $wpdb->insert($table, $data, $format);
            $event_id = $saved ? (int) $wpdb->insert_id : 0;
        }

        if ($saved === false || $event_id <= 0 || !CRM_DB::set_event_managers($event_id, $manager_phones)) {
            $this->redirect_with_notice('crm-events', 'خطا در ذخیره رویداد.', 'error');
        }

        $user = wp_get_current_user();
        $actor_identifier = $user->display_name ?: $user->user_login;
        if (!$previous) {
            CRM_DB::insert_manager_audit($actor_identifier, $event_id, 'event_created', 'event', $event_id);
        } else {
            $changes = [];
            foreach ([
                'name' => 'نام رویداد',
                'discount_value' => 'درصد تخفیف',
                'isAvailable' => 'وضعیت دسترسی',
                'expires_at' => 'تاریخ انقضا',
            ] as $field => $label) {
                $before = (string) $previous->{$field};
                $after = (string) $data[$field];
                if ($before !== $after) {
                    $changes[$label] = ['از' => $before, 'به' => $after];
                }
            }
            $old_phones = array_values(array_unique(array_map('strval', $previous_phones)));
            $new_phones = $manager_phones;
            sort($old_phones);
            sort($new_phones);
            if ($old_phones !== $new_phones) {
                $changes['شماره مدیران'] = ['از' => $old_phones, 'به' => $new_phones];
            }
            if ($changes) {
                CRM_DB::insert_manager_audit($actor_identifier, $event_id, 'event_updated', 'event', $event_id, $changes);
            }
        }

        wp_safe_redirect(add_query_arg([
            'page'    => 'crm-events',
            'message' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }


    private function process_delete_event(int $event_id): void
    {
        $event = $event_id > 0 ? CRM_DB::get_event_by_id($event_id) : null;
        if (!$event) {
            $this->redirect_with_notice('crm-events', 'رویداد یافت نشد.', 'error');
        }

        $approved_coupons_count = CRM_DB::count_approved_coupons_for_event($event_id);
        global $wpdb;
        $requests_table = CRM_DB::get_requests_table();
        $requests_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$requests_table} WHERE event_id = %d",
            $event_id
        ));
        if ($requests_count > 0 || $approved_coupons_count > 0) {
            $updated = $wpdb->update(
                CRM_DB::get_events_table(),
                ['isAvailable' => 0],
                ['id' => $event_id],
                ['%d'],
                ['%d']
            );
            if ($updated === false) {
                $this->redirect_with_notice('crm-events', 'خطا در غیرفعال‌کردن رویداد.', 'error');
            }
            if ($updated > 0) {
                $user = wp_get_current_user();
                $actor_identifier = $user->display_name ?: $user->user_login;
                CRM_DB::insert_manager_audit($actor_identifier, $event_id, 'event_updated', 'event', $event_id, [
                    'وضعیت دسترسی' => ['از' => (string) $event->isAvailable, 'به' => '0'],
                ]);
            }

            $reasons = [];
            if ($requests_count > 0) {
                $reasons[] = "{$requests_count} درخواست ثبت‌شده";
            }
            if ($approved_coupons_count > 0) {
                $reasons[] = "{$approved_coupons_count} کد تخفیف صادرشده";
            }
            $this->redirect_with_notice(
                'crm-events',
                'رویداد به دلیل داشتن ' . implode(' و ', $reasons) . ' حذف نشد و غیرفعال شد.',
                'success'
            );
        }

        $deleted = CRM_DB::delete_event($event_id);
        if ($deleted) {
            $this->redirect_with_notice('crm-events', 'رویداد با موفقیت حذف شد.', 'success');
        } else {
            $this->redirect_with_notice('crm-events', 'خطا در حذف رویداد.', 'error');
        }
    }

    private function process_save_settings(): void
    {
        $username = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash($_POST['password'])) : '';
        $body_id_customer = isset($_POST['body_id_customer']) ? sanitize_text_field(wp_unslash($_POST['body_id_customer'])) : '';
        $body_id_manager  = isset($_POST['body_id_manager']) ? sanitize_text_field(wp_unslash($_POST['body_id_manager'])) : '';
        $body_id_manager_otp = isset($_POST['body_id_manager_otp']) ? sanitize_text_field(wp_unslash($_POST['body_id_manager_otp'])) : '';

        update_option('crm_melipayamak_username', $username);
        update_option('crm_melipayamak_password', $password);
        update_option('crm_melipayamak_body_id_customer', $body_id_customer);
        update_option('crm_melipayamak_body_id_manager', $body_id_manager);
        update_option('crm_melipayamak_body_id_manager_otp', $body_id_manager_otp);

        $this->redirect_with_notice('crm-settings', 'تنظیمات با موفقیت ذخیره شدند.', 'success');
    }

    private function redirect_with_notice(string $page, string $message, string $type = 'success'): void
    {
        $url = add_query_arg([
            'page'        => $page,
            'crm_notice'  => urlencode($message),
            'crm_ntype'   => $type
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function display_notices(): void
    {
        if (isset($_GET['crm_notice'])) {
            $message = sanitize_text_field(wp_unslash($_GET['crm_notice']));
            $type = isset($_GET['crm_ntype']) && $_GET['crm_ntype'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public function render_requests_page(): void
    {
        global $wpdb;
        $req_table = CRM_DB::get_requests_table();

        $table = new CRM_Requests_List_Table();
        $table->prepare_items();

        $total_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$req_table}");
        $total_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$req_table} WHERE status = 'pending'");
        $total_approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$req_table} WHERE status = 'approved'");
        $total_disapproved = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$req_table} WHERE status = 'disapproved'");

        $current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
?>
        <div class="wrap crm-admin-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html('لیست درخواست‌های تخفیف'); ?></h1>
            <?php $this->display_notices(); ?>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=coupon-request-manager')); ?>" class="<?php echo empty($current_status) ? 'current' : ''; ?>"><?php echo esc_html("نمایش همه ({$total_all})"); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=coupon-request-manager&status=pending')); ?>" class="<?php echo $current_status === 'pending' ? 'current' : ''; ?>"><?php echo esc_html("در انتظار ({$total_pending})"); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=coupon-request-manager&status=approved')); ?>" class="<?php echo $current_status === 'approved' ? 'current' : ''; ?>"><?php echo esc_html("تأیید شده ({$total_approved})"); ?></a> |</li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=coupon-request-manager&status=disapproved')); ?>" class="<?php echo $current_status === 'disapproved' ? 'current' : ''; ?>"><?php echo esc_html("رد شده ({$total_disapproved})"); ?></a></li>
            </ul>

            <form method="get">
                <input type="hidden" name="page" value="coupon-request-manager" />
                <?php if (isset($_GET['audit_request_id']) && absint($_GET['audit_request_id']) > 0) : ?>
                    <input type="hidden" name="audit_request_id" value="<?php echo esc_attr((string) absint($_GET['audit_request_id'])); ?>">
                <?php endif; ?>
                <?php if (!empty($current_status)) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                <?php endif; ?>
                <?php $table->search_box('جستجو درخواست‌ها', 'crm_req_search'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
    <?php
    }

    public function render_events_page(): void
    {
        global $wpdb;
        $events_table = CRM_DB::get_events_table();
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        $event_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $event = null;

        $manager_phones_text = '';
        if ($action === 'edit' && $event_id > 0) {
            $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$events_table} WHERE id = %d", $event_id));
            if ($event) {
                $phones = [];
                if (method_exists('CRM_DB', 'get_event_manager_phones')) {
                    $phones = CRM_DB::get_event_manager_phones((int) $event->id);
                }
                if (empty($phones) && !empty($event->event_manager_phone)) {
                    $phones = [trim((string) $event->event_manager_phone)];
                }
                $manager_phones_text = implode("\n", $phones);
            }
        }

        $table = new CRM_Events_List_Table();

        $table->prepare_items();
    ?>
        <div class="wrap crm-admin-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html('مدیریت رویدادها'); ?></h1>
            <?php $this->display_notices(); ?>

            <div class="crm-admin-columns">
                <div class="crm-admin-column-form">
                    <h2><?php echo $event ? esc_html('ویرایش رویداد') : esc_html('افزودن رویداد جدید'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=crm-events')); ?>">
                        <?php wp_nonce_field('crm_save_event_nonce'); ?>
                        <?php if ($event) : ?>
                            <input type="hidden" name="event_id" value="<?php echo esc_attr((string)$event->id); ?>">
                        <?php endif; ?>

                        <p>
                            <label for="name"><?php echo esc_html('نام رویداد'); ?> *</label><br>
                            <input type="text" id="name" name="name" class="widefat" value="<?php echo $event ? esc_attr($event->name) : ''; ?>" required>
                        </p>
                        <p>
                            <label for="discount_value"><?php echo esc_html('میزان تخفیف (%)'); ?> *</label><br>
                            <input type="number" id="discount_value" name="discount_value" class="widefat" value="<?php echo $event ? esc_attr((string)$event->discount_value) : '0'; ?>" required>
                        </p>
                        <p>
                            <label for="event_manager_phones"><?php echo esc_html('شماره موبایل مدیران رویداد'); ?> *</label><br>
                            <textarea id="event_manager_phones" name="event_manager_phones" class="widefat" rows="3" placeholder="09120000000&#10;09350000000" required><?php echo esc_textarea($manager_phones_text); ?></textarea>
                            <span class="description"><?php echo esc_html('هر شماره در یک سطر وارد شود.'); ?></span>
                        </p>

                        <p>
                            <label for="expires_at"><?php echo esc_html('تاریخ انقضا'); ?></label><br>
                            <input type="text" id="expires_at" name="expires_at" class="widefat crm-jalali-input" data-sql-datetime="<?php echo $event ? esc_attr((string) $event->expires_at) : ''; ?>" data-today="<?php echo esc_attr(substr(CRM_DB::sql_now(), 0, 10)); ?>" placeholder="۱۴۰۵-۰۷-۰۶ ۱۸:۵۰" inputmode="numeric" autocomplete="off" dir="ltr" aria-describedby="crm-expires-hint">
                            <span id="crm-expires-hint" class="description">تاریخ و ساعت شمسی را انتخاب کنید یا به شکل ۱۴۰۵-۰۷-۰۶ ۱۸:۵۰ وارد کنید.</span>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="isAvailable" value="1" <?php checked(!$event || $event->isAvailable == 1); ?>>
                                <?php echo esc_html('رویداد فعال و در دسترس باشد'); ?>
                            </label>
                        </p>
                        <p>
                            <input type="submit" name="crm_save_event" class="button button-primary" value="<?php echo $event ? esc_attr('به‌روزرسانی رویداد') : esc_attr('افزودن رویداد'); ?>">
                            <?php if ($event) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=crm-events')); ?>" class="button"><?php echo esc_html('انصراف'); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="crm-admin-column-table">
                    <form method="get">
                        <input type="hidden" name="page" value="crm-events" />
                        <?php $table->search_box('جستجو رویدادها', 'crm_event_search'); ?>
                    </form>
                    <?php $table->display(); ?>
                </div>
            </div>
        </div>
    <?php
    }

    public function render_coupons_page(): void
    {
        $table = new CRM_Coupons_List_Table();
        $table->prepare_items();
    ?>
        <div class="wrap crm-admin-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html('کدهای تخفیف صادر شده'); ?></h1>
            <?php $this->display_notices(); ?>

            <form method="get">
                <input type="hidden" name="page" value="crm-coupons" />
                <?php $table->search_box('جستجو کد تخفیف', 'crm_coupon_search'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
    <?php
    }

    public function render_reports_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('دسترسی غیرمجاز.'));
        }
        $table = new CRM_Reports_List_Table();
        $table->prepare_items();
        $action = isset($_GET['audit_action']) ? sanitize_key(wp_unslash($_GET['audit_action'])) : '';
        $from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        ?>
        <div class="wrap crm-admin-wrap crm-reports-wrap">
            <h1><?php echo esc_html('گزارش ها'); ?></h1>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="crm-reports">
                <div class="crm-reports-filters">
                    <label for="crm-reports-search">جستجوی رویداد یا شماره مدیر
                        <input type="search" id="crm-reports-search" name="s" value="<?php echo esc_attr(isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''); ?>" placeholder="نام رویداد یا شماره مدیر">
                    </label>
                    <label for="crm-reports-action">نوع عملیات
                        <select id="crm-reports-action" name="audit_action">
                            <option value="">همه عملیات</option>
                            <?php foreach (CRM_Reports_List_Table::action_labels() as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($action, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="crm-reports-from">از تاریخ
                        <input type="date" id="crm-reports-from" name="date_from" value="<?php echo esc_attr($from); ?>">
                    </label>
                    <label for="crm-reports-to">تا تاریخ
                        <input type="date" id="crm-reports-to" name="date_to" value="<?php echo esc_attr($to); ?>">
                    </label>
                    <button type="submit" class="button button-primary">نمایش گزارش</button>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=crm-reports')); ?>">پاک‌کردن فیلترها</a>
                </div>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function render_settings_page(): void
    {
        $username = get_option('crm_melipayamak_username', '');
        $password = get_option('crm_melipayamak_password', '');
        $body_id_customer = get_option('crm_melipayamak_body_id_customer', '');
        $body_id_manager  = get_option('crm_melipayamak_body_id_manager', '');
        $body_id_manager_otp = get_option('crm_melipayamak_body_id_manager_otp', '');
    ?>
        <div class="wrap crm-admin-wrap">
            <h1><?php echo esc_html('تنظیمات پنل پیامک ملی‌پیامک'); ?></h1>
            <?php $this->display_notices(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=crm-settings')); ?>">
                <?php wp_nonce_field('crm_save_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="username"><?php echo esc_html('نام کاربری ملی‌پیامک'); ?></label></th>
                        <td><input type="text" id="username" name="username" value="<?php echo esc_attr($username); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="password"><?php echo esc_html('کلمه عبور ملی‌پیامک'); ?></label></th>
                        <td><input type="password" id="password" name="password" value="<?php echo esc_attr($password); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="body_id_customer"><?php echo esc_html('کد متن (BodyId) مشتری'); ?></label></th>
                        <td>
                            <input type="text" id="body_id_customer" name="body_id_customer" value="<?php echo esc_attr($body_id_customer); ?>" class="regular-text">
                            <p class="description"><?php echo esc_html('متن نمونه در پنل: کد تخفیف شما: {0}'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="body_id_manager"><?php echo esc_html('کد متن (BodyId) مدیر رویداد'); ?></label></th>
                        <td>
                            <input type="text" id="body_id_manager" name="body_id_manager" value="<?php echo esc_attr($body_id_manager); ?>" class="regular-text">
                            <p class="description"><?php echo esc_html('متن نمونه در پنل: درخواست از شماره {0} - کد تخفیف: {1}'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="body_id_manager_otp"><?php echo esc_html('کد متن (BodyId) ورود مدیر'); ?></label></th>
                        <td><input type="text" id="body_id_manager_otp" name="body_id_manager_otp" value="<?php echo esc_attr($body_id_manager_otp); ?>" class="regular-text">
                            <p class="description"><?php echo esc_html('قالب پیامک ورود: کد یک‌بارمصرف {0}'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('ذخیره تنظیمات', 'primary', 'crm_save_settings'); ?>
            </form>
        </div>
<?php
    }
}
