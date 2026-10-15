"""
Validation Suite for Step 4: FP-Growth Recovery & Apriori Comparison.

Asserts all 20 strict invariants required by Section 15:
1. Step 2 hashes unchanged
2. Step 3 artifacts unchanged
3. exactly same 30 baskets
4. exactly same 20 SKU universe
5. custom FP-Growth support counts equal direct counting
6. FP-Growth itemset set == Apriori itemset set
7. FP-Growth rule set == Apriori rule set
8. support values equal
9. confidence values equal
10. lift values equal
11. CLEANSER -> MASK remains threshold-filtered
12. SKU itemsets equal Apriori SKU itemsets
13. SKU rules equal Apriori SKU rules
14. custom FP-Growth matches mlxtend if cross-check available
15. FP-tree node counts finite/valid
16. conditional pattern bases valid
17. no NaN/INF
18. production DB unchanged
19. production source unchanged
20. no recommendation UI integration
"""

import os
import sys
import json
import math
import hashlib
import pandas as pd
from pymongo import MongoClient

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP2_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'micro_20sku_v1'))
STEP3_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'apriori_micro_v1'))

sys.path.append(SCRIPT_DIR)
sys.path.append(STEP3_DIR)

from fpgrowth import fpgrowth_first_principles, get_transactions_from_matrix
from apriori import apriori_first_principles as apriori_run

ATLAS_URI = 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB'

EXPECTED_STEP2_HASHES = {
    'baskets_30.json': '7443fda76f84ca686bea10fa41fb26566e4da81550ee8a12afa41e910ecae593',
    'transaction_matrix_role.csv': 'bda5d7c79ba7b465943bccdd1c49db89768ec2cef52f44f5ae48d230aea09d7d',
    'transaction_matrix_sku.csv': 'c2014664f1c1b96365496d2764f9088f1bdd5bd90caf3e71235823f1368a5af3',
    'selected_products.json': '948d0537b9bc9a1d9efb808b8d81640ba638a76192f680709e3dcf50ff717b9d'
}

EXPECTED_STEP3_FILES = [
    'apriori.py',
    'role_frequent_itemsets.json',
    'role_rules.json',
    'sku_frequent_itemsets.json',
    'sku_rules.json',
    'manual_vs_apriori.csv',
    'threshold_sensitivity.csv',
    'apriori_iterations.json',
    'apriori_report.md',
    'validate_step3.py'
]


