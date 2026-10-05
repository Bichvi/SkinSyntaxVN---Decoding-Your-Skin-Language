import sys
import io
import json
import pymongo
from datetime import datetime, timezone

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# Approved 20 SKUs and Metadata
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
        'brand': 'Aessa',
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

# 30 Synthetic Baskets definition
BASKETS = [
    # --- GROUP 1: 6 Single-Item Baskets (Size 1) ---
    {
        'id': 'B001',
        'items': [16],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },
    {
        'id': 'B002',
        'items': [112],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },
    {
        'id': 'B003',
        'items': [188],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },
    {
        'id': 'B004',
        'items': [4365],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },
    {
        'id': 'B005',
        'items': [5],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },
    {
        'id': 'B006',
        'items': [62],
        'pattern': 'SPONTANEOUS_SINGLE',
        'noise': 'Yes'
    },

    # --- GROUP 2: 12 Two-Item Baskets (Size 2) ---
    # Double Cleansing (MAKEUP_REMOVAL + CLEANSER)
    {
        'id': 'B007',
        'items': [5, 21],
        'pattern': 'DOUBLE_CLEANSING',
        'noise': 'No'
    },
    {
        'id': 'B008',
        'items': [103, 4365],
        'pattern': 'DOUBLE_CLEANSING',
        'noise': 'No'
    },
    {
        'id': 'B009',
        'items': [5, 728],
        'pattern': 'DOUBLE_CLEANSING',
        'noise': 'No'
    },
    # Cleanser + Toner (CLEANSER + TONER)
    {
        'id': 'B010',
        'items': [4365, 4],
        'pattern': 'CLEANSE_TONE',
        'noise': 'No'
    },
    {
        'id': 'B011',
        'items': [21, 240],
        'pattern': 'CLEANSE_TONE',
        'noise': 'No'
    },
    # Cleanser + Treatment (CLEANSER + TREATMENT)
    {
        'id': 'B012',
        'items': [728, 740],
        'pattern': 'ACNE_TARGET',
        'noise': 'No'
    },
    {
        'id': 'B013',
        'items': [4365, 112],
        'pattern': 'ACNE_TARGET',
        'noise': 'No'
    },
    # Serum + Moisturizer (SERUM + MOISTURIZER)
    {
        'id': 'B014',
        'items': [350, 139],
        'pattern': 'BARRIER_REPAIR',
        'noise': 'No'
    },
    {
        'id': 'B015',
        'items': [68, 93],
        'pattern': 'BARRIER_REPAIR',
        'noise': 'No'
    },
    # Moisturizer + Sunscreen (MOISTURIZER + SUNSCREEN)
    {
        'id': 'B016',
        'items': [318, 16],
        'pattern': 'DAYTIME_DEFENSE',
        'noise': 'No'
    },
    {
        'id': 'B017',
        'items': [93, 10],
        'pattern': 'DAYTIME_DEFENSE',
        'noise': 'No'
    },
    # Noise/Uncorrelated Two-item
    {
        'id': 'B018',
        'items': [240, 649], # Simple Toner + Banobagi Mask
        'pattern': 'EXPLORATORY_NOISE',
        'noise': 'Yes'
    },

    # --- GROUP 3: 8 Three-Item Baskets (Size 3) ---
    # Double Cleansing + Toner (MAKEUP_REMOVAL + CLEANSER + TONER)
    {
        'id': 'B019',
        'items': [5, 21, 4],
        'pattern': 'CLEANSING_RITUAL',
        'noise': 'No'
    },
    # Cleanser + Mask + Moisturizer (CLEANSER + MASK + MOISTURIZER)
    {
        'id': 'B020',
        'items': [4365, 188, 318],
        'pattern': 'WEEKLY_PAMPER',
        'noise': 'No'
    },
    # Cleanser + Mask (CLEANSER + MASK + SUNSCREEN)
    {
        'id': 'B021',
        'items': [728, 649, 725],
        'pattern': 'SOOTHING_CARE',
        'noise': 'No'
    },
    # Cleanser + Treatment + Moisturizer (CLEANSER + TREATMENT + MOISTURIZER)
    {
        'id': 'B022',
        'items': [728, 112, 139],
        'pattern': 'ACNE_RECOVERY',
        'noise': 'No'
    },
    # Serum + Moisturizer + Sunscreen (SERUM + MOISTURIZER + SUNSCREEN)
    {
        'id': 'B023',
        'items': [68, 139, 10],
        'pattern': 'DAY_ROUTINE',
        'noise': 'No'
    },
    {
        'id': 'B024',
        'items': [62, 318, 725],
        'pattern': 'DAY_ROUTINE',
        'noise': 'No'
    },
    # Cleanser + Toner + Serum (CLEANSER + TONER + SERUM)
    {
        'id': 'B025',
        'items': [21, 4, 350],
        'pattern': 'HYDRATION_CORE',
        'noise': 'No'
    },
    # Noise/Uncorrelated Three-item (Toner + Treatment + Sunscreen)
    {
        'id': 'B026',
        'items': [240, 740, 16],
        'pattern': 'EXPLORATORY_NOISE',
        'noise': 'Yes'
    },

    # --- GROUP 4: 4 Four-Item Baskets (Size 4) ---
    # Full Evening Routine: MAKEUP_REMOVAL + CLEANSER + SERUM + MOISTURIZER
    {
        'id': 'B027',
        'items': [103, 4365, 350, 139],
        'pattern': 'EVENING_ROUTINE',
        'noise': 'No'
    },
    # Full Acne Routine: MAKEUP_REMOVAL + CLEANSER + TREATMENT + MOISTURIZER
    {
        'id': 'B028',
        'items': [5, 728, 112, 318],
        'pattern': 'FULL_ACNE_SYSTEM',
        'noise': 'No'
    },
    # Full Day Routine: CLEANSER + TONER + MOISTURIZER + SUNSCREEN
    {
        'id': 'B029',
        'items': [21, 240, 93, 10],
        'pattern': 'FULL_DAY_SYSTEM',
        'noise': 'No'
    },
    # Mixed Routine with Mask: MAKEUP_REMOVAL + CLEANSER + TONER + MASK
    {
        'id': 'B030',
        'items': [103, 4365, 4, 188],
        'pattern': 'DEEP_CLEAN_MASK',
        'noise': 'No'
    }
]

