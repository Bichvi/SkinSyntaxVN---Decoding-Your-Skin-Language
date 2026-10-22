// backend/tests/aggregate_p4_baseline.js
const fs = require('fs');
const path = require('path');

const dir = path.resolve(__dirname, '../experiments/performance/frontend_p4');
const routes = ['home', 'category', 'product_detail', 'search', 'recommendation'];
const presets = ['desktop', 'mobile'];

const matrix = {};

for (const r of routes) {
  matrix[r] = {};
  for (const p of presets) {
    const file = path.join(dir, `lh_${r}_${p}.json`);
    if (!fs.existsSync(file)) {
      console.warn(`File not found: ${file}`);
      continue;
    }
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    const a = raw.audits;

    const score = Math.round((raw.categories.performance.score || 0) * 100);
    const fcp = parseFloat(a['first-contentful-paint']?.numericValue || 0);
    const lcp = parseFloat(a['largest-contentful-paint']?.numericValue || 0);
    const tbt = parseFloat(a['total-blocking-time']?.numericValue || 0);
    const cls = parseFloat(a['cumulative-layout-shift']?.numericValue || 0);
    const speedIndex = parseFloat(a['speed-index']?.numericValue || 0);
    const ttfb = parseFloat(a['server-response-time']?.numericValue || 0);
    const totalBytes = a['total-byte-weight']?.numericValue || 0;
    const totalBytesDisplay = a['total-byte-weight']?.displayValue || '0 KiB';
    const numRequests = a['network-requests']?.details?.items?.length || 0;
    const jsExecutionMs = parseFloat(a['bootup-time']?.numericValue || 0);
    const mainThreadMs = parseFloat(a['mainthread-work-breakdown']?.numericValue || 0);
    const renderBlocking = (a['render-blocking-resources']?.details?.items || []);
    const longTasks = (a['long-tasks']?.details?.items || []).length;
    const unsizedImages = (a['image-size-responsive']?.details?.items || []).length;
    const offscreenImages = (a['offscreen-images']?.details?.items || []).length;

    matrix[r][p] = {
      score,
      fcp_ms: Math.round(fcp),
      lcp_ms: Math.round(lcp),
      tbt_ms: Math.round(tbt),
      cls: parseFloat(cls.toFixed(4)),
      speed_index_ms: Math.round(speedIndex),
      ttfb_ms: Math.round(ttfb),
      total_bytes: totalBytes,
      total_bytes_display: totalBytesDisplay,
      network_requests: numRequests,
      js_execution_ms: Math.round(jsExecutionMs),
      main_thread_work_ms: Math.round(mainThreadMs),
      render_blocking_count: renderBlocking.length,
      render_blocking_wasted_ms: Math.round(renderBlocking.reduce((sum, item) => sum + (item.wastedMs || 0), 0)),
      long_tasks_count: longTasks,
      unsized_images_count: unsizedImages,
      offscreen_unlazy_count: offscreenImages
    };
  }
}

const outPath = path.join(dir, 'browser_baseline_matrix.json');
fs.writeFileSync(outPath, JSON.stringify(matrix, null, 2), 'utf8');
console.log('Aggregated Matrix:');
console.log(JSON.stringify(matrix, null, 2));
