(() => {
    'use strict';
    const root = document.getElementById('idolStudio');
    if (!root) return;
    const $ = id => document.getElementById(id) || root.querySelector(`[data-alias="${id}"]`);
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
        const isPub = flow === 'publication';
        const isManual = !isAgent && !isPub;
        $('manualFlow').hidden = !isManual;
        $('agentFlow').hidden = !isAgent;
        if ($('pubFlow')) $('pubFlow').hidden = !isPub;
        root.querySelectorAll('[data-studio-flow]').forEach(button => {
            const active = button.dataset.studioFlow === flow;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });
        if (isAgent) refreshAgentRuns();
        if (isPub) refreshPublicationVideos();
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
        if (currentStep === 3) syncScheduleConstraints(false);
        if (focus) $(`stepTab${currentStep}`).focus();
    }

    function getLeadMinutes() {
        const count = selectedProducts().length || 1;
        return Math.max(5, count * 8);
    }
    function formatToLocalInput(dateObj) {
        return new Date(dateObj.getTime() - dateObj.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }
    function syncScheduleConstraints(forceReset = false) {
        const lead = getLeadMinutes();
        if ($('leadMinutesBadge')) $('leadMinutesBadge').textContent = lead;
        if ($('leadMinutesText')) $('leadMinutesText').textContent = `${lead} phút`;
        const minDate = new Date(Date.now() + lead * 60 * 1000);
        const input = $('scheduledAt');
        if (!input) return;
        input.min = formatToLocalInput(minDate);
        const currentMs = input.value ? new Date(input.value).getTime() : 0;
        if (forceReset || !currentMs || currentMs < minDate.getTime()) {
            const suggested = new Date(Date.now() + (lead + 5) * 60 * 1000);
            suggested.setMinutes(Math.ceil(suggested.getMinutes() / 5) * 5, 0, 0);
            input.value = formatToLocalInput(suggested);
        }
    }
    $('btnEarliestSchedule')?.addEventListener('click', () => {
        syncScheduleConstraints(true);
        const count = selectedProducts().length || 1;
        notify(`Đã đặt mốc giờ phát sớm nhất (+${getLeadMinutes()} phút để chuẩn bị cho ${count} sản phẩm).`, true);
    });

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
        syncScheduleConstraints(false);
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
        const timeoutMs = options.timeoutMs || (options.body instanceof FormData ? 60000 : 30000);
        const controller = new AbortController();
        const timeoutTimer = setTimeout(() => {
            controller.abort(new Error('Yêu cầu kết nối quá thời gian chờ (timeout). Vui lòng thử lại.'));
        }, timeoutMs);

        let response;
        try {
            response = await fetch(url, { ...options, signal: options.signal || controller.signal });
        } catch (fetchErr) {
            clearTimeout(timeoutTimer);
            if (fetchErr.name === 'AbortError' || fetchErr.message?.includes('timeout')) {
                throw new Error('Yêu cầu kết nối quá thời gian chờ (timeout). Vui lòng thử lại.');
            }
            throw new Error('Không thể kết nối đến máy chủ: ' + (fetchErr.message || 'Lỗi mạng'));
        }
        clearTimeout(timeoutTimer);

        const raw = await response.text();
        let data;
        try { data = raw ? JSON.parse(raw) : {}; }
        catch {
            throw new Error(response.status === 413 ? 'Tệp vượt giới hạn máy chủ. Video nhân vật tối đa 35 MB.' : `Máy chủ trả về phản hồi không hợp lệ (HTTP ${response.status}). Hãy kiểm tra kết nối dịch vụ.`);
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
            if (!agentRuns.length) {
                agentListEl.innerHTML = `<div class="empty"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><p>${escapeHtml(error.message)}</p><button type="button" class="idol-btn secondary retry-agent-btn" style="margin-top:10px;"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Thử lại</button></div>`;
                const btn = agentListEl.querySelector('.retry-agent-btn');
                if (btn) btn.addEventListener('click', () => refreshAgentRuns());
            }
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
        const lead = getLeadMinutes();
        const minAllowedTime = Date.now() + lead * 60 * 1000;
        if (!scheduledAt || !Number.isFinite(new Date(scheduledAt).getTime())) return invalid(3, 'Chọn ngày và giờ phát hợp lệ.', 'scheduledAt');
        if (new Date(scheduledAt).getTime() < minAllowedTime) {
            syncScheduleConstraints(true);
            return invalid(3, `Giờ phát cần cách hiện tại tối thiểu ${lead} phút để chuẩn bị kịch bản và video cho ${productIds.length} sản phẩm. Đã tự động gợi ý mốc giờ an toàn tiếp theo.`, 'scheduledAt');
        }
        if (!Number.isInteger(duration) || duration < 1 || duration > 720) return invalid(3, 'Thời lượng phát từ 1 đến 720 phút.', 'durationMinutes');
        if (!platforms.length) return invalid(3, 'Chọn ít nhất một nơi phát.', 'durationMinutes');
        // Capture the form before upload; employees can keep browsing their library.
        const backgroundFile = $('backgroundFile').files?.[0];
        const payload = {name:$('campaignName').value.trim(), product_ids:productIds, scheduled_at:scheduledAt, auto_adjust_schedule:true, platforms, duration_minutes:duration, content_mode:$('contentMode').value, configuration:{format:$('videoFormat').value, length_mode:'Short (30-60s)', avatar_mode:isSyna() ? 'syna_3d' : isVideoFile(avatarFile) ? 'motion_video' : 'photo'}};
        createButton.disabled = true;
        createButton.textContent = 'Đang chuẩn bị…';
        notify('');
        try {
            // Required avatar uploads first, avoiding an orphan background on failure.
            payload.configuration.avatar_asset = payload.configuration.avatar_mode === 'syna_3d' ? '' : await uploadAsset('avatar', avatarFile);
            payload.configuration.background_asset = await uploadAsset('background', backgroundFile);
            const result = await api('index.php?r=admin_ai_idol_create_campaign', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            if (!result || !result.campaign_id) {
                throw new Error(result?.message || 'Máy chủ không trả về mã chương trình hợp lệ.');
            }
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
            $('syncStatus').innerHTML = '<span class="sync-dot"></span> Mất kết nối AI Service';
            if (!campaigns.length) {
                listEl.innerHTML = `<div class="empty"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><p>${escapeHtml(error.message)}</p><button type="button" class="idol-btn secondary retry-campaigns-btn" style="margin-top:10px;"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Thử lại</button></div>`;
                const btn = listEl.querySelector('.retry-campaigns-btn');
                if (btn) btn.addEventListener('click', () => refreshCampaigns());
                listSignature = '';
            }
        } finally { refreshBusy = false; $('refreshCampaigns').disabled = false; }
    }
    $('refreshCampaigns').addEventListener('click', async () => { await refreshCampaigns(); if (activeCampaignId) await loadDetail(activeCampaignId); });

    function rememberDrafts() {
        detailEl.querySelectorAll('.script-editor:not([readonly])').forEach(editor => scriptDrafts.set(editor.dataset.job, editor.value));
    }
    detailEl.addEventListener('input', event => {
        if (event.target.matches('.script-editor:not([readonly])')) scriptDrafts.set(event.target.dataset.job, event.target.value);
    });
    const STAGE_CONFIG = {
        CREATED: { percent: 5, label: 'Khởi tạo tác vụ', step: 1, etaSeconds: 90 },
        COLLECTING_PRODUCT: { percent: 12, label: 'Thu thập thông tin sản phẩm', step: 1, etaSeconds: 85 },
        RETRIEVING_KNOWLEDGE: { percent: 20, label: 'Truy xuất kho kiến thức & RAG', step: 1, etaSeconds: 80 },
        CRAFTING_SCRIPT: { percent: 32, label: 'Đang soạn kịch bản bán hàng (Groq AI)', step: 2, etaSeconds: 70 },
        VALIDATING_SCRIPT: { percent: 38, label: 'Kiểm tra & chuẩn hóa kịch bản', step: 2, etaSeconds: 65 },
        AWAITING_SCRIPT_APPROVAL: { percent: 45, label: 'Chờ duyệt kịch bản bên dưới', step: 2, etaSeconds: 0 },
        SCRIPT_APPROVED: { percent: 50, label: 'Kịch bản đã duyệt · Chuẩn bị tạo video', step: 3, etaSeconds: 50 },
        SCRIPT_AUTO_APPROVED: { percent: 50, label: 'Kịch bản đã duyệt tự động', step: 3, etaSeconds: 50 },
        GENERATING_VOICE: { percent: 65, label: 'Đang tổng hợp giọng nói tiếng Việt (Piper TTS)', step: 3, etaSeconds: 40 },
        GENERATING_AVATAR: { percent: 75, label: 'Đang tạo biểu cảm khuôn mặt', step: 4, etaSeconds: 30 },
        RENDERING: { percent: 85, label: 'Đang kết xuất hoạt họa Mascot Syna 3D', step: 4, etaSeconds: 25 },
        ASSEMBLING: { percent: 95, label: 'Đang ghép nối & hoàn tất video MP4', step: 5, etaSeconds: 8 },
        READY: { percent: 100, label: 'Video đã sẵn sàng', step: 5, etaSeconds: 0 },
        FAILED: { percent: 0, label: 'Gặp sự cố khi xử lý', step: 0, etaSeconds: 0 }
    };

    function getStageInfo(stage, status) {
        if (status === 'READY') return STAGE_CONFIG.READY;
        if (status === 'FAILED') return STAGE_CONFIG.FAILED;
        if (status === 'AWAITING_APPROVAL') return STAGE_CONFIG.AWAITING_SCRIPT_APPROVAL;
        return STAGE_CONFIG[stage] || (status === 'IN_PROGRESS' ? { percent: 60, label: 'Đang xử lý nội dung', step: 3, etaSeconds: 45 } : { percent: 10, label: 'Đang chuẩn bị', step: 1, etaSeconds: 80 });
    }

    function formatTimeDuration(totalSeconds) {
        const sec = Math.max(0, Math.floor(totalSeconds));
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    function formatEtaText(etaSeconds, isAwaitingApproval) {
        if (isAwaitingApproval) return 'Duyệt kịch bản bên dưới để tạo video';
        if (etaSeconds <= 0) return 'Sắp hoàn tất (~vài giây)';
        if (etaSeconds < 30) return `Còn khoảng ~${Math.round(etaSeconds)} giây`;
        if (etaSeconds < 60) return `Còn khoảng ~${Math.round(etaSeconds)} giây`;
        const mins = Math.ceil(etaSeconds / 60);
        return `Còn khoảng ~${mins} phút`;
    }

    let progressTickerInterval = null;
    let liveProgressState = null;

    function stopProgressTicker() {
        if (progressTickerInterval) {
            clearInterval(progressTickerInterval);
            progressTickerInterval = null;
        }
    }

    function startProgressTicker() {
        stopProgressTicker();
        if (!liveProgressState || liveProgressState.isReady) return;
        progressTickerInterval = setInterval(() => {
            if (!liveProgressState || liveProgressState.isReady) {
                stopProgressTicker();
                return;
            }
            const now = Date.now();
            const elapsed = Math.max(0, Math.floor((now - liveProgressState.startTime) / 1000));
            const elapsedEl = $('liveProgressElapsedClock');
            if (elapsedEl) elapsedEl.textContent = formatTimeDuration(elapsed);

            if (liveProgressState.etaSeconds > 5 && !liveProgressState.isAwaiting) {
                liveProgressState.etaSeconds = Math.max(5, liveProgressState.etaSeconds - 1);
                const etaEl = $('liveProgressEtaClock');
                if (etaEl) etaEl.textContent = formatEtaText(liveProgressState.etaSeconds, liveProgressState.isAwaiting);
            }

            if (liveProgressState.basePercent >= 80 && liveProgressState.basePercent < 95 && !liveProgressState.isAwaiting) {
                const subProgress = Math.min(94, liveProgressState.basePercent + Math.floor(elapsed / 12));
                if (subProgress > liveProgressState.currentPercent) {
                    liveProgressState.currentPercent = subProgress;
                    const fillEl = $('liveProgressBarFill');
                    if (fillEl) fillEl.style.width = `${subProgress}%`;
                    const percentEl = $('liveProgressPercentText');
                    if (percentEl) percentEl.innerHTML = `Đã chạy: <strong>${subProgress}%</strong>`;
                    const remainEl = $('liveProgressRemainText');
                    if (remainEl) remainEl.innerHTML = `Còn <strong>${Math.max(0, 100 - subProgress)}%</strong> nữa sẽ xong`;
                }
            }
        }, 1000);
    }

    function renderProgressCard(item) {
        if (['NEEDS_ATTENTION','FAILED'].includes(item.status)) {
            return `<div class="empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>Video chưa hoàn tất. Xem phần cần kiểm tra bên dưới.</p></div>`;
        }
        if (item.status === 'CANCELLED') {
            return `<div class="empty"><i class="bi bi-x-circle" aria-hidden="true"></i><p>Chương trình này đã được hủy.</p></div>`;
        }

        const segments = item.segments || [];
        const isReady = item.status === 'READY' || (item.progress && item.progress.ready === item.progress.total && item.progress.total > 0);
        const isAwaiting = item.status === 'AWAITING_APPROVAL' || segments.some(s => s.status === 'AWAITING_APPROVAL' || s.script_status === 'PENDING_APPROVAL');
        
        let sumPercent = 0;
        let activeStageLabel = 'Đang chuẩn bị';
        let activeStep = 1;
        let maxEta = 30;

        if (isReady) {
            sumPercent = 100;
            activeStageLabel = 'Video đã sẵn sàng';
            activeStep = 5;
            maxEta = 0;
        } else if (segments.length) {
            segments.forEach(seg => {
                const info = getStageInfo(seg.current_stage, seg.job_status || seg.status);
                sumPercent += info.percent;
                if (info.etaSeconds > maxEta) maxEta = info.etaSeconds;
                if (info.step >= activeStep) {
                    activeStep = info.step;
                    activeStageLabel = info.label;
                }
            });
            sumPercent = Math.round(sumPercent / segments.length);
        } else {
            sumPercent = item.status === 'ASSEMBLING' ? 95 : 20;
            activeStageLabel = item.status === 'ASSEMBLING' ? 'Đang ghép nối video MP4' : 'Đang xử lý dữ liệu';
            activeStep = item.status === 'ASSEMBLING' ? 5 : 2;
        }

        if (item.status === 'ASSEMBLING') {
            sumPercent = Math.max(sumPercent, 95);
            activeStep = 5;
            activeStageLabel = 'Đang ghép nối & hoàn tất video MP4';
            maxEta = 8;
        }

        const remainingPercent = Math.max(0, 100 - sumPercent);
        const startTime = new Date(item.updated_at || item.created_at || Date.now()).getTime();
        const elapsed = Math.max(0, Math.floor((Date.now() - startTime) / 1000));
        
        liveProgressState = {
            startTime,
            basePercent: sumPercent,
            currentPercent: sumPercent,
            etaSeconds: maxEta,
            isAwaiting,
            isReady
        };

        const steps = [
            { num: 1, label: '1. Dữ liệu & RAG' },
            { num: 2, label: '2. Soạn kịch bản' },
            { num: 3, label: '3. Giọng đọc AI' },
            { num: 4, label: '4. Render Syna 3D' },
            { num: 5, label: '5. Hoàn tất MP4' }
        ];

        const stepperHtml = steps.map(s => {
            const isDone = s.num < activeStep || isReady;
            const isActive = s.num === activeStep && !isReady;
            const icon = isDone ? '<i class="bi bi-check2"></i>' : isActive ? '<i class="bi bi-arrow-repeat spin"></i>' : `<span>${s.num}</span>`;
            return `<div class="progress-step-item ${isDone ? 'is-done' : ''} ${isActive ? 'is-active' : ''}">
                <div class="progress-step-dot">${icon}</div>
                <span>${escapeHtml(s.label)}</span>
            </div>`;
        }).join('');

        return `<div class="studio-progress-card" id="studioProgressCard">
            <div class="progress-card-top">
                <div class="progress-badges-wrap">
                    <span class="progress-badge-main">
                        <i class="bi ${isReady ? 'bi-check-circle-fill' : 'bi-arrow-repeat spin'}"></i>
                        <span id="liveProgressPercentText">Đã chạy: <strong>${sumPercent}%</strong></span>
                    </span>
                    <span class="progress-badge-remain">
                        <i class="bi bi-hourglass-split"></i>
                        <span id="liveProgressRemainText">${isReady ? 'Hoàn tất' : `Còn <strong>${remainingPercent}%</strong> nữa sẽ xong`}</span>
                    </span>
                </div>
                <div class="progress-time-group">
                    <span><i class="bi bi-stopwatch"></i> Thời gian chạy: <strong id="liveProgressElapsedClock">${formatTimeDuration(elapsed)}</strong></span>
                    <span><i class="bi bi-clock-history"></i> Dự kiến: <strong id="liveProgressEtaClock">${escapeHtml(formatEtaText(maxEta, isAwaiting))}</strong></span>
                </div>
            </div>

            <div class="progress-bar-track" role="progressbar" aria-valuenow="${sumPercent}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar-fill" id="liveProgressBarFill" style="width: ${sumPercent}%;">
                    ${isReady ? '' : '<span class="progress-bar-shimmer"></span>'}
                </div>
            </div>

            <div class="progress-stage-current">
                <i class="bi ${isReady ? 'bi-check2-circle' : isAwaiting ? 'bi-pencil-square' : 'bi-lightning-charge-fill'}"></i>
                <span>Giai đoạn hiện tại: <strong id="liveProgressStageLabel">${escapeHtml(activeStageLabel)}</strong> (${sumPercent}%)</span>
            </div>

            <div class="progress-stepper">
                ${stepperHtml}
            </div>
        </div>`;
    }

    function renderSegment(segment) {
        const errorText = String(segment.error_message || '');
        const errorSummary = errorText.includes('CUDA out of memory') ? 'GPU hết bộ nhớ khi tạo video.' : (errorText.split('\n')[0] || '').slice(0, 180);
        const errorMarkup = errorText ? `<details class="segment-error"><summary>${escapeHtml(errorSummary)}</summary><pre>${escapeHtml(errorText)}</pre></details>` : '';
        const awaiting = segment.status === 'AWAITING_APPROVAL' || segment.script_status === 'PENDING_APPROVAL';
        const scriptText = String(awaiting && scriptDrafts.has(segment.job_id) ? scriptDrafts.get(segment.job_id) : segment.script_text || '');
        const title = productNames.get(String(segment.product_id)) || `Sản phẩm ${segment.product_id}`;
        const scriptMarkup = scriptText ? `<details class="script-review" ${awaiting ? 'open' : ''}><summary>${awaiting ? 'Chỉnh sửa & duyệt kịch bản' : 'Xem kịch bản đã duyệt'}</summary><label for="script-${escapeHtml(segment.job_id)}">${escapeHtml(title)}</label><textarea class="script-editor" data-job="${escapeHtml(segment.job_id)}" id="script-${escapeHtml(segment.job_id)}" ${awaiting ? '' : 'readonly'}>${escapeHtml(scriptText)}</textarea><p class="script-review-note">${awaiting ? 'Sửa trực tiếp lời giới thiệu. Giọng nói và video chỉ được tạo sau khi bạn chấp nhận.' : 'Kịch bản đã duyệt cho video này.'}</p>${awaiting ? `<button type="button" class="idol-btn primary" data-approve-script="${escapeHtml(segment.job_id)}" ${approvingJobs.has(segment.job_id) ? 'disabled' : ''}>${approvingJobs.has(segment.job_id) ? 'Đang gửi duyệt…' : 'Chấp nhận & tạo video'}</button>` : ''}</details>` : '';
        const stageInfo = getStageInfo(segment.current_stage, segment.job_status || segment.status);
        const stageTag = (!['READY','FAILED'].includes(segment.status)) ? `<span class="segment-progress-tag ${segment.current_stage === 'RENDERING' ? 'rendering' : ''}"><i class="bi bi-${segment.job_status === 'READY' ? 'check-circle' : 'arrow-repeat spin'}"></i> ${escapeHtml(stageInfo.label)} (${stageInfo.percent}%)</span>` : '';
        const pubQuickBtn = ((segment.job_status === 'READY' || segment.status === 'READY') && segment.video_id)
            ? `<button type="button" class="text-button manage-pub-btn" data-video-id="${escapeHtml(segment.video_id)}" style="margin-left:auto;font-size:11.5px;display:inline-flex;align-items:center;gap:4px;"><i class="bi bi-box-arrow-up-right"></i> Xuất bản lên sản phẩm</button>`
            : '';
        return `<div class="segment"><div class="segment-heading"><div style="display:flex;align-items:center;gap:6px;"><span>Sản phẩm ${escapeHtml(segment.product_id)}</span>${stageTag}</div>${pubQuickBtn}${badge(segment.status)}</div>${errorMarkup}${scriptMarkup}</div>`;
    }
    async function loadDetail(id) {
        const requestNumber = ++detailRequest;
        const sameCampaign = activeCampaignId === id;
        rememberDrafts();
        activeCampaignId = id;
        renderCampaigns();
        $('libraryIdle').hidden = true;
        detailEl.hidden = false;
        if (!sameCampaign) {
            stopProgressTicker();
            detailEl.innerHTML = '<div class="empty" role="status">Đang mở chương trình…</div>';
        }
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
            detailEl.innerHTML = `<div class="detail-heading"><span class="studio-eyebrow">${canPreview ? 'XEM CHƯƠNG TRÌNH' : 'CHI TIẾT CHƯƠNG TRÌNH'}</span>${badge(item.status)}</div>
                <h3>${escapeHtml(item.name)}</h3><p class="campaign-meta">Lịch phát ${escapeHtml(formatDate(item.scheduled_at))}</p>
                ${item.error_message ? `<div class="studio-message">${escapeHtml(item.error_message)}</div>` : ''}
                ${canPreview ? `<video id="campaignPreview" controls playsinline preload="metadata" aria-label="Video chương trình" src="index.php?r=admin_ai_idol_stream_campaign&campaign_id=${encodeURIComponent(id)}"></video>` : renderProgressCard(item)}
                <div class="avatar-mode-note ${motion || item.configuration?.avatar_mode === 'syna_3d' ? 'motion' : 'photo'}">${escapeHtml(characterDescription)}</div>
                <div>${(item.segments || []).map(renderSegment).join('')}</div>
                ${item.status === 'NEEDS_ATTENTION' ? '<div class="detail-actions"><button type="button" class="idol-btn warn" id="retryCampaign">Tạo lại phần bị lỗi</button></div>' : ''}
                <div id="detailMessage" class="studio-message" role="alert" hidden></div>`;
            if (canPreview) {
                stopProgressTicker();
                const newVideo = $('campaignPreview');
                if (oldVideo && newVideo && oldVideoUrl === newVideo.getAttribute('src')) newVideo.replaceWith(oldVideo);
            } else {
                startProgressTicker();
            }
            activeDetailSignature = signature;
        } catch (error) {
            if (requestNumber !== detailRequest) return;
            stopProgressTicker();
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
    // ========================================================
    // Phase V1.1: Product Video Publication & Modal Management
    // ========================================================
    let pubVideos = [];
    let pubFilter = 'all';
    let pubSearchQuery = '';
    let pubRefreshBusy = false;
    let editingVideo = null;
    let modalSelectedProducts = new Set();
    let modalProductsList = Array.isArray(window.AI_IDOL_PRODUCTS) ? [...window.AI_IDOL_PRODUCTS] : [];
    let modalSearchDebounce = null;

    async function refreshPublicationVideos() {
        if (pubRefreshBusy) return;
        pubRefreshBusy = true;
        const refreshBtn = $('refreshPubVideos');
        if (refreshBtn) refreshBtn.disabled = true;
        try {
            const result = await api('index.php?r=admin_ai_idol_product_videos');
            pubVideos = result.videos || [];
            renderPublicationVideos();
        } catch (err) {
            const msgEl = $('pubVideoMessage');
            if (msgEl) {
                msgEl.textContent = 'Không thể tải danh sách video: ' + err.message;
                msgEl.className = 'studio-message';
                msgEl.hidden = false;
            }
        } finally {
            pubRefreshBusy = false;
            if (refreshBtn) refreshBtn.disabled = false;
        }
    }

    function renderPublicationVideos() {
        const grid = $('pubVideoGrid');
        if (!grid) return;

        const totalCount = pubVideos.length;
        const publishedCount = pubVideos.filter(v => v.is_published).length;
        const unpublishedCount = totalCount - publishedCount;

        if ($('pubCountAll')) $('pubCountAll').textContent = `(${totalCount})`;
        if ($('pubCountPublished')) $('pubCountPublished').textContent = `(${publishedCount})`;
        if ($('pubCountUnpublished')) $('pubCountUnpublished').textContent = `(${unpublishedCount})`;

        const query = normalize(pubSearchQuery);
        const filtered = pubVideos.filter(v => {
            if (pubFilter === 'published' && !v.is_published) return false;
            if (pubFilter === 'unpublished' && v.is_published) return false;
            if (query) {
                const searchStr = normalize(`${v.title || ''} ${v.campaign_name || ''} ${(v.associated_product_ids || []).join(' ')}`);
                if (!searchStr.includes(query)) return false;
            }
            return true;
        });

        if (!filtered.length) {
            const emptyText = query ? 'Không tìm thấy video phù hợp từ khóa.' : (pubFilter === 'published' ? 'Chưa có video nào được xuất bản lên sản phẩm.' : 'Không có video nào trong danh sách.');
            grid.innerHTML = `<div class="empty" style="grid-column: 1 / -1;"><i class="bi bi-film" aria-hidden="true"></i><p>${escapeHtml(emptyText)}</p></div>`;
            return;
        }

        grid.innerHTML = filtered.map(v => {
            const isReady = v.render_status === 'READY';
            const isPublished = Boolean(v.is_published);
            const durationFormatted = formatTimeDuration(v.duration || 0);
            const posterSrc = v.thumbnail_url || 'favicon.ico';
            const linkedCount = Array.isArray(v.associated_product_ids) ? v.associated_product_ids.length : 0;
            const primaryPid = v.primary_product_id || (v.associated_product_ids?.[0] ?? '');

            const statusBadge = isPublished
                ? '<span class="pub-badge-status published"><i class="bi bi-check-circle-fill"></i> Đã xuất bản</span>'
                : '<span class="pub-badge-status unpublished"><i class="bi bi-dash-circle"></i> Chưa xuất bản</span>';

            const renderBadge = isReady
                ? '<span class="small-tag" style="background:#eaf5ea;color:#185334;border:1px solid #cce3cb;"><i class="bi bi-check2"></i> READY</span>'
                : '<span class="small-tag" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;"><i class="bi bi-arrow-repeat spin"></i> Đang render</span>';

            const rc = v.reward_campaign;
            let rewardBadge = '';
            let rewardMetaBox = '';
            if (rc) {
                const discountText = rc.discount_type === 'percentage' ? `${rc.discount_value}%` : `${Number(rc.discount_value).toLocaleString('vi-VN')} đ`;
                if (rc.is_active) {
                    rewardBadge = `<span class="pub-badge-status reward-active"><i class="bi bi-gift-fill"></i> Voucher: ${discountText}</span>`;
                } else {
                    rewardBadge = `<span class="pub-badge-status reward-inactive"><i class="bi bi-gift"></i> Voucher (Tạm dừng)</span>`;
                }

                // Only display issuance count when REAL issuance data exists (> 0)
                if (Number(rc.issued_count || 0) > 0) {
                    rewardMetaBox = `<div class="pub-card-reward-box"><span class="text-muted"><i class="bi bi-ticket-perforated"></i> Đã phát thực tế:</span><strong>${rc.issued_count} / ${rc.total_quota}</strong></div>`;
                }
            }

            return `
            <div class="pub-card" data-video-id="${escapeHtml(v.video_id)}">
                <div class="pub-card-preview">
                    <img src="${escapeHtml(posterSrc)}" alt="" loading="lazy" onerror="this.src='favicon.ico'">
                    <span class="pub-card-format">${escapeHtml(v.format || '16:9')}</span>
                    <span class="pub-card-duration">${durationFormatted}</span>
                </div>
                <div class="pub-card-body">
                    <div class="pub-card-tags">
                        ${statusBadge}
                        ${renderBadge}
                        ${rewardBadge}
                    </div>
                    <h3 class="pub-card-title" title="${escapeHtml(v.title || '')}">${escapeHtml(v.title || 'Syna 3D - Giới thiệu sản phẩm')}</h3>
                    <div class="pub-card-campaign">
                        <i class="bi bi-collection-play"></i> <span>${escapeHtml(v.campaign_name || 'Chiến dịch AI Idol')}</span>
                    </div>
                    <div class="pub-card-meta">
                        <span class="pub-card-products-pill">
                            <i class="bi bi-link-45deg"></i>
                            <strong>${linkedCount}</strong> sản phẩm liên kết
                        </span>
                        <span class="text-muted" style="font-size:11px;">Ưu tiên: <strong>${v.display_priority || 10}</strong></span>
                    </div>
                    ${rewardMetaBox}
                </div>
                <div class="pub-card-footer" style="flex-wrap: wrap; gap: 6px;">
                    <button type="button" class="idol-btn secondary manage-pub-btn" data-video-id="${escapeHtml(v.video_id)}" ${!isReady ? 'disabled title="Video chưa render thành công không được xuất bản."' : ''}>
                        <i class="bi bi-sliders me-1"></i> Quản lý xuất bản
                    </button>
                    ${isPublished && isReady ? `
                    <button type="button" class="idol-btn primary config-reward-btn" data-video-id="${escapeHtml(v.video_id)}" title="Cấu hình voucher xem video">
                        <i class="bi bi-gift me-1"></i> Cấu hình voucher xem video
                    </button>` : ''}
                    ${isPublished && primaryPid ? `
                    <a href="index.php?r=chitiet&id=${encodeURIComponent(primaryPid)}" target="_blank" class="idol-btn secondary" style="width: auto; padding: 8px 12px;" title="Xem video trên trang chi tiết sản phẩm">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    function openPublicationModal(videoId) {
        const video = pubVideos.find(v => v.video_id === videoId);
        if (!video) return;
        editingVideo = video;

        modalSelectedProducts = new Set((video.associated_product_ids || []).map(String).filter(Boolean));
        if (!modalSelectedProducts.size && video.default_product_id) {
            modalSelectedProducts.add(String(video.default_product_id));
        }

        const backdrop = $('pubModalBackdrop');
        const alertBox = $('pubModalAlert');
        if (alertBox) { alertBox.hidden = true; alertBox.textContent = ''; }

        if ($('pubModalTitle')) $('pubModalTitle').textContent = `Quản lý xuất bản: ${video.title || 'Video AI Idol'}`;
        if ($('pubModalVideoTitle')) $('pubModalVideoTitle').value = video.title || `Syna 3D - Giới thiệu sản phẩm`;
        if ($('pubModalCampaignName')) $('pubModalCampaignName').textContent = video.campaign_name || 'Chương trình AI Idol';
        if ($('pubModalDurationBadge')) $('pubModalDurationBadge').textContent = formatTimeDuration(video.duration || 0);
        if ($('pubModalPriority')) $('pubModalPriority').value = video.display_priority || 10;

        const posterImg = $('pubModalPoster');
        if (posterImg) {
            posterImg.src = video.thumbnail_url || 'favicon.ico';
        }

        const statusBadge = $('pubModalStatusBadge');
        if (statusBadge) {
            statusBadge.className = `pub-badge-status ${video.is_published ? 'published' : 'unpublished'}`;
            statusBadge.innerHTML = video.is_published 
                ? '<i class="bi bi-check-circle-fill"></i> Đang xuất bản trên PDP' 
                : '<i class="bi bi-dash-circle"></i> Chưa xuất bản (Nháp)';
        }

        const unpublishBtn = $('pubModalUnpublishBtn');
        if (unpublishBtn) {
            unpublishBtn.hidden = !video.is_published;
        }

        if ($('pubModalProductSearch')) $('pubModalProductSearch').value = '';
        renderModalProductList();
        renderModalSelectedChips();
        updateModalPrimaryDropdown();

        if (backdrop) backdrop.hidden = false;
    }

    function closePublicationModal() {
        const backdrop = $('pubModalBackdrop');
        if (backdrop) backdrop.hidden = true;
        editingVideo = null;
    }

    function renderModalProductList(searchTerm = '') {
        const listEl = $('pubModalProductList');
        if (!listEl) return;

        const term = normalize(searchTerm.trim());
        const filtered = modalProductsList.filter(p => {
            if (!term) return true;
            return normalize(`${p.id} ${p.name}`).includes(term);
        });

        if (!filtered.length) {
            listEl.innerHTML = '<div class="empty compact p-3 text-center text-muted"><p>Không tìm thấy sản phẩm phù hợp.</p></div>';
            return;
        }

        listEl.innerHTML = filtered.map(p => {
            const isChecked = modalSelectedProducts.has(String(p.id));
            const formattedPrice = Number(p.price || 0).toLocaleString('vi-VN') + ' đ';
            return `
            <label class="pub-product-item ${isChecked ? 'is-selected' : ''}">
                <input type="checkbox" class="pub-modal-prod-check" value="${escapeHtml(p.id)}" ${isChecked ? 'checked' : ''}>
                <div class="pub-product-thumb">
                    <img src="${escapeHtml(p.image || 'favicon.ico')}" alt="" loading="lazy" onerror="this.src='favicon.ico'">
                </div>
                <div class="pub-product-info">
                    <span class="pub-product-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</span>
                    <span class="pub-product-code">Mã SP: <strong>${escapeHtml(p.id)}</strong></span>
                </div>
                <div class="pub-product-price">${formattedPrice}</div>
            </label>`;
        }).join('');
    }

    function renderModalSelectedChips() {
        const chipsWrap = $('pubModalSelectedChips');
        const countEl = $('pubModalSelectedCount');
        const count = modalSelectedProducts.size;

        if (countEl) countEl.textContent = `${count} đã chọn`;
        if (!chipsWrap) return;

        if (!count) {
            chipsWrap.hidden = true;
            chipsWrap.innerHTML = '';
            return;
        }

        chipsWrap.hidden = false;
        const chipsHtml = Array.from(modalSelectedProducts).map(pid => {
            const pInfo = modalProductsList.find(p => String(p.id) === String(pid));
            const pName = pInfo ? pInfo.name : `SP #${pid}`;
            return `
            <span class="pub-chip-item">
                <span>[${escapeHtml(pid)}] ${escapeHtml(pName)}</span>
                <button type="button" class="pub-chip-remove" data-remove-pid="${escapeHtml(pid)}" title="Bỏ chọn">&times;</button>
            </span>`;
        }).join('');
        chipsWrap.innerHTML = chipsHtml;
    }

    function updateModalPrimaryDropdown() {
        const select = $('pubModalPrimaryProduct');
        if (!select) return;

        const currentPrimary = editingVideo?.primary_product_id || '';
        const options = Array.from(modalSelectedProducts).map(pid => {
            const pInfo = modalProductsList.find(p => String(p.id) === String(pid));
            const pName = pInfo ? pInfo.name : `SP #${pid}`;
            const isSelected = String(pid) === String(currentPrimary);
            return `<option value="${escapeHtml(pid)}" ${isSelected ? 'selected' : ''}>[${escapeHtml(pid)}] ${escapeHtml(pName)}</option>`;
        });

        if (!options.length) {
            select.innerHTML = '<option value="">-- Chưa chọn sản phẩm liên kết --</option>';
        } else {
            select.innerHTML = options.join('');
        }
    }

    // Modal Events Setup
    if ($('refreshPubVideos')) $('refreshPubVideos').addEventListener('click', refreshPublicationVideos);

    root.querySelectorAll('[data-pub-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            root.querySelectorAll('[data-pub-filter]').forEach(b => b.classList.remove('is-active'));
            btn.classList.add('is-active');
            pubFilter = btn.dataset.pubFilter || 'all';
            renderPublicationVideos();
        });
    });

    if ($('pubSearchInput')) {
        $('pubSearchInput').addEventListener('input', e => {
            pubSearchQuery = e.target.value.trim();
            renderPublicationVideos();
        });
    }

    // Delegation for Quản lý xuất bản and Cấu hình voucher xem video
    root.addEventListener('click', e => {
        if (e.target.closest('a') || e.target.closest('input') || e.target.closest('select')) return;
        const rewardBtn = e.target.closest('.config-reward-btn');
        if (rewardBtn) {
            const vid = rewardBtn.dataset.videoId;
            if (vid) {
                if (!pubVideos.length) {
                    refreshPublicationVideos().then(() => openRewardModal(vid));
                } else {
                    openRewardModal(vid);
                }
            }
            return;
        }

        const btn = e.target.closest('.manage-pub-btn');
        const card = e.target.closest('.pub-card');
        const target = btn || card;
        if (target) {
            const vid = target.dataset.videoId;
            if (vid) {
                if (!pubVideos.length) {
                    refreshPublicationVideos().then(() => openPublicationModal(vid));
                } else {
                    openPublicationModal(vid);
                }
            }
        }
    });

    if ($('closePubModalBtn')) $('closePubModalBtn').addEventListener('click', closePublicationModal);
    if ($('pubModalCloseBtn')) $('pubModalCloseBtn').addEventListener('click', closePublicationModal);
    if ($('pubModalBackdrop')) {
        $('pubModalBackdrop').addEventListener('click', e => {
            if (e.target === $('pubModalBackdrop')) closePublicationModal();
        });
    }

    // Modal Product Live Search & Filter
    if ($('pubModalProductSearch')) {
        $('pubModalProductSearch').addEventListener('input', e => {
            const term = e.target.value.trim();
            renderModalProductList(term);

            // Server search for products not in local array
            if (term.length >= 2) {
                clearTimeout(modalSearchDebounce);
                modalSearchDebounce = setTimeout(async () => {
                    try {
                        const res = await api(`index.php?r=admin_ai_idol_search_products&q=${encodeURIComponent(term)}`);
                        if (res.products && Array.isArray(res.products)) {
                            res.products.forEach(newP => {
                                if (!modalProductsList.some(p => String(p.id) === String(newP.id))) {
                                    modalProductsList.push(newP);
                                }
                            });
                            renderModalProductList($('pubModalProductSearch').value);
                        }
                    } catch (_) {}
                }, 300);
            }
        });
    }

    // Modal Checkbox Selection
    if ($('pubModalProductList')) {
        $('pubModalProductList').addEventListener('change', e => {
            const cb = e.target.closest('.pub-modal-prod-check');
            if (!cb) return;
            const pid = String(cb.value);
            if (cb.checked) {
                modalSelectedProducts.add(pid);
            } else {
                modalSelectedProducts.delete(pid);
            }
            renderModalSelectedChips();
            updateModalPrimaryDropdown();
            renderModalProductList($('pubModalProductSearch')?.value || '');
        });
    }

    // Modal Remove Chip
    if ($('pubModalSelectedChips')) {
        $('pubModalSelectedChips').addEventListener('click', e => {
            const removeBtn = e.target.closest('[data-remove-pid]');
            if (!removeBtn) return;
            const pid = String(removeBtn.dataset.removePid);
            modalSelectedProducts.delete(pid);
            renderModalSelectedChips();
            updateModalPrimaryDropdown();
            renderModalProductList($('pubModalProductSearch')?.value || '');
        });
    }

    // Select all visible / clear all
    if ($('pubModalSelectAllVisible')) {
        $('pubModalSelectAllVisible').addEventListener('click', () => {
            const visibleChecks = root.querySelectorAll('.pub-modal-prod-check');
            visibleChecks.forEach(cb => {
                cb.checked = true;
                modalSelectedProducts.add(String(cb.value));
            });
            renderModalSelectedChips();
            updateModalPrimaryDropdown();
            renderModalProductList($('pubModalProductSearch')?.value || '');
        });
    }

    if ($('pubModalClearAll')) {
        $('pubModalClearAll').addEventListener('click', () => {
            modalSelectedProducts.clear();
            renderModalSelectedChips();
            updateModalPrimaryDropdown();
            renderModalProductList($('pubModalProductSearch')?.value || '');
        });
    }

    // Modal Publish Submit
    if ($('pubModalSubmitBtn')) {
        $('pubModalSubmitBtn').addEventListener('click', async () => {
            if (!editingVideo) return;
            const submitBtn = $('pubModalSubmitBtn');
            const alertBox = $('pubModalAlert');
            const pids = Array.from(modalSelectedProducts);

            if (!pids.length) {
                if (alertBox) {
                    alertBox.textContent = 'Vui lòng chọn ít nhất một sản phẩm liên kết để xuất bản video.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            const title = $('pubModalVideoTitle')?.value.trim() || editingVideo.title || 'Syna 3D - Giới thiệu sản phẩm';
            const priority = parseInt($('pubModalPriority')?.value || '10', 10);
            const primaryPid = $('pubModalPrimaryProduct')?.value || pids[0];

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Đang xuất bản…';
            if (alertBox) alertBox.hidden = true;

            try {
                const payload = {
                    video_id: editingVideo.video_id,
                    associated_product_ids: pids,
                    primary_product_id: primaryPid,
                    title: title,
                    display_priority: isNaN(priority) ? 10 : priority,
                    is_published: true,
                    csrf: window.AI_IDOL_CSRF || ''
                };
                const res = await api('index.php?r=admin_ai_idol_publish_video', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.AI_IDOL_CSRF || ''
                    },
                    body: JSON.stringify(payload)
                });

                if (alertBox) {
                    alertBox.textContent = res.message || 'Đã xuất bản video lên trang sản phẩm thành công!';
                    alertBox.className = 'studio-message success';
                    alertBox.hidden = false;
                }

                await refreshPublicationVideos();
                setTimeout(() => {
                    closePublicationModal();
                }, 900);
            } catch (err) {
                if (alertBox) {
                    alertBox.textContent = 'Lỗi xuất bản: ' + err.message;
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i> Xuất bản lên trang sản phẩm';
            }
        });
    }

    // Modal Unpublish Action
    if ($('pubModalUnpublishBtn')) {
        $('pubModalUnpublishBtn').addEventListener('click', async () => {
            if (!editingVideo) return;
            const unpubBtn = $('pubModalUnpublishBtn');
            const alertBox = $('pubModalAlert');

            unpubBtn.disabled = true;
            unpubBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Đang gỡ…';
            if (alertBox) alertBox.hidden = true;

            try {
                const payload = {
                    publication_id: editingVideo.publication_id || '',
                    video_id: editingVideo.video_id,
                    is_published: false,
                    csrf: window.AI_IDOL_CSRF || ''
                };
                const res = await api('index.php?r=admin_ai_idol_toggle_publish', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.AI_IDOL_CSRF || ''
                    },
                    body: JSON.stringify(payload)
                });

                if (alertBox) {
                    alertBox.textContent = res.message || 'Đã gỡ video khỏi trang sản phẩm thành công!';
                    alertBox.className = 'studio-message success';
                    alertBox.hidden = false;
                }

                await refreshPublicationVideos();
                setTimeout(() => {
                    closePublicationModal();
                }, 900);
            } catch (err) {
                if (alertBox) {
                    alertBox.textContent = 'Lỗi hủy xuất bản: ' + err.message;
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
            } finally {
                unpubBtn.disabled = false;
                unpubBtn.innerHTML = '<i class="bi bi-eye-slash me-1"></i> Hủy xuất bản';
            }
        });
    }

    // ==========================================
    // Phase V2A: Watch-to-Reward Campaign Logic
    // ==========================================
    let rewardEditingVideo = null;
    let rewardEditingCampaign = null;
    let rewardSelectedProducts = new Set();
    let rewardSearchDebounce = null;

    function formatDateTimeLocal(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(/-/g, '/'));
        if (isNaN(d.getTime())) {
            const d2 = new Date(dateStr);
            if (isNaN(d2.getTime())) return '';
            return toLocalIso(d2);
        }
        return toLocalIso(d);
    }

    function toLocalIso(d) {
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    async function openRewardModal(videoId) {
        const video = pubVideos.find(v => v.video_id === videoId);
        if (!video) return;
        rewardEditingVideo = video;
        rewardEditingCampaign = null;

        const backdrop = $('rewardModalBackdrop');
        const alertBox = $('rewardModalAlert');
        if (alertBox) {
            alertBox.hidden = true;
            alertBox.textContent = '';
        }

        // Set Video Header Info
        if ($('rewardModalPoster')) $('rewardModalPoster').src = video.thumbnail_url || 'favicon.ico';
        if ($('rewardModalDurationBadge')) $('rewardModalDurationBadge').textContent = formatTimeDuration(video.duration || 0);
        if ($('rewardModalCampaignName')) $('rewardModalCampaignName').textContent = video.campaign_name || 'Chương trình AI Idol';
        if ($('rewardModalVideoTitle')) $('rewardModalVideoTitle').textContent = video.title || 'Syna 3D - Giới thiệu sản phẩm';
        if ($('rewardModalVideoId')) $('rewardModalVideoId').textContent = video.video_id || '—';
        if ($('rewardVideoId')) $('rewardVideoId').value = video.video_id || '';
        if ($('rewardPublicationId')) $('rewardPublicationId').value = video.publication_id || '';

        // Reset Fields to Default
        if ($('rewardCampaignId')) $('rewardCampaignId').value = '';
        if ($('rewardNameInput')) $('rewardNameInput').value = `Voucher xem video: ${video.title || 'Syna 3D'}`;
        if ($('rewardDiscountTypeSelect')) $('rewardDiscountTypeSelect').value = 'percentage';
        if ($('rewardDiscountValueInput')) $('rewardDiscountValueInput').value = 10;
        if ($('rewardMaxDiscountInput')) $('rewardMaxDiscountInput').value = '';
        if ($('rewardMinOrderInput')) $('rewardMinOrderInput').value = 0;
        if ($('rewardTotalQuotaInput')) $('rewardTotalQuotaInput').value = 100;
        if ($('rewardUserLimitInput')) $('rewardUserLimitInput').value = 1;
        if ($('rewardStackFlashSale')) $('rewardStackFlashSale').checked = false;
        if ($('rewardStackOther')) $('rewardStackOther').checked = false;
        if ($('rewardIsActive')) $('rewardIsActive').checked = true;

        const now = new Date();
        const nextWeek = new Date(now.getTime() + 7 * 24 * 3600 * 1000);
        if ($('rewardStartDateInput')) $('rewardStartDateInput').value = toLocalIso(now);
        if ($('rewardEndDateInput')) $('rewardEndDateInput').value = toLocalIso(nextWeek);

        updateDiscountTypeDisplay();

        // Initialize Selected Products from Video Associated Products
        rewardSelectedProducts = new Set((video.associated_product_ids || []).map(String).filter(Boolean));
        if (!rewardSelectedProducts.size && video.default_product_id) {
            rewardSelectedProducts.add(String(video.default_product_id));
        }

        if ($('rewardStatsCard')) $('rewardStatsCard').hidden = true;
        if ($('rewardToggleActiveBtn')) $('rewardToggleActiveBtn').hidden = true;

        if (backdrop) backdrop.hidden = false;

        // Fetch authoritative existing campaign from backend
        try {
            const res = await api(`index.php?r=admin_ai_idol_reward_campaign&video_id=${encodeURIComponent(videoId)}`);
            if (res && res.campaign) {
                const c = res.campaign;
                rewardEditingCampaign = c;

                if ($('rewardCampaignId')) $('rewardCampaignId').value = c.campaign_id || '';
                if ($('rewardNameInput')) $('rewardNameInput').value = c.campaign_name || '';
                if ($('rewardDiscountTypeSelect')) $('rewardDiscountTypeSelect').value = c.discount_type || 'percentage';
                if ($('rewardDiscountValueInput')) $('rewardDiscountValueInput').value = c.discount_value || 10;
                if ($('rewardMaxDiscountInput')) $('rewardMaxDiscountInput').value = c.max_discount_amount || '';
                if ($('rewardMinOrderInput')) $('rewardMinOrderInput').value = c.min_order_amount || 0;
                if ($('rewardStartDateInput') && c.start_date) $('rewardStartDateInput').value = formatDateTimeLocal(c.start_date);
                if ($('rewardEndDateInput') && c.end_date) $('rewardEndDateInput').value = formatDateTimeLocal(c.end_date);
                if ($('rewardTotalQuotaInput')) $('rewardTotalQuotaInput').value = c.total_quota || 100;
                if ($('rewardUserLimitInput')) $('rewardUserLimitInput').value = c.user_limit || 1;
                if ($('rewardStackFlashSale')) $('rewardStackFlashSale').checked = Boolean(c.stack_with_flash_sale);
                if ($('rewardStackOther')) $('rewardStackOther').checked = Boolean(c.stack_with_other_vouchers);
                if ($('rewardIsActive')) $('rewardIsActive').checked = Boolean(c.is_active);

                updateDiscountTypeDisplay();

                if (Array.isArray(c.applicable_product_ids) && c.applicable_product_ids.length > 0) {
                    rewardSelectedProducts = new Set(c.applicable_product_ids.map(String).filter(Boolean));
                }

                // Show real statistics ONLY because real campaign exists
                if ($('rewardStatsCard')) {
                    $('rewardStatsCard').hidden = false;
                    const issued = Number(c.issued_count || 0);
                    const quota = Number(c.total_quota || 0);
                    if ($('rewardStatIssuedCount')) $('rewardStatIssuedCount').textContent = issued;
                    if ($('rewardStatTotalQuota')) $('rewardStatTotalQuota').textContent = quota;
                    if ($('rewardStatRemaining')) $('rewardStatRemaining').textContent = Math.max(0, quota - issued);
                    if ($('rewardStatUserLimit')) $('rewardStatUserLimit').textContent = c.user_limit || 1;
                    if ($('rewardStatsStatusBadge')) {
                        $('rewardStatsStatusBadge').className = `pub-badge-status ${c.is_active ? 'published' : 'unpublished'}`;
                        $('rewardStatsStatusBadge').innerHTML = c.is_active ? '<i class="bi bi-check-circle-fill"></i> Đang hoạt động' : '<i class="bi bi-pause-circle"></i> Đang tạm dừng';
                    }
                    if ($('rewardStatTimestamps')) {
                        $('rewardStatTimestamps').innerHTML = `Mã: <code>${escapeHtml(c.campaign_id)}</code> · Cập nhật: ${escapeHtml(c.updated_at || c.created_at || '—')}`;
                    }
                }

                if ($('rewardToggleActiveBtn')) {
                    $('rewardToggleActiveBtn').hidden = false;
                    if (c.is_active) {
                        $('rewardToggleActiveBtn').className = 'idol-btn danger-outline';
                        $('rewardToggleActiveBtn').innerHTML = '<i class="bi bi-pause-circle me-1"></i> Tắt chiến dịch';
                    } else {
                        $('rewardToggleActiveBtn').className = 'idol-btn secondary';
                        $('rewardToggleActiveBtn').innerHTML = '<i class="bi bi-play-circle me-1"></i> Kích hoạt chiến dịch';
                    }
                }
            }
        } catch (err) {
            // Non fatal, defaults will be used
        }

        renderRewardProductList();
        renderRewardSelectedChips();
    }

    function closeRewardModal() {
        const backdrop = $('rewardModalBackdrop');
        if (backdrop) backdrop.hidden = true;
        rewardEditingVideo = null;
        rewardEditingCampaign = null;
    }

    function updateDiscountTypeDisplay() {
        const typeSelect = $('rewardDiscountTypeSelect');
        const valInput = $('rewardDiscountValueInput');
        const valLabel = $('rewardDiscountValueLabel');
        const valHelp = $('rewardDiscountValueHelp');
        const maxWrap = $('rewardMaxDiscountWrap');

        if (!typeSelect || !valInput) return;
        const type = typeSelect.value;
        if (type === 'percentage') {
            if (valLabel) valLabel.textContent = 'Mức giảm (%)';
            valInput.min = '1';
            valInput.max = '100';
            valInput.step = '1';
            if (valHelp) valHelp.textContent = 'Nhập từ 1 đến 100%';
            if (maxWrap) maxWrap.style.display = '';
        } else {
            if (valLabel) valLabel.textContent = 'Mức giảm (VNĐ)';
            valInput.min = '1000';
            valInput.removeAttribute('max');
            valInput.step = '5000';
            if (valHelp) valHelp.textContent = 'Nhập số tiền giảm cố định (VNĐ)';
            if (maxWrap) maxWrap.style.display = 'none';
        }
    }

    function renderRewardProductList(searchTerm = '') {
        const listEl = $('rewardProductList');
        if (!listEl) return;

        const term = normalize(searchTerm.trim());
        const filtered = modalProductsList.filter(p => {
            if (!term) return true;
            return normalize(`${p.id} ${p.name}`).includes(term);
        });

        if (!filtered.length) {
            listEl.innerHTML = '<div class="empty compact p-3 text-center text-muted"><p>Không tìm thấy sản phẩm phù hợp.</p></div>';
            return;
        }

        listEl.innerHTML = filtered.map(p => {
            const isChecked = rewardSelectedProducts.has(String(p.id));
            const formattedPrice = Number(p.price || 0).toLocaleString('vi-VN') + ' đ';
            return `
            <label class="pub-product-item ${isChecked ? 'is-selected' : ''}">
                <input type="checkbox" class="reward-modal-prod-check" value="${escapeHtml(p.id)}" ${isChecked ? 'checked' : ''}>
                <div class="pub-product-thumb">
                    <img src="${escapeHtml(p.image || 'favicon.ico')}" alt="" loading="lazy" onerror="this.src='favicon.ico'">
                </div>
                <div class="pub-product-info">
                    <span class="pub-product-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</span>
                    <span class="pub-product-code">Mã SP: <strong>${escapeHtml(p.id)}</strong></span>
                </div>
                <div class="pub-product-price">${formattedPrice}</div>
            </label>`;
        }).join('');
    }

    function renderRewardSelectedChips() {
        const chipsWrap = $('rewardSelectedChips');
        const countEl = $('rewardSelectedProductCount');
        const count = rewardSelectedProducts.size;

        if (countEl) countEl.textContent = `${count} đã chọn`;
        if (!chipsWrap) return;

        if (!count) {
            chipsWrap.hidden = true;
            chipsWrap.innerHTML = '';
            return;
        }

        chipsWrap.hidden = false;
        chipsWrap.innerHTML = Array.from(rewardSelectedProducts).map(pid => {
            const pInfo = modalProductsList.find(p => String(p.id) === String(pid));
            const pName = pInfo ? pInfo.name : `SP #${pid}`;
            return `
            <span class="pub-chip-item">
                <span>[${escapeHtml(pid)}] ${escapeHtml(pName)}</span>
                <button type="button" class="reward-chip-remove" data-remove-pid="${escapeHtml(pid)}" title="Bỏ chọn">&times;</button>
            </span>`;
        }).join('');
    }

    // Reward Modal Events Setup
    if ($('closeRewardModalBtn')) $('closeRewardModalBtn').addEventListener('click', closeRewardModal);
    if ($('closeRewardModalFooterBtn')) $('closeRewardModalFooterBtn').addEventListener('click', closeRewardModal);
    if ($('rewardModalBackdrop')) {
        $('rewardModalBackdrop').addEventListener('click', e => {
            if (e.target === $('rewardModalBackdrop')) closeRewardModal();
        });
    }

    if ($('rewardDiscountTypeSelect')) {
        $('rewardDiscountTypeSelect').addEventListener('change', updateDiscountTypeDisplay);
    }

    if ($('rewardProductSearchInput')) {
        $('rewardProductSearchInput').addEventListener('input', e => {
            const term = e.target.value.trim();
            renderRewardProductList(term);

            if (term.length >= 2) {
                clearTimeout(rewardSearchDebounce);
                rewardSearchDebounce = setTimeout(async () => {
                    try {
                        const res = await api(`index.php?r=admin_ai_idol_search_products&q=${encodeURIComponent(term)}`);
                        if (res.products && Array.isArray(res.products)) {
                            res.products.forEach(newP => {
                                if (!modalProductsList.some(p => String(p.id) === String(newP.id))) {
                                    modalProductsList.push(newP);
                                }
                            });
                            renderRewardProductList($('rewardProductSearchInput').value);
                        }
                    } catch (_) {}
                }, 300);
            }
        });
    }

    if ($('rewardProductList')) {
        $('rewardProductList').addEventListener('change', e => {
            const checkbox = e.target.closest('.reward-modal-prod-check');
            if (!checkbox) return;
            const pid = String(checkbox.value);
            if (checkbox.checked) {
                rewardSelectedProducts.add(pid);
            } else {
                rewardSelectedProducts.delete(pid);
            }
            renderRewardProductList($('rewardProductSearchInput') ? $('rewardProductSearchInput').value : '');
            renderRewardSelectedChips();
        });
    }

    if ($('rewardSelectedChips')) {
        $('rewardSelectedChips').addEventListener('click', e => {
            const removeBtn = e.target.closest('.reward-chip-remove');
            if (!removeBtn) return;
            const pid = String(removeBtn.dataset.removePid);
            rewardSelectedProducts.delete(pid);
            renderRewardProductList($('rewardProductSearchInput') ? $('rewardProductSearchInput').value : '');
            renderRewardSelectedChips();
        });
    }

    if ($('rewardSelectAllLinked')) {
        $('rewardSelectAllLinked').addEventListener('click', () => {
            if (rewardEditingVideo && Array.isArray(rewardEditingVideo.associated_product_ids)) {
                rewardEditingVideo.associated_product_ids.forEach(pid => {
                    if (pid) rewardSelectedProducts.add(String(pid));
                });
            }
            renderRewardProductList($('rewardProductSearchInput') ? $('rewardProductSearchInput').value : '');
            renderRewardSelectedChips();
        });
    }

    if ($('rewardClearAllProducts')) {
        $('rewardClearAllProducts').addEventListener('click', () => {
            rewardSelectedProducts.clear();
            renderRewardProductList($('rewardProductSearchInput') ? $('rewardProductSearchInput').value : '');
            renderRewardSelectedChips();
        });
    }

    // Submit Reward Campaign Configuration
    if ($('rewardModalSubmitBtn')) {
        $('rewardModalSubmitBtn').addEventListener('click', async () => {
            const alertBox = $('rewardModalAlert');
            const submitBtn = $('rewardModalSubmitBtn');

            if (alertBox) {
                alertBox.hidden = true;
                alertBox.textContent = '';
            }

            const name = $('rewardNameInput').value.trim();
            if (!name) {
                if (alertBox) {
                    alertBox.textContent = 'Vui lòng nhập tên chiến dịch ưu đãi.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            const discountType = $('rewardDiscountTypeSelect').value;
            const discountVal = parseFloat($('rewardDiscountValueInput').value) || 0;
            if (discountType === 'percentage' && (discountVal <= 0 || discountVal > 100)) {
                if (alertBox) {
                    alertBox.textContent = 'Mức giảm phần trăm phải từ 1 đến 100%.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }
            if (discountType === 'fixed' && discountVal <= 0) {
                if (alertBox) {
                    alertBox.textContent = 'Mức giảm tiền cố định phải lớn hơn 0 VNĐ.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            const startDate = $('rewardStartDateInput').value;
            const endDate = $('rewardEndDateInput').value;
            if (!startDate || !endDate) {
                if (alertBox) {
                    alertBox.textContent = 'Vui lòng chọn ngày bắt đầu và kết thúc.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }
            if (new Date(startDate) >= new Date(endDate)) {
                if (alertBox) {
                    alertBox.textContent = 'Ngày bắt đầu phải trước ngày kết thúc.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            const totalQuota = parseInt($('rewardTotalQuotaInput').value, 10) || 0;
            if (totalQuota <= 0) {
                if (alertBox) {
                    alertBox.textContent = 'Tổng hạn ngạch phát hành phải lớn hơn 0.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            if (rewardEditingCampaign && totalQuota < Number(rewardEditingCampaign.issued_count || 0)) {
                if (alertBox) {
                    alertBox.textContent = `Hạn ngạch (${totalQuota}) không thể nhỏ hơn số voucher đã phát hành thực tế (${rewardEditingCampaign.issued_count}).`;
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            if (!rewardSelectedProducts.size) {
                if (alertBox) {
                    alertBox.textContent = 'Vui lòng chọn ít nhất 1 sản phẩm áp dụng voucher.';
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
                return;
            }

            const payload = {
                campaign_id: $('rewardCampaignId').value.trim(),
                video_id: $('rewardVideoId').value.trim(),
                publication_id: $('rewardPublicationId').value.trim(),
                campaign_name: name,
                discount_type: discountType,
                discount_value: discountVal,
                max_discount_amount: $('rewardMaxDiscountInput').value !== '' ? parseInt($('rewardMaxDiscountInput').value, 10) : null,
                min_order_amount: parseInt($('rewardMinOrderInput').value, 10) || 0,
                start_date: startDate,
                end_date: endDate,
                total_quota: totalQuota,
                user_limit: parseInt($('rewardUserLimitInput').value, 10) || 1,
                applicable_product_ids: Array.from(rewardSelectedProducts),
                stack_with_flash_sale: $('rewardStackFlashSale').checked,
                stack_with_other_vouchers: $('rewardStackOther').checked,
                is_active: $('rewardIsActive').checked,
                csrf: window.AI_IDOL_CSRF || ''
            };

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Đang lưu...';

            try {
                const res = await api('index.php?r=admin_ai_idol_save_reward_campaign', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.AI_IDOL_CSRF || ''
                    },
                    body: JSON.stringify(payload)
                });

                if (alertBox) {
                    alertBox.textContent = res.message || 'Đã lưu cấu hình voucher thành công!';
                    alertBox.className = 'studio-message success';
                    alertBox.hidden = false;
                }

                await refreshPublicationVideos();
                setTimeout(() => {
                    closeRewardModal();
                }, 900);
            } catch (err) {
                if (alertBox) {
                    alertBox.textContent = 'Lỗi lưu cấu hình: ' + err.message;
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Lưu cấu hình voucher';
            }
        });
    }

    // Toggle Active / Inactive for Reward Campaign
    if ($('rewardToggleActiveBtn')) {
        $('rewardToggleActiveBtn').addEventListener('click', async () => {
            if (!rewardEditingCampaign) return;
            const cid = rewardEditingCampaign.campaign_id;
            const newActive = !rewardEditingCampaign.is_active;
            const alertBox = $('rewardModalAlert');
            const toggleBtn = $('rewardToggleActiveBtn');

            toggleBtn.disabled = true;
            toggleBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i> Đang xử lý...';

            try {
                const res = await api('index.php?r=admin_ai_idol_toggle_reward_campaign', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.AI_IDOL_CSRF || ''
                    },
                    body: JSON.stringify({
                        campaign_id: cid,
                        is_active: newActive,
                        csrf: window.AI_IDOL_CSRF || ''
                    })
                });

                rewardEditingCampaign.is_active = newActive;
                if ($('rewardIsActive')) $('rewardIsActive').checked = newActive;
                if ($('rewardStatsStatusBadge')) {
                    $('rewardStatsStatusBadge').className = `pub-badge-status ${newActive ? 'published' : 'unpublished'}`;
                    $('rewardStatsStatusBadge').innerHTML = newActive ? '<i class="bi bi-check-circle-fill"></i> Đang hoạt động' : '<i class="bi bi-pause-circle"></i> Đang tạm dừng';
                }

                if (newActive) {
                    toggleBtn.className = 'idol-btn danger-outline';
                    toggleBtn.innerHTML = '<i class="bi bi-pause-circle me-1"></i> Tắt chiến dịch';
                } else {
                    toggleBtn.className = 'idol-btn secondary';
                    toggleBtn.innerHTML = '<i class="bi bi-play-circle me-1"></i> Kích hoạt chiến dịch';
                }

                if (alertBox) {
                    alertBox.textContent = res.message || 'Đã thay đổi trạng thái chiến dịch thành công.';
                    alertBox.className = 'studio-message success';
                    alertBox.hidden = false;
                }

                await refreshPublicationVideos();
            } catch (err) {
                if (alertBox) {
                    alertBox.textContent = 'Lỗi cập nhật: ' + err.message;
                    alertBox.className = 'studio-message';
                    alertBox.hidden = false;
                }
            } finally {
                toggleBtn.disabled = false;
            }
        });
    }

    syncScheduleConstraints(true);
    updateSelection();
    const initialFlow = new URLSearchParams(window.location.search).get('flow') || new URLSearchParams(window.location.search).get('tab') || 'manual';
    if (['agent', 'publication'].includes(initialFlow)) {
        showStudioFlow(initialFlow);
    }
    refreshCampaigns();
    refreshAgentRuns();
    refreshPublicationVideos().then(() => {
        const openVid = new URLSearchParams(window.location.search).get('open_pub') || new URLSearchParams(window.location.search).get('pub_vid');
        if (openVid) openPublicationModal(openVid);
    });
    setInterval(async () => {
        if (document.hidden || pollBusy) return;
        pollBusy = true;
        try {
            await refreshCampaigns();
            await refreshAgentRuns();
            if (activeCampaignId) await loadDetail(activeCampaignId);
            if ($('pubFlow') && !$('pubFlow').hidden) await refreshPublicationVideos();
        } finally { pollBusy = false; }
    }, 5000);
    window.addEventListener('pagehide', () => {
        $('avatarVideoPreview').pause();
    });
})();
