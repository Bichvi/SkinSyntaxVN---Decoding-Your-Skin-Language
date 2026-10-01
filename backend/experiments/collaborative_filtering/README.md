# Phase C & C.1: Isolated Synthetic Collaborative Filtering Experiment & Scientific Validation

## Overview
This directory contains an isolated, reproducible research and offline evaluation pipeline for Collaborative Filtering (Item-Based kNN and Regularized Biased Matrix Factorization via SGD) on SkinSyntaxVN.

## Safety & Isolation Boundary
- **Production Database Integrity**: Production collections (`skinsyntax.tuong_tac_nguoi_dung`, `skinsyntax.hoa_don`, `skinsyntax.chi_tiet_hoa_don`, `skinsyntax.danh_gia`, `skinsyntax.san_pham`, `skinsyntax.nguoi_dung`) are **NEVER** modified. All reads from the production catalog are strictly READ-ONLY.
- **Experimental Database**: Synthetic users and interactions are persisted exclusively to `skinsyntax_cf_dev`.
- **Runtime Isolation**: Neither Item-kNN nor Matrix Factorization is wired into `HomeController`, `SanPhamController`, or production recommendation endpoints.
- **Production Recommendation**: Uses Simple Weighted Rating + Adaptive Content-Based + Profile + Hybrid + Explainability (gợi ý dựa trên hồ sơ da, không cam kết hay đưa ra tuyên bố y khoa).

## File Structure
- `generate_synthetic_dataset.php`: Generates structured synthetic user-item interactions based on latent preferences (NO demographic stereotypes) with Pareto/power-law sampling and an e-commerce funnel (view -> cart -> purchase -> rating).
- `evaluate_cf.php`: Offline ranking evaluation engine using Leave-One-Out Temporal Per-User Holdout across all unseen catalog items, computing HitRate@K, Precision@K, Recall@K, NDCG@K, MRR@K ($K \in \{5, 10\}$).
- `run_cf_experiment.php`: Main orchestration pipeline executing multi-seed analysis (seeds 42, 123, 2026), dataset scale ablation (100, 300, 500 users), and cold-start evaluation.
- `audit_generator_and_popularity.php`: Forensic audit of generator scoring, exact score decomposition, popularity concentration (Gini coefficient), and kNN neighborhood sparsity diagnostics.
- `run_segmentation_and_recovery.php`: User activity length segmentation, popularity-bias segmentation, preference-strength segmentation, and preference recovery diagnostics (CategoryAffinity@10, BrandAffinity@10, PriceRangeMatch@10).
- `run_true_sparsity_experiment.php`: Controlled user history length experiment holding user count constant at 500 and varying history depth (SPARSE, MEDIUM, DENSE).
- `run_latent_recovery_experiment.php`: Controlled scenario `cf_latent_recovery_v1` evaluating Funk MF against planted collaborative latent vectors across 3 seeds.

## Output Artifacts
### Phase C Baseline Artifacts (`output/`)
- `config.json`: Generator hyperparameters and environment configuration.
- `dataset_stats.json`: Statistical breakdown of the synthetic dataset.
- `metrics_seed_42.json`, `metrics_seed_123.json`, `metrics_seed_2026.json`: Raw offline evaluation metrics per seed.
- `multi_seed_evaluation.json`: Aggregated mean metrics across all deterministic seeds.
- `sparsity_experiment.json`: Historical Dataset Scale Experiment (100, 300, 500 users).
- `cold_start_analysis.json`: Cold-start behavioral audit.

### Phase C.1 Scientific Validation Artifacts (`output/validation/`)
- `generator_audit.json`: Mathematical scoring audit, parameter influence, and 1,000-decision decomposition.
- `popularity_concentration.json`: Concentration shares (Top 1%, 5%, 10%, 20%) and Gini coefficient ($G = 0.7543$).
- `history_segment_metrics.json`: Performance broken down by train history length (5-8, 9-12, 13+ items).
- `popularity_segment_metrics.json`: Performance broken down by popularity bias (low, medium, high).
- `preference_recovery.json`: Synthetic diagnostic metrics measuring recovery of preferred categories, brands, and price tiers.
- `true_sparsity_experiment.json`: True sparsity / history-length ablation at $N=500$ users.
- `latent_recovery_experiment.json`: Latent collaborative structure ablation ($w_{\text{latent}} \in \{0.0, 0.25, 0.50\}$).
- `evaluation_sanity_samples.json`: 20-user audit proving target candidate presence and zero train leakage.
- `knn_diagnostics.json`: Catalog neighborhood distribution and zero-neighbor item breakdown.

## Baseline Multi-Seed Results (Preserved)
Mean across deterministic seeds 42, 123, 2026:
- **MOST_POPULAR**: Mean HitRate@10 = 0.0469, Mean NDCG@10 = 0.0217
- **ITEM_KNN**: Mean HitRate@10 = 0.0076, Mean NDCG@10 = 0.0040
- **MATRIX_FACTORIZATION**: Mean HitRate@10 = 0.0166, Mean NDCG@10 = 0.0074

## Mandatory Methodological Note
Synthetic-data evaluation demonstrates whether the implemented Collaborative Filtering pipeline can recover preference structures under controlled assumptions. It does not establish recommendation quality for real SkinSyntaxVN users.
