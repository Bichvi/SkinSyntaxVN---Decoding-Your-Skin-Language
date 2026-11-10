// backend/tests/run_p4_all_routes.js
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const outDir = path.resolve(__dirname, '../experiments/performance/frontend_p4');
if (!fs.existsSync(outDir)) {
  fs.mkdirSync(outDir, { recursive: true });
}

const tests = [
  { name: 'category', url: 'http://localhost/index.php?r=tatca', preset: 'desktop', flag: '--preset=desktop' },
  { name: 'category', url: 'http://localhost/index.php?r=tatca', preset: 'mobile', flag: '' },
  { name: 'product_detail', url: 'http://localhost/index.php?r=chitiet&id=111', preset: 'desktop', flag: '--preset=desktop' },
  { name: 'product_detail', url: 'http://localhost/index.php?r=chitiet&id=111', preset: 'mobile', flag: '' },
  { name: 'search', url: 'http://localhost/index.php?r=tatca&q=serum', preset: 'desktop', flag: '--preset=desktop' },
  { name: 'search', url: 'http://localhost/index.php?r=tatca&q=serum', preset: 'mobile', flag: '' },
  { name: 'recommendation', url: 'http://localhost/index.php?r=goiy', preset: 'desktop', flag: '--preset=desktop' },
  { name: 'recommendation', url: 'http://localhost/index.php?r=goiy', preset: 'mobile', flag: '' },
];

for (const t of tests) {
  const jsonPath = path.join(outDir, `lh_${t.name}_${t.preset}.json`);
  console.log(`\n============================================================`);
  console.log(`Running Lighthouse on [${t.name.toUpperCase()}] [${t.preset.toUpperCase()}]`);
  console.log(`============================================================`);

  const cmd = `npx lighthouse "${t.url}" --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json --output-path="${jsonPath}" ${t.flag} --only-categories=performance --max-wait-for-load=35000 --quiet`;
  try {
    execSync(cmd, { stdio: 'inherit', timeout: 120000 });
    console.log(`-> Saved to: ${jsonPath}`);
  } catch (e) {
    console.error(`-> Error: ${e.message}`);
  }
}

console.log('\nAll tests finished.');
