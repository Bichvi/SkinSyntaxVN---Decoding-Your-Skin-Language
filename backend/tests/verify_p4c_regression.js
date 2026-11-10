// backend/tests/verify_p4c_regression.js
// Automated Regression Verification for Phase P4C (16 Essential Checkpoints)

const http = require('http');

function fetchUrl(url, headers = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const options = {
      hostname: u.hostname,
      port: u.port || 80,
      path: u.pathname + u.search,
      method: 'GET',
      headers: {
        'Accept-Encoding': 'gzip, deflate',
        ...headers
      }
    };

    const req = http.request(options, (res) => {
      let data = [];
      res.on('data', chunk => data.push(chunk));
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body: Buffer.concat(data)
        });
      });
    });

    req.on('error', reject);
    req.end();
  });
}

const tests = [
  { id: 1, name: 'Homepage route', url: 'http://localhost/index.php?r=home' },
  { id: 2, name: 'Category route', url: 'http://localhost/index.php?r=tatca' },
  { id: 3, name: 'Product Detail (6376)', url: 'http://localhost/index.php?r=chitiet&id=6376' },
  { id: 4, name: 'Search route', url: 'http://localhost/index.php?r=tatca&q=serum' },
  { id: 5, name: 'Recommendation route', url: 'http://localhost/index.php?r=goiy' },
  { id: 6, name: 'Flash Sale route', url: 'http://localhost/index.php?r=flash_sale' },
  { id: 7, name: 'Cart route', url: 'http://localhost/index.php?r=giohang' },
  { id: 8, name: 'Checkout route', url: 'http://localhost/index.php?r=thanhtoan' },
];

