(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const page = $('ssLivePage');
    const {liveId, csrf, baseUrl} = page?.dataset ?? {};
    if (!liveId || liveId.startsWith('ai-')) return;

    const studio = page.dataset.isStudio === '1';
    const moderator = page.dataset.moderate === '1';
    const signedIn = page.dataset.signedIn === '1' || studio;
    const chat = $('liveChatBox');
    const form = $('liveChatForm');
    const input = $('liveChatInput');
    const suggestion = $('liveSynaSuggestion');
    const aiNotice = $('liveSynaNotice');
    const notice = $('liveChatNotice');
    const replyBar = $('liveChatReply');
    const studioNotice = $('liveStudioStatus');
    const mediaNotice = $('liveMediaStatus');
    const replay = $('replayVideoPlayer');
    const remote = $('livekitRemoteVideo');
    const remoteAudio = $('livekitRemoteAudio');
    const waiting = $('liveWaitingOverlay');
    const sourceLabels = {camera: 'Camera trực tiếp', screen: 'Chia sẻ màn hình', video: 'Video có sẵn', ai_video: 'Video AI dựng sẵn'};
    const statuses = {scheduled: 'Đã lên lịch', ready: 'Sẵn sàng phát', live: 'ĐANG PHÁT', interrupted: 'Tạm dừng phát', ended: 'Đã kết thúc', replay_ready: 'Xem lại', recording_failed: 'Lưu bản ghi lỗi'};
    let status = page.dataset.liveStatus;
    let aiEnabled = page.dataset.aiEnabled === '1';
    let mode = 'all', replyTo = '', previousHistory = '';
    let room, connecting, connectedAt = 0, publisherToken, publisherRefreshAt = 0, publishing = false, media, activeSource = '', recorder, recordingParts = [], recordedFile, switching = false, ending = false, nextAtEnd = false;
    let files = [], playlistActive = false, playingIndex = -1, outputMuted = false, visibleDeals = '', closed = false;
    let recordingId, nextRecordingPart = 0, heartbeatPending = false;
    let pinnedEndsAt = 0, liveServerOffsetMs = 0, pinnedUnlimited = false;
    let currentPinnedProduct = null;
    let dealsOpen = false;
    let chatIds = new Set();
    let chatLoaded = false;
    let chatPending = false;
    let voucherKey = '';
    const cartRequests = new WeakSet();

    const url = (route, params = {}) => baseUrl + '/index.php?' + new URLSearchParams({r: route, ...params});
    async function json(route, params = {}, fields) {
        try {
            let options;
            if (fields) {
                const body = fields instanceof FormData ? fields : new FormData();
                if (!(fields instanceof FormData)) Object.entries(fields).forEach(([key, value]) => body.set(key, value));
                body.set('csrf', csrf);
                body.set('room_id', liveId);
                options = {method: 'POST', body};
            }
            const response = await fetch(url(route, params), options ?? {cache: 'no-store'});
            return await response.json();
        } catch {
            return {ok: false};
        }
    }

    function updateHeaderCartCount(count) {
        window.updateSkinSyntaxCartCount?.(count);
        const badge = $('headerCartCount');
        if (!badge) return;
        badge.classList?.remove('is-updated');
        void badge.offsetWidth;
        badge.classList?.add('is-updated');
    }

    function showCartToast(message) {
        document.querySelector?.('.ss-cart-toast')?.remove();
        const toast = document.createElement('div');
        toast.className = 'ss-cart-toast';
        toast.setAttribute('role', 'status');
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-circle-check';
        icon.setAttribute('aria-hidden', 'true');
        const text = document.createElement('span');
        text.textContent = message;
        toast.append(icon, text);
        (document.fullscreenElement || document.body).appendChild(toast);
        setTimeout(() => toast.remove(), 2400);
    }

    function setDealsOpen(open) {
        const drawer = $('liveDeals');
        const trigger = $('btnLiveDeals');
        if (!drawer || !trigger || trigger.hidden) return;
        dealsOpen = Boolean(open);
        drawer.hidden = !dealsOpen;
        trigger.setAttribute('aria-expanded', dealsOpen ? 'true' : 'false');
        if (dealsOpen) drawer.querySelector('button')?.focus();
        else trigger.focus();
    }

    function activePlaybackElement() {
        if (replay && !replay.hidden) return replay;
        if (remote && !remote.hidden) return remote;
        return $('livekitLocalVideo');
    }

    function syncSoundControl(muted) {
        const control = $('btnLiveMute');
        if (!control) return;
        control.setAttribute('aria-pressed', muted ? 'false' : 'true');
        control.setAttribute('aria-label', muted ? 'Bật âm thanh' : 'Tắt âm thanh');
        const icon = control.querySelector('i');
        if (icon) icon.className = 'fa-solid ' + (muted ? 'fa-volume-xmark' : 'fa-volume-high');
    }

    function setConnection(text, tone) {
        const badge = $('liveConnectionBadge');
        if (!badge) return;
        badge.className = 'ss-badge ss-badge--' + tone;
        badge.textContent = text;
    }

    function button(label, handler) {
        const node = document.createElement('button');
        node.type = 'button';
        node.className = 'btn btn-link btn-sm p-0 me-2';
        node.textContent = label;
        node.addEventListener('click', handler);
        return node;
    }

    function setReply(message) {
        replyTo = message?.id ?? '';
        replyBar.hidden = !replyTo;
        replyBar.querySelector('span').textContent = message ? 'Đang trả lời ' + message.sender_name : '';
        if (replyTo) input.focus();
    }

    function renderMessage(message, messages) {
        const element = document.createElement('div');
        element.className = 'ss-message' + (message.is_ai ? ' ss-message--ai' : '');
        const meta = document.createElement('div');
        meta.className = 'ss-message__meta';
        const name = document.createElement('strong');
        name.textContent = message.sender_name;
        meta.appendChild(name);
        const roles = {ai: 'AI', admin: 'Quản trị viên', streamer: 'Streamer', employee: 'Nhân viên SkinSyntax', customer: 'Khách hàng', guest: 'Khách vãng lai'};
        if (message.is_ai) {
            const avatar = document.createElement('span');
            avatar.className = 'ss-live-syna-avatar';
            avatar.textContent = 'S';
            avatar.setAttribute('aria-hidden', 'true');
            meta.prepend(avatar);
        } else if (message.sender_avatar) {
            try {
                const source = new URL(message.sender_avatar, window.location.href);
                if (source.origin === window.location.origin && source.searchParams.get('r') === 'account_avatar_image') {
                    const avatar = document.createElement('img');
                    avatar.className = 'ss-chat-avatar';
                    avatar.alt = '';
                    avatar.src = source.href;
                    avatar.addEventListener('error', () => avatar.remove(), {once: true});
                    meta.prepend(avatar);
                }
            } catch { /* Keep the sender's name visible without an avatar. */ }
        }
        for (const label of [roles[message.sender_type], message.is_pinned ? 'Đã ghim' : ''].filter(Boolean)) {
            const badge = document.createElement('span');
            badge.className = 'ss-message__pill';
            badge.textContent = label;
            meta.appendChild(badge);
        }
        element.appendChild(meta);
        if (message.reply_to) {
            const original = messages.get(message.reply_to);
            const quote = document.createElement('small');
            quote.textContent = original ? 'Trả lời ' + original.sender_name + ' · ' + original.message.slice(0, 64) : 'Trả lời một tin nhắn';
            element.appendChild(quote);
            if (message.is_ai && (message.reply_to_sender_name || original?.sender_name)) {
                const target = document.createElement('div');
                target.className = 'ss-live-syna-target';
                target.textContent = 'SYNA đang trả lời @' + (message.reply_to_sender_name || original.sender_name);
                element.appendChild(target);
            }
        }
        const body = document.createElement('div');
        body.className = 'ss-message__text';
        const sourceLink = /(?:\n\n)(https:\/\/www\.aad\.org\/public\/everyday-care\/skin-care-basics\/[a-z/-]+)\/?$/;
        const source = message.is_ai ? message.message.match(sourceLink) : null;
        const text = source ? message.message.slice(0, source.index) : message.message;
        for (const part of text.split(/((?<![\p{L}\p{N}_@./])@syna(?![\p{L}\p{N}_@]|[.-][\p{L}\p{N}]))/iu)) {
            if (/^@syna$/i.test(part)) {
                const mark = document.createElement('span');
                mark.className = 'ss-live-mention';
                mark.textContent = part;
                body.appendChild(mark);
            } else body.append(part);
        }
        if (source) {
            const link = document.createElement('a');
            link.href = source[1];
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'American Academy of Dermatology';
            body.append(document.createElement('br'), link);
        }
        element.appendChild(body);
        if (status === 'live' && signedIn) element.appendChild(button('Trả lời', () => setReply(message)));
        if (moderator) {
            const actions = [['Ghim', 'pin'], ['Bỏ ghim', 'unpin'], ['Ẩn', 'hide']];
            if (!message.is_ai) actions.push(['Tắt chat 5 phút', 'mute']);
            for (const [label, action] of actions) {
                if ((action === 'pin' && message.is_pinned) || (action === 'unpin' && !message.is_pinned)) continue;
                element.appendChild(button(label, async () => {
                    const result = await json('api_live_manage', {}, {action, message_id: message.id});
                    if (notice) notice.textContent = result.ok ? (action === 'mute' ? 'Đã tắt chat 5 phút.' : '') : 'Không thể cập nhật tin nhắn.';
                    if (result.ok) loadChat();
                }));
            }
        }
        return element;
    }

    async function loadChat() {
        if (!chat || document.hidden || chatPending) return;
        chatPending = true;
        const requestedMode = mode;
        const result = await json('api_live_chat_history', {room_id: liveId, mode});
        chatPending = false;
        if (requestedMode !== mode) return void loadChat();
        if (!result.ok || !Array.isArray(result.data)) return;
        const signature = status + JSON.stringify(result.data);
        if (previousHistory === signature) return;
        previousHistory = signature;
        const distance = chat.scrollHeight - chat.clientHeight - chat.scrollTop;
        const messages = new Map(result.data.map(message => [message.id, message]));
        const sorted = [...result.data.filter(message => message.is_pinned), ...result.data.filter(message => !message.is_pinned)];
        const pinnedMessage = sorted.find(message => message.is_pinned);
        if (pinnedMessage) showPinnedComment(pinnedMessage.message);
        else hidePinnedComment();
        const oldPosition = chat.scrollTop;
        chat.replaceChildren(...sorted.map(message => {
            const element = renderMessage(message, messages);
            if (chatLoaded && !chatIds.has(message.id)) element.classList.add('ss-message--new');
            return element;
        }));
        chat.scrollTop = !chatLoaded || distance < 50 ? chat.scrollHeight : oldPosition;
        chatIds = new Set(messages.keys());
        chatLoaded = true;
    }

    function insertMention() {
        const start = input.selectionStart;
        const prefix = input.value.slice(0, start);
        const found = prefix.match(/(?:^|\s)@[\p{L}\p{N}_]*$/u)?.[0];
        const from = found ? prefix.lastIndexOf('@') : start;
        const sep = !found && start > 0 && !/\s$/.test(prefix) ? ' ' : '';
        input.setRangeText(sep + '@syna ', from, start, 'end');
        suggestion.hidden = true;
        input.focus();
    }

    function setSynaEnabled(value) {
        aiEnabled = value;
        $('liveAskSyna').hidden = !value;
        if (!value) suggestion.hidden = true;
        const toggle = $('liveToggleSyna');
        if (toggle && !toggle.disabled) toggle.checked = value;
    }

    async function askSyna(id) {
        aiNotice.textContent = 'Đang chờ SYNA trả lời…';
        const result = await json('api_live_syna', {}, {message_id: id});
        const messages = {
            disabled: 'SYNA đang tắt trong phiên này.',
            busy: 'Bạn đã có câu hỏi đang được xử lý hoặc vừa hỏi SYNA. Vui lòng đợi.',
            full: 'Hàng đợi SYNA đang đầy. Vui lòng thử lại sau.',
            budget: 'Phiên đã chạm giới hạn yêu cầu SYNA trong phút này. Vui lòng thử lại sau.',
            timeout: 'SYNA chưa xử lý kịp câu hỏi. Vui lòng thử lại sau.',
            unavailable: 'SYNA chưa trả lời được. Bạn có thể hỏi nhân viên.',
        };
        aiNotice.textContent = result.ok ? '' : (messages[result.ai_status] ?? messages.unavailable);
        if (result.ok) {
            void loadChat();
            if (result.syna_chat) void broadcastChatUpdate();
        }
    }

    async function broadcastChatUpdate() {
        if (room?.state !== 'connected') return;
        try {
            await room.localParticipant.publishData(
                new TextEncoder().encode(JSON.stringify({type: 'chat_message'})),
                {reliable: true}
            );
        } catch {
            // Viewers also synchronize with the persisted chat history.
        }
    }

    if (form) {
        setSynaEnabled(aiEnabled);
        replyBar.querySelector('button').addEventListener('click', () => setReply(null));
        $('liveAskSyna').addEventListener('click', insertMention);
        suggestion.addEventListener('click', insertMention);
        input.addEventListener('input', () => {
            suggestion.hidden = !aiEnabled || !/(?:^|\s)@[\p{L}\p{N}_]*$/u.test(input.value.slice(0, input.selectionStart));
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const message = input.value.trim();
            if (!signedIn || status !== 'live' || !message || input.disabled) return;
            const clientId = window.crypto?.randomUUID?.() ?? 'msg-' + Date.now() + '-' + Math.random().toString(36).slice(2);
            input.disabled = true;
            notice.textContent = '';
            const result = await json('api_live_chat', {}, {
                message, client_message_id: clientId, reply_to: replyTo,
            });
            input.disabled = false;
            input.focus();
            if (!result.ok) {
                notice.textContent = result.message || 'Không gửi được tin nhắn.';
                return;
            }
            input.value = '';
            suggestion.hidden = true;
            setReply(null);
            void loadChat();
            if (result.chat) void broadcastChatUpdate();
            loadChat();
            if (result.ai_status === 'pending') void askSyna(result.id);
            if (result.ai_status === 'disabled') aiNotice.textContent = 'SYNA đang tắt trong phiên này.';
        });
    }

    document.querySelectorAll('[data-chat-mode]').forEach(tab => tab.addEventListener('click', () => {
        document.querySelectorAll('[data-chat-mode]').forEach(item => item.classList.toggle('is-active', item === tab));
        mode = tab.dataset.chatMode;
        previousHistory = '';
        loadChat();
    }));

    async function connect() {
        if (room || connecting || (!studio && status !== 'live')) return connecting ?? room;
        connecting = (async () => {
            const sdk = window.LivekitClient;
            if (!sdk) throw new Error('Không tải được trình phát LiveKit.');
            const result = await json('api_live_token', {id: liveId, studio: studio ? '1' : '0'});
            if (!result.ok || !result.data?.token) throw new Error(result.message || 'Chưa kết nối được LiveKit.');
            const next = new sdk.Room({adaptiveStream: true});
            const events = sdk.RoomEvent;
            next.on(events.ConnectionStateChanged, state => {
                console.info('[SkinSyntax Live] room_state', state);
                if (state === 'reconnecting') setConnection('Đang kết nối lại', 'warning');
                if (state === 'connected') setConnection(studio ? 'Máy phát đã kết nối' : 'Đã kết nối', 'ready');
            });
            next.on(events.LocalTrackPublished, publication => {
                console.info('[SkinSyntax Live] local_track_published', {kind: publication?.kind || 'unknown', source: publication?.source || 'unknown'});
            });
            next.on(events.TrackSubscribed, (track, publication, participant) => {
                console.info('[SkinSyntax Live] track_subscribed', {kind: track?.kind || publication?.kind || 'unknown', participant: participant?.identity || 'unknown'});
                if (studio && publishing) return;
                if (track.kind === 'video') {
                    track.attach(remote);
                    remote.hidden = false;
                    void remote.play().catch(() => {});
                    waiting.hidden = true;
                } else if (track.kind === 'audio') track.attach(remoteAudio);
            });
            next.on(events.TrackUnsubscribed, track => {
                track.detach(track.kind === 'video' ? remote : remoteAudio);
                if (track.kind === 'video') {
                    remote.hidden = true;
                    if (!activeSource && !replay && !$('liveInPageReplay')) waiting.hidden = false;
                }
            });
            next.on(events.AudioPlaybackStatusChanged, () => {
                $('btnEnableLiveSound').hidden = next.canPlaybackAudio;
            });
            next.on(events.DataReceived, (payload) => {
                try {
                    const str = new TextDecoder().decode(payload);
                    const msg = JSON.parse(str);
                    handleDataChannelEvent(msg);
                } catch (e) {}
            });
            next.on(events.Disconnected, reason => {
                if (room !== next) return;
                room = null;
                publishing = false;
                if (reason !== undefined && reason === sdk.DisconnectReason?.DUPLICATE_IDENTITY) {
                    media?.release(media.source);
                    if (media) media.source = null;
                    activeSource = '';
                    syncStudio();
                    mediaNotice.textContent = 'Một thiết bị khác đang phát phiên này.';
                }
                setConnection('Đã ngắt kết nối', 'warning');
            });
            await next.connect(result.data.server_url, result.data.participant_token);
            room = next;
            connectedAt = Date.now();
            publisherToken = result.data.can_publish ? result.data.participant_token : '';
            publisherRefreshAt = Date.now() + 50 * 60 * 1000;
            return room;
        })();
        try {
            return await connecting;
        } catch (error) {
            setConnection(error.message, 'warning');
            return null;
        } finally {
            connecting = null;
        }
    }

    async function updateStream(state, source = activeSource) {
        if (['live', 'heartbeat'].includes(state) && Date.now() >= publisherRefreshAt) {
            const latest = await json('api_live_token', {id: liveId, studio: '1'});
            if (!latest.ok || !latest.data?.can_publish) throw new Error('Không gia hạn được quyền phát.');
            publisherToken = latest.data.participant_token;
            publisherRefreshAt = Date.now() + 50 * 60 * 1000;
        }
        const result = await json('api_live_stream_state', {}, {live_id: liveId, state, source, publisher_token: publisherToken});
        if (!result.ok) throw new Error(result.message || 'Không cập nhật được trạng thái phát.');
        if (state !== 'heartbeat') updateStatus(state);
    }

    function updateStatus(next) {
        status = next;
        if ($('liveWaitingTitle')) $('liveWaitingTitle').textContent = statuses[status] ?? 'Sắp bắt đầu';
        if ($('liveWaitingMessage')) $('liveWaitingMessage').textContent = {
            scheduled: 'Buổi live sẽ bắt đầu theo lịch hẹn.',
            ready: studio ? 'Xem trước nguồn phát rồi chọn Bắt đầu phát.' : 'Buổi live sắp bắt đầu. Bạn có thể giữ trang này để theo dõi.',
            live: 'Đang tải hình ảnh từ buổi live…',
            interrupted: 'Buổi live đang tạm dừng. Trang sẽ tự cập nhật khi tiếp tục.',
            ended: 'Buổi live đã kết thúc.',
            replay_ready: 'Video xem lại đã sẵn sàng.',
            recording_failed: 'Buổi live đã kết thúc. Hiện chưa có video xem lại.',
        }[status] ?? '';
        $('liveStatusLabel').textContent = statuses[status] ?? statuses.ready;
        $('liveStatusBadge').className = 'ss-badge ss-badge--' + (status === 'live' ? 'live' : 'neutral');
        $('liveViewers').hidden = status !== 'live';
        const chatOpen = signedIn && status === 'live';
        $('liveChatCompose').hidden = !chatOpen;
        $('liveChatLocked').hidden = chatOpen;
        if ($('liveChatGuestHint')) $('liveChatGuestHint').hidden = signedIn || status !== 'live';
        if ($('liveChatOfflineHint')) $('liveChatOfflineHint').hidden = status === 'live';
        if ($('chatConnectionState')) $('chatConnectionState').textContent = status === 'live' ? 'Đang mở' : 'Chưa mở';
        document.querySelectorAll('#liveBuyNowForm button, .live-add-to-cart-form button').forEach(control => {
            control.disabled = status !== 'live' || cartRequests.has(control);
        });
    }

    async function publish() {
        if (publishing) return;
        const current = await connect();
        if (!current || !publisherToken) throw new Error('Chưa có kết nối phát của streamer.');
        const participant = current.localParticipant;
        if (!media?.videoOutput || media.videoOutput.readyState !== 'live') throw new Error('Camera chưa có luồng hình ảnh thật.');
        console.info('[SkinSyntax Live] publish_check', {video: media.videoOutput.readyState, audioFallback: Boolean(media.source?.audio_fallback), audioTracks: media.audioOutput?.readyState === 'live' ? 1 : 0});
        await participant.publishTrack(media.videoOutput, {name: 'skinsyntax-video', source: 'camera', simulcast: false});
        try {
            await participant.publishTrack(media.audioOutput, {name: 'skinsyntax-audio', source: 'microphone'});
            if (media.source?.audio_fallback) console.warn('[SkinSyntax Live] microphone_fallback_silent_track');
        } catch (error) {
            await participant.unpublishTrack(media.videoOutput, false);
            throw error;
        }
        publishing = true;
    }

    function startRecording() {
        if (recorder || !window.MediaRecorder) return;
        try {
            const mimeType = ['video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'].find(type => MediaRecorder.isTypeSupported(type));
            if (!mimeType) return;
            recorder = new MediaRecorder(media.output, {mimeType, videoBitsPerSecond: 1200000, audioBitsPerSecond: 64000});
            recorder.ondataavailable = event => {
                if (event.data.size) recordingParts.push(event.data);
            };
            recorder.start(2000);
        } catch {
            recorder = null;
        }
    }

    async function stopRecording() {
        if (!recorder || recorder.state === 'inactive') return recordedFile;
        await new Promise(resolve => {
            recorder.addEventListener('stop', resolve, {once: true});
            recorder.stop();
        });
        const type = recorder.mimeType.split(';')[0];
        recordedFile = new Blob(recordingParts, {type});
        recordingParts = [];
        return recordedFile;
    }

    async function uploadRecording(blob) {
        const partSize = 4 * 1024 * 1024;
        const count = Math.ceil(blob.size / partSize);
        if (count > 256) throw new Error('Bản ghi vượt giới hạn 1 GB.');
        recordingId ??= window.crypto?.randomUUID?.() ?? 'recording-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        while (nextRecordingPart < count) {
            const index = nextRecordingPart;
            const fields = new FormData();
            fields.set('live_id', liveId);
            fields.set('recording_id', recordingId);
            fields.set('part_index', index);
            fields.set('part_count', count);
            fields.set('video_blob', blob.slice(index * partSize, (index + 1) * partSize), 'recording.part');
            $('liveRecordingState').textContent = 'Đang tải bản ghi ' + (index + 1) + '/' + count;
            const result = await json('api_upload_live_recording', {}, fields);
            if (!result.ok) throw new Error(result.message || 'Không tải lên được bản ghi. Bấm kết thúc để thử lại.');
            nextRecordingPart++;
        }
    }

    function syncStudio() {
        if (!$('btnEndLive')) return;
        $('btnEndLive').disabled = ending || closed || (!activeSource && !recorder);
        $('btnStopLive').disabled = !activeSource || switching || ending;
        $('btnToggleMic').disabled = media?.source?.kind !== 'camera' || switching || ending;
        $('btnToggleMic').textContent = media?.source?.mic ? 'Tắt micrô' : 'Bật micrô';
        if ($('micOffChip')) $('micOffChip').hidden = media?.source?.kind !== 'camera' || Boolean(media.source.mic);
        $('btnMuteOutput').disabled = !activeSource || switching || ending;
        for (const id of ['livePreviewCamera', 'livePreviewVideo', 'livePreviewLibrary']) {
            $(id).disabled = Boolean(activeSource) || switching || ending || closed;
        }
        document.querySelectorAll('[data-live-source],#livePlayLibrary').forEach(control => { control.disabled = switching || ending || closed; });
    }

    async function selectSource(source, savedVideo = '', previewOnly = false) {
        if (switching || ending || closed) return;
        if (previewOnly && activeSource) return;
        const selection = $('liveVideoQueue');
        const file = savedVideo || files[Number(selection?.value)];
        if (source.endsWith('video') && !file) {
            mediaNotice.textContent = 'Hãy chọn tệp MP4 trong thư viện.';
            return;
        }
        switching = true;
        mediaNotice.textContent = previewOnly ? 'Đang mở bản xem trước…' : 'Đang chuyển nguồn…';
        syncStudio();
        try {
            media ??= new window.LiveStudioMedia($('livekitLocalVideo'), () => {
                if (!activeSource) return;
                if (switching) nextAtEnd = true;
                else void nextSource();
            });
            await media.select(source, file, previewOnly || $('liveVideoLoop').checked, $('liveCameraDevice').value);
            media.setMuted(outputMuted);
            if (source === 'camera') void loadDevices();
            waiting.hidden = true;
            if (previewOnly) {
                mediaNotice.textContent = 'Xem trước · ' + sourceLabels[source];
                return;
            }
            await publish();
            await updateStream('live', source);
            activeSource = source;
            playlistActive = source.endsWith('video') && !savedVideo;
            playingIndex = playlistActive ? selection.selectedIndex : -1;
            waiting.hidden = true;
            const badge = $('liveSourceBadge');
            badge.textContent = sourceLabels[source];
            badge.hidden = false;
            startRecording();
            $('liveRecordingState').textContent = recorder ? 'Đang ghi nguồn phát hiện tại.' : 'Trình duyệt chưa ghi được luồng phát.';
            mediaNotice.textContent = sourceLabels[source] + (recorder ? ' · Đang ghi' : ' · Không ghi được bản ghi');
        } catch (error) {
            mediaNotice.textContent = error.message || 'Không thể chuyển nguồn.';
            if (!activeSource && media) {
                await hangUp().catch(() => {});
                media.close();
                media = null;
            }
        } finally {
            switching = false;
            syncStudio();
            if (nextAtEnd) {
                nextAtEnd = false;
                void nextSource();
            }
        }
    }

    async function hangUp() {
        if (publishing && room) {
            const participant = room.localParticipant;
            await Promise.all([media.videoOutput, media.audioOutput].map(track => participant.unpublishTrack(track, false)));
            publishing = false;
        }
    }

    async function pauseSource() {
        if (!activeSource) return;
        await updateStream('interrupted');
        await hangUp();
        media.release(media.source);
        media.source = null;
        activeSource = '';
        $('livekitLocalVideo').hidden = true;
        $('liveSourceBadge').hidden = true;
        waiting.hidden = false;
        mediaNotice.textContent = 'Đã dừng nguồn. Chọn nguồn khác hoặc kết thúc phiên.';
        syncStudio();
    }

    async function nextSource() {
        const list = $('liveVideoQueue');
        if (playlistActive && playingIndex >= 0 && playingIndex + 1 < files.length) {
            list.selectedIndex = playingIndex + 1;
            await selectSource(activeSource);
            return;
        }
        try {
            await pauseSource();
        } catch (error) {
            mediaNotice.textContent = error.message;
        }
    }

    async function loadDevices() {
        let devices;
        try {
            devices = await navigator.mediaDevices.enumerateDevices();
        } catch {
            return;
        }
        for (const [id, kind, label] of [['liveCameraDevice', 'videoinput', 'Camera'], ['liveMicDevice', 'audioinput', 'Micrô']]) {
            const select = $(id);
            const active = select.value;
            const options = devices.filter(device => device.kind === kind && device.deviceId && device.deviceId !== 'default');
            select.replaceChildren(new Option('Mặc định', ''), ...options.map((device, index) =>
                new Option(device.label || label + ' ' + (index + 1), device.deviceId)));
            select.value = options.some(device => device.deviceId === active) ? active : '';
        }
    }

    if (studio) {
        const picker = $('liveVideoFiles');
        picker?.addEventListener('change', () => {
            playlistActive = false;
            files = [...picker.files].filter(file => file.type === 'video/mp4' || /\.mp4$/i.test(file.name));
            const list = $('liveVideoQueue');
            list.replaceChildren(...files.map((file, index) => new Option(file.name, index)));
        });
        document.querySelectorAll('[data-live-source]').forEach(control => control.addEventListener('click', () => selectSource(control.dataset.liveSource)));
        $('livePreviewCamera')?.addEventListener('click', () => selectSource('camera', '', true));
        $('livePreviewVideo')?.addEventListener('click', () => selectSource('video', '', true));
        $('livePreviewLibrary')?.addEventListener('click', () => {
            const option = $('liveLibraryFiles').selectedOptions[0];
            if (option) selectSource(option.dataset.source, option.value, true);
        });
        $('livePlayLibrary')?.addEventListener('click', () => {
            const option = $('liveLibraryFiles').selectedOptions[0];
            if (option) selectSource(option.dataset.source, option.value);
        });
        $('liveLibraryUpload')?.addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const fields = new FormData(form);
            fields.set('action', 'video_upload');
            const result = await json('api_live_manage', {}, fields);
            if (result.ok) {
                const option = new Option(result.video.name, result.video.url, true, true);
                option.dataset.source = result.video.source;
                $('liveLibraryFiles').append(option);
                form.reset();
            }
            mediaNotice.textContent = result.ok ? 'Đã lưu video vào phiên.' : (result.message || 'Không tải lên được video.');
        });
        $('liveVideoLoop')?.addEventListener('change', event => {
            if (activeSource.endsWith('video')) media.source.video.loop = event.target.checked;
        });
        $('btnToggleMic')?.addEventListener('click', async () => {
            try {
                await media.toggleMicrophone($('liveMicDevice').value);
                void loadDevices();
            } catch (error) {
                mediaNotice.textContent = error.message;
            }
            syncStudio();
        });
        $('btnMuteOutput')?.addEventListener('click', event => {
            outputMuted = !outputMuted;
            media.setMuted(outputMuted);
            event.currentTarget.textContent = outputMuted ? 'Bật tiếng luồng' : 'Tắt tiếng luồng';
        });
        $('btnStopLive')?.addEventListener('click', async () => {
            try {
                await pauseSource();
            } catch (error) {
                mediaNotice.textContent = error.message;
            }
        });
        $('btnEndLive')?.addEventListener('click', async () => {
            ending = true;
            syncStudio();
            try {
                if (!['ended', 'recording_failed'].includes(status)) await updateStream('ended');
                const blob = await stopRecording();
                await hangUp();
                media?.close();
                media = null;
                activeSource = '';
                if (blob?.size) await uploadRecording(blob);
                const result = await json('api_live_products', {id: liveId});
                if (result.data?.recording_url) {
                    $('liveRecordingState').textContent = 'Bản ghi đã sẵn sàng.';
                    const link = $('liveRecordingLink');
                    link.href = result.data.recording_url;
                    link.hidden = false;
                    $('liveRecordingPanel').open = true;
                } else $('liveRecordingState').textContent = 'Phiên đã kết thúc. Không có bản ghi để xem lại.';
                $('livekitLocalVideo').hidden = true;
                $('liveSourceBadge').hidden = true;
                $('btnEndLive').disabled = true;
                closed = true;
                ending = false;
                syncStudio();
                refreshRoom();
            } catch (error) {
                mediaNotice.textContent = error.message;
                ending = false;
                syncStudio();
            }
        });
        setInterval(async () => {
            if (!activeSource || switching || ending) return;
            try {
                await publish();
                await updateStream('live');
            } catch (error) {
                mediaNotice.textContent = error.message;
            }
        }, 10000);
        if ($('liveCameraDevice')) void loadDevices();
    }

    $('btnEnableLiveSound')?.addEventListener('click', async () => {
        try {
            await room?.startAudio();
            await remoteAudio.play();
            remoteAudio.muted = false;
            syncSoundControl(false);
        } catch {
            setConnection('Không bật được âm thanh', 'warning');
        }
    });
    $('btnLiveMute')?.addEventListener('click', async () => {
        const player = activePlaybackElement();
        const nextMuted = !(remoteAudio?.muted ?? true);
        if (!nextMuted) {
            try {
                await room?.startAudio();
                if (remoteAudio) remoteAudio.muted = false;
                if (remoteAudio?.srcObject) await remoteAudio.play();
            } catch {
                if (remoteAudio) remoteAudio.muted = true;
                return;
            }
        }
        if (remoteAudio) remoteAudio.muted = nextMuted;
        if (player && player !== remote && !studio) player.muted = nextMuted;
        syncSoundControl(nextMuted);
    });
    $('btnLiveSettings')?.addEventListener('click', () => {
        const panel = $('liveSettingsPanel');
        const control = $('btnLiveSettings');
        if (!panel || !control) return;
        panel.hidden = !panel.hidden;
        control.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
    });
    $('liveVolume')?.addEventListener('input', event => {
        if (remoteAudio) remoteAudio.volume = Number(event.target.value);
    });
    $('liveFitVideo')?.addEventListener('change', event => {
        $('ssPlayer')?.classList.toggle('ss-player--fit', event.target.checked);
    });
    $('btnLiveFullscreen')?.addEventListener('click', async () => {
        const player = $('ssPlayer');
        if (!player) return;
        try {
            if (document.fullscreenElement) await document.exitFullscreen();
            else if (player.requestFullscreen) await player.requestFullscreen();
            else if (activePlaybackElement()?.webkitEnterFullscreen) activePlaybackElement().webkitEnterFullscreen();
            else throw new Error('Fullscreen unavailable');
        } catch (error) {
            setConnection('Trình duyệt không cho phép phóng to', 'warning');
        }
    });
    document.addEventListener?.('fullscreenchange', () => {
        const control = $('btnLiveFullscreen');
        if (!control) return;
        const expanded = document.fullscreenElement === $('ssPlayer');
        control.setAttribute('aria-label', expanded ? 'Thu nhỏ màn hình' : 'Phóng to màn hình');
        const icon = control.querySelector('i');
        if (icon) icon.className = 'fa-solid ' + (expanded ? 'fa-compress' : 'fa-expand');
    });
    $('btnLiveDeals')?.addEventListener('click', () => setDealsOpen(!dealsOpen));
    $('btnCloseLiveDeals')?.addEventListener('click', () => setDealsOpen(false));
    page.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (dealsOpen) setDealsOpen(false);
        else if ($('liveSettingsPanel') && !$('liveSettingsPanel').hidden) {
            $('btnLiveSettings').click();
            $('btnLiveSettings').focus();
        }
    });
    $('btnShareLive')?.addEventListener('click', async () => {
        const link = new URL(url('live', {id: liveId}), location.href);
        try {
            await navigator.clipboard.writeText(link.href);
            $('btnShareLive').textContent = 'Đã sao chép';
        } catch {
            window.prompt('Sao chép liên kết phiên phát', link.href);
        }
    });
    $('btnReaction')?.addEventListener('click', async () => {
        spawnFloatingHeart();
        if (room?.localParticipant) {
            try {
                const bytes = new TextEncoder().encode(JSON.stringify({ type: 'reaction' }));
                room.localParticipant.publishData(bytes, { reliable: false });
            } catch (e) {}
        }
        const result = await json('api_live_reaction', {}, {live_id: liveId});
        if (result.ok) $('reactionCount').textContent = result.reaction_count ?? 0;
    });

    let voucherCountdownTimer = null;
    function showVoucherPopup(v) {
        const popup = $('liveVoucherPopup');
        if (!popup || !v) return;
        const nextKey = String(v.ma_code || '') + ':' + String(v.ket_thuc || '');
        if (!v.ma_code || nextKey === voucherKey) return;
        voucherKey = nextKey;
        if ($('liveVoucherCode')) $('liveVoucherCode').textContent = v.ma_code || '';
        if ($('liveVoucherTitle')) $('liveVoucherTitle').textContent = v.ten_voucher || v.ma_code;
        if ($('liveVoucherValue')) $('liveVoucherValue').textContent = v.loai_giam === 'percent' ? v.gia_tri_giam + '%' : (Number(v.gia_tri_giam || 0).toLocaleString('vi-VN') + ' đ');
        popup.hidden = false;

        const btnClaim = $('btnClaimVoucher');
        if (btnClaim) {
            btnClaim.disabled = false;
            btnClaim.textContent = 'Nhận voucher';
            btnClaim.onclick = async () => {
                btnClaim.disabled = true;
                btnClaim.textContent = 'Đang nhận...';
                try {
                    const res = await json('api_live_claim_voucher', {}, { room_id: liveId, ma_code: v.ma_code });
                    if (res.ok) {
                        btnClaim.textContent = '✓ Đã nhận';
                    } else {
                        btnClaim.textContent = res.message || 'Lỗi nhận voucher';
                    }
                } catch (e) {
                    btnClaim.textContent = 'Nhận voucher';
                    btnClaim.disabled = false;
                }
            };
        }

        if (voucherCountdownTimer) clearInterval(voucherCountdownTimer);
        const endTs = v.ket_thuc ? new Date(v.ket_thuc.replace(' ', 'T')).getTime() : Date.now() + (v.duration_minutes || 10) * 60000;
        voucherCountdownTimer = setInterval(() => {
            const remain = Math.max(0, Math.floor((endTs - Date.now()) / 1000));
            const m = String(Math.floor(remain / 60)).padStart(2, '0');
            const s = String(remain % 60).padStart(2, '0');
            if ($('liveVoucherTimer')) $('liveVoucherTimer').textContent = `${m}:${s}`;
            if (remain <= 0) {
                clearInterval(voucherCountdownTimer);
                popup.hidden = true;
            }
        }, 1000);
    }

    function hideVoucherPopup() {
        voucherKey = '';
        const popup = $('liveVoucherPopup');
        if (popup) popup.hidden = true;
        if (voucherCountdownTimer) clearInterval(voucherCountdownTimer);
    }

    function showPinnedComment(text) {
        const banner = $('livePinnedCommentBanner');
        const txt = $('livePinnedCommentText');
        if (banner && txt) {
            txt.textContent = text;
            banner.hidden = false;
        }
    }

    function hidePinnedComment() {
        const banner = $('livePinnedCommentBanner');
        if (banner) banner.hidden = true;
    }

    function spawnFloatingHeart() {
        const container = $('liveHeartsContainer') || $('ssPlayer');
        if (!container) return;
        const hearts = ['💚', '❤️', '💖', '✨', '🔥'];
        const heart = document.createElement('span');
        heart.className = 'floating-heart';
        heart.textContent = hearts[Math.floor(Math.random() * hearts.length)];
        heart.style.right = (15 + Math.random() * 30) + 'px';
        container.appendChild(heart);
        setTimeout(() => heart.remove(), 2000);
    }

    function handleDataChannelEvent(msg) {
        if (!msg || !msg.type) return;
        if (['pin_product', 'products_changed', 'voucher_dropped', 'voucher_ended'].includes(msg.type)) {
            void refreshRoom();
        } else if (msg.type === 'pin_comment' || msg.type === 'unpin_comment') {
            void loadChat();
        } else if (msg.type === 'reaction') {
            spawnFloatingHeart();
        } else if (msg.type === 'chat_message' || msg.type === 'chat_moderated') {
            void loadChat();
        }
    }

    $('liveToggleSyna')?.addEventListener('change', async event => {
        const toggle = event.target;
        toggle.disabled = true;
        const result = await json('api_live_manage', {}, {action: 'syna', enabled: toggle.checked ? '1' : '0'});
        toggle.disabled = false;
        if (result.ok) setSynaEnabled(toggle.checked);
        else toggle.checked = aiEnabled;
        studioNotice.textContent = result.ok ? 'Đã cập nhật SYNA.' : 'Không thể cập nhật SYNA.';
    });
    for (const [id, action] of [['liveStaffForm', 'assign'], ['livePinForm', 'product_pin']]) {
        $(id)?.addEventListener('submit', async event => {
            event.preventDefault();
            const fields = new FormData(event.target);
            fields.set('action', event.submitter?.value || action);
            const result = await json('api_live_manage', {}, fields);
            studioNotice.textContent = result.ok ? 'Đã lưu.' : (result.message || 'Không thể lưu thay đổi.');
            if (result.ok) refreshRoom();
        });
    }
    $('liveUnpinProduct')?.addEventListener('click', async () => {
        const result = await json('api_live_manage', {}, {action: 'product_unpin'});
        studioNotice.textContent = result.ok ? 'Đã bỏ ghim sản phẩm.' : 'Không thể bỏ ghim.';
        if (result.ok) refreshRoom();
    });
    const productModal = $('liveProductModal');
    let activeModalProduct = null;
    const closeProductModal = () => { if (productModal) productModal.hidden = true; };
    function openProductModal(product, dealId = product?.deal_id || 'pinned') {
        if (!productModal || !product) return;
        activeModalProduct = {...product, deal_id: dealId};
        $('liveProductModalName').textContent = product.ten_san_pham || product.name || 'Sản phẩm đang giới thiệu';
        $('liveProductModalBrand').textContent = product.thuong_hieu || product.brand || '';
        $('liveProductModalPrice').textContent = vnd(product.price || product.gia_uu_dai_live || 0);
        $('liveProductModalMeta').textContent = 'Mã sản phẩm: ' + (product.ma_san_pham || product.product_id || '');
        const image = $('liveProductModalImage');
        image.src = product.link_hinh_anh || product.image || '';
        image.alt = product.ten_san_pham || product.name || '';
        productModal.hidden = false;
    }
    $('btnCloseLiveProductModal')?.addEventListener('click', closeProductModal);
    $('btnCloseLiveProductModal2')?.addEventListener('click', closeProductModal);
    productModal?.addEventListener('click', event => { if (event.target === productModal) closeProductModal(); });
    $('livePinnedLink')?.addEventListener('click', () => {
        openProductModal(currentPinnedProduct);
    });
    $('livePinnedOverlay')?.addEventListener('click', () => openProductModal(currentPinnedProduct));
    $('btnModalAddToCart')?.addEventListener('click', () => {
        closeProductModal();
        if (activeModalProduct) {
            $('livePinnedProductId').value = activeModalProduct.ma_san_pham || activeModalProduct.product_id || '';
            $('liveBuyNowForm').querySelector('[name="deal_id"]').value = activeModalProduct.deal_id || 'pinned';
        }
        $('liveBuyNowForm')?.requestSubmit();
    });
    document.querySelectorAll('[data-studio-tab]').forEach(control => control.addEventListener('click', () => {
        const panel = $(control.dataset.studioTab);
        if (!panel) return;
        if (panel.tagName === 'DETAILS') panel.open = true;
        panel.scrollIntoView({block: 'nearest'});
        if (panel === chat && status === 'live') input.focus();
    }));
    page.addEventListener('submit', async event => {
        const cart = event.target;
        if (!cart.matches('.live-add-to-cart-form,#liveBuyNowForm')) return;
        event.preventDefault();
        const control = cart.querySelector('button');
        if (!control || control.disabled) return;
        cartRequests.add(control);
        control.disabled = true;
        const original = Array.from(control.childNodes);
        const result = await json('api_live_add_to_cart', {}, new FormData(cart));
        control.textContent = result.ok ? 'Đã thêm' : (result.message || 'Không thêm được');
        if (result.ok) {
            updateHeaderCartCount(result.cart_count);
            showCartToast('Đã thêm vào giỏ hàng · ' + result.cart_count + ' sản phẩm');
        }
        setTimeout(() => {
            cartRequests.delete(control);
            control.replaceChildren(...original);
            control.disabled = status !== 'live';
        }, 1800);
    });

    const vnd = price => new Intl.NumberFormat('vi-VN').format(price) + ' đ';
    function updatePinnedCountdown() {
        const countdown = $('livePinCountdown');
        if (!countdown || $('livePinnedBody')?.hidden) return;
        if (pinnedUnlimited) {
            countdown.hidden = false;
            countdown.textContent = 'Đang giới thiệu · Không giới hạn thời gian';
            return;
        }
        if (!pinnedEndsAt) {
            countdown.hidden = true;
            return;
        }
        const now = Math.floor((Date.now() + liveServerOffsetMs) / 1000);
        const remaining = Math.max(0, pinnedEndsAt - now);
        const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
        const seconds = String(remaining % 60).padStart(2, '0');
        countdown.hidden = false;
        countdown.textContent = remaining > 0 ? `Đang giới thiệu · Còn ${minutes}:${seconds}` : 'Đã hết thời gian giới thiệu';
    }
    function renderDeals(deals, studioDeals) {
        const signature = JSON.stringify([deals, studioDeals]);
        if (visibleDeals === signature) return;
        visibleDeals = signature;
        const trigger = $('btnLiveDeals');
        if (trigger) trigger.hidden = !deals.length;
        if ($('liveDealCount')) $('liveDealCount').textContent = String(deals.length);
        if (!deals.length) {
            dealsOpen = false;
            $('liveDeals').hidden = true;
            trigger?.setAttribute('aria-expanded', 'false');
        }
        $('liveDealsList')?.replaceChildren(...deals.map(deal => {
            const row = document.createElement('article');
            row.className = 'ss-deal';
            const image = document.createElement('img');
            if (deal.image) image.src = deal.image;
            else image.hidden = true;
            image.alt = '';
            const text = document.createElement('div');
            text.className = 'ss-deal__meta';
            const name = document.createElement('strong');
            name.textContent = deal.name;
            const details = document.createElement('small');
            details.textContent = vnd(deal.price) + (deal.limit > 0 ? ' · Tối đa ' + deal.limit : '');
            text.append(name, details);
            if (deal.original_price > deal.price && deal.price > 0) {
                const regular = document.createElement('del');
                regular.textContent = vnd(deal.original_price);
                text.append(regular);
            }
            if (deal.ends_at) {
                const end = document.createElement('small');
                end.textContent = 'Đến ' + new Intl.DateTimeFormat('vi-VN', {
                    hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Ho_Chi_Minh',
                }).format(new Date(deal.ends_at * 1000));
                text.append(end);
            }
            const actions = document.createElement('div');
            actions.className = 'ss-deal__actions';
            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'ss-btn ss-btn--ghost';
            view.textContent = 'Xem';
            view.setAttribute('aria-label', 'Xem nhanh ' + deal.name);
            view.addEventListener('click', () => openProductModal(deal, deal.id));
            const cart = document.createElement('form');
            cart.className = 'live-add-to-cart-form';
            for (const [key, value] of [['product_id', deal.product_id], ['live_id', liveId], ['deal_id', deal.id]]) {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = key;
                field.value = value;
                cart.appendChild(field);
            }
            const add = document.createElement('button');
            add.className = 'ss-btn ss-btn--ghost';
            add.textContent = '+ Giỏ';
            add.disabled = status !== 'live';
            cart.appendChild(add);
            actions.append(view, cart);
            row.append(image, text, actions);
            return row;
        }));
        $('liveStudioDeals')?.replaceChildren(...studioDeals.map(deal => {
            const row = document.createElement('p');
            row.textContent = deal.name + ' ';
            row.appendChild(button('Gỡ', async () => {
                const result = await json('api_live_manage', {}, {action: 'deal_delete', deal_id: deal.id});
                studioNotice.textContent = result.ok ? 'Đã gỡ sản phẩm khỏi phiên.' : 'Không thể gỡ sản phẩm.';
                if (result.ok) refreshRoom();
            }));
            return row;
        }));
    }
    async function refreshRoom() {
        const result = await json('api_live_products', {id: liveId, queue: $('liveSynaQueue') ? '1' : '0'});
        if (!result.ok || !result.data) return;
        const data = result.data;
        const streamerAvatar = $('liveStreamerAvatar');
        if (streamerAvatar && streamerAvatar.getAttribute('src') !== (data.streamer_avatar || null)) {
            if (data.streamer_avatar) streamerAvatar.src = data.streamer_avatar;
            else streamerAvatar.removeAttribute('src');
            streamerAvatar.hidden = !data.streamer_avatar;
        }
        liveServerOffsetMs = Number(data.server_time || 0) * 1000 - Date.now();
        pinnedEndsAt = Number(data.pin_ends_at || 0);
        pinnedUnlimited = Boolean(data.pin_unlimited);
        if (!switching && !ending) updateStatus(data.status);
        if (data.status === 'replay_ready' && !data.recording_available && $('liveWaitingMessage')) $('liveWaitingMessage').textContent = 'Bản ghi không khả dụng.';
        setSynaEnabled(Boolean(data.bat_ai_cohost));
        const queue = data.deals || [];
        const offers = data.status === 'live' ? queue.filter(deal => deal.available_now !== false && deal.id !== 'pinned') : [];
        if (data.status === 'live' && data.pinned_product) {
            const pinned = data.pinned_product;
            offers.unshift({
                id: 'pinned', product_id: pinned.ma_san_pham, name: pinned.ten_san_pham,
                image: pinned.link_hinh_anh, price: data.gia_uu_dai_live || data.gia_ban_goc,
                original_price: data.gia_ban_goc, limit: pinned.deal_limit, ends_at: data.pin_ends_at,
            });
        }
        renderDeals(offers, queue);
        if ($('liveSynaQueue')) $('liveSynaQueue').textContent = data.syna_queue === null
            ? 'Hàng đợi SYNA chưa kết nối.' : 'Đang chờ hoặc xử lý: ' + data.syna_queue + ' · Giới hạn: ' + data.syna_limit + ' lượt/phút';
        const pinned = data.pinned_product;
        const featuredOffer = pinned ? {
            ...pinned,
            price: data.gia_uu_dai_live || data.gia_ban_goc,
            deal_id: 'pinned',
        } : offers[0] ? {
            ma_san_pham: offers[0].product_id,
            ten_san_pham: offers[0].name,
            thuong_hieu: offers[0].brand,
            link_hinh_anh: offers[0].image,
            price: offers[0].price,
            deal_id: offers[0].id,
        } : null;
        currentPinnedProduct = featuredOffer;
        const pinOverlay = $('livePinnedOverlay');
        if (pinOverlay) {
            pinOverlay.hidden = !featuredOffer || data.status !== 'live';
            if (featuredOffer) {
                $('livePinnedOverlayName').textContent = featuredOffer.ten_san_pham;
                $('livePinnedOverlayPrice').textContent = vnd(featuredOffer.price);
                const pinImage = $('livePinnedOverlayImage');
                pinImage.hidden = !featuredOffer.link_hinh_anh;
                pinImage.src = featuredOffer.link_hinh_anh || '';
                pinImage.alt = featuredOffer.ten_san_pham || '';
            }
        }
        $('livePinnedBody').hidden = !pinned;
        $('livePinnedEmpty').hidden = Boolean(pinned);
        if (pinned) {
            $('livePinnedName').textContent = pinned.ten_san_pham;
            $('livePinnedBrand').textContent = pinned.thuong_hieu;
            $('livePinnedPrice').textContent = vnd(data.gia_uu_dai_live || data.gia_ban_goc);
            $('livePinnedProductId').value = pinned.ma_san_pham;
            $('livePinnedImg').hidden = !pinned.link_hinh_anh;
            if (pinned.link_hinh_anh) $('livePinnedImg').src = pinned.link_hinh_anh;
            $('livePinnedImg').alt = pinned.ten_san_pham;
            $('livePinnedMarketPrice').hidden = !data.discount_percent;
            $('livePinnedMarketPrice').textContent = vnd(data.gia_thi_truong);
            $('livePinnedDiscountBadge').hidden = !data.discount_percent;
            $('livePinnedDiscountBadge').textContent = '-' + data.discount_percent + '%';
        }
        updatePinnedCountdown();
        if (data.active_voucher) {
            showVoucherPopup(data.active_voucher);
        } else {
            hideVoucherPopup();
        }
        if (!studio) {
            const source = $('liveSourceBadge');
            source.textContent = sourceLabels[data.stream_source] || '';
            source.hidden = status !== 'live' || !source.textContent;
            if (status === 'live') {
                if (room?.state === 'connected' && !document.hidden && Date.now() - connectedAt > 10000 &&
                    remote.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) await room.disconnect();
                await connect();
            }
            if (['ended', 'replay_ready', 'recording_failed'].includes(status)) {
                room?.disconnect();
                if (data.recording_url && !replay && !$('liveInPageReplay')) {
                    const player = document.createElement('video');
                    player.id = 'liveInPageReplay';
                    player.controls = true;
                    player.playsInline = true;
                    player.preload = 'metadata';
                    player.src = data.recording_url;
                    remote.parentNode.appendChild(player);
                    $('ssPlayer').classList.add('ss-player--replay');
                    remote.hidden = true;
                    if (remoteAudio) remoteAudio.muted = true;
                    waiting.hidden = true;
                }
            }
        }
    }

    loadChat();
    setInterval(loadChat, 1500);
    refreshRoom();
    setInterval(refreshRoom, 3000);
    setInterval(updatePinnedCountdown, 1000);
    // A publisher renews freshness without changing the session or interrupting recording.
    // Keep this active in background tabs; viewers never write publisher state.
    setInterval(async () => {
        if (!studio || !publishing || !activeSource || ending || closed || heartbeatPending || room?.state !== 'connected') return;
        heartbeatPending = true;
        try {
            await updateStream('heartbeat');
        } catch (error) {
            mediaNotice.textContent = error.message || 'Không cập nhật được kết nối phát.';
        } finally {
            heartbeatPending = false;
        }
    }, 15000);
    async function refreshPublisherHeartbeat() {
        if (!studio || !publishing || !activeSource || ending || closed || heartbeatPending || room?.state !== 'connected') return;
        heartbeatPending = true;
        try { await updateStream('heartbeat'); }
        catch (error) { console.warn('[SkinSyntax Live] publisher_heartbeat_failed', {name: error?.name || 'Error'}); }
        finally { heartbeatPending = false; }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void refreshPublisherHeartbeat(); });
    window.addEventListener('focus', () => { void refreshPublisherHeartbeat(); });
    setInterval(async () => {
        if (status !== 'live' || document.hidden) return;
        const result = await json('api_live_ping', {room: liveId});
        if (result.ok) $('liveViewerCount').textContent = result.active_viewers ?? 0;
    }, 5000);
})();
