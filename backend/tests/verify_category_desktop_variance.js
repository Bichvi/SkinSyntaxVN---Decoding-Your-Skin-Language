// backend/tests/verify_category_desktop_variance.js
const { execSync } = require('child_process');
const fs = require('fs');

const url = 'http://localhost/index.php?r=tatca';
console.log('Running 3 repeated measures on Category Desktop to evaluate median and variance...');

const scores = [];
const tbts = [];
const lcps = [];
const fcps = [];

for (let i = 1; i <= 3; i++) {
  const jsonPath = `backend/experiments/performance/frontend_p4/p4c/lh_category_desktop_run${i}.json`;
  const cmd = `npx -y lighthouse "${url}" --chrome-flags="--headless=new --no-sandbox --disable-gpu" --output=json --output-path="${jsonPath}" --preset=desktop --only-categories=performance --max-wait-for-load=45000 --quiet`;
  try {
    execSync(cmd, { stdio: 'pipe', timeout: 60000 });
    const raw = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
    const score = Math.round(raw.categories.performance.score * 100);
    const tbt = Math.round(raw.audits['total-blocking-time'].numericValue);
    const lcp = Math.round(raw.audits['largest-contentful-paint'].numericValue);
    const fcp = Math.round(raw.audits['first-contentful-paint'].numericValue);
    scores.push(score);
    tbts.push(tbt);
    lcps.push(lcp);
    fcps.push(fcp);
    console.log(`Run ${i}: Score = ${score} | LCP = ${lcp}ms | FCP = ${fcp}ms | TBT = ${tbt}ms`);
  } catch (e) {
    console.error(`Run ${i} error:`, e.message);
  }
}

if (scores.length > 0) {
  scores.sort((a,b) => a - b);
  tbts.sort((a,b) => a - b);
  lcps.sort((a,b) => a - b);
  fcps.sort((a,b) => a - b);
  const mid = Math.floor(scores.length / 2);
  console.log(`\n=== CATEGORY DESKTOP STATISTICAL SUMMARY ===`);
  console.log(`Median Score: ${scores[mid]} (range: ${scores[0]} - ${scores[scores.length-1]})`);
  console.log(`Median LCP: ${lcps[mid]}ms (range: ${lcps[0]}ms - ${lcps[lcps.length-1]}ms)`);
  console.log(`Median FCP: ${fcps[mid]}ms (range: ${fcps[0]}ms - ${fcps[fcps.length-1]}ms)`);
  console.log(`Median TBT: ${tbts[mid]}ms (range: ${tbts[0]}ms - ${tbts[tbts.length-1]}ms)`);
}
