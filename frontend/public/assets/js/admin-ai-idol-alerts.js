// Admin bell: AI Idol programmes still awaiting script approval close to (or past) their airtime.
(function () {
    'use strict';
    const bell = document.getElementById('adminNotificationButton');
    const group = document.getElementById('aiIdolNotificationGroup');
    if (!bell || !group || !window.AiIdolApproval) return;
    const {approvalWarning, formatDate} = window.AiIdolApproval;
    const POLL_MS = 60000;
    const MISSED_WINDOW_MS = 24 * 3600 * 1000; // a slot missed over a day ago stops nagging
    const SEEN_KEY = 'skinsyntax_ai_idol_alerts_seen';
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const readSeen = () => {
        try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); } catch (error) { return new Set(); }
    };
    const writeSeen = keys => {
        try { localStorage.setItem(SEEN_KEY, JSON.stringify([...keys].slice(-100))); } catch (error) { /* storage blocked */ }
    };
    // Seen per programme *and* level, so escalating from "soon" to "urgent" rings the bell again.
    const alertKey = alert => `${alert.id}:${alert.warning.level}`;
    let serverUnseen = parseInt(document.getElementById('adminNotificationBadge')?.textContent, 10) || 0;
    let alerts = [];
    let busy = false;

    function renderBadge() {
        const seen = readSeen();
        const total = serverUnseen + alerts.filter(alert => !seen.has(alertKey(alert))).length;
        let badge = document.getElementById('adminNotificationBadge');
        bell.setAttribute('aria-label', total ? `Thông báo, ${total} mới` : 'Thông báo');
        if (!total) { badge?.remove(); return; }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'admin-bell-badge';
            badge.id = 'adminNotificationBadge';
            bell.appendChild(badge);
        }
        badge.textContent = total > 99 ? '99+' : String(total);
    }
    function renderGroup() {
        group.hidden = !alerts.length;
        group.innerHTML = alerts.length ? '<div class="admin-notification-section"><div class="small fw-semibold text-uppercase text-muted">AI Idol · Kịch bản sắp tới giờ phát</div></div>' + alerts.map(alert => `
            <a class="admin-notification-item ai-idol-alert is-${escapeHtml(alert.warning.level)}" href="index.php?r=admin_ai_idol&campaign_id=${encodeURIComponent(alert.id)}">
                <div class="d-flex justify-content-between gap-3">
                    <div class="fw-semibold">${escapeHtml(alert.name)}</div>
                    <div class="small text-muted text-end text-nowrap">${escapeHtml(formatDate(alert.scheduledAt))}</div>
                </div>
                <div class="small fw-semibold mt-1 ai-idol-alert-title"><i class="bi bi-alarm me-1" aria-hidden="true"></i>${escapeHtml(alert.warning.short)}</div>
                <div class="small text-muted mt-1">${escapeHtml(alert.warning.text)}</div>
            </a>`).join('') : '';
    }
    async function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const response = await fetch('index.php?r=admin_ai_idol_campaigns&limit=100', {
                credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            if (!response.ok) return;
            const body = await response.json();
            const now = Date.now();
            alerts = (body.campaigns || [])
                .map(item => ({id: String(item._id), name: item.name || 'Chương trình AI Idol', scheduledAt: item.scheduled_at, warning: approvalWarning(item, now)}))
                .filter(alert => alert.warning && !(alert.warning.level === 'missed' && now - new Date(alert.scheduledAt).getTime() > MISSED_WINDOW_MS))
                .sort((a, b) => new Date(a.scheduledAt) - new Date(b.scheduledAt));
            renderGroup();
            renderBadge();
        } catch (error) {
            // AI Idol service unreachable: the bell keeps its other notices.
        } finally {
            busy = false;
        }
    }
    bell.addEventListener('click', () => {
        serverUnseen = 0; // header.php marks the server-side notices seen on this same click
        const seen = readSeen();
        alerts.forEach(alert => seen.add(alertKey(alert)));
        writeSeen(seen);
        renderBadge();
    });
    refresh();
    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}());
