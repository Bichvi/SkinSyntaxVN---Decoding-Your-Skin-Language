/*
 * Live filter cho mọi form tìm kiếm/lọc danh sách dạng GET (lọc vẫn chạy ở server).
 *
 * Cách dùng: <form method="get" data-live-filter data-live-target="#ketQua"
 *                  [data-live-also="#tabs,#counter"] [data-live-reset="trang,page"]>
 * - Gõ chữ: chờ 300ms rồi tải lại đúng URL của form, chỉ thay nội dung các vùng đích.
 * - Đổi select/checkbox/radio: chạy ngay. Bấm Enter/nút Tìm: chạy ngay, không tải lại trang.
 * - Request gõ/đổi bộ lọc chỉ gửi "X-Requested-With: live-filter" (server không ghi lịch sử, nhả khóa session).
 *   Enter/nút Tìm (submit) là "commit": luôn chạy, kể cả khi truy vấn không đổi, và gửi thêm
 *   "X-Live-Commit: 1" để server ghi lịch sử tìm kiếm đúng như một lần submit thường.
 * - Không có JS (hoặc lỗi mạng): form vẫn submit như cũ.
 */
(function () {
  "use strict";

  const DEBOUNCE_MS = 300;

  function regionsOf(form) {
    const selectors = [form.dataset.liveTarget || ""]
      .concat((form.dataset.liveAlso || "").split(","))
      .map((s) => s.trim())
      .filter(Boolean);
    return selectors;
  }

  function buildUrl(form) {
    const action = new URL(form.getAttribute("action") || window.location.href, window.location.href);
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
      if (typeof value === "string") params.append(key, value);
    });
    (form.dataset.liveReset || "").split(",").map((s) => s.trim()).filter(Boolean).forEach((key) => params.delete(key));
    action.search = params.toString();
    action.hash = window.location.hash;
    return action;
  }

  function setBusy(form, busy) {
    regionsOf(form).forEach((selector) => {
      const node = document.querySelector(selector);
      if (node) node.setAttribute("aria-busy", busy ? "true" : "false");
    });
    form.classList.toggle("is-live-loading", busy);
  }

  function attach(form) {
    const targetSelector = form.dataset.liveTarget;
    if (!targetSelector || !document.querySelector(targetSelector)) return;

    let timer = null;
    let controller = null;
    let lastQuery = buildUrl(form).search;

    async function run(options) {
      const commit = Boolean(options && options.commit);
      const url = buildUrl(form);
      if (!commit && url.search === lastQuery) return;
      lastQuery = url.search;
      if (controller) controller.abort();
      controller = new AbortController();
      setBusy(form, true);
      try {
        const response = await fetch(url.toString(), {
          credentials: "same-origin",
          headers: commit
            ? { "X-Requested-With": "live-filter", "X-Live-Commit": "1" }
            : { "X-Requested-With": "live-filter" },
          signal: controller.signal,
        });
        if (!response.ok) throw new Error("HTTP " + response.status);
        const doc = new DOMParser().parseFromString(await response.text(), "text/html");
        // Nhớ phần tử đang focus (có id) để trả focus nếu vùng bị thay mới
        const active = document.activeElement;
        const activeId = active && active.id ? active.id : "";
        regionsOf(form).forEach((selector) => {
          const current = document.querySelector(selector);
          const fresh = doc.querySelector(selector);
          if (current && fresh) current.innerHTML = fresh.innerHTML;
        });
        if (activeId && !active.isConnected) {
          const again = document.getElementById(activeId);
          if (again && typeof again.focus === "function") again.focus();
        }
        window.history.replaceState(null, "", url.toString());
        form.dispatchEvent(new CustomEvent("live-filter:updated", { bubbles: true }));
      } catch (error) {
        if (error.name !== "AbortError") {
          lastQuery = null;  // lần gõ tiếp theo sẽ thử lại
          console.warn("live-filter:", error.message);
        }
      } finally {
        setBusy(form, false);
      }
    }

    form.addEventListener("input", (event) => {
      const field = event.target;
      if (!field.matches("input[type=search], input[type=text], input[type=number], input:not([type]), textarea")) return;
      clearTimeout(timer);
      timer = setTimeout(run, DEBOUNCE_MS);
    });
    form.addEventListener("change", (event) => {
      if (event.target.matches("select, input[type=checkbox], input[type=radio], input[type=date], input[type=number]")) {
        clearTimeout(timer);
        run();
      }
    });
    // Nút/chip data-live-set='{"gia_tu":"0"}': gán giá trị cho các trường rồi lọc ngay (ủy quyền sự kiện vì vùng bị thay mới)
    // Chip data-live-uncheck='{"name":"thuong_hieu[]","value":"Cocoon"}': bỏ tick đúng một ô rồi lọc
    form.addEventListener("click", (event) => {
      const un = event.target.closest ? event.target.closest("[data-live-uncheck]") : null;
      if (!un || !form.contains(un)) return;
      let spec;
      try {
        spec = JSON.parse(un.getAttribute("data-live-uncheck") || "{}");
      } catch (error) {
        return;
      }
      if (!spec || !spec.name) return;
      event.preventDefault();
      form.querySelectorAll('[name="' + String(spec.name).replace(/"/g, "") + '"]').forEach((el) => {
        if ((el.type === "checkbox" || el.type === "radio") && el.value === String(spec.value)) el.checked = false;
      });
      clearTimeout(timer);
      run();
    });
    form.addEventListener("click", (event) => {
      const trigger = event.target.closest ? event.target.closest("[data-live-set]") : null;
      if (!trigger || !form.contains(trigger)) return;
      let values;
      try {
        values = JSON.parse(trigger.getAttribute("data-live-set") || "{}");
      } catch (error) {
        return;
      }
      if (!values || typeof values !== "object") return;
      event.preventDefault();
      Object.keys(values).forEach((name) => {
        // Giá trị mảng: nhóm checkbox cùng tên, chỉ giữ tick các value nằm trong mảng ([] = bỏ hết)
        if (Array.isArray(values[name])) {
          const keep = values[name].map(String);
          form.querySelectorAll('[name="' + name.replace(/"/g, "") + '"]').forEach((el) => {
            if (el.type === "checkbox" || el.type === "radio") el.checked = keep.includes(el.value) && !el.disabled;
          });
          return;
        }
        const value = String(values[name]);
        const selector = '[name="' + name.replace(/"/g, "") + '"]';
        let field = form.querySelector(selector);
        // Nhóm radio: chọn đúng radio có value khớp; không có radio khớp thì bỏ chọn hết
        if (field && field.type === "radio") {
          form.querySelectorAll(selector).forEach((radio) => {
            radio.checked = radio.type === "radio" && radio.value === value && !radio.disabled;
          });
          return;
        }
        if (!field) {
          field = document.createElement("input");
          field.type = "hidden";
          field.name = name;
          form.appendChild(field);
        }
        if (field.type === "checkbox" || field.type === "radio") {
          field.checked = value !== "";
        } else {
          field.value = value;
        }
      });
      clearTimeout(timer);
      run();
    });
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      clearTimeout(timer);
      run({ commit: true });
    });
  }

  function init(root) {
    (root || document).querySelectorAll("form[data-live-filter]").forEach((form) => {
      if (form.dataset.liveFilterReady) return;
      form.dataset.liveFilterReady = "1";
      attach(form);
    });
  }

  window.LiveFilter = { init };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => init());
  } else {
    init();
  }
})();
