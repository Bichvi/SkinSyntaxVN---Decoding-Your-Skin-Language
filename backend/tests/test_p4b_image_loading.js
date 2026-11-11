const http = require('http');

const testRoutes = [
  { name: 'Homepage', url: 'http://localhost/index.php' },
  { name: 'Category', url: 'http://localhost/index.php?r=tatca' },
  { name: 'Product Detail', url: 'http://localhost/index.php?r=chitiet&id=6376' },
  { name: 'Recommendation', url: 'http://localhost/index.php?r=goiy' },
  { name: 'Flash Sale', url: 'http://localhost/index.php?r=flash_sale' },
  { name: 'Search', url: 'http://localhost/index.php?r=tatca&keyword=kem' }
];

function fetchRoute(route) {
  return new Promise((resolve, reject) => {
    http.get(route.url, (res) => {
      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        const hasPhpError = /Fatal error|Parse error|Warning:|Notice:/i.test(data);
        const hasTimeMascot = data.includes('syna_mascot.png?v=time()');
        const mascotMatches = data.match(/syna_mascot\.png\?v=([0-9a-zA-Z_]+)/g) || [];
        const lazyCount = (data.match(/loading="lazy"/g) || []).length;
        const fetchPriorityCount = (data.match(/fetchpriority="high"/g) || []).length;
        const decodingAsyncCount = (data.match(/decoding="async"/g) || []).length;
        const dimensionCount = (data.match(/width="[0-9]+"\s+height="[0-9]+"/g) || []).length;

        resolve({
          name: route.name,
          url: route.url,
          statusCode: res.statusCode,
          hasPhpError,
          hasTimeMascot,
          mascotMatches: mascotMatches.slice(0, 2),
          lazyCount,
          fetchPriorityCount,
          decodingAsyncCount,
          dimensionCount,
          htmlLength: data.length
        });
      });
    }).on('error', reject);
  });
}

async function run() {
  console.log('=== P4B IMAGE LOADING VERIFICATION ===');
  for (const route of testRoutes) {
    try {
      const res = await fetchRoute(route);
      console.log(`[${res.statusCode === 200 ? 'PASS' : 'FAIL'}] ${res.name}: status=${res.statusCode}, phpError=${res.hasPhpError}, timeMascot=${res.hasTimeMascot}, lazy=${res.lazyCount}, fetchPriority=${res.fetchPriorityCount}, decoding=${res.decodingAsyncCount}, dimensions=${res.dimensionCount}, mascot=${res.mascotMatches[0] || 'N/A'}`);
    } catch (e) {
      console.error(`[ERROR] ${route.name}:`, e.message);
    }
  }
}

run();
