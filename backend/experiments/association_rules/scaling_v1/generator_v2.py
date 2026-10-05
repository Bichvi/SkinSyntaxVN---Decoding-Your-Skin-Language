"""
Synthetic Basket Generator V2 for SkinSyntaxVN Progressive Scaling Experiment.

Stages:
- Stage M: 40 SKUs, 100 Baskets (Seed 43, Scenario: association_scale_40sku_100basket_v1)
- Stage L: 80 SKUs, 300 Baskets (Seed 44, Scenario: association_scale_80sku_300basket_v1)

Design:
- Planted routine tendencies (experimental assumptions, weakened progressively)
- Background noise & exploratory baskets
- Sizes 1 to 5 items, strictly no duplicate SKUs within any basket
- Output: products JSON, baskets JSON, binary role and SKU CSV matrices, generator_config.json
"""

import os
import sys
import json
import random
import pandas as pd
from datetime import datetime, timezone
from pymongo import MongoClient
from typing import Dict, List, Any, Set, Tuple

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STEP2_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', 'micro_20sku_v1'))


def load_s20_products() -> Dict[str, Any]:
    with open(os.path.join(STEP2_DIR, 'selected_products.json'), 'r', encoding='utf-8') as f:
        return json.load(f)


def select_nested_products(atlas_uri: str) -> Tuple[Dict[str, Any], Dict[str, Any]]:
    """Select 40 SKUs for Stage M and 80 SKUs for Stage L, ensuring S20 ⊂ M40 ⊂ L80."""
    client = MongoClient(atlas_uri)
    db = client['skinsyntax']
    col = db['san_pham']
    brand_col = db['thuong_hieu']
    brands = {b['ma_thuong_hieu']: b.get('ten_thuong_hieu', 'SkinSyntax') for b in brand_col.find()}

    s20_dict = load_s20_products()
    s20_ids = set(int(k) for k in s20_dict.keys())

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

    # 1. Build Stage M (40 SKUs = 5 per role)
    stage_m_products = dict(s20_dict)
    new_m_skus = {}
    for role, q_base in queries.items():
        current_cnt = sum(1 for p in stage_m_products.values() if p['role'] == role)
        needed = 5 - current_cnt
        if needed <= 0:
            continue
        q = dict(q_base)
        q['ma_san_pham'] = {'$nin': list(s20_ids)}
        cursor = col.find(q).sort('luot_xem', -1)
        added = 0
        for doc in cursor:
            sku_id = doc.get('ma_san_pham')
            if sku_id in s20_ids or str(sku_id) in new_m_skus:
                continue
            b_name = brands.get(doc.get('ma_thuong_hieu'), 'SkinSyntax')
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

    # 2. Build Stage L (80 SKUs = 10 per role)
    stage_l_products = dict(stage_m_products)
    m_ids = set(int(k) for k in stage_m_products.keys())
    new_l_skus = {}
    for role, q_base in queries.items():
        current_cnt = sum(1 for p in stage_l_products.values() if p['role'] == role)
        needed = 10 - current_cnt
        if needed <= 0:
            continue
        q = dict(q_base)
        q['ma_san_pham'] = {'$nin': list(m_ids)}
        cursor = col.find(q).sort('luot_xem', -1)
        added = 0
        for doc in cursor:
            sku_id = doc.get('ma_san_pham')
            if sku_id in m_ids or str(sku_id) in new_l_skus:
                continue
            b_name = brands.get(doc.get('ma_thuong_hieu'), 'SkinSyntax')
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

    # Strict validations
    assert len(s20_dict) == 20
    assert len(stage_m_products) == 40
    assert len(stage_l_products) == 80
    assert set(int(k) for k in s20_dict.keys()).issubset(set(int(k) for k in stage_m_products.keys()))
    assert set(int(k) for k in stage_m_products.keys()).issubset(set(int(k) for k in stage_l_products.keys()))

    return stage_m_products, stage_l_products