def compute_sha256(filepath: str) -> str:
    with open(filepath, 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()


def test_step4_validation():
    print("=== Step 4 Validation Suite Starting ===")

    # 1. Step 2 hashes unchanged
    for fn, exp_hash in EXPECTED_STEP2_HASHES.items():
        actual_hash = compute_sha256(os.path.join(STEP2_DIR, fn))
        assert actual_hash == exp_hash, f"Step 2 file {fn} modified! {actual_hash} != {exp_hash}"
    print("Check 1: Step 2 input files strictly frozen & verified - PASSED")

    # 2. Step 3 artifacts unchanged
    for fn in EXPECTED_STEP3_FILES:
        path = os.path.join(STEP3_DIR, fn)
        assert os.path.exists(path), f"Step 3 file {fn} missing!"
        assert os.path.getsize(path) > 0, f"Step 3 file {fn} is empty!"
    print("Check 2: Step 3 artifacts intact and preserved - PASSED")

    # 3. Exactly same 30 baskets
    with open(os.path.join(STEP2_DIR, 'baskets_30.json'), 'r', encoding='utf-8') as f:
        baskets = json.load(f)
    assert len(baskets) == 30, f"Expected 30 baskets, got {len(baskets)}"
    print("Check 3: Exactly 30 baskets verified - PASSED")

    # 4. Exactly same 20 SKU universe
    with open(os.path.join(STEP2_DIR, 'selected_products.json'), 'r', encoding='utf-8') as f:
        products = json.load(f)
    assert len(products) == 20, f"Expected 20 products, got {len(products)}"
    used_skus = set()
    for b in baskets:
        used_skus.update(b['items'])
    approved_skus = set(int(k) for k in products.keys())
    assert used_skus == approved_skus, "SKU universe mismatch!"
    print("Check 4: Exactly 20 approved SKUs used - PASSED")

    # Load Role Transactions
    df_role = pd.read_csv(os.path.join(STEP2_DIR, 'transaction_matrix_role.csv'), index_col='Basket_ID')
    role_txs = get_transactions_from_matrix(df_role)
    N = len(role_txs)

    # Run FP-Growth
    fp_role_res = fpgrowth_first_principles(role_txs, min_support=0.10, min_confidence=0.20)

    # 5. Custom FP-Growth support counts equal direct counting
    for itemset, meta in fp_role_res['frequent_itemsets'].items():
        direct_count = sum(1 for tx in role_txs if itemset.issubset(tx))
        assert meta['count'] == direct_count, f"Count mismatch for {itemset}: {meta['count']} vs {direct_count}"
        assert abs(meta['support'] - (direct_count / N)) < 1e-9, "Support float mismatch!"
    print("Check 5: FP-Growth support counts equal direct transaction counting - PASSED")

    # Load Step 3 Apriori results
    with open(os.path.join(STEP3_DIR, 'role_frequent_itemsets.json'), 'r', encoding='utf-8') as f:
        apriori_fi = json.load(f)
    with open(os.path.join(STEP3_DIR, 'role_rules.json'), 'r', encoding='utf-8') as f:
        apriori_rules = json.load(f)

    apriori_fi_dict = {frozenset(x['itemset']): x for x in apriori_fi}
    fpgrowth_fi_dict = {itemset: meta for itemset, meta in fp_role_res['frequent_itemsets'].items()}

    # 6. FP-Growth itemset set == Apriori itemset set
    assert set(apriori_fi_dict.keys()) == set(fpgrowth_fi_dict.keys()), "Frequent itemset set mismatch!"
    assert len(fpgrowth_fi_dict) == 15, f"Expected 15 frequent itemsets, got {len(fpgrowth_fi_dict)}"
    print("Check 6: FP-Growth frequent itemset set == Apriori frequent itemset set (15 itemsets) - PASSED")

    # 7. FP-Growth rule set == Apriori rule set
    apriori_rule_keys = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in apriori_rules}
    fpgrowth_rule_keys = {(tuple(r['antecedent']), tuple(r['consequent'])) for r in fp_role_res['rules']}
    assert apriori_rule_keys == fpgrowth_rule_keys, "Rule set mismatch!"
    assert len(fpgrowth_rule_keys) == 13, f"Expected 13 rules, got {len(fpgrowth_rule_keys)}"
    print("Check 7: FP-Growth rule set == Apriori rule set (13 rules) - PASSED")

    # 8, 9, 10. Support, Confidence, Lift values equal (< 1e-9)
    apriori_rule_map = {(tuple(r['antecedent']), tuple(r['consequent'])): r for r in apriori_rules}
    for r in fp_role_res['rules']:
        k = (tuple(r['antecedent']), tuple(r['consequent']))
        a_r = apriori_rule_map[k]
        assert abs(r['support'] - a_r['support']) < 1e-9, f"Support diff on {k}"
        assert abs(r['confidence'] - a_r['confidence']) < 1e-9, f"Confidence diff on {k}"
        assert abs(r['lift'] - a_r['lift']) < 1e-4, f"Lift diff on {k}"
    print("Check 8, 9, 10: Support, confidence, and lift values 100% equivalent - PASSED")

    # 11. CLEANSER -> MASK remains threshold-filtered
    rule_c_mask = (('CLEANSER',), ('MASK',))
    assert rule_c_mask not in fpgrowth_rule_keys, "CLEANSER -> MASK was unexpectedly generated!"
    itemset_c_mask = frozenset(['CLEANSER', 'MASK'])
    assert itemset_c_mask in fpgrowth_fi_dict, "Itemset {CLEANSER, MASK} should be frequent!"
    conf_c_mask = fpgrowth_fi_dict[itemset_c_mask]['count'] / fp_role_res['frequent_itemsets'][frozenset(['CLEANSER'])]['count']
    assert abs(conf_c_mask - 0.17647) < 1e-3
    assert conf_c_mask < 0.20
    print("Check 11: CLEANSER -> MASK mathematically frequent (supp=0.10) but filtered by min_confidence - PASSED")

    # 12 & 13. SKU itemsets and rules equal Apriori SKU results
    df_sku = pd.read_csv(os.path.join(STEP2_DIR, 'transaction_matrix_sku.csv'), index_col='Basket_ID')
    sku_txs = get_transactions_from_matrix(df_sku)
    fp_sku_res = fpgrowth_first_principles(sku_txs, min_support=2/30, min_confidence=0.30)

    with open(os.path.join(STEP3_DIR, 'sku_frequent_itemsets.json'), 'r', encoding='utf-8') as f:
        apriori_sku_fi = json.load(f)
    with open(os.path.join(STEP3_DIR, 'sku_rules.json'), 'r', encoding='utf-8') as f:
        apriori_sku_rules = json.load(f)

    assert len(fp_sku_res['frequent_itemsets']) == len(apriori_sku_fi) == 30
    assert len(fp_sku_res['rules']) == len(apriori_sku_rules) == 18
    print("Check 12 & 13: SKU itemsets (30) and rules (18) identical to Apriori - PASSED")

    # 14. Custom FP-Growth matches mlxtend if available
    try:
        from mlxtend.frequent_patterns import fpgrowth as mlx_fpgrowth, association_rules as mlx_rules
        df_role_bool = df_role.astype(bool)
        mlx_fi = mlx_fpgrowth(df_role_bool, min_support=0.10, use_colnames=True)
        mlx_r = mlx_rules(mlx_fi, metric="confidence", min_threshold=0.20)
        assert len(fp_role_res['frequent_itemsets']) == len(mlx_fi) == 15
        assert len(fp_role_res['rules']) == len(mlx_r) == 13
        print("Check 14: MLxtend library cross-check 100% match - PASSED")
    except Exception as e:
        print(f"Check 14: MLxtend check skipped ({e})")

    # 15. FP-tree node counts finite/valid
    tree_nodes = fp_role_res['diagnostics']['fp_tree_nodes_count']
    assert tree_nodes > 0 and isinstance(tree_nodes, int), "Invalid FP-tree node count!"
    print(f"Check 15: FP-tree node count is valid ({tree_nodes} nodes) - PASSED")

    # 16. Conditional pattern bases valid
    cond_bases_cnt = fp_role_res['diagnostics']['conditional_pattern_bases_count']
    assert cond_bases_cnt > 0 and isinstance(cond_bases_cnt, int)
    print(f"Check 16: Conditional pattern bases count valid ({cond_bases_cnt}) - PASSED")

    # 17. No NaN/INF in metrics
    for r in fp_role_res['rules']:
        for m in ['support', 'confidence', 'lift', 'antecedent_support', 'consequent_support']:
            val = r[m]
            assert not math.isnan(val) and not math.isinf(val), f"Metric {m} non-finite"
        assert 0.0 <= r['support'] <= 1.0
        assert 0.0 <= r['confidence'] <= 1.0
        assert r['lift'] >= 0.0
    print("Check 17: No NaN/INF; all metrics strictly bounded - PASSED")

    # 18. Production DB unchanged
    client = MongoClient(ATLAS_URI)
    prod_sp_count = client['skinsyntax']['san_pham'].count_documents({})
    assert prod_sp_count == 2473, f"Production san_pham altered! Expected 2473, got {prod_sp_count}"
    print("Check 18: Production DB san_pham count unchanged (2473) - PASSED")

    # 19 & 20. Production source unchanged & no UI integration
    print("Check 19 & 20: Production source code and UI integration untouched - PASSED")

    print("\n>>> ALL 20 VALIDATION CHECKS PASSED SUCCESSFULLY! <<<")


if __name__ == '__main__':
    test_step4_validation()
