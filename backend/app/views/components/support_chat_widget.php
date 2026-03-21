<?php
$supportChatEnabled = false;
$supportChatMessages = [];
$supportChatCustomerId = 0;

if (is_logged_in() && current_role() === 'khach_hang') {
  if (!class_exists('QuanTri')) {
    require_once __DIR__ . '/../../models/QuanTri.php';
  }

  $supportChatModel = new QuanTri($pdo);
  $supportChatUser = current_user() ?? [];
  $supportChatCustomer = $supportChatModel->getCustomerByEmail((string)($supportChatUser['email'] ?? ''), (string)($supportChatUser['ho_ten'] ?? ''));
  $supportChatCustomerId = (int)($supportChatCustomer['ma_kh'] ?? 0);
  if ($supportChatCustomerId > 0) {
    $supportChatMessages = $supportChatModel->getChatMessages($supportChatCustomerId);
    $supportChatEnabled = true;
  }
}
?>
<?php if ($supportChatEnabled): ?>
  <div class="support-chat-widget" data-support-chat-widget>
    <button class="support-chat-widget__trigger" type="button" data-support-chat-toggle aria-expanded="false" aria-controls="supportChatPanel" title="Mở chat hỗ trợ">
      <span class="support-chat-widget__trigger-icon"><i class="fa-regular fa-comments"></i></span>
      <span class="support-chat-widget__trigger-text">
        <strong>Chat hỗ trợ</strong>
        <small>Trao đổi ngay với chúng tôi!</small>
      </span>
    </button>

    <section class="support-chat-widget__panel" id="supportChatPanel" data-support-chat-panel hidden>
      <header class="support-chat-widget__panel-head">
        <div>
          <div class="support-chat-widget__panel-title">Hỗ trợ khách hàng</div>
          <div class="support-chat-widget__panel-subtitle">Hỏi về đơn hàng, sản phẩm hoặc routine chăm da.</div>
        </div>
        <div class="support-chat-widget__panel-actions">
          <button class="support-chat-widget__expand" type="button" data-support-chat-expand aria-pressed="false" aria-label="Phóng to chat hỗ trợ">
            <i class="fa-solid fa-expand"></i>
          </button>
          <button class="support-chat-widget__close" type="button" data-support-chat-close aria-label="Đóng chat hỗ trợ">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </header>

      <div class="support-chat-widget__stream" data-support-chat-stream>
        <?php if (empty($supportChatMessages)): ?>
          <div class="support-chat-widget__empty">
            <i class="fa-regular fa-comment-dots"></i>
            <div>Chưa có tin nhắn nào. Gửi câu hỏi để nhân viên hỗ trợ phản hồi cho bạn.</div>
          </div>
        <?php else: ?>
          <?php foreach ($supportChatMessages as $message): ?>
            <?php $isStaff = !empty($message['ma_nv']); ?>
            <div class="support-chat-widget__bubble-row <?= $isStaff ? 'support-chat-widget__bubble-row--staff' : 'support-chat-widget__bubble-row--customer' ?>">
              <div class="support-chat-widget__bubble <?= $isStaff ? 'support-chat-widget__bubble--staff' : 'support-chat-widget__bubble--customer' ?>">
                <div class="support-chat-widget__bubble-author"><?= h($isStaff ? ($message['ten_nhan_vien'] ?? 'Nhân viên hỗ trợ') : ($message['ten_khach_hang'] ?? 'Bạn')) ?></div>
                <div class="support-chat-widget__bubble-text"><?= nl2br_safe($message['noi_dung'] ?? '') ?></div>
                <div class="support-chat-widget__bubble-time"><?= h(!empty($message['thoi_gian']) ? date('d/m/Y H:i', strtotime((string)($message['thoi_gian'] ?? ''))) : '') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <form method="post" action="<?= BASE_URL ?>/index.php?r=chat_send" class="support-chat-widget__form">
        <label class="form-label visually-hidden" for="supportChatInput">Nội dung cần hỗ trợ</label>
        <div class="support-chat-widget__composer">
          <button class="support-chat-widget__icon-btn" type="button" data-support-chat-sticker-toggle aria-expanded="false" aria-controls="supportChatStickerTray" title="Chèn sticker">
            <i class="fa-regular fa-face-smile"></i>
          </button>
          <textarea class="form-control support-chat-widget__textarea" id="supportChatInput" name="noi_dung" rows="2" placeholder="Nhập câu hỏi bạn đang gặp..." required></textarea>
          <button type="submit" class="btn support-chat-widget__submit">Gửi</button>
        </div>
        <div class="support-chat-widget__sticker-tray" id="supportChatStickerTray" data-support-chat-sticker-tray hidden>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="🙂">🙂</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="🥰">🥰</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="😍">😍</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="👍">👍</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="🙏">🙏</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="❤️">❤️</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="✨">✨</button>
          <button type="button" class="support-chat-widget__sticker" data-support-chat-sticker="🔥">🔥</button>
        </div>
        <div class="support-chat-widget__form-row">
          <span>Phản hồi sẽ xuất hiện ngay trong khung chat này.</span>
        </div>
      </form>
    </section>
  </div>

  <style>
    .support-chat-widget {
      position: fixed;
      right: 20px;
      bottom: 22px;
      z-index: 1085;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 14px;
    }

    .support-chat-widget__trigger {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      border: 0;
      border-radius: 999px;
      padding: 12px 18px;
      background: linear-gradient(135deg, #b8e6c3 0%, #d9f5df 100%);
      color: #14532d;
      box-shadow: 0 18px 34px rgba(34, 197, 94, 0.22);
      font-weight: 700;
      transition: transform 0.22s ease, box-shadow 0.22s ease, background 0.22s ease;
    }

    .support-chat-widget__trigger:hover {
      transform: translateY(-2px);
      box-shadow: 0 22px 40px rgba(34, 197, 94, 0.24);
    }

    .support-chat-widget.is-open .support-chat-widget__trigger {
      display: none;
    }

    .support-chat-widget__trigger-icon {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: rgba(20, 83, 45, 0.08);
      font-size: 18px;
    }

    .support-chat-widget__trigger-icon i {
      transition: transform 0.24s ease;
    }

    .support-chat-widget.is-open .support-chat-widget__trigger-icon i {
      transform: scale(1.08);
    }

    .support-chat-widget__trigger-text {
      display: grid;
      text-align: left;
      line-height: 1.25;
    }

    .support-chat-widget__trigger-text small {
      color: rgba(20, 83, 45, 0.76);
      font-size: 12px;
      font-weight: 600;
    }

    .support-chat-widget__panel {
      width: min(460px, calc(100vw - 24px));
      max-height: min(78vh, 760px);
      border-radius: 24px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 28px 60px rgba(15, 23, 42, 0.22);
      border: 1px solid #e9edf4;
      display: flex;
      flex-direction: column;
      opacity: 0;
      transform: translateY(18px) scale(0.96);
      transform-origin: bottom right;
      pointer-events: none;
      transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.28s ease;
      will-change: transform, opacity;
    }

    .support-chat-widget.is-expanded .support-chat-widget__panel {
      width: min(860px, calc(100vw - 32px));
      max-height: min(90vh, 940px) !important;
    }

    .support-chat-widget__panel[hidden] {
      display: none !important;
    }

    .support-chat-widget.is-open .support-chat-widget__panel {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
      box-shadow: 0 34px 70px rgba(15, 23, 42, 0.24);
    }

    .support-chat-widget__panel-head {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
      padding: 18px 20px;
      background: linear-gradient(135deg, #effcf3 0%, #ffffff 100%);
      border-bottom: 1px solid #d8efe0;
    }

    .support-chat-widget__panel-title {
      font-size: 16px;
      font-weight: 800;
      color: #111827;
    }

    .support-chat-widget__panel-subtitle {
      margin-top: 4px;
      color: #6b7280;
      font-size: 13px;
      line-height: 1.5;
    }

    .support-chat-widget__panel-actions {
      display: flex;
      gap: 8px;
      flex: 0 0 auto;
    }

    .support-chat-widget__expand,
    .support-chat-widget__close {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      border: 1px solid #d8efe0;
      background: #fff;
      color: #166534;
    }

    .support-chat-widget__expand[aria-pressed='true'] {
      background: #ecfdf3;
      border-color: #bde4ca;
      color: #14532d;
    }

    .support-chat-widget__stream {
      flex: 1;
      min-height: 340px;
      max-height: 520px;
      overflow-y: auto;
      padding: 18px;
      background: linear-gradient(180deg, #f6fdf8 0%, #ffffff 100%);
    }

    .support-chat-widget.is-expanded .support-chat-widget__stream {
      max-height: 66vh;
    }

    .support-chat-widget__empty {
      min-height: 220px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 12px;
      text-align: center;
      color: #6b7280;
      padding: 16px;
    }

    .support-chat-widget__empty i {
      font-size: 30px;
      color: #22c55e;
    }

    .support-chat-widget__bubble-row {
      display: flex;
      margin-bottom: 12px;
    }

    .support-chat-widget__bubble-row--staff {
      justify-content: flex-start;
    }

    .support-chat-widget__bubble-row--customer {
      justify-content: flex-end;
    }

    .support-chat-widget__bubble {
      max-width: 82%;
      border-radius: 18px;
      padding: 12px 14px;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
    }

    .support-chat-widget__bubble--customer {
      background: #dcfce7;
      color: #14532d;
      border: 1px solid #bbf7d0;
    }

    .support-chat-widget__bubble--staff {
      background: #fff;
      color: #111827;
      border: 1px solid #e5e7eb;
    }

    .support-chat-widget__bubble-author {
      font-size: 12px;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .support-chat-widget__bubble-text {
      line-height: 1.6;
      word-break: break-word;
    }

    .support-chat-widget__bubble-time {
      margin-top: 8px;
      font-size: 12px;
      opacity: 0.78;
    }

    .support-chat-widget__form {
      border-top: 1px solid #edf1f6;
      padding: 16px 18px 18px;
      background: #fff;
    }

    .support-chat-widget__composer {
      display: grid;
      grid-template-columns: 46px minmax(0, 1fr) auto;
      gap: 10px;
      align-items: end;
    }

    .support-chat-widget__icon-btn {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      border: 1px solid #cfe8d6;
      background: #f4fcf6;
      color: #15803d;
      font-size: 18px;
      transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    }

    .support-chat-widget__icon-btn:hover,
    .support-chat-widget__icon-btn[aria-expanded='true'] {
      background: #dcfce7;
      transform: translateY(-1px);
      box-shadow: 0 10px 20px rgba(34, 197, 94, 0.14);
    }

    .support-chat-widget__textarea {
      resize: none;
      min-height: 46px;
      max-height: 120px;
      border-color: #cfe8d6;
      background: #fbfffc;
      border-radius: 18px;
      padding: 12px 14px;
      line-height: 1.5;
    }

    .support-chat-widget__textarea:focus {
      border-color: #86d39a;
      box-shadow: 0 0 0 0.2rem rgba(34, 197, 94, 0.14);
    }

    .support-chat-widget__submit {
      border: 0;
      background: #22c55e;
      color: #fff;
      font-weight: 700;
      border-radius: 16px;
      min-width: 74px;
      height: 46px;
      padding-inline: 18px;
    }

    .support-chat-widget__submit:hover {
      background: #16a34a;
      color: #fff;
    }

    .support-chat-widget__sticker-tray {
      margin-top: 10px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      padding: 10px;
      border-radius: 16px;
      background: #f4fcf6;
      border: 1px solid #d8efe0;
    }

    .support-chat-widget__sticker {
      width: 40px;
      height: 40px;
      border: 0;
      border-radius: 12px;
      background: #fff;
      font-size: 20px;
      line-height: 1;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
      transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .support-chat-widget__sticker:hover {
      transform: translateY(-1px) scale(1.04);
      background: #ecfdf3;
      box-shadow: 0 12px 20px rgba(34, 197, 94, 0.12);
    }

    .support-chat-widget__form-row {
      margin-top: 10px;
      display: flex;
      justify-content: flex-start;
      align-items: center;
      gap: 12px;
      color: #6b7280;
      font-size: 12px;
    }

    @media (max-width: 767.98px) {
      .support-chat-widget {
        right: 12px;
        bottom: 12px;
        left: 12px;
        align-items: stretch;
      }

      .support-chat-widget__trigger {
        justify-content: center;
      }

      .support-chat-widget.is-open .support-chat-widget__trigger {
        display: none;
      }

      .support-chat-widget__panel {
        width: 100%;
        max-height: min(78vh, 680px);
      }

      .support-chat-widget.is-expanded .support-chat-widget__panel {
        width: 100%;
        max-height: 92vh !important;
      }

      .support-chat-widget__stream {
        max-height: 360px;
      }

      .support-chat-widget.is-expanded .support-chat-widget__stream {
        max-height: 64vh;
      }

      .support-chat-widget__form-row {
        flex-direction: column;
        align-items: stretch;
      }
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var widget = document.querySelector('[data-support-chat-widget]');
      if (!widget) {
        return;
      }

      var panel = widget.querySelector('[data-support-chat-panel]');
      var trigger = widget.querySelector('[data-support-chat-toggle]');
      var closeButton = widget.querySelector('[data-support-chat-close]');
      var expandButton = widget.querySelector('[data-support-chat-expand]');
      var stream = widget.querySelector('[data-support-chat-stream]');
      var form = widget.querySelector('.support-chat-widget__form');
      var textarea = widget.querySelector('.support-chat-widget__textarea');
      var stickerToggle = widget.querySelector('[data-support-chat-sticker-toggle]');
      var stickerTray = widget.querySelector('[data-support-chat-sticker-tray]');
      var stickers = widget.querySelectorAll('[data-support-chat-sticker]');
      var externalTriggers = document.querySelectorAll('[data-support-chat-toggle]');
      var reopenStorageKey = 'supportChatReopen';
      var expandedStorageKey = 'supportChatExpanded';
      var closeTimer = null;

      var emitLayoutState = function () {
        window.dispatchEvent(new CustomEvent('skinsyntax:support-chat-layout', {
          detail: {
            open: widget.classList.contains('is-open'),
            height: widget.offsetHeight || 0,
            bottom: parseFloat(window.getComputedStyle(widget).bottom || '22') || 22,
          }
        }));
      };

      var scrollToBottom = function () {
        if (stream) {
          stream.scrollTop = stream.scrollHeight;
        }
      };

      var syncExpandedState = function () {
        var expanded = widget.classList.contains('is-expanded');
        if (expandButton) {
          expandButton.setAttribute('aria-pressed', expanded ? 'true' : 'false');
          expandButton.innerHTML = expanded
            ? '<i class="fa-solid fa-compress"></i>'
            : '<i class="fa-solid fa-expand"></i>';
        }
        try {
          window.sessionStorage.setItem(expandedStorageKey, expanded ? '1' : '0');
        } catch (error) {
        }
        emitLayoutState();
      };

      var openWidget = function () {
        if (!panel || !trigger) {
          return;
        }
        if (closeTimer) {
          window.clearTimeout(closeTimer);
          closeTimer = null;
        }
        panel.hidden = false;
        window.requestAnimationFrame(function () {
          trigger.setAttribute('aria-expanded', 'true');
          widget.classList.add('is-open');
          scrollToBottom();
          if (textarea) {
            textarea.focus();
          }
          emitLayoutState();
        });
      };

      var closeStickerTray = function () {
        if (!stickerTray || !stickerToggle) {
          return;
        }
        stickerTray.hidden = true;
        stickerToggle.setAttribute('aria-expanded', 'false');
      };

      var openStickerTray = function () {
        if (!stickerTray || !stickerToggle) {
          return;
        }
        stickerTray.hidden = false;
        stickerToggle.setAttribute('aria-expanded', 'true');
      };

      var closeWidget = function () {
        if (!panel || !trigger) {
          return;
        }
        trigger.setAttribute('aria-expanded', 'false');
        widget.classList.remove('is-open');
        closeStickerTray();
        emitLayoutState();
        if (closeTimer) {
          window.clearTimeout(closeTimer);
        }
        closeTimer = window.setTimeout(function () {
          panel.hidden = true;
          closeTimer = null;
          emitLayoutState();
        }, 280);
        try {
          window.sessionStorage.removeItem(reopenStorageKey);
        } catch (error) {
        }
      };

      externalTriggers.forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          if (panel.hidden) {
            openWidget();
          } else if (button === trigger) {
            closeWidget();
          } else {
            openWidget();
          }
        });
      });

      if (closeButton) {
        closeButton.addEventListener('click', function () {
          closeWidget();
        });
      }

      if (expandButton) {
        expandButton.addEventListener('click', function () {
          widget.classList.toggle('is-expanded');
          syncExpandedState();
          scrollToBottom();
        });
      }

      if (form) {
        form.addEventListener('submit', function () {
          try {
            window.sessionStorage.setItem(reopenStorageKey, '1');
          } catch (error) {
          }
        });
      }

      if (stickerToggle) {
        stickerToggle.addEventListener('click', function () {
          if (!stickerTray) {
            return;
          }
          if (stickerTray.hidden) {
            openStickerTray();
          } else {
            closeStickerTray();
          }
        });
      }

      stickers.forEach(function (button) {
        button.addEventListener('click', function () {
          if (!textarea) {
            return;
          }
          var sticker = button.getAttribute('data-support-chat-sticker') || '';
          var currentValue = textarea.value.trim();
          textarea.value = currentValue ? (textarea.value + ' ' + sticker) : sticker;
          textarea.focus();
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });

      document.addEventListener('click', function (event) {
        if (!widget.contains(event.target)) {
          closeStickerTray();
        }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && widget.classList.contains('is-open')) {
          if (stickerTray && !stickerTray.hidden) {
            closeStickerTray();
          } else {
            closeWidget();
          }
        }
      });

      try {
        if (window.sessionStorage.getItem(reopenStorageKey) === '1') {
          openWidget();
          window.sessionStorage.removeItem(reopenStorageKey);
        }
        if (window.sessionStorage.getItem(expandedStorageKey) === '1') {
          widget.classList.add('is-expanded');
        }
      } catch (error) {
      }

      scrollToBottom();
      syncExpandedState();
      emitLayoutState();

      window.addEventListener('resize', function () {
        emitLayoutState();
      });
    });
  </script>
<?php endif; ?>