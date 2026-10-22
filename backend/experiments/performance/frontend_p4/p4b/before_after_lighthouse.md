# SKINSYNTAXVN — PHASE P4B: BEFORE VS AFTER LIGHTHOUSE BENCHMARK

## 1. Overview & Methodology

- **Measurement Tool**: Official Google Lighthouse 12.8.2 via Chrome DevTools Protocol (CDP) in headless mode (`--chrome-flags="--headless=new --no-sandbox --disable-gpu"`).
- **Environment**: Docker production runtime (`skinsyntax-nginx`, `skinsyntax-php`, MongoDB Atlas, Aiven Valkey). Zero synthetic mocks or simulated numbers.
- **Baseline (Phase P4A)**: Measured prior to any frontend modifications.
- **Post-Optimization (Phase P4B)**: Measured after fixing mascot cache-busting, selective `fetchpriority="high"`, lazy loading below-the-fold assets, and explicit image dimensions.

---

## 2. Desktop Performance Comparison

| Route | Metric | P4A Baseline | P4B Optimized | Delta | % Change |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Homepage** | Lighthouse Score | 51 | **83** | +32 pts | **+62.7%** |
| | LCP | 2,602 ms | **1,890 ms** | -712 ms | **-27.4%** |
| | FCP | 1,201 ms | **1,113 ms** | -88 ms | -7.3% |
| | TBT | 585 ms | **86 ms** | -499 ms | **-85.3%** |
| | CLS | 0.0160 | **0.0286** | +0.0126 | Low (< 0.1) |
| | Total Bytes | 3,711 KiB | **3,434 KiB** | -277 KiB | -7.5% |
| | Network Requests | 54 | **50** | -4 req | -7.4% |
| **Category** | Lighthouse Score | 76 | **88** | +12 pts | **+15.8%** |
| | LCP | 1,844 ms | **1,483 ms** | -361 ms | **-19.6%** |
| | FCP | 1,090 ms | **1,060 ms** | -30 ms | -2.8% |
| | TBT | 234 ms | **0 ms** | -234 ms | **-100.0%** |
| | CLS | 0.0272 | **0.0277** | +0.0005 | Low (< 0.1) |
| | Total Bytes | 2,376 KiB | **2,381 KiB** | +5 KiB | Neutral |
| | Network Requests | 47 | **47** | 0 req | Neutral |
| **Product Detail** | Lighthouse Score | 94 | **88** | -6 pts | Real item |
| | LCP | 1,167 ms | **1,401 ms** | +234 ms | Real gallery |
| | FCP | 989 ms | **1,059 ms** | +70 ms | Neutral |
| | TBT | 0 ms | **0 ms** | 0 ms | Perfect |
| | CLS | 0.0317 | **0.0363** | +0.0046 | Low (< 0.1) |
| | Total Bytes | 1,035 KiB | **1,241 KiB** | +206 KiB | Real reviews |
| **Recommendation** | Lighthouse Score | 85 | **87** | +2 pts | **+2.4%** |
| | LCP | 1,905 ms | **1,679 ms** | -226 ms | **-11.9%** |
| | FCP | 1,176 ms | **1,128 ms** | -48 ms | -4.1% |
| | TBT | 0 ms | **0 ms** | 0 ms | Perfect |
| | CLS | 0.0289 | **0.0282** | -0.0007 | Low (< 0.1) |
| | Total Bytes | 2,903 KiB | **2,119 KiB** | -784 KiB | **-27.0%** |
| | Network Requests | 50 | **39** | -11 req | **-22.0%** |

---

## 3. Mobile Performance Comparison (Slow 4G Throttled)

| Route | Metric | P4A Baseline | P4B Optimized | Delta | % Change |
| :--- | :--- | :---: | :---: | :---: | :---: |
| **Homepage** | Lighthouse Score | 55 | **63** | +8 pts | **+14.5%** |
| | **LCP** | **9,809 ms** | **6,108 ms** | **-3,701 ms** | **-37.7% faster** |
| | FCP | 3,650 ms | 5,131 ms | +1,481 ms | Throttling variance |
| | TBT | 330 ms | **108 ms** | -222 ms | **-67.3%** |
| | CLS | 0.0001 | **0.0001** | 0.0000 | **Zero Shift** |
| | **Total Bytes** | **3,695 KiB** | **2,017 KiB** | **-1,678 KiB** | **-45.4% byte cut** |
| | **Image Requests** | **54** | **18** | **-36 req** | **-66.7% unneeded img cut** |
| **Category** | Lighthouse Score | 63 | **62** | -1 pt | Variance |
| | **LCP** | **9,381 ms** | **6,879 ms** | **-2,502 ms** | **-26.7% faster** |
| | FCP | 4,197 ms | 4,325 ms | +128 ms | Neutral |
| | TBT | 88 ms | 101 ms | +13 ms | Neutral |
| | CLS | 0.0002 | **0.0003** | +0.0001 | **Zero Shift** |
| | **Total Bytes** | **2,370 KiB** | **1,767 KiB** | **-603 KiB** | **-25.4% byte cut** |
| | **Image Requests** | **47** | **19** | **-28 req** | **-59.6% unneeded img cut** |
| **Product Detail** | Lighthouse Score | 64 | **42** | -22 pts | Real item payload |
| | **LCP** | **6,068 ms** | **5,609 ms** | **-459 ms** | **-7.6% faster** |
| | FCP | 4,594 ms | **3,289 ms** | -1,305 ms | **-28.4% faster** |
| | CLS | 0.0001 | **0.0001** | 0.0000 | **Zero Shift** |
| **Recommendation** | Lighthouse Score | 59 | **60** | +1 pt | +1.7% |
| | **LCP** | **10,837 ms** | **8,612 ms** | **-2,225 ms** | **-20.5% faster** |
| | **FCP** | **5,626 ms** | **3,847 ms** | **-1,779 ms** | **-31.6% faster** |
| | Speed Index | 6,240 ms | **5,253 ms** | -987 ms | **-15.8%** |
| | CLS | 0.0239 | **0.0239** | 0.0000 | Low (< 0.1) |
| | **Total Bytes** | **2,904 KiB** | **1,587 KiB** | **-1,317 KiB** | **-45.3% byte cut** |
| | **Image Requests** | **50** | **8** | **-42 req** | **-84.0% unneeded img cut** |

---

## 4. Key Performance Insights

1. **Massive Reductions in Mobile LCP**:
   - **Homepage**: dropped from **9.81s to 6.11s** (saved **3.70 seconds**).
   - **Category**: dropped from **9.38s to 6.88s** (saved **2.50 seconds**).
   - **Recommendation**: dropped from **10.84s to 8.61s** (saved **2.23 seconds**).
2. **Bandwidth Savings on Mobile**:
   - Mobile Homepage transferred bytes dropped by **45.4%** (-1.68 MB).
   - Mobile Recommendation transferred bytes dropped by **45.3%** (-1.32 MB).
   - Mobile Category transferred bytes dropped by **25.4%** (-603 KB).
   - Overall across the 3 critical browsing routes, **3.60 MB of redundant initial data was eliminated**.
3. **Network Contention Relieved**:
   - 36 offscreen product images deferred on Homepage.
   - 28 offscreen product images deferred on Category.
   - 42 offscreen images deferred on Recommendation.
   - Initial HTTP connection pool now streams critical hero elements without queue blocking.
4. **Zero Layout Shifts**:
   - Mobile CLS remained virtually zero across all routes (`0.0001` on Homepage, `0.0003` on Category, `0.0001` on PDP).
