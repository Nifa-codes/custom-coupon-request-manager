<?php
if (!defined('ABSPATH')) {
    exit;
}

class CRM_Shortcode
{

    private static ?CRM_Shortcode $instance = null;

    /** Make sure the modal markup is printed only once per page (IDs must stay unique). */
    private static bool $modal_printed = false;

    public static function get_instance(): CRM_Shortcode
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Intentionally empty; register_shortcode() is called explicitly from init wiring.
    }

    public function register_shortcode(): void
    {
        // Original shortcode: built-in button + modal
        add_shortcode('coupon_request_form', [$this, 'render_shortcode']);

        // NEW shortcode: modal only. Open it with your own button (see JS trigger rules).
        add_shortcode('coupon_request_modal', [$this, 'render_modal_shortcode']);
    }

    /**
     * Enqueue front-end assets from inside the shortcode itself.
     * This makes it work with page builders (Elementor, etc.) where has_shortcode()
     * on post_content can't see the shortcode.
     */
    public static function enqueue_assets(): void
    {
        if (wp_script_is('crm-frontend-js', 'enqueued')) {
            return; // already enqueued + localized by the wp_enqueue_scripts hook
        }

        wp_enqueue_style('crm-frontend-css', CRM_PLUGIN_URL . 'assets/css/frontend.css', [], CRM_PLUGIN_VERSION);
        wp_enqueue_script('crm-frontend-js', CRM_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], CRM_PLUGIN_VERSION, true);
        wp_localize_script('crm-frontend-js', 'crm_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('crm_frontend_nonce'),
        ]);
    }

    private function get_event($event_id)
    {
        $event = null;

        if ($event_id > 0 && method_exists('CRM_DB', 'get_event_by_id')) {
            $event = CRM_DB::get_event_by_id($event_id);
        }

        if (!$event) {
            $event = CRM_DB::get_active_event();
        }

        return $event;
    }

    /**
     * [coupon_request_form] – button + modal (unchanged behaviour)
     */
    public function render_shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'event_id' => 0,
        ], $atts, 'coupon_request_form');

        $event = $this->get_event(absint($atts['event_id']));

        if (!$event) {
            return '<div class="crm-no-event"><p>در حال حاضر هیچ رویداد تخفیف فعالی وجود ندارد.</p></div>';
        }

        self::enqueue_assets();

        ob_start();
?>
        <div class="crm-container">
            <button type="button" id="crm-open-modal-btn" class="button crm-btn-primary">
                دریافت کد تخفیف
            </button>

            <?php $this->modal_markup($event); ?>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * [coupon_request_modal] – modal only, no button.
     *
     * Usage: [coupon_request_modal] or [coupon_request_modal event_id="3"]
     * Then give ANY button/link the CSS class "crm-open-modal"
     * or set its link to "#crm-open-modal".
     */
    public function render_modal_shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'event_id' => 0,
        ], $atts, 'coupon_request_modal');

        $event = $this->get_event(absint($atts['event_id']));

        self::enqueue_assets();

        ob_start();
        $this->modal_markup($event);
        return ob_get_clean();
    }

    /**
     * Shared modal markup. Prints nothing if a modal is already on the page.
     */
    private function modal_markup($event): void
    {
        if (self::$modal_printed) {
            return;
        }
        self::$modal_printed = true;
    ?>
        <div id="crm-modal" class="crm-modal" style="display: none;" aria-hidden="true">
            <div class="crm-modal-content">
                <span class="crm-modal-close" id="crm-close-modal-btn">&times;</span>
                <img src="<?php echo esc_url(plugins_url('../assets/icons/discount-icon.svg', __FILE__)); ?>"
                    alt=""
                    class="crm-modal-icon"
                    width="150" height="150">

                <?php if (!$event) : ?>
                    <h2>در حال حاضر هیچ رویداد تخفیف فعالی وجود ندارد.</h2>
                <?php else : ?>
                    <h2>درخواست کد تخفیف برای <?php echo esc_html($event->name); ?></h2>
                    <?php echo '<p class="crm-event-info">میزان تخفیف: <strong>' . esc_html($event->discount_value) . ' درصد</strong></p>'; ?>

                    <form id="crm-request-form">
                        <?php wp_nonce_field('crm_frontend_nonce', 'crm_nonce'); ?>
                        <input type="hidden" name="event_id" value="<?php echo esc_attr((int) $event->id); ?>">

                        <div class="crm-form-group">
                            <label for="crm_customer_phone">شماره همراه خود را وارد کنید:</label>
                            <input
                                type="tel"
                                id="crm_customer_phone"
                                name="customer_phone"
                                placeholder="09123456789"
                                required
                                pattern="09[0-9]{9}"
                                dir="ltr">
                        </div>

                        <div class="crm-form-group">
                            <button type="submit" id="crm-submit-btn" class="button crm-btn-submit">
                                ثبت درخواست کد تخفیف
                            </button>
                        </div>

                        <div id="crm-form-message" class="crm-message" style="display: none;"></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
<?php
    }
}
