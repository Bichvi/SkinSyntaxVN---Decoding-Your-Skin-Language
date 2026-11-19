'use strict';

// Exercise the shipped controller with fake DOM/media/HTTP boundaries: no devices, DB or network.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../live-commerce.js'), 'utf8');

function harness(studio = true, failStart = false) {
  const nodes = new Map();
  const timers = new Map();
  const requests = [];
  const tracks = [];
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, {
      dataset: {}, hidden: false, disabled: false, value: '', selectedIndex: 0, textContent: '',
      classList: {toggle() {}}, listeners: {},
      addEventListener(event, fn) { this.listeners[event] = fn; },
      replaceChildren() {}, querySelector() { return node('child'); },
    });
    return nodes.get(id);
  };
  node('ssLivePage').dataset = {liveId: 'fixture-room', isStudio: studio ? '1' : '0', liveStatus: 'ready', baseUrl: '', csrf: 'fixture-csrf'};
  const camera = node('camera');
  camera.dataset.liveSource = 'camera';
  class Room {
    state = 'connected';
    localParticipant = {
      publishTrack: async track => { tracks.push(track); },
      unpublishTrack: async track => { tracks.splice(tracks.indexOf(track), 1); },
    };
    on() {}
    async connect() {}
  }
  class Media {
    videoOutput = {readyState: 'live'}; audioOutput = {readyState: 'live'}; output = {};
    async select(kind) { this.source = {kind, audio_fallback: false}; }
    setMuted() {}
    close() { this.closed = true; }
  }
  const context = {
    document: {
      hidden: false,
      getElementById: id => ['liveChatForm', 'replayVideoPlayer'].includes(id) ? null : node(id),
      querySelectorAll: selector => selector === '[data-live-source]' ? [camera] : [],
    },
    navigator: {mediaDevices: {enumerateDevices: async () => []}},
    Option: class {}, FormData, URLSearchParams, URL, Intl, Date, console,
    LivekitClient: {Room, RoomEvent: {}}, LiveStudioMedia: Media,
    setInterval: (fn, ms) => { timers.set(ms, fn); }, setTimeout() {},
    fetch: async (url, options) => {
      const route = new URL(url, 'http://fixture.test').searchParams.get('r');
      const fields = options?.body ? Object.fromEntries(options.body) : {};
      requests.push({route, fields});
      const response = route === 'api_live_token'
        ? {ok: true, data: {token: 'fixture-token', participant_token: 'fixture-token', can_publish: studio, server_url: 'ws://fixture.test'}}
        : route === 'api_live_stream_state'
          ? {ok: !(failStart && fields.state === 'live'), message: 'fixture rejection'}
          : {ok: false};
      return {json: async () => response};
    },
  };
  context.window = context;
  vm.runInNewContext(source, context);
  return {node, timers, requests, tracks, start: () => camera.listeners.click()};
}

test('publisher sends heartbeat without replacing the live label or recording state', async () => {
  const h = harness();
  await h.start();
  assert.equal(h.node('liveStatusLabel').textContent, 'ĐANG PHÁT');
  await h.timers.get(15000)();
  const states = h.requests.filter(r => r.route === 'api_live_stream_state');
  assert.deepEqual(states.map(r => r.fields.state), ['live', 'heartbeat']);
  assert.equal(states[1].fields.publisher_token, 'fixture-token');
  assert.equal(states[1].fields.csrf, 'fixture-csrf');
  assert.equal(h.node('liveStatusLabel').textContent, 'ĐANG PHÁT');
});

test('viewers never renew publisher state', async () => {
  const h = harness(false);
  await h.timers.get(15000)();
  assert.equal(h.requests.filter(r => r.route === 'api_live_stream_state').length, 0);
});

test('rejected go-live releases tracks and allows retry', async () => {
  const h = harness(true, true);
  await h.start();
  assert.equal(h.tracks.length, 0);
  assert.equal(h.node('liveMediaStatus').textContent, 'fixture rejection');
  await h.start();
  assert.equal(h.requests.filter(r => r.route === 'api_live_stream_state').length, 2);
  assert.equal(h.tracks.length, 0);
});
