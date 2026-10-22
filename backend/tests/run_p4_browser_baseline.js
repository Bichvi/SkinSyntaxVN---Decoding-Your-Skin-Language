// backend/tests/run_p4_browser_baseline.js
// Automated Lighthouse & Chrome Performance Baseline Runner for Phase P4A
// Measures Desktop & Mobile across 5 key routes with zero synthetic data.

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const outDir = path.resolve(__dirname, '../experiments/performance/frontend_p4');
if (!fs.existsSync(outDir)) {
  fs.mkdirSync(outDir, { recursive: true });
}

const routes = [
  { name: 'Homepage', url: 'http://localhost/index.php?r=home' },
  { name: 'Category', url: 'http://localhost/index.php?r=tatca' },
  { name: 'Product Detail', url: 'http://localhost/index.php?r=chitiet&id=111' },
  { name: 'Search', url: 'http://localhost/index.php?r=tatca&q=serum' },
  { name: 'Recommendation', url: 'http://localhost/index.php?r=goiy' },
];

const presets = ['desktop', 'mobile'];

const results = {};

console.log('================================================================================');
console.log('SKINSYNTAXVN — PHASE P4A BROWSER PERFORMANCE BASELINE RUNNER');
console.log('Measuring Lighthouse & DevTools Core Web Vitals (Desktop & Mobile)');
console.log('================================================================================\n');

for (const r of routes) {
  results[r.name] = {};
  for (const preset of presets) {
    console.log(`>>> Measuring: [${r.name}] on [${preset.toUpperCase()}] ...`);
    const jsonPath = path.join(outDir, `lh_${r.name.toLowerCase().replace(/\s+/g, '_')}_${preset}.json`);
    
    const presetFlag = preset === 'desktop' ? '--preset=desktop' : '--form-factor=mobile --screenEmulation.mobile=true';
    const cmd = `npx lighthouse "${r.url}" --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json --output-path="${jsonPath}" ${presetFlag} --only-categories=performance --max-wait-for-load=45000 --quiet`;

    try {
      execSync(cmd, { stdio: 'pipe', timeout: 90000 });
      if (fs.existsSync(jsonPath)) {
        const raw = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
        const a = raw.audits;
        
        // Extract key metrics
        const score = Math.round((raw.categories.performance.score || 0) * 100);
        const fcp = parseFloat(a['first-contentful-paint']?.numericValue || 0);
        const lcp = parseFloat(a['largest-contentful-paint']?.numericValue || 0);
        const tbt = parseFloat(a['total-blocking-time']?.numericValue || 0);
        const cls = parseFloat(a['cumulative-layout-shift']?.numericValue || 0);
        const speedIndex = parseFloat(a['speed-index']?.numericValue || 0);
        const ttfb = parseFloat(a['server-response-time']?.numericValue || 0);
        const totalByteWeight = a['total-byte-weight']?.numericValue || 0;
        const totalBytesDisplay = a['total-byte-weight']?.displayValue || '0 KiB';
        const numRequests = a['network-requests']?.details?.items?.length || 0;

        // Diagnostics
        const jsExecutionMs = parseFloat(a['bootup-time']?.numericValue || 0);
        const mainThreadWorkMs = parseFloat(a['mainthread-work-breakdown']?.numericValue || 0);
        const renderBlockingItems = a['render-blocking-resources']?.details?.items || [];
        const unsizedImages = a['image-size-responsive']?.details?.items?.length || 0;
        const offscreenImages = a['offscreen-images']?.details?.items?.length || 0;
        const longTasks = (a['long-tasks']?.details?.items || []).length;

        results[r.name][preset] = {
          score,
          fcp_ms: Math.round(fcp),
          lcp_ms: Math.round(lcp),
          tbt_ms: Math.round(tbt),
          cls: parseFloat(cls.toFixed(4)),
          speed_index_ms: Math.round(speedIndex),
          ttfb_ms: Math.round(ttfb),
          total_bytes: totalByteWeight,
          total_bytes_display: totalBytesDisplay,
          network_requests: numRequests,
          js_execution_ms: Math.round(jsExecutionMs),
          main_thread_work_ms: Math.round(mainThreadWorkMs),
          render_blocking_count: renderBlockingItems.length,
          long_tasks_count: longTasks,
          unsized_images_count: unsizedImages,
          offscreen_unlazy_count: offscreenImages,
        };

        console.log(`  -> Score: ${score}/100 | LCP: ${Math.round(lcp)}ms | TBT: ${Math.round(tbt)}ms | CLS: ${cls.toFixed(3)} | Transferred: ${totalBytesDisplay} (${numRequests} reqs)`);
      }
    } catch (err) {
      console.error(`  [ERROR] Failed to run lighthouse for ${r.name} ${preset}:`, err.message);
    }
  }
}

const matrixFile = path.join(outDir, 'browser_baseline_matrix.json');
fs.writeFileSync(matrixFile, JSON.stringify(results, null, 2), 'utf8');
console.log(`\nSaved baseline matrix to: ${matrixFile}`);
