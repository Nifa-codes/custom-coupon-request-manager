jQuery(document).ready(function ($) {
  "use strict";

  var $modal = $("#crm-modal");
  var $openBtn = $("#crm-open-modal-btn");
  var $closeBtn = $("#crm-close-modal-btn");
  var $content = $modal.find(".crm-modal-content");
  var $form = $("#crm-request-form");
  var $phoneInput = $("#crm_customer_phone");
  var $responseMsg = $("#crm-form-message");
  var $submitBtn = $("#crm-submit-btn");

  var $title = $content.find("h2");
  var originalTitle = $title.text(); // ذخیره عنوان اولیه
  var $eventInfo = $(".crm-event-info");
  var $modalIcon = $(".crm-modal-icon");

  function showMessage(type, html) {
    // type: "success" | "error"
    $responseMsg
      .stop(true, true)
      .removeClass("success error")
      .addClass(type)
      .html(html)
      .show();
  }

  function clearMessage() {
    $responseMsg.removeClass("success error").empty().hide();
    $content.find(".crm-success-wrapper").remove();
  }

  function openModal() {
    $modal.fadeIn(200).attr("aria-hidden", "false");
  }

  function closeModal() {
    $modal.fadeOut(200).attr("aria-hidden", "true");

    // بازگرداندن وضعیت مودال به حالت اولیه برای استفاده‌های بعدی
    clearMessage();
    $form.show();
    $eventInfo.show();
    $title.show().text(originalTitle);
    if ($modalIcon.length) {
      $modalIcon.show();
    }
    if ($submitBtn.length) {
      $submitBtn.prop("disabled", false).text("ثبت درخواست کد تخفیف");
    }
  }

  // Safety guards
  if (!$modal.length || !$openBtn.length || !$form.length) return;

  $openBtn.on("click", function (e) {
    e.preventDefault();
    openModal();
  });

  $closeBtn.on("click", function (e) {
    e.preventDefault();
    closeModal();
  });

  $modal.on("click", function () {
    closeModal();
  });

  $content.on("click", function (e) {
    e.stopPropagation();
  });

  $form.on("submit", function (e) {
    e.preventDefault();

    var phone = ($phoneInput.val() || "").trim();
    var eventId = $('input[name="event_id"]').val() || "";

    // تبدیل ارقام فارسی و عربی به انگلیسی
    phone = phone
      .replace(/[۰-۹]/g, function (d) {
        return String(d.charCodeAt(0) - 1776);
      })
      .replace(/[٠-٩]/g, function (d) {
        return String(d.charCodeAt(0) - 1632);
      });

    // اعتبارسنجی شماره موبایل ایران
    if (!/^09\d{9}$/.test(phone)) {
      showMessage(
        "error",
        "لطفاً شماره موبایل معتبر ۱۱ رقمی وارد کنید (مانند 09123456789).",
      );
      return;
    }

    if (typeof crm_data === "undefined" || !crm_data.ajax_url) {
      showMessage("error", "خطای پیکربندی: crm_data در دسترس نیست.");
      return;
    }

    clearMessage();

    // فعال‌سازی حالت لودینگ دکمه
    $submitBtn
      .prop("disabled", true)
      .html('<span class="crm-spinner"></span> در حال ارسال...');

    $.ajax({
      url: crm_data.ajax_url,
      type: "POST",
      dataType: "json",
      data: {
        action: "crm_submit_request",
        nonce: crm_data.nonce,
        phone: phone,
        event_id: eventId,
      },

      success: function (res) {
        if (res && res.success) {
          // مخفی کردن بخش‌های فرم و اطلاعات برای نمایش انیمیشن تمیز
          $form.hide();
          $eventInfo.hide();
          $title.hide();
          if ($modalIcon.length) {
            $modalIcon.hide();
          }

          var msg =
            res.data && res.data.message
              ? res.data.message
              : "درخواست شما با موفقیت ثبت شد و پس از بررسی، کد تخفیف پیامک خواهد شد.";

          // ایجاد ساختار SVG با هندسه مشخص تیک (دارای زاویه استاندارد تیک)
          var successHtml =
            '<div class="crm-success-wrapper">' +
            '<div class="crm-success-icon-box">' +
            '<svg class="crm-checkmark-svg" viewBox="0 0 100 100">' +
            '<circle class="crm-svg-circle" cx="50" cy="50" r="40" />' +
            '<path class="crm-svg-check" d="M 28 52 L 44 68 L 74 34" />' +
            "</svg>" +
            "</div>" +
            '<div class="crm-success-text">' +
            msg +
            "</div>" +
            "</div>";

          $content.append(successHtml);
        } else {
          $submitBtn.prop("disabled", false).text("ثبت درخواست کد تخفیف");
          showMessage(
            "error",
            res && res.data && res.data.message
              ? res.data.message
              : "خطا در ثبت درخواست.",
          );
        }
      },
      error: function () {
        $submitBtn.prop("disabled", false).text("ثبت درخواست کد تخفیف");
        showMessage(
          "error",
          "خطایی در برقراری ارتباط با سرور رخ داد. لطفاً مجدداً تلاش کنید.",
        );
      },
    });
  });
});
