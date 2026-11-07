const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync('frontend/public/assets/js/chat-agent-stream.js', 'utf8');

class FakeNode {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.className = '';
    this.hidden = false;
    this.disabled = false;
    this.type = '';
    this.href = '';
    this.listeners = {};
    this._text = '';
  }

  set textContent(value) {
    this._text = String(value);
    this.children = [];
  }

  get textContent() {
    return this._text + this.children.map((child) => child.textContent).join('');
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  addEventListener(eventName, callback) {
    this.listeners[eventName] = callback;
  }

  click() {
    if (this.listeners.click) this.listeners.click({ target: this });
  }

  contains(node) {
    return this === node || this.children.some((child) => child.contains(node));
  }

  findAll(tagName) {
    const matches = [];
    for (const child of this.children) {
      if (!tagName || child.tagName === tagName.toUpperCase()) matches.push(child);
      matches.push(...child.findAll(tagName));
    }
    return matches;
  }
}

class FakeDocument {
  createElement(tagName) {
    return new FakeNode(tagName);
  }
}

function loadAgent(fetchImpl = () => Promise.reject(new Error('unused'))) {
  const fetchState = { impl: fetchImpl };
  let nextTimerId = 1;
  const activeTimers = new Set();
  const window = {
    location: { href: 'https://shop.test/index.php', origin: 'https://shop.test' },
    setInterval: () => {
      const id = nextTimerId++;
      activeTimers.add(id);
      return id;
    },
    clearInterval: (id) => activeTimers.delete(id)
  };
  const clock = { offsetMs: 0 };
  const RealDate = Date;
  class FakeDate extends RealDate {
    static now() { return RealDate.now() + clock.offsetMs; }
  }
  const context = {
    window,
    Date: FakeDate,
    document: new FakeDocument(),
    Intl,
    TextDecoder,
    TextEncoder,
    Uint8Array,
    AbortController,
    URL,
    fetch: (...args) => fetchState.impl(...args)
  };
  vm.runInNewContext(source, context);
  return { agent: window.SkinSyntaxAgent, window, document: context.document, fetchState, activeTimers, clock };
}

test('parses SSE CRLF, bare CR, and multi-line data', () => {
  const { agent } = loadAgent();
  const crlf = agent.parseSseBlock('event: token\r\ndata: {"text":"Xin "}\r\ndata: {"text":"chào"}');
  assert.equal(crlf.event, 'token');
  assert.equal(crlf.data.message, '{"text":"Xin "}\n{"text":"chào"}');

  const bareCr = agent.parseSseBlock('event: token\rdata: {"text":"ok"}');
  assert.equal(bareCr.event, 'token');
  assert.equal(bareCr.data.text, 'ok');
});

test('parses an SSE event split across response chunks', async () => {
  const chunks = [
    new TextEncoder().encode('event: token\r\ndata: {"text":"Xin'),
    new TextEncoder().encode(' chào"}\r\n\r\n'),
    new Uint8Array()
  ];
  const { agent } = loadAgent(() => Promise.resolve({
    ok: true,
    status: 200,
    body: { getReader: () => ({ read: () => Promise.resolve({ done: chunks.length === 0, value: chunks.shift() }), cancel: () => Promise.resolve() }) }
  }));
  const tokens = [];
  await agent.send({ message: 'test', onToken: (payload) => tokens.push(payload.text) });
  assert.deepEqual(tokens, ['Xin chào']);
});

