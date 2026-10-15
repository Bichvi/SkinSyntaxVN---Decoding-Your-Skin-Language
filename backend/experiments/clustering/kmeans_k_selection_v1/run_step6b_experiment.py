"""
Step 6B: K Selection with Elbow + Silhouette on a 40-Product Controlled Sample.
Research-only script for SkinSyntaxVN clustering analysis.
"""

import os
import sys
import json
import math
import numpy as np
import pandas as pd
import matplotlib.pyplot as plt
from typing import Dict, List, Any, Tuple
from sklearn.metrics import silhouette_score as sk_silhouette_score
from sklearn.metrics import adjusted_rand_score as sk_ari_score
from sklearn.cluster import KMeans as SklearnKMeans

sys.stdout.reconfigure(encoding='utf-8')

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP6A_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'kmeans_micro_v1'))


# ---------------------------------------------------------
# 1. First-Principles K-Means Implementation with Restart Support
# ---------------------------------------------------------
class CustomKMeans:
    """
    First-principles K-Means algorithm:
    - Deterministic initialization via RandomState seed
    - Explicit Euclidean distance matrix
    - Deterministic tie-breaking (argmin picks lowest index)
    - Deterministic empty-cluster handling (furthest point reassignment)
    - Convergence tracking & WCSS computation
    """

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
        
        # Deterministic initial centroid indices
        init_indices = rng.choice(n_samples, size=self.n_clusters, replace=False)
        centroids = X[init_indices].copy()

        prev_labels = None

        for it in range(1, self.max_iter + 1):
            self.n_iter = it
            
            # 1. Distances to centroids: shape (n_samples, n_clusters)
            # d_ik = sqrt(sum_j (x_ij - c_kj)^2)
            dists = np.zeros((n_samples, self.n_clusters), dtype=float)
            for k in range(self.n_clusters):
                diff = X - centroids[k]
                dists[:, k] = np.sqrt(np.sum(diff ** 2, axis=1))

            # 2. Assign to nearest centroid (argmin breaks ties by lowest index)
            labels = np.argmin(dists, axis=1)

            # 3. Check for empty clusters and handle deterministically
            new_centroids = np.zeros((self.n_clusters, n_features), dtype=float)
            for k in range(self.n_clusters):
                cluster_pts = X[labels == k]
                if len(cluster_pts) > 0:
                    new_centroids[k] = np.mean(cluster_pts, axis=0)
                else:
                    self.empty_cluster_events += 1
                    # Reinitialize empty cluster centroid to the point furthest from its assigned centroid
                    current_assigned_dists = np.array([dists[i, labels[i]] for i in range(n_samples)])
                    furthest_idx = np.argmax(current_assigned_dists)
                    new_centroids[k] = X[furthest_idx].copy()
                    # Reassign this point temporarily
                    labels[furthest_idx] = k

            # 4. Check convergence
            if prev_labels is not None and np.array_equal(labels, prev_labels):
                self.converged = True
                break

            prev_labels = labels.copy()
            centroids = new_centroids.copy()

        self.cluster_centers_ = centroids
        self.labels_ = labels

        # Compute final WCSS / Inertia
        wcss = 0.0
        for i in range(n_samples):
            wcss += np.sum((X[i] - self.cluster_centers_[self.labels_[i]]) ** 2)
        self.inertia_ = float(wcss)

        return self


