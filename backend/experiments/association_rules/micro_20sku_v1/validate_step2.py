import json
import os
import pandas as pd
from collections import Counter
from pymongo import MongoClient
from dotenv import load_dotenv

base_dir = os.path.dirname(os.path.abspath(__file__))
ATLAS_URI = 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB'

# 1. Load data
with open(os.path.join(base_dir, 'baskets_30.json'), 'r', encoding='utf-8') as f:
    baskets = json.load(f)
with open(os.path.join(base_dir, 'selected_products.json'), 'r', encoding='utf-8') as f:
    products = json.load(f)

sku_matrix = pd.read_csv(os.path.join(base_dir, 'transaction_matrix_sku.csv'), index_col='Basket_ID')
role_matrix = pd.read_csv(os.path.join(base_dir, 'transaction_matrix_role.csv'), index_col='Basket_ID')
with open(os.path.join(base_dir, 'manual_rule_calculations.json'), 'r', encoding='utf-8') as f:
    rules_calc = json.load(f)

approved_skus = set(int(k) for k in products.keys())

# Assertions
assert len(baskets) == 30, f"Expected 30 baskets, got {len(baskets)}"
print("Check 1: Exactly 30 baskets - PASSED")

all_used_skus = set()
for b in baskets:
    for sku in b['items']:
        assert sku in approved_skus, f"SKU {sku} not approved"
        all_used_skus.add(sku)
print("Check 2: Only approved 20 SKUs used - PASSED")

for b in baskets:
    assert len(b['items']) == len(set(b['items'])), f"Duplicate in {b['basket_id']}"
print("Check 3: No duplicate SKU in any basket - PASSED")

sizes = [len(b['items']) for b in baskets]
assert all(1 <= s <= 4 for s in sizes), f"Invalid sizes: {set(sizes)}"
print(f"Size distribution: {Counter(sizes)}")
assert Counter(sizes)[1] == 6 and Counter(sizes)[2] == 12 and Counter(sizes)[3] == 8 and Counter(sizes)[4] == 4
print("Check 4: Basket sizes 1-4 only (6/12/8/4) - PASSED")

all_roles = set()
for b in baskets:
    for r in b['roles']:
        all_roles.add(r)
assert len(all_roles) == 8, f"Expected 8 roles, got {len(all_roles)}"
print("Check 5: All 8 roles represented - PASSED")

assert all_used_skus == approved_skus, f"Missing SKUs: {approved_skus - all_used_skus}"
print("Check 6: Every selected SKU appears at least once - PASSED")

assert set(sku_matrix.values.flatten()).issubset({0, 1}), "SKU matrix has non 0/1"
print("Check 7: SKU matrix values only 0/1 - PASSED")

assert set(role_matrix.values.flatten()).issubset({0, 1}), "Role matrix has non 0/1"
print("Check 8: Role matrix values only 0/1 - PASSED")

# Arithmetic check
for r in rules_calc['role_level_rules']:
    n_a = r['N_A']
    n_b = r['N_B']
    n_ab = r['N_AB']
    supp = round(n_ab / 30, 4)
    conf = round(n_ab / n_a, 4)
    supp_b = round(n_b / 30, 4)
    lift = round(conf / supp_b, 4)
    assert abs(r['Support_AB'] - supp) < 1e-4
    assert abs(r['Confidence'] - conf) < 1e-4
    assert abs(r['Lift'] - lift) < 1e-3
print("Check 9, 10, 11: Manual support, confidence, lift arithmetic verified - PASSED")

# Check MongoDB Atlas
client = MongoClient(ATLAS_URI)
prod_db = client['skinsyntax']
research_db = client['skinsyntax_research_dev']

prod_sp_count = prod_db['san_pham'].count_documents({})
research_baskets_count = research_db['synthetic_baskets'].count_documents({})

print(f"Production san_pham count: {prod_sp_count}")
print(f"Research synthetic_baskets count: {research_baskets_count}")
assert prod_sp_count == 2473, f"Production count altered! Expected 2473, got {prod_sp_count}"
assert research_baskets_count == 30, f"Expected 30 research baskets, got {research_baskets_count}"
print("Check 12 & 13: Production DB counts unchanged (2473), research DB populated - PASSED")
