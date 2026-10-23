(function () {
  const form = document.getElementById("liveSearchForm");
  const input = document.getElementById("search-input");
  const dropdown = document.getElementById("smartSearchDropdown");

  if (!form || !input || !dropdown) {
    return;
  }

  const apiUrl = form.dataset.smartSearchUrl || "";
  const indexAction = form.getAttribute("action") || "index.php";
  const DEBOUNCE_MS = 150;
  const MIN_CHARS = 2;
  const OPTION_SELECTOR = ".smart-item, .smart-row, .smart-tag";

  let debounceTimer = null;
  let abortController = null;
  let requestSeq = 0;
  let activeIndex = -1;
  let optionSeq = 0;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // Server gửi *_html (đã escape + <mark>). Chỉ nhận khi ngoài <mark> không còn "<" hay ">" thô;
  // nếu không thì quay về văn bản thuần đã escape (không bao giờ chèn HTML lạ).
  function markup(html, plain) {
    if (typeof html === "string" && html !== "") {
      const rest = html.replace(/<\/?mark>/g, "");
      if (!/[<>]/.test(rest)) {
        return html;
      }
    }
    return escapeHtml(plain || "");
  }

  function formatPrice(value) {
    const numeric = Number(String(value ?? "").replace(/[^0-9.-]/g, ""));
    if (!Number.isFinite(numeric) || numeric <= 0) {
      return "0 đ";
    }
    return new Intl.NumberFormat("vi-VN").format(numeric) + " đ";
  }

  function getPlaceholderImage() {
    const base = (window.BASE_URL && window.BASE_URL !== '.') ? window.BASE_URL : '.';
    return base + '/assets/images/placeholder.svg';
  }

  function getPrimaryImage(raw) {
    if (!raw) {
      return getPlaceholderImage();
    }

    const first = String(raw)
      .split("|")
      .map((part) => part.trim())
      .find((part) => part.length > 0);

    return first || getPlaceholderImage();
  }

  function setExpanded(expanded) {
    input.setAttribute("aria-expanded", expanded ? "true" : "false");
  }

  function setActive(index) {
    const options = dropdown.querySelectorAll(OPTION_SELECTOR);
    activeIndex = options.length ? Math.max(-1, Math.min(index, options.length - 1)) : -1;
    options.forEach(function (el, i) {
      const on = i === activeIndex;
      el.classList.toggle("is-active", on);
      el.setAttribute("aria-selected", on ? "true" : "false");
    });
    if (activeIndex >= 0) {
      input.setAttribute("aria-activedescendant", options[activeIndex].id);
      options[activeIndex].scrollIntoView({ block: "nearest" });
    } else {
      input.removeAttribute("aria-activedescendant");
    }
  }

  function hideDropdown() {
    dropdown.hidden = true;
    dropdown.innerHTML = "";
    activeIndex = -1;
    input.removeAttribute("aria-activedescendant");
    setExpanded(false);
  }

  function showDropdown() {
    dropdown.hidden = false;
    setExpanded(true);
    // Gán id + role cho từng lựa chọn để aria-activedescendant trỏ đúng phần tử
    dropdown.querySelectorAll(OPTION_SELECTOR).forEach(function (el) {
      el.id = "smart-opt-" + (++optionSeq);
      el.setAttribute("role", "option");
      el.setAttribute("aria-selected", "false");
    });
    activeIndex = -1;
    input.removeAttribute("aria-activedescendant");
  }

  function searchUrl(q) {
    return indexAction + "?r=tatca&q=" + encodeURIComponent(q);
  }

  function sectionHtml(titleId, title, body) {
    return (
      '<section class="smart-section" role="group" aria-labelledby="' + titleId + '">' +
      '<h4 class="smart-section-title" id="' + titleId + '">' + escapeHtml(title) + "</h4>" +
      body +
      "</section>"
    );
  }

  function buildProductItem(item, keyword) {
    const id = encodeURIComponent(item.id || item.ma_san_pham || "");
    const q = keyword ? "&q=" + encodeURIComponent(keyword) : "";
    const detailUrl = indexAction + "?r=chitiet&id=" + id + q;
    const plainName = item.ten_san_pham || item.name || "";
    const name = markup(item.name_html, plainName);
    const alt = escapeHtml(plainName);
    const brand = escapeHtml(item.thuong_hieu || "");
    const price = escapeHtml(formatPrice(item.gia_ban));
    const img = escapeHtml(getPrimaryImage(item.link_hinh_anh));
    const fallbackImg = getPlaceholderImage();

    return (
      '<a class="smart-item" href="' +
      escapeHtml(detailUrl) +
      '">' +
      '<img class="smart-item-thumb" src="' +
      img +
      '" alt="' +
      alt +
      '" loading="lazy" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src=\'' + fallbackImg + '\';">' +
      '<span class="smart-item-meta">' +
      '<span class="smart-item-name">' +
      name +
      "</span>" +
      '<span class="smart-item-sub">' +
      brand +
      "</span>" +
      '<span class="smart-item-price">' +
      price +
      "</span>" +
      "</span>" +
      "</a>"
    );
  }

  function buildRow(icon, htmlLabel, plain, query) {
    return (
      '<a class="smart-row" href="' + escapeHtml(searchUrl(query)) + '" title="' + escapeHtml(plain) + '">' +
      '<i class="smart-row-icon fa-solid ' + icon + '" aria-hidden="true"></i>' +
      '<span class="smart-row-label">' + htmlLabel + "</span>" +
      "</a>"
    );
  }

  function renderZeroQuery(data) {
    const history = Array.isArray(data.history) ? data.history : [];
    const trending = Array.isArray(data.trending) ? data.trending : [];

    const historySection =
      '<section class="smart-section" role="group" aria-labelledby="smart-title-history">' +
      '<h4 class="smart-section-title" id="smart-title-history">Tìm kiếm gần đây</h4>' +
      (history.length
        ? '<div class="smart-tags">' +
          history
            .map(function (tag) {
              const val = escapeHtml(String(tag || ""));
              return '<button type="button" class="smart-tag" data-keyword="' + val + '">' + val + "</button>";
            })
            .join("") +
          "</div>"
        : '<div class="smart-empty">Chưa có lịch sử tìm kiếm.</div>') +
      "</section>";

    const trendingSection =
      '<section class="smart-section" role="group" aria-labelledby="smart-title-trending">' +
      '<h4 class="smart-section-title" id="smart-title-trending">Top Trending</h4>' +
      (trending.length
        ? trending
            .map(function (item) {
              return buildProductItem(item, "");
            })
            .join("")
        : '<div class="smart-empty">Chưa có dữ liệu trending.</div>') +
      "</section>";

    dropdown.innerHTML = historySection + trendingSection;
    showDropdown();
  }

  function renderLiveSearch(data, keyword) {
    const products = Array.isArray(data.results) ? data.results : [];
    const groups = data.groups && typeof data.groups === "object" ? data.groups : {};
    const brands = Array.isArray(groups.brands) ? groups.brands : [];
    const categories = Array.isArray(groups.categories) ? groups.categories : [];
    const queries = Array.isArray(groups.queries) ? groups.queries : [];

    let html = "";
    if (products.length) {
      html += sectionHtml(
        "smart-title-products",
        "Sản phẩm",
        products.map(function (item) { return buildProductItem(item, keyword); }).join("")
      );
    }
    if (brands.length) {
      html += sectionHtml(
        "smart-title-brands",
        "Thương hiệu",
        brands.map(function (b) { return buildRow("fa-tag", markup(b.name_html, b.name), b.name, b.q || b.name); }).join("")
      );
    }
    if (categories.length) {
      html += sectionHtml(
        "smart-title-categories",
        "Danh mục",
        categories.map(function (c) { return buildRow("fa-layer-group", markup(c.name_html, c.name), c.name, c.q || c.name); }).join("")
      );
    }
    if (queries.length) {
      html += sectionHtml(
        "smart-title-queries",
        "Tìm kiếm phổ biến",
        queries.map(function (s) { return buildRow("fa-magnifying-glass", markup(s.q_html, s.q), s.q, s.q); }).join("")
      );
    }
    if (!html) {
      html = '<div class="smart-empty">Không tìm thấy sản phẩm phù hợp.</div>';
    }

    dropdown.innerHTML = html;
    showDropdown();
  }

  async function fetchSmart(query) {
    if (!apiUrl) {
      return;
    }

    if (abortController) {
      abortController.abort();
    }
    abortController = new AbortController();
    const seq = ++requestSeq;

    const params = new URLSearchParams({ q: query });

    try {
      const response = await fetch(apiUrl + "&" + params.toString(), {
        method: "GET",
        headers: { Accept: "application/json" },
        signal: abortController.signal,
      });

      if (!response.ok) {
        throw new Error("Request failed");
      }

      const data = await response.json();
      if (seq !== requestSeq || input.value.trim() !== query) {
        return; // đã gõ tiếp hoặc xóa: bỏ kết quả cũ
      }

      if (data.type === "zero_query") {
        renderZeroQuery(data);
        return;
      }

      if (data.type === "live_search") {
        renderLiveSearch(data, query);
        return;
      }

      hideDropdown();
    } catch (error) {
      if (error.name === "AbortError") {
        return;
      }
      dropdown.innerHTML = '<div class="smart-empty">Không thể tải dữ liệu tìm kiếm.</div>';
      showDropdown();
    }
  }

  function scheduleFetch() {
    const query = input.value.trim();
    clearTimeout(debounceTimer);
    if (query.length > 0 && query.length < MIN_CHARS) {
      // Dưới 2 ký tự: không gọi server, hủy request đang bay
      if (abortController) {
        abortController.abort();
      }
      requestSeq++;
      hideDropdown();
      return;
    }
    debounceTimer = setTimeout(function () {
      fetchSmart(query);
    }, DEBOUNCE_MS);
  }

  input.addEventListener("focus", function () {
    if (input.value.trim() === "") {
      fetchSmart("");
      return;
    }
    scheduleFetch();
  });

  input.addEventListener("input", function () {
    scheduleFetch();
  });

  dropdown.addEventListener("click", function (event) {
    const tag = event.target.closest(".smart-tag");
    if (!tag) {
      return;
    }

    const keyword = tag.getAttribute("data-keyword") || "";
    input.value = keyword;
    fetchSmart(keyword);
  });

  dropdown.addEventListener("mousemove", function (event) {
    const option = event.target.closest(OPTION_SELECTOR);
    if (!option) {
      return;
    }
    const options = Array.prototype.slice.call(dropdown.querySelectorAll(OPTION_SELECTOR));
    const index = options.indexOf(option);
    if (index !== activeIndex) {
      setActive(index);
    }
  });

  document.addEventListener("click", function (event) {
    if (!form.contains(event.target)) {
      hideDropdown();
    }
  });

  input.addEventListener("keydown", function (event) {
    const open = !dropdown.hidden;
    const count = open ? dropdown.querySelectorAll(OPTION_SELECTOR).length : 0;

    if (event.key === "Escape") {
      if (open) {
        event.preventDefault();
        hideDropdown();
      }
      return;
    }

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      if (!open) {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          clearTimeout(debounceTimer);
          const typed = input.value.trim();
          if (typed.length === 0 || typed.length >= MIN_CHARS) {
            fetchSmart(typed);
          }
        }
        return;
      }
      if (!count) {
        return;
      }
      event.preventDefault();
      if (event.key === "ArrowDown") {
        setActive(activeIndex + 1 >= count ? 0 : activeIndex + 1);
      } else {
        setActive(activeIndex <= 0 ? count - 1 : activeIndex - 1);
      }
      return;
    }

    if (event.key === "Enter" && open && activeIndex >= 0) {
      const option = dropdown.querySelectorAll(OPTION_SELECTOR)[activeIndex];
      if (option) {
        event.preventDefault();
        option.click();
      }
    }
  });
})();
