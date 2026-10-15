"""
Run Step 5 Progressive Scaling and Rule-Stability Experiment.

Orchestrates:
1. Frozen S20_B30 baseline verification
2. Generation of Stage M (40 SKUs, 100 baskets) and Stage L (80 SKUs, 300 baskets)
3. Mining with custom Apriori and custom FP-Growth across all stages
4. Rule stability tracking for the 6 planted rules (Delta Support, Delta Confidence, Delta Lift)
5. Jaccard similarity across discovered rule sets
6. SKU-level stability & rare high-Lift rule follow-up (e.g. SKU 10 -> SKU 93)
7. Algorithmic scaling benchmark (30 runs per stage) & operation diagnostics
"""

import os
import sys
import json
import time
import hashlib
import statistics
import pandas as pd
from typing import Dict, List, Any, Set, Tuple

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP2_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'micro_20sku_v1'))
STEP3_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'apriori_micro_v1'))
STEP4_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'fpgrowth_micro_v1'))

sys.path.append(STEP3_DIR)
sys.path.append(STEP4_DIR)
sys.path.append(SCRIPT_DIR)

from apriori import apriori_first_principles, get_transactions_from_matrix
from fpgrowth import fpgrowth_first_principles
from generator_v2 import select_nested_products, generate_baskets_stage

ATLAS_URI = 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB'


def compute_sha256(filepath: str) -> str:
    with open(filepath, 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()


def compute_jaccard(rules_a: List[Dict[str, Any]], rules_b: List[Dict[str, Any]]) -> float:
    set_a = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in rules_a}
    set_b = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in rules_b}
    if not set_a and not set_b:
        return 1.0
    union = set_a | set_b
    if not union:
        return 0.0
    return round(len(set_a & set_b) / len(union), 4)


