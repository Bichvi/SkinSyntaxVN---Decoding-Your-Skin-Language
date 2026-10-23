# U09 — GIỎ HÀNG: CẢNH BÁO XUNG ĐỘT HOẠT CHẤT + GỢI Ý ĐỦ ROUTINE

## Vai trò
Kỹ sư fullstack + dữ liệu da liễu. Dùng Neo4j để (1) cảnh báo các cặp sản phẩm trong giỏ kỵ nhau và (2) gợi ý bước còn thiếu của routine.

## Đọc trước
`u00-common-rules.md` → handoff → `ai-service-flask/recommendation_service/{conflict_checker,routine_planner,safety_filter}.py` và `knowledge/` (luật xung đột/routine **đã có** — tái dùng, không viết lại, không xóa), `graph_sync/load.py` (cách nạp `CONFLICTS_WITH`, hiện 12 cặp), view/controller giỏ hàng (`frontend/views/giohang.php`, rg controller giỏ) — kiểm xem giỏ đã có cảnh báo xung đột nào chưa (dự án có "cart ingredient-conflict detection"): nếu có thì **nâng cấp nguồn dữ liệu**, không làm trùng UI.

## Phần A — Mở rộng tri thức xung đột
- Tạo `ai-service-flask/data/graph/conflict_rules.yaml` (nguồn có review), mỗi luật:
  ```yaml
  - id: retinoid_aha_bha
    a: [retinol, retinal, tretinoin, adapalene]      # INCI key hoặc nhóm
    b: [glycolic_acid, lactic_acid, mandelic_acid, salicylic_acid]
    severity: high | medium | low
    timing: same_routine | same_session | ok_alternate_days
    advice_vi: "Dùng xen kẽ tối: một tối retinoid, một tối acid."
    source: "ghi nguồn tham khảo (tài liệu da liễu công khai)"
  ```
  Bắt đầu từ luật có sẵn trong `CONFLICT_RULES` + thêm các cặp phổ biến đã được thừa nhận rộng rãi (retinoid × AHA/BHA, retinoid × benzoyl peroxide, vitamin C (ascorbic acid) × AHA/BHA nồng độ cao, benzoyl peroxide × vitamin C, nhiều chất tẩy tế bào chết cùng lúc, retinoid × vitamin C cùng thời điểm…). **Không bịa**; trường hợp không chắc → `severity: low` + ghi chú, hoặc bỏ. Mục tiêu ≥ 30 cặp INCI sau khi mở rộng nhóm.
- `graph_sync`: nạp từ YAML mới (giữ tương thích luật cũ), thuộc tính `severity`, `timing`, `advice_vi`, `rule_id`. Chạy `--full` hoặc lệnh nạp riêng `--conflicts-only` (thêm cờ), idempotent.

## Phần B — API Flask
1. `POST /api/cart/analyze` body `{product_ids:[...≤50], skin_profile?}` (auth nội bộ, rate limit):
   ```json
   { "conflicts":[{"a":"123","b":"456","severity":"high","timing":"same_routine",
                   "ingredients":["Retinol","Salicylic Acid"],"rule_id":"...","advice_code":"..."}],
     "routine": {"has":["sua_rua_mat","serum"],"missing":["toner","kem_duong","kem_chong_nang"]},
     "suggestions":[{"step":"kem_chong_nang","items":[{"product_id","score","reason_code","reason_params"}]}],
     "degraded": false }
   ```
2. Conflicts — Cypher:
   ```cypher
   UNWIND $ids AS id1 UNWIND $ids AS id2
   WITH id1, id2 WHERE id1 < id2
   MATCH (p1:Product {id:id1})-[:CONTAINS]->(i1)-[c:CONFLICTS_WITH]-(i2)<-[:CONTAINS]-(p2:Product {id:id2})
   RETURN id1, id2, collect(DISTINCT {a:i1.name, b:i2.name, severity:c.severity, timing:c.timing, rule:c.rule_id}) AS hits
   ```
   Gộp theo cặp SP, lấy severity cao nhất. Neo4j lỗi → dùng `conflict_checker` Python có sẵn làm fallback (`degraded:true`).
3. Routine:
   - Định nghĩa routine cơ bản trong `ai-service-flask/data/graph/routine_steps.yaml` (tái dùng `routine_planner` nếu đã có thứ tự bước): sáng `sua_rua_mat → toner → serum → kem_duong → kem_chong_nang`; tối `tay_trang → sua_rua_mat → toner → serum/treatment → kem_duong`. Map step ↔ category key (lá + cha qua `CHILD_OF`).
   - `missing` = bước cốt lõi chưa có (cốt lõi: sữa rửa mặt, kem dưỡng, kem chống nắng; phụ: toner, serum).
   - `suggestions`: mỗi bước thiếu (≤3 bước, ưu tiên cốt lõi) lấy 4 SP: thuộc category bước đó, `SUITABLE_FOR` loại da (nếu có hồ sơ), **không** xung đột với bất kỳ SP nào trong giỏ, điểm = độ giống hồ sơ hoạt chất của giỏ (chung benefit) + bán chạy + `CO_PURCHASED` (nếu flag bật). reason_code: `completes_routine` (params: tên bước), `fits_skin_type`.
4. Cache Redis theo hash tập id + skin hash, 10 phút.

## Phần C — PHP + View giỏ hàng
- Client gọi `cart/analyze` (timeout 1s, breaker chung). Lỗi → không hiện khối (giỏ hàng phải luôn hoạt động).
- UI:
  - Cảnh báo theo severity (high: màu cảnh báo + biểu tượng; medium/low: thông tin), câu khuyên từ `advice_vi` (escape), chỉ rõ 2 SP nào. Không chặn thanh toán.
  - Khối "Hoàn thiện routine của bạn": chip bước thiếu + carousel card (tái dùng partial/card U08), nút thêm giỏ AJAX → sau khi thêm, phân tích lại (fetch) và cập nhật khối.
  - Tracking `data-ssf-section="routine"|"conflict"` → beacon.
- Nếu giỏ đã có cảnh báo xung đột cũ: hợp nhất thành một khối, dữ liệu từ API mới, ghi rõ trong báo cáo.

## Kiểm thử
- Python unit: gộp cặp, severity cao nhất, routine missing, lọc xung đột trong gợi ý, validate input.
- Integration Neo4j: giỏ có 1 SP retinol + 1 SP BHA (tìm id thật bằng Cypher) → conflict high.
- PHP unit: client + render escape.
- E2E (Playwright MCP; nếu MCP không kết nối được, dùng trình duyệt tích hợp hoặc ghi NOT RUN): khách vãng lai thêm 2 SP xung đột → thấy cảnh báo; giỏ chỉ có sữa rửa mặt → thấy gợi ý kem chống nắng/kem dưỡng; thêm 1 gợi ý → khối cập nhật; tắt Neo4j → vẫn có cảnh báo (fallback) hoặc ẩn gọn, giỏ không lỗi. Ảnh `report/upgrade-e2e/u09-*.png`. Dọn giỏ test sau khi xong.

## Đầu ra
YAML luật (có nguồn), API, UI, mục "U09" trong handoff (số cặp xung đột trước/sau, contract). Không commit.
