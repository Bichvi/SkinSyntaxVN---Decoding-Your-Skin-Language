# Sản phẩm tương tự ở trang chi tiết (U08)

Ngày: 2026-10-23. Trạng thái: chưa commit.

## Làm gì

Trang chi tiết sản phẩm có thêm khối carousel "Sản phẩm tương tự" (4 thẻ trên desktop, 2 thẻ trên mobile, cuộn ngang). Mỗi thẻ có nhãn lý do ("Chung hoạt chất …", "Cùng công dụng", "Cùng dòng sản phẩm"), nút "Thêm vào giỏ" và "Mua ngay". Khối nằm ngay dưới khối "Mua kèm tiết kiệm" cũ; khối cũ và các bộ gợi ý có sẵn (Simple/Hybrid, GoiYContentBased) giữ nguyên.

## Luồng

1. Trang chi tiết chỉ in một khung chờ (skeleton). `similar-products.js` gọi `index.php?r=similar_products&id=<mã>` khi khung chờ gần vào khung nhìn, nên thời gian phản hồi trang (TTFB) không đổi.
2. PHP `SanPhamController::similarProducts` gọi `SearchRecommendClient::similar` (timeout 800ms, dùng chung breaker với `recommend`), lấy thẻ sản phẩm qua `findVisibleByIds`, render `partials/similar_products.php`. Không đủ 2 sản phẩm, lỗi, breaker mở, bị giới hạn tốc độ: trả 204 và khối tự ẩn.
3. Flask `GET /api/products/<id>/similar?limit=8&exclude=<csv>&skin_type=<key>` đọc `SIMILAR_TO` (Cypher chỉ đọc, tham số hóa), xếp lại, đa dạng hóa theo hãng.

## Công thức xếp hạng

`final = score + 0.15*cùng_danh_mục_lá + 0.05*log1p(đã_bán)_chuẩn_hóa - 0.5*hết_hàng + 0.1*hợp_loại_da`. Tối đa 3 sản phẩm một hãng (nếu sau khi giới hạn còn dưới 4 sản phẩm thì nới để khối không gần như rỗng). `SIMILAR_TO` được đọc không hướng vì mỗi cặp chỉ lưu một chiều.

## Dự phòng

Neo4j lỗi hoặc sản phẩm chưa có cạnh: lấy embedding của chính sản phẩm trong Chroma `catalog_products_v1`, chỉ giữ láng giềng cùng danh mục lá, trả `degraded: true`, `source: "semantic"`. Cache Redis `product:similar:v1:g<gen>:<id>:<skin|any>` (1 giờ; 120 giây khi là dự phòng), hết hiệu lực khi `publish_invalidate` tăng generation.

## Cấu hình

`SIMILAR_PRODUCTS_ENABLED` (PHP, 0 = ẩn khối), `SEARCH_SIMILAR_ENABLED` (Flask, 0 = 404), `SEARCH_SIMILAR_CACHE_TTL`, `SEARCH_SIMILAR_TIMEOUT_MS`. Contract: `ai-service-flask/search_service/contract/similar_response.schema.json`.

## Theo dõi

Dùng lại beacon `search_event`: section mới `similar`, `q = "similar:<mã sản phẩm>"` (PHP `SearchEventTracker::SECTIONS`, worker `search_events_flusher`, `metrics.SECTIONS`).

## Kiểm thử

`ai-service-flask/tests/search_service/test_similar.py` (33), 2 test tích hợp Neo4j trong `test_graph_integration.py` (cần `NEO4J_TEST_URI`), PHP `backend/tests/unit/SimilarProductsTest.php` (39).
