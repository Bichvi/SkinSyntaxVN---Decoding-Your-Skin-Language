/* global __dirname */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { runInNewContext } = require('node:vm');
const ts = require('typescript');

const file = join(__dirname, '../src/config/api.ts');
const configCode = ts.transpileModule(readFileSync(file, 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;

function config(dev, url, mode = 'development') {
  const exports = {};
  runInNewContext(configCode, {
    exports,
    URL,
    __DEV__: dev,
    process: { env: { EXPO_PUBLIC_API_MODE: mode, EXPO_PUBLIC_API_BASE_URL: url } },
  }, { filename: file });
  return exports;
}

test('production start and release builds disregard a development URL', () => {
  for (const actual of [config(true, 'http://localhost', 'production'), config(false, 'http://localhost')]) {
    assert.equal(actual.apiMode, 'production');
    assert.equal(actual.API_BASE_URL, 'https://skinsyntaxvn.app');
  }
});

test('developer URL preserves the base path and cannot override route mapping', () => {
  const dev = config(true, 'http://localhost:8080/store/');
  const url = new URL(dev.buildApiUrl(dev.routes.smartSearch, { r: 'wrong', q: 'sữa & da' }));
  assert.equal(url.pathname, '/store/index.php');
  assert.equal(url.searchParams.get('r'), 'api_smart_search');
  assert.equal(url.searchParams.get('q'), 'sữa & da');
});

test('incomplete and credential-bearing development URLs fail without production fallback', () => {
  for (const bad of ['', 'http://user:password@localhost', 'file:///temp']) {
    const dev = config(true, bad);
    assert.throws(() => dev.buildApiUrl(dev.routes.smartSearch));
  }
});