def generate_baskets_stage(
    stage_name: str,
    scenario: str,
    n_baskets: int,
    products_dict: Dict[str, Any],
    seed: int,
    size_weights: Dict[int, float],
    routine_prob: float,
    noise_prob: float,
    exploration_prob: float
) -> Tuple[List[Dict[str, Any]], pd.DataFrame, pd.DataFrame]:
    """
    Probabilistic basket generator with controlled progressive weakening of tendencies.
    """
    random.seed(seed)
    roles = ['MAKEUP_REMOVAL', 'CLEANSER', 'TONER', 'SERUM', 'TREATMENT', 'MOISTURIZER', 'SUNSCREEN', 'MASK']

    # Group SKUs by role
    role_skus: Dict[str, List[int]] = {r: [] for r in roles}
    for sku_str, p in products_dict.items():
        role_skus[p['role']].append(int(sku_str))

    # Core planted routine chains (probabilities conditioned on routine mode)
    planted_pairs = [
        ('MAKEUP_REMOVAL', 'CLEANSER', 'DOUBLE_CLEANSING'),
        ('CLEANSER', 'TONER', 'CLEANSE_TONE'),
        ('CLEANSER', 'TREATMENT', 'ACNE_TARGET'),
        ('SERUM', 'MOISTURIZER', 'BARRIER_REPAIR'),
        ('MOISTURIZER', 'SUNSCREEN', 'MORNING_DEFENSE'),
        ('CLEANSER', 'MASK', 'DEEP_PURIFYING')
    ]

    triplet_chains = [
        (['MAKEUP_REMOVAL', 'CLEANSER', 'TONER'], 'FULL_CLEANSING_TONE'),
        (['CLEANSER', 'TREATMENT', 'MOISTURIZER'], 'ACNE_CARE_CYCLE'),
        (['TONER', 'SERUM', 'MOISTURIZER'], 'HYDRATING_TRIO'),
        (['SERUM', 'MOISTURIZER', 'SUNSCREEN'], 'DAY_HYDRATION_PROTECT'),
        (['CLEANSER', 'MASK', 'MOISTURIZER'], 'DEEP_REPAIR')
    ]

    quad_chains = [
        (['MAKEUP_REMOVAL', 'CLEANSER', 'TONER', 'SUNSCREEN'], 'MORNING_FULL_ROUTINE'),
        (['MAKEUP_REMOVAL', 'CLEANSER', 'SERUM', 'MOISTURIZER'], 'EVENING_REPAIR_ROUTINE'),
        (['CLEANSER', 'TREATMENT', 'MASK', 'MOISTURIZER'], 'ACNE_INTENSIVE_CARE'),
        (['TONER', 'SERUM', 'MOISTURIZER', 'SUNSCREEN'], 'SOOTHING_DAY_REGIMEN')
    ]

    baskets = []
    sizes_pool = list(size_weights.keys())
    size_probs = [size_weights[s] for s in sizes_pool]

    for b_idx in range(1, n_baskets + 1):
        basket_id = f"{stage_name[0].upper()}{b_idx:03d}"
        basket_size = random.choices(sizes_pool, weights=size_probs, k=1)[0]
        
        mode = random.choices(['ROUTINE', 'NOISE', 'EXPLORATION'], weights=[routine_prob, noise_prob, exploration_prob], k=1)[0]
        
        chosen_roles = []
        pattern_applied = "SPONTANEOUS_SINGLE"
        noise_applied = "No"

        if basket_size == 1:
            chosen_roles = [random.choice(roles)]
            pattern_applied = "SPONTANEOUS_SINGLE"
            noise_applied = "Yes"
        elif mode == 'EXPLORATION':
            # Pure exploration: random distinct roles
            chosen_roles = random.sample(roles, k=min(basket_size, len(roles)))
            pattern_applied = "PURE_EXPLORATION"
            noise_applied = "Yes"
        elif mode == 'NOISE':
            # Background noise: randomly combine roles without respecting routine affinities
            chosen_roles = random.sample(roles, k=min(basket_size, len(roles)))
            pattern_applied = "BACKGROUND_NOISE"
            noise_applied = "Yes"
        else: # ROUTINE mode
            if basket_size == 2:
                pair = random.choice(planted_pairs)
                chosen_roles = [pair[0], pair[1]]
                pattern_applied = pair[2]
                noise_applied = "No"
            elif basket_size == 3:
                chain = random.choice(triplet_chains)
                chosen_roles = list(chain[0])
                pattern_applied = chain[1]
                noise_applied = "No"
            elif basket_size == 4:
                chain = random.choice(quad_chains)
                chosen_roles = list(chain[0])
                pattern_applied = chain[1]
                noise_applied = "No"
            elif basket_size >= 5:
                # Comprehensive 5-item regimen: Quad + 1 random distinct role
                chain = random.choice(quad_chains)
                remaining_roles = [r for r in roles if r not in chain[0]]
                chosen_roles = list(chain[0]) + [random.choice(remaining_roles)]
                pattern_applied = "COMPREHENSIVE_REGIMEN"
                noise_applied = "Yes"

        # Map chosen roles to concrete SKUs (probabilistic SKU-level variation)
        chosen_skus = []
        for r in chosen_roles:
            available_skus = [s for s in role_skus[r] if s not in chosen_skus]
            if available_skus:
                chosen_skus.append(random.choice(available_skus))

        # Ensure no duplicates
        chosen_skus = list(dict.fromkeys(chosen_skus))

        # Enriched basket metadata
        baskets.append({
            'basket_id': basket_id,
            'items': chosen_skus,
            'item_names': [products_dict[str(s)]['ten_san_pham'] for s in chosen_skus],
            'roles': [products_dict[str(s)]['role'] for s in chosen_skus],
            'basket_size': len(chosen_skus),
            'pattern_applied': pattern_applied,
            'noise_applied': noise_applied,
            'source': 'synthetic_seed',
            'scenario': scenario,
            'generator_version': 'scaling-v2',
            'seed': seed,
            'created_at': datetime.now(timezone.utc).isoformat()
        })

    # Construct Binary Matrices
    sku_columns = [f"SKU_{s}" for s in sorted(list(products_dict.keys()), key=lambda x: int(x))]
    all_sku_ids = [int(s) for s in sorted(list(products_dict.keys()), key=lambda x: int(x))]
    role_columns = roles

    sku_matrix_data = []
    role_matrix_data = []

    for b in baskets:
        b_skus = set(b['items'])
        b_roles = set(b['roles'])
        sku_row = [1 if s in b_skus else 0 for s in all_sku_ids]
        role_row = [1 if r in b_roles else 0 for r in role_columns]
        sku_matrix_data.append(sku_row)
        role_matrix_data.append(role_row)

    basket_ids = [b['basket_id'] for b in baskets]
    df_sku = pd.DataFrame(sku_matrix_data, index=basket_ids, columns=sku_columns)
    df_sku.index.name = 'Basket_ID'

    df_role = pd.DataFrame(role_matrix_data, index=basket_ids, columns=role_columns)
    df_role.index.name = 'Basket_ID'

    return baskets, df_role, df_sku
