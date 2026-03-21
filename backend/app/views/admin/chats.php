<?php
$pendingConversations = $pendingConversations ?? [];
$allConversations = $allConversations ?? [];
$activeConversationId = (int)($activeConversationId ?? 0);
$messages = $messages ?? [];
?>

<?php
$initialChatState = [
    'activeConversationId' => $activeConversationId,
    'pendingConversations' => $pendingConversations,
    'allConversations' => $allConversations,
    'messages' => $messages,
];
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Hỗ trợ khách hàng qua chat</h1>
        <p class="text-muted mb-0">Theo dõi lịch sử hội thoại và phản hồi trực tiếp cho khách hàng.</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-0">
                    <div class="p-3 border-bottom bg-light-subtle">
                        <div class="small fw-semibold text-uppercase text-muted">Chat chờ phản hồi</div>
                    </div>
                    <div class="list-group list-group-flush border-bottom" id="pending-conversations-list">
                        <?php if (empty($pendingConversations)): ?>
                            <div class="p-3 text-center text-muted small">Không còn chat nào đang chờ hỗ trợ.</div>
                        <?php else: ?>
                            <?php foreach ($pendingConversations as $conversation): ?>
                                <?php $maKh = (int)($conversation['ma_kh'] ?? 0); ?>
                                <a href="index.php?r=staff_chats&ma_kh=<?= $maKh ?>" class="list-group-item list-group-item-action p-3 <?= $maKh === $activeConversationId ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold"><?= h($conversation['ho_ten'] ?? 'Khách hàng') ?></div>
                                        <span class="badge text-bg-danger"><?= h((string)($conversation['tin_chua_phan_hoi'] ?? 0)) ?></span>
                                    </div>
                                    <div class="small <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['email'] ?? '') ?></div>
                                    <div class="small mt-1 <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['tin_nhan_moi'] ?? '') ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="p-3 border-bottom bg-light-subtle">
                        <div class="small fw-semibold text-uppercase text-muted">Tất cả chat</div>
                    </div>
                    <div class="list-group list-group-flush" id="all-conversations-list">
                        <?php if (empty($allConversations)): ?>
                            <div class="p-4 text-center text-muted">Chưa có cuộc hội thoại nào.</div>
                        <?php else: ?>
                            <?php foreach ($allConversations as $conversation): ?>
                                <?php $maKh = (int)($conversation['ma_kh'] ?? 0); ?>
                                <a href="index.php?r=staff_chats&ma_kh=<?= $maKh ?>" class="list-group-item list-group-item-action p-3 <?= $maKh === $activeConversationId ? 'active' : '' ?>">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold"><?= h($conversation['ho_ten'] ?? 'Khách hàng') ?></div>
                                        <?php if (!empty($conversation['dang_cho_phan_hoi'])): ?>
                                            <span class="badge text-bg-danger">Chờ phản hồi</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Đã phản hồi</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['email'] ?? '') ?></div>
                                    <div class="small mt-1 <?= $maKh === $activeConversationId ? 'text-white-50' : 'text-muted' ?>"><?= h($conversation['tin_nhan_moi'] ?? '') ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4 d-flex flex-column" style="min-height: 620px;" id="staff-chat-panel">
                    <?php if ($activeConversationId <= 0): ?>
                        <div class="m-auto text-center text-muted" id="chat-empty-state">Chọn một khách hàng để xem lịch sử chat.</div>
                    <?php else: ?>
                        <div class="flex-grow-1 overflow-auto mb-3 pe-2" style="max-height: 500px;" id="staff-chat-messages">
                            <?php foreach ($messages as $message): ?>
                                <?php $isStaff = !empty($message['ma_nv']); ?>
                                <div class="d-flex <?= $isStaff ? 'justify-content-end' : 'justify-content-start' ?> mb-3">
                                    <div class="p-3 rounded-4 <?= $isStaff ? 'bg-primary text-white' : 'bg-light' ?>" style="max-width: 78%;">
                                        <div class="small fw-semibold mb-1"><?= h($isStaff ? ($message['ten_nhan_vien'] ?? 'Nhân viên') : ($message['ten_khach_hang'] ?? 'Khách hàng')) ?></div>
                                        <div><?= nl2br_safe($message['noi_dung'] ?? '') ?></div>
                                        <div class="small mt-2 <?= $isStaff ? 'text-white-50' : 'text-muted' ?>"><?= h(!empty($message['thoi_gian']) ? date('d/m/Y H:i', strtotime((string)$message['thoi_gian'])) : '') ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="index.php?r=staff_chat_send" class="row g-2 mt-auto" id="staff-chat-form">
                            <input type="hidden" name="ma_kh" value="<?= $activeConversationId ?>">
                            <div class="col-md-10">
                                <textarea class="form-control" name="noi_dung" rows="3" placeholder="Nhập phản hồi cho khách hàng..." id="staff-chat-textarea"></textarea>
                            </div>
                            <div class="col-md-2 d-grid">
                                <button type="submit" class="btn btn-primary" id="staff-chat-submit">Gửi</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var state = <?= json_encode($initialChatState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var pendingList = document.getElementById('pending-conversations-list');
    var allList = document.getElementById('all-conversations-list');
    var chatMessages = document.getElementById('staff-chat-messages');
    var chatPanel = document.getElementById('staff-chat-panel');
    var chatForm = document.getElementById('staff-chat-form');
    var chatTextarea = document.getElementById('staff-chat-textarea');
    var chatSubmit = document.getElementById('staff-chat-submit');
    var emptyState = document.getElementById('chat-empty-state');
    var refreshTimer = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function nl2br(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }

        var parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return parsed.toLocaleString('vi-VN', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function renderConversationItem(conversation, isPending) {
        var maKh = Number(conversation.ma_kh || 0);
        var isActive = maKh === Number(state.activeConversationId || 0);
        var badge = '';

        if (isPending) {
            badge = '<span class="badge text-bg-danger">' + escapeHtml(conversation.tin_chua_phan_hoi || 0) + '</span>';
        } else if (conversation.dang_cho_phan_hoi) {
            badge = '<span class="badge text-bg-danger">Chờ phản hồi</span>';
        } else {
            badge = '<span class="badge text-bg-secondary">Đã phản hồi</span>';
        }

        var mutedClass = isActive ? 'text-white-50' : 'text-muted';
        return '' +
            '<a href="index.php?r=staff_chats&ma_kh=' + maKh + '" class="list-group-item list-group-item-action p-3 ' + (isActive ? 'active' : '') + '">' +
                '<div class="d-flex justify-content-between gap-2">' +
                    '<div class="fw-semibold">' + escapeHtml(conversation.ho_ten || 'Khách hàng') + '</div>' +
                    badge +
                '</div>' +
                '<div class="small ' + mutedClass + '">' + escapeHtml(conversation.email || '') + '</div>' +
                '<div class="small mt-1 ' + mutedClass + '">' + escapeHtml(conversation.tin_nhan_moi || '') + '</div>' +
            '</a>';
    }

    function renderConversationList(container, conversations, isPending, emptyText) {
        if (!container) {
            return;
        }

        if (!Array.isArray(conversations) || conversations.length === 0) {
            container.innerHTML = '<div class="p-3 text-center text-muted small">' + escapeHtml(emptyText) + '</div>';
            return;
        }

        container.innerHTML = conversations.map(function (conversation) {
            return renderConversationItem(conversation, isPending);
        }).join('');
    }

    function renderMessages(messages) {
        if (!chatMessages) {
            return;
        }

        if (!Array.isArray(messages) || messages.length === 0) {
            chatMessages.innerHTML = '<div class="m-auto text-center text-muted py-5">Chưa có tin nhắn nào trong cuộc hội thoại này.</div>';
            return;
        }

        chatMessages.innerHTML = messages.map(function (message) {
            var isStaff = !!message.ma_nv;
            return '' +
                '<div class="d-flex ' + (isStaff ? 'justify-content-end' : 'justify-content-start') + ' mb-3">' +
                    '<div class="p-3 rounded-4 ' + (isStaff ? 'bg-primary text-white' : 'bg-light') + '" style="max-width: 78%;">' +
                        '<div class="small fw-semibold mb-1">' + escapeHtml(isStaff ? (message.ten_nhan_vien || 'Nhân viên') : (message.ten_khach_hang || 'Khách hàng')) + '</div>' +
                        '<div>' + nl2br(message.noi_dung || '') + '</div>' +
                        '<div class="small mt-2 ' + (isStaff ? 'text-white-50' : 'text-muted') + '">' + escapeHtml(formatDateTime(message.thoi_gian || '')) + '</div>' +
                    '</div>' +
                '</div>';
        }).join('');

        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function ensureChatFormVisible(activeConversationId) {
        if (!chatPanel) {
            return;
        }

        if (activeConversationId > 0) {
            if (emptyState) {
                emptyState.remove();
                emptyState = null;
            }

            if (!chatMessages) {
                var messageBox = document.createElement('div');
                messageBox.id = 'staff-chat-messages';
                messageBox.className = 'flex-grow-1 overflow-auto mb-3 pe-2';
                messageBox.style.maxHeight = '500px';
                chatPanel.prepend(messageBox);
                chatMessages = messageBox;
            }
        }
    }

    function updateUi(nextState) {
        state = nextState || state;
        renderConversationList(pendingList, state.pendingConversations || [], true, 'Không còn chat nào đang chờ hỗ trợ.');
        renderConversationList(allList, state.allConversations || [], false, 'Chưa có cuộc hội thoại nào.');

        if (Number(state.activeConversationId || 0) > 0) {
            ensureChatFormVisible(Number(state.activeConversationId || 0));
            renderMessages(state.messages || []);
        }
    }

    function fetchState() {
        var activeConversationId = Number(state.activeConversationId || 0);
        return fetch('index.php?r=staff_chat_state&ma_kh=' + activeConversationId, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result && result.ok && result.data) {
                    updateUi(result.data);
                }
            })
            .catch(function () {
            });
    }

    if (chatForm) {
        chatForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var formData = new FormData(chatForm);
            var content = String(formData.get('noi_dung') || '').trim();
            if (!content) {
                if (chatTextarea) {
                    chatTextarea.focus();
                }
                return;
            }

            if (chatSubmit) {
                chatSubmit.disabled = true;
                chatSubmit.textContent = 'Đang gửi...';
            }

            fetch(chatForm.getAttribute('action') || 'index.php?r=staff_chat_send', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result && result.ok && result.data) {
                        updateUi(result.data);
                        if (chatTextarea) {
                            chatTextarea.value = '';
                            chatTextarea.focus();
                        }
                    } else if (result && result.message) {
                        window.alert(result.message);
                    }
                })
                .catch(function () {
                    window.alert('Không thể gửi phản hồi lúc này.');
                })
                .finally(function () {
                    if (chatSubmit) {
                        chatSubmit.disabled = false;
                        chatSubmit.textContent = 'Gửi';
                    }
                });
        });
    }

    updateUi(state);
    refreshTimer = window.setInterval(fetchState, 5000);
    window.addEventListener('beforeunload', function () {
        if (refreshTimer) {
            window.clearInterval(refreshTimer);
        }
    });
});
</script>