'use strict';
// Unit tests for the pure AI Idol live logic. Run: node --test "frontend/public/assets/js/tests/**/*.test.js"
const test = require('node:test');
const assert = require('node:assert/strict');
const { livePosition } = require('../ai-idol-live.js');

test('livePosition returns the offset inside the first loop', () => {
  assert.equal(livePosition(1030, 1000, 120), 30);
});

test('livePosition wraps once the video has looped', () => {
  assert.equal(livePosition(1000 + 120 * 3 + 45, 1000, 120), 45);
  assert.equal(livePosition(1120, 1000, 120), 0);
});

test('livePosition before the start is never negative', () => {
  assert.equal(livePosition(900, 1000, 120), 0);
});

test('livePosition with zero or invalid duration is 0', () => {
  assert.equal(livePosition(1500, 1000, 0), 0);
  assert.equal(livePosition(1500, 1000, -5), 0);
  assert.equal(livePosition(1500, 1000, NaN), 0);
});
