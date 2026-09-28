<?php
if (!defined('ABSPATH')) exit;

class CRM_Manager_Shortcode
{
    public static function init(): void
    {
        add_shortcode('crm_manager_panel', [__CLASS__, 'render']);
    }

    public static function render(): string
    {
        $phone = CRM_Manager_Ajax::session_phone();
        ob_start();
?>
        <div class="crm-manager" dir="rtl">
            <div class="crm-manager-login" <?php if ($phone) echo 'hidden'; ?>>
                <h2>ورود به پنل</h2>
                <p>شماره همراه ثبت‌شده برای رویداد را وارد کنید.</p>
                <form class="crm-manager-phone-form">
                    <label for="crm-manager-phone">شماره همراه</label>
                    <input id="crm-manager-phone" type="tel" inputmode="tel" placeholder="09123456789" dir="ltr" required>
                    <button type="submit" class="crm-manager-send-code"><span class="crm-manager-spinner" aria-hidden="true"></span><span>ارسال کد ورود</span></button>
                </form>
                <div class="crm-manager-otp-success" role="status" hidden>
                    <span class="crm-manager-success-icon" aria-hidden="true">✓</span>
                    <div><strong>کد ورود ارسال شد</strong><p>کد پیامک‌شده را در کادر زیر وارد کنید.</p></div>
                </div>
                <form class="crm-manager-otp-form" hidden>
                    <label for="crm-manager-code">کد پیامک‌شده</label>
                    <input id="crm-manager-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" required>
                    <button type="submit">ورود</button>
                    <button type="button" class="crm-manager-resend crm-manager-secondary">ارسال دوباره کد</button>
                </form>
            </div>
            <div class="crm-manager-panel" <?php if (!$phone) echo 'hidden'; ?>>
                <header class="crm-manager-header"><button type="button" class="crm-manager-logout">خروج</button></header>
                <nav class="crm-manager-tabs" aria-label="بخش‌های پنل">
                    <button type="button" data-tab="dashboard" class="active">داشبورد</button>
                    <button type="button" data-tab="requests">درخواست‌ها</button>
                    <button type="button" data-tab="coupons">کوپن‌ها</button>
                </nav>
                <section class="crm-manager-section" data-section="dashboard">
                    <div class="crm-manager-counts">
                        <div><strong data-count="pending">۰</strong><span>در انتظار</span></div>
                        <div><strong data-count="approved">۰</strong><span>تأییدشده</span></div>
                        <div><strong data-count="unused">۰</strong><span>استفاده‌نشده</span></div>
                        <div><strong data-count="used">۰</strong><span>استفاده شده</span></div>
                    </div>
                </section>
                <section class="crm-manager-section" data-section="requests" hidden>
                    <form class="crm-manager-request-filter crm-manager-filters">
                        <input name="search" type="search" placeholder="جستجوی شماره مشتری" aria-label="جستجوی شماره مشتری">
                        <select name="status" aria-label="وضعیت درخواست">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="pending">در انتظار</option>
                            <option value="approved">تأییدشده</option>
                            <option value="disapproved">ردشده</option>
                        </select>
                        <input name="date" type="date" aria-label="تاریخ درخواست">
                        <button type="submit">جستجو</button>
                    </form>
                    <div class="crm-manager-table-scroll"><table class="crm-manager-table crm-manager-request-table">
                        <thead><tr><th scope="col">رویداد</th><th scope="col">شماره مشتری</th><th scope="col">وضعیت</th><th scope="col">تاریخ ثبت</th><th scope="col">عملیات</th></tr></thead>
                        <tbody class="crm-manager-request-list"></tbody>
                    </table></div>
                </section>
                <section class="crm-manager-section" data-section="coupons" hidden>
                    <form class="crm-manager-coupon-filter crm-manager-filters">
                        <input name="search" type="search" placeholder="کد کوپن یا شماره مشتری" aria-label="جستجوی کوپن">
                        <select name="used" aria-label="وضعیت کوپن">
                            <option value="">همه کوپن‌ها</option>
                            <option value="0">استفاده‌نشده</option>
                            <option value="1">استفاده‌شده</option>
                        </select>
                        <button type="submit">جستجو</button>
                    </form>
                    <div class="crm-manager-table-scroll"><table class="crm-manager-table crm-manager-coupon-table">
                        <thead><tr><th scope="col">رویداد</th><th scope="col">شماره مشتری</th><th scope="col">کد تخفیف</th><th scope="col">درصد تخفیف</th><th scope="col">وضعیت</th><th scope="col">عملیات</th></tr></thead>
                        <tbody class="crm-manager-coupon-list"></tbody>
                    </table></div>
                </section>
            </div>
            <p class="crm-manager-message" role="status" aria-live="polite"></p>
        </div>
        <div class="crm-manager-toast" role="status" aria-live="polite" hidden>خوش آمدید! ورود شما به پنل مدیر با موفقیت انجام شد.</div>
<?php
        return ob_get_clean();
    }
}
