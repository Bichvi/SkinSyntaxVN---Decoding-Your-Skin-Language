import json
import os
from pymongo import MongoClient

client = MongoClient('mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB')
db = client['skinsyntax']
col = db['san_pham']

# Load S20 products
s20_path = os.path.abspath('backend/experiments/association_rules/micro_20sku_v1/selected_products.json')
with open(s20_path, 'r', encoding='utf-8') as f:
    s20_dict = json.load(f)

s20_ids = set(int(k) for k in s20_dict.keys())
print(f"Stage S (S20) count: {len(s20_ids)}")

# Roles and their MongoDB criteria
# Role 1: MAKEUP_REMOVAL (ma_danh_muc: 2)
# Role 2: CLEANSER (ma_danh_muc: 1)
# Role 3: TONER (ma_danh_muc: 4)
# Role 4: SERUM (ma_danh_muc: 11 with Serum/Tinh Chất)
# Role 5: TREATMENT (ma_danh_muc: 25)
# Role 6: MOISTURIZER (ma_danh_muc: 7)
# Role 7: SUNSCREEN (ma_danh_muc: 6)
# Role 8: MASK (ma_danh_muc: 11 with Mặt Nạ or ma_danh_muc: 9/30)

queries = {
    'MAKEUP_REMOVAL': {'ma_danh_muc': 2, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'CLEANSER': {'ma_danh_muc': 1, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'TONER': {'ma_danh_muc': 4, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'SERUM': {'ma_danh_muc': 9, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'TREATMENT': {'ma_danh_muc': 25, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'MOISTURIZER': {'ma_danh_muc': 7, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'SUNSCREEN': {'ma_danh_muc': 6, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}},
    'MASK': {'ma_danh_muc': 11, 'danh_muc_day_du': {'$regex': 'M.*t N.*', '$options': 'i'}, 'trang_thai': 'active', 'gia_ban': {'$gt': 0}}
}

print("\nCatalog counts by role:")
for role, q in queries.items():
    cnt = col.count_documents(q)
    s20_count = sum(1 for p in s20_dict.values() if p['role'] == role)
    print(f"  {role:15s}: Total in DB = {cnt:4d} | Current S20 = {s20_count}")

# Select additional SKUs:
# For Stage M: 5 SKUs per role (Total 40 = 8 roles * 5)
# For Stage L: 10 SKUs per role (Total 80 = 8 roles * 10)

stage_m_products = dict(s20_dict) # start with S20
stage_l_products = dict()

# Brand lookup
brand_col = db['thuong_hieu']
brands = {b['ma_thuong_hieu']: b.get('ten_thuong_hieu', 'Unknown') for b in brand_col.find()}

m_needed_per_role = {}
for role in queries:
    current_count = sum(1 for p in stage_m_products.values() if p['role'] == role)
    m_needed_per_role[role] = 5 - current_count

print("\nStage M additions needed per role (to reach 5 per role):", m_needed_per_role)

new_m_skus = {}
for role, needed in m_needed_per_role.items():
    if needed <= 0:
        continue
    q = dict(queries[role])
    q['ma_san_pham'] = {'$nin': list(s20_ids)}
    # Find diverse brands
    cursor = col.find(q).sort('luot_xem', -1)
    added = 0
    used_brands = {p.get('brand') for p in stage_m_products.values() if p['role'] == role}
    for doc in cursor:
        b_name = brands.get(doc.get('ma_thuong_hieu'), 'SkinSyntax')
        sku_id = doc.get('ma_san_pham')
        if sku_id in s20_ids or sku_id in new_m_skus:
            continue
        new_m_skus[str(sku_id)] = {
            'ten_san_pham': doc.get('ten_san_pham'),
            'role': role,
            'brand': b_name,
            'ma_danh_muc': doc.get('ma_danh_muc'),
            'danh_muc': doc.get('danh_muc_day_du', '').split('->')[-1].strip(),
            'gia_ban': doc.get('gia_ban'),
            'loai_da': doc.get('loai_da', 'Mọi loại da')
        }
        added += 1
        if added >= needed:
            break

stage_m_products.update(new_m_skus)
print(f"Total Stage M SKUs: {len(stage_m_products)}")
for role in queries:
    cnt = sum(1 for p in stage_m_products.values() if p['role'] == role)
    print(f"  Stage M {role}: {cnt}")

# For Stage L: need 5 more per role (to reach 10 per role, total 80)
stage_l_products = dict(stage_m_products)
m_ids = set(int(k) for k in stage_m_products.keys())

new_l_skus = {}
for role in queries:
    current_count = sum(1 for p in stage_l_products.values() if p['role'] == role)
    needed = 10 - current_count
    if needed <= 0:
        continue
    q = dict(queries[role])
    q['ma_san_pham'] = {'$nin': list(m_ids) + list(new_l_skus.keys())}
    cursor = col.find(q).sort('luot_xem', -1)
    added = 0
    for doc in cursor:
        b_name = brands.get(doc.get('ma_thuong_hieu'), 'SkinSyntax')
        sku_id = doc.get('ma_san_pham')
        if sku_id in m_ids or str(sku_id) in new_l_skus:
            continue
        new_l_skus[str(sku_id)] = {
            'ten_san_pham': doc.get('ten_san_pham'),
            'role': role,
            'brand': b_name,
            'ma_danh_muc': doc.get('ma_danh_muc'),
            'danh_muc': doc.get('danh_muc_day_du', '').split('->')[-1].strip(),
            'gia_ban': doc.get('gia_ban'),
            'loai_da': doc.get('loai_da', 'Mọi loại da')
        }
        added += 1
        if added >= needed:
            break

stage_l_products.update(new_l_skus)
print(f"\nTotal Stage L SKUs: {len(stage_l_products)}")
for role in queries:
    cnt = sum(1 for p in stage_l_products.values() if p['role'] == role)
    print(f"  Stage L {role}: {cnt}")

# Verify subset property
s_set = set(int(k) for k in s20_dict.keys())
m_set = set(int(k) for k in stage_m_products.keys())
l_set = set(int(k) for k in stage_l_products.keys())

assert s_set.issubset(m_set), "S is not a subset of M!"
assert m_set.issubset(l_set), "M is not a subset of L!"
assert len(s_set) == 20
assert len(m_set) == 40
assert len(l_set) == 80
print("\nNested subset verification: S (20) ⊂ M (40) ⊂ L (80) - PASSED!")
