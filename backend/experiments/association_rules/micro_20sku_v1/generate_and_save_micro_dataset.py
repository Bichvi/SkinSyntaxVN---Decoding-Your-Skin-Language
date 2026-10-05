import os
import sys
import io
import json
import csv
import pymongo
from datetime import datetime, timezone

# Ensure utf-8 encoding for file output
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

SKU_INFO = {
    5: {
        'ten_san_pham': "Nước Tẩy Trang Bioderma Dành Cho Da Nhạy Cảm 500ml",
        'role': 'MAKEUP_REMOVAL',
        'brand': 'Bioderma',
        'ma_danh_muc': 2,
        'danh_muc': 'Tẩy Trang Mặt',
        'gia_ban': 335000,
        'loai_da': 'Da nhạy cảm'
    },
    103: {
        'ten_san_pham': "Nước Tẩy Trang L'Oreal Làm Sạch Sâu Cho Da Dầu 400ml",
        'role': 'MAKEUP_REMOVAL',
        'brand': "L'Oreal",
        'ma_danh_muc': 2,
        'danh_muc': 'Tẩy Trang Mặt',
        'gia_ban': 167000,
        'loai_da': 'Da dầu/Hỗn hợp dầu'
    },
    4365: {
        'ten_san_pham': "Gel Rửa Mặt Cosrx Tràm Trà, 0.5% BHA Có Độ pH Thấp 150ml",
        'role': 'CLEANSER',
        'brand': 'Cosrx',
        'ma_danh_muc': 1,
        'danh_muc': 'Sữa Rửa Mặt',
        'gia_ban': 129000,
        'loai_da': 'Da thường/Mọi loại da'
    },
    21: {
        'ten_san_pham': "Gel Rửa Mặt La Roche-Posay Dành Cho Da Dầu, Nhạy Cảm 400ml",
        'role': 'CLEANSER',
        'brand': 'La Roche-Posay',
        'ma_danh_muc': 1,
        'danh_muc': 'Sữa Rửa Mặt',
        'gia_ban': 412000,
        'loai_da': 'Da dầu/Hỗn hợp dầu'
    },
    728: {
        'ten_san_pham': "Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 200ml",
        'role': 'CLEANSER',
        'brand': 'Eucerin',
        'ma_danh_muc': 1,
        'danh_muc': 'Sữa Rửa Mặt',
        'gia_ban': 295000,
        'loai_da': 'Da dầu/Hỗn hợp dầu'
    },
    4: {
        'ten_san_pham': "Nước Hoa Hồng Klairs Không Mùi Cho Da Nhạy Cảm 180ml",
        'role': 'TONER',
        'brand': 'Klairs',
        'ma_danh_muc': 4,
        'danh_muc': 'Toner / Nước Cân Bằng Da',
        'gia_ban': 201000,
        'loai_da': 'Da nhạy cảm'
    },
    240: {
        'ten_san_pham': "Nước Hoa Hồng Simple Làm Dịu Da & Cấp Ẩm 200ml",
        'role': 'TONER',
        'brand': 'Simple',
        'ma_danh_muc': 4,
        'danh_muc': 'Toner / Nước Cân Bằng Da',
        'gia_ban': 108000,
        'loai_da': 'Da nhạy cảm'
    },
    350: {
        'ten_san_pham': "Serum Skin1004 Rau Má Làm Dịu & Hỗ Trợ Phục Hồi Da 55ml",
        'role': 'SERUM',
        'brand': 'Skin1004',
        'ma_danh_muc': 9,
        'danh_muc': 'Serum / Tinh Chất',
        'gia_ban': 243000,
        'loai_da': 'Da mụn'
    },
    68: {
        'ten_san_pham': "Serum Timeless Vitamin B5 Làm Dịu & Phục Hồi Da 30ml",
        'role': 'SERUM',
        'brand': 'Timeless',
        'ma_danh_muc': 9,
        'danh_muc': 'Serum / Tinh Chất',
        'gia_ban': 357000,
        'loai_da': 'Da nhạy cảm'
    },
    62: {
        'ten_san_pham': "Serum L'Oreal Hyaluronic Acid Cấp Ẩm Sáng Da 30ml",
        'role': 'SERUM',
        'brand': "L'Oreal",
        'ma_danh_muc': 9,
        'danh_muc': 'Serum / Tinh Chất',
        'gia_ban': 290000,
        'loai_da': 'Da thường/Mọi loại da'
    },
    112: {
        'ten_san_pham': "Gel Dưỡng Megaduo Plus Giảm Mụn, Mờ Thâm 15g",
        'role': 'TREATMENT',
        'brand': 'Gamma Chemicals',
        'ma_danh_muc': 25,
        'danh_muc': 'Hỗ Trợ Trị Mụn',
        'gia_ban': 128000,
        'loai_da': 'Da mụn'
    },
    740: {
        'ten_san_pham': "Gel Giảm Mụn Eucerin Dành Cho Mụn Viêm & Không Viêm 40ml",
        'role': 'TREATMENT',
        'brand': 'Eucerin',
        'ma_danh_muc': 25,
        'danh_muc': 'Hỗ Trợ Trị Mụn',
        'gia_ban': 400000,
        'loai_da': 'Da mụn'
    },
    139: {
        'ten_san_pham': "Kem Dưỡng Ẩm Klairs Làm Dịu & Phục Hồi Da Ban Đêm 50g",
        'role': 'MOISTURIZER',
        'brand': 'Klairs',
        'ma_danh_muc': 7,
        'danh_muc': 'Kem / Gel / Dầu Dưỡng',
        'gia_ban': 289000,
        'loai_da': 'Da nhạy cảm'
    },
    318: {
        'ten_san_pham': "Kem Dưỡng Ẩm Hada Labo Tối Ưu Cho Mọi Loại Da 50g",
        'role': 'MOISTURIZER',
        'brand': 'Hada Labo',
        'ma_danh_muc': 7,
        'danh_muc': 'Kem / Gel / Dầu Dưỡng',
        'gia_ban': 174000,
        'loai_da': 'Da thường/Mọi loại da'
    },
    93: {
        'ten_san_pham': "Sữa Dưỡng Ẩm Embryolisse Siêu Phục Hồi Da 30ml",
        'role': 'MOISTURIZER',
        'brand': 'Embryolisse',
        'ma_danh_muc': 7,
        'danh_muc': 'Kem / Gel / Dầu Dưỡng',
        'gia_ban': 245000,
        'loai_da': 'Da khô/Hỗn hợp khô'
    },
    16: {
        'ten_san_pham': "Sữa Chống Nắng Anessa Dưỡng Da Kiềm Dầu 60ml (Bản Mới)",
        'role': 'SUNSCREEN',
        'brand': 'Anessa',
        'ma_danh_muc': 6,
        'danh_muc': 'Chống Nắng Da Mặt',
        'gia_ban': 432000,
        'loai_da': 'Da dầu/Hỗn hợp dầu'
    },
    10: {
        'ten_san_pham': "Kem Chống Nắng La Roche-Posay Phổ Rộng, Nâng Tông Kiềm Dầu 50ml",
        'role': 'SUNSCREEN',
        'brand': 'La Roche-Posay',
        'ma_danh_muc': 6,
        'danh_muc': 'Chống Nắng Da Mặt',
        'gia_ban': 412000,
        'loai_da': 'Da thường/Mọi loại da'
    },
    725: {
        'ten_san_pham': "Sữa Chống Nắng Sunplay Hiệu Chỉnh Sắc Da 50g (Xanh Dương)",
        'role': 'SUNSCREEN',
        'brand': 'Sunplay',
        'ma_danh_muc': 6,
        'danh_muc': 'Chống Nắng Da Mặt',
        'gia_ban': 132000,
        'loai_da': 'Da thường/Mọi loại da'
    },
    188: {
        'ten_san_pham': "Mặt Nạ Naruko Tràm Trà Kiểm Soát Dầu Và Giảm Mụn 26ml",
        'role': 'MASK',
        'brand': 'Naruko',
        'ma_danh_muc': 11,
        'danh_muc': 'Mặt Nạ Giấy',
        'gia_ban': 30000,
        'loai_da': 'Da dầu/Hỗn hợp dầu'
    },
    649: {
        'ten_san_pham': "Mặt Nạ Banobagi Dưỡng Sáng Và Cấp Ẩm Cho Da 30g (Xanh)",
        'role': 'MASK',
        'brand': 'Banobagi',
        'ma_danh_muc': 11,
        'danh_muc': 'Mặt Nạ Giấy',
        'gia_ban': 16000,
        'loai_da': 'Da thường/Mọi loại da'
    }
}

