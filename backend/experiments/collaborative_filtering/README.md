# Phase C: Isolated Synthetic Collaborative Filtering Experiment

## Overview
This directory contains an isolated, reproducible research and evaluation pipeline for Collaborative Filtering (Item-Based kNN and Regularized Biased Matrix Factorization via SGD) on SkinSyntaxVN.

## Safety & Isolation Boundary
- **Production Database Integrity**: Production collections (`skinsyntax.tuong_tac_nguoi_dung`, `skinsyntax.hoa_don`, `skinsyntax.chi_tiet_hoa_don`, `skinsyntax.danh_gia`, `skinsyntax.san_pham`, `skinsyntax.nguoi_dung`) are **NEVER** modified. All reads from the production catalog are strictly READ-ONLY.
- **Experimental Database**: Synthetic users and interactions are persisted exclusively to `skinsyntax_cf_dev`.
- **Runtime Isolation**: Neither Item-kNN nor Matrix Factorization is wired into `HomeController`, `SanPhamController`, or production recommendation endpoints.

## File Structure
- `generate_synthetic_dataset.php`: Generates structured synthetic user-item interactions based on latent preferences (NO demographic stereotypes) with Pareto/power-law sampling and an e-commerce funnel (view -> cart -> purchase -> rating).
- `evaluate_cf.php`: Offline ranking evaluation engine using Leave-One-Out Temporal Per-User Holdout across all unseen catalog items, computing HitRate@K, Precision@K, Recall@K, NDCG@K, MRR@K ($K \in \{5, 10\}$).
- `run_cf_experiment.php`: Main orchestration pipeline executing multi-seed analysis (seeds 42, 123, 2026), data sparsity ablation (100, 300, 500 users), and cold-start evaluation.
- `output/`:
  - `config.json`: Generator hyperparameters and environment configuration.
  - `dataset_stats.json`: Statistical breakdown of the synthetic dataset.
  - `metrics_seed_42.json`, `metrics_seed_123.json`, `metrics_seed_2026.json`: Raw offline evaluation metrics per seed.
  - `multi_seed_evaluation.json`: Aggregated mean metrics across all deterministic seeds.
  - `sparsity_experiment.json`: Results across varying interaction densities (Dataset S, M, L).
  - `cold_start_analysis.json`: Cold-start behavioral audit.

## Reproducing the Experiment
Inside the docker container:
```bash
docker exec skinsyntax-php php /var/www/html/experiments/collaborative_filtering/run_cf_experiment.php
```

## Mandatory Methodological Note
Synthetic-data evaluation demonstrates whether the implemented Collaborative Filtering pipeline can recover preference structures under controlled assumptions. It does not establish recommendation quality for real SkinSyntaxVN users.
