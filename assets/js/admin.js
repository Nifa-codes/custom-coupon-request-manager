jQuery(document).ready(function ($) {
  "use strict";

  // Retry OTP Button Handler
  $(document).on("click", ".crm-retry-otp-btn", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var couponId = $btn.data("coupon-id");

    if (!couponId) {
      alert("شناسه کوپن نامعتبر است.");
      return;
    }

    $btn.prop("disabled", true).text("در حال ارسال...");

    $.ajax({
      url: crm_admin_data.ajax_url,
      type: "POST",
      dataType: "json",
      data: {
        action: "crm_retry_otp",
        coupon_id: couponId,
        nonce: crm_admin_data.nonce,
      },
      success: function (res) {
        if (res.success) {
          alert(res.data.message || "پیامک با موفقیت مجدداً ارسال شد.");
          location.reload();
        } else {
          alert(res.data.message || "خطا در ارسال مجدد پیامک.");
          $btn.prop("disabled", false).text("تلاش مجدد ارسال پیامک");
        }
      },
      error: function () {
        alert("خطای ارتباط با سرور.");
        $btn.prop("disabled", false).text("تلاش مجدد ارسال پیامک");
      },
    });
  });

  // Mark as Used Button Handler
  $(document).on("click", ".mark-used-btn", function (e) {
    e.preventDefault();

    var $btn = $(this);
    var originalText = $btn.text();
    var couponId = $btn.data("id");
    var $row = $btn.closest("tr");

    if (!couponId) {
      return;
    }

    if (
      !confirm(
        "آیا از علامت‌گذاری این کد تخفیف به‌عنوان استفاده‌شده اطمینان دارید؟",
      )
    ) {
      return;
    }

    $btn.prop("disabled", true);

    $.ajax({
      url: crm_admin_data.ajax_url,
      type: "POST",
      data: {
        action: "crm_mark_coupon_used",
        coupon_id: couponId,
        nonce: crm_admin_data.nonce,
      },
      success: function (response) {
        if (response.success) {
          var formattedDate =
            response.data && response.data.formatted_date
              ? response.data.formatted_date
              : "—";
          $row.find("td.column-used_at").text(formattedDate);
          $btn.remove();
        } else {
          $btn.prop("disabled", false).text(originalText);
          alert(
            response.data && response.data.message
              ? response.data.message
              : "خطایی رخ داد.",
          );
        }
      },
      error: function () {
        $btn.prop("disabled", false).text(originalText);
        alert("خطای ارتباط با سرور.");
      },
    });
  });
});
