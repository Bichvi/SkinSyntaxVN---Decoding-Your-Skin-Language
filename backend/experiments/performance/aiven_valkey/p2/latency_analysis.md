# SkinSyntaxVN — Valkey Latency & Connection Architecture Analysis (Phase P2)

## 1. Executive Summary & Raw Measurements

Before rolling out caching across the storefront, micro-benchmarks were executed to measure each phase of the Valkey request lifecycle over TLS (`rediss://`) to Aiven Cloud.

### Micro-Benchmark Metrics (`backend/tests/benchmark_valkey_latency.php`):
- **Cold TLS Handshake Latency:** **513.59 ms** (Initial cold TCP + TLS 1.3 handshake across public cloud internet).
- **Warm Command Latency (Over Open Socket):**
  - `PING`: **62.44 ms**
  - `SETEX`: **63.35 ms**
  - `GET`: **59.83 ms**
- **Serialization / Deserialization CPU Overhead:**
  - `json_encode` (1,000 iterations): **0.491 ms** (< 0.001 ms per operation)
  - `json_decode` (1,000 iterations): **2.670 ms** (< 0.003 ms per operation)
  - *Finding:* Serialization overhead in PHP 8.2 is completely negligible.
- **Cache MISS Latency (Remote Lookup):** **94.49 ms**
- **Cache HIT Latency (Remote Retrieval):** **50.89 ms**
- **Degraded Fallback Detection Duration:** **47.72 ms** (instant non-blocking failover when Valkey is down).

---

## 2. Critical Architectural Findings: Cloud Valkey vs. Cloud MongoDB Atlas

### 2.1 The "Cloud-to-Cloud" Distance Reality
- Both **MongoDB Atlas** and **Aiven Valkey** reside in remote cloud data centers.
- Local PHP requests establish remote connections across the public internet:
  - Atlas query latency: **~55 ms to 300 ms** depending on query complexity and batching.
  - Valkey single command latency: **~50 ms to 65 ms**.

### 2.2 Where Valkey Delivers Massive Gains:
1. **Aggregations & Complex Multi-Table Lookups:**
   - On the **Category Page (`?r=tatca`)**:
     - MongoDB Atlas executes count aggregations and filter pagination taking **312 ms – 2,688 ms**.
     - Valkey serves the cached result in a single warm retrieval.
     - **Result:** Atlas query count dropped from **2 down to 0**, reducing page latency from **1,964 ms down to 1,232 ms** (37% faster).
2. **Sequential Multi-Query Compounding (Homepage):**
   - The Homepage previously fired **11 separate sequential queries** to Atlas (wait time: ~938 ms).
   - By serving Brands, Categories, and Static Carousels from Valkey, Atlas queries dropped from **11 down to 2** (82% database load reduction), and p95 tail latency dropped from **6,166 ms down to 2,899 ms** (52% reduction).

### 2.3 Where Valkey is NOT Faster (Important Discovery on Product Detail):
- On the **Product Detail Page (`?r=chitiet&id=111`)**:
  - The single indexed lookup `findOne(['id' => 111])` in MongoDB Atlas takes only **~57 ms**.
  - A remote TLS roundtrip to Aiven Valkey takes **~50 ms – 170 ms**.
  - *Observation:* Caching a single tiny indexed document in a remote cloud cache does NOT yield a significant speedup over an already warm MongoDB connection pool.
  - *Decision:* We retain cache-aside for PDP metadata, but recognize that remote cache benefits are primarily realized on **composite objects, aggregations, and high-frequency read queries**.

---

## 3. Optimizations Implemented in `CacheService`

To overcome the PHP shared-nothing stateless penalty and remote TLS overhead, `CacheService.php` was engineered with:
1. **Persistent TLS Connection Pooling:** Configured `'persistent' => true` in Predis options to eliminate repeated TLS handshakes within PHP worker processes.
2. **L1 In-Memory Request-Scoped Cache:**
   - Any key retrieved or set during a PHP request is retained in `$this->l1MemoryCache`.
   - Repeated lookups for the same key (e.g. `danh_muc` and `thuong_hieu` referenced multiple times across headers, filters, and controllers) return in **0.00 ms with 0 network calls**.
3. **Lazy Connection Initialization:** Eliminated redundant `ping()` calls on socket connect; the connection connects lazily upon the first actual data command.
