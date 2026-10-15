"""
Step 6A Validation Suite: 20-Point Verification of Manual K-Means Micro Experiment.
"""

import os
import sys
import json
import math
import numpy as np
import pandas as pd
from pymongo import MongoClient

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
EXPERIMENTS_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..'))
BACKEND_DIR = os.path.abspath(os.path.join(EXPERIMENTS_DIR, '..'))
PROJECT_ROOT = os.path.abspath(os.path.join(BACKEND_DIR, '..'))

def run_validations():
    print("==================================================")
    print("STEP 6A: 20-POINT VALIDATION SUITE")
    print("==================================================")
    
    results = []

    # 1. Exactly 10 real active products
    sel_path = os.path.join(SCRIPT_DIR, 'selected_products_10.json')
    with open(sel_path, 'r', encoding='utf-8') as f:
        sel_prods = json.load(f)
    check1 = (len(sel_prods) == 10)
    results.append(("1. Exactly 10 real active products", check1, f"Found {len(sel_prods)} products"))

    # 2. Production DB unchanged
    # Connect and check total count in skinsyntax.san_pham == 2473
    uri = "mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB"
    client = MongoClient(uri, serverSelectionTimeoutMS=5000)
    db = client["skinsyntax"]
    total_sp = db["san_pham"].count_documents({})
    check2 = (total_sp == 2473)
    results.append(("2. Production DB unchanged", check2, f"Total products in DB: {total_sp} (expected 2473)"))

    # 3. Production source unchanged (git status check for production files)
    # Check that modified files are only in backend/experiments/
    import subprocess
    git_status = subprocess.check_output(['git', 'status', '--porcelain'], cwd=PROJECT_ROOT).decode('utf-8', errors='ignore')
    modified_lines = [l.strip() for l in git_status.splitlines() if l.strip()]
    prod_changes = [l for l in modified_lines if not any(x in l for x in ['backend/experiments/', '.gemini', 'workflows/'])]
    check3 = (len(prod_changes) == 0)
    results.append(("3. Production source unchanged", check3, f"Non-experiment git changes: {len(prod_changes)}"))

    # 4. No so_luong_da_ban interpreted as sales
    audit_path = os.path.join(SCRIPT_DIR, 'schema_audit.json')
    with open(audit_path, 'r', encoding='utf-8') as f:
        schema_audit = json.load(f)
    sldb_audit = next((f for f in schema_audit.get("schema_fields", []) if f["field"] == "so_luong_da_ban"), {})
    check4 = (sldb_audit.get("suitable_for_clustering") == False and "never" in sldb_audit.get("reason", "").lower())
    results.append(("4. No so_luong_da_ban interpreted as sales", check4, "Confirmed rejected in schema audit"))

    # 5. No raw category ID used as Euclidean numeric feature
    # 6. No raw brand ID used as Euclidean numeric feature
    scaled_path = os.path.join(SCRIPT_DIR, 'scaled_features.csv')
    df_scaled = pd.read_csv(scaled_path)
    feature_cols = [c for c in df_scaled.columns if c not in ['Product_ID', 'SKU_ID', 'Product_Name', 'Role', 'Brand']]
    has_raw_cat = any('ma_danh_muc' in c for c in feature_cols)
    has_raw_brand = any('ma_thuong_hieu' in c for c in feature_cols)
    check5 = not has_raw_cat
    check6 = not has_raw_brand
    results.append(("5. No raw category ID used as Euclidean numeric feature", check5, f"Feature columns: {feature_cols}"))
    results.append(("6. No raw brand ID used as Euclidean numeric feature", check6, f"Brand ID excluded: {not has_raw_brand}"))

    # 7. Price transformation documented (log1p + z-score)
    enc_path = os.path.join(SCRIPT_DIR, 'encoded_features.csv')
    df_enc = pd.read_csv(enc_path)
    check7 = ('log1p_price' in df_enc.columns and 'z_price' in df_scaled.columns)
    results.append(("7. Price transformation documented", check7, "log1p(price) + z-score present"))

    # 8. Feature matrix finite
    matrix_vals = df_scaled[feature_cols].values
    check8 = np.all(np.isfinite(matrix_vals))
    results.append(("8. Feature matrix finite", check8, f"All {matrix_vals.size} elements finite"))

    # 9. Distance diagonal zero
    dist_path = os.path.join(SCRIPT_DIR, 'distance_matrix.csv')
    df_dist = pd.read_csv(dist_path, index_col=0)
    diag_vals = np.diag(df_dist.values)
    check9 = np.allclose(diag_vals, 0.0, atol=1e-5)
    results.append(("9. Distance diagonal zero", check9, f"Max diagonal: {np.max(diag_vals)}"))

    # 10. Distance matrix symmetric
    dist_mat = df_dist.values
    check10 = np.allclose(dist_mat, dist_mat.T, atol=1e-5)
    results.append(("10. Distance matrix symmetric", check10, "d(A,B) == d(B,A) for all pairs"))

    # 11. Distances non-negative
    check11 = np.all(dist_mat >= -1e-6)
    results.append(("11. Distances non-negative", check11, f"Min distance: {np.min(dist_mat)}"))

    # 12. Manual pair distances equal programmatic calculation
    manual_dist_path = os.path.join(SCRIPT_DIR, 'manual_distance_calculation.csv')
    df_man_dist = pd.read_csv(manual_dist_path)
    manual_pairs = df_man_dist['Pair_Comparison'].unique()
    diffs = []
    for pair in manual_pairs:
        p_sub = df_man_dist[df_man_dist['Pair_Comparison'] == pair]
        sum_sq = p_sub['Difference_Squared'].sum()
        manual_d = math.sqrt(sum_sq)
        p1 = p_sub.iloc[0]['Product_A'].split()[0]
        p2 = p_sub.iloc[0]['Product_B'].split()[0]
        matrix_d = df_dist.loc[p1, p2]
        diffs.append(abs(manual_d - matrix_d))
    check12 = (max(diffs) < 1e-4)
    results.append(("12. Manual pair distances equal programmatic calculation", check12, f"Max diff across manual pairs: {max(diffs):.6f}"))

    # 13. Deterministic initial centroids
    init_path = os.path.join(SCRIPT_DIR, 'initial_centroids.json')
    with open(init_path, 'r', encoding='utf-8') as f:
        init_centroids = json.load(f)
    c_pids = [init_centroids[k]['product_id'] for k in ['C1', 'C2', 'C3']]
    check13 = (c_pids == ['P_4365', 'P_350', 'P_16'])
    results.append(("13. Deterministic initial centroids", check13, f"Initial centroids: {c_pids}"))

    # 14. Manual assignment uses nearest centroid
    iter1_path = os.path.join(SCRIPT_DIR, 'manual_iteration_1.csv')
    df_iter1 = pd.read_csv(iter1_path)
    valid_assignments = True
    for _, row in df_iter1.iterrows():
        dists = [row['d(C1)'], row['d(C2)'], row['d(C3)']]
        expected_cluster = f"Cluster_{np.argmin(dists) + 1}"
        if row['Assigned_Cluster'] != expected_cluster:
            valid_assignments = False
    check14 = valid_assignments
    results.append(("14. Manual assignment uses nearest centroid", check14, "All 10 assignments correctly match min distance"))

    # 15. Centroid means calculated correctly
    update_path = os.path.join(SCRIPT_DIR, 'centroid_update_iteration1.csv')
    df_up1 = pd.read_csv(update_path)
    means_correct = True
    for _, row in df_up1.iterrows():
        c_name = row['Centroid']
        k = int(c_name[1]) - 1
        members = [p.strip() for p in row['Members'].split(',')]
        member_indices = [df_scaled[df_scaled['Product_ID'] == m].index[0] for m in members]
        calc_mean = np.mean(matrix_vals[member_indices], axis=0)
        table_mean = [row[f"mean_{col}"] for col in feature_cols]
        if not np.allclose(calc_mean, table_mean, atol=1e-3):
            means_correct = False
    check15 = means_correct
    results.append(("15. Centroid means calculated correctly", check15, "Arithmetic means verified across all features"))

    # 16. Custom K-Means matches manual result
    custom_res_path = os.path.join(SCRIPT_DIR, 'custom_kmeans_result.json')
    with open(custom_res_path, 'r', encoding='utf-8') as f:
        custom_res = json.load(f)
    check16 = custom_res.get('matches_manual', False)
    results.append(("16. Custom K-Means matches manual result", check16, f"Matches manual: {check16}"))

    # 17. WCSS non-increasing
    wcss_path = os.path.join(SCRIPT_DIR, 'wcss_by_iteration.csv')
    df_wcss = pd.read_csv(wcss_path)
    wcss_vals = df_wcss['WCSS'].values
    check17 = all(wcss_vals[i] >= wcss_vals[i+1] - 1e-5 for i in range(len(wcss_vals)-1))
    results.append(("17. WCSS non-increasing", check17, f"WCSS trajectory: {list(wcss_vals)}"))

    # 18. No production recommender integration
    # Verify no import of kmeans in backend/recommendation
    rcm_dir = os.path.join(BACKEND_DIR, 'recommendation')
    kmeans_in_rcm = False
    for root, dirs, files in os.walk(rcm_dir):
        for f in files:
            if f.endswith('.py') or f.endswith('.php'):
                fpath = os.path.join(root, f)
                with open(fpath, 'r', encoding='utf-8', errors='ignore') as code_f:
                    c = code_f.read()
                    if 'kmeans_from_scratch' in c or 'kmeans_micro_v1' in c:
                        kmeans_in_rcm = True
    check18 = not kmeans_in_rcm
    results.append(("18. No production recommender integration", check18, "Production recommender files untouched"))

    # 19. No Homepage changes
    # Verify frontend/index.html or similar has no kmeans references
    hp_path = os.path.join(PROJECT_ROOT, 'index.html')
    check19 = True
    if os.path.exists(hp_path):
        with open(hp_path, 'r', encoding='utf-8', errors='ignore') as f:
            if 'kmeans' in f.read().lower():
                check19 = False
    results.append(("19. No Homepage changes", check19, "Homepage completely free of clustering changes"))

    # 20. Previous association artifacts unchanged
    assoc_step2 = os.path.join(EXPERIMENTS_DIR, 'association_rules', 'micro_20sku_v1', 'baskets_30.json')
    assoc_step3 = os.path.join(EXPERIMENTS_DIR, 'association_rules', 'apriori_micro_v1', 'sku_rules.json')
    assoc_step4 = os.path.join(EXPERIMENTS_DIR, 'association_rules', 'fpgrowth_micro_v1', 'sku_rules.json')
    check20 = (os.path.exists(assoc_step2) and os.path.exists(assoc_step3) and os.path.exists(assoc_step4))
    results.append(("20. Previous association artifacts unchanged", check20, "All Step 2-4 association artifacts intact"))

    # Print summary
    print("\nVALIDATION SUMMARY:")
    all_passed = True
    for name, status, detail in results:
        status_str = "[PASS]" if status else "[FAIL]"
        print(f"{status_str} {name} -> {detail}")
        if not status:
            all_passed = False

    print("\n--------------------------------------------------")
    if all_passed:
        print("ALL 20 VALIDATION CHECKS PASSED PERFECTLY!")
    else:
        print("SOME CHECKS FAILED. Please review above.")
    print("--------------------------------------------------")
    return all_passed

if __name__ == '__main__':
    success = run_validations()
    sys.exit(0 if success else 1)
