(function () {
  const form = document.getElementById("liveSearchForm");
  const input = document.getElementById("search-input");
  const dropdown = document.getElementById("smartSearchDropdown");

  if (!form || !input || !dropdown) {
    return;
  }

  const apiUrl = form.dataset.smartSearchUrl || "";
  const indexAction = form.getAttribute("action") || "index.php";
  const DEBOUNCE_MS = 120;

  let debounceTimer = null;
  let abortController = null;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function formatPrice(value) {
    const numeric = Number(String(value ?? "").replace(/[^0-9.-]/g, ""));
    if (!Number.isFinite(numeric) || numeric <= 0) {
      return "0 đ";
    }
    return new Intl.NumberFormat("vi-VN").format(numeric) + " đ";
  }

  function getPrimaryImage(raw) {
    if (!raw) {
      return "https://via.placeholder.com/96x96?text=No+Image";
    }

    const first = String(raw)
      .split("|")
      .map((part) => part.trim())
      .find((part) => part.length > 0);

    return first || "https://via.placeholder.com/96x96?text=No+Image";
  }

  function setExpanded(expanded) {
    input.setAttribute("aria-expanded", expanded ? "true" : "false");
  }

  function hideDropdown() {
    dropdown.hidden = true;
    dropdown.innerHTML = "";
    setExpanded(false);
  }

  function showDropdown() {
    dropdown.hidden = false;
    setExpanded(true);
  }

  function buildProductItem(item, keyword) {
    const id = encodeURIComponent(item.id || item.ma_san_pham || "");
    const q = keyword ? "&q=" + encodeURIComponent(keyword) : "";
    const detailUrl = indexAction + "?r=chitiet&id=" + id + q;
    const name = escapeHtml(item.ten_san_pham || item.name || "");
    const brand = escapeHtml(item.thuong_hieu || "");
    const price = escapeHtml(formatPrice(item.gia_ban));
    const img = escapeHtml(getPrimaryImage(item.link_hinh_anh));

    return (
      '<a class="smart-item" href="' +
      detailUrl +
      '">' +
      '<img class="smart-item-thumb" src="' +
      img +
      '" alt="' +
      name +
      '" loading="lazy" referrerpolicy="no-referrer" onerror="this.src=\'https://via.placeholder.com/96x96?text=No+Image\';">' +
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

  function renderZeroQuery(data) {
    const history = Array.isArray(data.history) ? data.history : [];
    const trending = Array.isArray(data.trending) ? data.trending : [];

    const historySection =
      '<section class="smart-section">' +
      '<h4 class="smart-section-title">Tìm kiếm gần đây</h4>' +
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
      '<section class="smart-section">' +
      '<h4 class="smart-section-title">Top Trending</h4>' +
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
    const results = Array.isArray(data.results) ? data.results : [];

    const html =
      '<section class="smart-section">' +
      '<h4 class="smart-section-title">Kết quả tìm kiếm</h4>' +
      (results.length
        ? results
            .map(function (item) {
              return buildProductItem(item, keyword);
            })
            .join("")
        : '<div class="smart-empty">Không tìm thấy sản phẩm phù hợp.</div>') +
      "</section>";

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

  document.addEventListener("click", function (event) {
    if (!form.contains(event.target)) {
      hideDropdown();
    }
  });

  input.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      hideDropdown();
      input.blur();
    }
  });
})();
