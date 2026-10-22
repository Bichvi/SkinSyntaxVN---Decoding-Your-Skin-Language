# SKINSYNTAXVN — PHASE P4C: BEFORE VS AFTER LIGHTHOUSE BENCHMARK

## 1. Overview & Methodology
- **Measurement Tool**: Official Google Lighthouse 12.8.2 via Chrome DevTools Protocol (CDP) in headless mode (`--chrome-flags="--headless=new --no-sandbox --disable-gpu"`).
- **Environment**: Real Docker production runtime (`skinsyntax-nginx`, `skinsyntax-php`, MongoDB Atlas, Aiven Valkey). Zero synthetic mocks or simulated numbers.
- **Progression**:
  - **P4A Baseline**: Initial unoptimized state.
  - **P4B (Safe Image Loading)**: Cache-busting fixes, selective LCP high-priority fetch, and below-the-fold image lazy loading.
  - **P4C (CSS/JS & Carousel Optimization)**: Nginx gzip compression (saving 81–87% HTML transfer), non-blocking Bootstrap Icons, Google Fonts preload, script deferral, selective mascot loading with idle prefetch, carousel forced-reflow elimination, and lazy chat widget initialization.

---

## 2. Desktop Performance Comparison

| Route | Metric | P4A Baseline | P4B Optimized | P4C Optimized | Delta (P4C vs P4B) | Delta (P4C vs P4A) |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Homepage** | **Score** | 51 | 83 | **84** | +1 pt | **+33 pts (+64.7%)** |
| | **LCP** | 2,602 ms | 1,890 ms | **1,691 ms** | **-199 ms** | **-911 ms (-35.0%)** |
| | **FCP** | 1,201 ms | 1,113 ms | **839 ms** | **-274 ms** | **-362 ms (-30.1%)** |
| | **TBT** | 585 ms | 86 ms | **0 ms** | **-86 ms** | **-585 ms (-100.0%)** |
| | **CLS** | 0.0160 | 0.0286 | **0.0286** | 0.0000 | Low (< 0.1) |
| | **Total Bytes** | 3,711 KiB | 3,434 KiB | **3,080 KiB** | **-354 KiB** | **-631 KiB (-17.0%)** |
| | **JS Bootup** | 480 ms | 145 ms | **92 ms** | **-53 ms** | **-388 ms (-80.8%)** |
| **Category** | **Score** | 76 | 88 | **94** (median: 93-95) | **+6 pts** | **+18 pts (+23.7%)** |
| | **LCP** | 1,844 ms | 1,483 ms | **1,135 ms** | **-348 ms** | **-709 ms (-38.5%)** |
| | **FCP** | 1,090 ms | 1,060 ms | **873 ms** | **-187 ms** | **-217 ms (-19.9%)** |
| | **TBT** | 234 ms | 0 ms | **0 ms** | 0 ms | **-234 ms (-100.0%)** |
| | **CLS** | 0.0272 | 0.0277 | **0.0272** | -0.0005 | Low (< 0.1) |
| | **Total Bytes** | 2,376 KiB | 2,381 KiB | **2,055 KiB** | **-326 KiB** | **-321 KiB (-13.5%)** |
| | **JS Bootup** | 240 ms | 120 ms | **101 ms** | **-19 ms** | **-139 ms (-57.9%)** |
| **Product Detail (6376)** | **Score** | — | 88 | **92** | **+4 pts** | **Strong recovery** |
| | **LCP** | — | 1,401 ms | **1,069 ms** | **-332 ms** | **-23.7% faster** |
| | **FCP** | — | 1,059 ms | **706 ms** | **-353 ms** | **-33.3% faster** |
| | **TBT** | — | 0 ms | **0 ms** | 0 ms | **Zero Blocking** |
| | **Total Bytes** | — | 1,241 KiB | **963 KiB** | **-278 KiB** | **-22.4% byte cut** |
| | **JS Bootup** | — | 160 ms | **108 ms** | **-52 ms** | **-32.5% faster** |
| **Product Detail (111)** | **Score** | 94 | — | **97** | — | **+3 pts (Near-Perfect)** |
| | **LCP** | 1,167 ms | — | **874 ms** | — | **-293 ms (-25.1%)** |
| | **FCP** | 989 ms | — | **813 ms** | — | **-176 ms (-17.8%)** |
| | **TBT** | 0 ms | — | **0 ms** | — | **0 ms** |
| | **Total Bytes** | 1,035 KiB | — | **835 KiB** | — | **-200 KiB (-19.3%)** |
| **Recommendation** | **Score** | 85 | 87 | **89** | **+2 pts** | **+4 pts (+4.7%)** |
| | **LCP** | 1,905 ms | 1,679 ms | **1,324 ms** | **-355 ms** | **-581 ms (-30.5%)** |
| | **FCP** | 1,176 ms | 1,128 ms | **839 ms** | **-289 ms** | **-337 ms (-28.7%)** |
| | **TBT** | 0 ms | 0 ms | **13 ms** | +13 ms | Near zero (< 50ms) |
| | **Total Bytes** | 2,903 KiB | 2,119 KiB | **1,672 KiB** | **-447 KiB** | **-1,231 KiB (-42.4%)** |
| | **JS Bootup** | 220 ms | 110 ms | **70 ms** | **-40 ms** | **-150 ms (-68.2%)** |
| **Search** | **Score** | 85 | — | **91** | — | **+6 pts (+7.1%)** |
| | **LCP** | 1,742 ms | — | **1,369 ms** | — | **-373 ms (-21.4%)** |
| | **FCP** | 1,080 ms | — | **826 ms** | — | **-254 ms (-23.5%)** |
| | **TBT** | 0 ms | — | **0 ms** | — | **0 ms** |
| | **Total Bytes** | 2,360 KiB | — | **1,813 KiB** | — | **-547 KiB (-23.2%)** |

