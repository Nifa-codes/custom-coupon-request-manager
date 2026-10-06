<?php

/**
 * Plugin Name: Coupon Request Manager
 * Description: Frontend coupon request system with OTP SMS integration (Melipayamak), admin management and event manager login and dashboard panel.
 * Version:     1.0.4
 * Author:      Nifa-codes
 * Text Domain: coupon-request-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRM_PLUGIN_VERSION', '1.0.4');
define('CRM_DB_VERSION', '1.2.0');
define('CRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CRM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CRM_PLUGIN_DIR . 'includes/class-db.php';
require_once CRM_PLUGIN_DIR . 'includes/class-jalali-date.php';
require_once CRM_PLUGIN_DIR . 'includes/class-phone-helper.php';
require_once CRM_PLUGIN_DIR . 'includes/class-coupon-generator.php';
require_once CRM_PLUGIN_DIR . 'includes/class-melipayamak.php';
require_once CRM_PLUGIN_DIR . 'includes/class-auto-approve.php';
require_once CRM_PLUGIN_DIR . 'includes/class-ajax.php';
require_once CRM_PLUGIN_DIR . 'includes/class-manager-ajax.php';
require_once CRM_PLUGIN_DIR . 'includes/class-admin.php';
require_once CRM_PLUGIN_DIR . 'includes/class-reports-table.php';
require_once CRM_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once CRM_PLUGIN_DIR . 'includes/class-manager-shortcode.php';

// تأیید خودکار درخواست‌ها (WP-Cron + هوک ثبت درخواست)
CRM_Auto_Approve::init();
register_deactivation_hook(__FILE__, ['CRM_Auto_Approve', 'unschedule']);

// فعال‌سازی افزونه: ساخت جداول و اجرای مایگریشن
register_activation_hook(__FILE__, function () {
    CRM_DB::create_tables();
    CRM_DB::migrate();
    update_option('crm_db_version', CRM_DB_VERSION);
});

// اجرای یک‌باره هنگام به‌روزرسانی اسکیما
add_action('plugins_loaded', function () {
    $installed_ver = get_option('crm_db_version');
    if ($installed_ver !== CRM_DB_VERSION) {
        CRM_DB::create_tables();
        CRM_DB::migrate();
        update_option('crm_db_version', CRM_DB_VERSION);
    }
});

// راه‌اندازی ماژول‌ها
add_action('init', function () {
    CRM_Ajax::init();
    CRM_Manager_Ajax::init();
    CRM_Admin::get_instance();
    CRM_Shortcode::get_instance()->register_shortcode();
    CRM_Manager_Shortcode::init();
});

// بارگذاری فایل‌های فرانت‌اند
add_action('wp_enqueue_scripts', function () {
    global $post;

    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'crm_manager_panel')) {
        wp_enqueue_script('crm-jalali-js', CRM_PLUGIN_URL . 'assets/js/jalali.js', [], CRM_PLUGIN_VERSION, true);
        wp_enqueue_style('crm-manager-css', CRM_PLUGIN_URL . 'assets/css/manager.css', [], CRM_PLUGIN_VERSION);
        wp_enqueue_script('crm-manager-js', CRM_PLUGIN_URL . 'assets/js/manager.js', ['jquery', 'crm-jalali-js'], CRM_PLUGIN_VERSION, true);
        wp_localize_script('crm-manager-js', 'crm_manager_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('crm_manager_nonce'),
        ]);
    }

    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'coupon_request_form')) {
        return;
    }

    wp_enqueue_style(
        'crm-frontend-css',
        CRM_PLUGIN_URL . 'assets/css/frontend.css',
        [],
        CRM_PLUGIN_VERSION
    );

    wp_enqueue_script(
        'crm-frontend-js',
        CRM_PLUGIN_URL . 'assets/js/frontend.js',
        ['jquery'],
        CRM_PLUGIN_VERSION,
        true
    );

    wp_localize_script('crm-frontend-js', 'crm_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('crm_frontend_nonce'),
    ]);
});

// بارگذاری فایل‌های پیشخوان ادمین
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'coupon-request-manager') === false && strpos($hook, 'crm') === false) {
        return;
    }

    wp_enqueue_style(
        'crm-admin-css',
        CRM_PLUGIN_URL . 'assets/css/admin.css',
        [],
        CRM_PLUGIN_VERSION
    );

    wp_enqueue_script('crm-jalali-js', CRM_PLUGIN_URL . 'assets/js/jalali.js', [], CRM_PLUGIN_VERSION, true);

    wp_enqueue_script(
        'crm-admin-js',
        CRM_PLUGIN_URL . 'assets/js/admin.js',
        ['jquery', 'crm-jalali-js'],
        CRM_PLUGIN_VERSION,
        true
    );

    wp_localize_script('crm-admin-js', 'crm_admin_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('crm_admin_nonce'),
    ]);
});
