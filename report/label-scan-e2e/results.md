# Chụp bảng thành phần — kết quả M7 (2026-11-05)

## API smoke (trong container skinsyntax-ai, POST /api/label/analyze, skin_profile da_kho)
Dữ liệu thô: smoke-output.jsonl (script: smoke.py). Provider không lộ ra trong log nên không ghi.

| # | Ảnh | status | total/recognized | took_ms | Ghi chú |
|---|---|---|---|---|---|
| 1 | pharmaform-brightener | ok | 21/16 | 5964 | tên "Skin Brightener Serum", brand Pharmaform |
| 2 | neutrogena-hydroboost | ok | 25/24 | 5654 | gồm CI 77007, titanium dioxide, mica đúng như nhãn |
| 3 | cosrx-ha3 | ok | 30/30 | 8307 | NHÃN CÓ ~44 thành phần, chỉ đọc 30 (dừng ở sodium_methyl_stearoyl_taurate) |
| 4 | rivaj-no-inci | not_label | 0/0 | 4879 | đúng |
| 5 | ha-adaptogen-vite | ok | 18/16 | 17343 | chậm (>10 s) |
| 6 | advanced-clinicals-no-inci | not_label | 0/0 | 4262 | đúng |
| 7 | ha-cream-partial | ok | 8/3 | 6100 | warnings low_recognition (+cart_empty), verdict neutral (không 🟢) |
| 8 | its-skin-multilang-no-inci | not_label | 0/0 | 7692 | đúng |

Văn bản AHA/BHA + product_ids=[98] (Obagi Retinol, tìm từ trang tatca?q=retinol): conflicts = [98, high, same_routine], schedule kind=alternate_days (đúng phán quyết), took_ms 656, warnings graph_degraded (neo4j không trả alternatives).

## AC2 — recall hoạt chất (chỉ ảnh có danh sách: 1,2,3,5,7; hoạt chất = dưỡng ẩm/phục hồi/chăm sóc, bỏ dung môi, tạo đặc, bảo quản)
| Ảnh | Hoạt chất trên nhãn | Đọc đúng | Thiếu |
|---|---|---|---|
| 1 | 10 (niacinamide, tranexamic, 4-butylresorcinol, HA, tocopheryl acetate, bisabolol, acetyl glucosamine, squalane, glycyrrhiza, morus alba) | 10 | – |
| 2 | 8 (panthenol, sodium hyaluronate, hydrolyzed HA, glycerin, trehalose, xylitol, anhydroxylitol, yeast extract) | 8 | – |
| 3 | 13 (sodium hyaluronate, betaine, sodium PCA, glycerin, serine, alanine, glycine, proline, ceramide NP, cholesterol, phytosphingosine, tocopherol, lactic acid) | 7 | proline, ceramide NP, cholesterol, phytosphingosine, tocopherol, lactic acid |
| 5 | 12 (các dạng HA x8, tocopherol, glycine soja sterols, illicium verum, cynanchum atratum) | 12 | – |
| 7 | 7 (hydrovance, glycerin, allantoin, HA, aloe, carrot, pumpkin) | 7 | – |
Tổng 44/50 = 88% (≥85% đạt về tổng; ảnh 3 chỉ 54%). Không thấy thành phần bịa ngoài nhãn. not_label đúng 3/3 (4,6,8).

## AC1 — took_ms (8 ảnh)
Sắp xếp: 4262, 4879, 5654, 5964, 6100, 7692, 8307, 17343 -> p50 = 6032 ms, p95 (nearest-rank, n=8) = 17343 ms. 7/8 ảnh <= 8.3 s; ảnh 5 là ngoại lệ 17.3 s. Mẫu nhỏ.
