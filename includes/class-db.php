<?php

if (!defined('ABSPATH')) {
    exit;
}

class CRM_DB
{
    /** Use the SQL server's timestamp for database DATETIME columns. */
    public static function sql_now(): string
    {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT NOW()');
    }
    /**
     * Events table.
     *
     * @return string
     */
    public static function get_events_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_available_events';
    }

    /**
     * Pending requests table.
     *
     * @return string
     */
    public static function get_requests_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_pending_requests';
    }

    /**
     * Coupons table.
     *
     * @return string
     */
    public static function get_coupons_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_coupons';
    }

    /**
     * Event-to-manager membership table.
     *
     * @return string
     */
    public static function get_event_managers_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_event_managers';
    }

    /**
     * Manager OTP table.
     *
     * @return string
     */
    public static function get_manager_otps_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_manager_otps';
    }

    public static function get_manager_sessions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'coupon_manager_sessions';
    }

    /**
     * Manager audit table.
     *
     * @return string
     */
    public static function get_manager_audit_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'coupon_manager_audit';
    }

    /**
     * Create or update all plugin database tables.
     *
     * This method must be called only during activation or a versioned database
     * migration. It must not be called unconditionally on every page load.
     *
     * @return void
     */
    public static function create_tables()
    {
        global $wpdb;

        $charset_collate      = $wpdb->get_charset_collate();
        $table_events         = self::get_events_table();
        $table_requests       = self::get_requests_table();
        $table_coupons        = self::get_coupons_table();
        $table_managers       = self::get_event_managers_table();
        $table_manager_otps   = self::get_manager_otps_table();
        $table_manager_sessions = self::get_manager_sessions_table();
        $table_manager_audit  = self::get_manager_audit_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /*
         * Keep event_manager_phone for one compatibility release.
         *
         * Existing code still reads this column in:
         * - get_request_with_event()
         * - get_coupon_details_by_id()
         * - existing admin/list-table fallback logic
         */
        $sql_events = "CREATE TABLE {$table_events} (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            discount_value bigint(20) NOT NULL,
            event_manager_phone varchar(20) NOT NULL,
            isAvailable tinyint(1) NOT NULL DEFAULT 1,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        /*
         * Many-to-many manager/event membership table.
         *
         * Do not add a database-level foreign key here. WordPress dbDelta()
         * does not reliably create, update, or remove foreign keys across
         * existing installations.
         */
        $sql_event_managers = "CREATE TABLE {$table_managers} (
            id int(11) NOT NULL AUTO_INCREMENT,
            event_id int(11) NOT NULL,
            manager_phone varchar(20) NOT NULL,
            manager_name varchar(150) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_event_manager (event_id, manager_phone),
            KEY event_id (event_id),
            KEY manager_phone (manager_phone)
        ) {$charset_collate};";

        $sql_requests = "CREATE TABLE {$table_requests} (
            id int(11) NOT NULL AUTO_INCREMENT,
            customer_phone varchar(20) NOT NULL,
            event_id int(11) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            rejected_by_type varchar(20) DEFAULT NULL,
            rejected_by varchar(100) DEFAULT NULL,
            rejected_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY customer_phone (customer_phone)
        ) {$charset_collate};";

        /*
         * Keep all existing coupon fields.
         *
         * Existing compatibility fields:
         * - accepted_by_admin
         * - used_by_phone
         * - used_by_role
         *
         * New general action-attribution fields:
         * - accepted_by_type
         * - accepted_by
         */
        $sql_coupons = "CREATE TABLE {$table_coupons} (
            id int(11) NOT NULL AUTO_INCREMENT,
            pending_req_id int(11) NOT NULL,
            coupon_code varchar(64) NOT NULL,
            available_event_id int(11) NOT NULL,
            is_used tinyint(1) NOT NULL DEFAULT 0,
            used_at datetime DEFAULT NULL,
            used_by_phone varchar(20) DEFAULT NULL,
            used_by_role varchar(20) DEFAULT NULL,
            accepted_by_admin varchar(100) DEFAULT NULL,
            accepted_by_type varchar(20) DEFAULT NULL,
            accepted_by varchar(100) DEFAULT NULL,
            accepted_at datetime DEFAULT NULL,
            otp_sent tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY coupon_code (coupon_code),
            KEY pending_req_id (pending_req_id),
            KEY available_event_id (available_event_id),
            KEY used_by_phone (used_by_phone),
            KEY accepted_by_type (accepted_by_type)
        ) {$charset_collate};";

        /*
         * OTP records are intentionally stored separately from managers.
         *
         * The future authentication service must:
         * - store only a hash, never the plain OTP;
         * - enforce an approximately two-minute expiry;
         * - enforce a maximum of five verification attempts;
         * - enforce a sixty-second resend cooldown;
         * - enforce at most seven requests per hour;
         * - delete or otherwise invalidate an OTP after successful use.
         *
         * Multiple records per manager are required for rate-limit history.
         */
        $sql_manager_otps = "CREATE TABLE {$table_manager_otps} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            manager_phone varchar(20) NOT NULL,
            otp_hash varchar(64) NOT NULL,
            expires_at datetime NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY manager_phone (manager_phone),
            KEY manager_created (manager_phone, created_at),
            KEY manager_expires (manager_phone, expires_at)
        ) {$charset_collate};";

        $sql_manager_sessions = "CREATE TABLE {$table_manager_sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            manager_phone varchar(20) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY manager_expires (manager_phone, expires_at)
        ) {$charset_collate};";

        /*
         * Audit all manager mutations.
         *
         * Examples:
         * - approve_request
         * - reject_request
         * - mark_coupon_used
         *
         * details can contain JSON generated with wp_json_encode().
         */
        $sql_manager_audit = "CREATE TABLE {$table_manager_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_identifier varchar(100) NOT NULL,
            event_id int(11) DEFAULT NULL,
            action varchar(50) NOT NULL,
            target_type varchar(50) NOT NULL,
            target_id bigint(20) unsigned DEFAULT NULL,
            details longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY actor_identifier (actor_identifier),
            KEY event_id (event_id),
            KEY action (action),
            KEY target (target_type, target_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql_events);
        dbDelta($sql_event_managers);
        dbDelta($sql_requests);
        dbDelta($sql_coupons);
        dbDelta($sql_manager_otps);
        dbDelta($sql_manager_sessions);
        dbDelta($sql_manager_audit);
    }

    /**
     * Run migrations required by existing installations.
     *
     * Manager phones are normalized and deduplicated before storage.

     *
     * CRM_Ajax::handle_submit_request()
     *
     * A later patch should extract that implementation into a shared helper
     * used by AJAX, admin manager forms, and manager authentication.
     *
     * @return void
     */
    public static function migrate(): void
    {
        global $wpdb;

        $table_events  = self::get_events_table();
        $table_requests = self::get_requests_table();
        $table_coupons = self::get_coupons_table();
        $table_managers = self::get_event_managers_table();
        $table_manager_audit = self::get_manager_audit_table();

        /* Add rejection attribution to existing request tables. */
        if (self::table_exists($table_requests)) {
            if (!self::column_exists($table_requests, 'rejected_by_type')) {
                $wpdb->query(
                    "ALTER TABLE {$table_requests}
                     ADD COLUMN rejected_by_type varchar(20) DEFAULT NULL
                     AFTER status"
                );
            }

            if (!self::column_exists($table_requests, 'rejected_by')) {
                $wpdb->query(
                    "ALTER TABLE {$table_requests}
                     ADD COLUMN rejected_by varchar(100) DEFAULT NULL
                     AFTER rejected_by_type"
                );
            }

            if (!self::column_exists($table_requests, 'rejected_at')) {
                $wpdb->query(
                    "ALTER TABLE {$table_requests}
                     ADD COLUMN rejected_at datetime DEFAULT NULL
                     AFTER rejected_by"
                );
            }
        }

        /* Rename the empty audit table's legacy manager_phone column. */
        if (
            self::table_exists($table_manager_audit)
            && self::column_exists($table_manager_audit, 'manager_phone')
            && !self::column_exists($table_manager_audit, 'actor_identifier')
        ) {
            $wpdb->query(
                "ALTER TABLE {$table_manager_audit}
                 CHANGE COLUMN manager_phone actor_identifier varchar(100) NOT NULL"
            );
            $wpdb->query(
                "ALTER TABLE {$table_manager_audit}
                 DROP INDEX manager_phone, ADD KEY actor_identifier (actor_identifier)"
            );
        }

        /*
         * Explicitly add compatibility and attribution columns to existing
         * coupon tables. dbDelta() should normally add these columns, but these
         * checks make the migration predictable on older installations.
         */
        if (self::table_exists($table_coupons)) {
            if (!self::column_exists($table_coupons, 'used_by_phone')) {
                $wpdb->query(
                    "ALTER TABLE {$table_coupons}
                     ADD COLUMN used_by_phone varchar(20) DEFAULT NULL
                     AFTER used_at"
                );
            }

            if (!self::column_exists($table_coupons, 'used_by_role')) {
                $wpdb->query(
                    "ALTER TABLE {$table_coupons}
                     ADD COLUMN used_by_role varchar(20) DEFAULT NULL
                     AFTER used_by_phone"
                );
            }

            if (!self::column_exists($table_coupons, 'accepted_by_type')) {
                $wpdb->query(
                    "ALTER TABLE {$table_coupons}
                     ADD COLUMN accepted_by_type varchar(20) DEFAULT NULL
                     AFTER accepted_by_admin"
                );
            }

            if (!self::column_exists($table_coupons, 'accepted_by')) {
                $wpdb->query(
                    "ALTER TABLE {$table_coupons}
                     ADD COLUMN accepted_by varchar(100) DEFAULT NULL
                     AFTER accepted_by_type"
                );
            }

            /*
             * Backfill the new actor fields from the existing admin field.
             * Existing values are not overwritten.
             */
            if (
                self::column_exists($table_coupons, 'accepted_by_admin')
                && self::column_exists($table_coupons, 'accepted_by_type')
                && self::column_exists($table_coupons, 'accepted_by')
            ) {
                $wpdb->query(
                    "UPDATE {$table_coupons}
                     SET accepted_by_type = 'admin',
                         accepted_by = accepted_by_admin
                     WHERE accepted_by_admin IS NOT NULL
                       AND TRIM(accepted_by_admin) <> ''
                       AND (
                           accepted_by_type IS NULL
                           OR accepted_by_type = ''
                           OR accepted_by IS NULL
                           OR accepted_by = ''
                       )"
                );
            }
        }

        /*
         * Backfill the many-to-many membership table from the legacy scalar
         * event_manager_phone field.
         *
         * Only TRIM is used here. No second phone-normalization implementation
         * is introduced into CRM_DB.
         */
        if (
            self::table_exists($table_events)
            && self::table_exists($table_managers)
            && self::column_exists($table_events, 'event_manager_phone')
        ) {
            $wpdb->query(
                "INSERT IGNORE INTO {$table_managers}
                    (event_id, manager_phone, created_at)
                 SELECT
                    id,
                    TRIM(event_manager_phone),
                    NOW()
                 FROM {$table_events}
                 WHERE event_manager_phone IS NOT NULL
                   AND TRIM(event_manager_phone) <> ''"
            );
        }
    }

    /**
     * Get all manager phone numbers assigned to an event.
     *
     * Falls back to the legacy event_manager_phone column when no membership
     * rows exist for the event.
     *
     * @param int $event_id Event ID.
     * @return array
     */
    public static function get_event_manager_phones(int $event_id): array
    {
        global $wpdb;

        $event_id = (int) $event_id;

        if ($event_id <= 0) {
            return [];
        }

        $table = self::get_event_managers_table();

        $phones = [];

        if (self::table_exists($table)) {
            $phones = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT manager_phone
                     FROM {$table}
                     WHERE event_id = %d
                     ORDER BY id ASC",
                    $event_id
                )
            );
        }

        $phones = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'trim',
                        is_array($phones) ? $phones : []
                    ),
                    static function ($phone) {
                        return $phone !== '';
                    }
                )
            )
        );

        if (!empty($phones)) {
            return $phones;
        }

        /*
         * Legacy fallback for installations that have not yet been migrated
         * or events saved by older code.
         */
        $legacy_phone = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_manager_phone
                 FROM " . self::get_events_table() . "
                 WHERE id = %d
                 LIMIT 1",
                $event_id
            )
        );

        $legacy_phone = is_string($legacy_phone)
            ? trim($legacy_phone)
            : '';

        return $legacy_phone !== '' ? [$legacy_phone] : [];
    }



    /**
     * Return all events assigned to a manager phone.
     *
     * The phone is normalized with the shared helper (CRM_Phone_Helper).
     * Only events that actually exist are returned because the membership
     * rows are joined against the events table. Falls back to the legacy
     * event_manager_phone column when no membership rows exist for the phone.
     *
     * @param string $phone Manager phone.
     * @return array
     */
    public static function get_events_for_manager_phone(string $phone): array
    {
        global $wpdb;

        $normalized_phone = CRM_Phone_Helper::normalize($phone);

        if ($normalized_phone === null) {
            return [];
        }

        $table_events   = self::get_events_table();
        $table_managers = self::get_event_managers_table();

        $events = [];

        if (self::table_exists($table_managers)) {
            $events = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT e.*
                     FROM {$table_managers} m
                     INNER JOIN {$table_events} e
                        ON m.event_id = e.id
                     WHERE m.manager_phone = %s
                     ORDER BY e.id DESC",
                    $normalized_phone
                )
            );
        }

        /*
         * Legacy fallback while event_manager_phone is still supported.
         * TRIM mirrors the legacy comparison in is_phone_manager_of_event().
         */
        $legacy_events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_events}
                 WHERE TRIM(event_manager_phone) = %s
                 ORDER BY id DESC",
                $normalized_phone
            )
        );

        $combined = [];
        foreach (array_merge(is_array($events) ? $events : [], is_array($legacy_events) ? $legacy_events : []) as $event) {
            $combined[(int) $event->id] = $event;
        }
        return array_values($combined);
    }

    /**
     * Replace all manager assignments for an event.
     *
     * Phone values must already have been normalized by the shared input layer.
     * CRM_DB deliberately does not contain a duplicate normalization routine.
     *
     * This method also keeps event_manager_phone synchronized with the first
     * manager for compatibility with older plugin code.
     *
     * Existing callers may ignore the returned boolean.
     *
     * @param int   $event_id Event ID.
     * @param array $phones   Manager phone numbers.
     * @return bool
     */
    public static function set_event_managers(int $event_id, array $phones): bool
    {
        global $wpdb;

        $event_id = (int) $event_id;

        if ($event_id <= 0 || !self::get_event_by_id($event_id)) {
            return false;
        }

        $table_managers = self::get_event_managers_table();
        $table_events   = self::get_events_table();

        if (!self::table_exists($table_managers)) {
            return false;
        }

        /*
         * Trim and deduplicate only.
         *
         * Do not normalize here because the current plugin already has
         * normalization in CRM_Ajax::handle_submit_request(). That code should
         * later be extracted into a reusable helper rather than duplicated.
         */
        $unique_phones = [];

        foreach ($phones as $phone) {
            $normalized_phone = CRM_Phone_Helper::normalize($phone);

            if ($normalized_phone !== null) {
                $unique_phones[$normalized_phone] = $normalized_phone;
            }
        }

        $unique_phones = array_values($unique_phones);
        $legacy_phone  = !empty($unique_phones) ? $unique_phones[0] : '';


        if ($wpdb->query('START TRANSACTION') === false) {
            return false;
        }

        $delete_result = $wpdb->delete(
            $table_managers,
            ['event_id' => $event_id],
            ['%d']
        );

        if ($delete_result === false) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        foreach ($unique_phones as $phone) {
            $insert_result = $wpdb->insert(
                $table_managers,
                [
                    'event_id'      => $event_id,
                    'manager_phone' => $phone,
                    'created_at'    => self::sql_now(),
                ],
                ['%d', '%s', '%s']
            );

            if ($insert_result === false) {
                $wpdb->query('ROLLBACK');

                return false;
            }
        }

        /*
         * Maintain the old scalar field for one compatibility release.
         */
        $legacy_update = $wpdb->update(
            $table_events,
            ['event_manager_phone' => $legacy_phone],
            ['id' => $event_id],
            ['%s'],
            ['%d']
        );

        if ($legacy_update === false) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return true;
    }

    /**
     * Check whether a phone is assigned to an event.
     *
     * The caller must pass a normalized phone number.
     *
     * @param string $phone    Manager phone.
     * @param int    $event_id Event ID.
     * @return bool
     */
    public static function is_phone_manager_of_event(
        string $phone,
        int $event_id
    ): bool {
        global $wpdb;
        $phone    = CRM_Phone_Helper::normalize($phone);
        $event_id = (int) $event_id;

        if ($phone === null || $event_id <= 0) {

            return false;
        }

        $table_managers = self::get_event_managers_table();

        if (self::table_exists($table_managers)) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table_managers}
                     WHERE event_id = %d
                       AND manager_phone = %s",
                    $event_id,
                    $phone
                )
            );

            if ((int) $count > 0) {
                return true;
            }
        }

        /*
         * Legacy fallback while event_manager_phone is still supported.
         */
        $legacy_phone = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_manager_phone
                 FROM " . self::get_events_table() . "
                 WHERE id = %d
                 LIMIT 1",
                $event_id
            )
        );

        return is_string($legacy_phone)
            && trim($legacy_phone) === $phone;
    }

    /**
     * Return the active event.
     *
     * @return object|null
     */
    public static function get_active_event()
    {
        global $wpdb;

        $table = self::get_events_table();

        return $wpdb->get_row(
                "SELECT *
                 FROM {$table}
                 WHERE isAvailable = 1
                   AND expires_at > NOW()
                 ORDER BY id DESC
                 LIMIT 1"
        );
    }

    /**
     * Return an event by ID.
     *
     * @param mixed $event_id Event ID.
     * @return object|null
     */
    public static function get_event_by_id($event_id)
    {
        global $wpdb;

        $table = self::get_events_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                (int) $event_id
            )
        );
    }

    /**
     * Check whether a customer has a pending request.
     *
     * Existing behavior is preserved:
     * - with event ID: checks that event;
     * - without event ID: checks all events.
     *
     * @param string $phone    Customer phone.
     * @param int    $event_id Optional event ID.
     * @return bool
     */
    public static function has_pending_request($phone, $event_id = 0)
    {
        global $wpdb;

        $table = self::get_requests_table();

        if ((int) $event_id > 0) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE customer_phone = %s
                       AND event_id = %d
                       AND status = 'pending'",
                    $phone,
                    (int) $event_id
                )
            );
        } else {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table}
                     WHERE customer_phone = %s
                       AND status = 'pending'",
                    $phone
                )
            );
        }

        return (int) $count > 0;
    }

    /**
     * Insert a pending customer request.
     *
     * The caller is responsible for validation and normalization.
     *
     *and normalization.
     *
     * @param string $phone    Customer phone.
     *$event_id Event ID.
     * @return int|false
     */
    public static function insert_request($phone, $event_id)
    {
        global $wpdb;

        $table = self::get_requests_table();

        $result = $wpdb->insert(
            $table,
            [
                'customer_phone' => $phone,
                'event_id'       => (int) $event_id,
                'status'         => 'pending',
                'created_at'     => self::sql_now(),
            ],
            ['%s', '%d', '%s', '%s']
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Return a request with its event information.
     *
     * @param mixed $request_id Request ID.
     * @return object|null
     */
    public static function get_request_with_event($request_id)
    {
        global $wpdb;

        $table_requests = self::get_requests_table();
        $table_events   = self::get_events_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    r.*,
                    e.name AS event_name,
                    e.discount_value,
                    e.expires_at,
                    e.event_manager_phone
                 FROM {$table_requests} r
                 INNER JOIN {$table_events} e
                    ON r.event_id = e.id
                 WHERE r.id = %d
                 LIMIT 1",
                (int) $request_id
            )
        );
    }

    /**
     * Update request status.
     *
     * @param mixed  $request_id Request ID.
     * @param string $status     New status.
     * @return int|false
     */
    public static function update_request_status($request_id, $status)
    {
        global $wpdb;

        if (!in_array(
            $status,
            ['pending', 'approved', 'disapproved'],
            true
        )) {
            return false;
        }

        return $wpdb->update(
            self::get_requests_table(),
            ['status' => $status],
            ['id' => (int) $request_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Record a rejected request and its actor.
     *
     * @param mixed  $request_id Request ID.
     * @param string $actor_type Actor type.
     * @param string $identifier Actor identifier.
     * @return int|false
     */
    public static function update_request_rejection(
        $request_id,
        string $actor_type,
        string $identifier
    ) {
        global $wpdb;

        if (!in_array($actor_type, ['admin', 'manager'], true)) {
            return false;
        }

        return $wpdb->update(
            self::get_requests_table(),
            [
                'status'           => 'disapproved',
                'rejected_by_type' => $actor_type,
                'rejected_by'      => trim($identifier),
                'rejected_at'      => self::sql_now(),
            ],
            ['id' => (int) $request_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Approve a pending request, issue its coupon, and send OTP messages.
     *
     * @param int    $request_id Request ID.
     * @param string $actor_type Actor type (admin or manager).
     * @param string $identifier Actor identifier.
     * @return true|WP_Error
     */
    public static function approve_request(
        int $request_id,
        string $actor_type,
        string $identifier
    ) {
        global $wpdb;

        if (!in_array($actor_type, ['admin', 'manager'], true)) {
            return new WP_Error('invalid_actor', 'درخواست نامعتبر است یا قبلاً پردازش شده است.');
        }

        $request_data = self::get_request_with_event($request_id);

        if (!$request_data || $request_data->status !== 'pending') {
            return new WP_Error('invalid_request', 'درخواست نامعتبر است یا قبلاً پردازش شده است.');
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('approve_failed', 'شروع تراکنش دیتابیس ناموفق بود.');
        }

        // Lock the request row and re-check its status, so two concurrent callers
        // (manager click, auto-approve, background sweep) can never both issue a
        // coupon for the same request.
        $locked_status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . self::get_requests_table() . ' WHERE id = %d FOR UPDATE',
            $request_id
        ));
        if ($locked_status !== 'pending') {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid_request', 'درخواست نامعتبر است یا قبلاً پردازش شده است.');
        }

        try {
            $coupon_code = CRM_Coupon_Generator::generate();
            $identifier = trim($identifier);
            $coupons_table = self::get_coupons_table();

            $inserted = $wpdb->insert(
                $coupons_table,
                [
                    'pending_req_id'     => $request_id,
                    'coupon_code'        => $coupon_code,
                    'available_event_id' => (int) $request_data->event_id,
                    'is_used'            => 0,
                    'accepted_by_admin'  => $actor_type === 'admin' ? $identifier : null,
                    'accepted_by_type'   => $actor_type,
                    'accepted_by'        => $identifier,
                    'accepted_at'        => self::sql_now(),
                    'otp_sent'           => 0,
                ],
                ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d']
            );

            if (!$inserted) {
                throw new Exception('خطا در درج کد تخفیف در دیتابیس.');
            }

            $coupon_id = (int) $wpdb->insert_id;

            $status_updated = self::update_request_status($request_id, 'approved');
            if (!$status_updated) {
                throw new Exception('خطا در به‌روزرسانی وضعیت درخواست.');
            }

            // Attempt OTP SMS sending
            $sms = new CRM_Melipayamak();
            $body_id_cust = (string) get_option('crm_melipayamak_body_id_customer', '0');
            $body_id_mgr  = (string) get_option('crm_melipayamak_body_id_manager', '0');

            // 1) Send OTP to customer
            $sent_customer = $sms->sendOtp(
                $request_data->customer_phone,
                $body_id_cust,
                [$coupon_code, $request_data->event_name]
            );

            // 2) Get all manager phones for this event (multi-manager)
            $manager_phones = self::get_event_manager_phones((int) $request_data->event_id);

            // Fallback for legacy events
            if (empty($manager_phones) && !empty($request_data->event_manager_phone)) {
                $manager_phones = [(string) $request_data->event_manager_phone];
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
                    [$request_data->customer_phone, $coupon_code]
                );

                if ($sent_mgr) {
                    $any_manager_sent = true;
                }
            }

            $otp_sent = ($sent_customer && $any_manager_sent) ? 1 : 0;
            $otp_status_updated = self::update_coupon_otp_status($coupon_id, $otp_sent);

            if ($otp_status_updated === false) {
                throw new Exception('خطا در به‌روزرسانی وضعیت ارسال پیامک.');
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new Exception('ثبت تراکنش دیتابیس ناموفق بود.');
            }

            self::insert_manager_audit(
                $identifier,
                (int) $request_data->event_id,
                'approve_request',
                'request',
                $request_id,
                ['actor_type' => $actor_type, 'coupon_id' => $coupon_id]
            );

            if (!$sent_customer || !$any_manager_sent) {
                self::insert_manager_audit($identifier, (int) $request_data->event_id, 'sms_failed', 'coupon', $coupon_id, [
                    'actor_type' => $actor_type, 'context' => 'approve_request',
                    'customer_failed' => !$sent_customer, 'manager_failed' => !$any_manager_sent,
                ]);
            }

            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('approve_failed', $e->getMessage());
        }
    }


    /**
     * Disapprove a request and record the actor and timestamp.
     *
     * @param int    $request_id Request ID.
     * @param string $actor_type Actor type (admin or manager).
     * @param string $identifier Actor identifier.
     * @return int|false
     */
    public static function disapprove_request(
        int $request_id,
        string $actor_type,
        string $identifier
    ) {
        $request_data = self::get_request_with_event($request_id);
        $updated = self::update_request_rejection($request_id, $actor_type, $identifier);

        if ($updated) {
            self::insert_manager_audit(
                $identifier,
                $request_data ? (int) $request_data->event_id : null,
                'reject_request',
                'request',
                $request_id,
                ['actor_type' => $actor_type]
            );
        }

        return $updated;
    }

    /**
     * Count generated/approved coupons for an event.
     *
     * This preserves the existing behavior. A coupon row is created only after
     * approval, so the method counts all coupon rows for the event.
     *
     * @param mixed $event_id Event ID.
     * @return int
     */
    public static function count_approved_coupons_for_event($event_id)
    {
        global $wpdb;

        $table = self::get_coupons_table();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 WHERE available_event_id = %d",
                (int) $event_id
            )
        );
    }

    /**
     * Delete an event and its manager memberships when no requests or coupons exist.
     *
     * Existing return behavior is preserved: the event-delete result is
     * returned as an integer or false.
     *
     * @param mixed $event_id Event ID.
     * @return int|false
     */
    public static function delete_event($event_id)
    {
        global $wpdb;

        $event_id = (int) $event_id;

        if ($event_id <= 0) {
            return false;
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            return false;
        }

        $event_row = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::get_events_table() . ' WHERE id = %d FOR UPDATE',
            $event_id
        ));
        $requests_table = self::get_requests_table();
        $request_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$requests_table} WHERE event_id = %d",
            $event_id
        ));
        if (!$event_row || $request_count > 0 || self::count_approved_coupons_for_event($event_id) > 0) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $manager_delete = $wpdb->delete(
            self::get_event_managers_table(),
            ['event_id' => $event_id],
            ['%d']
        );

        if ($manager_delete === false) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        $event_delete = $wpdb->delete(
            self::get_events_table(),
            ['id' => $event_id],
            ['%d']
        );

        if ($event_delete !== 1) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return $event_delete;
    }

    /**
     * Return coupon information by numeric coupon ID.
     *
     * @param mixed $coupon_id Coupon ID.
     * @return object|null
     */
    public static function get_coupon_details_by_id($coupon_id)
    {
        global $wpdb;

        $table_coupons  = self::get_coupons_table();
        $table_requests = self::get_requests_table();
        $table_events   = self::get_events_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    c.*,
                    r.customer_phone,
                    e.name AS event_name,
                    e.discount_value,
                    e.expires_at,
                    e.event_manager_phone
                 FROM {$table_coupons} c
                 INNER JOIN {$table_requests} r
                    ON c.pending_req_id = r.id
                 INNER JOIN {$table_events} e
                    ON c.available_event_id = e.id
                 WHERE c.id = %d
                 LIMIT 1",
                (int) $coupon_id
            )
        );
    }

    /**
     * Update whether coupon SMS/OTP was sent.
     *
     * @param mixed $coupon_id Coupon ID.
     * @param mixed $status    Status.
     * @return int|false
     */
    public static function update_coupon_otp_status(
        $coupon_id,
        $status = 1
    ) {
        global $wpdb;

        return $wpdb->update(
            self::get_coupons_table(),
            ['otp_sent' => (int) $status],
            ['id' => (int) $coupon_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Mark a coupon as used.
     *
     * Old supported call:
     * mark_coupon_as_used($coupon_id, $datetime)
     *
     * Existing extended call:
     * mark_coupon_as_used(
     *     $coupon_id,
     *     $datetime,
     *     $used_by_phone,
     *     $used_by_role
     * )
     *
     * $coupon_id may be a numeric database ID or a coupon-code string.
     *
     * @param mixed       $coupon_id     Numeric ID or coupon code.
     * @param string|null $datetime      Usage time.
     * @param string|null $used_by_phone Actor phone.
     * @param string|null $used_by_role  Actor role.
     * @return int|false
     */
    public static function mark_coupon_as_used(
        $coupon_id,
        $datetime = null,
        $used_by_phone = null,
        $used_by_role = null
    ) {
        global $wpdb;

        $table = self::get_coupons_table();

        $data = [
            'is_used' => 1,
            'used_at' => $datetime ?: self::sql_now(),
        ];

        $formats = ['%d', '%s'];

        if ($used_by_phone !== null) {
            $data['used_by_phone'] = trim((string) $used_by_phone);
            $formats[] = '%s';
        }

        if ($used_by_role !== null) {
            $data['used_by_role'] = trim((string) $used_by_role);
            $formats[] = '%s';
        }

        if (is_numeric($coupon_id)) {
            return $wpdb->update(
                $table,
                $data,
                ['id' => (int) $coupon_id],
                $formats,
                ['%d']
            );
        }

        return $wpdb->update(
            $table,
            $data,
            ['coupon_code' => trim((string) $coupon_id)],
            $formats,
            ['%s']
        );
    }

    /**
     * Add an audit record for a manager action.
     *
     * This helper does not verify event ownership. The service/AJAX layer must
     * verify ownership before performing a mutation and writing the audit row.
     *
     * @param string     $actor_identifier Actor identifier.
     * @param int|null   $event_id      Event ID.
     * @param string     $action        Action name.
     * @param string     $target_type   Target type.
     * @param int|null   $target_id     Target ID.
     * @param array|null $details       Optional structured details.
     * @return int|false Inserted audit ID or false.
     */
    public static function insert_manager_audit(
        string $actor_identifier,
        ?int $event_id,
        string $action,
        string $target_type,
        ?int $target_id = null,
        ?array $details = null
    ) {
        global $wpdb;

        $actor_identifier = trim($actor_identifier);
        $action        = sanitize_key($action);
        $target_type   = sanitize_key($target_type);

        if (
            $actor_identifier === ''
            || $action === ''
            || $target_type === ''
        ) {
            return false;
        }

        $result = $wpdb->insert(
            self::get_manager_audit_table(),
            [
                'actor_identifier' => $actor_identifier,
                'event_id'      => $event_id,
                'action'        => $action,
                'target_type'   => $target_type,
                'target_id'     => $target_id,
                'details'       => $details === null
                    ? null
                    : wp_json_encode(
                        $details,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                'created_at'    => self::sql_now(),
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Determine whether a database table exists.
     *
     * @param string $table Table name.
     * @return bool
     */
    private static function table_exists(string $table): bool
    {
        global $wpdb;

        $found_table = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        return $found_table === $table;
    }

    /**
     * Determine whether a table column exists.
     *
     * Table names are internal values generated from $wpdb->prefix and are
     * never accepted from a request.
     *
     * @param string $table  Table name.
     * @param string $column Column name.
     * @return bool
     */
    public static function manager_otp_limits(string $phone): array
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        return [
            'count' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE manager_phone = %s AND created_at > NOW() - INTERVAL 1 HOUR", $phone)),
            'latest' => $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$table} WHERE manager_phone = %s ORDER BY id DESC LIMIT 1", $phone)),
        ];
    }

    public static function create_manager_otp(string $phone, string $hash): int
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        self::invalidate_manager_otps($phone);
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (manager_phone, otp_hash, expires_at, created_at) VALUES (%s, %s, NOW() + INTERVAL 180 SECOND, NOW())",
            $phone,
            $hash
        ));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get_latest_valid_manager_otp(string $phone): ?object
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE manager_phone = %s AND expires_at > NOW() AND attempts < 5 ORDER BY id DESC LIMIT 1",
            $phone
        ));
    }

    public static function increment_manager_otp_attempts(int $id): void
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND attempts < 5 AND expires_at > NOW()", $id));
    }

    public static function invalidate_manager_otps(string $phone, ?int $id = null): void
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        $where = $id === null ? $wpdb->prepare('manager_phone = %s', $phone) : $wpdb->prepare('manager_phone = %s AND id = %d', $phone, $id);
        $wpdb->query("UPDATE {$table} SET expires_at = '1970-01-01 00:00:00' WHERE {$where} AND expires_at > '1970-01-01 00:00:00'");
    }

    public static function consume_manager_otp(string $phone, int $id): bool
    {
        global $wpdb;
        $table = self::get_manager_otps_table();
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET expires_at = '1970-01-01 00:00:00'
             WHERE id = %d AND manager_phone = %s AND expires_at > NOW() AND attempts <= 5",
            $id,
            $phone
        ));
        if ($changed !== 1) return false;
        self::invalidate_manager_otps($phone);
        return true;
    }

    public static function create_manager_session(string $phone, string $token): bool
    {
        global $wpdb;
        $table = self::get_manager_sessions_table();
        return (bool) $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (token_hash, manager_phone, expires_at, created_at) VALUES (%s, %s, NOW() + INTERVAL 1 DAY, NOW())",
            hash('sha256', $token),
            $phone
        ));
    }

    public static function validate_manager_session(string $token): ?string
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $table = self::get_manager_sessions_table();
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT manager_phone FROM {$table} WHERE token_hash = %s AND expires_at > NOW() LIMIT 1",
            hash('sha256', $token)
        ));
        return is_string($phone) ? $phone : null;
    }

    public static function delete_manager_session(string $token): void
    {
        global $wpdb;
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $wpdb->delete(self::get_manager_sessions_table(), ['token_hash' => hash('sha256', $token)], ['%s']);
        }
    }

    public static function manager_event_ids(string $phone): array
    {
        global $wpdb;
        $events = self::get_events_table();
        $members = self::get_event_managers_table();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT e.id FROM {$events} e WHERE TRIM(e.event_manager_phone) = %s OR EXISTS
             (SELECT 1 FROM {$members} m WHERE m.event_id = e.id AND m.manager_phone = %s)",
            $phone,
            $phone
        ));
        return array_map('intval', $ids ?: []);
    }

    public static function get_manager_dashboard(string $phone): array
    {
        global $wpdb;
        $ids = self::manager_event_ids($phone);
        $counts = ['pending' => 0, 'approved' => 0, 'unused' => 0, 'used' => 0];
        if (!$ids) {
            return $counts;
        }
        $in = implode(',', $ids);
        $requests = self::get_requests_table();
        $coupons = self::get_coupons_table();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) total FROM {$requests} WHERE event_id IN ({$in}) GROUP BY status");
        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status] = (int) $row->total;
            }
        }
        $counts['unused'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$coupons} WHERE available_event_id IN ({$in}) AND is_used = 0");
        $counts['used'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$coupons} WHERE available_event_id IN ({$in}) AND is_used = 1");
        return $counts;
    }

    public static function get_manager_requests(string $phone, string $search = '', string $status = '', string $date = ''): array
    {
        global $wpdb;
        $ids = self::manager_event_ids($phone);
        if (!$ids) return [];
        $in = implode(',', $ids);
        $requests = self::get_requests_table();
        $events = self::get_events_table();
        $where = "r.event_id IN ({$in})";
        $args = [];
        if ($search !== '') {
            $where .= ' AND r.customer_phone LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if (in_array($status, ['pending', 'approved', 'disapproved'], true)) {
            $where .= ' AND r.status = %s';
            $args[] = $status;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where .= ' AND r.created_at >= %s AND r.created_at < DATE_ADD(%s, INTERVAL 1 DAY)';
            $args[] = $date;
            $args[] = $date;
        }
        $sql = "SELECT r.id, r.event_id, r.customer_phone, r.status, r.created_at, e.name event_name
                FROM {$requests} r JOIN {$events} e ON e.id = r.event_id WHERE {$where} ORDER BY r.id DESC";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql) ?: [];
    }

    public static function get_manager_coupons(string $phone, string $search = '', string $used = ''): array
    {
        global $wpdb;
        $ids = self::manager_event_ids($phone);
        if (!$ids) return [];
        $in = implode(',', $ids);
        $coupons = self::get_coupons_table();
        $requests = self::get_requests_table();
        $events = self::get_events_table();
        $where = "c.available_event_id IN ({$in})";
        $args = [];
        if ($search !== '') {
            $where .= ' AND (c.coupon_code LIKE %s OR r.customer_phone LIKE %s)';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
            $args[] = $args[0];
        }
        if ($used === '0' || $used === '1') {
            $where .= ' AND c.is_used = %d';
            $args[] = (int) $used;
        }
        $sql = "SELECT c.id, c.available_event_id, c.coupon_code, c.is_used, c.used_at, c.accepted_at,
                r.customer_phone, e.name event_name, e.discount_value FROM {$coupons} c
                JOIN {$requests} r ON r.id = c.pending_req_id JOIN {$events} e ON e.id = c.available_event_id
                WHERE {$where} ORDER BY c.id DESC";
        return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql) ?: [];
    }

    private static function column_exists(
        string $table,
        string $column
    ): bool {
        global $wpdb;

        if (!self::table_exists($table)) {
            return false;
        }

        $found_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                $column
            )
        );

        return $found_column === $column;
    }
}
