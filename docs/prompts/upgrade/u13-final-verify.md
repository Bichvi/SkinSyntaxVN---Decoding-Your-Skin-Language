# U13 — KIỂM THỬ TỔNG, TÀI LIỆU, COMMIT THEO NHÓM

## Vai trò
Kỹ sư QA/release. Xác minh toàn bộ giai đoạn 2 trên ứng dụng thật, viết tài liệu bàn giao, chuẩn bị commit (chỉ commit khi người dùng đồng ý).

## Đọc trước
`u00-common-rules.md` → **toàn bộ** `docs/plans/upgrade-handoff.md` → `.claude/skills/{e2e,lint,frontend-testing}/SKILL.md`.

## 1. Kiểm kê
- `git status`, `git diff --stat`; nhóm file theo bước U01–U12. Phát hiện file ngoài phạm vi (của phiên khác: `ai-idol-service/*`, technology inventory, `docs/superpowers/*`…) → **không đụng, không commit**.
- Xác nhận không có giá trị bí mật trong file được track (`git grep` các tên biến env + so khớp giá trị thật đọc từ `.env` mà không in ra).

## 2. Test tự động
- Python: mọi suite (search_service, graph_sync, chatbot_graph, redis_kit, workers, recommendation) + `-m integration` (Neo4j/Redis local).
- PHP: mọi `backend/tests/unit/*Test.php` mới/cũ của giai đoạn 1–2 (KHÔNG chạy script cũ khác trong `backend/tests/`), `php -l` mọi file PHP đổi.
- JS: `node --check` file JS đổi. Lint theo skill nếu có công cụ.

## 3. E2E trên :8080 (Playwright MCP; nếu MCP không kết nối, dùng trình duyệt tích hợp; không có cả hai → NOT RUN)
| # | Kịch bản | Kỳ vọng |
|---|---|---|
| 1 | Gõ "toi" ở ô tìm | gợi ý Torriden (U04) |
| 2 | "toriden srm" | tự sửa + banner + link exact (U05) |
| 3 | "torriden" | SP bán chạy lên đầu (U05) |
| 4 | "xyzabc" | fallback như giai đoạn 1 |
| 5 | Đăng nhập khách test, truy vấn ít kết quả | có "Hợp với làn da của bạn", `user_hash` ≠ null (U01) |
| 6 | Admin test: tìm SP; sửa tag công dụng 1 SP | lưu được, ≤ 60s Neo4j cập nhật (U07, U11) |
| 7 | Admin ẩn 1 SP rồi hiện lại | ≤ 60s biến mất/khôi phục trong gợi ý (U11) |
| 8 | Trang chi tiết 1496 | khối "Sản phẩm tương tự" có sữa rửa mặt khác hãng (U08) |
| 9 | Giỏ có 2 SP xung đột | cảnh báo đúng severity (U09) |
| 10 | Giỏ chỉ có sữa rửa mặt | gợi ý hoàn thiện routine (U09) |
| 11 | Syna: "serum nào hợp da dầu, không kỵ retinol" | đúng ràng buộc (U10) |
| 12 | Tắt Neo4j | tìm kiếm/chi tiết/giỏ/Syna vẫn chạy (xuống cấp mềm) |
| 13 | Tắt Redis 30s | trang chạy, beacon fallback Atlas, không lỗi |
| 14 | Tắt Flask | best sellers, breaker mở |
| 15 | Mobile 375 | không cuộn ngang ở tìm kiếm, chi tiết, giỏ |
| 16 | XSS qua q, tag admin | được escape |
- Bật lại mọi service; đăng xuất; dọn giỏ test. Ảnh `report/upgrade-final-e2e/`.

## 4. Hiệu năng & vận hành
- Bench API (recommend, suggest, similar, cart/analyze): p50/p95/p99 tuần tự + c=4.
- TTFB/Load trang tìm kiếm, chi tiết, giỏ: trước/sau giai đoạn 2 (nếu có số cũ trong handoff).
- `/api/ops/health`: không alert; RAM Neo4j; độ dài stream; trạng thái job.

## 5. Tài liệu
- Viết `update_search_upgrade_phase2.md` (tiếng Việt): những gì đã làm theo từng U, kiến trúc Redis (bảng key), luồng sự kiện catalog, lịch job, flag/env mới, cổng điều kiện U12 và số liệu, cách vận hành/sự cố, rủi ro còn lại.
- Thêm mục trỏ tới tài liệu vào `update.md` (chỉ hunk của mình). Cập nhật `docs/plans/upgrade-handoff.md` mục "U13".

## 6. Commit (chỉ khi người dùng đồng ý rõ ràng trong chat)
- `git diff --check`; `node .gitnexus/run.cjs detect-changes --scope all --repo .` (partial/truncated → chạy lại).
- Đề xuất nhóm commit theo phụ thuộc, mỗi commit tự chạy được, ví dụ:
  1. `fix(ops): internal token, test accounts, livekit backoff, chatbot init locks, clean logs` (U01–U02)
  2. `feat(redis): shared redis kit, rate limits, event streams, cache generations` (U03)
  3. `feat(search): token autocomplete, ranking, did-you-mean` (U04–U05)
  4. `feat(data): INCI aliases, zero-result loop, benefit tagging with review` (U06–U07)
  5. `feat(graph): similar products, cart conflicts and routine, Syna graph tools` (U08–U10)
  6. `feat(ops): event-driven sync, scheduler, health monitoring` (U11)
  7. `feat(search): CTR boost, A/B framework, cross-encoder gate` (U12)
  8. `docs: phase 2 handoff and E2E evidence`
- Thêm `Co-Authored-By` theo quy ước phiên. File có hunk của việc khác → stage từng phần bằng `git apply --cached` từ patch đã lọc (không dùng lệnh tương tác). Không push.

## Đầu ra cho người dùng
Kết luận sẵn sàng/chưa → bảng E2E → test → hiệu năng → bảo mật → file → bỏ qua → rủi ro → đề xuất commit.
