jQuery(function ($) {
  "use strict";
  if (typeof crm_manager_data === "undefined") return;
  $(".crm-manager").each(function () {
    const root = $(this),
      message = root.find(".crm-manager-message");
    let phone = "";
    let toastTimer;
    const labels = {
      pending: "در انتظار",
      approved: "تأییدشده",
      disapproved: "ردشده",
    };
    const autoSwitch = root.find(".crm-manager-switch");
    function setAutoSwitch(on) {
      autoSwitch.attr("aria-checked", on ? "true" : "false");
    }
    function note(value, error) {
      message.text(value || "").toggleClass("error", !!error);
    }
    function call(action, data) {
      return $.ajax({
        url: crm_manager_data.ajax_url,
        method: "POST",
        dataType: "json",
        data: $.extend(
          { action: "crm_manager_" + action, nonce: crm_manager_data.nonce },
          data || {},
        ),
      }).then(
        function (res) {
          if (!res.success) return $.Deferred().reject(res).promise();
          return res.data;
        },
        function (xhr) {
          if (
            xhr.status === 401 &&
            xhr.responseJSON &&
            xhr.responseJSON.data &&
            xhr.responseJSON.data.expired
          )
            showLogin();
          return $.Deferred()
            .reject(xhr.responseJSON || {})
            .promise();
        },
      );
    }
    function fail(res) {
      note(
        (res && res.data && res.data.message) || "ارتباط با سرور ناموفق بود.",
        true,
      );
    }
    function showLogin() {
      root.find(".crm-manager-panel").prop("hidden", true);
      root.find(".crm-manager-login").prop("hidden", false);
    }
    function showPanel() {
      root.find(".crm-manager-login").prop("hidden", true);
      root.find(".crm-manager-panel").prop("hidden", false);
      load("dashboard");
    }
    function welcome() {
      const toast = root.next(".crm-manager-toast");
      clearTimeout(toastTimer);
      toast.prop("hidden", false);
      toastTimer = setTimeout(function () { toast.prop("hidden", true); }, 4500);
    }
    function cell(tag, value, className) {
      return $("<" + tag + ">")
        .addClass(className || "")
        .text(value == null ? "" : value);
    }
    function row(item, coupon) {
      const box = $("<tr>"), actions = $("<td class='crm-manager-actions'>");
      box.append(cell("td", item.event_name), cell("td", item.customer_phone, "crm-manager-ltr"));
      if (coupon) {
        box.append(
          cell("td", item.coupon_code, "crm-manager-ltr"),
          cell("td", item.discount_value + "٪"),
          cell("td", item.is_used == 1 ? "استفاده‌شده" : "استفاده‌نشده", item.is_used == 1 ? "crm-manager-status-used" : ""),
        );
        if (item.is_used == 0) actions.append(
          $("<button type='button'>").text("ثبت استفاده").attr("data-coupon-id", item.id),
        );
      } else {
        box.append(
          cell("td", labels[item.status] || item.status, "crm-manager-status-" + item.status),
          cell("td", CRMJalali.format(item.created_at), "crm-manager-ltr"),
        );
        if (item.status === "pending") actions.append(
          $("<button type='button'>").text("تأیید").attr("data-approve-id", item.id),
          $("<button type='button' class='crm-manager-secondary'>").text("رد").attr("data-reject-id", item.id),
        );
      }
      if (!actions.children().length) actions.text("—");
      return box.append(actions);
    }
    function load(tab) {
      if (tab === "dashboard")
        call("get_dashboard")
          .done(function (data) {
            $.each(data.counts, function (key, value) {
              root
                .find('[data-count="' + key + '"]')
                .text(Number(value).toLocaleString("fa-IR"));
            });
          })
          .fail(fail);
      if (tab === "requests")
        call(
          "get_requests",
          Object.fromEntries(
            root
              .find(".crm-manager-request-filter")
              .serializeArray()
              .map(function (field) {
                return [field.name, field.value];
              }),
          ),
        )
          .done(function (data) {
            if (typeof data.auto_approve !== "undefined")
              setAutoSwitch(!!data.auto_approve);
            const list = root.find(".crm-manager-request-list").empty();
            if (!data.rows.length) list.append($("<tr>").append(cell("td", "درخواستی یافت نشد.").attr("colspan", 5)));
            data.rows.forEach(function (item) {
              list.append(row(item, false));
            });
          })
          .fail(fail);
      if (tab === "coupons")
        call(
          "get_coupons",
          Object.fromEntries(
            root
              .find(".crm-manager-coupon-filter")
              .serializeArray()
              .map(function (field) {
                return [field.name, field.value];
              }),
          ),
        )
          .done(function (data) {
            const list = root.find(".crm-manager-coupon-list").empty();
            if (!data.rows.length) list.append($("<tr>").append(cell("td", "کوپنی یافت نشد.").attr("colspan", 6)));
            data.rows.forEach(function (item) {
              list.append(row(item, true));
            });
          })
          .fail(fail);
    }
    root.find(".crm-manager-phone-form").on("submit", function (event) {
      event.preventDefault();
      phone = root.find("#crm-manager-phone").val();
      note("");
      const sendButton = root.find(".crm-manager-send-code");
      sendButton.prop("disabled", true).addClass("is-loading");
      root.find(".crm-manager-otp-success").prop("hidden", true);
      call("request_otp", { phone: phone })
        .done(function () {
          root.find(".crm-manager-otp-success").prop("hidden", false);
          root.find(".crm-manager-otp-form").prop("hidden", false);
          root.find("#crm-manager-code").trigger("focus");
        })
        .fail(fail)
        .always(function () { sendButton.prop("disabled", false).removeClass("is-loading"); });
    });
    root.find(".crm-manager-resend").on("click", function () {
      call("request_otp", { phone: phone })
        .done(function (data) {
          note("");
          root.find(".crm-manager-otp-success").prop("hidden", false);
        })
        .fail(fail);
    });
    root.find(".crm-manager-otp-form").on("submit", function (event) {
      event.preventDefault();
      call("verify_otp", {
        phone: phone,
        code: root.find("#crm-manager-code").val(),
      })
        .done(function () {
          note("");
          showPanel();
          welcome();
        })
        .fail(fail);
    });
    root.find(".crm-manager-logout").on("click", function () {
      call("logout")
        .done(function () {
          showLogin();
          note("خارج شدید.");
        })
        .fail(fail);
    });
    root.find(".crm-manager-tabs button").on("click", function () {
      const tab = $(this).data("tab");
      root.find(".crm-manager-tabs button").removeClass("active");
      $(this).addClass("active");
      root
        .find(".crm-manager-section")
        .prop("hidden", true)
        .filter('[data-section="' + tab + '"]')
        .prop("hidden", false);
      note("");
      load(tab);
    });
    autoSwitch.on("click", function () {
      const button = $(this),
        next = button.attr("aria-checked") !== "true";
      button.prop("disabled", true);
      note("");
      call("set_auto_approve", { enabled: next ? 1 : 0 })
        .done(function (data) {
          setAutoSwitch(!!data.enabled);
          let text = data.enabled
            ? "تأیید خودکار فعال شد."
            : "تأیید خودکار غیرفعال شد.";
          if (data.enabled && data.approved)
            text +=
              " " +
              Number(data.approved).toLocaleString("fa-IR") +
              " درخواست در انتظار تأیید شد.";
          if (data.enabled && data.remaining)
            text +=
              " " +
              Number(data.remaining).toLocaleString("fa-IR") +
              " درخواست باقی‌مانده در پس‌زمینه تأیید می‌شود.";
          note(text);
          load("requests");
          load("dashboard");
        })
        .fail(fail)
        .always(function () {
          button.prop("disabled", false);
        });
    });
    root.find(".crm-manager-filters").on("submit change", function (event) {
      event.preventDefault();
      load(
        $(this).hasClass("crm-manager-request-filter") ? "requests" : "coupons",
      );
    });
    root.on(
      "click",
      "[data-approve-id], [data-reject-id], [data-coupon-id]",
      function () {
        const button = $(this),
          approve = button.attr("data-approve-id"),
          reject = button.attr("data-reject-id"),
          coupon = button.attr("data-coupon-id");
        button.prop("disabled", true);
        call(
          coupon
            ? "mark_coupon_used"
            : approve
              ? "approve_request"
              : "disapprove_request",
          coupon ? { coupon_id: coupon } : { request_id: approve || reject },
        )
          .done(function (data) {
            note(data.message);
            load(coupon ? "coupons" : "requests");
            load("dashboard");
          })
          .fail(function (res) {
            fail(res);
            button.prop("disabled", false);
          });
      },
    );
    if (!root.find(".crm-manager-panel").prop("hidden")) load("dashboard");
  });
});
