<?php
if (!defined('ABSPATH')) {
    exit;
}

class CRM_Shortcode
{

    private static ?CRM_Shortcode $instance = null;

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
        add_shortcode('coupon_request_form', [$this, 'render_shortcode']);
    }

    public function render_shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'event_id' => 0,
        ], $atts, 'coupon_request_form');

        $event_id = absint($atts['event_id']);
        $event = null;

        if ($event_id > 0 && method_exists('CRM_DB', 'get_event_by_id')) {
            $event = CRM_DB::get_event_by_id($event_id);
        }

        if (!$event) {
            $event = CRM_DB::get_active_event();
        }

        if (!$event) {
            return '<div class="crm-no-event"><p>در حال حاضر هیچ رویداد تخفیف فعالی وجود ندارد.</p></div>';
        }

        ob_start();
?>
        <div class="crm-container">
            <button type="button" id="crm-open-modal-btn" class="button crm-btn-primary">
                دریافت کد تخفیف
            </button>

            <div id="crm-modal" class="crm-modal" style="display: none;" aria-hidden="true">
                <div class="crm-modal-content">
                    <span class="crm-modal-close" id="crm-close-modal-btn">&times;</span>
                    <img src="<?php echo esc_url(plugins_url('../assets/icons/discount-icon.svg', __FILE__)); ?>"
                        alt=""
                        class="crm-modal-icon"
                        width="150" height="150">
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
                </div>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}