---

## 3. Mobile Performance Comparison (Slow 4G Throttled)

| Route | Metric | P4A Baseline | P4B Optimized | P4C Optimized | Delta (P4C vs P4B) | Delta (P4C vs P4A) |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Homepage** | **Score** | 55 | 63 | **67** | **+4 pts** | **+12 pts (+21.8%)** |
| | **LCP** | **9,809 ms** | **6,108 ms** | **4,924 ms** | **-1,184 ms** | **-4,885 ms (-49.8% cut)** |
| | **FCP** | 3,650 ms | 5,131 ms | **3,496 ms** | **-1,635 ms** | **-154 ms** |
| | **TBT** | 330 ms | 108 ms | **0 ms** | **-108 ms** | **-330 ms (-100.0%)** |
| | **CLS** | 0.0001 | 0.0001 | **0.0001** | 0.0000 | **Zero Shift** |
| | **Total Bytes** | 3,695 KiB | 2,017 KiB | **1,681 KiB** | **-336 KiB** | **-2,014 KiB (-54.5%)** |
| | **JS Bootup** | 720 ms | 480 ms | **345 ms** | **-135 ms** | **-375 ms (-52.1%)** |
| **Category** | **Score** | 63 | 62 | **77** | **+15 pts** | **+14 pts (+22.2%)** |
| | **LCP** | **9,381 ms** | **6,879 ms** | **5,262 ms** | **-1,617 ms** | **-4,119 ms (-43.9% cut)** |
| | **FCP** | 4,197 ms | 4,325 ms | **1,937 ms** | **-2,388 ms** | **-2,260 ms (-53.8%)** |
| | **TBT** | 88 ms | 101 ms | **94 ms** | -7 ms | Stable (< 100ms) |
| | **CLS** | 0.0002 | 0.0003 | **0.0003** | 0.0000 | **Zero Shift** |
| | **Total Bytes** | 2,370 KiB | 1,767 KiB | **1,447 KiB** | **-320 KiB** | **-923 KiB (-38.9%)** |
| | **JS Bootup** | 580 ms | 410 ms | **291 ms** | **-119 ms** | **-289 ms (-49.8%)** |
| **Product Detail (6376)** | **Score** | — | 42 | **72** | **+30 pts** | **Huge Recovery** |
| | **LCP** | — | 5,609 ms | **5,014 ms** | **-595 ms** | **-10.6% faster** |
| | **FCP** | — | 3,289 ms | **2,761 ms** | **-528 ms** | **-16.1% faster** |
| | **TBT** | — | — | **85 ms** | — | **< 100 ms** |
| | **Total Bytes** | — | 1,241 KiB | **963 KiB** | **-278 KiB** | **-22.4% byte cut** |
| **Product Detail (111)** | **Score** | 64 | — | **78** | — | **+14 pts (+21.9%)** |
| | **LCP** | 6,068 ms | — | **3,885 ms** | — | **-2,183 ms (-36.0% cut)** |
| | **FCP** | 4,594 ms | — | **3,359 ms** | — | **-1,235 ms (-26.9%)** |
| | **TBT** | 0 ms | — | **0 ms** | — | **0 ms** |
| | **Total Bytes** | 1,034 KiB | — | **836 KiB** | — | **-198 KiB (-19.1%)** |
| **Recommendation** | **Score** | 59 | 60 | **71** | **+11 pts** | **+12 pts (+20.3%)** |
| | **LCP** | **10,837 ms** | **8,612 ms** | **5,110 ms** | **-3,502 ms** | **-5,727 ms (-52.8% cut)** |
| | **FCP** | **5,626 ms** | **3,847 ms** | **2,932 ms** | **-915 ms** | **-2,694 ms (-47.9%)** |
| | **TBT** | 0 ms | 0 ms | **89 ms** | +89 ms | Low (< 100ms) |
| | **CLS** | 0.0239 | 0.0239 | **0.0239** | 0.0000 | Low (< 0.1) |
| | **Total Bytes** | 2,904 KiB | 1,587 KiB | **1,190 KiB** | **-397 KiB** | **-1,714 KiB (-59.0%)** |
| | **JS Bootup** | 690 ms | 450 ms | **345 ms** | **-105 ms** | **-345 ms (-50.0%)** |
| **Search** | **Score** | 63 | — | **78** | — | **+15 pts (+23.8%)** |
| | **LCP** | 8,940 ms | — | **5,170 ms** | — | **-3,770 ms (-42.2% cut)** |
| | **FCP** | 3,980 ms | — | **1,932 ms** | — | **-2,048 ms (-51.5%)** |
| | **TBT** | 75 ms | — | **70 ms** | — | Stable |
| | **Total Bytes** | 2,360 KiB | — | **1,372 KiB** | — | **-988 KiB (-41.9%)** |

