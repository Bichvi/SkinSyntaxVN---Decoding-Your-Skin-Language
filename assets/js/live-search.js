(function () {
  const form = document.getElementById("liveSearchForm");
  const input = document.getElementById("liveSearchInput");
  const dropdown = document.getElementById("liveSearchResults");

  if (!form || !input || !dropdown) {
    return;
  }

  const apiUrl = form.dataset.liveSearchUrl || "";
  const indexAction = form.getAttribute("action") || "index.php";
  const MIN_CHARS = 2;
  const DEBOUNCE_MS = 220;
  const LIMIT = 8;

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

  function showMessage(message) {
    dropdown.hidden = false;
    dropdown.innerHTML = '<div class="live-search-empty">' + escapeHtml(message) + "</div>";
    setExpanded(true);
  }

  function renderItems(items) {
    if (!Array.isArray(items) || items.length === 0) {
      showMessage("Không có gợi ý phù hợp");
      return;
    }

    const html = items
      .map((item) => {
        const id = encodeURIComponent(item.id || "");
        const currentKeyword = input.value.trim();
        const keywordParam = currentKeyword ? "&q=" + encodeURIComponent(currentKeyword) : "";
        const detailUrl = indexAction + "?r=chitiet&id=" + id + keywordParam;
        const name = escapeHtml(item.ten_san_pham || "");
        const brand = escapeHtml(item.thuong_hieu || "");
        const price = escapeHtml(formatPrice(item.gia_ban));
        const img = escapeHtml(getPrimaryImage(item.link_hinh_anh));

        return (
          '<a class="live-search-item" href="' +
          detailUrl +
          '" role="option">' +
          '<img class="live-search-thumb" src="' +
          img +
          '" alt="' +
          name +
          '" loading="lazy" referrerpolicy="no-referrer" onerror="this.src=\'https://via.placeholder.com/96x96?text=No+Image\';">' +
          '<span class="live-search-meta">' +
          '<span class="live-search-name">' +
          name +
          "</span>" +
          '<span class="live-search-sub">' +
          brand +
          "</span>" +
          '<span class="live-search-price">' +
          price +
          "</span>" +
          "</span>" +
          "</a>"
        );
      })
      .join("");

    dropdown.hidden = false;
    dropdown.innerHTML = html;
    setExpanded(true);
  }

  async function fetchSuggestions(query) {
    if (!apiUrl) {
      return;
    }

    if (abortController) {
      abortController.abort();
    }
    abortController = new AbortController();

    const params = new URLSearchParams({
      q: query,
      limit: String(LIMIT)
    });

    try {
      const response = await fetch(apiUrl + "&" + params.toString(), {
        method: "GET",
        headers: {
          Accept: "application/json"
        },
        signal: abortController.signal
      });

      if (!response.ok) {
        throw new Error("Request failed");
      }

      const data = await response.json();
      if (!data || data.ok !== true) {
        throw new Error("Invalid response");
      }

      renderItems(data.items || []);
    } catch (error) {
      if (error.name === "AbortError") {
        return;
      }
      showMessage("Không thể tải gợi ý. Vui lòng thử lại.");
    }
  }

  input.addEventListener("input", function () {
    const value = input.value.trim();

    if (value.length < MIN_CHARS) {
      if (abortController) {
        abortController.abort();
      }
      hideDropdown();
      return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () {
      fetchSuggestions(value);
    }, DEBOUNCE_MS);
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
