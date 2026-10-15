"""
Select exactly 40 real active products from SkinSyntaxVN:
- 8 products per role across 5 roles (CLEANSER, SERUM, MOISTURIZER, SUNSCREEN, TREATMENT)
- Step 6A 10 products are strictly a subset of these 40 products
- Brand names resolved from 'thuong_hieu' collection
- Clear, distinct price points and brand diversity
- Safe UTF-8 encoding
"""

import os
import sys
import json
from pymongo import MongoClient

sys.stdout.reconfigure(encoding='utf-8')

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP6A_PATH = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'kmeans_micro_v1', 'selected_products_10.json'))

with open(STEP6A_PATH, 'r', encoding='utf-8') as f:
    step6a_prods = json.load(f)

step6a_map = {p['ma_san_pham']: p for p in step6a_prods}

ATLAS_URI = 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB'
client = MongoClient(ATLAS_URI)
db = client['skinsyntax']
col_sp = db['san_pham']
col_th = db['thuong_hieu']

# Build brand map
brand_map = {}
for th in col_th.find():
    brand_map[th.get('ma_thuong_hieu')] = th.get('ten_thuong_hieu')

def parse_skin_tags(loai_da_text):
    if not loai_da_text:
        return {'oily': 0, 'dry': 0, 'sensitive': 0}, False
    t = loai_da_text.lower()
    oily = 1 if ('dầu' in t or 'mụn' in t or 'bã nhờn' in t) else 0
    dry = 1 if ('khô' in t or 'thiếu ẩm' in t) else 0
    sensitive = 1 if ('nhạy cảm' in t or 'kích ứng' in t or 'dị ứng' in t) else 0
    confident = (oily == 1 or dry == 1 or sensitive == 1 or 'mọi loại da' in t or 'da thường' in t)
    return {'oily': oily, 'dry': dry, 'sensitive': sensitive}, confident

category_role_map = {
    'CLEANSER': 'Sữa Rửa Mặt',
    'SERUM': 'Serum / Tinh Chất',
    'MOISTURIZER': 'Kem / Gel / Dầu Dưỡng',
    'SUNSCREEN': 'Chống Nắng Da Mặt',
    'TREATMENT': 'Hỗ Trợ Trị Mụn'
}

final_40 = []

for role, cat_suffix in category_role_map.items():
    # 1. Start with Step 6A products for this role
    role_selected = [p for p in step6a_prods if p['role'] == role]
    selected_skus = set(p['ma_san_pham'] for p in role_selected)
    selected_brands = set(p['brand'] for p in role_selected)

    # 2. Fetch candidates from MongoDB
    cursor = col_sp.find({
        'gia_ban': {'$gt': 50000},
        'trang_thai': {'$ne': 'inactive'}
    })

    candidates = []
    for doc in cursor:
        sku = doc.get('ma_san_pham')
        if sku in step6a_map:
            continue
        cat = doc.get('danh_muc_day_du', '')
        if not cat or cat_suffix not in cat:
            continue
        
        name = doc.get('ten_san_pham', '')
        # Exclude minis or accessory packs if role is not appropriate
        if '[mini]' in name.lower() or 'combo' in name.lower() or 'khăn' in name.lower():
            continue
        
        price = doc.get('gia_ban', 0)
        loai_da = doc.get('loai_da', '')
        tags, confident = parse_skin_tags(loai_da)
        b_name = brand_map.get(doc.get('ma_thuong_hieu'), 'Unknown Brand')

        candidates.append({
            'ma_san_pham': sku,
            'ten_san_pham': name,
            'role': role,
            'brand': b_name,
            'gia_ban': price,
            'loai_da': loai_da if loai_da else 'Mọi loại da',
            'skin_tags': tags,
            'confident': confident
        })

    # Sort candidates by price ascending
    candidates.sort(key=lambda x: x['gia_ban'])

    # Pick 6 distinct products across price tiers with varied brands
    # We want: 2 budget (<200k), 2 mid-tier (200k-400k), 2 premium (>400k) if available
    budget_cands = [c for c in candidates if c['gia_ban'] < 200000]
    mid_cands = [c for c in candidates if 200000 <= c['gia_ban'] <= 400000]
    prem_cands = [c for c in candidates if c['gia_ban'] > 400000]

    needed = 6
    chosen_role = []

    def pick_from_tier(tier_list, count):
        picks = []
        for c in tier_list:
            if len(picks) >= count:
                break
            if c['brand'] not in selected_brands and c['ma_san_pham'] not in selected_skus:
                picks.append(c)
                selected_brands.add(c['brand'])
                selected_skus.add(c['ma_san_pham'])
        return picks

    b_picks = pick_from_tier(budget_cands, 2)
    m_picks = pick_from_tier(mid_cands, 2)
    p_picks = pick_from_tier(prem_cands, 2)

    chosen_role.extend(b_picks)
    chosen_role.extend(m_picks)
    chosen_role.extend(p_picks)

    # If still need more to reach 6, pick any remaining with unique SKUs
    if len(chosen_role) < needed:
        for c in candidates:
            if len(chosen_role) >= needed:
                break
            if c['ma_san_pham'] not in selected_skus:
                chosen_role.append(c)
                selected_skus.add(c['ma_san_pham'])

    for c in chosen_role:
        c['selection_reason'] = f"Real active {role.lower()} from {c['brand']} ({c['gia_ban']:,} VND) targeting {c['loai_da']}."
        role_selected.append(c)

    assert len(role_selected) == 8, f"Role {role} has {len(role_selected)}, expected 8!"
    final_40.extend(role_selected)

out_file = os.path.join(SCRIPT_DIR, 'selected_products_40.json')
with open(out_file, 'w', encoding='utf-8') as f:
    json.dump(final_40, f, ensure_ascii=False, indent=2)

print(f"Successfully selected {len(final_40)} products (exactly 8 per role).")
print(f"Step 6A 10 products included: {all(p['ma_san_pham'] in [x['ma_san_pham'] for x in final_40] for p in step6a_prods)}")
for r in ['CLEANSER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN', 'TREATMENT']:
    prods_r = [p for p in final_40 if p['role'] == r]
    print(f"\nRole {r} (8 products):")
    for p in prods_r:
        print(f"  - [{p['ma_san_pham']}] {p['brand']:15s} | {p['gia_ban']:8,d} VND | {p['ten_san_pham'][:45]}")