ALL_SKUS_SORTED = [5, 103, 4365, 21, 728, 4, 240, 350, 68, 62, 112, 740, 139, 318, 93, 16, 10, 725, 188, 649]
ALL_ROLES_SORTED = ['MAKEUP_REMOVAL', 'CLEANSER', 'TONER', 'SERUM', 'TREATMENT', 'MOISTURIZER', 'SUNSCREEN', 'MASK']

BASKETS_RAW = [
    # 6 Single-Item Baskets (Size 1)
    {'id': 'B001', 'items': [16], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},
    {'id': 'B002', 'items': [112], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},
    {'id': 'B003', 'items': [188], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},
    {'id': 'B004', 'items': [4365], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},
    {'id': 'B005', 'items': [5], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},
    {'id': 'B006', 'items': [62], 'pattern': 'SPONTANEOUS_SINGLE', 'noise': 'Yes'},

    # 12 Two-Item Baskets (Size 2)
    {'id': 'B007', 'items': [5, 21], 'pattern': 'DOUBLE_CLEANSING', 'noise': 'No'},
    {'id': 'B008', 'items': [103, 4365], 'pattern': 'DOUBLE_CLEANSING', 'noise': 'No'},
    {'id': 'B009', 'items': [5, 728], 'pattern': 'DOUBLE_CLEANSING', 'noise': 'No'},
    {'id': 'B010', 'items': [4365, 4], 'pattern': 'CLEANSE_TONE', 'noise': 'No'},
    {'id': 'B011', 'items': [21, 240], 'pattern': 'CLEANSE_TONE', 'noise': 'No'},
    {'id': 'B012', 'items': [728, 740], 'pattern': 'ACNE_TARGET', 'noise': 'No'},
    {'id': 'B013', 'items': [4365, 112], 'pattern': 'ACNE_TARGET', 'noise': 'No'},
    {'id': 'B014', 'items': [350, 139], 'pattern': 'BARRIER_REPAIR', 'noise': 'No'},
    {'id': 'B015', 'items': [68, 93], 'pattern': 'BARRIER_REPAIR', 'noise': 'No'},
    {'id': 'B016', 'items': [318, 16], 'pattern': 'DAYTIME_DEFENSE', 'noise': 'No'},
    {'id': 'B017', 'items': [93, 10], 'pattern': 'DAYTIME_DEFENSE', 'noise': 'No'},
    {'id': 'B018', 'items': [240, 649], 'pattern': 'EXPLORATORY_NOISE', 'noise': 'Yes'},

    # 8 Three-Item Baskets (Size 3)
    {'id': 'B019', 'items': [5, 21, 4], 'pattern': 'CLEANSING_RITUAL', 'noise': 'No'},
    {'id': 'B020', 'items': [4365, 188, 318], 'pattern': 'WEEKLY_PAMPER', 'noise': 'No'},
    {'id': 'B021', 'items': [728, 649, 725], 'pattern': 'SOOTHING_CARE', 'noise': 'No'},
    {'id': 'B022', 'items': [728, 112, 139], 'pattern': 'ACNE_RECOVERY', 'noise': 'No'},
    {'id': 'B023', 'items': [68, 139, 10], 'pattern': 'DAY_ROUTINE', 'noise': 'No'},
    {'id': 'B024', 'items': [62, 318, 725], 'pattern': 'DAY_ROUTINE', 'noise': 'No'},
    {'id': 'B025', 'items': [21, 4, 350], 'pattern': 'HYDRATION_CORE', 'noise': 'No'},
    {'id': 'B026', 'items': [240, 740, 16], 'pattern': 'EXPLORATORY_NOISE', 'noise': 'Yes'},

    # 4 Four-Item Baskets (Size 4)
    {'id': 'B027', 'items': [103, 4365, 350, 139], 'pattern': 'EVENING_ROUTINE', 'noise': 'No'},
    {'id': 'B028', 'items': [5, 728, 112, 318], 'pattern': 'FULL_ACNE_SYSTEM', 'noise': 'No'},
    {'id': 'B029', 'items': [21, 240, 93, 10], 'pattern': 'FULL_DAY_SYSTEM', 'noise': 'No'},
    {'id': 'B030', 'items': [103, 4365, 4, 188], 'pattern': 'DEEP_CLEAN_MASK', 'noise': 'No'}
]

