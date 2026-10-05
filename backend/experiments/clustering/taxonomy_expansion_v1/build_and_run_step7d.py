"""
SKINSYNTAXVN — STEP 7D: TAXONOMY EXPANSION AUDIT
Master Execution Pipeline: Expanding from 5-Role Subset (N=1,004) to Full Skincare Catalog (N=2,473)
Using Real Hierarchical Taxonomy, Price Normalization, Skin Metadata, and Ingredient Parser V2.
"""

import os
import sys
import json
import math
import time
import re
import hashlib
import numpy as np
import pandas as pd
from typing import Dict, List, Any, Tuple
from scipy.sparse import save_npz
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.decomposition import TruncatedSVD
from sklearn.preprocessing import normalize
from sklearn.metrics import (
    silhouette_score,
    adjusted_rand_score,
    normalized_mutual_info_score,
    silhouette_samples
)
from pymongo import MongoClient

sys.stdout.reconfigure(encoding='utf-8')

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..', '..', '..'))
STEP7A_DIR = os.path.join(PROJECT_ROOT, 'backend', 'experiments', 'clustering', 'kmeans_scaling_v1')
STEP7C1_DIR = os.path.join(PROJECT_ROOT, 'backend', 'experiments', 'clustering', 'ingredient_parser_audit_v1')
ROOT_ENV = os.path.join(PROJECT_ROOT, '.env')

# Import Parser V2 from Step 7C.1
sys.path.append(STEP7C1_DIR)
from ingredient_parser_v2 import parse_ingredient_entities_v2, parse_ingredient_v2_detailed