# ---------------------------------------------------------
# 2. Custom Silhouette Score from First Principles
# ---------------------------------------------------------
def compute_silhouette_custom(X: np.ndarray, labels: np.ndarray) -> Tuple[np.ndarray, float, float, float, float, int]:
    """
    Calculates Silhouette coefficients from first principles.
    a(i) = mean distance to other points in same cluster
    b(i) = min mean distance to points in another cluster
    s(i) = (b(i) - a(i)) / max(a(i), b(i))
    """
    n_samples = X.shape[0]
    unique_labels = np.unique(labels)
    k = len(unique_labels)

    if k <= 1 or k >= n_samples:
        raise ValueError("Silhouette is undefined for k=1 or k=n_samples")

    # Pairwise distance matrix
    pairwise_dists = np.zeros((n_samples, n_samples), dtype=float)
    for i in range(n_samples):
        diff = X - X[i]
        pairwise_dists[i] = np.sqrt(np.sum(diff ** 2, axis=1))

    s_scores = np.zeros(n_samples, dtype=float)

    for i in range(n_samples):
        own_cluster = labels[i]
        own_members = np.where(labels == own_cluster)[0]

        # a(i)
        if len(own_members) == 1:
            # Singleton cluster
            s_scores[i] = 0.0
            continue
        else:
            same_dists = pairwise_dists[i, own_members]
            # Exclude self distance (which is 0)
            a_i = np.sum(same_dists) / (len(own_members) - 1)

        # b(i)
        other_means = []
        for other_c in unique_labels:
            if other_c == own_cluster:
                continue
            other_members = np.where(labels == other_c)[0]
            if len(other_members) > 0:
                mean_dist = np.mean(pairwise_dists[i, other_members])
                other_means.append(mean_dist)
        
        b_i = min(other_means) if other_means else 0.0

        denom = max(a_i, b_i)
        if denom == 0.0:
            s_scores[i] = 0.0
        else:
            s_scores[i] = (b_i - a_i) / denom

    mean_s = float(np.mean(s_scores))
    median_s = float(np.median(s_scores))
    min_s = float(np.min(s_scores))
    max_s = float(np.max(s_scores))
    neg_count = int(np.sum(s_scores < 0))

    return s_scores, mean_s, median_s, min_s, max_s, neg_count


