(function (global) {
  "use strict";
  const formatter = new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
    timeZone: "UTC",
    year: "numeric",
    month: "numeric",
    day: "numeric",
  });
  const monthNames = [
    "فروردین",
    "اردیبهشت",
    "خرداد",
    "تیر",
    "مرداد",
    "شهریور",
    "مهر",
    "آبان",
    "آذر",
    "دی",
    "بهمن",
    "اسفند",
  ];
  const pad = (n) => String(n).padStart(2, "0");
  function parseParts(sql) {
    const match = String(sql || "").match(
      /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/,
    );
    if (!match) return null;
    const [year, month, day] = match.slice(1, 4).map(Number);
    const utc = new Date(Date.UTC(year, month - 1, day, 12));
    if (
      utc.getUTCFullYear() !== year ||
      utc.getUTCMonth() !== month - 1 ||
      utc.getUTCDate() !== day
    )
      return null;
    const parts = Object.fromEntries(
      formatter
        .formatToParts(utc)
        .map((part) => [part.type, Number(part.value)]),
    );
    return {
      year: parts.year,
      month: parts.month,
      day: parts.day,
      hour: match[4] || "00",
      minute: match[5] || "00",
      second: match[6] || "00",
    };
  }
  function format(sql, seconds = true) {
    const p = parseParts(sql);
    if (!p) return sql || "—";
    return `${p.year}-${pad(p.month)}-${pad(p.day)} ${p.hour}:${p.minute}${seconds ? ":" + p.second : ""}`;
  }
  function toGregorian(year, month, day) {
    const jy = year + 1595;
    let days =
      -355668 +
      365 * jy +
      Math.floor(jy / 33) * 8 +
      Math.floor(((jy % 33) + 3) / 4) +
      day +
      (month < 7 ? (month - 1) * 31 : (month - 7) * 30 + 186);
    let gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    const date = new Date(Date.UTC(gy, 0, days + 1));
    return date;
  }
  function daysInMonth(year, month) {
    if (month <= 6) return 31;
    if (month <= 11) return 30;
    return Math.round(
      (toGregorian(year + 1, 1, 1) - toGregorian(year, 1, 1)) / 86400000,
    ) === 366
      ? 30
      : 29;
  }
  function initPicker(input) {
    if (input.dataset.jalaliReady === "1") return;
    input.dataset.jalaliReady = "1";
    const saved =
      input.dataset.sqlDatetime === "2099-12-31 23:59:59"
        ? null
        : parseParts(input.dataset.sqlDatetime);
    const today =
      parseParts(input.dataset.today) ||
      parseParts(new Date().toISOString().slice(0, 10));
    let year = saved ? saved.year : today.year;
    let month = saved ? saved.month : today.month;
    let view = "days"; // "days" | "months" | "years"
    let yearsStart = year - 4;
    let selected = saved
      ? { year: saved.year, month: saved.month, day: saved.day }
      : null;
    let selectedTime = saved ? saved.hour + ":" + saved.minute : "";
    let timeChosen = !!saved; // the time is only written to the input when the user chose one
    if (saved) input.value = format(input.dataset.sqlDatetime, false);
    const host = document.createElement("span");
    host.className = "crm-jalali-picker-host";
    input.parentNode.insertBefore(host, input);
    host.appendChild(input);
    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "button crm-jalali-toggle";
    toggle.textContent = "انتخاب تاریخ شمسی";
    toggle.setAttribute("aria-label", "بازکردن تقویم شمسی");
    host.appendChild(toggle);
    const panel = document.createElement("div");
    panel.className = "crm-jalali-panel";
    panel.hidden = true;
    host.appendChild(panel);
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-expanded", "false");
    function show() {
      if (!panel.hidden) return;
      view = "days";
      if (selected) {
        year = selected.year;
        month = selected.month;
      }
      panel.hidden = false;
      input.setAttribute("aria-expanded", "true");
      render();
    }
    function hide() {
      panel.hidden = true;
      input.setAttribute("aria-expanded", "false");
    }
    function commit() {
      if (!selected) return;
      input.value =
        `${selected.year}-${pad(selected.month)}-${pad(selected.day)}` +
        (timeChosen ? " " + selectedTime : "");
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
    function button(text, onclick, className) {
      const el = document.createElement("button");
      el.type = "button";
      el.textContent = text;
      if (className) el.className = className;
      el.onclick = onclick;
      return el;
    }
    function list(items) {
      const box = document.createElement("div");
      box.className = "crm-jalali-list";
      items.forEach((item) =>
        box.append(
          button(item.text, item.onclick, item.selected ? "selected" : ""),
        ),
      );
      return box;
    }
    function render() {
      panel.replaceChildren();
      const header = document.createElement("div");
      header.className = "crm-jalali-header";
      const title = document.createElement("span");
      title.className = "crm-jalali-title";
      const openYears = () => {
        view = "years";
        yearsStart = year - 4;
        render();
      };
      let prev, next, body;
      if (view === "days") {
        prev = button("ماه قبل", () => {
          if (--month < 1) {
            month = 12;
            year--;
          }
          render();
        });
        next = button("ماه بعد", () => {
          if (++month > 12) {
            month = 1;
            year++;
          }
          render();
        });
        title.append(
          button(monthNames[month - 1], () => {
            view = "months";
            render();
          }),
          button(String(year), openYears),
        );
        body = document.createElement("div");
        body.className = "crm-jalali-days";
        ["ش", "ی", "د", "س", "چ", "پ", "ج"].forEach((label) => {
          const span = document.createElement("span");
          span.textContent = label;
          body.append(span);
        });
        const offset = (toGregorian(year, month, 1).getUTCDay() + 1) % 7;
        for (let i = 0; i < offset; i++)
          body.append(document.createElement("span"));
        for (let n = 1; n <= daysInMonth(year, month); n++) {
          const isSelected =
            selected &&
            selected.year === year &&
            selected.month === month &&
            selected.day === n;
          body.append(
            button(
              String(n),
              () => {
                selected = { year, month, day: n };
                commit();
                hide();
              },
              isSelected ? "selected" : "",
            ),
          );
        }
      } else if (view === "months") {
        prev = button("سال قبل", () => {
          year--;
          render();
        });
        next = button("سال بعد", () => {
          year++;
          render();
        });
        title.append(button(String(year), openYears));
        body = list(
          monthNames.map((name, i) => ({
            text: name,
            selected: month === i + 1,
            onclick: () => {
              month = i + 1;
              view = "days";
              render();
            },
          })),
        );
      } else {
        prev = button("قبلی", () => {
          yearsStart -= 12;
          render();
        });
        next = button("بعدی", () => {
          yearsStart += 12;
          render();
        });
        const range = document.createElement("strong");
        range.textContent = `${yearsStart} - ${yearsStart + 11}`;
        title.append(range);
        body = list(
          Array.from({ length: 12 }, (_, i) => {
            const y = yearsStart + i;
            return {
              text: String(y),
              selected: y === year,
              onclick: () => {
                year = y;
                view = "days";
                render();
              },
            };
          }),
        );
      }
      header.append(prev, title, next);
      panel.append(header, body);
      if (view !== "days") return;
      const footer = document.createElement("div");
      footer.className = "crm-jalali-footer";
      const label = document.createElement("span");
      label.textContent = "ساعت (اختیاری)";
      const time = document.createElement("input");
      time.type = "time";
      time.value = timeChosen ? selectedTime : "";
      time.setAttribute("aria-label", "ساعت (اختیاری)");
      time.addEventListener("change", (event) => {
        event.stopPropagation(); // commit() fires the single change event the page listens for
        selectedTime = time.value;
        timeChosen = !!time.value;
        commit();
      });
      footer.append(label, time);
      panel.append(footer);
    }
    toggle.onclick = () => {
      if (panel.hidden) show();
      else hide();
    };
    input.addEventListener("click", show);
    // composedPath() still contains the host after render() removed the clicked button from the DOM.
    document.addEventListener("click", (event) => {
      if (!event.composedPath().includes(host)) hide();
    });
    host.addEventListener("keydown", (event) => {
      if (event.key === "Escape") hide();
    });
  }
  global.CRMJalali = { format, initPicker };
  function initPage() {
    document.querySelectorAll(".crm-jalali-datetime").forEach((element) => {
      element.textContent = format(element.getAttribute("data-sql-datetime"));
    });
    document.querySelectorAll(".crm-jalali-input").forEach(initPicker);
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", initPage);
  else initPage();
})(window);