test('formats Vietnamese prices and validates redirect targets', () => {
  const { agent } = loadAgent();
  assert.equal(agent.formatPrice(189000), '189.000đ');
  assert.equal(agent.isAllowedRedirect('/don-hang/7', 'https://shop.test', 'sandbox.vnpayment.vn'), true);
  assert.equal(agent.isAllowedRedirect('https://shop.test/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), true);
  assert.equal(agent.isAllowedRedirect('https://sandbox.vnpayment.vn/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), true);
  assert.equal(agent.isAllowedRedirect('https://sandbox.vnpayment.vn:8443/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), false);
  assert.equal(agent.isAllowedRedirect('https://sandbox.vnpayment.vn:443/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), true);
  assert.equal(agent.isAllowedRedirect('javascript:alert(1)', 'https://shop.test', 'sandbox.vnpayment.vn'), false);
  assert.equal(agent.isAllowedRedirect('data:text/html,x', 'https://shop.test', 'sandbox.vnpayment.vn'), false);
  assert.equal(agent.isAllowedRedirect('https://evil.test/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), false);
  assert.equal(agent.isAllowedRedirect('http://sandbox.vnpayment.vn/pay', 'https://shop.test', 'sandbox.vnpayment.vn'), false);
});

test('starting a new send aborts the previous stream without UI callbacks', async () => {
  let call = 0;
  const readers = [];
  const { agent } = loadAgent(() => {
    call += 1;
    const index = call;
    const reader = index === 1
      ? {
          read: () => new Promise((resolve, reject) => {
            readers.push({ reject });
          }),
          cancel: () => Promise.resolve()
        }
      : { read: () => Promise.resolve({ done: true, value: new Uint8Array() }), cancel: () => Promise.resolve() };
    return Promise.resolve({ ok: true, status: 200, body: { getReader: () => reader } });
  });
  const firstEvents = [];
  const first = agent.send({ message: 'one', onDone: () => firstEvents.push('done'), onError: () => firstEvents.push('error') });
  await Promise.resolve();
  await Promise.resolve();
  const second = agent.send({ message: 'two' });
  readers.forEach(({ reject }) => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
  assert.equal((await first).aborted, true);
  assert.equal((await second).fallback, false);
  assert.deepEqual(firstEvents, []);
});

test('draft card state survives re-render and never creates a second interval', () => {
  const { agent, document, activeTimers } = loadAgent();
  const payload = { draft_id: 'draft-1', expires_at: Math.floor(Date.now() / 1000) + 60, items: [] };
  const node = document.createElement('div');
  agent.renderDraftCard(payload, node, { messageId: 'message-1' });
  assert.equal(activeTimers.size, 1);
  agent.renderDraftCard(payload, node, { messageId: 'message-1' });
  assert.equal(activeTimers.size, 1);
});

test('created draft cards remain disabled after re-render and unsafe next URLs do not redirect', async () => {
  const { agent, document, window, fetchState } = loadAgent();
  agent.configure({ vnpayHost: 'sandbox.vnpayment.vn' });
  fetchState.impl = () => Promise.resolve({
    json: () => Promise.resolve({
      ok: true,
      status: 'created',
      order_id: 42,
      next: { type: 'vnpay', url: 'javascript:alert(1)' }
    })
  });
  const payload = { draft_id: 'draft-2', expires_at: Math.floor(Date.now() / 1000) + 60, items: [] };
  const node = document.createElement('div');
  agent.renderDraftCard(payload, node, { messageId: 'message-2' });
  node.findAll('button')[0].click();
  await new Promise((resolve) => setImmediate(resolve));
  agent.renderDraftCard(payload, node, { messageId: 'message-2' });
  assert.equal(node.findAll('button')[0].disabled, true);
  assert.match(node.textContent, /Đã tạo đơn #42/);
  assert.match(node.textContent, /Bạn mở mục Đơn hàng của tôi để thanh toán nhé/);
  assert.equal(window.location.href, 'https://shop.test/index.php');
});

test('confirming draft cards stay disabled across re-render and result targets the current node', async () => {
  let resolveConfirm;
  let fetchCount = 0;
  const { agent, document, fetchState } = loadAgent(() => {
    fetchCount += 1;
    return new Promise((resolve) => { resolveConfirm = resolve; });
  });
  const payload = { draft_id: 'draft-3', expires_at: Math.floor(Date.now() / 1000) + 60, items: [] };
  const firstNode = document.createElement('div');
  agent.renderDraftCard(payload, firstNode, { messageId: 'message-3' });
  firstNode.findAll('button')[0].click();
  assert.equal(agent.getDraftCardState('draft-3').status, 'confirming');
  assert.equal(agent.getDraftCardState('message-3').status, 'confirming');

  const remountedNode = document.createElement('div');
  agent.renderDraftCard(payload, remountedNode, { messageId: 'message-3' });
  assert.equal(remountedNode.findAll('button')[0].disabled, true);
  assert.match(remountedNode.textContent, /Đang xác nhận/);
  remountedNode.findAll('button')[0].click();
  assert.equal(fetchCount, 1);

  resolveConfirm({ json: () => Promise.resolve({ ok: true, status: 'created', order_id: 43 }) });
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
  assert.match(remountedNode.textContent, /Đã tạo đơn #43/);
  assert.equal(remountedNode.findAll('button')[0].disabled, true);
});

test('draft card state is keyed by draft_id before messageId', () => {
  const { agent, document } = loadAgent();
  const future = Math.floor(Date.now() / 1000) + 60;
  const firstNode = document.createElement('div');
  agent.renderDraftCard({ draft_id: 'draft-A', expires_at: future, items: [] }, firstNode, { messageId: 'message-shared' });
  firstNode.findAll('button')[1].click();
  assert.equal(firstNode.hidden, true);
  assert.equal(agent.getDraftCardState('draft-A').status, 'cancelled');

  // A replacement draft arriving under the same message id must not inherit the cancelled state.
  const secondNode = document.createElement('div');
  agent.renderDraftCard({ draft_id: 'draft-B', expires_at: future, items: [] }, secondNode, { messageId: 'message-shared' });
  assert.equal(secondNode.hidden, false);
  assert.equal(agent.getDraftCardState('draft-B').status, 'pending');
  assert.equal(agent.getDraftCardState('draft-A').status, 'cancelled');
});

test('the same draft keeps one state even when message ids differ', () => {
  const { agent, document, activeTimers } = loadAgent();
  const payload = { draft_id: 'draft-C', expires_at: Math.floor(Date.now() / 1000) + 60, items: [] };
  const node = document.createElement('div');
  agent.renderDraftCard(payload, node, { messageId: 'message-x' });
  agent.renderDraftCard(payload, node, { messageId: 'message-y' });
  assert.equal(activeTimers.size, 1);
  assert.equal(agent.getDraftCardState('draft-C'), agent.getDraftCardState('message-y'));
  assert.equal(agent.getDraftCardState('draft-C').status, 'pending');
});

test('stream request is a JSON POST', async () => {
  let captured;
  const { agent } = loadAgent((url, init) => {
    captured = { url, init };
    return Promise.resolve({
      ok: true,
      status: 200,
      body: { getReader: () => ({ read: () => Promise.resolve({ done: true, value: undefined }), cancel: () => Promise.resolve() }) }
    });
  });
  await agent.send({ message: 'xin chào' });
  assert.equal(captured.init.method, 'POST');
  assert.equal(captured.init.headers['Content-Type'], 'application/json');
  assert.equal(JSON.parse(captured.init.body).message, 'xin chào');
});

test('draft countdown uses a local deadline when server clock is ahead of the browser', () => {
  const { agent, document, clock } = loadAgent();
  const serverNow = Math.floor(Date.now() / 1000) + 167;
  const payload = { draft_id: 'skew-1', server_now: serverNow, expires_at: serverNow + 600, items: [] };
  const node = document.createElement('div');
  agent.renderDraftCard(payload, node, { messageId: 'skew-m' });
  const remaining = Math.round((payload.deadline_ms - Date.now()) / 1000);
  assert.ok(remaining >= 599 && remaining <= 600, 'remaining ' + remaining);
  assert.equal(agent.getDraftCardState('skew-m').status, 'pending');
  assert.match(node.textContent, /Còn (59\d|600) giây/);
  clock.offsetMs = 601 * 1000;
  agent.renderDraftCard(payload, node, { messageId: 'skew-m' });
  assert.equal(agent.getDraftCardState('skew-m').status, 'expired');
  assert.match(node.textContent, /hết hạn/);
});

test('re-rendering a stored draft payload does not extend its deadline', () => {
  const { agent, document, clock } = loadAgent();
  const serverNow = Math.floor(Date.now() / 1000);
  const payload = { draft_id: 'stable-1', server_now: serverNow, expires_at: serverNow + 600, items: [] };
  agent.renderDraftCard(payload, document.createElement('div'), { messageId: 'stable-m' });
  const first = payload.deadline_ms;
  clock.offsetMs = 300 * 1000;
  agent.renderDraftCard(payload, document.createElement('div'), { messageId: 'stable-m' });
  assert.equal(payload.deadline_ms, first);
});

test('draft without server_now keeps the expires_at behaviour', () => {
  const { agent, document, clock } = loadAgent();
  const payload = { draft_id: 'legacy-1', expires_at: Math.floor(Date.now() / 1000) + 60, items: [] };
  const node = document.createElement('div');
  agent.renderDraftCard(payload, node, { messageId: 'legacy-m' });
  assert.equal(payload.deadline_ms, undefined);
  assert.equal(agent.getDraftCardState('legacy-m').status, 'pending');
  clock.offsetMs = 120 * 1000;
  agent.renderDraftCard(payload, node, { messageId: 'legacy-m' });
  assert.equal(agent.getDraftCardState('legacy-m').status, 'expired');
});