print(f"Total baskets designed: {len(BASKETS)}")

# Check basket size distribution
sizes = [len(b['items']) for b in BASKETS]
print(f"Sizes distribution: Size 1: {sizes.count(1)}, Size 2: {sizes.count(2)}, Size 3: {sizes.count(3)}, Size 4: {sizes.count(4)}")

# Check SKU frequencies
sku_counts = {}
for b in BASKETS:
    for sku in b['items']:
        sku_counts[sku] = sku_counts.get(sku, 0) + 1

print("\nSKU occurrence counts:")
all_skus = list(SKU_INFO.keys())
for sku in sorted(all_skus):
    role = SKU_INFO[sku]['role']
    name = SKU_INFO[sku]['ten_san_pham'][:40]
    count = sku_counts.get(sku, 0)
    print(f"  SKU {sku:4d} | Role: {role:<14} | Count: {count:2d} | {name}")

missing_skus = [s for s in all_skus if s not in sku_counts]
print(f"\nMissing SKUs: {missing_skus} (Count: {len(missing_skus)})")

# Check role occurrences
role_baskets = {r: 0 for r in ['MAKEUP_REMOVAL', 'CLEANSER', 'TONER', 'SERUM', 'TREATMENT', 'MOISTURIZER', 'SUNSCREEN', 'MASK']}
for b in BASKETS:
    roles_in_b = set(SKU_INFO[sku]['role'] for sku in b['items'])
    for r in roles_in_b:
        role_baskets[r] += 1

print("\nRole basket occurrences (out of 30):")
for r, c in role_baskets.items():
    print(f"  {r:<16} : {c:2d} / 30 ({c/30*100:.1f}%)")

# Calculate association rules manually
def calc_rule(ant_role, con_role):
    n_total = len(BASKETS)
    n_ant = 0
    n_con = 0
    n_both = 0
    for b in BASKETS:
        roles = set(SKU_INFO[sku]['role'] for sku in b['items'])
        has_ant = ant_role in roles
        has_con = con_role in roles
        if has_ant:
            n_ant += 1
        if has_con:
            n_con += 1
        if has_ant and has_con:
            n_both += 1
    supp_ant = n_ant / n_total
    supp_con = n_con / n_total
    supp_both = n_both / n_total
    conf = n_both / n_ant if n_ant > 0 else 0.0
    lift = conf / supp_con if supp_con > 0 else 0.0
    return {
        'N_total': n_total,
        'N_A': n_ant,
        'N_B': n_con,
        'N_AB': n_both,
        'supp_A': supp_ant,
        'supp_B': supp_con,
        'supp_AB': supp_both,
        'conf': conf,
        'lift': lift
    }

print("\n=== RULE EVALUATIONS (ROLE-LEVEL) ===")
rules_to_check = [
    ('MAKEUP_REMOVAL', 'CLEANSER'),
    ('CLEANSER', 'TONER'),
    ('CLEANSER', 'TREATMENT'),
    ('SERUM', 'MOISTURIZER'),
    ('MOISTURIZER', 'SUNSCREEN'),
    ('CLEANSER', 'MASK'),
    # Asymmetric reverse rules
    ('CLEANSER', 'MAKEUP_REMOVAL'),
    ('TONER', 'CLEANSER'),
    ('MOISTURIZER', 'SERUM'),
    # Negative/Control rules
    ('MASK', 'SUNSCREEN'),
    ('TREATMENT', 'MAKEUP_REMOVAL'),
    ('TONER', 'SUNSCREEN')
]

for ant, con in rules_to_check:
    res = calc_rule(ant, con)
    print(f"Rule: {ant} -> {con}")
    print(f"  N(A)={res['N_A']}, N(B)={res['N_B']}, N(AB)={res['N_AB']}, N={res['N_total']}")
    print(f"  Supp(A)={res['supp_A']:.4f}, Supp(B)={res['supp_B']:.4f}, Supp(AB)={res['supp_AB']:.4f}")
    print(f"  Conf={res['conf']:.4f} ({res['N_AB']}/{res['N_A']}), Lift={res['lift']:.4f}")
    classification = "Positive Association (> 1)" if res['lift'] > 1.05 else ("Negative Association (< 1)" if res['lift'] < 0.95 else "Independent (~ 1)")
    print(f"  Classification: {classification}\n")
