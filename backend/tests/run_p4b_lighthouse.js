// backend/tests/run_p4b_lighthouse.js
// Automated Lighthouse Runner for Phase P4B: Safe Image Loading Optimization
// Compares P4B against P4A baseline under identical configurations.

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const p4aMatrixPath = path.resolve(__dirname, '../experiments/performance/frontend_p4/browser_baseline_matrix.json');
const outDir = path.resolve(__dirname, '../experiments/performance/frontend_p4/p4b');
if (!fs.existsSync(outDir)) {
  fs.mkdirSync(outDir, { recursive: true });
}

const routes = [
  { key: 'home', name: 'Homepage', url: 'http://localhost/index.php?r=home' },
  { key: 'category', name: 'Category', url: 'http://localhost/index.php?r=tatca' },
  { key: 'product_detail', name: 'Product Detail', url: 'http://localhost/index.php?r=chitiet&id=6376' },
  { key: 'recommendation', name: 'Recommendation', url: 'http://localhost/index.php?r=goiy' }
];

const presets = ['desktop', 'mobile'];
const p4bResults = {};

console.log('================================================================================');
console.log('SKINSYNTAXVN — PHASE P4B LIGHTHOUSE REBENCHMARK RUNNER');
console.log('Target Directory:', outDir);
console.log('================================================================================\n');

for (const r of routes) {
  p4bResults[r.key] = {};
  for (const preset of presets) {
    const filename = `lh_${r.key}_${preset}.json`;
    const jsonPath = path.join(outDir, filename);

    console.log(`>>> Measuring [${r.name.toUpperCase()}] on [${preset.toUpperCase()}] ...`);

    const presetFlag = preset === 'desktop' ? '--preset=desktop' : '--form-factor=mobile --screenEmulation.mobile=true';
    const cmd = `npx lighthouse "${r.url}" --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json --output-path="${jsonPath}" ${presetFlag} --only-categories=performance --max-wait-for-load=45000 --quiet`;

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
        const networkRequests = a['network-requests']?.details?.items || [];
        const numRequests = networkRequests.length;

        // Image breakdown
        const imageRequests = networkRequests.filter(item => item.resourceType === 'Image');
        const imageCount = imageRequests.length;
        const imageBytes = imageRequests.reduce((acc, item) => acc + (item.transferSize || 0), 0);

        // LCP element info
        const lcpElement = a['largest-contentful-paint-element']?.details?.items?.[0]?.node?.nodeLabel || 'N/A';

        p4bResults[r.key][preset] = {
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
          image_requests: imageCount,
          image_bytes: imageBytes,
          lcp_element: lcpElement
        };

        console.log(`    Score: ${score} | LCP: ${Math.round(lcp)}ms | FCP: ${Math.round(fcp)}ms | CLS: ${cls.toFixed(4)} | Total: ${totalBytesDisplay} | ImgReq: ${imageCount}`);
      }
    } catch (e) {
      console.error(`    [ERROR] ${e.message}`);
    }
  }
}

// Save P4B matrix
const matrixOut = path.join(outDir, 'p4b_matrix.json');
fs.writeFileSync(matrixOut, JSON.stringify(p4bResults, null, 2), 'utf8');
console.log(`\nSaved P4B matrix to: ${matrixOut}`);
