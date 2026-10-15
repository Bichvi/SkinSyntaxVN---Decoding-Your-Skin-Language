# SkinSyntaxVN — Performance Baseline Measurements (Phase P0)

**Date of Measurement:** October 2026  
**Environment:** Docker local development, PHP 8.2.33, Nginx, MongoDB Atlas (Production Cluster)  
**Methodology:** Automated HTTP curl benchmarking across 5 iterations per endpoint, paired with internal MongoDB command-subscriber profiling (`MongoDB\Driver\Monitoring\CommandSubscriber`).  
**Cache State:** Disabled / No Valkey cache applied (pure MongoDB Atlas queries).

---

## 1. Executive Summary Table

| Page / Route | HTTP Status | p50 Latency (ms) | p95 Latency (ms) | Avg Latency (ms) | MongoDB Atlas Queries | Atlas Query Time (ms) | Peak Memory | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Homepage** (`?r=home`) | 200 | **2,503.51** | **6,166.06** | 3,156.43 | **11** | 938.18 | 22 MB | 0% |
| **Category Listing** (`?r=tatca`) | 200 | **1,964.97** | **2,814.00** | 1,936.98 | **2** | 312.88 | 22 MB | 0% |
| **Product Detail** (`?r=chitiet&id=111`) | 200 | **446.63** | **614.78** | 493.25 | **1** | 57.71 | 22 MB | 0% |
| **Search** (`?r=timkiem&keyword=serum`) | 200 | **1,894.64** | **2,305.38** | 1,798.63 | **2** | 323.15 | 22 MB | 0% |
| **Recommendation** (`?r=goiy`) | 200 | **1,956.64** | **2,623.99** | 2,016.28 | **7** | 614.49 | 22 MB | 0% |

---

## 2. Detailed Route Breakdown

### 2.1 Homepage (`index.php?r=home`)
- **HTTP Latency Profile:**
  - Min: 2,191.63 ms
  - Max: 6,166.06 ms (cold connection)
  - Avg: 3,156.43 ms
  - **p50:** 2,503.51 ms
  - **p95:** 6,166.06 ms
- **Atlas Queries (11 Total):**
  - `san_pham`: 4 finds (featured, new arrivals, promotional, top-rated) + 1 aggregate
  - `danh_muc`: 2 finds (root categories and active lists)
  - `thuong_hieu`: 1 find (featured brand partner list)
  - `xuat_xu`: 1 find (countries of origin)
  - `flash_sale_campaigns`: 1 find (active flash sale)
- **Atlas Roundtrip Latency:** 938.18 ms cumulative database wait time.
- **Root Cause of High Latency:** Multiple sequential queries to MongoDB Atlas over the cloud network. Caching static elements (brands, categories, flash sales, featured products) in Valkey will eliminate 9 of these 11 queries on cache HIT.

### 2.2 Category / TatCa (`index.php?r=tatca`)
- **HTTP Latency Profile:**
  - Min: 1,282.15 ms
  - Max: 2,814.00 ms
  - Avg: 1,936.98 ms
  - **p50:** 1,964.97 ms
  - **p95:** 2,814.00 ms
- **Atlas Queries (2 Total):**
  - `san_pham`: 1 aggregate (facets / filter counting), 1 find (paginated items)
- **Atlas Roundtrip Latency:** 312.88 ms.

### 2.3 Product Detail Page (`index.php?r=chitiet&id=111`)
- **HTTP Latency Profile:**
  - Min: 404.43 ms
  - Max: 614.78 ms
  - Avg: 493.25 ms
  - **p50:** 446.63 ms
  - **p95:** 614.78 ms
- **Atlas Queries (1 Total):**
  - `san_pham`: 1 find
- **Atlas Roundtrip Latency:** 57.71 ms.

### 2.4 Search (`index.php?r=timkiem&keyword=serum`)
- **HTTP Latency Profile:**
  - Min: 1,096.17 ms
  - Max: 2,305.38 ms
  - Avg: 1,798.63 ms
  - **p50:** 1,894.64 ms
  - **p95:** 2,305.38 ms
- **Atlas Queries (2 Total):**
  - `san_pham`: 1 aggregate (regex/text search pipeline), 1 find

### 2.5 Recommendation (`index.php?r=goiy`)
- **HTTP Latency Profile:**
  - Min: 1,686.01 ms
  - Max: 2,623.99 ms
  - Avg: 2,016.28 ms
  - **p50:** 1,956.64 ms
  - **p95:** 2,623.99 ms
- **Atlas Queries (7 Total):**
  - `san_pham`: 5 finds (candidate retrieval across skin profiles and product ingredients)
  - `thuong_hieu`: 1 find
- **Atlas Roundtrip Latency:** 614.49 ms.

---

## 3. Baseline Audit Conclusion

- MongoDB Atlas query latency is significantly amplified by network distance between local runtime and cloud database clusters.
- The Homepage executes 11 queries taking nearly 1 full second in database wait time alone, causing HTTP response times of 2.5s – 6.1s.
- Caching read-heavy objects (brands, categories, featured carousels, PDP metadata) via Aiven Valkey in Phase P2 will drop p50 response times down significantly, achieving sub-second loads on warm cache.
