"""Catalog giả lập (mô phỏng đường dẫn danh mục thật) cho các test pb-fixture; không phải test case."""
from __future__ import annotations

import random
from types import SimpleNamespace

from fixtures.prompt_builder.catalog_pick import pick_restore_candidates

ROOT = "Sức Khỏe - Làm Đẹp -> "
FACE = "Chăm Sóc Da Mặt -> "
BRANDS = {
    1: "Cocoon", 2: "La Roche-Posay", 3: "L'Oreal", 4: "Sunplay", 5: "Anessa", 6: "Vichy", 7: "Eucerin",
    8: "Garnier", 9: "Hada Labo", 10: "Innisfree", 11: "Mediheal", 12: "Dove", 13: "Clear", 14: "Maybelline",
    15: "3CE", 16: "Romand", 17: "Simple", 18: "Bioderma", 19: "Neutrogena", 20: "The Ordinary",
}
BRAND_WEIGHTS = [10, 9, 8] + [3] * 17
SKIN = [("Da thường/Mọi loại da", 50), ("Da dầu/Hỗn hợp dầu", 12), ("Unknown", 11), ("Da nhạy cảm", 10),
        ("Da mụn", 9), ("Da khô/Hỗn hợp khô", 8)]
ACTIVES = (("Serum Retinol", "Retinol"), ("Serum Niacinamide", "Niacinamide"), ("Serum Vitamin C", "Ascorbic Acid"),
           ("Serum Cấp Ẩm", "Hyaluronic Acid"), ("Tinh Chất Centella", "Centella Asiatica"))

