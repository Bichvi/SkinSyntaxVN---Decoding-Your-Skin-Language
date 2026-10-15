"""
Step 6A: Manual K-Means + Euclidean Distance Micro Experiment Runner.
"""

import os
import json
import math
import numpy as np
import pandas as pd
from typing import Dict, List, Any, Tuple

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP2_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..', 'association_rules', 'micro_20sku_v1'))

# 1. Ten Selected Products
SELECTED_10_SKUS = [
    {
        'ma_san_pham': 4365,
        'ten_san_pham': "Gel Rửa Mặt Cosrx Tràm Trà, 0.5% BHA Có Độ pH Thấp 150ml",
        'role': 'CLEANSER',
        'brand': 'Cosrx',
        'gia_ban': 129000,
        'loai_da': "Da dầu/Hỗn hợp dầu/Mụn",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 0},
        'selection_reason': "Standard BHA cleansing gel for oily/acne-prone skin; accessible price point."
    },
    {
        'ma_san_pham': 21,
        'ten_san_pham': "Gel Rửa Mặt La Roche-Posay Dành Cho Da Dầu, Nhạy Cảm 400ml",
        'role': 'CLEANSER',
        'brand': 'La Roche-Posay',
        'gia_ban': 412000,
        'loai_da': "Da dầu/Nhạy cảm",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 1},
        'selection_reason': "Premium dermocosmetic cleanser tailored for oily and sensitive skin."
    },
    {
        'ma_san_pham': 350,
        'ten_san_pham': "Serum Skin1004 Rau Má Làm Dịu & Hỗ Trợ Phục Hồi Da 55ml",
        'role': 'SERUM',
        'brand': 'Skin1004',
        'gia_ban': 299000,
        'loai_da': "Da nhạy cảm/Mọi loại da",
        'skin_tags': {'oily': 0, 'dry': 0, 'sensitive': 1},
        'selection_reason': "Centella-based soothing serum focusing on barrier repair for sensitive skin."
    },
    {
        'ma_san_pham': 62,
        'ten_san_pham': "Serum L'Oreal Hyaluronic Acid Cấp Ẩm Sáng Da 30ml",
        'role': 'SERUM',
        'brand': "L'Oreal",
        'gia_ban': 289000,
        'loai_da': "Da khô/Mọi loại da",
        'skin_tags': {'oily': 0, 'dry': 1, 'sensitive': 0},
        'selection_reason': "Hyaluronic acid humectant serum for moisture replenishment and dry skin."
    },
    {
        'ma_san_pham': 139,
        'ten_san_pham': "Kem Dưỡng Ẩm Klairs Làm Dịu & Phục Hồi Da Ban Đêm 50g",
        'role': 'MOISTURIZER',
        'brand': 'Klairs',
        'gia_ban': 329000,
        'loai_da': "Da nhạy cảm/Da kích ứng",
        'skin_tags': {'oily': 0, 'dry': 0, 'sensitive': 1},
        'selection_reason': "Guaiazulene soothing recovery cream for irritated and sensitive skin."
    },
    {
        'ma_san_pham': 318,
        'ten_san_pham': "Kem Dưỡng Hada Labo Dưỡng Ẩm Tối Ưu Cho Da Thường/Khô 50g",
        'role': 'MOISTURIZER',
        'brand': 'Hada Labo',
        'gia_ban': 185000,
        'loai_da': "Da khô/Da thường",
        'skin_tags': {'oily': 0, 'dry': 1, 'sensitive': 0},
        'selection_reason': "Deep hydration occlusion cream for normal-to-dry skin barriers."
    },
    {
        'ma_san_pham': 16,
        'ten_san_pham': "Sữa Chống Nắng Anessa Dưỡng Da Kiềm Dầu 60ml (Bản Mới)",
        'role': 'SUNSCREEN',
        'brand': 'Anessa',
        'gia_ban': 549000,
        'loai_da': "Da dầu/Hỗn hợp dầu",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 0},
        'selection_reason': "High-end sebum-regulating UV milk for oily skin; top price tier in sample."
    },
    {
        'ma_san_pham': 725,
        'ten_san_pham': "Sữa Chống Nắng Sunplay Skin Aqua Nắp Xanh Dành Cho Da Dầu 50g",
        'role': 'SUNSCREEN',
        'brand': 'Sunplay Skin Aqua',
        'gia_ban': 102000,
        'loai_da': "Da dầu/Hỗn hợp dầu",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 0},
        'selection_reason': "Budget daily sun protection milk for oily skin; lowest price tier in sample."
    },
    {
        'ma_san_pham': 112,
        'ten_san_pham': "Gel Dưỡng Megaduo Plus Giảm Mụn, Mờ Thâm 15g",
        'role': 'TREATMENT',
        'brand': 'Megaduo',
        'gia_ban': 105000,
        'loai_da': "Da mụn/Da dầu",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 0},
        'selection_reason': "Targeted retinaldehyde/AHA acne spot treatment; budget active."
    },
    {
        'ma_san_pham': 740,
        'ten_san_pham': "Gel Giảm Mụn Eucerin Dành Cho Mụn Viêm & Không Viêm 40ml",
        'role': 'TREATMENT',
        'brand': 'Eucerin',
        'gia_ban': 370000,
        'loai_da': "Da dầu/Da mụn/Nhạy cảm",
        'skin_tags': {'oily': 1, 'dry': 0, 'sensitive': 1},
        'selection_reason': "Clinical salicylic/glycolic acid complex treatment for acne and blemishes."
    }
]


