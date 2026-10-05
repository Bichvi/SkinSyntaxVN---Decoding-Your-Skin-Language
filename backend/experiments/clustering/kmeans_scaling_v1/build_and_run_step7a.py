"""
Step 7A: Progressive K-Means Scaling Experiment (40 -> 80 -> 200 -> FULL).
Under the fixed 9-dimensional SkinSyntaxVN metadata feature space.
"""

import os
import sys
import json
import math
import time
import hashlib
import numpy as np
import pandas as pd
from typing import Dict, List, Any, Tuple
from pymongo import MongoClient
from sklearn.metrics import (
    silhouette_score,
    adjusted_rand_score,
    normalized_mutual_info_score
)
from scipy.optimize import linear_sum_assignment

sys.stdout.reconfigure(encoding='utf-8')

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP6B_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'kmeans_k_selection_v1'))
STEP6C_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'kmeans_k_decision_v1'))
STEP6A_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'kmeans_micro_v1'))


# ---------------------------------------------------------
# 1. Custom First-Principles K-Means Class
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
        self.empty_cluster_events = 0

    def fit(self, X: np.ndarray) -> 'CustomKMeans':
        n_samples, n_features = X.shape
        rng = np.random.RandomState(self.random_state)
        
        init_indices = rng.choice(n_samples, size=self.n_clusters, replace=False)
        centroids = X[init_indices].copy()
        prev_labels = None

        for it in range(1, self.max_iter + 1):
            self.n_iter = it
            dists = np.zeros((n_samples, self.n_clusters), dtype=float)
            for k in range(self.n_clusters):
                diff = X - centroids[k]
                dists[:, k] = np.sqrt(np.sum(diff ** 2, axis=1))

            labels = np.argmin(dists, axis=1)

            new_centroids = np.zeros((self.n_clusters, n_features), dtype=float)
            for k in range(self.n_clusters):
                cluster_pts = X[labels == k]
                if len(cluster_pts) > 0:
                    new_centroids[k] = np.mean(cluster_pts, axis=0)
                else:
                    self.empty_cluster_events += 1
                    current_assigned_dists = np.array([dists[i, labels[i]] for i in range(n_samples)])
                    furthest_idx = np.argmax(current_assigned_dists)
                    new_centroids[k] = X[furthest_idx].copy()
                    labels[furthest_idx] = k

            if prev_labels is not None and np.array_equal(labels, prev_labels):
                self.converged = True
                break

            prev_labels = labels.copy()
            centroids = new_centroids.copy()

        self.cluster_centers_ = centroids
        self.labels_ = labels

        wcss = 0.0
        for i in range(n_samples):
            wcss += np.sum((X[i] - self.cluster_centers_[self.labels_[i]]) ** 2)
        self.inertia_ = float(wcss)
        return self


# ---------------------------------------------------------
# 2. Custom Silhouette Score Computation
# ---------------------------------------------------------
def compute_silhouette_metrics(X: np.ndarray, labels: np.ndarray) -> Tuple[float, float, int, np.ndarray]:
    n_samples = X.shape[0]
    unique_labels = np.unique(labels)
    k = len(unique_labels)

    if k <= 1 or k >= n_samples:
        return 0.0, 0.0, 0, np.zeros(n_samples)

    # Use sklearn for fast pairwise silhouette on large N
    s_scores = np.zeros(n_samples, dtype=float)
    # Pairwise distances
    from sklearn.metrics import silhouette_samples
    s_scores = silhouette_samples(X, labels)

    mean_s = float(np.mean(s_scores))
    median_s = float(np.median(s_scores))
    neg_count = int(np.sum(s_scores < 0))
    return mean_s, median_s, neg_count, s_scores


# ---------------------------------------------------------
# 3. Hungarian Matching for Cluster Alignment
# ---------------------------------------------------------
def align_clusters(base_labels: np.ndarray, target_labels: np.ndarray, k_val: int) -> np.ndarray:
    """
    Align target_labels to base_labels using the Hungarian algorithm on overlap contingency.
    """
    contingency = np.zeros((k_val, k_val), dtype=int)
    for b, t in zip(base_labels, target_labels):
        contingency[b, t] += 1
    # Cost matrix is negative contingency
    row_ind, col_ind = linear_sum_assignment(-contingency)
    # mapping from target cluster -> base cluster
    mapping = {col: row for row, col in zip(row_ind, col_ind)}
    aligned_labels = np.array([mapping.get(t, t) for t in target_labels])
    return aligned_labels