# (đường dẫn, tiền tố tên, số lượng, khoảng giá, có trong catalog live)
LEAVES = [
    (FACE + "Chống Nắng Da Mặt", ("Kem Chống Nắng", "Sữa Chống Nắng", "Gel Chống Nắng", "Xịt Chống Nắng"), 110, (59000, 650000), True),
    (FACE + "Làm Sạch Da -> Sữa Rửa Mặt", ("Sữa Rửa Mặt", "Gel Rửa Mặt"), 80, (89000, 450000), True),
    (FACE + "Làm Sạch Da -> Tẩy Trang Mặt", ("Nước Tẩy Trang", "Dầu Tẩy Trang"), 12, (99000, 400000), True),
    (FACE + "Làm Sạch Da -> Tẩy Tế Bào Chết Da Mặt", ("Tẩy Tế Bào Chết",), 8, (99000, 350000), True),
    (FACE + "Làm Sạch Da -> Toner / Nước Cân Bằng Da", ("Toner", "Nước Cân Bằng"), 8, (99000, 450000), True),
    (FACE + "Dưỡng Ẩm -> Kem / Gel / Dầu Dưỡng", ("Kem Dưỡng Ẩm", "Gel Dưỡng"), 20, (120000, 700000), True),
    (FACE + "Dưỡng Ẩm -> Lotion / Sữa Dưỡng", ("Sữa Dưỡng",), 6, (120000, 500000), True),
    (FACE + "Dưỡng Ẩm -> Xịt Khoáng", ("Xịt Khoáng",), 5, (80000, 300000), True),
    (FACE + "Đặc Trị -> Serum / Tinh Chất", ("Serum",), 80, (150000, 1200000), True),
    (FACE + "Đặc Trị -> Hỗ Trợ Trị Mụn", ("Gel Chấm Mụn",), 6, (70000, 250000), True),
    (FACE + "Mặt Nạ -> Mặt Nạ Giấy", ("Mặt Nạ Giấy",), 80, (8000, 90000), True),
    (FACE + "Mặt Nạ -> Mặt Nạ Rửa", ("Mặt Nạ Đất Sét",), 8, (90000, 300000), True),
    (FACE + "Dưỡng Mắt -> Serum / Kem Dưỡng Mắt", ("Kem Mắt", "Serum Dưỡng Mắt"), 8, (200000, 700000), True),
    (FACE + "Dưỡng Môi -> Son Dưỡng Môi", ("Son Dưỡng Môi",), 6, (50000, 200000), True),
    ("Trang Điểm -> Trang Điểm Môi -> Son Thỏi", ("Son Thỏi",), 30, (120000, 500000), False),
    ("Trang Điểm -> Trang Điểm Môi -> Son Kem / Tint", ("Son Kem", "Son Tint"), 30, (100000, 400000), False),
    ("Trang Điểm -> Trang Điểm Môi -> Son Bóng", ("Son Bóng",), 30, (90000, 350000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> Kem Nền", ("Kem Nền",), 30, (150000, 700000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> Phấn Nền", ("Phấn Nền",), 30, (150000, 600000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> Phấn Nước Cushion", ("Phấn Nước",), 30, (250000, 800000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> BB / CC Cream", ("BB Cream",), 30, (120000, 450000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> Che Khuyết Điểm", ("Kem Che Khuyết Điểm",), 30, (120000, 450000), False),
    ("Trang Điểm -> Trang Điểm Mặt -> Kem Lót", ("Kem Lót",), 30, (150000, 500000), False),
    ("Trang Điểm -> Dụng Cụ Trang Điểm -> Cọ Trang Điểm", ("Cọ Trang Điểm",), 5, (50000, 300000), False),
    ("Chăm Sóc Cơ Thể -> Sữa Tắm", ("Sữa Tắm",), 30, (80000, 300000), False),
    ("Chăm Sóc Cơ Thể -> Dưỡng Thể", ("Kem Dưỡng Thể", "Sữa Dưỡng Thể"), 30, (100000, 500000), False),
    ("Chăm Sóc Cơ Thể -> Chống Nắng Cơ Thể", ("Kem Chống Nắng Body",), 30, (120000, 450000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Gội", ("Dầu Gội",), 30, (80000, 350000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Gội Khô", ("Dầu Gội Khô",), 30, (90000, 300000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Gội Xả 2in1", ("Dầu Gội Xả",), 30, (90000, 300000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Xả", ("Dầu Xả",), 30, (80000, 350000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dưỡng Tóc -> Mặt Nạ / Kem Ủ Tóc", ("Kem Ủ Tóc",), 30, (100000, 400000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dưỡng Tóc -> Serum / Dầu Dưỡng Tóc", ("Dầu Dưỡng Tóc",), 30, (100000, 450000), False),
    ("Chăm Sóc Tóc Và Da Đầu -> Dưỡng Tóc -> Xịt Dưỡng Tóc", ("Xịt Dưỡng Tóc",), 30, (100000, 400000), False),
]


def _row(pid, path, prefix, k, brand_id, price, rng, sold_scale):
    brand = BRANDS[brand_id]
    skin = rng.choices([s for s, _ in SKIN], weights=[w for _, w in SKIN])[0]
    sold = 0 if rng.random() < 0.2 else int(min(250, rng.paretovariate(1.1) * sold_scale))
    market = int(price * rng.uniform(1.05, 1.6)) // 1000 * 1000 if rng.random() < 0.85 else price
    row = {
        "ma_san_pham": pid, "ten_san_pham": f"{prefix} {brand} Phiên Bản {k + 1}",
        "danh_muc_day_du": ROOT + path, "loai_san_pham": path.split(" -> ")[-1],
        "ma_thuong_hieu": brand_id, "ten_thuong_hieu": brand, "gia_ban": price, "gia_thi_truong": max(market, price),
        "loai_da": skin, "diem_danh_gia": round(rng.uniform(3.6, 5.0), 1), "so_luong_danh_gia": rng.randint(0, 400),
        "so_luong_da_ban": sold, "so_luong_ton_kho": rng.randint(0, 300), "trang_thai": "active",
    }
    if path.endswith("Serum / Tinh Chất"):
        name, ingredient = ACTIVES[k % len(ACTIVES)]
        row["ten_san_pham"] = f"{name} {brand} Phiên Bản {k + 1}"
        row["thanh_phan_chinh"] = ingredient
    return row


def build_catalog(seed: int = 11, per_leaf: int = 22):
    """Trả (live, backup, restored, all_products). Backup chứa cả bản sao của live như collection thật."""
    rng = random.Random(seed)
    live, backup_only = [], []
    next_live, next_backup = 100, 5000
    for path, prefixes, count, (lo, hi), is_live in LEAVES:
        for k in range(count):
            brand_id = rng.choices(list(BRANDS), weights=BRAND_WEIGHTS)[0]
            price = int(rng.uniform(lo, hi) // 1000 * 1000) or lo
            prefix = prefixes[k % len(prefixes)]
            if is_live:
                live.append(_row(next_live, path, prefix, k, brand_id, price, rng, 8))
                next_live += 1
            else:
                backup_only.append(_row(next_backup, path, prefix, k, brand_id, price, rng, 8))
                next_backup += 1
    sunscreens = [r for r in live if r["danh_muc_day_du"].endswith("Chống Nắng Da Mặt")]
    for row, price in zip(sorted(sunscreens, key=lambda r: r["ma_san_pham"])[:3], (2900000, 2100000, 1600000)):
        row["gia_ban"], row["gia_thi_truong"] = price, price + 200000  # sản phẩm cao cấp cho ca doanh thu cao
    backup = [dict(r) for r in live] + backup_only
    restored = pick_restore_candidates(backup, {str(r["ma_san_pham"]) for r in live}, per_leaf=per_leaf, rng=random.Random(seed))
    for row in restored:
        row["trang_thai"] = "active"
        row["restored_from"] = "san_pham_backup_6377"
    return SimpleNamespace(live=live, backup=backup, restored=restored, products=live + restored)