def main():
    print("=== Step 5: Progressive Scaling & Rule-Stability Experiment Starting ===")

    # --- 1. Verify Frozen S20_B30 Baseline ---
    expected_s20_hashes = {
        'baskets_30.json': '7443fda76f84ca686bea10fa41fb26566e4da81550ee8a12afa41e910ecae593',
        'selected_products.json': '948d0537b9bc9a1d9efb808b8d81640ba638a76192f680709e3dcf50ff717b9d',
        'transaction_matrix_role.csv': 'bda5d7c79ba7b465943bccdd1c49db89768ec2cef52f44f5ae48d230aea09d7d',
        'transaction_matrix_sku.csv': 'c2014664f1c1b96365496d2764f9088f1bdd5bd90caf3e71235823f1368a5af3'
    }

    for fn, exp_hash in expected_s20_hashes.items():
        actual_hash = compute_sha256(os.path.join(STEP2_DIR, fn))
        if actual_hash != exp_hash:
            raise ValueError(f"Integrity Error: Stage S file {fn} modified!")
    print("[Dataset Integrity] Stage S (S20_B30) strictly verified and frozen.")

    # --- 2. Select Nested Products for Stage M & Stage L ---
    print("\n--- Selecting Nested Real Catalog SKUs (S subset of M subset of L) ---")
    stage_m_products, stage_l_products = select_nested_products(ATLAS_URI)

    # Save Products JSON
    m_prod_file = os.path.join(SCRIPT_DIR, 'stage_m_products.json')
    with open(m_prod_file, 'w', encoding='utf-8') as f:
        json.dump(stage_m_products, f, ensure_ascii=False, indent=2)
    print(f"Saved: {m_prod_file} (Count = {len(stage_m_products)})")

    l_prod_file = os.path.join(SCRIPT_DIR, 'stage_l_products.json')
    with open(l_prod_file, 'w', encoding='utf-8') as f:
        json.dump(stage_l_products, f, ensure_ascii=False, indent=2)
    print(f"Saved: {l_prod_file} (Count = {len(stage_l_products)})")

    # --- 3. Generator Configuration (Recorded BEFORE mining) ---
    generator_config = {
        'generator_version': 'scaling-v2',
        'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'stages': {
            'Stage_S': {
                'code': 'S20_B30',
                'sku_count': 20,
                'basket_count': 30,
                'routine_probability': 0.80,
                'noise_probability': 0.20,
                'exploration_probability': 0.0,
                'seed': 42,
                'status': 'frozen_baseline'
            },
            'Stage_M': {
                'code': 'M40_B100',
                'sku_count': 40,
                'basket_count': 100,
                'scenario': 'association_scale_40sku_100basket_v1',
                'routine_probability': 0.65,
                'noise_probability': 0.20,
                'exploration_probability': 0.15,
                'size_weights': {'1': 0.15, '2': 0.40, '3': 0.25, '4': 0.15, '5': 0.05},
                'seed': 43
            },
            'Stage_L': {
                'code': 'L80_B300',
                'sku_count': 80,
                'basket_count': 300,
                'scenario': 'association_scale_80sku_300basket_v1',
                'routine_probability': 0.50,
                'noise_probability': 0.25,
                'exploration_probability': 0.25,
                'size_weights': {'1': 0.15, '2': 0.35, '3': 0.25, '4': 0.15, '5': 0.10},
                'seed': 44
            }
        },
        'design_rationale': (
            'Progressively weakens planted routine probability from 80% (Stage S) to 65% (Stage M) '
            'and 50% (Stage L), while increasing SKU universe from 20 to 40 and 80. '
            'This tests whether association rules remain stable or attenuate as catalog scale and noise increase.'
        )
    }

    config_file = os.path.join(SCRIPT_DIR, 'generator_config.json')
    with open(config_file, 'w', encoding='utf-8') as f:
        json.dump(generator_config, f, ensure_ascii=False, indent=2)
    print(f"Saved: {config_file}")

    # --- 4. Generate Stage M & Stage L Baskets & Matrices ---
    print("\n--- Generating Stage M Baskets & Matrices (100 baskets, 40 SKUs, seed 43) ---")
    m_baskets, m_role_df, m_sku_df = generate_baskets_stage(
        stage_name='Stage_M',
        scenario='association_scale_40sku_100basket_v1',
        n_baskets=100,
        products_dict=stage_m_products,
        seed=43,
        size_weights={1: 0.15, 2: 0.40, 3: 0.25, 4: 0.15, 5: 0.05},
        routine_prob=0.65,
        noise_prob=0.20,
        exploration_prob=0.15
    )

    with open(os.path.join(SCRIPT_DIR, 'stage_m_baskets.json'), 'w', encoding='utf-8') as f:
        json.dump(m_baskets, f, ensure_ascii=False, indent=2)
    m_role_df.to_csv(os.path.join(SCRIPT_DIR, 'stage_m_role_matrix.csv'))
    m_sku_df.to_csv(os.path.join(SCRIPT_DIR, 'stage_m_sku_matrix.csv'))
    print("Stage M saved: baskets JSON, role matrix CSV (100x8), SKU matrix CSV (100x40)")

    print("\n--- Generating Stage L Baskets & Matrices (300 baskets, 80 SKUs, seed 44) ---")
    l_baskets, l_role_df, l_sku_df = generate_baskets_stage(
        stage_name='Stage_L',
        scenario='association_scale_80sku_300basket_v1',
        n_baskets=300,
        products_dict=stage_l_products,
        seed=44,
        size_weights={1: 0.15, 2: 0.35, 3: 0.25, 4: 0.15, 5: 0.10},
        routine_prob=0.50,
        noise_prob=0.25,
        exploration_prob=0.25
    )

    with open(os.path.join(SCRIPT_DIR, 'stage_l_baskets.json'), 'w', encoding='utf-8') as f:
        json.dump(l_baskets, f, ensure_ascii=False, indent=2)
    l_role_df.to_csv(os.path.join(SCRIPT_DIR, 'stage_l_role_matrix.csv'))
    l_sku_df.to_csv(os.path.join(SCRIPT_DIR, 'stage_l_sku_matrix.csv'))
    print("Stage L saved: baskets JSON, role matrix CSV (300x8), SKU matrix CSV (300x80)")

    # --- 5. Run Pattern Mining Across Stages (Fixed Thresholds) ---
    print("\n--- Running Association Mining Across S, M, L ---")
    # Load Stage S role transactions
    s_role_df = pd.read_csv(os.path.join(STEP2_DIR, 'transaction_matrix_role.csv'), index_col='Basket_ID')
    s_role_txs = get_transactions_from_matrix(s_role_df)

    m_role_txs = get_transactions_from_matrix(m_role_df)
    l_role_txs = get_transactions_from_matrix(l_role_df)

    # Role-level mining (fixed baseline: min_supp=0.10, min_conf=0.20)
    print("Mining Stage S (Role)...")
    s_apriori_role = apriori_first_principles(s_role_txs, min_support=0.10, min_confidence=0.20)
    s_fpgrowth_role = fpgrowth_first_principles(s_role_txs, min_support=0.10, min_confidence=0.20)

    print("Mining Stage M (Role)...")
    m_apriori_role = apriori_first_principles(m_role_txs, min_support=0.10, min_confidence=0.20)
    m_fpgrowth_role = fpgrowth_first_principles(m_role_txs, min_support=0.10, min_confidence=0.20)

    print("Mining Stage L (Role)...")
    l_apriori_role = apriori_first_principles(l_role_txs, min_support=0.10, min_confidence=0.20)
    l_fpgrowth_role = fpgrowth_first_principles(l_role_txs, min_support=0.10, min_confidence=0.20)

    # Verify Apriori == FP-Growth on all stages
    for name, ap_res, fp_res in [('Stage S', s_apriori_role, s_fpgrowth_role),
                                  ('Stage M', m_apriori_role, m_fpgrowth_role),
                                  ('Stage L', l_apriori_role, l_fpgrowth_role)]:
        assert set(ap_res['frequent_itemsets'].keys()) == set(fp_res['frequent_itemsets'].keys()), f"Itemset mismatch on {name}!"
        ap_rules_set = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in ap_res['rules']}
        fp_rules_set = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in fp_res['rules']}
        assert ap_rules_set == fp_rules_set, f"Rules mismatch on {name}!"
        print(f"[{name}] Apriori == FP-Growth verified (Itemsets: {len(ap_res['frequent_itemsets'])}, Rules: {len(ap_res['rules'])})")

    # --- 6. Track the Six Planted Role Rules Across Stages ---
    print("\n--- Tracking 6 Planted Rules Across Stages S, M, L ---")
    tracked_pairs = [
        ('MAKEUP_REMOVAL', 'CLEANSER'),
        ('CLEANSER', 'TONER'),
        ('CLEANSER', 'TREATMENT'),
        ('SERUM', 'MOISTURIZER'),
        ('MOISTURIZER', 'SUNSCREEN'),
        ('CLEANSER', 'MASK')
    ]

    stages_data = [
        ('Stage_S', 'S20_B30', 30, s_role_df, s_fpgrowth_role),
        ('Stage_M', 'M40_B100', 100, m_role_df, m_fpgrowth_role),
        ('Stage_L', 'L80_B300', 300, l_role_df, l_fpgrowth_role)
    ]

    stability_rows = []
    # Dict to track for deltas: rule -> stage -> metrics
    rule_stage_metrics = {pair: {} for pair in tracked_pairs}

    for stg_id, stg_code, n_b, df_mat, res in stages_data:
        rule_map = {(tuple(r['antecedent']), tuple(r['consequent'])): r for r in res['rules']}
        txs = get_transactions_from_matrix(df_mat)

        for ant, con in tracked_pairs:
            # Direct transaction counts
            cnt_a = sum(1 for tx in txs if ant in tx)
            cnt_b = sum(1 for tx in txs if con in tx)
            cnt_ab = sum(1 for tx in txs if {ant, con}.issubset(tx))

            supp = round(cnt_ab / n_b, 4)
            conf = round(cnt_ab / cnt_a, 4) if cnt_a > 0 else 0.0
            lift = round((cnt_ab * n_b) / (cnt_a * cnt_b), 4) if (cnt_a > 0 and cnt_b > 0) else 0.0

            pass_supp = "YES" if supp >= 0.10 else "NO"
            pass_conf = "YES" if conf >= 0.20 else "NO"
            gen_rule = "YES" if (pass_supp == "YES" and pass_conf == "YES") else "NO"

            ap_status = "Generated" if ((ant,), (con,)) in rule_map else "Filtered"
            fp_status = ap_status

            rule_stage_metrics[(ant, con)][stg_id] = {
                'support': supp,
                'confidence': conf,
                'lift': lift
            }

            stability_rows.append({
                'Rule': f"{ant} -> {con}",
                'Stage': stg_code,
                'N_Baskets': n_b,
                'N_A': cnt_a,
                'N_B': cnt_b,
                'N_AB': cnt_ab,
                'Support': supp,
                'Confidence': conf,
                'Lift': lift,
                'Pass_Support_Threshold': pass_supp,
                'Pass_Confidence_Threshold': pass_conf,
                'Generated_As_Rule': gen_rule,
                'Apriori_Result': ap_status,
                'FPGrowth_Result': fp_status
            })

    # Add Delta columns to stability table
    # For Stage M: Delta S->M; For Stage L: Delta M->L
    for row in stability_rows:
        rule_tuple = tuple(row['Rule'].split(' -> '))
        stg = row['Stage']
        if stg == 'S20_B30':
            row['Delta_Support'] = 0.0
            row['Delta_Confidence'] = 0.0
            row['Delta_Lift'] = 0.0
        elif stg == 'M40_B100':
            s_m = rule_stage_metrics[rule_tuple]['Stage_S']
            row['Delta_Support'] = round(row['Support'] - s_m['support'], 4)
            row['Delta_Confidence'] = round(row['Confidence'] - s_m['confidence'], 4)
            row['Delta_Lift'] = round(row['Lift'] - s_m['lift'], 4)
        elif stg == 'L80_B300':
            m_m = rule_stage_metrics[rule_tuple]['Stage_M']
            row['Delta_Support'] = round(row['Support'] - m_m['support'], 4)
            row['Delta_Confidence'] = round(row['Confidence'] - m_m['confidence'], 4)
            row['Delta_Lift'] = round(row['Lift'] - m_m['lift'], 4)

    df_stability = pd.DataFrame(stability_rows)
    stab_file = os.path.join(SCRIPT_DIR, 'rule_stability.csv')
    df_stability.to_csv(stab_file, index=False)
    print(f"Saved: {stab_file}")

    # --- 7. Discovered Rule Set Jaccard Similarity ---
    print("\n--- Computing Rule Set Jaccard Similarities ---")
    jaccard_rows = [
        {'Pair': 'Stage S (S20_B30) vs Stage M (M40_B100)', 'Jaccard': compute_jaccard(s_fpgrowth_role['rules'], m_fpgrowth_role['rules']),
         'Rules_A_Count': len(s_fpgrowth_role['rules']), 'Rules_B_Count': len(m_fpgrowth_role['rules'])},
        {'Pair': 'Stage M (M40_B100) vs Stage L (L80_B300)', 'Jaccard': compute_jaccard(m_fpgrowth_role['rules'], l_fpgrowth_role['rules']),
         'Rules_A_Count': len(m_fpgrowth_role['rules']), 'Rules_B_Count': len(l_fpgrowth_role['rules'])},
        {'Pair': 'Stage S (S20_B30) vs Stage L (L80_B300)', 'Jaccard': compute_jaccard(s_fpgrowth_role['rules'], l_fpgrowth_role['rules']),
         'Rules_A_Count': len(s_fpgrowth_role['rules']), 'Rules_B_Count': len(l_fpgrowth_role['rules'])}
    ]
    df_jaccard = pd.DataFrame(jaccard_rows)
    jaccard_file = os.path.join(SCRIPT_DIR, 'rule_set_jaccard.csv')
    df_jaccard.to_csv(jaccard_file, index=False)
    print(f"Saved: {jaccard_file}")
    print(df_jaccard)

    # --- 8. SKU-Level Stability Experiment ---
    print("\n--- Running SKU-Level Scaling Across S, M, L ---")
    s_sku_df = pd.read_csv(os.path.join(STEP2_DIR, 'transaction_matrix_sku.csv'), index_col='Basket_ID')
    s_sku_txs = get_transactions_from_matrix(s_sku_df)
    m_sku_txs = get_transactions_from_matrix(m_sku_df)
    l_sku_txs = get_transactions_from_matrix(l_sku_df)

    # Settings: min_support = 2 / N (count >= 2), min_confidence = 0.30
    s_sku_res = fpgrowth_first_principles(s_sku_txs, min_support=2/30, min_confidence=0.30)
    m_sku_res = fpgrowth_first_principles(m_sku_txs, min_support=2/100, min_confidence=0.30)
    l_sku_res = fpgrowth_first_principles(l_sku_txs, min_support=2/300, min_confidence=0.30)

    # Also run Apriori on SKU level to verify consistency
    s_sku_ap = apriori_first_principles(s_sku_txs, min_support=2/30, min_confidence=0.30)
    m_sku_ap = apriori_first_principles(m_sku_txs, min_support=2/100, min_confidence=0.30)
    l_sku_ap = apriori_first_principles(l_sku_txs, min_support=2/300, min_confidence=0.30)

    assert set(s_sku_res['frequent_itemsets'].keys()) == set(s_sku_ap['frequent_itemsets'].keys())
    assert set(m_sku_res['frequent_itemsets'].keys()) == set(m_sku_ap['frequent_itemsets'].keys())
    assert set(l_sku_res['frequent_itemsets'].keys()) == set(l_sku_ap['frequent_itemsets'].keys())
    print("SKU Apriori == FP-Growth verified on all 3 stages.")

    sku_stab_rows = []
    for stage_code, n_b, n_skus, res in [
        ('S20_B30', 30, 20, s_sku_res),
        ('M40_B100', 100, 40, m_sku_res),
        ('L80_B300', 300, 80, l_sku_res)
    ]:
        rules = res['rules']
        uniq_ants = len({tuple(r['antecedent']) for r in rules})
        uniq_cons = len({tuple(r['consequent']) for r in rules})
        sku_stab_rows.append({
            'Stage': stage_code,
            'N_Baskets': n_b,
            'N_SKUs': n_skus,
            'Frequent_Itemsets_Count': len(res['frequent_itemsets']),
            'Total_Rules_Count': len(rules),
            'Unique_Antecedent_SKUs': uniq_ants,
            'Unique_Consequent_SKUs': uniq_cons
        })
    df_sku_stab = pd.DataFrame(sku_stab_rows)
    sku_stab_file = os.path.join(SCRIPT_DIR, 'sku_stability.csv')
    df_sku_stab.to_csv(sku_stab_file, index=False)
    print(f"Saved: {sku_stab_file}")
    print(df_sku_stab)

    # --- 9. Rare High-Lift Rule Follow-Up ---
    print("\n--- Rare High-Lift Rule Follow-Up (SKU 10 -> SKU 93, etc.) ---")
    rare_pairs_followup = [
        (10, 93, "La Roche-Posay Anthelios -> Embryolisse Lait-Crème"),
        (93, 10, "Embryolisse Lait-Crème -> La Roche-Posay Anthelios"),
        (350, 139, "Skin1004 Rau Má -> Klairs Midnight Blue"),
        (728, 112, "Eucerin Gel -> Megaduo Plus"),
        (103, 4365, "L'Oreal Micellar -> Cosrx Low pH Gel")
    ]

    rare_rows = []
    for a_id, c_id, desc in rare_pairs_followup:
        sku_a = f"SKU_{a_id}"
        sku_c = f"SKU_{c_id}"

        for stg_code, n_b, txs_sku, sku_res_cur in [
            ('S20_B30', 30, s_sku_txs, s_sku_res),
            ('M40_B100', 100, m_sku_txs, m_sku_res),
            ('L80_B300', 300, l_sku_txs, l_sku_res)
        ]:
            cnt_a = sum(1 for tx in txs_sku if sku_a in tx)
            cnt_c = sum(1 for tx in txs_sku if sku_c in tx)
            cnt_ac = sum(1 for tx in txs_sku if {sku_a, sku_c}.issubset(tx))

            supp = round(cnt_ac / n_b, 4)
            conf = round(cnt_ac / cnt_a, 4) if cnt_a > 0 else 0.0
            lift = round((cnt_ac * n_b) / (cnt_a * cnt_c), 4) if (cnt_a > 0 and cnt_b > 0 and cnt_ac > 0) else 0.0

            # Check if generated
            rule_map = {(tuple(r['antecedent']), tuple(r['consequent'])): r for r in sku_res_cur['rules']}
            is_gen = "Generated" if ((sku_a,), (sku_c,)) in rule_map else "Filtered/Absent"

            rare_rows.append({
                'Rule': f"{sku_a} -> {sku_c}",
                'Description': desc,
                'Stage': stg_code,
                'N_Baskets': n_b,
                'Count_A': cnt_a,
                'Count_C': cnt_c,
                'Co_Occurrence_Count': cnt_ac,
                'Support': supp,
                'Confidence': conf,
                'Lift': lift,
                'Rule_Status': is_gen
            })

    df_rare = pd.DataFrame(rare_rows)
    rare_file = os.path.join(SCRIPT_DIR, 'rare_rule_followup.csv')
    df_rare.to_csv(rare_file, index=False)
    print(f"Saved: {rare_file}")

    # --- 10. Algorithm Scaling & Runtime Benchmarks (30 runs per stage) ---
    print("\n--- Benchmarking Scaling Runtimes (30 runs per stage) ---")
    N_RUNS = 30
    runtime_records = []

    for stg_label, r_txs, s_txs, min_s_r, min_s_s, min_c_r, min_c_s in [
        ('Stage S (N=30)', s_role_txs, s_sku_txs, 0.10, 2/30, 0.20, 0.30),
        ('Stage M (N=100)', m_role_txs, m_sku_txs, 0.10, 2/100, 0.20, 0.30),
        ('Stage L (N=300)', l_role_txs, l_sku_txs, 0.10, 2/300, 0.20, 0.30)
    ]:
        # Role Apriori
        t_ap_r = []
        for _ in range(N_RUNS):
            t0 = time.perf_counter()
            apriori_first_principles(r_txs, min_support=min_s_r, min_confidence=min_c_r)
            t_ap_r.append((time.perf_counter() - t0) * 1000)

        # Role FP-Growth
        t_fp_r = []
        for _ in range(N_RUNS):
            t0 = time.perf_counter()
            fpgrowth_first_principles(r_txs, min_support=min_s_r, min_confidence=min_c_r)
            t_fp_r.append((time.perf_counter() - t0) * 1000)

        # SKU Apriori
        t_ap_s = []
        for _ in range(N_RUNS):
            t0 = time.perf_counter()
            apriori_first_principles(s_txs, min_support=min_s_s, min_confidence=min_c_s)
            t_ap_s.append((time.perf_counter() - t0) * 1000)

        # SKU FP-Growth
        t_fp_s = []
        for _ in range(N_RUNS):
            t0 = time.perf_counter()
            fpgrowth_first_principles(s_txs, min_support=min_s_s, min_confidence=min_c_s)
            t_fp_s.append((time.perf_counter() - t0) * 1000)

        def add_rec(scope, algo, vals):
            runtime_records.append({
                'Stage': stg_label,
                'Scope': scope,
                'Algorithm': algo,
                'Iterations': len(vals),
                'Mean_ms': round(statistics.mean(vals), 4),
                'Median_ms': round(statistics.median(vals), 4),
                'Min_ms': round(min(vals), 4),
                'Max_ms': round(max(vals), 4),
                'StdDev_ms': round(statistics.stdev(vals), 4)
            })

        add_rec('Role-Level (8 roles)', 'Apriori', t_ap_r)
        add_rec('Role-Level (8 roles)', 'FP-Growth', t_fp_r)
        add_rec('SKU-Level', 'Apriori', t_ap_s)
        add_rec('SKU-Level', 'FP-Growth', t_fp_s)

    df_runtime_scale = pd.DataFrame(runtime_records)
    runtime_scale_file = os.path.join(SCRIPT_DIR, 'algorithm_scaling.csv')
    df_runtime_scale.to_csv(runtime_scale_file, index=False)
    print(f"Saved: {runtime_scale_file}")
    print(df_runtime_scale[['Stage', 'Scope', 'Algorithm', 'Mean_ms', 'Median_ms']])

    # --- 11. Operation Scaling Diagnostics ---
    op_scaling = {
        'Stage_S': {
            'baskets': 30, 'roles': 8, 'skus': 20,
            'role_level': {
                'apriori': {'C1': 8, 'C2': 28, 'C3': 0, 'frequent_itemsets': 15, 'rules': 13},
                'fpgrowth': s_fpgrowth_role['diagnostics']
            },
            'sku_level': {
                'apriori_frequent_itemsets': len(s_sku_res['frequent_itemsets']),
                'apriori_rules': len(s_sku_res['rules']),
                'fpgrowth': s_sku_res['diagnostics']
            }
        },
        'Stage_M': {
            'baskets': 100, 'roles': 8, 'skus': 40,
            'role_level': {
                'apriori': {'frequent_itemsets': len(m_apriori_role['frequent_itemsets']), 'rules': len(m_apriori_role['rules'])},
                'fpgrowth': m_fpgrowth_role['diagnostics']
            },
            'sku_level': {
                'apriori_frequent_itemsets': len(m_sku_res['frequent_itemsets']),
                'apriori_rules': len(m_sku_res['rules']),
                'fpgrowth': m_sku_res['diagnostics']
            }
        },
        'Stage_L': {
            'baskets': 300, 'roles': 8, 'skus': 80,
            'role_level': {
                'apriori': {'frequent_itemsets': len(l_apriori_role['frequent_itemsets']), 'rules': len(l_apriori_role['rules'])},
                'fpgrowth': l_fpgrowth_role['diagnostics']
            },
            'sku_level': {
                'apriori_frequent_itemsets': len(l_sku_res['frequent_itemsets']),
                'apriori_rules': len(l_sku_res['rules']),
                'fpgrowth': l_sku_res['diagnostics']
            }
        }
    }

    op_scale_file = os.path.join(SCRIPT_DIR, 'operation_scaling.json')
    with open(op_scale_file, 'w', encoding='utf-8') as f:
        json.dump(op_scaling, f, ensure_ascii=False, indent=2)
    print(f"Saved: {op_scale_file}")

    print("\n=== Step 5 Experiment Completed Successfully ===")


if __name__ == '__main__':
    main()
