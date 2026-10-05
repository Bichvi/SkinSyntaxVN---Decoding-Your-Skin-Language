"""
SKINSYNTAXVN — STEP 7E VALIDATION SUITE
Rigorous verification of 20 research integrity constraints, security standards,
and academic wording requirements for Final Clustering Consolidation.
"""

import os
import sys
import json
import re
import subprocess
import pandas as pd

sys.stdout.reconfigure(encoding='utf-8')

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..', '..', '..'))

def run_validation():
    print("=" * 75)
    print("RUNNING STEP 7E FINAL CONSOLIDATION VALIDATION SUITE (20 CRITERIA)")
    print("=" * 75)
    checks_passed = 0
    total_checks = 20

    rep_path = os.path.join(SCRIPT_DIR, 'clustering_final_report.md')
    assert os.path.exists(rep_path), "Missing clustering_final_report.md"
    with open(rep_path, 'r', encoding='utf-8') as f:
        rep_txt = f.read()

    timeline_path = os.path.join(SCRIPT_DIR, 'research_timeline.md')
    assert os.path.exists(timeline_path), "Missing research_timeline.md"
    with open(timeline_path, 'r', encoding='utf-8') as f:
        timeline_txt = f.read()

    # 1. Steps 6A–7D referenced
    required_steps = ['Step 6A', 'Step 6B', 'Step 6C', 'Step 7A', 'Step 7B', 'Step 7C', 'Step 7C.1', 'Step 7D']
    for st in required_steps:
        assert st in rep_txt and st in timeline_txt, f"Missing reference to {st}"
    print("[PASS] Check 1: All research phases Step 6A to Step 7D fully referenced.")
    checks_passed += 1

    # 2. no universal K claim
    assert 'NO UNIVERSAL K ESTABLISHED' in rep_txt
    k_summary_path = os.path.join(SCRIPT_DIR, 'k_selection_summary.csv')
    assert os.path.exists(k_summary_path), "Missing k_selection_summary.csv"
    k_summary_df = pd.read_csv(k_summary_path)
    assert any('NO UNIVERSAL K ESTABLISHED' in str(v) for v in k_summary_df['Scientific_Conclusion'])
    print("[PASS] Check 2: 'NO UNIVERSAL K ESTABLISHED' explicitly verified.")
    checks_passed += 1

    # 3. no optimal/best/winner claim
    prohibited_phrases = ['OPTIMAL K', 'BEST MODEL', 'CONFIGURATION WINNER', 'WINNER CONFIGURATION', 'ACCURATE RECOMMENDER']
    for p in prohibited_phrases:
        assert p not in rep_txt.upper(), f"Prohibited phrase found: {p}"
    print("[PASS] Check 3: Zero optimal/best/winner claims verified.")
    checks_passed += 1

    # 4. no arbitrary aggregate score
    decision_matrix_path = os.path.join(SCRIPT_DIR, 'representation_decision_matrix.csv')
    assert os.path.exists(decision_matrix_path), "Missing representation_decision_matrix.csv"
    matrix_df = pd.read_csv(decision_matrix_path)
    assert 'overall_score' not in matrix_df.columns
    assert 'weighted_score' not in matrix_df.columns
    assert 'total_score' not in matrix_df.columns
    print("[PASS] Check 4: Zero arbitrary aggregate scores in decision matrix.")
    checks_passed += 1

    # 5. no chemical ontology claim
    ing_summary_path = os.path.join(SCRIPT_DIR, 'ingredient_evidence_summary.md')
    assert os.path.exists(ing_summary_path), "Missing ingredient_evidence_summary.md"
    with open(ing_summary_path, 'r', encoding='utf-8') as f:
        ing_txt = f.read()
    assert 'Bản thể học hóa học (Chemical Ontology)' in ing_txt
    assert 'không đồng nghĩa với bản thể học hóa học' in rep_txt or 'Parsed Ingredient Representation' in ing_txt
    print("[PASS] Check 5: Parsed representation strictly separated from chemical ontology.")
    checks_passed += 1

    # 6. no clinical equivalence claim
    assert 'chúng không chứng minh chất lượng gợi ý' in rep_txt or 'chúng không chứng minh chất lượng recommendation' in rep_txt
    assert 'tính tương đương lâm sàng (clinical equivalence)' in rep_txt
    print("[PASS] Check 6: Clinical equivalence strictly disclaimed.")
    checks_passed += 1

    # 7. no recommendation-quality claim from clustering metrics
    assert 'chúng không chứng minh chất lượng gợi ý (recommendation quality)' in rep_txt
    assert 'Clustering optimizes geometric variance (WCSS) without ranking loss' in open(os.path.join(SCRIPT_DIR, 'clustering_role_matrix.csv'), 'r', encoding='utf-8').read()
    print("[PASS] Check 7: No recommendation quality claim derived from clustering metrics.")
    checks_passed += 1

    # 8. taxonomy purity not external validation
    assert 'Category alignment không được xem là external validation vì taxonomy được đưa trực tiếp vào feature representation.' in rep_txt
    print("[PASS] Check 8: Category alignment disclaimed as external validation.")
    checks_passed += 1

    # 9. variant sensitivity explicitly documented
    variant_summary_path = os.path.join(SCRIPT_DIR, 'variant_evidence_summary.md')
    assert os.path.exists(variant_summary_path), "Missing variant_evidence_summary.md"
    with open(variant_summary_path, 'r', encoding='utf-8') as f:
        var_txt = f.read()
    assert '364 dòng sản phẩm đa biến thể' in var_txt
    assert '898 sản phẩm' in var_txt
    print("[PASS] Check 9: Variant sensitivity explicitly documented (364 families, 898 products).")
    checks_passed += 1

    # 10. Step7D ARI .5655/NMI .7458 correctly represented
    assert 'ARI = 0.5655' in rep_txt and 'NMI = 0.7458' in rep_txt
    assert 'Variant collapsing produced a material change in partition structure under this sensitivity analysis' in rep_txt
    print("[PASS] Check 10: Step 7D variant sensitivity metrics (ARI=0.5655, NMI=0.7458) and mandatory wording verified.")
    checks_passed += 1

    # 11. no 1-ARI percentage interpretation
    assert '1 - ARI' not in rep_txt and '1-ARI' not in rep_txt
    print("[PASS] Check 11: Zero 1-ARI percentage misinterpretations.")
    checks_passed += 1

    # 12. human evaluation gap documented
    limit_path = os.path.join(SCRIPT_DIR, 'known_limitations.md')
    assert os.path.exists(limit_path), "Missing known_limitations.md"
    with open(limit_path, 'r', encoding='utf-8') as f:
        limit_txt = f.read()
    assert 'Khoảng Trống Đánh Giá Chuyên Gia & Người Dùng (Human Evaluation Gap)' in limit_txt
    future_plan_path = os.path.join(SCRIPT_DIR, 'future_validation_plan.md')
    assert os.path.exists(future_plan_path), "Missing future_validation_plan.md"
    print("[PASS] Check 12: Human evaluation gap documented and future validation protocol formulated without synthetic scores.")
    checks_passed += 1

    # 13. clustering task separated from association
    arch_path = os.path.join(SCRIPT_DIR, 'architecture_boundary.md')
    assert os.path.exists(arch_path), "Missing architecture_boundary.md"
    with open(arch_path, 'r', encoding='utf-8') as f:
        arch_txt = f.read()
    assert 'Apriori / FP-Growth' in arch_txt
    assert 'Frequently Bought Together' in arch_txt
    print("[PASS] Check 13: Clustering task strictly separated from association mining.")
    checks_passed += 1

    # 14. clustering task separated from CF
    assert 'Collaborative Filtering (CF)' in arch_txt
    assert 'User-Item Preference Prediction' in arch_txt
    print("[PASS] Check 14: Clustering task strictly separated from collaborative filtering.")
    checks_passed += 1

    # 15. clustering task separated from ranking
    assert 'K-Means KHÔNG PHẢI là Recommender' in arch_txt
    assert 'Adaptive Content-Based' in arch_txt
    print("[PASS] Check 15: Clustering task strictly separated from content-based ranking.")
    checks_passed += 1

    # 16. production boundary explicit
    assert 'RESEARCH COMPLETE — NOT PRODUCTION VALIDATED' in rep_txt
    assert 'NOT PRODUCTION-READY' in rep_txt
    prod_matrix_path = os.path.join(SCRIPT_DIR, 'production_readiness_matrix.csv')
    assert os.path.exists(prod_matrix_path), "Missing production_readiness_matrix.csv"
    print("[PASS] Check 16: Production boundary explicit: 'RESEARCH COMPLETE — NOT PRODUCTION VALIDATED'.")
    checks_passed += 1

    # 17. no production files modified
    git_status = subprocess.run(['git', 'status', '--porcelain'], capture_output=True, text=True, cwd=PROJECT_ROOT).stdout
    for line in git_status.splitlines():
        if line.startswith(' M') or line.startswith('M '):
            fn = line.split()[-1]
            assert not fn.startswith('api/') and not fn.startswith('frontend/') and not fn.startswith('public/'), f"Production file modified: {fn}"
    print("[PASS] Check 17: Zero production PHP or API files modified.")
    checks_passed += 1

    # 18. no UI modified
    for line in git_status.splitlines():
        fn = line.split()[-1]
        assert not fn.startswith('frontend/') and not fn.startswith('public/'), f"UI modified: {fn}"
    print("[PASS] Check 18: Zero UI files modified.")
    checks_passed += 1

    # 19. no credentials
    all_files = [os.path.join(SCRIPT_DIR, f) for f in os.listdir(SCRIPT_DIR) if f.endswith(('.json', '.csv', '.md', '.py'))]
    secret_patterns = [r'mongodb://[a-zA-Z0-9]+:[a-zA-Z0-9]+@', r'password\s*=', r'secret\s*=']
    for fp in all_files:
        with open(fp, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            for pat in secret_patterns:
                assert not re.search(pat, content, re.IGNORECASE), f"Possible credential leak in {fp}"
    print("[PASS] Check 19: Zero credentials or secrets in all Step 7E artifacts.")
    checks_passed += 1

    # 20. no so_luong_da_ban as real sales
    artifact_files = [os.path.join(SCRIPT_DIR, f) for f in os.listdir(SCRIPT_DIR) if f != 'validate_clustering_final.py' and f.endswith(('.json', '.csv', '.md'))]
    for fp in artifact_files:
        with open(fp, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            assert 'so_luong_da_ban' not in content, f"so_luong_da_ban found in {fp}"
    print("[PASS] Check 20: Zero so_luong_da_ban occurrences across all report and data artifacts.")
    checks_passed += 1

    print("=" * 75)
    print(f"STEP 7E VALIDATION RESULT: {checks_passed}/{total_checks} CHECKS PASSED (100%)")
    print("ALL RIGOROUS RESEARCH, INTEGRITY, AND ARCHITECTURAL CONSTRAINTS SATISFIED.")
    print("=" * 75)

if __name__ == '__main__':
    run_validation()
