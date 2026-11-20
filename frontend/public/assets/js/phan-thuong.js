(function () {
  'use strict';
  var root = document.getElementById('phanThuong');
  if (!root || root.getAttribute('data-logged-in') !== '1') return;

  var csrf = root.getAttribute('data-reward-csrf') || '';
  var urls = {
    summary: root.getAttribute('data-summary-url'),
    history: root.getAttribute('data-history-url'),
    checkin: root.getAttribute('data-checkin-url'),
    claim: root.getAttribute('data-claim-url')
  };
  var $ = function (id) { return document.getElementById(id); };
  var state = null;
  var busy = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function toast(msg, isErr) {
    var t = $('ptToast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'pt-toast' + (isErr ? ' pt-toast--err' : '');
    t.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.hidden = true; }, 5000);
  }

  function fmtClock(s) {
    s = Math.max(0, Math.ceil(s));
    var m = Math.floor(s / 60), r = s % 60;
    return m + ':' + (r < 10 ? '0' : '') + r;
  }

  function getJson(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; }); });
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; }); });
  }

  function capReached(s) {
    return s && Number(s.daily_cap) > 0 && Number(s.xu_today) >= Number(s.daily_cap);
  }

  function fmt(n) { return Number(n || 0).toLocaleString('vi-VN'); }

  function renderBalance(s) {
    $('ptBalance').textContent = fmt(s.xu_balance);
    var sub = 'Tổng xu đã nhận từ trước đến nay';
    if (Number(s.xu_pending) > 0) sub += ' · +' + fmt(s.xu_pending) + ' xu đang tích';
    $('ptBalanceSub').textContent = sub;
    var cap = $('ptCap');
    if (capReached(s)) {
      cap.textContent = 'Hôm nay bạn đã đạt trần ' + fmt(s.daily_cap) + ' xu';
      cap.hidden = false;
    } else {
      cap.hidden = true;
    }
    $('ptAvail').textContent = 'Số dư có thể dùng: ' + fmt(s.xu_balance) + ' xu';
  }

  function renderDays(s) {
    var ol = $('ptDays');
    ol.textContent = '';
    ((s.checkin && s.checkin.days) || []).forEach(function (d) {
      var li = el('li', 'pt-day pt-day--' + d.state);
      var ico = el('span', 'pt-day__ico');
      var i = el('i');
      if (d.state === 'done') i.className = 'fa-solid fa-check';
      else if (Number(d.day) === 7) i.className = 'fa-solid fa-gift';
      if (i.className) ico.appendChild(i);
      li.appendChild(ico);
      li.appendChild(el('span', 'pt-day__xu', '+' + fmt(d.xu)));
      li.appendChild(el('span', 'pt-day__lbl', 'Ngày ' + d.day));
      if (d.state === 'today') li.setAttribute('aria-current', 'date');
      ol.appendChild(li);
    });
  }

  function renderCheckinBtn() {
    var b = $('ptCheckinBtn');
    var c = state && state.checkin;
    if (!c) return;
    b.textContent = '';
    var w = $('ptCheckinWatch');
    if (w) w.hidden = true;
    if (c.checked_in_today) {
      b.className = 'pt-btn pt-btn--lock';
      b.disabled = true;
      b.textContent = 'Đã điểm danh hôm nay';
    } else if (c.can_checkin) {
      b.className = 'pt-btn pt-btn--ready';
      b.disabled = false;
      // Chạm trần vẫn điểm danh được để giữ chuỗi (xu có thể là 0).
      b.textContent = capReached(state) ? 'Điểm danh giữ chuỗi' : 'Điểm danh +' + fmt(c.next_xu) + ' xu';
    } else {
      b.className = 'pt-btn pt-btn--lock';
      b.disabled = true;
      // Tĩnh theo server: không đếm ngược khi chưa có video nào đang phát.
      b.appendChild(el('span', '', 'Điểm danh · Xem thêm ' + fmtClock(c.seconds_needed)));
      if (w) {
        w.href = root.getAttribute('data-watch-url') || w.href;
        w.hidden = false;
      }
    }
  }

  function renderMilestones(s) {
    var secs = Number(s.watched_seconds_today || 0);
    var pct = Math.min(100, (secs / 1800) * 100);
    $('ptBarFill').style.width = pct + '%';
    $('ptBar').setAttribute('aria-valuenow', String(Math.min(30, Math.floor(secs / 60))));
    var wrap = $('ptMilestones');
    wrap.textContent = '';
    (s.milestones || []).forEach(function (m) {
      var box = el('div', 'pt-m pt-m--' + m.state);
      var i = el('i', 'fa-solid ' + (m.state === 'claimed' ? 'fa-circle-check' : 'fa-gift'));
      box.appendChild(i);
      box.appendChild(el('b', '', m.minutes + ' phút'));
      if (m.state === 'ready' && capReached(s)) {
        box.appendChild(el('span', 'pt-note', 'Đủ ' + fmt(s.daily_cap) + ' xu hôm nay'));
      } else if (m.state === 'ready') {
        var btn = el('button', 'pt-btn pt-btn--ready', 'Nhận +' + fmt(m.xu));
        btn.type = 'button';
        btn.addEventListener('click', function () { claim(m.minutes, btn); });
        box.appendChild(btn);
      } else {
        box.appendChild(el('span', 'pt-note', m.state === 'claimed' ? 'Đã nhận +' + fmt(m.xu) : '+' + fmt(m.xu) + ' xu'));
      }
      wrap.appendChild(box);
    });
    $('ptWatchNote').textContent = 'Đã xem hôm nay: ' + Math.floor(secs / 60) + ' phút';
  }

  function render() {
    if (!state) return;
    renderBalance(state);
    renderDays(state);
    renderCheckinBtn();
    renderMilestones(state);
  }

  function loadSummary() {
    return getJson(urls.summary).then(function (r) {
      if (r.status === 200 && r.body && r.body.ok) { state = r.body; render(); }
      else toast((r.body && r.body.message) || 'Không tải được phần thưởng.', true);
    }).catch(function () { toast('Không tải được phần thưởng.', true); });
  }

  function loadHistory() {
    var ul = $('ptHistory');
    return getJson(urls.history).then(function (r) {
      var items = Array.isArray((r.body || {}).items) ? r.body.items : [];
      ul.textContent = '';
      if (!items.length) { ul.appendChild(el('li', 'pt-empty', 'Đánh giá sản phẩm đã mua hoặc xem video để nhận xu đầu tiên')); return; }
      items.forEach(function (it) {
        var li = el('li');
        var amt = Number(it.amount_xu || 0);
        li.appendChild(el('b', '', (amt >= 0 ? '+' : '') + fmt(amt) + ' xu'));
        var d = String(it.date_vn || '').split('-');
        var dd = d.length === 3 ? d[2] + '/' + d[1] : '';
        li.appendChild(document.createTextNode(' · ' + (it.label || '') + (dd ? ' · ' + dd : '')));
        ul.appendChild(li);
      });
    }).catch(function () {
      ul.textContent = '';
      ul.appendChild(el('li', 'pt-empty', 'Không tải được lịch sử xu.'));
    });
  }

  function act(url, payload, btn) {
    if (busy) return;
    busy = true;
    if (btn) btn.disabled = true;
    postJson(url, payload).then(function (r) {
      var b = r.body || {};
      if (r.status === 200 && b.ok) toast((b.message || 'Đã nhận xu') + (b.xu_awarded ? ' (+' + fmt(b.xu_awarded) + ' xu)' : ''), false);
      else toast(b.message || 'Có lỗi xảy ra, vui lòng thử lại.', true);
    }).catch(function () {
      toast('Có lỗi kết nối, vui lòng thử lại.', true);
    }).then(function () {
      busy = false;
      return Promise.all([loadSummary(), loadHistory()]);
    });
  }

  function claim(minutes, btn) { act(urls.claim, { minutes: minutes }, btn); }

  $('ptCheckinBtn').addEventListener('click', function () {
    if (!this.disabled) act(urls.checkin, {}, this);
  });

  loadSummary();
  loadHistory();
})();