# ---------------------------------------------------------
# 3. Main Experiment Execution
# ---------------------------------------------------------
def run_step6b():
    print("=== Step 6B: K Selection on 40-Product Controlled Sample Starting ===")

    # 1. Load 40 selected products
    prod_file = os.path.join(SCRIPT_DIR, 'selected_products_40.json')
    with open(prod_file, 'r', encoding='utf-8') as f:
        products = json.load(f)

    n_prods = len(products)
    assert n_prods == 40, f"Expected 40 products, got {n_prods}"
    print(f"Loaded {n_prods} products successfully.")

    # 2. Dataset Feature Audit
    roles = [p['role'] for p in products]
    brands = set(p['brand'] for p in products)
    prices = [p['gia_ban'] for p in products]
    skin_oily_cnt = sum(p['skin_tags']['oily'] for p in products)
    skin_dry_cnt = sum(p['skin_tags']['dry'] for p in products)
    skin_sens_cnt = sum(p['skin_tags']['sensitive'] for p in products)
    unmapped_skin_cnt = sum(1 for p in products if not p.get('confident', True))

    role_counts = pd.Series(roles).value_counts().to_dict()
    print("\n--- Feature Coverage Audit ---")
    print(f"Total Products: {n_prods}")
    print(f"Role Distribution: {role_counts}")
    print(f"Unique Brands: {len(brands)}")
    print(f"Price Min: {min(prices):,d} VND | Median: {int(np.median(prices)):,d} VND | Max: {max(prices):,d} VND")
    print(f"Skin Oily Prevalence: {skin_oily_cnt}/{n_prods} ({skin_oily_cnt/n_prods*100:.1f}%)")
    print(f"Skin Dry Prevalence: {skin_dry_cnt}/{n_prods} ({skin_dry_cnt/n_prods*100:.1f}%)")
    print(f"Skin Sensitive Prevalence: {skin_sens_cnt}/{n_prods} ({skin_sens_cnt/n_prods*100:.1f}%)")
    print(f"Unmapped Skin Labels: {unmapped_skin_cnt}")

    # 3. Feature Engineering & Matrices
    # Roles order
    roles_order = ['CLEANSER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN', 'TREATMENT']
    
    raw_rows = []
    enc_rows = []
    
    log_prices = np.array([math.log(1.0 + p['gia_ban']) for p in products], dtype=float)
    # Population vs sample std dev
    mean_log_p = float(np.mean(log_prices))
    std_pop_log_p = float(np.sqrt(np.mean((log_prices - mean_log_p) ** 2)))
    std_sample_log_p = float(np.std(log_prices, ddof=1))
    
    print(f"\nPrice Log Transform (N=40): Mean = {mean_log_p:.4f}, Std (pop) = {std_pop_log_p:.4f}, Std (sample) = {std_sample_log_p:.4f}")
    print(f"Using POPULATION standard deviation convention (consistent with Step 6A).")

    for i, p in enumerate(products):
        pid = f"P_{p['ma_san_pham']}"
        raw_rows.append({
            'Product_ID': pid,
            'SKU_ID': p['ma_san_pham'],
            'Product_Name': p['ten_san_pham'],
            'Role': p['role'],
            'Brand': p['brand'],
            'gia_ban': p['gia_ban'],
            'loai_da': p['loai_da'],
            'skin_oily': p['skin_tags']['oily'],
            'skin_dry': p['skin_tags']['dry'],
            'skin_sensitive': p['skin_tags']['sensitive']
        })

        enc_row = {
            'Product_ID': pid,
            'SKU_ID': p['ma_san_pham'],
            'Role': p['role']
        }
        for r in roles_order:
            enc_row[f"role_{r.lower()}"] = 1 if p['role'] == r else 0
        enc_row['log1p_price'] = round(log_prices[i], 4)
        enc_row['skin_oily'] = p['skin_tags']['oily']
        enc_row['skin_dry'] = p['skin_tags']['dry']
        enc_row['skin_sensitive'] = p['skin_tags']['sensitive']
        enc_rows.append(enc_row)

    df_raw = pd.DataFrame(raw_rows)
    df_enc = pd.DataFrame(enc_rows)

    df_raw.to_csv(os.path.join(SCRIPT_DIR, 'raw_features_40.csv'), index=False)
    df_enc.to_csv(os.path.join(SCRIPT_DIR, 'encoded_features_40.csv'), index=False)

    # Scaled features
    scaled_rows = []
    feature_cols = [f"role_{r.lower()}" for r in roles_order] + ['z_price', 'skin_oily', 'skin_dry', 'skin_sensitive']
    
    for i, p in enumerate(products):
        pid = f"P_{p['ma_san_pham']}"
        z_p = (log_prices[i] - mean_log_p) / std_pop_log_p
        s_row = {
            'Product_ID': pid,
            'SKU_ID': p['ma_san_pham'],
            'Product_Name': p['ten_san_pham'],
            'Role': p['role'],
            'Brand': p['brand']
        }
        for r in roles_order:
            s_row[f"role_{r.lower()}"] = 1.0 if p['role'] == r else 0.0
        s_row['z_price'] = round(z_p, 4)
        s_row['skin_oily'] = float(p['skin_tags']['oily'])
        s_row['skin_dry'] = float(p['skin_tags']['dry'])
        s_row['skin_sensitive'] = float(p['skin_tags']['sensitive'])
        scaled_rows.append(s_row)

    df_scaled = pd.DataFrame(scaled_rows)
    df_scaled.to_csv(os.path.join(SCRIPT_DIR, 'scaled_features_40.csv'), index=False)
    print("Saved feature matrices: raw_features_40.csv, encoded_features_40.csv, scaled_features_40.csv")

    X = df_scaled[feature_cols].values
    assert X.shape == (40, 9), f"Feature matrix shape {X.shape} must be (40, 9)"
    assert np.all(np.isfinite(X)), "Feature matrix contains non-finite values!"

    # 4. Evaluate Candidate K in {2, 3, 4, 5, 6, 7, 8} with 20 Restarts per K
    k_range = list(range(2, 9))
    n_restarts = 20
    base_seed = 42

    print(f"\n--- Running K-Means for K in {k_range} with {n_restarts} Restarts each ---")
    restart_rows = []
    best_models_by_k = {}
    wcss_summary_rows = []
    sil_summary_rows = []
    sil_per_prod_dict = {'Product_ID': df_scaled['Product_ID'], 'SKU_ID': df_scaled['SKU_ID'], 'Product_Name': df_scaled['Product_Name'], 'Role': df_scaled['Role']}

    for k in k_range:
        k_wcss_list = []
        best_km = None
        best_wcss = float('inf')

        for r in range(n_restarts):
            seed = base_seed + r
            km = CustomKMeans(n_clusters=k, max_iter=50, random_state=seed)
            km.fit(X)
            
            c_sizes = [int(np.sum(km.labels_ == c)) for c in range(k)]
            k_wcss_list.append(km.inertia_)

            restart_rows.append({
                'K': k,
                'Restart_Index': r + 1,
                'Seed': seed,
                'Iterations': km.n_iter,
                'Converged': km.converged,
                'Empty_Cluster_Events': km.empty_cluster_events,
                'WCSS': round(km.inertia_, 4),
                'Cluster_Sizes': str(c_sizes)
            })

            if km.inertia_ < best_wcss:
                best_wcss = km.inertia_
                best_km = km

        best_models_by_k[k] = best_km

        # Compute silhouette for best model
        s_scores, s_mean, s_median, s_min, s_max, neg_cnt = compute_silhouette_custom(X, best_km.labels_)
        sil_per_prod_dict[f"Silhouette_K{k}"] = np.round(s_scores, 4)

        # Sklearn cross-check for silhouette
        sk_sil = sk_silhouette_score(X, best_km.labels_)
        assert abs(s_mean - sk_sil) < 1e-4, f"Custom silhouette {s_mean} != sklearn {sk_sil}"

        # WCSS summary
        wcss_summary_rows.append({
            'K': k,
            'Best_WCSS': round(best_wcss, 4),
            'Mean_WCSS': round(float(np.mean(k_wcss_list)), 4),
            'Std_WCSS': round(float(np.std(k_wcss_list)), 4),
            'Min_WCSS': round(float(np.min(k_wcss_list)), 4),
            'Max_WCSS': round(float(np.max(k_wcss_list)), 4)
        })

        sil_summary_rows.append({
            'K': k,
            'Silhouette_Mean': round(s_mean, 4),
            'Silhouette_Median': round(s_median, 4),
            'Silhouette_Min': round(s_min, 4),
            'Silhouette_Max': round(s_max, 4),
            'Negative_Silhouette_Count': neg_cnt
        })

        print(f"K={k:1d} | Best WCSS: {best_wcss:8.4f} (Mean: {np.mean(k_wcss_list):8.4f}) | Sil Mean: {s_mean:.4f} | Neg Sil: {neg_cnt:2d}")

    # Save restart results
    df_restarts = pd.DataFrame(restart_rows)
    df_restarts.to_csv(os.path.join(SCRIPT_DIR, 'restart_results.csv'), index=False)
    print("Saved: restart_results.csv")

    # Add Delta WCSS and percentage reduction
    df_wcss = pd.DataFrame(wcss_summary_rows)
    delta_wcss = [None]
    pct_red = [None]
    for i in range(1, len(df_wcss)):
        prev_w = df_wcss.loc[i-1, 'Best_WCSS']
        curr_w = df_wcss.loc[i, 'Best_WCSS']
        d = prev_w - curr_w
        pct = (d / prev_w) * 100.0
        delta_wcss.append(round(d, 4))
        pct_red.append(round(pct, 2))
    df_wcss['Delta_WCSS'] = delta_wcss
    df_wcss['Reduction_Percent'] = pct_red
    df_wcss.to_csv(os.path.join(SCRIPT_DIR, 'wcss_by_k.csv'), index=False)
    print("Saved: wcss_by_k.csv")

    # Save silhouette tables
    df_sil = pd.DataFrame(sil_summary_rows)
    df_sil.to_csv(os.path.join(SCRIPT_DIR, 'silhouette_by_k.csv'), index=False)
    print("Saved: silhouette_by_k.csv")

    df_sil_per_prod = pd.DataFrame(sil_per_prod_dict)
    df_sil_per_prod.to_csv(os.path.join(SCRIPT_DIR, 'silhouette_per_product.csv'), index=False)
    print("Saved: silhouette_per_product.csv")

    # 5. K-Selection Summary Table
    k_sel_rows = []
    for i, k in enumerate(k_range):
        best_km = best_models_by_k[k]
        c_sizes = [int(np.sum(best_km.labels_ == c)) for c in range(k)]
        k_sel_rows.append({
            'K': k,
            'WCSS': df_wcss.loc[i, 'Best_WCSS'],
            'Delta_WCSS': df_wcss.loc[i, 'Delta_WCSS'],
            'Reduction_Percent': df_wcss.loc[i, 'Reduction_Percent'],
            'Silhouette_Mean': df_sil.loc[i, 'Silhouette_Mean'],
            'Silhouette_Median': df_sil.loc[i, 'Silhouette_Median'],
            'Negative_Silhouette_Count': df_sil.loc[i, 'Negative_Silhouette_Count'],
            'Smallest_Cluster_Size': min(c_sizes),
            'Largest_Cluster_Size': max(c_sizes)
        })
    df_k_sel = pd.DataFrame(k_sel_rows)
    df_k_sel.to_csv(os.path.join(SCRIPT_DIR, 'k_selection_summary.csv'), index=False)
    print("Saved: k_selection_summary.csv")

    # 6. Manual Silhouette Example for One Product in K=3 Solution
    # Select P_4365 (index 0) in K=3
    print("\n--- Constructing Manual Silhouette Example for P_4365 under K=3 ---")
    km3 = best_models_by_k[3]
    target_idx = 0
    target_pid = df_scaled.loc[target_idx, 'Product_ID']
    target_name = df_scaled.loc[target_idx, 'Product_Name']
    target_cluster = km3.labels_[target_idx]

    # Pairwise distances
    p_dists = np.sqrt(np.sum((X - X[target_idx]) ** 2, axis=1))

    man_rows = []
    # Cluster breakdown
    same_cluster_indices = [i for i in range(n_prods) if km3.labels_[i] == target_cluster and i != target_idx]
    other_clusters = [c for c in range(3) if c != target_cluster]

    for idx in same_cluster_indices:
        man_rows.append({
            'Target_Product': target_pid,
            'Comparison_Product': df_scaled.loc[idx, 'Product_ID'],
            'Comparison_Product_Name': df_scaled.loc[idx, 'Product_Name'],
            'Assigned_Cluster': f"Cluster_{km3.labels_[idx]+1}",
            'Cluster_Relation': "Own Cluster (C_own)",
            'Euclidean_Distance': round(p_dists[idx], 4)
        })

    a_i = float(np.mean([p_dists[idx] for idx in same_cluster_indices]))

    # Distances to other clusters
    other_mean_dists = {}
    for oc in other_clusters:
        oc_indices = [i for i in range(n_prods) if km3.labels_[i] == oc]
        for idx in oc_indices:
            man_rows.append({
                'Target_Product': target_pid,
                'Comparison_Product': df_scaled.loc[idx, 'Product_ID'],
                'Comparison_Product_Name': df_scaled.loc[idx, 'Product_Name'],
                'Assigned_Cluster': f"Cluster_{km3.labels_[idx]+1}",
                'Cluster_Relation': f"Other Cluster {oc+1}",
                'Euclidean_Distance': round(p_dists[idx], 4)
            })
        other_mean_dists[oc] = float(np.mean([p_dists[idx] for idx in oc_indices]))

    nearest_other_cluster = min(other_mean_dists, key=other_mean_dists.get)
    b_i = other_mean_dists[nearest_other_cluster]
    s_i = (b_i - a_i) / max(a_i, b_i)

    print(f"Manual a(i) = {a_i:.4f}")
    for oc, om in other_mean_dists.items():
        print(f"Mean dist to Cluster {oc+1} = {om:.4f}")
    print(f"Manual b(i) = {b_i:.4f} (from Cluster {nearest_other_cluster+1})")
    print(f"Manual s(i) = ({b_i:.4f} - {a_i:.4f}) / max({a_i:.4f}, {b_i:.4f}) = {s_i:.4f}")
    print(f"Programmatic s(i) = {df_sil_per_prod.loc[target_idx, 'Silhouette_K3']:.4f}")
    assert abs(s_i - df_sil_per_prod.loc[target_idx, 'Silhouette_K3']) < 1e-4

    df_man_sil = pd.DataFrame(man_rows)
    df_man_sil.to_csv(os.path.join(SCRIPT_DIR, 'manual_silhouette_example.csv'), index=False)
    print("Saved: manual_silhouette_example.csv")

    # 7. Cluster Composition for K=3 and Selected Research K
    # Let's inspect K=5 as candidate research K vs K=3
    # In research K: K=5 directly aligns with the 5 routine roles, let's see how K=5 separates vs K=3
    cluster_comp_data = {}
    for k in [3, 4, 5, 6]:
        km_k = best_models_by_k[k]
        k_clusters = []
        for c in range(k):
            members_idx = np.where(km_k.labels_ == c)[0]
            c_prods = [products[m] for m in members_idx]
            c_prices = [p['gia_ban'] for p in c_prods]
            c_roles = [p['role'] for p in c_prods]
            c_brands = list(set(p['brand'] for p in c_prods))
            c_oily = sum(p['skin_tags']['oily'] for p in c_prods)
            c_dry = sum(p['skin_tags']['dry'] for p in c_prods)
            c_sens = sum(p['skin_tags']['sensitive'] for p in c_prods)

            k_clusters.append({
                'cluster_index': c + 1,
                'cluster_name': f"Cluster_{c+1}",
                'size': len(c_prods),
                'member_pids': [f"P_{p['ma_san_pham']}" for p in c_prods],
                'role_distribution': pd.Series(c_roles).value_counts().to_dict(),
                'brand_count': len(c_brands),
                'brand_list': c_brands,
                'price_min': min(c_prices),
                'price_median': int(np.median(c_prices)),
                'price_max': max(c_prices),
                'skin_prevalence': {
                    'oily': f"{c_oily}/{len(c_prods)} ({c_oily/len(c_prods)*100:.1f}%)",
                    'dry': f"{c_dry}/{len(c_prods)} ({c_dry/len(c_prods)*100:.1f}%)",
                    'sensitive': f"{c_sens}/{len(c_prods)} ({c_sens/len(c_prods)*100:.1f}%)"
                }
            })
        cluster_comp_data[f"K_{k}"] = k_clusters

    with open(os.path.join(SCRIPT_DIR, 'cluster_composition.json'), 'w', encoding='utf-8') as f:
        json.dump(cluster_comp_data, f, ensure_ascii=False, indent=2)
    print("Saved: cluster_composition.json")

    # 8. Role-Dominance Analysis (Cluster x Role Contingency Table for K=5 and K=3)
    # Using selected research K=5 (and reporting K=3)
    km_sel = best_models_by_k[5]
    contingency_matrix = np.zeros((5, 5), dtype=int)
    for i in range(n_prods):
        c_idx = km_sel.labels_[i]
        r_idx = roles_order.index(products[i]['role'])
        contingency_matrix[c_idx, r_idx] += 1

    df_contingency = pd.DataFrame(
        contingency_matrix,
        index=[f"Cluster_{c+1}" for c in range(5)],
        columns=roles_order
    )
    df_contingency['Cluster_Size'] = df_contingency.sum(axis=1)
    df_contingency['Dominant_Role'] = [roles_order[np.argmax(contingency_matrix[c])] for c in range(5)]
    df_contingency['Dominant_Role_Count'] = [np.max(contingency_matrix[c]) for c in range(5)]
    df_contingency['Purity'] = [round(np.max(contingency_matrix[c]) / len(np.where(km_sel.labels_ == c)[0]), 4) if len(np.where(km_sel.labels_ == c)[0]) > 0 else 0.0 for c in range(5)]
    
    total_purity = sum(np.max(contingency_matrix[c]) for c in range(5)) / n_prods
    print(f"\nRole-Dominance Contingency (K=5): Overall Purity = {total_purity:.4f}")
    df_contingency.to_csv(os.path.join(SCRIPT_DIR, 'cluster_role_contingency.csv'))
    print("Saved: cluster_role_contingency.csv")

    # 9. Feature Sensitivity Analysis across Config A, B, C for Selected K=5
    print("\n--- Feature Sensitivity Analysis (Config A vs B vs C) ---")
    cols_a = [f"role_{r.lower()}" for r in roles_order] + ['z_price'] # 6 features
    cols_b = feature_cols # 9 features (Primary)
    cols_c = ['z_price', 'skin_oily', 'skin_dry', 'skin_sensitive'] # 4 features (No role)

    X_a = df_scaled[cols_a].values
    X_b = X
    X_c = df_scaled[cols_c].values

    # Fit best of 20 restarts for each config at K=5
    def fit_best(X_mat, k_val):
        best_m = None
        best_w = float('inf')
        for r in range(20):
            m = CustomKMeans(n_clusters=k_val, max_iter=50, random_state=42 + r)
            m.fit(X_mat)
            if m.inertia_ < best_w:
                best_w = m.inertia_
                best_m = m
        return best_m

    km_a5 = fit_best(X_a, 5)
    km_b5 = best_models_by_k[5]
    km_c5 = fit_best(X_c, 5)

    ari_ab = sk_ari_score(km_a5.labels_, km_b5.labels_)
    ari_bc = sk_ari_score(km_b5.labels_, km_c5.labels_)
    ari_ac = sk_ari_score(km_a5.labels_, km_c5.labels_)

    s_a = sk_silhouette_score(X_a, km_a5.labels_)
    s_b = sk_silhouette_score(X_b, km_b5.labels_)
    s_c = sk_silhouette_score(X_c, km_c5.labels_)

    sens_rows = [
        {
            'Configuration': 'Config_A (Role + Price)',
            'Num_Features': len(cols_a),
            'Feature_List': str(cols_a),
            'WCSS_K5': round(km_a5.inertia_, 4),
            'Silhouette_K5': round(s_a, 4),
            'ARI_vs_Config_B (Primary)': round(ari_ab, 4)
        },
        {
            'Configuration': 'Config_B (Role + Price + Skin) [PRIMARY]',
            'Num_Features': len(cols_b),
            'Feature_List': str(cols_b),
            'WCSS_K5': round(km_b5.inertia_, 4),
            'Silhouette_K5': round(s_b, 4),
            'ARI_vs_Config_B (Primary)': 1.0000
        },
        {
            'Configuration': 'Config_C (Price + Skin, No Role)',
            'Num_Features': len(cols_c),
            'Feature_List': str(cols_c),
            'WCSS_K5': round(km_c5.inertia_, 4),
            'Silhouette_K5': round(s_c, 4),
            'ARI_vs_Config_B (Primary)': round(ari_bc, 4)
        }
    ]
    df_sens = pd.DataFrame(sens_rows)
    df_sens.to_csv(os.path.join(SCRIPT_DIR, 'feature_sensitivity.csv'), index=False)
    print("Saved: feature_sensitivity.csv")
    print(f"ARI(Config A, Config B): {ari_ab:.4f} | ARI(Config B, Config C): {ari_bc:.4f}")

    # 10. Generate Visualizations (Plots)
    print("\n--- Generating Visualizations ---")
    # Plot 1: Elbow WCSS Curve
    plt.figure(figsize=(8, 5))
    plt.plot(df_wcss['K'], df_wcss['Best_WCSS'], marker='o', linewidth=2, color='#1f77b4', label='Best WCSS (Inertia)')
    plt.fill_between(df_wcss['K'], df_wcss['Min_WCSS'], df_wcss['Max_WCSS'], color='#1f77b4', alpha=0.15, label='Min-Max Range (20 Restarts)')
    plt.title('SkinSyntaxVN Step 6B: WCSS (Inertia) vs Number of Clusters (K)', fontsize=12, fontweight='bold')
    plt.xlabel('Candidate K', fontsize=11)
    plt.ylabel('Within-Cluster Sum of Squares (WCSS)', fontsize=11)
    plt.xticks(k_range)
    plt.grid(True, linestyle='--', alpha=0.6)
    plt.legend(loc='upper right')
    plt.tight_layout()
    elbow_path = os.path.join(SCRIPT_DIR, 'elbow_wcss.png')
    plt.savefig(elbow_path, dpi=300)
    plt.close()
    print(f"Saved: {elbow_path}")

    # Plot 2: Silhouette Score Curve
    plt.figure(figsize=(8, 5))
    plt.plot(df_sil['K'], df_sil['Silhouette_Mean'], marker='s', linewidth=2, color='#2ca02c', label='Mean Silhouette')
    plt.plot(df_sil['K'], df_sil['Silhouette_Median'], marker='^', linewidth=1.5, linestyle='--', color='#ff7f0e', label='Median Silhouette')
    plt.title('SkinSyntaxVN Step 6B: Silhouette Score across Candidate K', fontsize=12, fontweight='bold')
    plt.xlabel('Candidate K', fontsize=11)
    plt.ylabel('Silhouette Coefficient', fontsize=11)
    plt.xticks(k_range)
    plt.grid(True, linestyle='--', alpha=0.6)
    plt.legend(loc='lower left')
    plt.tight_layout()
    sil_path = os.path.join(SCRIPT_DIR, 'silhouette_by_k.png')
    plt.savefig(sil_path, dpi=300)
    plt.close()
    print(f"Saved: {sil_path}")

    print("\n=== Step 6B Pipeline Executed Successfully ===")


if __name__ == '__main__':
    run_step6b()
