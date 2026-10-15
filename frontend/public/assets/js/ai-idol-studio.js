(() => {
    'use strict';
    const root = document.getElementById('idolStudio');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const listEl = $('campaignList');
    const detailEl = $('campaignDetail');
    const createButton = $('createCampaign');
    const agentListEl = $('agentRunList');
    const agentDetailEl = $('agentRunDetail');
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const formatDate = value => value ? new Date(value).toLocaleString('vi-VN', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : 'Chưa có lịch';
    const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd').toLocaleLowerCase('vi');
    const selectedProducts = () => [...root.querySelectorAll('.product-check:checked')].map(item => item.value);
    const isVideoFile = file => Boolean(file && (file.type.startsWith('video/') || /\.(mp4|webm)$/i.test(file.name)));
    const isSyna = () => $('characterMode').value === 'syna_3d';
    const statusNames = {DRAFT:'Bản nháp',QUEUED:'Đang chờ',PENDING:'Đang chờ',GENERATING:'Đang tạo',PROCESSING:'Đang xử lý',AWAITING_APPROVAL:'Chờ duyệt',READY:'Sẵn sàng',SCHEDULED:'Đã lên lịch',STARTING:'Sắp lên sóng',LIVE:'Đang phát',COMPLETED:'Hoàn tất',VIDEOS_READY:'Video đã tạo xong',NEEDS_ATTENTION:'Cần kiểm tra',PARTIAL_FAILURE:'Lỗi một phần',FAILED:'Bị lỗi',CANCELLED:'Đã hủy'};
    Object.assign(statusNames, {WAITING_RENDERER:'Chờ Syna', ASSEMBLING:'Đang ghép video', VALIDATING:'Đang kiểm tra', REJECTED_BY_VALIDATOR:'Bị từ chối kiểm duyệt'});
    const statusName = value => statusNames[value] || String(value || 'Đang chờ');
    const badge = (value, label) => `<span class="status ${escapeHtml(value)}">${escapeHtml(label || statusName(value))}</span>`;
    const LOCAL_PLATFORMS = ['internal', 'preview'];
    const isInternalOnly = platforms => Array.isArray(platforms) && platforms.length > 0 && platforms.every(value => LOCAL_PLATFORMS.includes(value));
    // A LIVE internal-only programme is viewed inside the Studio, not on an outside platform.
    const liveBadge = (status, platforms) => status === 'LIVE' && isInternalOnly(platforms) ? badge(status, 'Đang phát nội bộ') : badge(status);
    const platformName = value => ({internal:'Nội bộ',youtube:'YouTube',facebook:'Facebook'}[value] || value);
    const {approvalWarning} = window.AiIdolApproval; // ai-idol-approval.js, loaded before this file
    const approvalAlertMarkup = (warning, compact) => warning ? (compact
        ? `<span class="campaign-alert is-${warning.level}"><i class="bi bi-alarm" aria-hidden="true"></i>${escapeHtml(warning.short)}</span>`
        : `<div class="approval-alert is-${warning.level}"><i class="bi bi-alarm" aria-hidden="true"></i><p><strong>${escapeHtml(warning.short)}</strong>${escapeHtml(warning.text)}</p></div>`) : '';
    const productNames = new Map([...root.querySelectorAll('.product-check')].map(input => [input.value, input.dataset.name]));
    const scriptDrafts = new Map();
    const approvingJobs = new Set();
    let activeCampaignId = null;
    let activeDetailSignature = '';
    let listSignature = '';
    let campaigns = [];
    let campaignFilter = 'all';
    let currentStep = 1;
    let avatarMediaValid = false;
    let avatarObjectUrl = '';
    let backgroundObjectUrl = '';
    let refreshBusy = false;
    let pollBusy = false;
    let detailRequest = 0;
    let agentRuns = [];
    let activeAgentRunId = null;
    let agentRefreshBusy = false;
    let previousAgentPrompt = '';
    let agentDetailSignature = '';
    // Values typed into the confirmation boxes survive a re-render of the same run.
    let agentDraft = {runId: '', code: '', schedule: ''};

    root.querySelectorAll('[data-agent-prompt]').forEach(button => {
        button.addEventListener('click', () => {
            previousAgentPrompt = $('agentBrief').value;
            $('agentBrief').value = button.dataset.agentPrompt;
            $('undoAgentPrompt').hidden = false;
            $('agentBrief').focus();
            agentNotify('Đã điền lệnh mẫu. Bạn có thể sửa số lượng, điều kiện và lịch trước khi chạy.', true);
        });
    });
    $('undoAgentPrompt').addEventListener('click', () => {
        $('agentBrief').value = previousAgentPrompt;
        $('undoAgentPrompt').hidden = true;
        $('agentBrief').focus();
    });

    function notify(message, success = false) {
        const target = $('formMessage');
        target.textContent = message;
        target.className = `studio-message${success ? ' success' : ''}`;
        target.hidden = !message;
    }

    function agentNotify(message, success = false) {
        const target = $('agentMessage');
        target.textContent = message;
        target.className = `studio-message${success ? ' success' : ''}`;
        target.hidden = !message;
    }

    const idolCatalog = window.__IDOL_CATALOG__ || {};
    const agentCategoryOptions = {
        face: {
            label: 'Chăm Sóc Da Mặt',
            count: idolCatalog.face?.count || 2473,
            children: {}
        },
        makeup: {label: 'Trang Điểm', count: 0, children: {}},
        body: {label: 'Chăm Sóc Cơ Thể', count: 0, children: {}},
        hair: {label: 'Chăm Sóc Tóc', count: 0, children: {}}
    };

    if (idolCatalog.face && idolCatalog.face.children && Object.keys(idolCatalog.face.children).length > 0) {
        Object.entries(idolCatalog.face.children).forEach(([id, cat]) => {
            const childrenObj = {};
            if (cat.children) {
                Object.entries(cat.children).forEach(([subId, subCat]) => {
                    childrenObj[subId] = {
                        id: subId,
                        label: subCat.label,
                        phrase: subCat.phrase || subCat.label.toLocaleLowerCase('vi'),
                        count: Number(subCat.count || 0)
                    };
                });
            }
            agentCategoryOptions.face.children[id] = {
                id: id,
                label: cat.label,
                phrase: cat.phrase || ('sản phẩm ' + cat.label.toLocaleLowerCase('vi')),
                count: Number(cat.count || 0),
                children: childrenObj
            };
        });
    } else {
        agentCategoryOptions.face.children = {
            cleansing: {label: 'Làm Sạch Da', count: 613, phrase: 'sản phẩm làm sạch da', children: {cleanser: {label: 'sữa rửa mặt', count: 285}, remover: {label: 'nước tẩy trang', count: 195}, exfoliant: {label: 'tẩy tế bào chết', count: 39}}},
            hydration: {label: 'Dưỡng Ẩm & Phục Hồi', count: 313, phrase: 'sản phẩm dưỡng ẩm và phục hồi da', children: {moisturizer: {label: 'kem dưỡng ẩm', count: 215}}},
            serum: {label: 'Serum / Đặc Trị', count: 275, phrase: 'serum', children: {retinol: {label: 'serum retinol', count: 207}, niacinamide: {label: 'serum niacinamide', count: 207}, vitaminC: {label: 'serum vitamin C', count: 207}}},
            sunscreen: {label: 'Chống Nắng', count: 231, phrase: 'kem chống nắng', children: {face_sunscreen: {label: 'kem chống nắng', count: 231}, body_sunscreen: {label: 'kem chống nắng body', count: 0}}},
            mask: {label: 'Mặt Nạ', count: 622, phrase: 'mặt nạ chăm sóc da', children: {sheet: {label: 'mặt nạ giấy', count: 549}, wash_off: {label: 'mặt nạ rửa', count: 50}}},
            eye: {label: 'Chăm Sóc Mắt', count: 27, phrase: 'sản phẩm chăm sóc mắt', children: {eye_cream: {label: 'kem mắt', count: 16}}}
        };
    }

    const agentCategory1 = $('agentCategoryLevel1');
    const agentCategory2 = $('agentCategoryLevel2');
    const agentCategory3 = $('agentCategoryLevel3');
    const agentPromptPreview = $('agentPromptPreview');
    const agentPromptSelectionCount = $('agentPromptSelectionCount');
    const useAgentBuilderPrompt = $('useAgentBuilderPrompt');
    const AGENT_BRIEF_MIN_LENGTH = 12;

    const clearSelect = (select, placeholder) => {
        select.replaceChildren(new Option(placeholder, ''));
        select.value = '';
        select.disabled = true;
    };
    const fillCategorySelect = (select, options, placeholder) => {
        select.replaceChildren(new Option(placeholder, ''));
        Object.entries(options || {}).forEach(([value, option]) => {
            const countStr = (option && typeof option.count === 'number') ? ` ( có ${option.count.toLocaleString('vi-VN')} sản phẩm )` : '';
            const text = typeof option === 'string' ? option : `${option.label || option.name || value}${countStr}`;
            select.add(new Option(text, value));
        });
        select.disabled = !Object.keys(options || {}).length;
    };
    function updateAgentCategoryOptions() {
        const level1 = agentCategoryOptions[agentCategory1.value];
        fillCategorySelect(agentCategory2, level1?.children, level1 ? 'Chọn danh mục' : 'Chọn nhóm trước');
        clearSelect(agentCategory3, 'Chọn danh mục trước');
        updateAgentPromptPreview();
        if (typeof triggerLiveProductsSearch === 'function') triggerLiveProductsSearch();
    }
    function updateAgentSubcategoryOptions() {
        const level1 = agentCategoryOptions[agentCategory1.value];
        const level2 = level1?.children?.[agentCategory2.value];
        fillCategorySelect(agentCategory3, level2?.children, level2 ? 'Chọn phân loại (không bắt buộc)' : 'Chọn danh mục trước');
        updateAgentPromptPreview();
        if (typeof triggerLiveProductsSearch === 'function') triggerLiveProductsSearch();
    }
    function localAgentDate(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        const pad = number => String(number).padStart(2, '0');
        return `lúc ${date.getHours()}h${pad(date.getMinutes())} ngày ${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
    }
    function buildAgentPrompt() {
        const level1 = agentCategoryOptions[agentCategory1.value];
        const level2 = level1?.children?.[agentCategory2.value];
        const categoryPhrase = level2 ? (agentCategoryOptions[agentCategory1.value]?.children?.[agentCategory2.value]?.children?.[agentCategory3.value] || level2.phrase) : (level1 ? 'sản phẩm ' + level1.label.toLocaleLowerCase('vi') : 'sản phẩm');
        const outOfStock = root.querySelector('input[name="agentStock"]:checked')?.value === 'out_of_stock';
        $('agentEnoughStock').disabled = outOfStock;
        if (outOfStock) $('agentEnoughStock').checked = false;
        const fragments = [];
        const inventory = [...root.querySelectorAll('input[name="agentExpiry"]:checked, input[name="agentStock"]:checked')].map(input => input.dataset.fragment).filter(Boolean);
        const sales = [...root.querySelectorAll('input[name="agentSales"]:checked')].map(input => input.dataset.fragment).filter(Boolean);
        const styles = [...root.querySelectorAll('input[name="agentStyle"]:checked')].map(input => input.value);
        const quantity = $('agentQuantity').value;
        const brand = $('agentBrand').value.trim();
        const warehouseMode = $('agentWarehouseMode').value;
        const warehouse = $('agentWarehouse').value.trim();
        const purpose = selectedAgentPurpose();
        const liveEnabled = purpose !== 'product_video';

        if (inventory.length) fragments.push(...inventory);
        if (sales.length) fragments.push(...sales);
        if (warehouseMode) fragments.push(`${warehouseMode === 'batch' ? 'chỉ lấy từ lô hàng' : 'chỉ lấy từ kho'} ${warehouse || (warehouseMode === 'batch' ? '[chưa nhập mã lô]' : '[chưa nhập mã kho]')}`);
        if (brand) fragments.push(`ưu tiên nhiều sản phẩm của thương hiệu ${brand}`);
        if ($('agentBrandCap').checked) fragments.push('mỗi thương hiệu tối đa 2 sản phẩm');
        if ($('agentEnoughStock').checked) fragments.push('ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)');

        let prompt = `${quantity ? `Lọc ${quantity}` : 'Tìm'} ${categoryPhrase}`;
        if (fragments.length) prompt += ` ${fragments.join(', ')}`;
        if (liveEnabled) {
            const liveAt = localAgentDate($('agentLiveAt').value);
            const duration = $('agentLiveDuration').value;
            const channel = $('agentLiveChannel').value;
            prompt += `, tạo livestream${liveAt ? ` ${liveAt}` : ''}, thời lượng ${duration} phút, ${channel}`;
            prompt += purpose === 'live_only'
                ? ', chỉ livestream'
                : '; sau khi phát xong đăng phần giới thiệu không có giá lên trang sản phẩm';
        } else {
            prompt += ', tạo video sản phẩm dùng lâu dài, không nhắc giá và khuyến mãi';
        }
        if (styles.length) prompt += `, theo phong cách ${styles.join(', ')}`;
        prompt += '.';
        if (prompt.length < AGENT_BRIEF_MIN_LENGTH) prompt = prompt.replace(/^Tìm /, 'Tìm sản phẩm ');

        const choiceCount = inventory.length + sales.length + styles.length + (level1 ? 1 : 0) + (level2 ? 1 : 0) + (agentCategory3.value ? 1 : 0) + (quantity ? 1 : 0) + (brand ? 1 : 0) + (warehouseMode ? 1 : 0) + ($('agentBrandCap').checked ? 1 : 0) + ($('agentEnoughStock').checked ? 1 : 0) + (liveEnabled ? 1 : 0);
        agentPromptPreview.value = choiceCount ? prompt : '';
        agentPromptPreview.placeholder = choiceCount ? '' : 'Chọn một vài điều kiện ở trên để xem prompt hoàn chỉnh...';
        agentPromptSelectionCount.textContent = `${choiceCount} lựa chọn`;
        const incompleteScope = Boolean(warehouseMode && !warehouse);
        const tooShort = prompt.length < AGENT_BRIEF_MIN_LENGTH;
        useAgentBuilderPrompt.disabled = !choiceCount || incompleteScope || tooShort;
        $('agentPromptHint').textContent = !choiceCount ? 'Chọn ít nhất một điều kiện để ghép prompt.' : incompleteScope ? 'Nhập mã kho hoặc mã lô trước khi dùng prompt.' : tooShort ? `Prompt cần ít nhất ${AGENT_BRIEF_MIN_LENGTH} ký tự.` : 'Prompt chỉ được điền vào ô lệnh khi bạn bấm nút bên dưới.';
        return {prompt, choiceCount, incompleteScope, tooShort};
    }
    function updateAgentPromptPreview() { buildAgentPrompt(); }
    function setAgentLiveMin() {
        const min = new Date(Date.now() + 60 * 60 * 1000);
        const pad = number => String(number).padStart(2, '0');
        $('agentLiveAt').min = `${min.getFullYear()}-${pad(min.getMonth() + 1)}-${pad(min.getDate())}T${pad(min.getHours())}:${pad(min.getMinutes())}`;
    }
    agentCategory1.addEventListener('change', updateAgentCategoryOptions);
    agentCategory2.addEventListener('change', updateAgentSubcategoryOptions);
    root.querySelectorAll('.agent-builder input, .agent-builder select').forEach(input => input.addEventListener('change', () => {
        updateAgentPromptPreview();
        if (typeof triggerLiveProductsSearch === 'function') triggerLiveProductsSearch();
    }));
    $('agentBrand').addEventListener('input', () => {
        updateAgentPromptPreview();
        if (typeof triggerLiveProductsSearch === 'function') triggerLiveProductsSearch(250);
    });
    $('agentWarehouse').addEventListener('input', updateAgentPromptPreview);
    $('agentLiveAt').addEventListener('input', updateAgentPromptPreview);
    $('agentWarehouseMode').addEventListener('change', () => {
        const mode = $('agentWarehouseMode').value;
        $('agentWarehouseValue').hidden = !mode;
        $('agentWarehouseLabel').textContent = mode === 'batch' ? 'Mã lô hàng' : 'Tên hoặc mã kho';
        $('agentWarehouse').placeholder = mode === 'batch' ? 'Ví dụ: LOT-2026-09-A' : 'Ví dụ: KHO-HN-01';
        updateAgentPromptPreview();
    });
    function selectedAgentPurpose() {
        return root.querySelector('input[name="agentPurpose"]:checked')?.value || 'product_video';
    }
    // Product videos never speak prices, so the price-led narration styles are switched off.
    const PRICE_STYLES = ['tập trung chốt sale', 'nhấn mạnh giá và ưu đãi'];
    function applyAgentPurpose() {
        const evergreen = selectedAgentPurpose() === 'product_video';
        root.querySelectorAll('input[name="agentStyle"]').forEach(input => {
            if (!PRICE_STYLES.includes(input.value)) return;
            input.disabled = evergreen;
            if (evergreen) input.checked = false;
            input.closest('label')?.classList.toggle('is-disabled', evergreen);
        });
        $('agentPurposeNote').hidden = !evergreen;
    }
    root.querySelectorAll('input[name="agentPurpose"]').forEach(input => input.addEventListener('change', () => {
        const live = selectedAgentPurpose() !== 'product_video';
        $('agentLiveFields').hidden = !live;
        applyAgentPurpose();
        if (live && !$('agentLiveAt').value) {
            const suggested = new Date(Date.now() + 60 * 60 * 1000);
            const pad = number => String(number).padStart(2, '0');
            $('agentLiveAt').value = `${suggested.getFullYear()}-${pad(suggested.getMonth() + 1)}-${pad(suggested.getDate())}T${pad(suggested.getHours())}:${pad(suggested.getMinutes())}`;
        }
        updateAgentPromptPreview();
    }));
    setAgentLiveMin();
    applyAgentPurpose();
    useAgentBuilderPrompt.addEventListener('click', () => {
        const result = buildAgentPrompt();
        if (!result.choiceCount || result.tooShort) return;
        if (result.incompleteScope) {
            agentNotify('Hãy nhập tên/mã kho hoặc mã lô trước khi dùng prompt.', false);
            return $('agentWarehouse').focus();
        }
        previousAgentPrompt = $('agentBrief').value;
        $('agentBrief').value = result.prompt;
        $('undoAgentPrompt').hidden = false;
        $('agentBrief').focus();
        agentNotify('Đã ghép prompt từ các lựa chọn. Bạn vẫn có thể sửa câu lệnh trước khi chạy.', true);
    });

    // --- LIVE SEARCH & REALTIME INVENTORY SYNC ---
    const liveProductsContainer = $('liveProductsContainer');
    const liveProductCountBadge = $('liveProductCountBadge');
    const liveSelectedCounter = $('liveSelectedCounter');
    const liveKeywordInput = $('liveProductFilterKeyword');
    const liveSelectAllBtn = $('liveSelectAllBtn');
    const liveDeselectAllBtn = $('liveDeselectAllBtn');
    let liveSearchAbortController = null;
    let liveSearchDebounceTimer = null;
    let liveSelectedProductIds = new Set();
    let currentLiveSyncHash = '';
    let liveSearchBusy = false;

    function renderLiveStockBadge(qty) {
        const count = Number(qty || 0);
        if (count > 100) {
            return `<span class="live-stock-badge in-stock" data-stock="${count}" title="Tồn kho dồi dào (>100)"><i class="bi bi-box-seam" aria-hidden="true"></i> Tồn kho: <strong>${count.toLocaleString('vi-VN')}</strong></span>`;
        }
        if (count > 10) {
            return `<span class="live-stock-badge normal-stock" data-stock="${count}" title="Còn hàng trong kho"><i class="bi bi-check2" aria-hidden="true"></i> Tồn kho: <strong>${count.toLocaleString('vi-VN')}</strong></span>`;
        }
        if (count > 0) {
            return `<span class="live-stock-badge low-stock" data-stock="${count}" title="Sắp hết hàng trong kho (≤10)"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Sắp hết: <strong>${count}</strong></span>`;
        }
        return `<span class="live-stock-badge out-of-stock" data-stock="0" title="Đã hết hàng trong kho"><i class="bi bi-x-circle" aria-hidden="true"></i> Hết hàng (0)</span>`;
    }

    function renderLiveProductsList(products) {
        if (!liveProductsContainer) return;
        if (!products || !products.length) {
            liveProductsContainer.innerHTML = `
                <div class="empty compact">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <p>Không có sản phẩm nào phù hợp với bộ lọc hiện tại.<br>Thử chọn danh mục hoặc tiêu chí kho/hạn dùng khác.</p>
                </div>`;
            return;
        }

        const itemsHtml = products.map(p => {
            const pid = String(p.id || p.ma_san_pham);
            const isChecked = liveSelectedProductIds.has(pid);
            const priceFormatted = Number(p.gia_ban || 0).toLocaleString('vi-VN');
            const soldFormatted = Number(p.so_luong_da_ban || 0).toLocaleString('vi-VN');
            const catName = p.danh_muc_day_du ? p.danh_muc_day_du.split('->').pop().trim() : '';

            return `
                <label class="live-product-card${isChecked ? ' is-selected' : ''}" data-product-id="${escapeHtml(pid)}">
                    <input type="checkbox" class="live-product-checkbox" value="${escapeHtml(pid)}" data-name="${escapeHtml(p.ten_san_pham)}" data-stock="${Number(p.so_luong_ton_kho || 0)}"${isChecked ? ' checked' : ''}>
                    <div class="live-product-thumb">
                        <img src="${escapeHtml(p.image)}" alt="" loading="lazy">
                    </div>
                    <div class="live-product-info">
                        <div class="live-product-title" title="${escapeHtml(p.ten_san_pham)}">${escapeHtml(p.ten_san_pham)}</div>
                        <div class="live-product-meta">
                            <span class="live-product-sku">Mã: #${escapeHtml(p.ma_san_pham)}</span>
                            ${catName ? `<span class="live-product-cat">${escapeHtml(catName)}</span>` : ''}
                        </div>
                        <div class="live-product-pricing">
                            <span class="live-product-price">${priceFormatted} đ</span>
                            <span class="live-product-sold"><i class="bi bi-bag-check" aria-hidden="true"></i> Đã bán: ${soldFormatted}</span>
                        </div>
                    </div>
                    <div class="live-product-stock-wrap">
                        ${renderLiveStockBadge(p.so_luong_ton_kho)}
                    </div>
                </label>`;
        }).join('');

        liveProductsContainer.innerHTML = itemsHtml;
    }

    async function fetchLiveProducts(isBackgroundSync = false) {
        if (!liveProductsContainer) return;
        if (liveSearchBusy && isBackgroundSync) return;

        const catL1 = agentCategory1 ? agentCategory1.value : '';
        const catL2 = agentCategory2 ? agentCategory2.value : '';
        const catL3 = agentCategory3 ? agentCategory3.value : '';
        const expiry = root.querySelector('input[name="agentExpiry"]:checked')?.value || '';
        const stock = root.querySelector('input[name="agentStock"]:checked')?.value || '';
        const inventory = [expiry, stock].filter(Boolean).join(',');
        const sales = root.querySelector('input[name="agentSales"]:checked')?.value || '';
        const brand = $('agentBrand') ? $('agentBrand').value.trim() : '';
        const q = liveKeywordInput ? liveKeywordInput.value.trim() : '';

        const params = new URLSearchParams({
            r: 'admin_ai_idol_products',
            cat_l1: catL1,
            cat_l2: catL2,
            cat_l3: catL3,
            inventory: inventory,
            sales: sales,
            brand: brand,
            q: q,
            limit: '80'
        });

        if (!isBackgroundSync) {
            if (liveSearchAbortController) liveSearchAbortController.abort();
            liveSearchAbortController = new AbortController();
            if (liveProductCountBadge) liveProductCountBadge.textContent = 'Đang tìm kiếm…';
        }

        liveSearchBusy = true;
        try {
            const url = 'index.php?' + params.toString();
            const signal = isBackgroundSync ? undefined : liveSearchAbortController.signal;
            const res = await fetch(url, { signal });
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.ok) return;

            if (isBackgroundSync) {
                if (data.sync_hash && data.sync_hash === currentLiveSyncHash) {
                    return;
                }
                currentLiveSyncHash = data.sync_hash || '';
                (data.products || []).forEach(p => {
                    const pid = String(p.id || p.ma_san_pham);
                    const card = liveProductsContainer.querySelector(`[data-product-id="${pid}"]`);
                    if (card) {
                        const stockWrap = card.querySelector('.live-product-stock-wrap');
                        const oldBadge = stockWrap?.querySelector('.live-stock-badge');
                        const oldQty = oldBadge?.dataset.stock;
                        if (String(oldQty) !== String(p.so_luong_ton_kho)) {
                            stockWrap.innerHTML = renderLiveStockBadge(p.so_luong_ton_kho);
                            const newBadge = stockWrap.querySelector('.live-stock-badge');
                            if (newBadge) {
                                newBadge.classList.add('has-pulsed');
                                setTimeout(() => newBadge.classList.remove('has-pulsed'), 700);
                            }
                        }
                    }
                });
                if (liveProductCountBadge && typeof data.total === 'number') {
                    liveProductCountBadge.textContent = `${data.total.toLocaleString('vi-VN')} sản phẩm phù hợp`;
                }
                return;
            }

            currentLiveSyncHash = data.sync_hash || '';
            const total = Number(data.total || 0);
            if (liveProductCountBadge) {
                liveProductCountBadge.textContent = `${total.toLocaleString('vi-VN')} sản phẩm phù hợp`;
            }
            renderLiveProductsList(data.products || []);
            updateLiveSelectedUi();
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (!isBackgroundSync && liveProductCountBadge) {
                liveProductCountBadge.textContent = 'Lỗi tìm kiếm';
            }
        } finally {
            liveSearchBusy = false;
        }
    }

    function triggerLiveProductsSearch(debounceMs = 120) {
        clearTimeout(liveSearchDebounceTimer);
        liveSearchDebounceTimer = setTimeout(() => {
            fetchLiveProducts(false);
        }, debounceMs);
    }

    function updateLiveSelectedUi() {
        const count = liveSelectedProductIds.size;
        if (liveSelectedCounter) {
            liveSelectedCounter.hidden = !count;
            liveSelectedCounter.textContent = `${count} đã chọn`;
        }
        if (liveSelectAllBtn) liveSelectAllBtn.hidden = count > 0;
        if (liveDeselectAllBtn) liveDeselectAllBtn.hidden = count === 0;
    }

    if (liveProductsContainer) {
        liveProductsContainer.addEventListener('change', event => {
            const checkbox = event.target.closest('.live-product-checkbox');
            if (!checkbox) return;
            const pid = checkbox.value;
            const card = checkbox.closest('.live-product-card');
            if (checkbox.checked) {
                liveSelectedProductIds.add(pid);
                if (card) card.classList.add('is-selected');
            } else {
                liveSelectedProductIds.delete(pid);
                if (card) card.classList.remove('is-selected');
            }
            updateLiveSelectedUi();
        });
    }

    if (liveSelectAllBtn) {
        liveSelectAllBtn.addEventListener('click', () => {
            root.querySelectorAll('.live-product-checkbox').forEach(cb => {
                cb.checked = true;
                liveSelectedProductIds.add(cb.value);
                cb.closest('.live-product-card')?.classList.add('is-selected');
            });
            updateLiveSelectedUi();
        });
    }

    if (liveDeselectAllBtn) {
        liveDeselectAllBtn.addEventListener('click', () => {
            root.querySelectorAll('.live-product-checkbox').forEach(cb => {
                cb.checked = false;
                cb.closest('.live-product-card')?.classList.remove('is-selected');
            });
            liveSelectedProductIds.clear();
            updateLiveSelectedUi();
        });
    }

    if (liveKeywordInput) {
        liveKeywordInput.addEventListener('input', () => triggerLiveProductsSearch(250));
    }

    triggerLiveProductsSearch(50);

    function showStudioFlow(flow) {
        const isAgent = flow === 'agent';
        $('manualFlow').hidden = isAgent;
        $('agentFlow').hidden = !isAgent;
        root.querySelectorAll('[data-studio-flow]').forEach(button => {
            const active = button.dataset.studioFlow === flow;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });
        if (isAgent) refreshAgentRuns();
    }
    root.querySelectorAll('[data-studio-flow]').forEach(button => {
        button.addEventListener('click', () => showStudioFlow(button.dataset.studioFlow));
    });

    function showStep(step, focus = false) {
        currentStep = Math.min(3, Math.max(1, step));
        root.querySelectorAll('[data-step]').forEach(button => {
            const active = Number(button.dataset.step) === currentStep;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
        });
        [1, 2, 3].forEach(number => { $(`stepPanel${number}`).hidden = number !== currentStep; });
        $('stepCounter').textContent = `Bước ${currentStep} / 3`;
        $('previousStep').hidden = currentStep === 1;
        $('footerNote').hidden = currentStep !== 1;
        $('nextStep').hidden = currentStep === 3;
        createButton.hidden = currentStep !== 3;
        $('nextStep').innerHTML = `${currentStep === 1 ? 'Chọn không gian' : 'Đặt lịch phát'} <i class="bi bi-arrow-right" aria-hidden="true"></i>`;
        if (currentStep !== 2) $('avatarVideoPreview').pause();
        if (focus) $(`stepTab${currentStep}`).focus();
    }

    root.querySelectorAll('[data-step]').forEach(button => {
        button.addEventListener('click', () => showStep(Number(button.dataset.step)));
        button.addEventListener('keydown', event => {
            const steps = {ArrowRight: currentStep % 3 + 1, ArrowLeft: (currentStep + 1) % 3 + 1, Home: 1, End: 3};
            if (steps[event.key]) { event.preventDefault(); showStep(steps[event.key], true); }
        });
    });
    $('previousStep').addEventListener('click', () => showStep(currentStep - 1, true));
    $('nextStep').addEventListener('click', () => {
        if (currentStep === 1 && !selectedProducts().length) {
            notify('Chọn ít nhất một sản phẩm để tiếp tục.');
            return $('productSearch').focus();
        }
        if (currentStep === 2 && !isSyna() && !avatarMediaValid) {
            notify('Chọn ảnh hoặc video nhân vật hợp lệ để tiếp tục.');
            return $('avatarFile').focus();
        }
        notify('');
        showStep(currentStep + 1, true);
    });

    function updateSelectButton() {
        const visible = [...root.querySelectorAll('.product-item:not([hidden]) .product-check')];
        $('selectVisible').disabled = !visible.length;
        $('selectVisible').textContent = visible.length && visible.every(input => input.checked) ? 'Bỏ chọn' : 'Chọn tất cả';
    }
    function updateSelection() {
        const selected = [...root.querySelectorAll('.product-check:checked')];
        const count = selected.length;
        const estimated = Math.max(5, count * 8);
        $('selectionCount').textContent = `${count} đã chọn`;
        $('selectionNote').textContent = count ? `${count} sản phẩm · nên chừa ít nhất ${estimated} phút để tạo video.` : 'Chọn sản phẩm bạn muốn giới thiệu trong buổi live.';
        $('summaryProducts').textContent = count ? `${count} sản phẩm trong chương trình` : 'Chưa chọn sản phẩm';
        $('summaryPreparation').textContent = count ? `Dự kiến cần ít nhất ${estimated} phút chuẩn bị video, chưa tính thời gian bạn duyệt.` : 'Quay lại Nội dung để chọn sản phẩm.';
        $('selectedChips').hidden = !count;
        $('selectedChips').innerHTML = selected.map(input => `<button type="button" class="selection-chip" data-remove-product="${escapeHtml(input.value)}" aria-label="Bỏ chọn ${escapeHtml(input.dataset.name)}"><span>${escapeHtml(input.dataset.name)}</span><i class="bi bi-x" aria-hidden="true"></i></button>`).join('');
        updateSelectButton();
    }
    $('productSearch').addEventListener('input', event => {
        const term = normalize(event.target.value.trim());
        let visible = 0;
        root.querySelectorAll('.product-item').forEach(item => {
            item.hidden = Boolean(term && !normalize(item.dataset.search).includes(term));
            if (!item.hidden) visible++;
        });
        $('productEmpty').hidden = visible > 0;
        updateSelectButton();
    });
    $('productList').addEventListener('change', updateSelection);
    $('selectVisible').addEventListener('click', () => {
        const visible = [...root.querySelectorAll('.product-item:not([hidden]) .product-check')];
        const shouldCheck = visible.some(item => !item.checked);
        visible.forEach(item => { item.checked = shouldCheck; });
        updateSelection();
    });
    $('selectedChips').addEventListener('click', event => {
        const button = event.target.closest('[data-remove-product]');
        if (!button) return;
        [...root.querySelectorAll('.product-check')].find(input => input.value === button.dataset.removeProduct).checked = false;
        updateSelection();
    });
    root.querySelectorAll('.product-thumb img').forEach(img => {
        img.addEventListener('error', () => { img.hidden = true; });
    });

    const setAvatarNote = (mode, message) => {
        $('avatarModeNote').className = `avatar-mode-note${mode ? ` ${mode}` : ''}`;
        $('avatarModeNote').textContent = message;
    };
    $('avatarFile').addEventListener('change', () => {
        const input = $('avatarFile');
        const image = $('avatarImagePreview');
        const video = $('avatarVideoPreview');
        avatarMediaValid = false;
        image.onload = image.onerror = video.onloadedmetadata = video.onerror = null;
        video.pause();
        image.removeAttribute('src'); video.removeAttribute('src');
        image.classList.remove('has-image'); video.classList.remove('has-image'); video.load();
        if (avatarObjectUrl) URL.revokeObjectURL(avatarObjectUrl);
        avatarObjectUrl = '';
        const file = input.files?.[0];
        $('avatarFilename').textContent = file?.name || 'Chọn video hoặc ảnh';
        if (!file) return setAvatarNote('', 'Chọn nhân vật để xem trước tại đây.');
        const isVideo = isVideoFile(file);
        const fail = message => { input.value = ''; avatarMediaValid = false; setAvatarNote('photo', message); notify(message); };
        if (!/\.(png|jpe?g|webp|mp4|webm)$/i.test(file.name)) return fail('Nhân vật cần là ảnh PNG, JPG, WebP hoặc video MP4, WebM.');
        if (file.size > (isVideo ? 35 : 10) * 1024 * 1024) return fail(`Tệp nhân vật tối đa ${isVideo ? 35 : 10} MB.`);
        notify('');
        avatarObjectUrl = URL.createObjectURL(file);
        const currentUrl = avatarObjectUrl;
        if (!isVideo) {
            setAvatarNote('', 'Đang đọc ảnh nhân vật…');
            image.onload = () => {
                if (avatarObjectUrl !== currentUrl) return;
                avatarMediaValid = true;
                image.classList.add('has-image');
                setAvatarNote('photo', 'Ảnh tĩnh: miệng và đầu chuyển động nhẹ; vai, tay và cơ thể giữ nguyên.');
            };
            image.onerror = () => fail('Không đọc được ảnh này. Bạn hãy chọn ảnh khác.');
            image.src = currentUrl;
        } else {
            setAvatarNote('', 'Đang kiểm tra video nhân vật…');
            video.onloadedmetadata = () => {
                if (avatarObjectUrl !== currentUrl) return;
                if (!Number.isFinite(video.duration) || video.duration < 5 || video.duration > 30) return fail('Video nhân vật cần dài từ 5 đến 30 giây.');
                avatarMediaValid = true;
                video.classList.add('has-image');
                setAvatarNote('motion', 'Video: giữ chuyển động mắt, đầu, vai và tay có sẵn trong clip; tạo lại khẩu hình theo lời nói.');
                if (currentStep === 2) video.play().catch(() => {});
            };
            video.onerror = () => fail('Không đọc được video này. Thử video MP4 định dạng H.264.');
            video.src = currentUrl;
        }
    });
    $('backgroundFile').addEventListener('change', () => {
        const input = $('backgroundFile');
        const preview = $('backgroundPreview');
        preview.onload = preview.onerror = null;
        preview.removeAttribute('src'); preview.classList.remove('has-image');
        if (backgroundObjectUrl) URL.revokeObjectURL(backgroundObjectUrl);
        backgroundObjectUrl = '';
        const file = input.files?.[0];
        $('backgroundFilename').textContent = file?.name || 'Chọn ảnh nền · tùy chọn';
        if (!file) return;
        if (file.size > 10 * 1024 * 1024 || !/\.(png|jpe?g|webp)$/i.test(file.name)) {
            input.value = ''; return notify('Phông nền cần là ảnh PNG, JPG hoặc WebP, tối đa 10 MB.');
        }
        backgroundObjectUrl = URL.createObjectURL(file);
        preview.onload = () => preview.classList.add('has-image');
        preview.onerror = () => { input.value = ''; notify('Không đọc được phông nền. Bạn hãy chọn ảnh khác.'); };
        preview.src = backgroundObjectUrl;
    });

    function updateCharacterMode() {
        const syna = isSyna();
        $('customAvatarPicker').hidden = syna;
        $('avatarModeNote').hidden = syna;
        $('synaModeHelp').hidden = !syna;
        $('videoFormat').querySelector('option[value="9:16"]').disabled = syna;
        if (syna) { $('videoFormat').value = '16:9'; $('avatarVideoPreview').pause(); }
    }
    $('characterMode').addEventListener('change', updateCharacterMode);
    updateCharacterMode();

    async function api(url, options = {}) {
        const response = await fetch(url, options);
        const raw = await response.text();
        let data;
        try { data = raw ? JSON.parse(raw) : {}; }
        catch {
            throw new Error(response.status === 413 ? 'Tệp vượt giới hạn máy chủ. Video nhân vật tối đa 35 MB.' : `Máy chủ trả về phản hồi không hợp lệ (HTTP ${response.status}). Hãy kiểm tra phiên đăng nhập.`);
        }
        if (!response.ok || !data.ok) {
            const failure = new Error(data.message || `HTTP ${response.status}`);
            failure.status = response.status;
            throw failure;
        }
        return data;
    }

    const agentStatusNames = {
        RECEIVED:'Đã tiếp nhận', INTERPRETING:'Đang hiểu yêu cầu', CLASSIFYING_INTENT:'Đang phân loại ý định', INTENT_REVIEW:'Cần xác nhận ý định', RESOLVING_PRODUCTS:'Đang tìm sản phẩm',
        NEEDS_INPUT:'Cần bạn xác nhận', PLAN_READY:'Đã lập kế hoạch', CAMPAIGN_CREATED:'Đã tạo chương trình',
        NEEDS_ATTENTION:'Cần kiểm tra', CANCELLED:'Đã hủy', COMPLETED:'Hoàn tất', VIDEOS_READY:'Video đã tạo xong'
    };
    Object.assign(statusNames, {RESULTS_READY:'Đã tìm xong', DATA_UNAVAILABLE:'Thiếu dữ liệu', EMPTY_RESULTS:'Không có kết quả'});
    Object.assign(agentStatusNames, {QUERYING_PRODUCTS:'Đang lọc và kiểm tra số liệu', QUERY_RESULTS:'Kết quả truy vấn', SCHEDULE_REVIEW:'Cần sửa lịch phát', POLICY_GATE:'Cần chọn nơi phát', CAMPAIGN_CANCELLED:'Chương trình đã hủy'});
    const agentStatusName = value => agentStatusNames[value] || statusName(value);
    const toDatetimeLocal = value => {
        const date = new Date(value);
        if (!value || !Number.isFinite(date.getTime())) return '';
        const pad = number => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };
    const unresolvedReasonNames = {NOT_FOUND:'không tìm thấy trong catalog đang bán', INACTIVE:'đang ẩn hoặc ngừng bán'};

    function renderAgentRuns() {
        if (!agentRuns.length) {
            agentListEl.innerHTML = '<div class="empty"><i class="bi bi-stars" aria-hidden="true"></i><p>Chưa có yêu cầu Agent nào.</p></div>';
            agentDetailEl.hidden = true;
            return;
        }
        if (!activeAgentRunId || !agentRuns.some(item => item._id === activeAgentRunId)) activeAgentRunId = agentRuns[0]._id;
        agentListEl.innerHTML = agentRuns.map(item => { const liveStatus = item.status_effective || item.campaign_status || item.status; return `<button type="button" class="agent-run ${item._id === activeAgentRunId ? 'is-active' : ''}" data-agent-run="${escapeHtml(item._id)}"><span class="agent-run-top"><span class="agent-run-brief">${escapeHtml(item.brief)}</span>${liveBadge(liveStatus, item.campaign_platforms)}</span><span class="campaign-meta">${escapeHtml(agentStatusName(item.campaign_status || item.current_stage || item.status))} · ${escapeHtml(formatDate(item.updated_at))}</span></button>`; }).join('');
        renderAgentDetail(agentRuns.find(item => item._id === activeAgentRunId));
    }

    function renderAgentDetail(run) {
        if (!run) { agentDetailEl.hidden = true; return; }
        const signature = JSON.stringify(run);
        if (!agentDetailEl.hidden && signature === agentDetailSignature) return;
        agentDetailSignature = signature;
        const plan = run.plan || {};
        const resolved = plan.resolved_products || [];
        const candidates = plan.candidate_products || [];
        const warnings = plan.warnings || [];
        const intent = run.intent_classification || plan.intent_classification;
        const intentNames = {
            CREATE_CAMPAIGN:'Tạo livestream', PRODUCT_SEARCH:'Tìm sản phẩm', PREVIEW_CAMPAIGN:'Xem trước',
            REVISE_SCRIPT:'Sửa kịch bản', APPROVE_SCRIPT:'Duyệt kịch bản', RETRY_FAILED:'Tạo lại phần lỗi',
            CANCEL_CAMPAIGN:'Hủy campaign', STATUS_QUERY:'Xem trạng thái', UNKNOWN:'Chưa rõ',
        };
        const intentMarkup = intent ? `<div class="agent-intent"><span><i class="bi bi-bullseye"></i> Ý định nhận diện: <strong>${escapeHtml(intentNames[intent.intent] || intent.intent || 'Chưa rõ')}</strong></span><small>${Math.round(Number(intent.confidence || 0) * 100)}% · ${escapeHtml(intent.reason || '')}</small></div>` : '';
        const schedule = plan.scheduled_at ? `<div class="agent-stage"><i class="bi bi-calendar2-check"></i><span>Lịch đề xuất: ${escapeHtml(formatDate(plan.scheduled_at))}</span></div>` : '';
        const waiting = run.status === 'NEEDS_INPUT' && !run.campaign_id;
        const needs = Array.isArray(plan.needs) ? plan.needs : [];
        const unresolvedIds = plan.unresolved_ids || [];
        const unresolvedReasons = plan.unresolved_reasons || {};
        const confirmable = plan.confirmable_products?.length ? plan.confirmable_products : candidates;
        const needsProduct = needs.includes('product') || (!needs.length && candidates.length > 0);
        const needsSchedule = needs.includes('schedule');
        const canConfirmProduct = waiting && needsProduct && confirmable.length > 0;
        const productRow = (item, action = '') => `<div class="agent-product"><span><strong>${escapeHtml(item.name)}</strong><br>Mã ${escapeHtml(item.product_id)}${item.evidence ? renderQueryEvidence(item.evidence) : ` · độ khớp ${Math.round(Number(item.score || 0) * 100)}%`}</span>${action}</div>`;
        const confirmAction = item => `<button type="button" data-agent-confirm-product="${escapeHtml(item.product_id)}">Dùng sản phẩm này</button>`;
        const productRows = canConfirmProduct ? confirmable : resolved;
        const products = productRows.length ? productRows.map(item => productRow(item, canConfirmProduct ? confirmAction(item) : '')).join('') : '';
        const unresolvedMarkup = waiting && unresolvedIds.length ? `<ul class="agent-unresolved">${unresolvedIds.map(id => `<li><strong>Mã ${escapeHtml(id)}</strong>: ${escapeHtml(unresolvedReasonNames[unresolvedReasons[id]] || unresolvedReasonNames.NOT_FOUND)}</li>`).join('')}</ul>` : '';
        const shownWarnings = warnings.filter(message => !unresolvedIds.some(id => String(message).startsWith(`Không tìm thấy mã ${id} `) || message === `Mã ${id} đang ẩn/ngừng bán.`));
        const productInputMarkup = waiting && needsProduct ? `<div class="agent-input"><label for="agentProductCode">Mã sản phẩm</label><input id="agentProductCode" class="idol-input" data-agent-product-code type="text" maxlength="40" autocomplete="off" placeholder="Ví dụ: 93" value="${escapeHtml(agentDraft.runId === run._id ? agentDraft.code : '')}"><button type="button" class="idol-btn primary" data-agent-confirm-code>Dùng mã này</button></div>` : '';
        const scheduleDefault = agentDraft.runId === run._id && agentDraft.schedule ? agentDraft.schedule : toDatetimeLocal(plan.scheduled_at);
        const scheduleInputMarkup = waiting && needsSchedule ? `<div class="agent-input"><label for="agentScheduleAt">Lịch phát</label><input id="agentScheduleAt" class="idol-input" data-agent-schedule-input type="datetime-local" value="${escapeHtml(scheduleDefault)}"><button type="button" class="idol-btn primary" data-agent-confirm-schedule>Dùng lịch này</button></div>` : '';
        const query = plan.query_result;
        const missingLabels = {stock:'Số tồn kho', expiry_or_batch_quantity:'Ngày hết hạn / số lượng lô', sales_snapshot:'Snapshot thống kê đúng kỳ', lifetime_sales:'Số bán toàn kỳ', invalid_conversion_cohort:'Phiên xem/mua không hợp lệ', growth_baseline:'Kỳ trước chưa có cơ sở tăng trưởng', comparison_sample:'Nhóm so sánh chưa đủ mẫu', price:'Giá bán / giá gốc'};
        const queryMarkup = query ? `<section class="agent-query-summary"><strong>Điều kiện đã hiểu</strong><ul>${[...(query.query?.summary || []), ...(query.query?.assumptions || [])].map(text => `<li>${escapeHtml(text)}</li>`).join('')}</ul><p>Trả ${query.selected?.length || 0} / ${Number(query.requested_count || 0)} yêu cầu · ${Number(query.matched_count || 0)} kết quả phù hợp${query.complete ? '' : ' · dữ liệu chưa đầy đủ'}</p><small>Đối chiếu: ${escapeHtml(formatDate(query.checked_at))}</small>${Object.keys(query.missing_data || {}).length ? `<ul class="agent-data-gaps">${Object.entries(query.missing_data).map(([key, count]) => `<li>${escapeHtml(missingLabels[key] || key)}: ${Number(count)} trường hợp cần bổ sung</li>`).join('')}</ul>` : ''}</section>` : '';
        const queryActions = query && !run.campaign_id ? `<button type="button" class="idol-btn secondary" data-edit-agent-query>Sửa lệnh này</button>${resolved.length && query.complete ? '<button type="button" class="idol-btn primary" data-query-to-wizard>Chọn kết quả vào Wizard</button>' : ''}` : '';
        const candidateMarkup = canConfirmProduct ? '<p class="idol-note">Agent không tự chọn thay bạn. Chọn một sản phẩm bên dưới hoặc nhập mã khác.</p>' : '';
        const campaignButton = run.campaign_id ? `<button type="button" class="idol-btn primary" data-open-agent-campaign="${escapeHtml(run.campaign_id)}">Mở chương trình</button>` : '';
        const retryButton = run.status === 'NEEDS_ATTENTION' ? `<button type="button" class="idol-btn warn" data-retry-agent="${escapeHtml(run._id)}">Thử lại bước lỗi</button>` : waiting ? `<button type="button" class="idol-btn secondary" data-retry-agent="${escapeHtml(run._id)}">Chạy lại</button>` : '';
        const cancelButton = !run.campaign_id && !['CANCELLED','COMPLETED'].includes(run.status) ? `<button type="button" class="idol-btn secondary" data-cancel-agent="${escapeHtml(run._id)}">Hủy Agent</button>` : '';
        agentDetailEl.hidden = false;
        const liveStatus = run.status_effective || run.campaign_status || run.status;
        const campaignError = run.campaign_error_message || run.error_message;
        agentDetailEl.innerHTML = `<div class="detail-heading"><span class="studio-eyebrow">AGENT RUN</span>${liveBadge(liveStatus, run.campaign_platforms)}</div><h3>${escapeHtml(agentStatusName(liveStatus))}</h3><p class="campaign-meta">${escapeHtml(run.brief)}</p>${intentMarkup}${campaignError ? `<div class="studio-message">${escapeHtml(campaignError)}</div>` : ''}<div class="agent-timeline"><div class="agent-stage"><i class="bi bi-check2-circle"></i><span>${escapeHtml(agentStatusName(run.campaign_status || run.current_stage || run.status))}</span></div>${schedule}</div>${agentStepsMarkup(run)}${queryMarkup}${candidateMarkup}${products ? `<div class="agent-products">${products}</div>` : ''}${unresolvedMarkup}${shownWarnings.map(message => `<div class="agent-warning">${escapeHtml(message)}</div>`).join('')}${productInputMarkup}${scheduleInputMarkup}<div class="agent-actions">${queryActions}${campaignButton}${retryButton}${cancelButton}</div>`;
    }

    // Seven user-facing steps derived from the run, campaign status and segment job stages.
    const agentStepLabels = ['Hiểu lệnh', 'Chọn sản phẩm', 'Kịch bản', 'Giọng nói', 'Render Syna', 'Ghép & lên lịch', 'Phát'];
    // A product video ends on the product page: nothing is assembled, scheduled or broadcast.
    const productVideoStepLabels = ['Hiểu lệnh', 'Chọn sản phẩm', 'Kịch bản', 'Giọng nói', 'Render Syna', 'Đăng lên trang sản phẩm'];
    const isProductVideoRun = run => run.campaign_purpose === 'product_video';
    const agentLabelsFor = run => isProductVideoRun(run) ? productVideoStepLabels : agentStepLabels;
    function segmentStepIndex(segment) {
        if (segment.status === 'READY') return 5;
        if (segment.job_stage === 'GENERATING_VOICE') return 3;
        if (['RENDERING', 'GENERATING_AVATAR', 'WAITING_RENDERER'].includes(segment.job_stage)) return 4;
        return 2;
    }
    function agentStepStates(run) {
        const productVideo = isProductVideoRun(run);
        const labels = agentLabelsFor(run);
        const states = labels.map(() => 'pending');
        const notes = labels.map(() => '');
        const mark = (done, active, problem) => {
            states.forEach((_, index) => { if (index < done) states[index] = 'done'; });
            if (active != null && active < states.length) states[active] = problem ? 'error' : 'active';
        };
        const problem = ['NEEDS_ATTENTION', 'NEEDS_INPUT'].includes(run.status);
        if (!run.campaign_id) {
            const productStages = ['RESOLVING_PRODUCTS', 'POLICY_GATE', 'SCHEDULE_REVIEW', 'QUERYING_PRODUCTS', 'QUERY_RESULTS', 'PLAN_READY'];
            const index = productStages.includes(run.current_stage) || run.status === 'PLAN_READY' ? 1 : 0;
            mark(index, index, problem);
            return {states, notes};
        }
        const status = run.campaign_status || '';
        const segments = run.campaign_segments || [];
        const failed = segments.some(item => item.status === 'FAILED') || status === 'NEEDS_ATTENTION';
        const media = segments.length ? Math.min(...segments.map(segmentStepIndex)) : 2;
        if (['CANCELLED'].includes(status)) { mark(2, null); return {states, notes}; }
        if (['COMPLETED'].includes(status)) { mark(7, null); return {states, notes}; }
        if (productVideo && !failed && (status === 'VIDEOS_READY' || (segments.length && media >= 5))) {
            // Every clip is rendered; "done" only means the clips are on the product page.
            mark(5, null);
            const pages = segments.map(item => item.product_page).filter(page => page && page.eligible !== false);
            if (pages.some(page => page.block_reason && !page.ready)) {
                states[5] = 'error';
                notes[5] = 'Không cắt được phần không nói giá';
            } else if (pages.length && pages.every(page => page.ready === true)) {
                states[5] = 'done';
            } else {
                states[5] = 'active';
                notes[5] = pages.filter(page => !page.ready).map(page => page.last_error).find(Boolean) || 'Đang đăng lên trang sản phẩm…';
            }
            return {states, notes};
        }
        if (status === 'PARTIAL_FAILURE') { mark(6, 6, true); return {states, notes}; }
        if (['STARTING', 'LIVE'].includes(status)) { mark(6, 6, false); return {states, notes}; }
        if (status === 'SCHEDULED') { mark(6, null); notes[5] = 'Đã lên lịch'; return {states, notes}; }
        if (status === 'ASSEMBLING') { mark(5, 5, false); return {states, notes}; }
        // A product video has no step after rendering, so its last in-progress step is "Render Syna".
        const lastRenderStep = productVideo ? 4 : 5;
        mark(Math.min(media, lastRenderStep), media < lastRenderStep ? media : lastRenderStep, failed);
        if (segments.some(item => item.status === 'AWAITING_APPROVAL')) notes[2] = 'Chờ bạn duyệt kịch bản';
        if (segments.some(item => item.job_stage === 'WAITING_RENDERER')) notes[4] = 'Chờ Syna bật lại, sẽ tự chạy tiếp';
        return {states, notes};
    }
    function agentStepsMarkup(run) {
        const {states, notes} = agentStepStates(run);
        const progress = run.campaign_progress || {};
        const total = Number(progress.total || (run.campaign_segments || []).length || 0);
        const published = (run.campaign_segments || []).filter(item => item.product_page?.ready && !item.product_page.hidden).length;
        const counterText = isProductVideoRun(run)
            ? `${Math.min(published, total)}/${total} video đã lên trang sản phẩm`
            : `${Math.min(Number(progress.ready || 0), total)}/${total} sản phẩm sẵn sàng`;
        const counter = run.campaign_id && total ? `<p class="agent-steps-count">${counterText}</p>` : '';
        const icons = {done:'check-circle-fill', active:'arrow-repeat', error:'exclamation-circle-fill', pending:'circle'};
        return `<ol class="agent-steps" aria-label="Các bước Agent">${agentLabelsFor(run).map((label, index) => `<li class="is-${states[index]}"><i class="bi bi-${icons[states[index]]}" aria-hidden="true"></i><span>${escapeHtml(label)}${notes[index] ? `<small>${escapeHtml(notes[index])}</small>` : ''}</span></li>`).join('')}</ol>${counter}`;
    }

    function renderQueryEvidence(evidence) {
        const fields = {stock:'Tồn khả dụng', eligible_quantity:'Số lượng lô phù hợp', expires_on:'HSD gần nhất', units_sold:'Đã bán', net_revenue:'Doanh thu', viewed_sessions:'Phiên xem', converted_sessions:'Phiên mua', cart_sessions:'Phiên thêm giỏ', absolute_growth:'Tăng thêm', units_per_day:'Số bán / ngày', brand:'Thương hiệu', warehouse_id:'Kho', batch_id:'Lô'};
        const parts = Object.entries(fields).filter(([key]) => evidence[key] != null).map(([key, label]) => `${label}: ${typeof evidence[key] === 'number' ? evidence[key].toLocaleString('vi-VN', {maximumFractionDigits:2}) : evidence[key]}`);
        for (const [key, label] of [['conversion_rate','Chuyển đổi'], ['growth_rate','Tăng trưởng']]) {
            if (evidence[key] != null) parts.push(`${label}: ${(evidence[key] * 100).toFixed(1)}%`);
        }
        if (evidence.metric_source) parts.push(`Nguồn: ${evidence.metric_source === 'catalog_lifetime' ? 'Catalog · toàn kỳ' : 'Snapshot thống kê theo kỳ'}`);
        const batches = (evidence.batches || []).map(batch => `Lô ${batch.batch_id || 'chưa có mã'} · ${batch.expires_on} · ${batch.quantity} đơn vị`);
        return `<small class="agent-evidence">${[...parts, ...batches].map(escapeHtml).join('<br>')}</small>`;
    }

    async function refreshAgentRuns() {
        if (agentRefreshBusy) return;
        agentRefreshBusy = true;
        $('refreshAgentRuns').disabled = true;
        try {
            const result = await api('index.php?r=admin_ai_idol_agent_runs&limit=20');
            agentRuns = result.runs || [];
            renderAgentRuns();
        } catch (error) {
            if (!agentRuns.length) agentListEl.innerHTML = `<div class="empty">${escapeHtml(error.message)}</div>`;
        } finally {
            agentRefreshBusy = false;
            $('refreshAgentRuns').disabled = false;
        }
    }

    $('agentForm').addEventListener('submit', async event => {
        event.preventDefault();
        const button = $('runAgent');
        if (button.disabled) return;
        const brief = $('agentBrief').value.trim();
        if (brief.length < AGENT_BRIEF_MIN_LENGTH) { agentNotify(`Hãy mô tả yêu cầu ít nhất ${AGENT_BRIEF_MIN_LENGTH} ký tự.`); return $('agentBrief').focus(); }
        const policy = root.querySelector('input[name="agentPolicy"]:checked')?.value || 'auto_preview';
        button.disabled = true;
        button.textContent = 'Agent đang tiếp nhận…';
        agentNotify('');
        try {
            const result = await api('index.php?r=admin_ai_idol_agent_create', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({brief, autonomy_policy:policy, purpose:selectedAgentPurpose(), idempotency_key:`studio:${Date.now()}`})});
            activeAgentRunId = result.run_id;
            if ($('agentBrief').value.trim() === brief) $('agentBrief').value = '';
            await refreshAgentRuns();
            agentNotify('Agent đã nhận việc và đang lập kế hoạch.', true);
        } catch (error) { agentNotify('Không thể chạy Agent: ' + error.message); }
        finally { button.disabled = false; button.innerHTML = 'Lập kế hoạch & chạy <i class="bi bi-arrow-right" aria-hidden="true"></i>'; }
    });

    $('refreshAgentRuns').addEventListener('click', refreshAgentRuns);
    agentListEl.addEventListener('click', event => {
        const button = event.target.closest('[data-agent-run]');
        if (!button) return;
        activeAgentRunId = button.dataset.agentRun;
        renderAgentRuns();
    });
    agentDetailEl.addEventListener('click', async event => {
        const selectedRun = agentRuns.find(item => item._id === activeAgentRunId);
        if (event.target.closest('[data-edit-agent-query]')) {
            previousAgentPrompt = $('agentBrief').value;
            $('agentBrief').value = selectedRun?.brief || '';
            $('undoAgentPrompt').hidden = false;
            return $('agentBrief').focus();
        }
        if (event.target.closest('[data-query-to-wizard]')) {
            const result = selectedRun?.plan?.query_result;
            if (!result?.complete || !result.selected?.length) return;
            const ids = new Set(result.selected.map(item => String(item.product_id)));
            if (selectedProducts().length && !window.confirm('Thay danh sách sản phẩm đang chọn trong Wizard bằng kết quả này?')) return;
            // The initial Wizard contains only the latest catalog page. Add verified
            // query results locally; campaign creation still validates IDs server-side.
            result.selected.forEach(item => {
                const id = String(item.product_id);
                if ([...root.querySelectorAll('.product-check')].some(input => input.value === id)) return;
                const label = document.createElement('label');
                label.className = 'product-item';
                label.dataset.search = `${id} ${item.name}`;
                label.innerHTML = `<input type="checkbox" class="product-check" value="${escapeHtml(id)}" data-name="${escapeHtml(item.name)}"><span class="product-thumb" aria-hidden="true"><i class="bi bi-box-seam"></i></span><span class="product-copy"><span class="product-name">${escapeHtml(item.name)}</span><small>Mã sản phẩm ${escapeHtml(id)} · Từ kết quả Agent</small></span><i class="bi bi-check2 selected-tick" aria-hidden="true"></i>`;
                $('productList').prepend(label);
                productNames.set(id, item.name);
            });
            const inputs = [...root.querySelectorAll('.product-check')];
            inputs.forEach(input => { input.checked = ids.has(input.value); });
            $('productSearch').value = '';
            root.querySelectorAll('.product-item').forEach(item => { item.hidden = false; });
            $('productEmpty').hidden = true;
            updateSelection();
            showStudioFlow('manual');
            showStep(1, true);
            notify('Đã chọn sản phẩm từ kết quả truy vấn. Bạn kiểm tra nội dung và lịch trước khi tạo; chưa có video nào được tạo.', true);
            return;
        }
        const productButton = event.target.closest('[data-agent-confirm-product]');
        const codeButton = event.target.closest('[data-agent-confirm-code]');
        const scheduleButton = event.target.closest('[data-agent-confirm-schedule]');
        const openButton = event.target.closest('[data-open-agent-campaign]');
        const retryButton = event.target.closest('[data-retry-agent]');
        const cancelButton = event.target.closest('[data-cancel-agent]');
        if (openButton) {
            showStudioFlow('manual');
            await refreshCampaigns();
            return loadDetail(openButton.dataset.openAgentCampaign);
        }
        const confirmButton = productButton || codeButton || scheduleButton;
        const runId = confirmButton ? activeAgentRunId : retryButton?.dataset.retryAgent || cancelButton?.dataset.cancelAgent;
        if (!runId) return;
        let body = {};
        if (productButton) body = {product_ids:[productButton.dataset.agentConfirmProduct]};
        if (codeButton) {
            const code = (agentDetailEl.querySelector('[data-agent-product-code]')?.value || '').trim();
            if (!code) return agentNotify('Nhập mã sản phẩm trước khi bấm "Dùng mã này".');
            body = {product_ids:[code]};
        }
        if (scheduleButton) {
            const value = agentDetailEl.querySelector('[data-agent-schedule-input]')?.value || '';
            const time = new Date(value).getTime();
            if (!value || !Number.isFinite(time) || time <= Date.now()) return agentNotify('Chọn giờ phát nằm trong tương lai.');
            body = {scheduled_at:value};
        }
        const route = confirmButton ? 'admin_ai_idol_agent_confirm' : retryButton ? 'admin_ai_idol_agent_retry' : 'admin_ai_idol_agent_cancel';
        const buttons = [productButton, codeButton, scheduleButton, retryButton, cancelButton].filter(Boolean);
        buttons.forEach(button => { button.disabled = true; });
        agentNotify('');
        try {
            await api(`index.php?r=${route}&run_id=${encodeURIComponent(runId)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
            if (confirmButton) agentDraft = {runId:'', code:'', schedule:''};
            await refreshAgentRuns();
            if (confirmButton) agentNotify('Agent đã nhận xác nhận và đang xử lý.', true);
        } catch (error) {
            buttons.forEach(button => { button.disabled = false; });
            agentNotify(error.status === 409 ? 'Agent đang xử lý, chờ vài giây rồi thử lại.' : 'Không thể cập nhật Agent: ' + error.message);
        }
    });
    agentDetailEl.addEventListener('input', event => {
        const code = event.target.closest?.('[data-agent-product-code]');
        const schedule = event.target.closest?.('[data-agent-schedule-input]');
        if (!code && !schedule) return;
        agentDraft = {...(agentDraft.runId === activeAgentRunId ? agentDraft : {code:'', schedule:''}), runId:activeAgentRunId};
        if (code) agentDraft.code = code.value;
        if (schedule) agentDraft.schedule = schedule.value;
    });
    async function uploadAsset(kind, file) {
        if (!file) return '';
        const body = new FormData(); body.append('kind', kind); body.append('file', file);
        const result = await api('index.php?r=admin_ai_idol_upload_asset', {method:'POST', body});
        return result.filename || '';
    }
    function selectedManualPurpose() {
        return root.querySelector('input[name="manualPurpose"]:checked')?.value || 'product_video';
    }
    function applyManualPurpose() {
        const live = selectedManualPurpose() !== 'product_video';
        $('manualLiveFields').hidden = !live;
        $('manualProductVideoNote').hidden = live;
        $('manualApprovalNote').hidden = !live;
    }
    root.querySelectorAll('input[name="manualPurpose"]').forEach(input => input.addEventListener('change', applyManualPurpose));
    applyManualPurpose();
    $('studioForm').addEventListener('submit', async event => {
        event.preventDefault();
        if (createButton.disabled) return;
        const productIds = selectedProducts();
        const scheduledAt = $('scheduledAt').value;
        const platforms = [...root.querySelectorAll('input[name="platform"]:checked')].map(item => item.value);
        const avatarFile = $('avatarFile').files?.[0];
        const duration = Number($('durationMinutes').value);
        const invalid = (step, message, field) => { showStep(step); notify(message); $(field).focus(); };
        if (!productIds.length) return invalid(1, 'Chọn ít nhất một sản phẩm.', 'productSearch');
        if (!isSyna() && (!avatarFile || !avatarMediaValid)) return invalid(2, 'Chọn ảnh hoặc video nhân vật hợp lệ.', 'avatarFile');
        const purpose = selectedManualPurpose();
        const live = purpose !== 'product_video';
        if (live && (!scheduledAt || !Number.isFinite(new Date(scheduledAt).getTime()) || new Date(scheduledAt).getTime() <= Date.now())) return invalid(3, 'Chọn giờ phát nằm trong tương lai.', 'scheduledAt');
        if (live && (!Number.isInteger(duration) || duration < 1 || duration > 720)) return invalid(3, 'Thời lượng phát từ 1 đến 720 phút.', 'durationMinutes');
        if (live && !platforms.length) return invalid(3, 'Chọn ít nhất một nơi phát.', 'durationMinutes');
        // Capture the form before upload; employees can keep browsing their library.
        const backgroundFile = $('backgroundFile').files?.[0];
        const payload = {name:$('campaignName').value.trim(), product_ids:productIds, purpose, scheduled_at:live ? scheduledAt : null, platforms:live ? platforms : [], duration_minutes:live ? duration : 60, content_mode:$('contentMode').value, configuration:{format:$('videoFormat').value, length_mode:live ? 'Short (30-60s)' : 'Long (90-150s)', avatar_mode:isSyna() ? 'syna_3d' : isVideoFile(avatarFile) ? 'motion_video' : 'photo'}};
        createButton.disabled = true;
        createButton.textContent = 'Đang chuẩn bị…';
        notify('');
        try {
            // Required avatar uploads first, avoiding an orphan background on failure.
            payload.configuration.avatar_asset = payload.configuration.avatar_mode === 'syna_3d' ? '' : await uploadAsset('avatar', avatarFile);
            payload.configuration.background_asset = await uploadAsset('background', backgroundFile);
            const result = await api('index.php?r=admin_ai_idol_create_campaign', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            activeDetailSignature = '';
            setFilter('all');
            await refreshCampaigns();
            await loadDetail(result.campaign_id);
            notify('Đã tạo chương trình. Kịch bản sẽ xuất hiện trong thư viện để bạn duyệt.', true);
        } catch (error) { notify('Không thể tạo chương trình: ' + error.message); }
        finally { createButton.disabled = false; createButton.innerHTML = 'Tạo kịch bản <i class="bi bi-arrow-right" aria-hidden="true"></i>'; }
    });

    function setFilter(value) {
        campaignFilter = value;
        root.querySelectorAll('[data-filter]').forEach(button => {
            const active = button.dataset.filter === value;
            button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
        });
        renderCampaigns();
    }
    root.querySelectorAll('[data-filter]').forEach(button => button.addEventListener('click', () => setFilter(button.dataset.filter)));
    function renderCampaigns() {
        // Warnings count down by the minute, so they are part of the signature too.
        const warnings = new Map(campaigns.map(item => [item._id, approvalWarning(item)]));
        const signature = JSON.stringify([campaigns, campaignFilter, activeCampaignId, [...warnings.values()]]);
        if (signature === listSignature) return; // Keep keyboard focus and scroll on unchanged polls.
        listSignature = signature;
        $('totalCampaigns').textContent = campaigns.length;
        $('libraryCount').textContent = campaigns.length;
        $('approvalCount').textContent = campaigns.filter(item => item.status === 'AWAITING_APPROVAL').length;
        const dueSoon = [...warnings.values()].filter(Boolean).length;
        const approvalStat = $('approvalCount').closest('.studio-stat');
        approvalStat.classList.toggle('is-alert', dueSoon > 0);
        approvalStat.title = dueSoon ? `${dueSoon} chương trình sắp tới giờ phát nhưng chưa duyệt kịch bản` : '';
        $('scheduledCount').textContent = campaigns.filter(item => item.status === 'SCHEDULED').length;
        const filtered = campaigns.filter(item => campaignFilter === 'all' || (campaignFilter === 'approval' ? item.status === 'AWAITING_APPROVAL' : item.status === 'SCHEDULED'));
        if (!filtered.length) {
            const message = campaignFilter === 'approval' ? 'Không có kịch bản đang chờ duyệt.' : campaignFilter === 'scheduled' ? 'Chưa có chương trình được lên lịch.' : 'Chưa có chương trình nào.<br>Tạo chương trình đầu tiên ở phần Chuẩn bị.';
            listEl.innerHTML = `<div class="empty"><i class="bi bi-collection-play" aria-hidden="true"></i><p>${message}</p></div>`;
            return;
        }
        const focusId = document.activeElement?.closest('.campaign')?.dataset.id;
        listEl.innerHTML = filtered.map(item => {
            const progress = item.progress || {};
            const total = Math.max(Number(progress.total || item.product_ids?.length || 1), 1);
            const ready = Number(progress.ready || 0);
            const icon = item.status === 'AWAITING_APPROVAL' ? 'pencil-square' : 'play-btn';
            return `<button type="button" class="campaign ${item._id === activeCampaignId ? 'active' : ''}" data-id="${escapeHtml(item._id)}" aria-pressed="${item._id === activeCampaignId}"><span class="campaign-icon"><i class="bi bi-${icon}" aria-hidden="true"></i></span><span class="campaign-copy"><span class="campaign-top"><span class="campaign-title">${escapeHtml(item.name)}</span>${liveBadge(item.status, item.platforms)}</span><span class="campaign-meta">${escapeHtml(formatDate(item.scheduled_at))} · ${total} sản phẩm · ${(item.platforms || []).map(value => escapeHtml(platformName(value))).join(', ')}</span>${approvalAlertMarkup(warnings.get(item._id), true)}<span class="meter" role="progressbar" aria-label="Video đã tạo" aria-valuenow="${Math.min(total, ready)}" aria-valuemin="0" aria-valuemax="${total}"><span style="width:${Math.min(100, Math.max(0, ready / total * 100))}%"></span></span></span></button>`;
        }).join('');
        if (focusId) [...listEl.querySelectorAll('.campaign')].find(button => button.dataset.id === focusId)?.focus({preventScroll:true});
    }
    listEl.addEventListener('click', event => {
        const item = event.target.closest('.campaign');
        if (item) loadDetail(item.dataset.id);
    });
    async function refreshCampaigns() {
        if (refreshBusy) return;
        refreshBusy = true;
        $('refreshCampaigns').disabled = true;
        try {
            const result = await api('index.php?r=admin_ai_idol_campaigns&limit=30');
            campaigns = result.campaigns || [];
            renderCampaigns();
            $('syncStatus').classList.remove('is-offline');
            $('syncStatus').innerHTML = '<span class="sync-dot"></span> Tự cập nhật mỗi 5 giây';
        } catch (error) {
            $('syncStatus').classList.add('is-offline');
            $('syncStatus').innerHTML = '<span class="sync-dot"></span> Chưa thể cập nhật';
            if (!campaigns.length) { listEl.innerHTML = `<div class="empty">${escapeHtml(error.message)}</div>`; listSignature = ''; }
        } finally { refreshBusy = false; $('refreshCampaigns').disabled = false; }
    }
    $('refreshCampaigns').addEventListener('click', async () => { await refreshCampaigns(); if (activeCampaignId) await loadDetail(activeCampaignId); });

    function rememberDrafts() {
        detailEl.querySelectorAll('.script-editor:not([readonly])').forEach(editor => scriptDrafts.set(editor.dataset.job, editor.value));
    }
    detailEl.addEventListener('input', event => {
        if (event.target.matches('.script-editor:not([readonly])')) scriptDrafts.set(event.target.dataset.job, event.target.value);
    });
    const PAGE_BLOCK_REASONS = {
        missing_price_marker: 'Kịch bản thiếu mốc [Price] nên không tách được phần không nói giá.',
        body_cut_failed: 'Không cắt được phần giới thiệu không giá.',
    };
    function livePageCount(item) {
        return (item.segments || []).filter(segment => segment.product_page?.ready && !segment.product_page?.hidden).length;
    }
    function productPageMarkup(segment) {
        const page = segment.product_page;
        if (!page) return '';
        if (page.eligible === false) {
            return `<div class="segment-page"><span><i class="bi bi-shop" aria-hidden="true"></i> Chế độ chỉ livestream: video này không đăng lên trang sản phẩm.</span></div>`;
        }
        const text = page.ready
            ? (page.hidden ? 'Đã ẩn khỏi trang sản phẩm.' : 'Đang hiện trên trang sản phẩm.')
            : PAGE_BLOCK_REASONS[page.block_reason] || (page.last_error ? 'Chưa đăng được: ' + page.last_error : 'Sẽ tự lên trang sản phẩm khi sẵn sàng.');
        const button = page.ready
            ? `<button type="button" class="idol-btn secondary" data-video-visibility="${escapeHtml(page.video_id)}" data-hide="${page.hidden ? '0' : '1'}">${page.hidden ? 'Hiện lại trên trang sản phẩm' : 'Ẩn khỏi trang sản phẩm'}</button>`
            : '';
        return `<div class="segment-page ${page.ready && !page.hidden ? 'is-live' : ''}"><span><i class="bi bi-shop" aria-hidden="true"></i> ${escapeHtml(text)}</span>${button}</div>`;
    }
    function renderSegment(segment) {
        const errorText = String(segment.error_message || '');
        const errorSummary = errorText.includes('CUDA out of memory') ? 'GPU hết bộ nhớ khi tạo video.' : (errorText.split('\n')[0] || '').slice(0, 180);
        const errorMarkup = errorText ? `<details class="segment-error"><summary>${escapeHtml(errorSummary)}</summary><pre>${escapeHtml(errorText)}</pre></details>` : '';
        // A draft the validator rejected is kept so staff can fix the flagged claims and approve it.
        const rejected = segment.script_status === 'REJECTED_BY_VALIDATOR' && Boolean(segment.rejected_script_text);
        const awaiting = segment.status === 'AWAITING_APPROVAL' || segment.script_status === 'PENDING_APPROVAL' || rejected;
        const scriptText = String(awaiting && scriptDrafts.has(segment.job_id) ? scriptDrafts.get(segment.job_id) : (segment.script_text || (rejected ? segment.rejected_script_text : '')));
        const feedbackMarkup = rejected ? `<div class="agent-warning script-feedback"><strong>Kịch bản bị từ chối:</strong> ${escapeHtml(segment.validation_feedback || 'Có nội dung vi phạm quy chế quảng cáo.')}<br>Sửa lại các câu bị nêu rồi bấm chấp nhận; hệ thống sẽ kiểm tra lại trước khi tạo video.</div>` : '';
        const title = productNames.get(String(segment.product_id)) || `Sản phẩm ${segment.product_id}`;
        const scriptMarkup = scriptText ? `<details class="script-review" ${awaiting ? 'open' : ''}><summary>${rejected ? 'Sửa kịch bản bị từ chối' : awaiting ? 'Chỉnh sửa & duyệt kịch bản' : 'Xem kịch bản đã duyệt'}</summary>${feedbackMarkup}<label for="script-${escapeHtml(segment.job_id)}">${escapeHtml(title)}</label><textarea class="script-editor" data-job="${escapeHtml(segment.job_id)}" id="script-${escapeHtml(segment.job_id)}" ${awaiting ? '' : 'readonly'}>${escapeHtml(scriptText)}</textarea><p class="script-review-note">${awaiting ? 'Sửa trực tiếp lời giới thiệu. Giọng nói và video chỉ được tạo sau khi bạn chấp nhận.' : 'Kịch bản đã duyệt cho video này.'}</p>${awaiting ? `<button type="button" class="idol-btn primary" data-approve-script="${escapeHtml(segment.job_id)}" ${approvingJobs.has(segment.job_id) ? 'disabled' : ''}>${approvingJobs.has(segment.job_id) ? 'Đang gửi duyệt…' : 'Chấp nhận & tạo video'}</button>` : ''}</details>` : '';
        return `<div class="segment"><div class="segment-heading"><span>Sản phẩm ${escapeHtml(segment.product_id)}</span>${badge(segment.status)}</div>${errorMarkup}${productPageMarkup(segment)}${scriptMarkup}</div>`;
    }
    async function loadDetail(id) {
        const requestNumber = ++detailRequest;
        const sameCampaign = activeCampaignId === id;
        rememberDrafts();
        activeCampaignId = id;
        renderCampaigns();
        $('libraryIdle').hidden = true;
        detailEl.hidden = false;
        if (!sameCampaign) detailEl.innerHTML = '<div class="empty" role="status">Đang mở chương trình…</div>';
        try {
            const result = await api(`index.php?r=admin_ai_idol_campaign&campaign_id=${encodeURIComponent(id)}`);
            if (requestNumber !== detailRequest || id !== activeCampaignId) return;
            const item = result.data;
            const warning = approvalWarning(item);
            const signature = JSON.stringify([item._id,item.status,item.master_video_path,item.error_message,item.configuration,item.segments,item.scheduled_at,item.platforms,item.broadcasts,item.preflight,item.started_at,item.ends_at,warning]);
            if (sameCampaign && activeDetailSignature === signature) return;
            // Let employees finish typing or submitting; preserve drafts across later updates.
            if (sameCampaign && (detailEl.contains(document.activeElement) && document.activeElement.matches('textarea:not([readonly]), #rescheduleAt') || approvingJobs.size)) return;
            rememberDrafts();
            const oldVideo = sameCampaign ? detailEl.querySelector('video') : null;
            const oldVideoUrl = oldVideo?.getAttribute('src');
            const campaignAvatar = String(item.configuration?.avatar_asset || '');
            const motion = /\.(mp4|webm)$/i.test(campaignAvatar);
            const characterDescription = item.configuration?.avatar_mode === 'syna_3d' ? 'Syna 3D · giọng đọc đã duyệt, sản phẩm thật và bảng thành phần.' : motion ? 'Nhân vật từ video · giữ chuyển động có sẵn trong clip.' : 'Nhân vật từ ảnh tĩnh · miệng và đầu chuyển động nhẹ.';
            const canPreview = Boolean(item.master_video_path) && ['READY','SCHEDULED','STARTING','LIVE','COMPLETED','PARTIAL_FAILURE'].includes(item.status);
            const internalLive = item.status === 'LIVE' && isInternalOnly(item.platforms) && Boolean(item.started_at);
            const liveNote = internalLive ? ` · đang phát nội bộ${item.ends_at ? ', kết thúc ' + escapeHtml(formatDate(item.ends_at)) : ''}` : '';
            const waiting = item.status === 'VIDEOS_READY' ? 'Video sản phẩm đã sẵn sàng. Trạng thái trên trang sản phẩm nằm ở từng sản phẩm bên dưới.' : item.status === 'AWAITING_APPROVAL' ?'Kịch bản đã sẵn sàng. Duyệt bên dưới để bắt đầu tạo video.' : ['NEEDS_ATTENTION','FAILED'].includes(item.status) ? 'Video chưa hoàn tất. Xem phần cần kiểm tra bên dưới.' : item.status === 'CANCELLED' ? 'Chương trình này đã được hủy.' : 'Video đang được chuẩn bị. Tiến độ sẽ tự cập nhật.';
            detailEl.innerHTML = `<div class="detail-heading"><span class="studio-eyebrow">${canPreview ? 'XEM CHƯƠNG TRÌNH' : 'CHI TIẾT CHƯƠNG TRÌNH'}</span>${liveBadge(item.status, item.platforms)}</div>
                <h3>${escapeHtml(item.name)}</h3><p class="campaign-meta">${item.scheduled_at ? `Lịch phát ${escapeHtml(formatDate(item.scheduled_at))}${liveNote}` : 'Video sản phẩm · không phát sóng'}${livePageCount(item) ? ` · ${livePageCount(item)} video đang hiện trên trang sản phẩm` : ''}</p>
                ${approvalAlertMarkup(warning, false)}
                ${item.error_message ? `<div class="studio-message">${escapeHtml(item.error_message)}</div>` : ''}
                ${canPreview ? `<video id="campaignPreview" controls playsinline preload="metadata" aria-label="Video chương trình" src="index.php?r=admin_ai_idol_stream_campaign&campaign_id=${encodeURIComponent(id)}"></video>` : `<div class="empty"><i class="bi bi-${item.status === 'AWAITING_APPROVAL' ? 'pencil-square' : 'camera-reels'}" aria-hidden="true"></i><p>${waiting}</p></div>`}
                <div class="avatar-mode-note ${motion || item.configuration?.avatar_mode === 'syna_3d' ? 'motion' : 'photo'}">${escapeHtml(characterDescription)}</div>
                <div>${(item.segments || []).map(renderSegment).join('')}</div>
                ${broadcastTableMarkup(item)}
                ${item.status === 'NEEDS_ATTENTION' ? '<div class="detail-actions"><button type="button" class="idol-btn warn" id="retryCampaign">Tạo lại phần bị lỗi</button></div>' : ''}
                ${campaignControlsMarkup(item)}
                <div id="detailMessage" class="studio-message" role="alert" hidden></div>`;
            // Reuse the actual video node instead of resetting playback every poll.
            const newVideo = $('campaignPreview');
            if (oldVideo && newVideo && oldVideoUrl === newVideo.getAttribute('src')) newVideo.replaceWith(oldVideo);
            else if (newVideo && internalLive) seekToLivePosition(newVideo, item.started_at);
            activeDetailSignature = signature;
        } catch (error) {
            if (requestNumber !== detailRequest) return;
            if (!sameCampaign || !activeDetailSignature) detailEl.innerHTML = `<div class="empty">${escapeHtml(error.message)}</div>`;
            else detailError(error.message);
            activeDetailSignature = '';
        }
    }
    // Internal programmes loop the master video from the real start time, so every viewer sees the same moment.
    function seekToLivePosition(video, startedAt) {
        const started = new Date(startedAt).getTime();
        if (!Number.isFinite(started)) return;
        video.loop = true;
        video.addEventListener('loadedmetadata', () => {
            if (Number.isFinite(video.duration) && video.duration > 0) video.currentTime = ((Date.now() - started) / 1000) % video.duration;
            video.play().catch(() => {});
        }, {once:true});
    }
    function broadcastTableMarkup(item) {
        const broadcasts = item.broadcasts || [];
        const preflight = item.preflight;
        if (!broadcasts.length && !preflight) return '';
        const rows = broadcasts.map(row => `<tr><td>${escapeHtml(platformName(row.platform))}</td><td>${badge(row.status)}</td><td>${escapeHtml(row.error_message || (row.ends_at ? 'Đến ' + formatDate(row.ends_at) : ''))}</td></tr>`).join('');
        const table = rows ? `<table class="broadcast-table"><thead><tr><th>Nơi phát</th><th>Trạng thái</th><th>Chi tiết</th></tr></thead><tbody>${rows}</tbody></table>` : '';
        const check = preflight ? `<p class="broadcast-preflight ${preflight.ok ? 'is-ok' : 'is-bad'}"><strong>Kiểm tra trước phát:</strong> ${preflight.ok ? 'đạt' : 'chưa đạt'}${(preflight.errors || []).length ? ' · ' + (preflight.errors || []).map(escapeHtml).join('; ') : ''}${preflight.checked_at ? `<small> (${escapeHtml(formatDate(preflight.checked_at))})</small>` : ''}</p>` : '';
        return `<section class="broadcast-panel">${table}${check}</section>`;
    }
    const CANCELLABLE = ['VALIDATING', 'GENERATING', 'AWAITING_APPROVAL', 'NEEDS_ATTENTION', 'SCHEDULED'];
    function campaignControlsMarkup(item) {
        if (!CANCELLABLE.includes(item.status)) return '';
        const canAirNow = isInternalOnly(item.platforms) && item.master_video_path && ['SCHEDULED', 'NEEDS_ATTENTION'].includes(item.status);
        return `<div class="campaign-controls"><div class="agent-input"><label for="rescheduleAt">Đổi lịch</label><input id="rescheduleAt" class="idol-input" type="datetime-local" value="${escapeHtml(toDatetimeLocal(item.scheduled_at))}"><button type="button" class="idol-btn secondary" id="rescheduleCampaign">Đổi lịch</button></div><div class="detail-actions">${canAirNow ? '<button type="button" class="idol-btn primary" id="airCampaignNow">Phát nội bộ ngay</button>' : ''}<button type="button" class="idol-btn secondary danger" id="cancelCampaign">Hủy chương trình</button></div></div>`;
    }
    function detailError(message) {
        const target = $('detailMessage');
        if (target) { target.textContent = message; target.hidden = false; }
    }
    detailEl.addEventListener('click', async event => {
        const approveButton = event.target.closest('[data-approve-script]');
        const retryButton = event.target.closest('#retryCampaign');
        const campaignId = activeCampaignId;
        const controlButton = event.target.closest('#cancelCampaign, #airCampaignNow, #rescheduleCampaign');
        if (controlButton && !controlButton.disabled) return runCampaignControl(controlButton, campaignId);
        const visibilityButton = event.target.closest('[data-video-visibility]');
        if (visibilityButton && !visibilityButton.disabled) {
            const hide = visibilityButton.dataset.hide === '1';
            visibilityButton.disabled = true;
            try {
                await api('index.php?r=admin_ai_idol_video_visibility', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({video_id:visibilityButton.dataset.videoVisibility, hidden:hide})});
                activeDetailSignature = '';
                if (activeCampaignId === campaignId) await loadDetail(campaignId);
            } catch (error) {
                detailError((hide ? 'Không thể ẩn video: ' : 'Không thể hiện lại video: ') + error.message);
                visibilityButton.disabled = false;
            }
            return;
        }
        if (approveButton) {
            const jobId = approveButton.dataset.approveScript;
            const text = $(`script-${jobId}`)?.value.trim() || '';
            if (text.length < 40) return detailError('Kịch bản cần ít nhất 40 ký tự.');
            if (approvingJobs.has(jobId)) return;
            approvingJobs.add(jobId); approveButton.disabled = true; approveButton.textContent = 'Đang gửi duyệt…';
            try {
                await api(`index.php?r=admin_ai_idol_approve_script&job_id=${encodeURIComponent(jobId)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({script_text:text})});
                scriptDrafts.delete(jobId);
                approvingJobs.delete(jobId);
                activeDetailSignature = '';
                if (activeCampaignId === campaignId) await loadDetail(campaignId);
                await refreshCampaigns();
            } catch (error) { detailError('Không thể duyệt kịch bản: ' + error.message); }
            finally { approvingJobs.delete(jobId); approveButton.disabled = false; approveButton.textContent = 'Chấp nhận & tạo video'; }
        }
        if (retryButton && !retryButton.disabled) {
            retryButton.disabled = true; retryButton.textContent = 'Đang gửi yêu cầu…';
            try {
                await api(`index.php?r=admin_ai_idol_retry_campaign&campaign_id=${encodeURIComponent(campaignId)}`, {method:'POST'});
                activeDetailSignature = '';
                if (activeCampaignId === campaignId) await loadDetail(campaignId);
                await refreshCampaigns();
            } catch (error) { detailError('Không thể thử lại: ' + error.message); }
            finally { retryButton.disabled = false; retryButton.textContent = 'Tạo lại phần bị lỗi'; }
        }
    });
    async function runCampaignControl(button, campaignId) {
        const action = button.id;
        let route = 'admin_ai_idol_reschedule_campaign', body = {};
        if (action === 'cancelCampaign') {
            if (!window.confirm('Hủy chương trình này? Các phần chưa tạo xong sẽ dừng lại và không thể khôi phục.')) return;
            route = 'admin_ai_idol_cancel_campaign';
        } else if (action === 'airCampaignNow') {
            body = {scheduled_at:'now'};
        } else {
            const value = $('rescheduleAt')?.value || '';
            const time = new Date(value).getTime();
            if (!value || !Number.isFinite(time) || time < Date.now() + 2 * 60 * 1000) return detailError('Chọn lịch mới cách hiện tại ít nhất 2 phút.');
            body = {scheduled_at:value};
        }
        const original = button.textContent;
        button.disabled = true; button.textContent = 'Đang gửi…';
        try {
            await api(`index.php?r=${route}&campaign_id=${encodeURIComponent(campaignId)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
            activeDetailSignature = '';
            if (activeCampaignId === campaignId) await loadDetail(campaignId);
            await refreshCampaigns();
            await refreshAgentRuns();
        } catch (error) {
            detailError(error.status === 409 ? error.message : 'Không thể cập nhật chương trình: ' + error.message);
            button.disabled = false; button.textContent = original;
        }
    }
    const date = new Date(Date.now() + 60 * 60 * 1000);
    date.setMinutes(Math.ceil(date.getMinutes() / 5) * 5, 0, 0);
    $('scheduledAt').value = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    updateSelection();
    // Service health strip: informational only, never disables a run button.
    const healthEl = $('idolHealth');
    const healthLabels = {mongo:'Dữ liệu', redis:'Hàng đợi', workers:'Worker', script_council:'Kịch bản', syna:'Syna', avatar:'Avatar', rtmp:'Phát ngoài'};
    function renderHealth(data) {
        if (!healthEl) return;
        const services = data?.services || {};
        const chips = Object.entries(healthLabels).filter(([key]) => services[key]).map(([key, label]) => {
            const item = services[key];
            const state = item.status === 'not_required' || item.status === 'not_configured' ? 'idle' : item.ok ? 'ok' : 'down';
            const extra = key === 'rtmp' ? (item.platforms || []).map(platformName).join(', ') || 'chỉ nội bộ' : '';
            return `<span class="health-chip is-${state}" title="${escapeHtml(item.detail || '')}"><i class="bi bi-${state === 'down' ? 'x-circle-fill' : state === 'ok' ? 'check-circle-fill' : 'dash-circle'}" aria-hidden="true"></i>${escapeHtml(label)}${extra ? `: ${escapeHtml(extra)}` : ''}</span>`;
        }).join('');
        const problems = Object.entries(healthLabels).filter(([key]) => services[key] && !services[key].ok && services[key].detail).map(([key]) => escapeHtml(services[key].detail));
        healthEl.innerHTML = `<span class="health-title">Dịch vụ</span>${chips}${problems.length ? `<span class="health-note is-bad">${problems.join(' ')}</span>` : ''}`;
    }
    async function refreshHealth() {
        if (document.hidden) return;
        try { renderHealth((await api('index.php?r=admin_ai_idol_health')).data); }
        catch (error) { if (healthEl) healthEl.innerHTML = `<span class="health-title">Dịch vụ</span><span class="health-note is-bad">Không kiểm tra được tình trạng dịch vụ: ${escapeHtml(error.message)}</span>`; }
    }
    refreshHealth();
    setInterval(refreshHealth, 30000);
    refreshCampaigns();
    refreshAgentRuns();
    // Links from the admin notification bell open the programme directly.
    const linkedCampaignId = new URLSearchParams(location.search).get('campaign_id');
    if (linkedCampaignId) loadDetail(linkedCampaignId).then(() => detailEl.scrollIntoView({block:'start'}));
    setInterval(async () => {
        if (document.hidden || pollBusy) return;
        pollBusy = true;
        try {
            await refreshCampaigns();
            await refreshAgentRuns();
            if (activeCampaignId) await loadDetail(activeCampaignId);
            if (!$('agentFlow')?.hidden && typeof fetchLiveProducts === 'function') {
                await fetchLiveProducts(true);
            }
        }
        finally { pollBusy = false; }
    }, 5000);
    window.addEventListener('pagehide', () => {
        $('avatarVideoPreview').pause();
    });
})();
