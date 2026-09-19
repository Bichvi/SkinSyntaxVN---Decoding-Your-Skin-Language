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
    const statusNames = {DRAFT:'Bản nháp',QUEUED:'Đang chờ',PENDING:'Đang chờ',GENERATING:'Đang tạo',PROCESSING:'Đang xử lý',AWAITING_APPROVAL:'Chờ duyệt',READY:'Sẵn sàng',SCHEDULED:'Đã lên lịch',STARTING:'Sắp lên sóng',LIVE:'Đang phát',COMPLETED:'Hoàn tất',NEEDS_ATTENTION:'Cần kiểm tra',PARTIAL_FAILURE:'Lỗi một phần',FAILED:'Bị lỗi',CANCELLED:'Đã hủy'};
    const statusName = value => statusNames[value] || String(value || 'Đang chờ');
    const badge = value => `<span class="status ${escapeHtml(value)}">${escapeHtml(statusName(value))}</span>`;
    const platformName = value => ({internal:'Nội bộ',youtube:'YouTube',facebook:'Facebook'}[value] || value);
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
        if (!response.ok || !data.ok) throw new Error(data.message || `HTTP ${response.status}`);
        return data;
    }

    const agentStatusNames = {
        RECEIVED:'Đã tiếp nhận', INTERPRETING:'Đang hiểu yêu cầu', CLASSIFYING_INTENT:'Đang phân loại ý định', INTENT_REVIEW:'Cần xác nhận ý định', RESOLVING_PRODUCTS:'Đang tìm sản phẩm',
        NEEDS_INPUT:'Cần bạn xác nhận', PLAN_READY:'Đã lập kế hoạch', CAMPAIGN_CREATED:'Đã tạo chương trình',
        NEEDS_ATTENTION:'Cần kiểm tra', CANCELLED:'Đã hủy', COMPLETED:'Hoàn tất'
    };
    const agentStatusName = value => agentStatusNames[value] || statusName(value);

    function renderAgentRuns() {
        if (!agentRuns.length) {
            agentListEl.innerHTML = '<div class="empty"><i class="bi bi-stars" aria-hidden="true"></i><p>Chưa có yêu cầu Agent nào.</p></div>';
            agentDetailEl.hidden = true;
            return;
        }
        if (!activeAgentRunId || !agentRuns.some(item => item._id === activeAgentRunId)) activeAgentRunId = agentRuns[0]._id;
        agentListEl.innerHTML = agentRuns.map(item => { const liveStatus = item.campaign_status || item.status; return `<button type="button" class="agent-run ${item._id === activeAgentRunId ? 'is-active' : ''}" data-agent-run="${escapeHtml(item._id)}"><span class="agent-run-top"><span class="agent-run-brief">${escapeHtml(item.brief)}</span>${badge(liveStatus)}</span><span class="campaign-meta">${escapeHtml(agentStatusName(item.campaign_status || item.current_stage || item.status))} · ${escapeHtml(formatDate(item.updated_at))}</span></button>`; }).join('');
        renderAgentDetail(agentRuns.find(item => item._id === activeAgentRunId));
    }

    function renderAgentDetail(run) {
        if (!run) { agentDetailEl.hidden = true; return; }
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
        const products = resolved.length ? resolved.map(item => `<div class="agent-product"><span><strong>${escapeHtml(item.name)}</strong><br>Mã ${escapeHtml(item.product_id)} · tin cậy ${Math.round(Number(item.score || 0) * 100)}%</span></div>`).join('') : '';
        const candidateMarkup = candidates.length ? `<p class="idol-note">Agent chưa dám tự chọn. Bạn xác nhận một sản phẩm:</p><div class="agent-products">${candidates.map(item => `<div class="agent-product"><span><strong>${escapeHtml(item.name)}</strong><br>Mã ${escapeHtml(item.product_id)} · khớp ${Math.round(Number(item.score || 0) * 100)}%</span><button type="button" data-agent-confirm-product="${escapeHtml(item.product_id)}">Chọn</button></div>`).join('')}</div>` : '';
        const campaignButton = run.campaign_id ? `<button type="button" class="idol-btn primary" data-open-agent-campaign="${escapeHtml(run.campaign_id)}">Mở chương trình</button>` : '';
        const retryButton = run.status === 'NEEDS_ATTENTION' ? `<button type="button" class="idol-btn warn" data-retry-agent="${escapeHtml(run._id)}">Thử lại bước lỗi</button>` : '';
        const cancelButton = !run.campaign_id && !['CANCELLED','COMPLETED'].includes(run.status) ? `<button type="button" class="idol-btn secondary" data-cancel-agent="${escapeHtml(run._id)}">Hủy Agent</button>` : '';
        agentDetailEl.hidden = false;
        const liveStatus = run.campaign_status || run.status;
        const campaignError = run.campaign_error_message || run.error_message;
        agentDetailEl.innerHTML = `<div class="detail-heading"><span class="studio-eyebrow">AGENT RUN</span>${badge(liveStatus)}</div><h3>${escapeHtml(agentStatusName(liveStatus))}</h3><p class="campaign-meta">${escapeHtml(run.brief)}</p>${intentMarkup}${campaignError ? `<div class="studio-message">${escapeHtml(campaignError)}</div>` : ''}<div class="agent-timeline"><div class="agent-stage"><i class="bi bi-check2-circle"></i><span>${escapeHtml(agentStatusName(run.campaign_status || run.current_stage || run.status))}</span></div>${schedule}</div>${products ? `<div class="agent-products">${products}</div>` : ''}${candidateMarkup}${warnings.map(message => `<div class="agent-warning">${escapeHtml(message)}</div>`).join('')}<div class="agent-actions">${campaignButton}${retryButton}${cancelButton}</div>`;
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
        const brief = $('agentBrief').value.trim();
        if (brief.length < 12) { agentNotify('Hãy mô tả yêu cầu ít nhất 12 ký tự.'); return $('agentBrief').focus(); }
        const policy = root.querySelector('input[name="agentPolicy"]:checked')?.value || 'auto_preview';
        button.disabled = true;
        button.textContent = 'Agent đang tiếp nhận…';
        agentNotify('');
        try {
            const result = await api('index.php?r=admin_ai_idol_agent_create', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({brief, autonomy_policy:policy, idempotency_key:`studio:${Date.now()}`})});
            activeAgentRunId = result.run_id;
            $('agentBrief').value = '';
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
        const productButton = event.target.closest('[data-agent-confirm-product]');
        const openButton = event.target.closest('[data-open-agent-campaign]');
        const retryButton = event.target.closest('[data-retry-agent]');
        const cancelButton = event.target.closest('[data-cancel-agent]');
        if (openButton) {
            showStudioFlow('manual');
            await refreshCampaigns();
            return loadDetail(openButton.dataset.openAgentCampaign);
        }
        const runId = productButton ? activeAgentRunId : retryButton?.dataset.retryAgent || cancelButton?.dataset.cancelAgent;
        if (!runId) return;
        const route = productButton ? 'admin_ai_idol_agent_confirm' : retryButton ? 'admin_ai_idol_agent_retry' : 'admin_ai_idol_agent_cancel';
        const body = productButton ? {product_ids:[productButton.dataset.agentConfirmProduct]} : {};
        [productButton, retryButton, cancelButton].filter(Boolean).forEach(button => { button.disabled = true; });
        try {
            await api(`index.php?r=${route}&run_id=${encodeURIComponent(runId)}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
            await refreshAgentRuns();
        } catch (error) { agentNotify('Không thể cập nhật Agent: ' + error.message); }
    });
    async function uploadAsset(kind, file) {
        if (!file) return '';
        const body = new FormData(); body.append('kind', kind); body.append('file', file);
        const result = await api('index.php?r=admin_ai_idol_upload_asset', {method:'POST', body});
        return result.filename || '';
    }
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
        if (!scheduledAt || !Number.isFinite(new Date(scheduledAt).getTime()) || new Date(scheduledAt).getTime() <= Date.now()) return invalid(3, 'Chọn giờ phát nằm trong tương lai.', 'scheduledAt');
        if (!Number.isInteger(duration) || duration < 1 || duration > 720) return invalid(3, 'Thời lượng phát từ 1 đến 720 phút.', 'durationMinutes');
        if (!platforms.length) return invalid(3, 'Chọn ít nhất một nơi phát.', 'durationMinutes');
        // Capture the form before upload; employees can keep browsing their library.
        const backgroundFile = $('backgroundFile').files?.[0];
        const payload = {name:$('campaignName').value.trim(), product_ids:productIds, scheduled_at:scheduledAt, platforms, duration_minutes:duration, content_mode:$('contentMode').value, configuration:{format:$('videoFormat').value, length_mode:'Short (30-60s)', avatar_mode:isSyna() ? 'syna_3d' : isVideoFile(avatarFile) ? 'motion_video' : 'photo'}};
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
        const signature = JSON.stringify([campaigns, campaignFilter, activeCampaignId]);
        if (signature === listSignature) return; // Keep keyboard focus and scroll on unchanged polls.
        listSignature = signature;
        $('totalCampaigns').textContent = campaigns.length;
        $('libraryCount').textContent = campaigns.length;
        $('approvalCount').textContent = campaigns.filter(item => item.status === 'AWAITING_APPROVAL').length;
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
            return `<button type="button" class="campaign ${item._id === activeCampaignId ? 'active' : ''}" data-id="${escapeHtml(item._id)}" aria-pressed="${item._id === activeCampaignId}"><span class="campaign-icon"><i class="bi bi-${icon}" aria-hidden="true"></i></span><span class="campaign-copy"><span class="campaign-top"><span class="campaign-title">${escapeHtml(item.name)}</span>${badge(item.status)}</span><span class="campaign-meta">${escapeHtml(formatDate(item.scheduled_at))} · ${total} sản phẩm · ${(item.platforms || []).map(value => escapeHtml(platformName(value))).join(', ')}</span><span class="meter" role="progressbar" aria-label="Video đã tạo" aria-valuenow="${Math.min(total, ready)}" aria-valuemin="0" aria-valuemax="${total}"><span style="width:${Math.min(100, Math.max(0, ready / total * 100))}%"></span></span></span></button>`;
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
    function renderSegment(segment) {
        const errorText = String(segment.error_message || '');
        const errorSummary = errorText.includes('CUDA out of memory') ? 'GPU hết bộ nhớ khi tạo video.' : (errorText.split('\n')[0] || '').slice(0, 180);
        const errorMarkup = errorText ? `<details class="segment-error"><summary>${escapeHtml(errorSummary)}</summary><pre>${escapeHtml(errorText)}</pre></details>` : '';
        const awaiting = segment.status === 'AWAITING_APPROVAL' || segment.script_status === 'PENDING_APPROVAL';
        const scriptText = String(awaiting && scriptDrafts.has(segment.job_id) ? scriptDrafts.get(segment.job_id) : segment.script_text || '');
        const title = productNames.get(String(segment.product_id)) || `Sản phẩm ${segment.product_id}`;
        const scriptMarkup = scriptText ? `<details class="script-review" ${awaiting ? 'open' : ''}><summary>${awaiting ? 'Chỉnh sửa & duyệt kịch bản' : 'Xem kịch bản đã duyệt'}</summary><label for="script-${escapeHtml(segment.job_id)}">${escapeHtml(title)}</label><textarea class="script-editor" data-job="${escapeHtml(segment.job_id)}" id="script-${escapeHtml(segment.job_id)}" ${awaiting ? '' : 'readonly'}>${escapeHtml(scriptText)}</textarea><p class="script-review-note">${awaiting ? 'Sửa trực tiếp lời giới thiệu. Giọng nói và video chỉ được tạo sau khi bạn chấp nhận.' : 'Kịch bản đã duyệt cho video này.'}</p>${awaiting ? `<button type="button" class="idol-btn primary" data-approve-script="${escapeHtml(segment.job_id)}" ${approvingJobs.has(segment.job_id) ? 'disabled' : ''}>${approvingJobs.has(segment.job_id) ? 'Đang gửi duyệt…' : 'Chấp nhận & tạo video'}</button>` : ''}</details>` : '';
        return `<div class="segment"><div class="segment-heading"><span>Sản phẩm ${escapeHtml(segment.product_id)}</span>${badge(segment.status)}</div>${errorMarkup}${scriptMarkup}</div>`;
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
            const signature = JSON.stringify([item._id,item.status,item.master_video_path,item.error_message,item.configuration,item.segments]);
            if (sameCampaign && activeDetailSignature === signature) return;
            // Let employees finish typing or submitting; preserve drafts across later updates.
            if (sameCampaign && (detailEl.contains(document.activeElement) && document.activeElement.matches('textarea:not([readonly])') || approvingJobs.size)) return;
            rememberDrafts();
            const oldVideo = sameCampaign ? detailEl.querySelector('video') : null;
            const oldVideoUrl = oldVideo?.getAttribute('src');
            const campaignAvatar = String(item.configuration?.avatar_asset || '');
            const motion = /\.(mp4|webm)$/i.test(campaignAvatar);
            const characterDescription = item.configuration?.avatar_mode === 'syna_3d' ? 'Syna 3D · giọng đọc đã duyệt, sản phẩm thật và bảng thành phần.' : motion ? 'Nhân vật từ video · giữ chuyển động có sẵn trong clip.' : 'Nhân vật từ ảnh tĩnh · miệng và đầu chuyển động nhẹ.';
            const canPreview = Boolean(item.master_video_path) && ['READY','SCHEDULED','STARTING','LIVE','COMPLETED','PARTIAL_FAILURE'].includes(item.status);
            const waiting = item.status === 'AWAITING_APPROVAL' ? 'Kịch bản đã sẵn sàng. Duyệt bên dưới để bắt đầu tạo video.' : ['NEEDS_ATTENTION','FAILED'].includes(item.status) ? 'Video chưa hoàn tất. Xem phần cần kiểm tra bên dưới.' : item.status === 'CANCELLED' ? 'Chương trình này đã được hủy.' : 'Video đang được chuẩn bị. Tiến độ sẽ tự cập nhật.';
            detailEl.innerHTML = `<div class="detail-heading"><span class="studio-eyebrow">${canPreview ? 'XEM CHƯƠNG TRÌNH' : 'CHI TIẾT CHƯƠNG TRÌNH'}</span>${badge(item.status)}</div>
                <h3>${escapeHtml(item.name)}</h3><p class="campaign-meta">Lịch phát ${escapeHtml(formatDate(item.scheduled_at))}</p>
                ${item.error_message ? `<div class="studio-message">${escapeHtml(item.error_message)}</div>` : ''}
                ${canPreview ? `<video id="campaignPreview" controls playsinline preload="metadata" aria-label="Video chương trình" src="index.php?r=admin_ai_idol_stream_campaign&campaign_id=${encodeURIComponent(id)}"></video>` : `<div class="empty"><i class="bi bi-${item.status === 'AWAITING_APPROVAL' ? 'pencil-square' : 'camera-reels'}" aria-hidden="true"></i><p>${waiting}</p></div>`}
                <div class="avatar-mode-note ${motion || item.configuration?.avatar_mode === 'syna_3d' ? 'motion' : 'photo'}">${escapeHtml(characterDescription)}</div>
                <div>${(item.segments || []).map(renderSegment).join('')}</div>
                ${item.status === 'NEEDS_ATTENTION' ? '<div class="detail-actions"><button type="button" class="idol-btn warn" id="retryCampaign">Tạo lại phần bị lỗi</button></div>' : ''}
                <div id="detailMessage" class="studio-message" role="alert" hidden></div>`;
            // Reuse the actual video node instead of resetting playback every poll.
            const newVideo = $('campaignPreview');
            if (oldVideo && newVideo && oldVideoUrl === newVideo.getAttribute('src')) newVideo.replaceWith(oldVideo);
            activeDetailSignature = signature;
        } catch (error) {
            if (requestNumber !== detailRequest) return;
            if (!sameCampaign || !activeDetailSignature) detailEl.innerHTML = `<div class="empty">${escapeHtml(error.message)}</div>`;
            else detailError(error.message);
            activeDetailSignature = '';
        }
    }
    function detailError(message) {
        const target = $('detailMessage');
        if (target) { target.textContent = message; target.hidden = false; }
    }
    detailEl.addEventListener('click', async event => {
        const approveButton = event.target.closest('[data-approve-script]');
        const retryButton = event.target.closest('#retryCampaign');
        const campaignId = activeCampaignId;
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
    const date = new Date(Date.now() + 60 * 60 * 1000);
    date.setMinutes(Math.ceil(date.getMinutes() / 5) * 5, 0, 0);
    $('scheduledAt').value = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    updateSelection();
    refreshCampaigns();
    refreshAgentRuns();
    setInterval(async () => {
        if (document.hidden || pollBusy) return;
        pollBusy = true;
        try { await refreshCampaigns(); await refreshAgentRuns(); if (activeCampaignId) await loadDetail(activeCampaignId); }
        finally { pollBusy = false; }
    }, 5000);
    window.addEventListener('pagehide', () => {
        $('avatarVideoPreview').pause();
    });
})();
