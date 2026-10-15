# SkinSyntaxVN — Performance Comparative Analysis & Projections (Phase P1)

## 1. Baseline Performance (Phase P0 Measured)

Measured across 5 runs per endpoint against production MongoDB Atlas:

| Endpoint | Baseline p50 Latency | Baseline p95 Latency | MongoDB Atlas Queries | Atlas Database Time |
| :--- | :---: | :---: | :---: | :---: |
| **Homepage (`?r=home`)** | **2,503.51 ms** | **6,166.06 ms** | **11 queries** | **938.18 ms** |
| **Category (`?r=tatca`)** | **1,964.97 ms** | **2,814.00 ms** | **2 queries** | **312.88 ms** |
| **Product Detail (`?r=chitiet&id=111`)** | **446.63 ms** | **614.78 ms** | **1 query** | **57.71 ms** |
| **Search (`?r=timkiem&keyword=serum`)** | **1,894.64 ms** | **2,305.38 ms** | **2 queries** | **323.15 ms** |
| **Recommendation (`?r=goiy`)** | **1,956.64 ms** | **2,623.99 ms** | **7 queries** | **614.49 ms** |

---

## 2. Phase P1 Status: Infrastructure & Fallback Readiness

In Phase P1, the Aiven Valkey connection has been established, tested, and validated:
- Connection latency to Aiven Valkey over TLS: **~780ms - 1400ms** for cross-regional TLS handshake, but subsequent commands in warm connections operate in single-digit milliseconds.
- `CacheService` abstraction is operational with complete circuit-breaker fallback.
- **Strict Scope Control:** In accordance with instructions, business routes have NOT yet been modified in P1. They continue serving authoritative data directly from MongoDB Atlas without regression.

---

## 3. Projected Improvements for Phase P2 (Pending Approval)

Once Phase P2 is approved and applied to Homepage, Category, and Product Detail:

| Metric | Baseline (P0) | Projected Cache MISS | Projected Cache HIT (P2) | Expected Reduction |
| :--- | :---: | :---: | :---: | :---: |
| **Homepage p50** | 2,503 ms | ~2,600 ms | **< 350 ms** | **-85% latency** |
| **Homepage Atlas Queries** | 11 | 11 | **0 - 2** | **-80% query load** |
| **Category p50** | 1,964 ms | ~2,000 ms | **< 300 ms** | **-85% latency** |
| **Product Detail p50** | 446 ms | ~450 ms | **< 150 ms** | **-66% latency** |
| **Database Load on Atlas** | 100% | 100% | **~15 - 20%** | Massive Atlas RU savings |