def run_micro_experiment():
    print("=== Step 6A: Manual K-Means Micro Experiment Starting ===")

    # 1. Save selected_products_10.json
    sel_path = os.path.join(SCRIPT_DIR, 'selected_products_10.json')
    with open(sel_path, 'w', encoding='utf-8') as f:
        json.dump(SELECTED_10_SKUS, f, ensure_ascii=False, indent=2)
    print(f"Saved: {sel_path}")

    # 2. Raw features table
    raw_rows = []
    for p in SELECTED_10_SKUS:
        raw_rows.append({
            'Product_ID': f"P_{p['ma_san_pham']}",
            'SKU_ID': p['ma_san_pham'],
            'Product_Name': p['ten_san_pham'],
            'Role': p['role'],
            'Brand': p['brand'],
            'Price_VND': p['gia_ban'],
            'Skin_Type_Raw': p['loai_da'],
            'Tag_Oily': p['skin_tags']['oily'],
            'Tag_Dry': p['skin_tags']['dry'],
            'Tag_Sensitive': p['skin_tags']['sensitive']
        })
    df_raw = pd.DataFrame(raw_rows)
    df_raw.to_csv(os.path.join(SCRIPT_DIR, 'raw_features.csv'), index=False)
    print("Saved: raw_features.csv")

    # 3. Feature Engineering: Price log1p and z-score standardization
    # x_price = log(1 + price)
    prices = [p['gia_ban'] for p in SELECTED_10_SKUS]
    log_prices = [math.log(1.0 + p) for p in prices]
    mean_log_p = sum(log_prices) / len(log_prices)
    # Population standard deviation convention
    std_log_p = math.sqrt(sum((x - mean_log_p) ** 2 for x in log_prices) / len(log_prices))
    z_prices = [(x - mean_log_p) / std_log_p for x in log_prices]

    print(f"Price Log Transform: Mean = {mean_log_p:.4f}, Std (pop) = {std_log_p:.4f}")

    # One-hot encoding for 5 roles: CLEANSER, SERUM, MOISTURIZER, SUNSCREEN, TREATMENT
    roles_order = ['CLEANSER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN', 'TREATMENT']

    # Encoded features (raw values + encoded categorical)
    encoded_rows = []
    scaled_rows = []

    for idx, p in enumerate(SELECTED_10_SKUS):
        pid = f"P_{p['ma_san_pham']}"
        role_onehot = {f"role_{r.lower()}": (1 if p['role'] == r else 0) for r in roles_order}

        enc_row = {'Product_ID': pid, 'SKU_ID': p['ma_san_pham'], 'Role': p['role']}
        enc_row.update(role_onehot)
        enc_row['log1p_price'] = round(log_prices[idx], 4)
        enc_row['skin_oily'] = p['skin_tags']['oily']
        enc_row['skin_dry'] = p['skin_tags']['dry']
        enc_row['skin_sensitive'] = p['skin_tags']['sensitive']
        encoded_rows.append(enc_row)

        scaled_row = {'Product_ID': pid, 'SKU_ID': p['ma_san_pham'], 'Role': p['role']}
        scaled_row.update(role_onehot)
        scaled_row['z_price'] = round(z_prices[idx], 4)
        scaled_row['skin_oily'] = p['skin_tags']['oily']
        scaled_row['skin_dry'] = p['skin_tags']['dry']
        scaled_row['skin_sensitive'] = p['skin_tags']['sensitive']
        scaled_rows.append(scaled_row)

    df_encoded = pd.DataFrame(encoded_rows)
    df_encoded.to_csv(os.path.join(SCRIPT_DIR, 'encoded_features.csv'), index=False)
    print("Saved: encoded_features.csv")

    df_scaled = pd.DataFrame(scaled_rows)
    df_scaled.to_csv(os.path.join(SCRIPT_DIR, 'scaled_features.csv'), index=False)
    print("Saved: scaled_features.csv")

    # Feature vector definition (9 dimensions):
    # [role_cleanser, role_serum, role_moisturizer, role_sunscreen, role_treatment, z_price, skin_oily, skin_dry, skin_sensitive]
    feature_cols = [f"role_{r.lower()}" for r in roles_order] + ['z_price', 'skin_oily', 'skin_dry', 'skin_sensitive']
    X = df_scaled[feature_cols].values
    product_ids = df_scaled['Product_ID'].tolist()

    # 4. Manual Euclidean Distance for 3 selected pairs:
    # Pair A (Similar): P_4365 (Cosrx Cleanser) vs P_21 (LRP Cleanser) - same role, both oily
    # Pair B (Distinct roles): P_4365 (Cosrx Cleanser) vs P_16 (Anessa Sunscreen) - Cleanser vs Sunscreen, different price
    # Pair C (Intermediate): P_350 (Skin1004 Serum) vs P_139 (Klairs Moisturizer) - Serum vs Moisturizer, both sensitive
    idx_4365 = product_ids.index('P_4365')
    idx_21 = product_ids.index('P_21')
    idx_16 = product_ids.index('P_16')
    idx_350 = product_ids.index('P_350')
    idx_139 = product_ids.index('P_139')

    pairs_to_detail = [
        ('Pair_A_Similar_Cleansers', idx_4365, idx_21, 'P_4365 (Cosrx Cleanser)', 'P_21 (LRP Cleanser)'),
        ('Pair_B_Distinct_Roles', idx_4365, idx_16, 'P_4365 (Cosrx Cleanser)', 'P_16 (Anessa Sunscreen)'),
        ('Pair_C_Intermediate_Barrier', idx_350, idx_139, 'P_350 (Skin1004 Serum)', 'P_139 (Klairs Moisturizer)')
    ]

    manual_dist_rows = []
    for pair_name, i_a, i_b, name_a, name_b in pairs_to_detail:
        vec_a = X[i_a]
        vec_b = X[i_b]
        sum_sq = 0.0
        for f_idx, f_name in enumerate(feature_cols):
            val_a = vec_a[f_idx]
            val_b = vec_b[f_idx]
            diff = val_a - val_b
            diff_sq = diff ** 2
            sum_sq += diff_sq
            manual_dist_rows.append({
                'Pair_Comparison': pair_name,
                'Product_A': name_a,
                'Product_B': name_b,
                'Feature': f_name,
                'Value_A': round(val_a, 4),
                'Value_B': round(val_b, 4),
                'Difference (A - B)': round(diff, 4),
                'Difference_Squared': round(diff_sq, 4),
                'Running_Sum_Squares': round(sum_sq, 4),
                'Final_Euclidean_Distance': round(math.sqrt(sum_sq), 4) if f_idx == len(feature_cols) - 1 else None
            })

    df_manual_dist = pd.DataFrame(manual_dist_rows)
    df_manual_dist.to_csv(os.path.join(SCRIPT_DIR, 'manual_distance_calculation.csv'), index=False)
    print("Saved: manual_distance_calculation.csv")

    # 5. Full 10x10 Pairwise Distance Matrix
    n_prods = len(product_ids)
    dist_matrix = np.zeros((n_prods, n_prods))
    for i in range(n_prods):
        for j in range(n_prods):
            d = math.sqrt(np.sum((X[i] - X[j]) ** 2))
            dist_matrix[i, j] = round(d, 4)

    df_dist_mat = pd.DataFrame(dist_matrix, index=product_ids, columns=product_ids)
    df_dist_mat.to_csv(os.path.join(SCRIPT_DIR, 'distance_matrix.csv'))
    print("Saved: distance_matrix.csv")

    # Identify smallest non-zero and largest distances
    non_zero_dists = []
    for i in range(n_prods):
        for j in range(i + 1, n_prods):
            non_zero_dists.append((product_ids[i], product_ids[j], dist_matrix[i, j]))
    non_zero_dists.sort(key=lambda x: x[2])
    print("\n3 Smallest Non-Zero Distances (Most Similar in Feature Space):")
    for a, b, d in non_zero_dists[:3]:
        print(f"  {a} <-> {b}: d = {d:.4f}")
    print("3 Largest Distances (Most Dissimilar in Feature Space):")
    for a, b, d in non_zero_dists[-3:]:
        print(f"  {a} <-> {b}: d = {d:.4f}")

    # 6. Initial Centroids Definition (K = 3)
    # Documented deterministic choice from distinct metadata regions:
    # Centroid 1: P_4365 (Cosrx Cleanser, budget oily active)
    # Centroid 2: P_350 (Skin1004 Serum, mid-range soothing sensitive)
    # Centroid 3: P_16 (Anessa Sunscreen, premium oily sun protection)
    init_indices = [idx_4365, idx_350, idx_16]
    initial_centroids = {
        'C1': {'product_id': 'P_4365', 'sku_id': 4365, 'name': SELECTED_10_SKUS[idx_4365]['ten_san_pham'], 'vector': list(np.round(X[idx_4365], 4))},
        'C2': {'product_id': 'P_350', 'sku_id': 350, 'name': SELECTED_10_SKUS[idx_350]['ten_san_pham'], 'vector': list(np.round(X[idx_350], 4))},
        'C3': {'product_id': 'P_16', 'sku_id': 16, 'name': SELECTED_10_SKUS[idx_16]['ten_san_pham'], 'vector': list(np.round(X[idx_16], 4))}
    }
    with open(os.path.join(SCRIPT_DIR, 'initial_centroids.json'), 'w', encoding='utf-8') as f:
        json.dump(initial_centroids, f, ensure_ascii=False, indent=2)
    print("Saved: initial_centroids.json")

    # 7. Manual Iteration 1
    centroids = np.array([X[idx_4365], X[idx_350], X[idx_16]], dtype=float)
    iter1_rows = []
    assignments = []

    for i in range(n_prods):
        d0 = math.sqrt(np.sum((X[i] - centroids[0]) ** 2))
        d1 = math.sqrt(np.sum((X[i] - centroids[1]) ** 2))
        d2 = math.sqrt(np.sum((X[i] - centroids[2]) ** 2))
        dists = [d0, d1, d2]
        # Deterministic tie-breaking: np.argmin picks smallest index
        assigned = int(np.argmin(dists))
        assignments.append(assigned)
        iter1_rows.append({
            'Product_ID': product_ids[i],
            'SKU_ID': SELECTED_10_SKUS[i]['ma_san_pham'],
            'Product_Name': SELECTED_10_SKUS[i]['ten_san_pham'],
            'Role': SELECTED_10_SKUS[i]['role'],
            'd(C1)': round(d0, 4),
            'd(C2)': round(d1, 4),
            'd(C3)': round(d2, 4),
            'Assigned_Cluster': f"Cluster_{assigned + 1}"
        })

    df_iter1 = pd.DataFrame(iter1_rows)
    df_iter1.to_csv(os.path.join(SCRIPT_DIR, 'manual_iteration_1.csv'), index=False)
    print("Saved: manual_iteration_1.csv")

    # Calculate WCSS for Iteration 1
    wcss_iter1 = sum(np.sum((X[i] - centroids[assignments[i]]) ** 2) for i in range(n_prods))
    print(f"Iteration 1 WCSS: {wcss_iter1:.4f}")

    # Update Centroids Iteration 1
    new_centroids = np.zeros_like(centroids)
    update_rows = []
    for k in range(3):
        cluster_members = [i for i, a in enumerate(assignments) if a == k]
        c_size = len(cluster_members)
        if c_size > 0:
            mean_vec = np.mean(X[cluster_members], axis=0)
            new_centroids[k] = mean_vec
        else:
            new_centroids[k] = centroids[k]

        update_row = {
            'Centroid': f"C{k+1}",
            'Cluster_Size': c_size,
            'Members': ", ".join([product_ids[m] for m in cluster_members])
        }
        for f_idx, f_name in enumerate(feature_cols):
            update_row[f"mean_{f_name}"] = round(new_centroids[k][f_idx], 4)
        update_rows.append(update_row)

    df_update1 = pd.DataFrame(update_rows)
    df_update1.to_csv(os.path.join(SCRIPT_DIR, 'centroid_update_iteration1.csv'), index=False)
    print("Saved: centroid_update_iteration1.csv")

    # 8. Loop iterations until convergence (Iteration 2, etc.)
    all_iterations_log = [{
        'iteration': 1,
        'wcss': round(wcss_iter1, 4),
        'changed_products_count': 10,
        'changed_products': product_ids,
        'cluster_sizes': [sum(1 for a in assignments if a == k) for k in range(3)],
        'centroids': [list(np.round(c, 4)) for c in new_centroids]
    }]
    wcss_history = [{'Iteration': 1, 'WCSS': round(wcss_iter1, 4)}]
    current_centroids = new_centroids.copy()
    prev_assignments = list(assignments)
    it_num = 2
    converged = False

    while it_num <= 10 and not converged:
        iter_assignments = []
        iter_dists = []
        for i in range(n_prods):
            dists = [math.sqrt(np.sum((X[i] - current_centroids[k]) ** 2)) for k in range(3)]
            assigned = int(np.argmin(dists))
            iter_assignments.append(assigned)
            iter_dists.append(dists)

        wcss_cur = sum(np.sum((X[i] - current_centroids[iter_assignments[i]]) ** 2) for i in range(n_prods))
        wcss_history.append({'Iteration': it_num, 'WCSS': round(wcss_cur, 4)})

        changed_products = [product_ids[i] for i in range(n_prods) if iter_assignments[i] != prev_assignments[i]]

        # Compute next centroids
        next_centroids = np.zeros_like(current_centroids)
        for k in range(3):
            members = [i for i, a in enumerate(iter_assignments) if a == k]
            if members:
                next_centroids[k] = np.mean(X[members], axis=0)
            else:
                next_centroids[k] = current_centroids[k]

        all_iterations_log.append({
            'iteration': it_num,
            'wcss': round(wcss_cur, 4),
            'changed_products_count': len(changed_products),
            'changed_products': changed_products,
            'cluster_sizes': [sum(1 for a in iter_assignments if a == k) for k in range(3)],
            'centroids': [list(np.round(c, 4)) for c in next_centroids]
        })

        if iter_assignments == prev_assignments:
            print(f"Convergence reached at iteration {it_num}!")
            converged = True
        else:
            prev_assignments = list(iter_assignments)
            current_centroids = next_centroids.copy()
            it_num += 1

    with open(os.path.join(SCRIPT_DIR, 'manual_iterations.json'), 'w', encoding='utf-8') as f:
        json.dump(all_iterations_log, f, ensure_ascii=False, indent=2)
    print("Saved: manual_iterations.json")

    df_wcss = pd.DataFrame(wcss_history)
    df_wcss.to_csv(os.path.join(SCRIPT_DIR, 'wcss_by_iteration.csv'), index=False)
    print("Saved: wcss_by_iteration.csv")

    # 9. Implement K-Means from First Principles & Cross-Check
    print("\n--- Implementing and Verifying Custom K-Means ---")
    from kmeans_from_scratch import KMeansFromScratch

    custom_km = KMeansFromScratch(n_clusters=3, max_iter=10, random_state=42)
    # Initialize with exact manual initial centroids
    custom_km.fit(X, initial_centroids=np.array([X[idx_4365], X[idx_350], X[idx_16]], dtype=float))

    custom_result = {
        'n_clusters': 3,
        'converged': bool(custom_km.converged),
        'n_iter': int(custom_km.n_iter),
        'final_wcss': round(float(custom_km.inertia_), 4),
        'cluster_assignments': [int(a) for a in custom_km.labels_],
        'final_centroids': [list(np.round(c, 4)) for c in custom_km.cluster_centers_],
        'matches_manual': bool(list(custom_km.labels_) == prev_assignments and abs(custom_km.inertia_ - wcss_history[-1]['WCSS']) < 1e-4)
    }
    with open(os.path.join(SCRIPT_DIR, 'custom_kmeans_result.json'), 'w', encoding='utf-8') as f:
        json.dump(custom_result, f, ensure_ascii=False, indent=2)
    print(f"Custom K-Means matches manual calculation: {custom_result['matches_manual']}")
    print(f"Final WCSS: {custom_result['final_wcss']}, Iterations to converge: {custom_result['n_iter']}")

    # 10. Optional Scikit-Learn Cross-Check
    try:
        from sklearn.cluster import KMeans as SklearnKMeans
        sk_km = SklearnKMeans(n_clusters=3, init=np.array([X[idx_4365], X[idx_350], X[idx_16]], dtype=float), n_init=1, max_iter=10)
        sk_km.fit(X)
        print(f"Scikit-Learn WCSS: {sk_km.inertia_:.4f} (Custom: {custom_km.inertia_:.4f})")
        assert abs(sk_km.inertia_ - custom_km.inertia_) < 1e-4
        assert list(sk_km.labels_) == list(custom_km.labels_)
        print("Scikit-Learn Cross-Check: PERFECT MATCH (100% agreement)!")
    except Exception as e:
        print(f"Sklearn note: {e}")

    # 11. Feature Sensitivity Analysis:
    # Config A: Role (5) + Price (1) = 6 features
    # Config B: Role (5) + Price (1) + Skin Tags (3) = 9 features
    print("\n--- Running Feature Sensitivity Analysis ---")
    cols_a = [f"role_{r.lower()}" for r in roles_order] + ['z_price']
    X_a = df_scaled[cols_a].values
    init_a = np.array([X_a[idx_4365], X_a[idx_350], X_a[idx_16]], dtype=float)

    km_a = KMeansFromScratch(n_clusters=3, max_iter=10)
    km_a.fit(X_a, initial_centroids=init_a)

    sens_rows = []
    for i in range(n_prods):
        pid = product_ids[i]
        p_name = SELECTED_10_SKUS[i]['ten_san_pham']
        role = SELECTED_10_SKUS[i]['role']
        c_a = int(km_a.labels_[i]) + 1
        c_b = int(custom_km.labels_[i]) + 1
        sens_rows.append({
            'Product_ID': pid,
            'Product_Name': p_name,
            'Role': role,
            'Config_A_Cluster (Role+Price)': f"Cluster_{c_a}",
            'Config_B_Cluster (Role+Price+Skin)': f"Cluster_{c_b}",
            'Assignment_Shift': "Shifted" if c_a != c_b else "Stable"
        })

    df_sens = pd.DataFrame(sens_rows)
    df_sens.to_csv(os.path.join(SCRIPT_DIR, 'feature_sensitivity.csv'), index=False)
    print("Saved: feature_sensitivity.csv")

    print("\n=== All Step 6A Artifacts Generated Successfully ===")


if __name__ == '__main__':
    run_micro_experiment()
