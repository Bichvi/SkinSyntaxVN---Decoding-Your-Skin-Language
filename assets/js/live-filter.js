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
        regionsOf(form).forEach((selector) => {
          const current = document.querySelector(selector);
          const fresh = doc.querySelector(selector);
          if (current && fresh) current.innerHTML = fresh.innerHTML;
        });
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