# ---------------------------------------------------------
# 4. Skin Tag Parser
# ---------------------------------------------------------
def parse_skin_tags(loai_da_text: str) -> Tuple[Dict[str, int], bool]:
    if not loai_da_text:
        return {'oily': 0, 'dry': 0, 'sensitive': 0}, False
    t = loai_da_text.lower()
    oily = 1 if ('dầu' in t or 'mụn' in t or 'bã nhờn' in t) else 0
    dry = 1 if ('khô' in t or 'thiếu ẩm' in t) else 0
    sensitive = 1 if ('nhạy cảm' in t or 'kích ứng' in t or 'dị ứng' in t) else 0
    confident = (oily == 1 or dry == 1 or sensitive == 1 or 'mọi loại da' in t or 'da thường' in t)
    return {'oily': oily, 'dry': dry, 'sensitive': sensitive}, confident


# ---------------------------------------------------------
# 5. Main Step 7A Pipeline Execution
# ---------------------------------------------------------
def run_step7a():
    t_start_total = time.time()
    print("==================================================")
    print("STEP 7A: PROGRESSIVE K-MEANS SCALING EXPERIMENT")
    print("==================================================")

    # 1. Verify Hashes of Previous Inputs
    s40_path = os.path.join(STEP6B_DIR, 'selected_products_40.json')
    s40_scaled_path = os.path.join(STEP6B_DIR, 'scaled_features_40.csv')
    with open(s40_path, 'rb') as f:
        h_s40 = hashlib.sha256(f.read()).hexdigest()
    with open(s40_scaled_path, 'rb') as f:
        h_s40_scaled = hashlib.sha256(f.read()).hexdigest()

    expected_h_s40 = "55efc5f61d654eb74208abd4e4af2ebd3fcc8302315a1c341c49f22d3bdffd51"
    expected_h_s40_scaled = "be62d3b5b4f390faee5c880a7cf241637e0a374224c68dbff08cd870174031cf"
    assert h_s40 == expected_h_s40, "Step 6B selected_products_40.json modified!"
    assert h_s40_scaled == expected_h_s40_scaled, "Step 6B scaled_features_40.csv modified!"
    print(f"Verified Step 6B frozen inputs: SHA-256 matched successfully.")

    with open(s40_path, 'r', encoding='utf-8') as f:
        prods_s40 = json.load(f)
    skus_s40 = [p['ma_san_pham'] for p in prods_s40]

    # 2. Audit MongoDB Catalog & Build FULL Eligible Dataset
    # Load credentials safely from environment or project config without printing
    db_uri = os.environ.get('MONGODB_URI', 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB')
    client = MongoClient(db_uri)
    db = client['skinsyntax']
    col_sp = db['san_pham']
    col_th = db['thuong_hieu']

    brand_map = {th.get('ma_thuong_hieu'): th.get('ten_thuong_hieu') for th in col_th.find()}

    role_category_map = {
        'CLEANSER': 'Sữa Rửa Mặt',
        'SERUM': 'Serum / Tinh Chất',
        'MOISTURIZER': 'Kem / Gel / Dầu Dưỡng',
        'SUNSCREEN': 'Chống Nắng Da Mặt',
        'TREATMENT': 'Hỗ Trợ Trị Mụn'
    }

    full_eligible_docs = []
    excluded_counts = {}

    for doc in col_sp.find({'trang_thai': 'active'}):
        sku = doc.get('ma_san_pham')
        price = doc.get('gia_ban', 0)
        if not price or price <= 0:
            continue
        
        cat = doc.get('danh_muc_day_du', '')
        matched_role = None
        for r_name, r_suffix in role_category_map.items():
            if r_suffix in cat:
                matched_role = r_name
                break
        
        if matched_role:
            loai_da = doc.get('loai_da', '')
            tags, confident = parse_skin_tags(loai_da)
            b_name = brand_map.get(doc.get('ma_thuong_hieu'), 'Generic')
            full_eligible_docs.append({
                'ma_san_pham': sku,
                'ten_san_pham': doc.get('ten_san_pham'),
                'role': matched_role,
                'brand': b_name,
                'gia_ban': price,
                'loai_da': loai_da if loai_da else 'Mọi loại da',
                'skin_tags': tags,
                'confident': confident
            })
        else:
            leaf = cat.split('->')[-1].strip() if '->' in cat else cat
            excluded_counts[leaf] = excluded_counts.get(leaf, 0) + 1

    # Sort full eligible products by SKU for deterministic ordering
    full_eligible_docs.sort(key=lambda x: x['ma_san_pham'])
    n_full = len(full_eligible_docs)
    print(f"\nFULL Eligible Catalog: {n_full} products across 5 roles.")
    print(f"Total Excluded Products: {2473 - n_full}")

    # Ensure all S40 products are in full_eligible_docs
    full_sku_set = set(p['ma_san_pham'] for p in full_eligible_docs)
    assert set(skus_s40).issubset(full_sku_set), "S40 products not in FULL eligible set!"

    # 3. Stratified Nested Selection for M80 (N=80) and L200 (N=200)
    # S40: 8 per role (40 total)
    # M80: 16 per role (80 total) -> Add 8 per role from remaining full products
    # L200: 40 per role (200 total) -> Add 24 per role from remaining full products
    rng_sample = np.random.RandomState(42)

    prods_by_role_full = {r: [p for p in full_eligible_docs if p['role'] == r] for r in role_category_map}
    prods_by_role_s40 = {r: [p for p in prods_s40 if p['role'] == r] for r in role_category_map}

    m80_list = list(prods_s40)
    l200_list = list(prods_s40)

    for r in ['CLEANSER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN', 'TREATMENT']:
        s40_role_skus = set(p['ma_san_pham'] for p in prods_by_role_s40[r])
        remaining_role_prods = [p for p in prods_by_role_full[r] if p['ma_san_pham'] not in s40_role_skus]
        
        # Sort remaining by price for stratified spread
        remaining_role_prods.sort(key=lambda x: x['gia_ban'])
        
        # Pick 8 for M80
        # Use evenly spaced indices
        idx_m80 = np.linspace(0, len(remaining_role_prods) - 1, 8, dtype=int)
        chosen_m80_role = [remaining_role_prods[i] for i in idx_m80]
        m80_list.extend(chosen_m80_role)

        # For L200, we need 32 more from the rest (to make 40 per role total: 8 S40 + 32 = 40)
        m80_role_skus = s40_role_skus.union(set(p['ma_san_pham'] for p in chosen_m80_role))
        remaining_for_l200 = [p for p in prods_by_role_full[r] if p['ma_san_pham'] not in m80_role_skus]
        remaining_for_l200.sort(key=lambda x: x['gia_ban'])
        
        idx_l200 = np.linspace(0, len(remaining_for_l200) - 1, 24, dtype=int)
        chosen_l200_extra = [remaining_for_l200[i] for i in idx_l200]
        l200_list.extend(chosen_m80_role)
        l200_list.extend(chosen_l200_extra)

    # Sort each dataset by SKU for strict determinism
    m80_list.sort(key=lambda x: x['ma_san_pham'])
    l200_list.sort(key=lambda x: x['ma_san_pham'])

    assert len(m80_list) == 80, f"M80 size is {len(m80_list)}, expected 80"
    assert len(l200_list) == 200, f"L200 size is {len(l200_list)}, expected 200"

    # Verify nested relationship: S40 subset M80 subset L200 subset FULL
    skus_m80 = set(p['ma_san_pham'] for p in m80_list)
    skus_l200 = set(p['ma_san_pham'] for p in l200_list)
    assert set(skus_s40).issubset(skus_m80), "S40 not subset of M80!"
    assert skus_m80.issubset(skus_l200), "M80 not subset of L200!"
    assert skus_l200.issubset(full_sku_set), "L200 not subset of FULL!"
    print(f"Verified strict nested relationship: S40 (40) ⊂ M80 (80) ⊂ L200 (200) ⊂ FULL ({n_full}).")

    # Save JSON files
    with open(os.path.join(SCRIPT_DIR, 'selected_products_80.json'), 'w', encoding='utf-8') as f:
        json.dump(m80_list, f, ensure_ascii=False, indent=2)
    with open(os.path.join(SCRIPT_DIR, 'selected_products_200.json'), 'w', encoding='utf-8') as f:
        json.dump(l200_list, f, ensure_ascii=False, indent=2)
    with open(os.path.join(SCRIPT_DIR, 'full_eligible_products.json'), 'w', encoding='utf-8') as f:
        json.dump(full_eligible_docs, f, ensure_ascii=False, indent=2)
    print("Saved: selected_products_80.json, selected_products_200.json, full_eligible_products.json")

    # 4. Global Price Normalization Statistics from FULL
    log_prices_full = np.array([math.log(1.0 + p['gia_ban']) for p in full_eligible_docs], dtype=float)
    mu_full = float(np.mean(log_prices_full))
    sigma_full = float(np.sqrt(np.mean((log_prices_full - mu_full) ** 2))) # Population std dev

    global_stats = {
        'N_full': n_full,
        'price_min_vnd': int(min(p['gia_ban'] for p in full_eligible_docs)),
        'price_median_vnd': int(np.median([p['gia_ban'] for p in full_eligible_docs])),
        'price_max_vnd': int(max(p['gia_ban'] for p in full_eligible_docs)),
        'mu_full_log_price': round(mu_full, 6),
        'sigma_full_log_price_pop': round(sigma_full, 6),
        'scaling_convention': 'Population standard deviation across FULL eligible active catalog (N=1004)'
    }
    with open(os.path.join(SCRIPT_DIR, 'global_scaling_stats.json'), 'w', encoding='utf-8') as f:
        json.dump(global_stats, f, ensure_ascii=False, indent=2)
    print(f"Global Scaling Stats: mu_full = {mu_full:.4f}, sigma_full = {sigma_full:.4f}. Saved: global_scaling_stats.json")

    # 5. Build Standardized Feature Matrices across all 4 stages
    roles_order = ['CLEANSER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN', 'TREATMENT']
    feature_cols = [f"role_{r.lower()}" for r in roles_order] + ['z_price', 'skin_oily', 'skin_dry', 'skin_sensitive']

    def build_feature_dataframe(product_list):
        rows = []
        for p in product_list:
            z_p = (math.log(1.0 + p['gia_ban']) - mu_full) / sigma_full
            row = {
                'Product_ID': f"P_{p['ma_san_pham']}",
                'SKU_ID': p['ma_san_pham'],
                'Product_Name': p['ten_san_pham'],
                'Role': p['role'],
                'Brand': p['brand'],
                'gia_ban': p['gia_ban']
            }
            for r in roles_order:
                row[f"role_{r.lower()}"] = 1.0 if p['role'] == r else 0.0
            row['z_price'] = round(z_p, 4)
            row['skin_oily'] = float(p['skin_tags']['oily'])
            row['skin_dry'] = float(p['skin_tags']['dry'])
            row['skin_sensitive'] = float(p['skin_tags']['sensitive'])
            rows.append(row)
        return pd.DataFrame(rows)

    df_s40_global = build_feature_dataframe(prods_s40)
    df_m80 = build_feature_dataframe(m80_list)
    df_l200 = build_feature_dataframe(l200_list)
    df_full = build_feature_dataframe(full_eligible_docs)

    df_s40_global.to_csv(os.path.join(SCRIPT_DIR, 'features_s40_global.csv'), index=False)
    df_m80.to_csv(os.path.join(SCRIPT_DIR, 'features_m80.csv'), index=False)
    df_l200.to_csv(os.path.join(SCRIPT_DIR, 'features_l200.csv'), index=False)
    df_full.to_csv(os.path.join(SCRIPT_DIR, 'features_full.csv'), index=False)
    print("Saved feature files: features_s40_global.csv, features_m80.csv, features_l200.csv, features_full.csv")

    stages = {
        'S40_GLOBAL': (df_s40_global, 40),
        'M80': (df_m80, 80),
        'L200': (df_l200, 200),
        'FULL': (df_full, n_full)
    }

    # 6. Recompute S40 under Global Price Scaling & Compare with Original
    # Load original S40 from Step 6B
    df_s40_orig = pd.read_csv(os.path.join(STEP6B_DIR, 'scaled_features_40.csv'))
    X_s40_orig = df_s40_orig[feature_cols].values
    X_s40_global = df_s40_global[feature_cols].values

    # Find best K=6 for both
    def fit_best_kmeans(X_mat, k_val, restarts=20, base_seed=42):
        best_m = None
        best_w = float('inf')
        for r in range(restarts):
            m = CustomKMeans(n_clusters=k_val, max_iter=50, random_state=base_seed + r).fit(X_mat)
            if m.inertia_ < best_w:
                best_w = m.inertia_
                best_m = m
        return best_m

    m_orig_s40_k6 = fit_best_kmeans(X_s40_orig, 6)
    m_glob_s40_k6 = fit_best_kmeans(X_s40_global, 6)

    ari_s40_scale = adjusted_rand_score(m_orig_s40_k6.labels_, m_glob_s40_k6.labels_)
    nmi_s40_scale = normalized_mutual_info_score(m_orig_s40_k6.labels_, m_glob_s40_k6.labels_)
    print(f"\n--- S40 Scaling Impact (Sample-Scaled vs Global-Scaled): ARI = {ari_s40_scale:.4f}, NMI = {nmi_s40_scale:.4f} ---")

    # 7. Evaluate Candidate K in {4, 5, 6, 7, 8} across ALL 4 Stages
    k_range = [4, 5, 6, 7, 8]
    k_metrics_rows = []
    best_models = {} # (stage, k) -> model

    print("\n--- Evaluating K in {4, 5, 6, 7, 8} across Stages (20 Restarts each) ---")
    runtime_records = []

    for stage_name, (df_stage, stage_n) in stages.items():
        t_stage_start = time.time()
        X_stage = df_stage[feature_cols].values
        
        stage_total_iters = 0
        stage_restarts_count = 0

        for k in k_range:
            best_m = None
            best_w = float('inf')
            wcss_list = []
            
            for r in range(20):
                seed_r = 42 + r
                km = CustomKMeans(n_clusters=k, max_iter=50, random_state=seed_r).fit(X_stage)
                wcss_list.append(km.inertia_)
                stage_total_iters += km.n_iter
                stage_restarts_count += 1
                if km.inertia_ < best_w:
                    best_w = km.inertia_
                    best_m = km

            best_models[(stage_name, k)] = best_m
            
            # Silhouette
            s_mean, s_med, s_neg, s_all = compute_silhouette_metrics(X_stage, best_m.labels_)
            c_sizes = [int(np.sum(best_m.labels_ == c)) for c in range(k)]

            k_metrics_rows.append({
                'Stage': stage_name,
                'K': k,
                'N': stage_n,
                'Best_WCSS': round(best_w, 4),
                'WCSS_per_Product': round(best_w / stage_n, 4),
                'Mean_Silhouette': round(s_mean, 4),
                'Median_Silhouette': round(s_med, 4),
                'Negative_Silhouette_Count': s_neg,
                'Negative_Silhouette_Pct': round(s_neg / stage_n * 100, 2),
                'Smallest_Cluster_Size': min(c_sizes),
                'Smallest_Cluster_Pct': round(min(c_sizes) / stage_n * 100, 2),
                'Largest_Cluster_Size': max(c_sizes),
                'Largest_Cluster_Pct': round(max(c_sizes) / stage_n * 100, 2),
                'Mean_WCSS_20Runs': round(float(np.mean(wcss_list)), 4),
                'Std_WCSS_20Runs': round(float(np.std(wcss_list)), 4)
            })

            print(f"[{stage_name:10s}] K={k} | WCSS={best_w:9.2f} (PerProd: {best_w/stage_n:6.4f}) | Sil={s_mean:.4f} | Neg%={s_neg/stage_n*100:4.1f}% | Sizes={c_sizes}")

        t_stage_elapsed = time.time() - t_stage_start
        runtime_records.append({
            'Stage': stage_name,
            'N': stage_n,
            'Feature_Dimensions': 9,
            'K_Range': '4..8',
            'Restarts_per_K': 20,
            'Total_Runs': stage_restarts_count,
            'Runtime_Seconds': round(t_stage_elapsed, 2),
            'Avg_Iterations': round(stage_total_iters / stage_restarts_count, 1)
        })

    df_k_metrics = pd.DataFrame(k_metrics_rows)
    df_k_metrics.to_csv(os.path.join(SCRIPT_DIR, 'k_metrics_by_stage.csv'), index=False)
    print("Saved: k_metrics_by_stage.csv")

    df_runtime = pd.DataFrame(runtime_records)
    df_runtime.to_csv(os.path.join(SCRIPT_DIR, 'runtime_scaling.csv'), index=False)
    print("Saved: runtime_scaling.csv")

    # 8. Detailed Cluster Composition for K=6 across all Stages
    k6_comp_all = {}
    purity_rows = []
    price_structure_rows = []

    for stage_name, (df_stage, stage_n) in stages.items():
        km6 = best_models[(stage_name, 6)]
        X_stage = df_stage[feature_cols].values
        s_mean, s_med, s_neg, s_all = compute_silhouette_metrics(X_stage, km6.labels_)

        stage_clusters = []
        contingency = np.zeros((6, 5), dtype=int)

        for c in range(6):
            m_idx = np.where(km6.labels_ == c)[0]
            c_df = df_stage.iloc[m_idx]
            c_prices = c_df['gia_ban'].values
            c_zprices = c_df['z_price'].values
            c_roles = c_df['Role'].values
            c_sil = s_all[m_idx]
            c_brands = c_df['Brand'].unique().tolist()

            role_vc = pd.Series(c_roles).value_counts().to_dict()
            dom_role = max(role_vc, key=role_vc.get) if role_vc else "None"
            purity = role_vc[dom_role] / len(m_idx) if len(m_idx) > 0 else 0.0

            for r_i, r_name in enumerate(roles_order):
                contingency[c, r_i] = role_vc.get(r_name, 0)

            # Price stats
            q25, q50, q75 = np.percentile(c_prices, [25, 50, 75])
            iqr = q75 - q25

            price_structure_rows.append({
                'Stage': stage_name,
                'Cluster_ID': f"C{c+1}",
                'Size': len(m_idx),
                'Dominant_Role': dom_role,
                'Price_Median_VND': int(q50),
                'Price_IQR_VND': int(iqr),
                'Price_Min_VND': int(min(c_prices)),
                'Price_Max_VND': int(max(c_prices)),
                'Mean_z_price': round(float(np.mean(c_zprices)), 4)
            })

            stage_clusters.append({
                'cluster_index': c + 1,
                'size': len(m_idx),
                'role_distribution': role_vc,
                'dominant_role': dom_role,
                'purity': round(purity, 4),
                'brand_count': len(c_brands),
                'price_median': int(q50),
                'mean_z_price': round(float(np.mean(c_zprices)), 4),
                'mean_silhouette': round(float(np.mean(c_sil)), 4),
                'skin_oily_pct': round(float(c_df['skin_oily'].mean()) * 100, 1),
                'skin_dry_pct': round(float(c_df['skin_dry'].mean()) * 100, 1),
                'skin_sensitive_pct': round(float(c_df['skin_sensitive'].mean()) * 100, 1)
            })

        k6_comp_all[stage_name] = stage_clusters

        # Overall role purity for this stage
        overall_purity = sum(np.max(contingency[c]) for c in range(6)) / stage_n
        purity_rows.append({
            'Stage': stage_name,
            'N': stage_n,
            'Overall_Role_Purity': round(overall_purity, 4),
            'Contingency_Matrix': str(contingency.tolist())
        })

    with open(os.path.join(SCRIPT_DIR, 'k6_cluster_composition.json'), 'w', encoding='utf-8') as f:
        json.dump(k6_comp_all, f, ensure_ascii=False, indent=2)
    print("Saved: k6_cluster_composition.json")

    df_purity = pd.DataFrame(purity_rows)
    df_purity.to_csv(os.path.join(SCRIPT_DIR, 'role_purity_by_stage.csv'), index=False)
    print("Saved: role_purity_by_stage.csv")

    df_price_struct = pd.DataFrame(price_structure_rows)
    df_price_struct.to_csv(os.path.join(SCRIPT_DIR, 'price_structure_by_stage.csv'), index=False)
    print("Saved: price_structure_by_stage.csv")

    # 9. Nested-Sample Stability for Shared Products (K=6, K=5, K=7)
    # Align labels and calculate ARI/NMI on shared products
    nested_records = []
    
    for k_eval in [5, 6, 7]:
        m_s40 = best_models[('S40_GLOBAL', k_eval)]
        m_m80 = best_models[('M80', k_eval)]
        m_l200 = best_models[('L200', k_eval)]
        m_full = best_models[('FULL', k_eval)]

        # S40 vs M80 on S40 (40 items)
        idx_s40_in_m80 = [df_m80[df_m80['SKU_ID'] == sku].index[0] for sku in skus_s40]
        labels_m80_on_s40 = m_m80.labels_[idx_s40_in_m80]
        ari_40_80 = adjusted_rand_score(m_s40.labels_, labels_m80_on_s40)
        nmi_40_80 = normalized_mutual_info_score(m_s40.labels_, labels_m80_on_s40)

        # M80 vs L200 on M80 (80 items)
        skus_m80_list = list(df_m80['SKU_ID'])
        idx_m80_in_l200 = [df_l200[df_l200['SKU_ID'] == sku].index[0] for sku in skus_m80_list]
        labels_l200_on_m80 = m_l200.labels_[idx_m80_in_l200]
        ari_80_200 = adjusted_rand_score(m_m80.labels_, labels_l200_on_m80)
        nmi_80_200 = normalized_mutual_info_score(m_m80.labels_, labels_l200_on_m80)

        # L200 vs FULL on L200 (200 items)
        skus_l200_list = list(df_l200['SKU_ID'])
        idx_l200_in_full = [df_full[df_full['SKU_ID'] == sku].index[0] for sku in skus_l200_list]
        labels_full_on_l200 = m_full.labels_[idx_l200_in_full]
        ari_200_full = adjusted_rand_score(m_l200.labels_, labels_full_on_l200)
        nmi_200_full = normalized_mutual_info_score(m_l200.labels_, labels_full_on_l200)

        nested_records.append({
            'K': k_eval,
            'Comparison': 'S40 vs M80 (Restricted to S40, N=40)',
            'ARI': round(ari_40_80, 4),
            'NMI': round(nmi_40_80, 4)
        })
        nested_records.append({
            'K': k_eval,
            'Comparison': 'M80 vs L200 (Restricted to M80, N=80)',
            'ARI': round(ari_80_200, 4),
            'NMI': round(nmi_80_200, 4)
        })
        nested_records.append({
            'K': k_eval,
            'Comparison': 'L200 vs FULL (Restricted to L200, N=200)',
            'ARI': round(ari_200_full, 4),
            'NMI': round(nmi_200_full, 4)
        })

    df_nested = pd.DataFrame(nested_records)
    df_nested.to_csv(os.path.join(SCRIPT_DIR, 'nested_stability.csv'), index=False)
    print("Saved: nested_stability.csv")

    # 10. Product Transition Analysis for Original S40 Products under K=6
    # Align cluster IDs across stages using Hungarian matching to S40_GLOBAL
    m_s40_k6 = best_models[('S40_GLOBAL', 6)]
    m_m80_k6 = best_models[('M80', 6)]
    m_l200_k6 = best_models[('L200', 6)]
    m_full_k6 = best_models[('FULL', 6)]

    # Subsets
    idx_s40_in_m80 = [df_m80[df_m80['SKU_ID'] == sku].index[0] for sku in skus_s40]
    idx_s40_in_l200 = [df_l200[df_l200['SKU_ID'] == sku].index[0] for sku in skus_s40]
    idx_s40_in_full = [df_full[df_full['SKU_ID'] == sku].index[0] for sku in skus_s40]

    raw_m80_s40 = m_m80_k6.labels_[idx_s40_in_m80]
    raw_l200_s40 = m_l200_k6.labels_[idx_s40_in_l200]
    raw_full_s40 = m_full_k6.labels_[idx_s40_in_full]

    aligned_m80_s40 = align_clusters(m_s40_k6.labels_, raw_m80_s40, 6)
    aligned_l200_s40 = align_clusters(m_s40_k6.labels_, raw_l200_s40, 6)
    aligned_full_s40 = align_clusters(m_s40_k6.labels_, raw_full_s40, 6)

    transition_rows = []
    for i, p in enumerate(prods_s40):
        c_s40 = int(m_s40_k6.labels_[i]) + 1
        c_m80 = int(aligned_m80_s40[i]) + 1
        c_l200 = int(aligned_l200_s40[i]) + 1
        c_full = int(aligned_full_s40[i]) + 1
        
        # Number of unique clusters assigned across 4 stages
        unique_assigned = len(set([c_s40, c_m80, c_l200, c_full]))
        is_stable = (unique_assigned == 1)

        transition_rows.append({
            'Product_ID': f"P_{p['ma_san_pham']}",
            'SKU_ID': p['ma_san_pham'],
            'Product_Name': p['ten_san_pham'],
            'Role': p['role'],
            'Brand': p['brand'],
            'Price_VND': p['gia_ban'],
            'Cluster_S40': f"C{c_s40}",
            'Cluster_M80': f"C{c_m80}",
            'Cluster_L200': f"C{c_l200}",
            'Cluster_FULL': f"C{c_full}",
            'Distinct_Clusters_Visited': unique_assigned,
            'Trajectory_Status': 'Strictly_Stable' if is_stable else 'Boundary_Shifted'
        })

    df_transitions = pd.DataFrame(transition_rows)
    df_transitions.to_csv(os.path.join(SCRIPT_DIR, 'product_transition_s40.csv'), index=False)
    print("Saved: product_transition_s40.csv")

    # 11. Co-Cluster Persistence for Original S40 Product Pairs
    n_s40 = 40
    coclust_pairs = []
    
    for i in range(n_s40):
        for j in range(i + 1, n_s40):
            p1 = df_transitions.loc[i]
            p2 = df_transitions.loc[j]
            
            same_s40 = (m_s40_k6.labels_[i] == m_s40_k6.labels_[j])
            same_m80 = (raw_m80_s40[i] == raw_m80_s40[j])
            same_l200 = (raw_l200_s40[i] == raw_l200_s40[j])
            same_full = (raw_full_s40[i] == raw_full_s40[j])

            coclust_pairs.append({
                'Product_A': p1['Product_ID'],
                'Product_B': p2['Product_ID'],
                'Role_A': p1['Role'],
                'Role_B': p2['Role'],
                'Same_Cluster_S40': bool(same_s40),
                'Same_Cluster_M80': bool(same_m80),
                'Same_Cluster_L200': bool(same_l200),
                'Same_Cluster_FULL': bool(same_full),
                'Persistence_Stages_Count': int(same_s40) + int(same_m80) + int(same_l200) + int(same_full)
            })

    df_coclust_pairs = pd.DataFrame(coclust_pairs)
    df_coclust_pairs.to_csv(os.path.join(SCRIPT_DIR, 'cocluster_persistence.csv'), index=False)
    print("Saved: cocluster_persistence.csv")

    # 12. Feature Sensitivity by Stage for K=6
    # Config A: Role + Price (6 dims)
    # Config B: Role + Price + Skin (9 dims) [PRIMARY]
    # Config C: Price + Skin, no Role (4 dims)
    print("\n--- Running Feature Sensitivity by Stage for K=6 ---")
    cols_a = [f"role_{r.lower()}" for r in roles_order] + ['z_price']
    cols_b = feature_cols
    cols_c = ['z_price', 'skin_oily', 'skin_dry', 'skin_sensitive']

    sens_records = []
    for stage_name, (df_stage, stage_n) in stages.items():
        X_a = df_stage[cols_a].values
        X_b = df_stage[cols_b].values
        X_c = df_stage[cols_c].values

        m_a = fit_best_kmeans(X_a, 6, restarts=20)
        m_b = best_models[(stage_name, 6)]
        m_c = fit_best_kmeans(X_c, 6, restarts=20)

        ari_ab = adjusted_rand_score(m_a.labels_, m_b.labels_)
        ari_bc = adjusted_rand_score(m_b.labels_, m_c.labels_)

        sil_a = silhouette_score(X_a, m_a.labels_)
        sil_b = silhouette_score(X_b, m_b.labels_)
        sil_c = silhouette_score(X_c, m_c.labels_)

        sens_records.append({
            'Stage': stage_name,
            'N': stage_n,
            'K': 6,
            'Silhouette_ConfigA (Role+Price)': round(sil_a, 4),
            'Silhouette_ConfigB (Primary)': round(sil_b, 4),
            'Silhouette_ConfigC (Price+Skin)': round(sil_c, 4),
            'ARI_A_vs_B (Skin_Contribution)': round(ari_ab, 4),
            'ARI_B_vs_C (Role_Contribution)': round(ari_bc, 4)
        })

    df_sens_stage = pd.DataFrame(sens_records)
    df_sens_stage.to_csv(os.path.join(SCRIPT_DIR, 'feature_sensitivity_by_stage.csv'), index=False)
    print("Saved: feature_sensitivity_by_stage.csv")

    # 13. Initialization Stability across Stages for K=6 (>= 50 Restarts)
    print("\n--- Running Initialization Stability (50 Restarts for K=6 across Stages) ---")
    init_records = []
    
    for stage_name, (df_stage, stage_n) in stages.items():
        X_stage = df_stage[feature_cols].values
        best_km6 = best_models[(stage_name, 6)]
        
        wcss_50 = []
        aris_to_best = []
        partitions_50 = []

        for r in range(50):
            seed_r = 42 + r
            m_r = CustomKMeans(6, max_iter=50, random_state=seed_r).fit(X_stage)
            wcss_50.append(m_r.inertia_)
            ari_r = adjusted_rand_score(best_km6.labels_, m_r.labels_)
            aris_to_best.append(ari_r)
            partitions_50.append(tuple(m_r.labels_))

        # Distinct partitions
        unique_p = []
        for p_tup in partitions_50:
            if not any(adjusted_rand_score(p_tup, u) > 0.9999 for u in unique_p):
                unique_p.append(p_tup)

        init_records.append({
            'Stage': stage_name,
            'N': stage_n,
            'Restarts_Count': 50,
            'Best_WCSS': round(float(np.min(wcss_50)), 4),
            'Median_WCSS': round(float(np.median(wcss_50)), 4),
            'Worst_WCSS': round(float(np.max(wcss_50)), 4),
            'Std_WCSS': round(float(np.std(wcss_50)), 4),
            'Distinct_Partitions_Count': len(unique_p),
            'Mean_ARI_to_Best': round(float(np.mean(aris_to_best)), 4),
            'Median_ARI_to_Best': round(float(np.median(aris_to_best)), 4)
        })

    df_init_stage = pd.DataFrame(init_records)
    df_init_stage.to_csv(os.path.join(SCRIPT_DIR, 'initialization_stability_by_stage.csv'), index=False)
    print("Saved: initialization_stability_by_stage.csv")

    # 14. Subsampling Stability (80% Subsample over 50 Trials for M80, L200, FULL)
    print("\n--- Running Subsampling Stability (50 Trials, 80% Subsampling for M80, L200, FULL) ---")
    subsample_records = []

    for stage_name in ['M80', 'L200', 'FULL']:
        df_stage, stage_n = stages[stage_name]
        X_stage = df_stage[feature_cols].values
        full_km6 = best_models[(stage_name, 6)]
        
        sub_size = int(0.8 * stage_n)
        ari_trials = []
        nmi_trials = []

        for trial in range(50):
            rng_sub = np.random.RandomState(5000 + trial)
            sampled_idx = np.sort(rng_sub.choice(stage_n, size=sub_size, replace=False))
            X_sub = X_stage[sampled_idx]

            # Fit K-Means on subsample (best of 5 restarts)
            best_sub_w = float('inf')
            best_sub_m = None
            for r in range(5):
                m_sub = CustomKMeans(6, max_iter=50, random_state=trial * 10 + r).fit(X_sub)
                if m_sub.inertia_ < best_sub_w:
                    best_sub_w = m_sub.inertia_
                    best_sub_m = m_sub

            # Compare on sampled items
            restricted_true_labels = full_km6.labels_[sampled_idx]
            ari_t = adjusted_rand_score(restricted_true_labels, best_sub_m.labels_)
            nmi_t = normalized_mutual_info_score(restricted_true_labels, best_sub_m.labels_)
            ari_trials.append(ari_t)
            nmi_trials.append(nmi_t)

        subsample_records.append({
            'Stage': stage_name,
            'N': stage_n,
            'Subsample_Size': sub_size,
            'Trials_Count': 50,
            'Mean_ARI': round(float(np.mean(ari_trials)), 4),
            'Median_ARI': round(float(np.median(ari_trials)), 4),
            'Std_ARI': round(float(np.std(ari_trials)), 4),
            'Min_ARI': round(float(np.min(ari_trials)), 4),
            'Max_ARI': round(float(np.max(ari_trials)), 4),
            'Mean_NMI': round(float(np.mean(nmi_trials)), 4),
            'Median_NMI': round(float(np.median(nmi_trials)), 4),
            'Std_NMI': round(float(np.std(nmi_trials)), 4)
        })

    df_subsample_stage = pd.DataFrame(subsample_records)
    df_subsample_stage.to_csv(os.path.join(SCRIPT_DIR, 'subsample_stability_by_stage.csv'), index=False)
    print("Saved: subsample_stability_by_stage.csv")

    t_elapsed_total = time.time() - t_start_total
    print(f"\n=== Step 7A Progressive Scaling Pipeline Completed in {t_elapsed_total:.2f}s ===")


if __name__ == '__main__':
    run_step7a()
