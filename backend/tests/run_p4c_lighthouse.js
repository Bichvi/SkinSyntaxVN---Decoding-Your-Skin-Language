// backend/tests/run_p4c_lighthouse.js
// Automated Lighthouse Benchmark Runner for Phase P4C: CSS/JS & Carousel Performance Optimization
// Compares P4C against P4B and P4A baseline under identical configurations.

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const outDir = path.resolve(__dirname, '../experiments/performance/frontend_p4/p4c');
if (!fs.existsSync(outDir)) {
  fs.mkdirSync(outDir, { recursive: true });
}

const routes = [
  { key: 'home', name: 'Homepage', url: 'http://localhost/index.php?r=home' },
  { key: 'category', name: 'Category', url: 'http://localhost/index.php?r=tatca' },
  { key: 'product_detail', name: 'Product Detail (6376)', url: 'http://localhost/index.php?r=chitiet&id=6376' },
  { key: 'product_detail_111', name: 'Product Detail (111)', url: 'http://localhost/index.php?r=chitiet&id=111' },
  { key: 'recommendation', name: 'Recommendation', url: 'http://localhost/index.php?r=goiy' },
  { key: 'search', name: 'Search', url: 'http://localhost/index.php?r=tatca&q=serum' },
];

const presets = ['desktop', 'mobile'];
const p4cResults = {};

console.log('================================================================================');
console.log('SKINSYNTAXVN — PHASE P4C LIGHTHOUSE BENCHMARK RUNNER');
console.log('Target Directory:', outDir);
console.log('================================================================================\n');

for (const r of routes) {
  p4cResults[r.key] = {};
  for (const preset of presets) {
    const filename = `lh_${r.key}_${preset}.json`;
    const jsonPath = path.join(outDir, filename);

    console.log(`>>> Measuring [${r.name.toUpperCase()}] on [${preset.toUpperCase()}] ...`);

    const presetFlag = preset === 'desktop' ? '--preset=desktop' : '--form-factor=mobile --screenEmulation.mobile=true';
    const cmd = `npx -y lighthouse "${r.url}" --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json --output-path="${jsonPath}" ${presetFlag} --only-categories=performance --max-wait-for-load=45000 --quiet`;

    try {
      execSync(cmd, { stdio: 'pipe', timeout: 90000 });
      if (fs.existsSync(jsonPath)) {
        const raw = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
        const a = raw.audits;

        const score = Math.round((raw.categories.performance.score || 0) * 100);
        const fcp = parseFloat(a['first-contentful-paint']?.numericValue || 0);
        const lcp = parseFloat(a['largest-contentful-paint']?.numericValue || 0);
        const tbt = parseFloat(a['total-blocking-time']?.numericValue || 0);
        const cls = parseFloat(a['cumulative-layout-shift']?.numericValue || 0);
        const speedIndex = parseFloat(a['speed-index']?.numericValue || 0);
        const ttfb = parseFloat(a['server-response-time']?.numericValue || 0);
        const totalByteWeight = a['total-byte-weight']?.numericValue || 0;
        const totalBytesDisplay = a['total-byte-weight']?.displayValue || '0 KiB';
        const jsExecutionMs = parseFloat(a['bootup-time']?.numericValue || 0);
        const mainThreadWorkMs = parseFloat(a['mainthread-work-breakdown']?.numericValue || 0);
        const networkRequests = a['network-requests']?.details?.items || [];
        const numRequests = networkRequests.length;

        // Image breakdown
        const imageRequests = networkRequests.filter(item => item.resourceType === 'Image');
        const imageCount = imageRequests.length;
        const imageBytes = imageRequests.reduce((acc, item) => acc + (item.transferSize || 0), 0);

        // LCP element info
        const lcpElement = a['largest-contentful-paint-element']?.details?.items?.[0]?.node?.nodeLabel || 'N/A';

        p4cResults[r.key][preset] = {
          score,
          fcp_ms: Math.round(fcp),
          lcp_ms: Math.round(lcp),
          tbt_ms: Math.round(tbt),
          cls: parseFloat(cls.toFixed(4)),
          speed_index_ms: Math.round(speedIndex),
          ttfb_ms: Math.round(ttfb),
          total_bytes: totalByteWeight,
          total_bytes_display: totalBytesDisplay,
          js_execution_ms: Math.round(jsExecutionMs),
          main_thread_work_ms: Math.round(mainThreadWorkMs),
          network_requests: numRequests,
          image_requests: imageCount,
          image_bytes: imageBytes,
          lcp_element: lcpElement
        };

        console.log(`    Score: ${score} | LCP: ${Math.round(lcp)}ms | FCP: ${Math.round(fcp)}ms | TBT: ${Math.round(tbt)}ms | CLS: ${cls.toFixed(4)} | Total: ${totalBytesDisplay} | JS: ${Math.round(jsExecutionMs)}ms`);
      }
    } catch (e) {
      console.error(`    [ERROR] ${e.message}`);
    }
  }
}

// Save P4C matrix
const matrixOut = path.join(outDir, 'p4c_matrix.json');
fs.writeFileSync(matrixOut, JSON.stringify(p4cResults, null, 2), 'utf8');
console.log(`\nSaved P4C matrix to: ${matrixOut}`);