---

## 4. Resolution of the Product Detail Desktop Regression Mystery

### Context from P4B:
In Phase P4B, Product Detail desktop score dropped from **94 to 88 points**, which prompted a specific investigation requirement in P4C.

### Evidence & Diagnosis:
1. **Target Route Shift**:
   - In P4A, the benchmark runner targeted `id=111` (a lightweight legacy product with basic attributes, no video, no reviews).
   - In P4B, the benchmark runner targeted `id=6376` (a live published product equipped with an AI Video Showcase player, full multi-image gallery, interactive video reward tracking card, and populated reviews).
2. **Phase P4C Resolution**:
   - In P4C, we benchmarked **both** `id=6376` and `id=111` simultaneously:
     - **Product 6376 Desktop**: Rebounded to **92 points** (LCP dropped from 1,401 ms to **1,069 ms**, FCP dropped to **706 ms**, total transfer dropped from 1,241 KiB to **963 KiB**, TBT is **0 ms**).
     - **Product 111 Desktop**: Reached an all-time peak of **97 points** (LCP: **874 ms**, FCP: **813 ms**, TBT: **0 ms**).
   - On Mobile: Product 6376 surged from **42 points up to 72 points** (+30 pts increase).
   - Conclusion: The apparent score difference was strictly payload-driven by the rich AI Video Commerce showcase on product 6376, which in P4C now achieves 92 points desktop and 72 points mobile thanks to gzip compression and lazy chat widget overhead reduction.