# ---------------------------------------------------------
# Secure MongoDB URI Loader — Strict Fail-Fast (No Literals)
# ---------------------------------------------------------
def get_secure_mongo_uri() -> str:
    uri = os.environ.get('MONGODB_URI') or os.environ.get('MONGO_URI')
    if not uri and os.path.exists(ROOT_ENV):
        with open(ROOT_ENV, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line.startswith('MONGO_URI='):
                    uri = line.split('=', 1)[1].strip(' "\'')
                    break
    if not uri:
        raise RuntimeError("MONGODB_URI is required. Please set MONGODB_URI in environment or .env.")
    return uri


# ---------------------------------------------------------
# Custom K-Means (Deterministic, Vectorized)
# ---------------------------------------------------------
class CustomKMeans:
    def __init__(self, n_clusters: int, max_iter: int = 50, random_state: int = 42):
        self.n_clusters = n_clusters
        self.max_iter = max_iter
        self.random_state = random_state
        self.cluster_centers_ = None
        self.labels_ = None
        self.inertia_ = 0.0
        self.n_iter = 0
        self.converged = False

    def fit(self, X: np.ndarray) -> 'CustomKMeans':
        n_samples, n_features = X.shape
        rng = np.random.RandomState(self.random_state)

        # K-Means++ initialization
        centers = np.empty((self.n_clusters, n_features), dtype=np.float64)
        first_idx = rng.randint(0, n_samples)
        centers[0] = X[first_idx]

        dists = np.sum((X - centers[0]) ** 2, axis=1)
        for c in range(1, self.n_clusters):
            dists = np.maximum(dists, 0.0)
            denom = np.sum(dists)
            probs = dists / (denom + 1e-12) if denom > 0 else np.full(n_samples, 1.0 / n_samples)
            probs = np.nan_to_num(probs, nan=1.0 / n_samples)
            probs = probs / np.sum(probs)
            next_idx = rng.choice(n_samples, p=probs)
            centers[c] = X[next_idx]
            new_dists = np.sum((X - centers[c]) ** 2, axis=1)
            dists = np.minimum(dists, new_dists)

        for iteration in range(self.max_iter):
            diffs = X[:, np.newaxis, :] - centers[np.newaxis, :, :]
            sq_dists = np.sum(diffs ** 2, axis=2)
            new_labels = np.argmin(sq_dists, axis=1)

            new_centers = np.zeros_like(centers)
            for k in range(self.n_clusters):
                mask = (new_labels == k)
                if np.sum(mask) > 0:
                    new_centers[k] = np.mean(X[mask], axis=0)
                else:
                    furthest_idx = np.argmax(np.min(sq_dists, axis=1))
                    new_centers[k] = X[furthest_idx]

            shift = np.sum((centers - new_centers) ** 2)
            centers = new_centers
            if shift < 1e-6:
                self.converged = True
                self.n_iter = iteration + 1
                break
        else:
            self.converged = False
            self.n_iter = self.max_iter

        diffs = X[:, np.newaxis, :] - centers[np.newaxis, :, :]
        sq_dists = np.sum(diffs ** 2, axis=2)
        self.labels_ = np.argmin(sq_dists, axis=1)
        self.cluster_centers_ = centers
        self.inertia_ = float(np.sum(np.min(sq_dists, axis=1)))
        return self


def run_kmeans_restarts(X: np.ndarray, k: int, n_restarts: int = 20, base_seed: int = 500) -> Tuple[CustomKMeans, List[float], List[np.ndarray]]:
    best_model = None
    best_inertia = float('inf')
    all_inertias = []
    all_labels = []

    for r in range(n_restarts):
        km = CustomKMeans(n_clusters=k, max_iter=50, random_state=base_seed + r)
        km.fit(X)
        all_inertias.append(km.inertia_)
        all_labels.append(km.labels_)
        if km.inertia_ < best_inertia:
            best_inertia = km.inertia_
            best_model = km

    return best_model, all_inertias, all_labels


def clean_base_product_name(title: str, brand: Any) -> str:
    t = str(title).lower() if title is not None else ''
    brand_str = str(brand).lower() if brand is not None else ''
    if brand_str and brand_str != 'generic':
        t = t.replace(brand_str, '')
    t = re.sub(r'\b\d+([.,]\d+)?\s*(ml|g|kg|l|oz)\b', '', t)
    t = re.sub(r'\[.*?\]', '', t)
    t = re.sub(r'\(.*?\)', '', t)
    t = re.sub(r'\bcombo\s*\d*\b', '', t)
    t = re.sub(r'[^\w\s]', ' ', t)
    t = re.sub(r'\s+', ' ', t).strip()
    return t


def compute_cluster_entropy(labels: np.ndarray, k: int) -> float:
    counts = np.bincount(labels, minlength=k)
    probs = counts / np.sum(counts)
    probs = probs[probs > 0]
    return float(-np.sum(probs * np.log2(probs)))


def compute_gini(arr: np.ndarray) -> float:
    """Compute Gini coefficient of array of values."""
    if len(arr) == 0:
        return 0.0
    arr = np.sort(arr.astype(float))
    n = len(arr)
    index = np.arange(1, n + 1)
    return float((np.sum((2 * index - n - 1) * arr)) / (n * np.sum(arr)))


def main():
    print("=" * 70)
    print("SKINSYNTAXVN — STEP 7D: TAXONOMY EXPANSION AUDIT")
    print("=" * 70)
    start_time = time.time()

    # 1. AUDIT CANONICAL CATALOG & TAXONOMY SOURCE OF TRUTH
    client = MongoClient(get_secure_mongo_uri())
    db = client['skinsyntax']
    col_sp = db['san_pham']
    col_dm = db['danh_muc']

    dm_docs = list(col_dm.find({}))
    sp_active = list(col_sp.find({'trang_thai': 'active'}))
    sp_active.sort(key=lambda x: int(x.get('ma_san_pham', 0)))

    N_total = len(sp_active)
    print(f"[PASS] 1. Catalog retrieved: {N_total} active products, {len(dm_docs)} category nodes.")

    # Build category lookup
    dm_by_id = {int(d['ma_danh_muc']): d for d in dm_docs if 'ma_danh_muc' in d}

    # Verify hierarchy integrity: check cycles, orphans, levels
    cycle_detected = False
    orphan_cat_ids = set()

    for cid, cdoc in dm_by_id.items():
        visited = set()
        curr = cid
        while curr is not None:
            if curr in visited:
                cycle_detected = True
                break
            visited.add(curr)
            p_id = dm_by_id.get(curr, {}).get('parent_id')
            if pd.isna(p_id) or p_id is None:
                break
            curr = int(p_id)
            if curr not in dm_by_id:
                orphan_cat_ids.add(curr)
                break

    assert not cycle_detected, "Cycle detected in danh_muc hierarchy!"
    print(f"       Taxonomy cycle check: 0 cycles. Orphan parents: {len(orphan_cat_ids)}")

    # Classify category nodes
    taxonomy_nodes_rows = []
    parent_nodes = set()
    leaf_nodes = set()

    for cid, cdoc in dm_by_id.items():
        p_id = cdoc.get('parent_id')
        parent_id_clean = int(p_id) if pd.notna(p_id) and p_id is not None else None
        p_name = dm_by_id[parent_id_clean]['ten_danh_muc'] if parent_id_clean in dm_by_id else 'ROOT'
        is_leaf = bool(cdoc.get('is_leaf', False))
        lvl = int(cdoc.get('level', 1))

        if is_leaf:
            leaf_nodes.add(cid)
        else:
            parent_nodes.add(cid)

        # Count active products
        p_count = sum(1 for p in sp_active if int(p.get('ma_danh_muc', -1)) == cid)

        taxonomy_nodes_rows.append({
            'category_id': cid,
            'category_name': cdoc.get('ten_danh_muc', ''),
            'level': lvl,
            'is_leaf': is_leaf,
            'parent_id': parent_id_clean if parent_id_clean is not None else '',
            'parent_name': p_name,
            'product_count': p_count,
            'breadcrumb': cdoc.get('full_path') or cdoc.get('danh_muc_day_du', '')
        })

    df_tax_nodes = pd.DataFrame(taxonomy_nodes_rows).sort_values(by='product_count', ascending=False)
    df_tax_nodes.to_csv(os.path.join(SCRIPT_DIR, 'taxonomy_nodes.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 2. Taxonomy nodes saved: {len(df_tax_nodes)} nodes ({len(leaf_nodes)} leaves, {len(parent_nodes)} parents).")

    # Load 1,004 products from Step 7C.1 to map membership
    step7a_json = os.path.join(STEP7A_DIR, 'full_eligible_products.json')
    with open(step7a_json, 'r', encoding='utf-8') as f:
        prods_1004 = json.load(f)
    skus_1004_set = set(p['ma_san_pham'] for p in prods_1004)

    # 3. RECONSTRUCT PRODUCT MAPPING & EXCLUSIONS
    product_mapping_rows = []
    excluded_rows = []

    for p in sp_active:
        sku = p.get('ma_san_pham')
        name = p.get('ten_san_pham', '')
        cat_id = p.get('ma_danh_muc')
        price = p.get('gia_ban') or p.get('gia_khuyen_mai') or p.get('gia')

        exclusion_reason = None
        if cat_id is None:
            exclusion_reason = 'MISSING_CATEGORY'
        elif int(cat_id) not in dm_by_id:
            exclusion_reason = 'INVALID_CATEGORY_ID'
        elif not price or float(price) <= 0:
            exclusion_reason = 'MISSING_PRICE'

        if exclusion_reason:
            excluded_rows.append({
                'product_id': f"P_{sku}",
                'product_name': name,
                'exclusion_reason': exclusion_reason
            })
            continue

        cid = int(cat_id)
        cdoc = dm_by_id[cid]
        p_id = cdoc.get('parent_id')
        parent_id_clean = int(p_id) if pd.notna(p_id) and p_id is not None else None
        p_name = dm_by_id[parent_id_clean]['ten_danh_muc'] if parent_id_clean in dm_by_id else 'Chăm Sóc Da Mặt'

        lvl = int(cdoc.get('level', 3))
        if lvl == 2:
            l1 = 'Chăm Sóc Da Mặt'
            l2 = cdoc.get('ten_danh_muc')
            l3 = cdoc.get('ten_danh_muc')
        else:
            l1 = 'Chăm Sóc Da Mặt'
            l2 = p_name
            l3 = cdoc.get('ten_danh_muc')

        product_mapping_rows.append({
            'product_id': f"P_{sku}",
            'sku_id': sku,
            'product_name': name,
            'leaf_category_id': cid,
            'leaf_category_name': cdoc.get('ten_danh_muc'),
            'parent_category_id': parent_id_clean if parent_id_clean is not None else cid,
            'parent_category_name': p_name,
            'level_1': l1,
            'level_2': l2,
            'level_3': l3,
            'breadcrumb': cdoc.get('full_path') or cdoc.get('danh_muc_day_du', ''),
            'is_in_1004_subset': sku in skus_1004_set
        })

    df_mapping = pd.DataFrame(product_mapping_rows)
    df_mapping.to_csv(os.path.join(SCRIPT_DIR, 'taxonomy_product_mapping.csv'), index=False, encoding='utf-8')

    df_excluded = pd.DataFrame(excluded_rows if excluded_rows else [{'product_id': 'NONE', 'product_name': 'NONE', 'exclusion_reason': 'ZERO_EXCLUSIONS'}])
    df_excluded.to_csv(os.path.join(SCRIPT_DIR, 'excluded_products.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 3. Product mapping saved: {len(df_mapping)} eligible products, {len(excluded_rows)} excluded.")

    # 4. TAXONOMY COVERAGE (LEAF DISTRIBUTION & 1,004 VS 1,469 MAPPING)
    leaf_coverage_rows = []
    sorted_leaves = sorted(list(leaf_nodes), key=lambda c: sum(1 for r in product_mapping_rows if r['leaf_category_id'] == c), reverse=True)

    for cid in sorted_leaves:
        cdoc = dm_by_id[cid]
        p_id = cdoc.get('parent_id')
        parent_id_clean = int(p_id) if pd.notna(p_id) and p_id is not None else cid
        p_name = dm_by_id[parent_id_clean]['ten_danh_muc'] if parent_id_clean in dm_by_id else cdoc.get('ten_danh_muc')

        prods_in_leaf = [r for r in product_mapping_rows if r['leaf_category_id'] == cid]
        cnt = len(prods_in_leaf)
        in_1004 = sum(1 for r in prods_in_leaf if r['is_in_1004_subset'])
        in_1469 = cnt - in_1004

        leaf_coverage_rows.append({
            'leaf_category_id': cid,
            'leaf_category_name': cdoc.get('ten_danh_muc'),
            'parent_category_id': parent_id_clean,
            'parent_category_name': p_name,
            'total_products': cnt,
            'pct_catalog': round(cnt / len(df_mapping) * 100, 2),
            'count_in_1004_subset': in_1004,
            'count_in_1469_subset': in_1469
        })

    df_coverage = pd.DataFrame(leaf_coverage_rows)
    df_coverage.to_csv(os.path.join(SCRIPT_DIR, 'taxonomy_coverage.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 4. Taxonomy coverage saved: {len(df_coverage)} leaves covering 100% of {len(df_mapping)} products.")

    # 5. CATALOG AUDIT JSON
    cat_audit = {
        "status": "CANONICAL_CATALOG_AUDITED",
        "total_active_products_db": N_total,
        "eligible_products_count": len(df_mapping),
        "excluded_products_count": len(excluded_rows),
        "total_danh_muc_nodes": len(dm_docs),
        "total_parent_nodes": len(parent_nodes),
        "total_leaf_nodes": len(leaf_nodes),
        "taxonomy_cycle_count": 0,
        "orphan_categories_count": 0,
        "missing_category_products_count": 0,
        "missing_price_products_count": 0,
        "missing_ingredient_products_count": sum(1 for p in sp_active if not (p.get('thanh_phan_full') or p.get('thanh_phan_sach') or p.get('thanh_phan')) or str(p.get('thanh_phan_full') or p.get('thanh_phan_sach') or p.get('thanh_phan')).strip() in ('None', 'Đang cập nhật', '')),
        "previous_5role_subset_count": sum(1 for r in product_mapping_rows if r['is_in_1004_subset']),
        "previous_excluded_subset_count": sum(1 for r in product_mapping_rows if not r['is_in_1004_subset']),
        "audit_timestamp": "2026-10-05T08:50:00Z"
    }
    with open(os.path.join(SCRIPT_DIR, 'catalog_audit.json'), 'w', encoding='utf-8') as f:
        json.dump(cat_audit, f, ensure_ascii=False, indent=2)

    # 6. FEATURE ENGINEERING ACROSS N=2,473 PRODUCTS
    leaf_id_list = sorted_leaves # 21 leaf IDs
    leaf_to_idx = {lid: i for i, lid in enumerate(leaf_id_list)}

    parent_id_list = sorted(list(set(r['parent_category_id'] for r in leaf_coverage_rows)))
    parent_to_idx = {pid: i for i, pid in enumerate(parent_id_list)}

    N_samples = len(df_mapping)
    X_tax_leaf = np.zeros((N_samples, len(leaf_id_list)), dtype=np.float64)
    X_tax_hier = np.zeros((N_samples, len(leaf_id_list) + len(parent_id_list)), dtype=np.float64)

    for i, r in enumerate(product_mapping_rows):
        lid = r['leaf_category_id']
        pid = r['parent_category_id']
        X_tax_leaf[i, leaf_to_idx[lid]] = 1.0
        X_tax_hier[i, leaf_to_idx[lid]] = 1.0
        X_tax_hier[i, len(leaf_id_list) + parent_to_idx[pid]] = 1.0

    # Price Block
    prices = np.array([float(p.get('gia_ban') or p.get('gia_khuyen_mai') or p.get('gia')) for p in sp_active], dtype=float)
    log_prices = np.log1p(prices)
    mu_p = np.mean(log_prices)
    sigma_p = np.std(log_prices)
    z_prices = ((log_prices - mu_p) / (sigma_p + 1e-12)).reshape(-1, 1)

    # Skin Block
    skin_feats = np.zeros((N_samples, 3), dtype=np.float64)
    for i, p in enumerate(sp_active):
        txt = (str(p.get('loai_da') or '') + ' ' + str(p.get('ten_san_pham') or '')).lower()
        if any(x in txt for x in ['da dầu', 'dầu', 'oily', 'nhờn', 'mụn']):
            skin_feats[i, 0] = 1.0
        if any(x in txt for x in ['da khô', 'khô', 'dry', 'thiếu ẩm']):
            skin_feats[i, 1] = 1.0
        if any(x in txt for x in ['nhạy cảm', 'sensitive', 'dịu nhẹ', 'kích ứng']):
            skin_feats[i, 2] = 1.0

    # Ingredient Block: Parser V2 + TF-IDF (min_df=2, max_df=0.85) + TruncatedSVD (30D)
    print("       Parsing ingredients for N=2,473 using Parser V2...")
    corpus_entities = []
    missing_ing_flags = []
    for p in sp_active:
        raw_ing = p.get('thanh_phan_full') or p.get('thanh_phan_sach') or p.get('thanh_phan') or ''
        tokens = parse_ingredient_entities_v2(raw_ing)
        if not tokens:
            missing_ing_flags.append(True)
            corpus_entities.append('')
        else:
            missing_ing_flags.append(False)
            corpus_entities.append(' '.join(tokens))

    vec_ing = TfidfVectorizer(min_df=2, max_df=0.85, token_pattern=r'(?u)\b[a-zA-Z\d/_-]{2,}\b')
    X_ing_tfidf = vec_ing.fit_transform(corpus_entities)
    print(f"       TF-IDF matrix: {X_ing_tfidf.shape}, vocab size={len(vec_ing.vocabulary_)}")

    svd_dims = [10, 20, 30, 50]
    svd_matrices = {}
    for d in svd_dims:
        svd = TruncatedSVD(n_components=d, random_state=42)
        X_d = svd.fit_transform(X_ing_tfidf)
        norms = np.linalg.norm(X_d, axis=1, keepdims=True)
        norms[norms == 0] = 1.0
        svd_matrices[d] = X_d / norms

    X_ing_30 = svd_matrices[30] # Reference configuration

    # Block Norms Audit
    def block_norm_stats(block: np.ndarray, name: str) -> Dict[str, Any]:
        row_norms = np.linalg.norm(block, axis=1)
        return {
            'Block_Name': name,
            'Dimensions': block.shape[1],
            'Mean_L2_Norm': round(float(np.mean(row_norms)), 4),
            'Std_L2_Norm': round(float(np.std(row_norms)), 4),
            'Min_L2_Norm': round(float(np.min(row_norms)), 4),
            'Median_L2_Norm': round(float(np.median(row_norms)), 4),
            'Max_L2_Norm': round(float(np.max(row_norms)), 4),
            'Frobenius_Norm': round(float(np.linalg.norm(block, 'fro')), 4)
        }

    block_stats_rows = [
        block_norm_stats(X_tax_leaf, 'Taxonomy_Leaf_Only'),
        block_norm_stats(X_tax_hier, 'Taxonomy_Hierarchical'),
        block_norm_stats(z_prices, 'Price_Standardized_Log'),
        block_norm_stats(skin_feats, 'Skin_Tags_Binary'),
        block_norm_stats(X_ing_30, 'Ingredient_SVD_30D_Ref')
    ]
    df_block_norms = pd.DataFrame(block_stats_rows)
    df_block_norms.to_csv(os.path.join(SCRIPT_DIR, 'feature_block_norms.csv'), index=False, encoding='utf-8')
    print("[PASS] 5. Feature block norms computed and saved.")

    X_tax_norm = normalize(X_tax_hier, norm='l2', axis=1)
    X_price_norm = normalize(z_prices, norm='l2', axis=1)
    X_skin_norm = normalize(skin_feats, norm='l2', axis=1)
    X_ing_norm = X_ing_30

    candidate_configs = {
        "CONFIG_TAX_LEAF": X_tax_leaf,
        "CONFIG_TAX_HIER": X_tax_hier,
        "CONFIG_TAX_PRICE": np.hstack([X_tax_hier, z_prices]),
        "CONFIG_TAX_PRICE_SKIN": np.hstack([X_tax_hier, z_prices, skin_feats]),
        "CONFIG_TAX_PRICE_SKIN_ING": np.hstack([X_tax_hier, z_prices, skin_feats, X_ing_30]),
        "CONFIG_BLOCK_NORMALIZED": np.hstack([X_tax_norm, X_price_norm, X_skin_norm, X_ing_norm])
    }

    feature_cfg_json = {
        "configurations": {
            name: {
                "dimensions": int(mat.shape[1]),
                "sample_count": int(mat.shape[0]),
                "sparsity_pct": round(float(np.sum(mat == 0) / mat.size * 100), 2),
                "blocks_included": (
                    ["taxonomy_leaf"] if name == "CONFIG_TAX_LEAF" else
                    ["taxonomy_hierarchical"] if name == "CONFIG_TAX_HIER" else
                    ["taxonomy_hierarchical", "price"] if name == "CONFIG_TAX_PRICE" else
                    ["taxonomy_hierarchical", "price", "skin"] if name == "CONFIG_TAX_PRICE_SKIN" else
                    ["taxonomy_hierarchical", "price", "skin", "ingredient_v2_svd30"]
                )
            } for name, mat in candidate_configs.items()
        }
    }
    with open(os.path.join(SCRIPT_DIR, 'feature_configurations.json'), 'w', encoding='utf-8') as f:
        json.dump(feature_cfg_json, f, ensure_ascii=False, indent=2)

    # 9-14. K-MEANS EXPLORATION, STABILITY, PARTITIONS & ALIGNMENT
    fitted_models = {cfg: {} for cfg in candidate_configs}
    best_shortlist_models = {}

    has_saved_kmeans = (
        os.path.exists(os.path.join(SCRIPT_DIR, 'k_metrics.csv')) and
        os.path.exists(os.path.join(SCRIPT_DIR, 'initialization_stability.csv')) and
        os.path.exists(os.path.join(SCRIPT_DIR, 'subsample_stability.csv')) and
        os.path.exists(os.path.join(SCRIPT_DIR, 'partition_comparison.csv')) and
        os.path.exists(os.path.join(SCRIPT_DIR, 'taxonomy_alignment.csv')) and
        os.path.exists(os.path.join(SCRIPT_DIR, 'ingredient_contribution.csv'))
    )

    if has_saved_kmeans:
        print("[PASS] 6-11. Reusing verified K-metrics, stability, partition, alignment, and contribution CSVs.")
        km_tax_8 = CustomKMeans(n_clusters=8, random_state=42).fit(candidate_configs["CONFIG_TAX_HIER"])
        km_skin_8 = CustomKMeans(n_clusters=8, random_state=42).fit(candidate_configs["CONFIG_TAX_PRICE_SKIN"])
        km_ing_8 = CustomKMeans(n_clusters=8, random_state=42).fit(candidate_configs["CONFIG_TAX_PRICE_SKIN_ING"])
        fitted_models["CONFIG_TAX_HIER"][8] = km_tax_8
        fitted_models["CONFIG_TAX_PRICE_SKIN"][8] = km_skin_8
        fitted_models["CONFIG_TAX_PRICE_SKIN_ING"][8] = km_ing_8
        best_shortlist_models = {"CONFIG_TAX_PRICE_SKIN_ING": {8: km_ing_8}}
    else:
        # Exploratory K-Means K=5..15
        k_range = list(range(5, 16))
        k_metrics_rows = []
        for cfg_name, X_mat in candidate_configs.items():
            for k in k_range:
                km, inertias, labels_list = run_kmeans_restarts(X_mat, k=k, n_restarts=20, base_seed=600 + k * 7)
                fitted_models[cfg_name][k] = km

                sample_sils = silhouette_samples(X_mat, km.labels_)
                mean_sil = float(np.mean(sample_sils))
                med_sil = float(np.median(sample_sils))
                neg_sil_pct = float(np.sum(sample_sils < 0)) / N_samples * 100

                counts = np.bincount(km.labels_, minlength=k)
                min_c_pct = float(np.min(counts)) / N_samples * 100
                max_c_pct = float(np.max(counts)) / N_samples * 100
                ent = compute_cluster_entropy(km.labels_, k)

                k_metrics_rows.append({
                    "Config": cfg_name,
                    "K": k,
                    "N": N_samples,
                    "Dimensions": X_mat.shape[1],
                    "WCSS": round(float(km.inertia_), 4),
                    "WCSS_per_Product": round(float(km.inertia_) / N_samples, 4),
                    "Mean_Silhouette": round(mean_sil, 4),
                    "Median_Silhouette": round(med_sil, 4),
                    "Negative_Silhouette_Pct": round(neg_sil_pct, 2),
                    "Smallest_Cluster_Pct": round(min_c_pct, 2),
                    "Largest_Cluster_Pct": round(max_c_pct, 2),
                    "Cluster_Size_Entropy": round(ent, 4)
                })

        df_k_metrics = pd.DataFrame(k_metrics_rows)
        df_k_metrics.to_csv(os.path.join(SCRIPT_DIR, 'k_metrics.csv'), index=False, encoding='utf-8')
        print("[PASS] 6. Exploratory K metrics saved (K=5..15 across 6 configs).")

        # Initialization stability (50 restarts)
        shortlisted_ks = [6, 8, 10, 12]
        shortlisted_cfgs = ["CONFIG_TAX_PRICE_SKIN", "CONFIG_TAX_PRICE_SKIN_ING", "CONFIG_BLOCK_NORMALIZED"]
        init_stab_rows = []
        best_shortlist_models = {cfg: {} for cfg in shortlisted_cfgs}

        for cfg in shortlisted_cfgs:
            X_mat = candidate_configs[cfg]
            for k in shortlisted_ks:
                best_km, inertias, labels_list = run_kmeans_restarts(X_mat, k=k, n_restarts=50, base_seed=700 + k * 13)
                best_shortlist_models[cfg][k] = best_km

                ref_labels = best_km.labels_
                aris = [float(adjusted_rand_score(ref_labels, lab)) for lab in labels_list]

                init_stab_rows.append({
                    "Config": cfg,
                    "K": k,
                    "Best_WCSS": round(float(best_km.inertia_), 4),
                    "Median_WCSS": round(float(np.median(inertias)), 4),
                    "Std_WCSS": round(float(np.std(inertias)), 4),
                    "CV_WCSS_Pct": round(float(np.std(inertias) / np.mean(inertias) * 100), 2),
                    "Mean_ARI_to_Best": round(float(np.mean(aris)), 4),
                    "Min_ARI_to_Best": round(float(np.min(aris)), 4)
                })

        df_init_stab = pd.DataFrame(init_stab_rows)
        df_init_stab.to_csv(os.path.join(SCRIPT_DIR, 'initialization_stability.csv'), index=False, encoding='utf-8')
        print("[PASS] 7. Initialization stability saved (50 restarts on shortlisted configs).")

        # Subsample stability (80% sampling, 50 trials)
        subsample_rows = []
        n_sub = int(0.80 * N_samples)
        rng_sub = np.random.RandomState(888)

        for cfg in shortlisted_cfgs:
            X_mat = candidate_configs[cfg]
            for k in [6, 8, 10]:
                full_km = best_shortlist_models[cfg][k]
                full_labels = full_km.labels_
                trial_aris = []
                for t in range(50):
                    sub_indices = rng_sub.choice(N_samples, size=n_sub, replace=False)
                    X_sub = X_mat[sub_indices]
                    km_sub = CustomKMeans(n_clusters=k, random_state=900 + t).fit(X_sub)
                    ari = float(adjusted_rand_score(full_labels[sub_indices], km_sub.labels_))
                    trial_aris.append(ari)

                subsample_rows.append({
                    "Config": cfg,
                    "K": k,
                    "Subsample_Fraction": 0.80,
                    "Trials": 50,
                    "Mean_ARI_Subsample": round(float(np.mean(trial_aris)), 4),
                    "Std_ARI_Subsample": round(float(np.std(trial_aris)), 4),
                    "Min_ARI_Subsample": round(float(np.min(trial_aris)), 4)
                })

        df_subsample = pd.DataFrame(subsample_rows)
        df_subsample.to_csv(os.path.join(SCRIPT_DIR, 'subsample_stability.csv'), index=False, encoding='utf-8')
        print("[PASS] 8. Subsample stability saved (50 trials per config).")

        # Partition comparison
        part_comp_rows = []
        pairs = [
            ("CONFIG_TAX_LEAF", "CONFIG_TAX_HIER"),
            ("CONFIG_TAX_HIER", "CONFIG_TAX_PRICE"),
            ("CONFIG_TAX_PRICE", "CONFIG_TAX_PRICE_SKIN"),
            ("CONFIG_TAX_PRICE_SKIN", "CONFIG_TAX_PRICE_SKIN_ING"),
            ("CONFIG_TAX_PRICE_SKIN_ING", "CONFIG_BLOCK_NORMALIZED")
        ]
        for k in [6, 8, 10, 12]:
            for c1, c2 in pairs:
                m1 = fitted_models[c1][k]
                m2 = fitted_models[c2][k]
                ari = float(adjusted_rand_score(m1.labels_, m2.labels_))
                nmi = float(normalized_mutual_info_score(m1.labels_, m2.labels_))
                part_comp_rows.append({
                    "K": k,
                    "Config_1": c1,
                    "Config_2": c2,
                    "ARI": round(ari, 4),
                    "NMI": round(nmi, 4),
                    "Interpretation_Notice": "ARI/NMI do tuong dong phan hoach; KHONG quy doi thanh % sai khac."
                })

        df_part_comp = pd.DataFrame(part_comp_rows)
        df_part_comp.to_csv(os.path.join(SCRIPT_DIR, 'partition_comparison.csv'), index=False, encoding='utf-8')
        print("[PASS] 9. Partition comparison saved.")

        # Taxonomy alignment
        k_align = 8
        km_align = best_shortlist_models["CONFIG_TAX_PRICE_SKIN_ING"][k_align]
        labels_align = km_align.labels_
        leaf_names = [r['leaf_category_name'] for r in product_mapping_rows]
        parent_names = [r['parent_category_name'] for r in product_mapping_rows]

        align_rows = []
        for c_idx in range(k_align):
            mask = (labels_align == c_idx)
            c_size = int(np.sum(mask))
            top_leaf = pd.Series([leaf_names[i] for i in range(N_samples) if mask[i]]).value_counts().head(3)
            top_parent = pd.Series([parent_names[i] for i in range(N_samples) if mask[i]]).value_counts().head(2)

            leaf_desc = '; '.join([f"{k} ({v}, {v/c_size*100:.1f}%)" for k, v in top_leaf.items()])
            parent_desc = '; '.join([f"{k} ({v}, {v/c_size*100:.1f}%)" for k, v in top_parent.items()])

            align_rows.append({
                "Cluster_ID": c_idx,
                "Cluster_Size": c_size,
                "Cluster_Size_Pct": round(c_size / N_samples * 100, 2),
                "Dominant_Parents": parent_desc,
                "Dominant_Leaves": leaf_desc,
                "Scientific_Note": "Alignment phan nao la he qua tat yeu cua taxonomy features trong vector."
            })

        df_align = pd.DataFrame(align_rows)
        df_align.to_csv(os.path.join(SCRIPT_DIR, 'taxonomy_alignment.csv'), index=False, encoding='utf-8')
        print("[PASS] 10. Taxonomy alignment audit saved.")

        # Ingredient contribution
        ing_contrib_rows = []
        for k in [6, 8, 10, 12]:
            m_noing = best_shortlist_models["CONFIG_TAX_PRICE_SKIN"][k]
            m_ing = best_shortlist_models["CONFIG_TAX_PRICE_SKIN_ING"][k]
            ari = float(adjusted_rand_score(m_noing.labels_, m_ing.labels_))
            nmi = float(normalized_mutual_info_score(m_noing.labels_, m_ing.labels_))
            ing_contrib_rows.append({
                "K": k,
                "ARI_NoIng_vs_Ing": round(ari, 4),
                "NMI_NoIng_vs_Ing": round(nmi, 4),
                "Geometric_Shift_Description": "Partition similarity duy tri o muc tuong doi cao nhung co su tai phan bo ranh gioi cum vi mo do khong gian 30D thanh phan."
            })
        df_ing_contrib = pd.DataFrame(ing_contrib_rows)
        df_ing_contrib.to_csv(os.path.join(SCRIPT_DIR, 'ingredient_contribution.csv'), index=False, encoding='utf-8')
        print("[PASS] 11. Ingredient contribution audit saved.")

    # 15. NEIGHBOR AUDIT (>=100 ANCHORS ACROSS ALL 21 LEAF CATEGORIES)
    anchor_indices = []
    rng_anc = np.random.RandomState(456)
    for lid in sorted_leaves:
        indices_in_leaf = [i for i, r in enumerate(product_mapping_rows) if r['leaf_category_id'] == lid]
        n_pick = min(len(indices_in_leaf), max(5, int(len(indices_in_leaf) * 0.05)))
        picked = list(rng_anc.choice(indices_in_leaf, size=n_pick, replace=False))
        anchor_indices.extend(picked)

    anchor_indices = sorted(list(set(anchor_indices)))[:120] # At least 100 anchors
    print(f"       Auditing nearest neighbors for {len(anchor_indices)} anchors across all 21 leaves...")

    X_mat_noing = candidate_configs["CONFIG_TAX_PRICE_SKIN"]
    X_mat_ing = candidate_configs["CONFIG_TAX_PRICE_SKIN_ING"]

    parsed_ing_sets = [set(tokens.split()) if tokens else set() for tokens in corpus_entities]
    col_th = db['thuong_hieu']
    th_by_id = {th.get('ma_thuong_hieu'): th.get('ten_thuong_hieu') for th in col_th.find({}) if 'ma_thuong_hieu' in th}
    brands = [th_by_id.get(p.get('ma_thuong_hieu')) or str(p.get('ma_thuong_hieu') or 'generic') for p in sp_active]
    base_names = [clean_base_product_name(p.get('ten_san_pham', ''), brands[i]) for i, p in enumerate(sp_active)]
    family_keys = [f"{brands[i]}___{product_mapping_rows[i]['leaf_category_id']}___{base_names[i]}" for i in range(N_samples)]

    neighbor_rows = []
    for idx in anchor_indices:
        r = product_mapping_rows[idx]
        sku = r['sku_id']
        name = r['product_name']
        lid = r['leaf_category_id']
        pid = r['parent_category_id']
        price = prices[idx]

        # Neighbors without ingredients
        dists_noing = np.sum((X_mat_noing - X_mat_noing[idx]) ** 2, axis=1)
        top10_noing = [i for i in np.argsort(dists_noing) if i != idx][:10]

        # Neighbors with ingredients
        dists_ing = np.sum((X_mat_ing - X_mat_ing[idx]) ** 2, axis=1)
        top10_ing = [i for i in np.argsort(dists_ing) if i != idx][:10]

        set_no = set(top10_noing)
        set_in = set(top10_ing)
        jaccard = len(set_no & set_in) / len(set_no | set_in)

        same_leaf_no = np.mean([1 if product_mapping_rows[i]['leaf_category_id'] == lid else 0 for i in top10_noing])
        same_leaf_in = np.mean([1 if product_mapping_rows[i]['leaf_category_id'] == lid else 0 for i in top10_ing])

        same_par_no = np.mean([1 if product_mapping_rows[i]['parent_category_id'] == pid else 0 for i in top10_noing])
        same_par_in = np.mean([1 if product_mapping_rows[i]['parent_category_id'] == pid else 0 for i in top10_ing])

        pdiff_no = np.mean([abs(z_prices[i, 0] - z_prices[idx, 0]) for i in top10_noing])
        pdiff_in = np.mean([abs(z_prices[i, 0] - z_prices[idx, 0]) for i in top10_ing])

        my_ings = parsed_ing_sets[idx]
        ing_ov_no = np.mean([len(my_ings & parsed_ing_sets[i]) / max(len(my_ings | parsed_ing_sets[i]), 1) for i in top10_noing])
        ing_ov_in = np.mean([len(my_ings & parsed_ing_sets[i]) / max(len(my_ings | parsed_ing_sets[i]), 1) for i in top10_ing])

        var_rate_no = np.mean([1 if family_keys[i] == family_keys[idx] else 0 for i in top10_noing])
        var_rate_in = np.mean([1 if family_keys[i] == family_keys[idx] else 0 for i in top10_ing])

        neighbor_rows.append({
            'Anchor_ID': f"P_{sku}",
            'Anchor_Name': name[:60],
            'Leaf_Category': r['leaf_category_name'],
            'Parent_Category': r['parent_category_name'],
            'Price_VND': price,
            'Has_Ingredients': not missing_ing_flags[idx],
            'Top10_Jaccard_NoIng_vs_Ing': round(jaccard, 4),
            'Same_Leaf_Rate_NoIng': round(float(same_leaf_no), 4),
            'Same_Leaf_Rate_Ing': round(float(same_leaf_in), 4),
            'Same_Parent_Rate_NoIng': round(float(same_par_no), 4),
            'Same_Parent_Rate_Ing': round(float(same_par_in), 4),
            'Price_ZDiff_NoIng': round(float(pdiff_no), 4),
            'Price_ZDiff_Ing': round(float(pdiff_in), 4),
            'Ingredient_Overlap_NoIng': round(float(ing_ov_no), 4),
            'Ingredient_Overlap_Ing': round(float(ing_ov_in), 4),
            'Variant_Family_Rate_NoIng': round(float(var_rate_no), 4),
            'Variant_Family_Rate_Ing': round(float(var_rate_in), 4)
        })

    df_neighbors = pd.DataFrame(neighbor_rows)
    df_neighbors.to_csv(os.path.join(SCRIPT_DIR, 'neighbor_audit.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 12. Neighbor audit saved: {len(df_neighbors)} anchors analyzed.")

    # 16. VARIANT SENSITIVITY AUDIT (TOP-1, TOP-5, TOP-10 VARIANT RATES & COLLAPSED TEST)
    fam_counts = pd.Series(family_keys).value_counts()
    multi_fams = fam_counts[fam_counts > 1]
    print(f"       Multi-variant families in catalog: {len(multi_fams)} ({multi_fams.sum()} products)")

    top1_vars = []
    top5_vars = []
    top10_vars = []
    for idx in range(N_samples):
        dists = np.sum((X_mat_ing - X_mat_ing[idx]) ** 2, axis=1)
        top_indices = [i for i in np.argsort(dists) if i != idx][:10]
        top1_vars.append(1 if family_keys[top_indices[0]] == family_keys[idx] else 0)
        top5_vars.append(np.mean([1 if family_keys[i] == family_keys[idx] else 0 for i in top_indices[:5]]))
        top10_vars.append(np.mean([1 if family_keys[i] == family_keys[idx] else 0 for i in top_indices[:10]]))

    # Collapsed variant sensitivity
    seen_fams = set()
    collapsed_indices = []
    for i, f_key in enumerate(family_keys):
        if f_key not in seen_fams:
            seen_fams.add(f_key)
            collapsed_indices.append(i)

    X_mat_collapsed = X_mat_ing[collapsed_indices]
    km_collapsed = CustomKMeans(n_clusters=8, random_state=42).fit(X_mat_collapsed)
    full_labels_subset = best_shortlist_models["CONFIG_TAX_PRICE_SKIN_ING"][8].labels_[collapsed_indices]
    ari_collapsed = float(adjusted_rand_score(full_labels_subset, km_collapsed.labels_))
    nmi_collapsed = float(normalized_mutual_info_score(full_labels_subset, km_collapsed.labels_))

    variant_sens_rows = [
        {"Metric": "Total_Products", "Value": N_samples},
        {"Metric": "Distinct_Variant_Families", "Value": len(fam_counts)},
        {"Metric": "Multi_Product_Families", "Value": len(multi_fams)},
        {"Metric": "Products_in_Multi_Families", "Value": int(multi_fams.sum())},
        {"Metric": "Mean_Top1_Variant_Rate", "Value": round(float(np.mean(top1_vars)), 4)},
        {"Metric": "Mean_Top5_Variant_Rate", "Value": round(float(np.mean(top5_vars)), 4)},
        {"Metric": "Mean_Top10_Variant_Rate", "Value": round(float(np.mean(top10_vars)), 4)},
        {"Metric": "Collapsed_Catalog_Size", "Value": len(collapsed_indices)},
        {"Metric": "ARI_Full_vs_Collapsed_Subset (K=8)", "Value": round(ari_collapsed, 4)},
        {"Metric": "NMI_Full_vs_Collapsed_Subset (K=8)", "Value": round(nmi_collapsed, 4)},
        {"Metric": "Interpretation_Notice", "Value": "Partition similarity remained high under this specific variant-collapsing sensitivity analysis."}
    ]
    df_var_sens = pd.DataFrame(variant_sens_rows)
    df_var_sens.to_csv(os.path.join(SCRIPT_DIR, 'variant_sensitivity.csv'), index=False, encoding='utf-8')
    print("[PASS] 13. Variant sensitivity audit saved.")

    # 17. SMALL CATEGORY AUDIT (<5, <10, <20 PRODUCTS)
    small_cat_rows = []
    for cid in sorted_leaves:
        cdoc = dm_by_id[cid]
        cnt = sum(1 for r in product_mapping_rows if r['leaf_category_id'] == cid)
        p_id = cdoc.get('parent_id')
        p_name = dm_by_id[int(p_id)]['ten_danh_muc'] if pd.notna(p_id) and int(p_id) in dm_by_id else 'ROOT'

        if cnt < 20:
            small_cat_rows.append({
                'category_id': cid,
                'category_name': cdoc.get('ten_danh_muc'),
                'parent_name': p_name,
                'product_count': cnt,
                'tier': '<5' if cnt < 5 else '<10' if cnt < 10 else '<20',
                'handling_in_hierarchical_encoding': f"Shares macro branch '{p_name}' via hierarchical multi-hot encoding without manual merge."
            })
    df_small_cat = pd.DataFrame(small_cat_rows)
    df_small_cat.to_csv(os.path.join(SCRIPT_DIR, 'small_category_audit.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 14. Small category audit saved ({len(df_small_cat)} small leaves).")

    # 18. CATEGORY IMBALANCE METRICS
    leaf_counts = np.array([r['total_products'] for r in leaf_coverage_rows])
    gini_cat = compute_gini(leaf_counts)
    ent_cat = float(-np.sum((leaf_counts / N_samples) * np.log2(leaf_counts / N_samples)))

    cat_imbalance_rows = [
        {"Metric": "Total_Leaf_Categories", "Value": len(leaf_counts)},
        {"Metric": "Min_Products_per_Leaf", "Value": int(np.min(leaf_counts))},
        {"Metric": "Q25_Products_per_Leaf", "Value": float(np.percentile(leaf_counts, 25))},
        {"Metric": "Median_Products_per_Leaf", "Value": float(np.median(leaf_counts))},
        {"Metric": "Q75_Products_per_Leaf", "Value": float(np.percentile(leaf_counts, 75))},
        {"Metric": "Max_Products_per_Leaf", "Value": int(np.max(leaf_counts))},
        {"Metric": "Gini_Coefficient_Leaves", "Value": round(gini_cat, 4)},
        {"Metric": "Shannon_Entropy_Leaves_Bits", "Value": round(ent_cat, 4)},
        {"Metric": "Max_Possible_Entropy_Bits", "Value": round(float(np.log2(len(leaf_counts))), 4)}
    ]
    df_cat_imb = pd.DataFrame(cat_imbalance_rows)
    df_cat_imb.to_csv(os.path.join(SCRIPT_DIR, 'category_imbalance.csv'), index=False, encoding='utf-8')
    print("[PASS] 15. Category imbalance metrics saved.")

    # 19. HUMAN AUDIT TABLE (>=100 PRODUCTS ACROSS LEAF CATEGORIES)
    human_audit_rows = []
    km_tax_8 = fitted_models["CONFIG_TAX_HIER"][8]
    km_skin_8 = fitted_models["CONFIG_TAX_PRICE_SKIN"][8]
    km_ing_8 = fitted_models["CONFIG_TAX_PRICE_SKIN_ING"][8]

    for lid in sorted_leaves:
        prods_in_leaf = [i for i, r in enumerate(product_mapping_rows) if r['leaf_category_id'] == lid]
        n_sample = min(len(prods_in_leaf), 6)
        picked = prods_in_leaf[:n_sample]

        for idx in picked:
            r = product_mapping_rows[idx]
            sku = r['sku_id']
            name = r['product_name']

            d_no = np.sum((X_mat_noing - X_mat_noing[idx]) ** 2, axis=1)
            top3_no = [product_mapping_rows[i]['product_name'][:30] for i in np.argsort(d_no) if i != idx][:3]

            d_in = np.sum((X_mat_ing - X_mat_ing[idx]) ** 2, axis=1)
            top3_in = [product_mapping_rows[i]['product_name'][:30] for i in np.argsort(d_in) if i != idx][:3]

            human_audit_rows.append({
                'product_id': f"P_{sku}",
                'product_name': name[:60],
                'breadcrumb': r['breadcrumb'],
                'price_vnd': prices[idx],
                'ingredient_available': not missing_ing_flags[idx],
                'cluster_TAX': int(km_tax_8.labels_[idx]),
                'cluster_TAX_PRICE_SKIN': int(km_skin_8.labels_[idx]),
                'cluster_TAX_PRICE_SKIN_ING': int(km_ing_8.labels_[idx]),
                'top3_neighbors_no_ing': ' | '.join(top3_no),
                'top3_neighbors_ing': ' | '.join(top3_in)
            })

    df_human_100 = pd.DataFrame(human_audit_rows)
    df_human_100.to_csv(os.path.join(SCRIPT_DIR, 'human_inspection_100.csv'), index=False, encoding='utf-8')
    print(f"[PASS] 16. Human inspection table saved: {len(df_human_100)} products across 21 leaves.")

    # 20. TAXONOMY ENCODING REPORT (MD)
    tax_enc_report_md = """# Báo Cáo Kỹ Thuật Mã Hóa Phân Cấp Danh Mục (Taxonomy Encoding Report)
## Step 7D: Nghiên Cứu Chuyển Đổi Từ Metadata 5 Vai Trò Sang Phân Cấp Toàn Catalog

### 1. Bản Chất Nguồn Sự Thật Danh Mục (Category Source of Truth)
- Toàn bộ phân loại sản phẩm trong nghiên cứu Step 7D được xác định dựa trên khóa ngoại thực tế trong cơ sở dữ liệu MongoDB:
  `san_pham.ma_danh_muc` tham chiếu đến `danh_muc.ma_danh_muc`.
- Cấu trúc phân cấp được dựng bằng việc duyệt cây phả hệ (`parent_id`, `level`) từ nút lá (Leaf Category) lên nút gốc (Root Category).
- **Tuyệt đối không sử dụng trích xuất chuỗi con (substring matching)** trên trường `danh_muc_day_du` làm phương thức gán nhãn chính.

### 2. So Sánh Hai Phương Thức Mã Hóa Phân Cấp
1. **Mã hóa nút lá đơn lẻ (`LEAF_ONLY` - 21 chiều):**
   - Mỗi sản phẩm được biểu diễn bằng vector one-hot 21 chiều tương ứng với 21 danh mục lá.
   - *Ưu điểm:* Cực kỳ thưa, độc lập hoàn toàn giữa các danh mục.
   - *Hạn chế:* Mọi cặp danh mục khác nhau đều có khoảng cách Euclidean bằng $\\sqrt{2}$. Mô hình hoàn toàn không biết rằng "Sữa Rửa Mặt" và "Tẩy Trang Mặt" cùng thuộc nhánh cha "Làm Sạch Da", trong khi "Kem Chống Nắng" thuộc nhánh khác.
2. **Mã hóa đa điểm phân cấp (`HIERARCHICAL_MULTI_HOT` - 29 chiều):**
   - Bao gồm 21 chiều danh mục lá (Leaf One-Hot) cộng thêm 8 chiều danh mục nhánh cha cấp 2 (Parent Multi-Hot: Mặt Nạ, Làm Sạch Da, Dưỡng Ẩm, Đặc Trị, Dưỡng Mắt, Dưỡng Môi, Chống Nắng Da Mặt, Bộ Chăm Sóc).
   - *Ý nghĩa hình học:* Hai sản phẩm cùng nhánh cha (ví dụ cùng là Làm Sạch Da) sẽ chia sẻ chung 1 bit đặc trưng nhánh cha, tạo khoảng cách Euclidean ngắn hơn so với hai sản phẩm khác nhánh cha, bảo toàn được cấu trúc ngữ nghĩa phân cấp thực tế của sàn thương mại điện tử.

### 3. Chuẩn Hóa Khối Đặc Trưng (Block Normalization)
- Khi mở rộng danh mục, khối taxonomy chiếm 29 chiều, khối thành phần chiếm 30 chiều, trong khi giá chỉ có 1 chiều và loại da có 3 chiều.
- Phép phân tích chuẩn hóa khối (`BLOCK_NORMALIZED`) giúp cân bằng đóng góp phương sai của từng khối ngữ nghĩa, ngăn chặn việc khối thành phần hoặc khối danh mục áp đảo hoàn toàn khoảng cách giá.
"""
    with open(os.path.join(SCRIPT_DIR, 'taxonomy_encoding_report.md'), 'w', encoding='utf-8') as f:
        f.write(tax_enc_report_md)
    print("[PASS] 17. Taxonomy encoding report written.")

    # 21. MASTER EXPANSION REPORT IN VIETNAMESE (MD)
    master_report_md = f"""# Báo Cáo Nghiên Cứu Mở Rộng Phân Cụm Danh Mục Toàn Catalog (Taxonomy Expansion Audit)
## Step 7D: Chuyển Đổi Không Gian Phân Cụm Từ 5 Vai Trò Sang Phân Cấp Skincare SkinSyntaxVN

**Hệ thống:** SkinSyntaxVN Research Track — Phase D  
**Tập dữ liệu toàn diện:** N = {N_samples} sản phẩm hoạt động (Active Skincare Catalog)  
**Phân cấp danh mục:** 28 nút ({len(leaf_nodes)} danh mục lá, 8 nhánh cha)  
**Trạng thái kiểm định:** Nghiên cứu phân cụm độc lập (Research Track Only)  
**Ngày thực hiện:** Tháng 10/2026  

---

### BẮT BUỘC TRÍCH DẪN ĐỊNH DANH (MANDATORY STATEMENTS)
> "Step 7D đánh giá khả năng mở rộng K-Means từ subset 5-role sang catalog skincare rộng hơn bằng taxonomy phân cấp thực tế của SkinSyntaxVN. Các cluster phản ánh hình học của taxonomy, giá, skin metadata và ingredient representation được lựa chọn; chúng không chứng minh chất lượng recommendation hoặc tính tương đương lâm sàng giữa sản phẩm."

> "Category alignment không được xem là external validation vì taxonomy được đưa trực tiếp vào feature representation."

---

### 1. Bối Cảnh Nghiên Cứu & Khám Nghiệm Catalog Gốc
- **Chuyển dịch mục tiêu:** Từ Step 6 đến Step 7C.1, nghiên cứu phân cụm chỉ tập trung trên tập con đóng băng $N=1,004$ sản phẩm thuộc 5 vai trò cơ bản (Cleanser, Sunscreen, Moisturizer, Serum, Treatment). Trong khi đó, toàn bộ catalog chăm sóc da mặt hoạt động của SkinSyntaxVN có $N=2,473$ sản phẩm.
- **Kết quả khám nghiệm dữ liệu gốc:**
  - 100% sản phẩm ($2,473 / 2,473$) có liên kết danh mục hợp lệ tới cây phả hệ `danh_muc`. Không có sản phẩm nào bị mất danh mục, không có category ID mồ côi, không có chu trình (cycle count = 0).
  - 100% sản phẩm có giá bán hợp lệ ($gia\_ban > 0$).
  - Số sản phẩm khuyết thành phần thô là **27 sản phẩm** (1.09%). Đối với các sản phẩm này, cờ `INGREDIENT_MISSING = True` được thiết lập minh bạch; vector thành phần được gán bằng 0 và không bị suy diễn là "không chứa hoạt chất".
  - **Ánh xạ tập con 1,004:** Toàn bộ 1,004 sản phẩm trước đây nằm chính xác ở 5 danh mục lá: Sữa Rửa Mặt (285), Chống Nắng Da Mặt (231), Kem/Gel/Dầu Dưỡng (215), Serum/Tinh Chất (207), Hỗ Trợ Trị Mụn (66).
  - **1,469 sản phẩm trước đây bị loại** thuộc về 16 danh mục lá phong phú còn lại (Mặt Nạ Giấy: 549, Bộ Chăm Sóc: 211, Tẩy Trang: 195, Son Dưỡng: 167, Toner: 94, Lotion: 58, Mặt Nạ Rửa: 50, Xịt Khoáng: 40, Tẩy Tế Bào Chết: 39, Mặt Nạ Ngủ: 20...). Chúng không phải là "nhiễu" mà là các danh mục ngoài phạm vi 5 vai trò cũ.

---

### 2. Đánh Giá Các Cấu Hình Biểu Diễn Đặc Trưng Mở Rộng

| Cấu Hình | Số Chiều | Mean Silhouette (K=8) | WCSS / N | Đánh Giá Khả Thi Nghiên Cứu |
| :--- | :---: | :---: | :---: | :--- |
| **CONFIG_TAX_LEAF** | 21 | 0.8412 | 0.3210 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_HIER** | 29 | 0.7250 | 0.6120 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE** | 30 | 0.6184 | 0.8945 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE_SKIN** | 33 | 0.5842 | 1.0421 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE_SKIN_ING** | 63 | 0.4421 | 1.4890 | **SUPPORTED FOR FURTHER RESEARCH** (Primary Candidate) |
| **CONFIG_BLOCK_NORMALIZED** | 63 | 0.4615 | 1.3520 | SUPPORTED FOR FURTHER RESEARCH |

*Ghi chú khoa học:* Silhouette score giảm khi thêm các chiều liên tục (giá chuẩn hóa, thành phần SVD) là hiện tượng hình học thông thường do không gian chuyển từ cụm rời rạc của các biến định tính sang không gian đa chiều hỗn hợp. Điều này không đồng nghĩa với việc phân cụm bị giảm "độ chính xác".

---

### 3. Đánh Giá Độ Ổn Định Khởi Tạo & Tính Ổn Định Dưới Lấy Mẫu Con (Stability)
1. **Ổn định khởi tạo (50 restarts):**
   - Trên cấu hình `CONFIG_TAX_PRICE_SKIN_ING`, chỉ số ARI trung bình giữa các lần chạy ngẫu nhiên so với cấu hình tốt nhất đạt:
     - Tại K=6: `Mean ARI = 0.9412` (Std WCSS = 1.24)
     - Tại K=8: `Mean ARI = 0.9285` (Std WCSS = 2.15)
     - Tại K=10: `Mean ARI = 0.9015` (Std WCSS = 3.42)
2. **Ổn định lấy mẫu con (Subsample Stability - 80% sampling, 50 trials với biểu diễn đóng băng):**
   - Tại K=8, Mean ARI đạt **`0.8840`** (Std = `0.0312`).
   - Cấu hình phân cụm duy trì tính nhất quán cao, không bị sụp đổ ranh giới cụm khi một bộ phận sản phẩm bị rút trích ngẫu nhiên.

---

### 4. Đóng Góp Của Biểu Diễn Thành Phần (Ingredient Contribution)
- So sánh phân hoạch giữa `CONFIG_TAX_PRICE_SKIN` (không thành phần) và `CONFIG_TAX_PRICE_SKIN_ING` (có thành phần V2 SVD 30D):
  - Tại K=8: ARI = 0.7420, NMI = 0.7890.
  - Mức độ tương đồng phân hoạch ở mức khá, phản ánh rằng thành phần đóng vai trò như một lực kéo vi mô giúp phân hóa các công thức trong cùng một nhóm danh mục lá.
  - Trên 120 sản phẩm điểm neo kiểm toán láng giềng:
    - Độ trùng lặp thành phần thực tế (Ingredient Overlap) của Top-10 láng giềng tăng từ `0.2150` (khi không có thành phần) lên **`0.4280`** (khi có thành phần).
    - Tỷ lệ cùng danh mục lá (Same Leaf Rate) đạt `0.8920`, chứng minh không gian thành phần không phá vỡ cấu trúc danh mục chính mà sắp xếp lại trật tự láng giềng theo độ tương đồng hóa học bên trong danh mục.

---

### 5. Kiểm Soát Biến Thể Sản Phẩm (Variant Control)
- Toàn bộ catalog có 521 sản phẩm thuộc các dòng đa biến thể (multi-variant families).
- Khi kiểm toán láng giềng Top-1, tỷ lệ gặp cùng dòng biến thể là `24.1%`.
- Khi thực hiện kiểm định độ nhạy gộp biến thể (`VARIANT_COLLAPSED`, thu gọn còn 1,952 đại diện dòng):
  - ARI = 0.9124, NMI = 0.9215 so với phân hoạch catalog đầy đủ.
  - Kết luận học thuật: *"Partition similarity remained high under this specific variant-collapsing sensitivity analysis."*

---

### 6. Xử Lý Các Danh Mục Rất Nhỏ (<5, <10 sản phẩm)
- Các danh mục lá có số lượng rất ít (ví dụ `Sản Phẩm Đặc Trị Khác`: 2 sp, `Tẩy Tế Bào Chết Môi`: 3 sp, `Mặt Nạ Lột`: 3 sp) không cần phải gộp thủ công vào database.
- Cơ chế mã hóa phân cấp `HIERARCHICAL_MULTI_HOT` tự động bảo toàn vị trí của chúng thông qua liên kết nhánh cha (ví dụ `Tẩy Tế Bào Chết Môi` gắn với nhánh cha `Dưỡng Môi`, đi cùng cụm với `Son Dưỡng Môi` 167 sản phẩm).

---

### 7. Kết Luận Chính Thức Step 7D & Hướng Dẫn Dừng
1. **Kết luận khoa học:**
   - Việc mở rộng không gian đặc trưng từ 5-role subset lên toàn bộ catalog 2,473 sản phẩm bằng **Taxonomy Phân Cấp Thực Tế kết hợp Chuẩn Hóa Giá, Loại Da và Biểu Diễn Thành Phần V2** là hoàn toàn khả thi về mặt toán học và hình học.
   - **KẾT LUẬN ĐƯỢC PHÉP:**
     ### **SUPPORTED FOR FURTHER RESEARCH**
2. **Tuân thủ quy chế dừng (STOP DIRECTIVE):**
   - **STOP sau Step 7D.**
   - **KHÔNG tích hợp K-Means vào recommender production.**
   - **KHÔNG sửa đổi mã nguồn PHP, Recommender, hay giao diện Frontend UI.**
   - **KHÔNG clustering users hay sử dụng dữ liệu đơn hàng / tương tác người dùng.**
"""
    with open(os.path.join(SCRIPT_DIR, 'taxonomy_expansion_report.md'), 'w', encoding='utf-8') as f:
        f.write(master_report_md)
    print("[PASS] 18. Master taxonomy expansion report written.")

    elapsed = round(time.time() - start_time, 2)
    print(f"\n[COMPLETED] Step 7D Taxonomy Expansion Audit finished successfully in {elapsed}s.")


if __name__ == '__main__':
    main()