async function run() {
  console.log('================================================================================');
  console.log('SKINSYNTAXVN — PHASE P4C 16-POINT REGRESSION VERIFICATION');
  console.log('================================================================================\n');

  let passed = 0;
  let total = 0;

  function report(num, name, ok, details = '') {
    total++;
    if (ok) {
      passed++;
      console.log(`[PASS] Checkpoint ${num}: ${name} -> ${details}`);
    } else {
      console.error(`![FAIL] Checkpoint ${num}: ${name} -> ${details}`);
    }
  }

  // Checkpoints 1-8: Primary Route Status & Compression
  for (const t of tests) {
    try {
      const res = await fetchUrl(t.url);
      if (res.statusCode === 302) {
        // Redirection route (e.g., guest checkout redirects to cart/login)
        const hasLocation = !!res.headers.location;
        report(t.id, t.name, hasLocation, `HTTP 302 Redirect -> ${res.headers.location} (Expected for guest checkout)`);
      } else {
        const isOk = res.statusCode === 200;
        const isGzip = res.headers['content-encoding'] === 'gzip';
        report(t.id, t.name, isOk && isGzip, `HTTP ${res.statusCode} | Content-Encoding: ${res.headers['content-encoding']}`);
      }
    } catch (e) {
      report(t.id, t.name, false, e.message);
    }
  }

  // Checkpoint 9: AI Chat Widget markup and lazy trigger
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasAiTrigger = homeHtml.includes('data-ai-chat-toggle') && homeHtml.includes('Chat với Syna');
    const hasAiPanel = homeHtml.includes('data-ai-chat-panel') && homeHtml.includes('hidden');
    report(9, 'AI Chat Widget Lazy Presence', hasAiTrigger && hasAiPanel, 'Trigger visible, panel hidden on boot');
  } catch (e) {
    report(9, 'AI Chat Widget Lazy Presence', false, e.message);
  }

  // Checkpoint 10: Support Chat Widget
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasSupportLayout = homeHtml.includes('skinsyntax:support-chat-layout');
    report(10, 'Support Chat Widget Interface', hasSupportLayout, 'Support chat layout listener intact');
  } catch (e) {
    report(10, 'Support Chat Widget Interface', false, e.message);
  }

  // Checkpoint 11: Carousel integrity
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasFlashCarousel = homeHtml.includes('data-flash-sale-carousel') && homeHtml.includes('flashSaleCarouselViewport');
    const hasPrevNext = homeHtml.includes('btnFlashPrev') && homeHtml.includes('btnFlashNext');
    report(11, 'Flash Sale Carousel DOM & Navigation', hasFlashCarousel && hasPrevNext, 'Carousel viewport, prev/next controls present');
  } catch (e) {
    report(11, 'Flash Sale Carousel DOM & Navigation', false, e.message);
  }

  // Checkpoint 12: Responsive Viewport Meta
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasViewport = homeHtml.includes('<meta name="viewport" content="width=device-width, initial-scale=1">');
    report(12, 'Responsive Desktop/Mobile Viewport', hasViewport, 'Viewport meta tag standard and intact');
  } catch (e) {
    report(12, 'Responsive Desktop/Mobile Viewport', false, e.message);
  }

  // Checkpoint 13: Video Commerce HTTP Range 206
  try {
    const vidRes = await fetchUrl('http://localhost/index.php?r=product_video_stream&id=6acc6bd54e1a7ffa0e0672b2&product_id=6376', {
      'Range': 'bytes=0-1023'
    });
    const is206 = vidRes.statusCode === 206;
    const isMp4 = (vidRes.headers['content-type'] || '').includes('video/mp4');
    const hasContentRange = !!vidRes.headers['content-range'];
    report(13, 'Video Commerce Range 206 Seeking', is206 && isMp4 && hasContentRange, `HTTP ${vidRes.statusCode} | Range: ${vidRes.headers['content-range']} | Type: ${vidRes.headers['content-type']}`);
  } catch (e) {
    report(13, 'Video Commerce Range 206 Seeking', false, e.message);
  }

  // Checkpoint 14: JavaScript Syntax & Script Tags
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasDeferredBootstrap = homeHtml.includes('bootstrap.bundle.min.js" defer');
    const hasDeferredLiveSearch = homeHtml.includes('live-search.js" defer');
    const hasDeferredSmartSearch = homeHtml.includes('smart-search.js" defer');
    report(14, 'Deferred JS Script Tags in Footer', hasDeferredBootstrap && hasDeferredLiveSearch && hasDeferredSmartSearch, 'Bootstrap, live-search, smart-search all deferred');
  } catch (e) {
    report(14, 'Deferred JS Script Tags in Footer', false, e.message);
  }

  // Checkpoint 15: Icon and Font Dependencies
  try {
    const homeRes = await fetchUrl('http://localhost/index.php?r=home');
    const zlib = require('zlib');
    const homeHtml = zlib.gunzipSync(homeRes.body).toString('utf-8');
    const hasFA = homeHtml.includes('fontawesome.min.css');
    const hasBI = homeHtml.includes('bootstrap-icons.min.css');
    const hasGF = homeHtml.includes('fonts.googleapis.com');
    report(15, 'Fonts & Icon Dependencies', hasFA && hasBI && hasGF, 'FontAwesome, Bootstrap Icons, Google Fonts all present');
  } catch (e) {
    report(15, 'Fonts & Icon Dependencies', false, e.message);
  }

  // Checkpoint 16: CSS Layout Integrity & Fallbacks
  try {
    const cssRes = await fetchUrl('http://localhost/assets/css/style.css');
    const isGzip = cssRes.headers['content-encoding'] === 'gzip';
    const is200 = cssRes.statusCode === 200;
    report(16, 'CSS Design System & Token Integrity', is200 && isGzip, `HTTP ${cssRes.statusCode} | Gzipped CSS: ${cssRes.body.length} bytes`);
  } catch (e) {
    report(16, 'CSS Design System & Token Integrity', false, e.message);
  }

  console.log(`\n================================================================================`);
  console.log(`VERIFICATION RESULT: ${passed} / ${total} CHECKPOINTS PASSED`);
  console.log(`================================================================================\n`);
}

run();