def main():
    print("Generating Micro-Dataset Artifacts...")

    # 1. Enrich Baskets Data
    enriched_baskets = []
    for b in BASKETS_RAW:
        item_names = [SKU_INFO[sku]['ten_san_pham'] for sku in b['items']]
        item_roles = [SKU_INFO[sku]['role'] for sku in b['items']]
        enriched_baskets.append({
            'basket_id': b['id'],
            'items': b['items'],
            'item_names': item_names,
            'roles': item_roles,
            'basket_size': len(b['items']),
            'pattern_applied': b['pattern'],
            'noise_applied': b['noise'],
            'source': 'synthetic_seed',
            'scenario': 'basket_20_products_micro_v1',
            'generator_version': 'micro-v1',
            'seed': 42,
            'created_at': datetime.now(timezone.utc).isoformat()
        })

    # 2. Save selected_products.json
    selected_prods_path = os.path.join(SCRIPT_DIR, 'selected_products.json')
    with open(selected_prods_path, 'w', encoding='utf-8') as f:
        json.dump(SKU_INFO, f, ensure_ascii=False, indent=2)
    print(f"Saved: {selected_prods_path}")

    # 3. Save baskets_30.json
    baskets_json_path = os.path.join(SCRIPT_DIR, 'baskets_30.json')
    with open(baskets_json_path, 'w', encoding='utf-8') as f:
        json.dump(enriched_baskets, f, ensure_ascii=False, indent=2)
    print(f"Saved: {baskets_json_path}")

    # 4. Save transactions_readable.csv
    csv_readable_path = os.path.join(SCRIPT_DIR, 'transactions_readable.csv')
    with open(csv_readable_path, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['Basket ID', 'SKU IDs', 'Product Names', 'Routine Roles', 'Basket Size', 'Pattern Applied', 'Noise Applied?'])
        for b in enriched_baskets:
            sku_str = "[" + ", ".join(map(str, b['items'])) + "]"
            names_str = " + ".join(b['item_names'])
            roles_str = " + ".join(b['roles'])
            writer.writerow([b['basket_id'], sku_str, names_str, roles_str, b['basket_size'], b['pattern_applied'], b['noise_applied']])
    print(f"Saved: {csv_readable_path}")

    # 5. Save transaction_matrix_sku.csv (30 x 20)
    matrix_sku_path = os.path.join(SCRIPT_DIR, 'transaction_matrix_sku.csv')
    with open(matrix_sku_path, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        header = ['Basket_ID'] + [f"SKU_{sku}" for sku in ALL_SKUS_SORTED]
        writer.writerow(header)
        for b in enriched_baskets:
            b_set = set(b['items'])
            row = [b['basket_id']] + [1 if sku in b_set else 0 for sku in ALL_SKUS_SORTED]
            writer.writerow(row)
    print(f"Saved: {matrix_sku_path}")

    # 6. Save transaction_matrix_role.csv (30 x 8)
    matrix_role_path = os.path.join(SCRIPT_DIR, 'transaction_matrix_role.csv')
    with open(matrix_role_path, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        header = ['Basket_ID'] + ALL_ROLES_SORTED
        writer.writerow(header)
        for b in enriched_baskets:
            r_set = set(b['roles'])
            row = [b['basket_id']] + [1 if role in r_set else 0 for role in ALL_ROLES_SORTED]
            writer.writerow(row)
    print(f"Saved: {matrix_role_path}")

    # 7. Compute Manual Rule Calculations
    def compute_role_rule(ant, con):
        N = len(enriched_baskets)
        n_a = sum(1 for b in enriched_baskets if ant in set(b['roles']))
        n_b = sum(1 for b in enriched_baskets if con in set(b['roles']))
        n_ab = sum(1 for b in enriched_baskets if ant in set(b['roles']) and con in set(b['roles']))
        supp_a = n_a / N
        supp_b = n_b / N
        supp_ab = n_ab / N
        conf = n_ab / n_a if n_a > 0 else 0.0
        lift = conf / supp_b if supp_b > 0 else 0.0
        return {
            'antecedent': ant,
            'consequent': con,
            'type': 'ROLE_LEVEL',
            'N_total': N,
            'N_A': n_a,
            'N_B': n_b,
            'N_AB': n_ab,
            'Support_A': round(supp_a, 4),
            'Support_B': round(supp_b, 4),
            'Support_AB': round(supp_ab, 4),
            'Confidence': round(conf, 4),
            'Lift': round(lift, 4),
            'formula_support': f"{n_ab} / {N} = {supp_ab:.4f}",
            'formula_confidence': f"{n_ab} / {n_a} = {conf:.4f}",
            'formula_lift': f"{conf:.4f} / {supp_b:.4f} = {lift:.4f}",
            'interpretation': "Positive Association (Lift > 1)" if lift > 1.05 else ("Negative Association (Lift < 1)" if lift < 0.95 else "Independent (Lift ~ 1)")
        }

    def compute_sku_rule(ant_sku, con_sku):
        N = len(enriched_baskets)
        n_a = sum(1 for b in enriched_baskets if ant_sku in set(b['items']))
        n_b = sum(1 for b in enriched_baskets if con_sku in set(b['items']))
        n_ab = sum(1 for b in enriched_baskets if ant_sku in set(b['items']) and con_sku in set(b['items']))
        supp_a = n_a / N
        supp_b = n_b / N
        supp_ab = n_ab / N
        conf = n_ab / n_a if n_a > 0 else 0.0
        lift = conf / supp_b if supp_b > 0 else 0.0
        return {
            'antecedent_sku': ant_sku,
            'antecedent_name': SKU_INFO[ant_sku]['ten_san_pham'],
            'consequent_sku': con_sku,
            'consequent_name': SKU_INFO[con_sku]['ten_san_pham'],
            'type': 'SKU_LEVEL',
            'N_total': N,
            'N_A': n_a,
            'N_B': n_b,
            'N_AB': n_ab,
            'Support_A': round(supp_a, 4),
            'Support_B': round(supp_b, 4),
            'Support_AB': round(supp_ab, 4),
            'Confidence': round(conf, 4),
            'Lift': round(lift, 4),
            'formula_support': f"{n_ab} / {N} = {supp_ab:.4f}",
            'formula_confidence': f"{n_ab} / {n_a} = {conf:.4f}",
            'formula_lift': f"{conf:.4f} / {supp_b:.4f} = {lift:.4f}",
            'interpretation': "Positive Association (Lift > 1)" if lift > 1.05 else ("Negative Association (Lift < 1)" if lift < 0.95 else "Independent (Lift ~ 1)")
        }

    # Planted and Reverse Rules
    target_role_rules = [
        ('MAKEUP_REMOVAL', 'CLEANSER'),
        ('CLEANSER', 'TONER'),
        ('CLEANSER', 'TREATMENT'),
        ('SERUM', 'MOISTURIZER'),
        ('MOISTURIZER', 'SUNSCREEN'),
        ('CLEANSER', 'MASK'),
        # Reverse Direction (Asymmetry check)
        ('CLEANSER', 'MAKEUP_REMOVAL'),
        ('TONER', 'CLEANSER'),
        ('MOISTURIZER', 'SERUM'),
        # Negative / Control Rules
        ('MASK', 'SUNSCREEN'),
        ('TREATMENT', 'MAKEUP_REMOVAL'),
        ('TONER', 'SUNSCREEN')
    ]

    target_sku_rules = [
        (5, 21),    # Bioderma -> La Roche-Posay Cleanser
        (350, 139), # Skin1004 Serum -> Klairs Moisturizer
        (728, 112)  # Eucerin Cleanser -> Megaduo Treatment
    ]

    role_calcs = [compute_role_rule(a, c) for a, c in target_role_rules]
    sku_calcs = [compute_sku_rule(a, c) for a, c in target_sku_rules]

    manual_results = {
        'metadata': {
            'dataset': 'basket_20_products_micro_v1',
            'N_baskets': 30,
            'N_skus': 20,
            'N_roles': 8,
            'source': 'synthetic_seed',
            'generator_version': 'micro-v1',
            'seed': 42
        },
        'role_level_rules': role_calcs,
        'sku_level_rules': sku_calcs
    }

    calcs_json_path = os.path.join(SCRIPT_DIR, 'manual_rule_calculations.json')
    with open(calcs_json_path, 'w', encoding='utf-8') as f:
        json.dump(manual_results, f, ensure_ascii=False, indent=2)
    print(f"Saved: {calcs_json_path}")

    # 8. Insert into MongoDB skinsyntax_research_dev.synthetic_baskets
    try:
        client = pymongo.MongoClient('mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB')
        r_db = client['skinsyntax_research_dev']
        col = r_db['synthetic_baskets']
        
        # Clear existing scenario baskets if any
        col.delete_many({'scenario': 'basket_20_products_micro_v1'})
        
        # Prepare mongo documents
        mongo_docs = []
        for b in enriched_baskets:
            doc = dict(b)
            doc['created_at'] = datetime.now(timezone.utc)
            mongo_docs.append(doc)
            
        result = col.insert_many(mongo_docs)
        print(f"Inserted {len(result.inserted_ids)} baskets into skinsyntax_research_dev.synthetic_baskets")
    except Exception as e:
        print(f"MongoDB warning: {e}")

    print("All artifacts generated successfully.")

if __name__ == '__main__':
    main()
