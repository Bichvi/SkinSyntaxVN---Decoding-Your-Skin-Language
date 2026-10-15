# SkinSyntaxVN — Kiểm tra chatbot và thiết kế luồng tư vấn

Ngày: 09/09/2026. Phạm vi: chatbot chữ trong widget PHP; nguồn là checkout hiện tại, có thay đổi chưa commit từ trước.

Đây là báo cáo tìm lỗi, cách sửa và thiết kế đề xuất. Chưa chỉnh source ứng dụng, cấu hình chạy, database hoặc model. Có tạo script kiểm tra, JSON kết quả và sơ đồ để đối chiếu. Không gọi API LLM, không dùng dữ liệu người dùng thật, không chạy kiểm thử giao diện đầu cuối. Vì vậy không kết luận về model đang được phục vụ, chất lượng trả lời thực tế toàn hệ thống, chi phí hay độ trễ đo được.

## Kết quả quan trọng

15/15 tình huống Python và 1/1 tình huống PHP tái hiện hành vi lỗi. Đây là số tình huống tái hiện, không phải “16 bài test chất lượng đã đạt”. Script Python thực thi các hàm/class nguyên bản trích bằng AST, thay những ranh giới model/database bằng dữ liệu kiểm soát. Script PHP dùng nguyên phương thức cache, thay lưu file bằng bộ nhớ. Các kết quả nằm trong `reproduction-results.json` và `cache-reproduction.json`.

Luồng đang được nối theo code:

```text
frontend/views/components/ai_chat_widget.php:1987
  → HomeController::aiChatAssistant():2061
  → POST /api/chat/auto (config.php:125; docker-compose.yml:264)
  → chatbot_service.chatbot_flask:app (Dockerfile.ai)
  → chat():2659 → xu_ly_cau_hoi():1694 trong CÙNG chatbot_flask.py
```

Tên `/api/chat/auto` không có nghĩa endpoint này đang gọi `router.should_use_agent`, `run_agent` hay `pipeline.py`. Trong file đang nối endpoint không có import các hàm đó. `gunicorn_config.py` lại warm-up `retrieval.py` và `llm_pool.py`, trong khi hàm xử lý dùng bản khởi tạo khác trong `chatbot_flask.py`. Đây là căn cứ từ source, chưa phải trace của một process đang chạy.

**Đính chính nhận định trước:** chatbot đang nối endpoint đã có tìm tổ hợp routine theo tổng tiền (tối đa 3 ứng viên × 6 nhóm, 3^6 = 729 tổ hợp khi đủ nhóm). Lỗi là tối ưu trước khi gộp dữ liệu, phạm vi tổ hợp còn cứng, và không kiểm tra lại đầu ra. Recommendation LlamaIndex là service khác; không dùng kết luận từ service đó thay cho chatbot.

## Sơ đồ

- `current-flow.png` / `current-flow.svg`: luồng hiện có, các dấu Fxx là lỗi trong bảng bên dưới.
- `proposed-flow.png` / `proposed-flow.svg`: luồng mục tiêu. Mọi top-k, timeout, token budget trong hình này là điểm khởi đầu để đo, chưa phải cấu hình đã áp dụng hoặc thông số tối ưu.
- `render_diagrams.py`: nguồn tạo sơ đồ; dùng Pillow để xuất PNG và cùng tọa độ để xuất SVG. SVG giữ nét khi phóng to.

## Những lỗi phải sửa trước

| Mã | Ưu tiên | Bằng chứng và tác động | Cách sửa / điều kiện nghiệm thu |
|---|---|---|---|
| F00 | P1 | Endpoint gọi `chatbot_flask.py:2715`, không gọi `pipeline.py`. Các cải tiến ở file rời không tự có tác dụng. Warm-up và request dùng hai bộ khởi tạo. | Giữ route công khai, đưa orchestrator vào một module duy nhất và để route/warm-up import cùng module. Thêm test spy xác nhận endpoint gọi orchestrator mới. Chuyển luồng sau khi các test hành vi đạt, không đổi import mù quáng. |
| F01 | P1 | `HomeController.php:40–104,2099`: cache dùng SHA256(câu hỏi + product_id), TTL 604800 giây, không có user/profile/history/cart. Trả nguyên payload kể cả `conversation_id`. Fixture chứng minh trả lại câu trả lời và ID cuộc trò chuyện trước. | Tắt cache toàn bộ câu trả lời cá nhân hóa ngay trong bản vá đầu. Không cache hoặc replay conversation_id. Chỉ cache tri thức chung đã xác minh; dữ liệu giá/stock lấy mới. Nếu sau này cache theo phiên, cần conversation/profile/routine/cart/catalog/prompt/model versions và xác định owner từ server. |
| F02a | P1 | `chatbot_flask.py:113–121` truyền `BM25Search()` rỗng. Không có thao tác nạp tài liệu vào đối tượng này. Nhánh lexical không đóng góp vào RRF. | Dùng một retrieval factory, nạp BM25 từ cùng snapshot sản phẩm, báo count lexical trong health/trace. Corpus có tài liệu thì lexical count phải >0 ở query có từ khớp. |
| F02b | P1 | `hybrid_search.py:119–144`: `tf=1` cho mọi tài liệu nếu token tồn tại đâu đó trong corpus; không kiểm tra tần suất token ở tài liệu đang chấm. Fixture “retinol” vẫn cho điểm “lipstick red”. | Dùng `rank_bm25.BM25Okapi`, hoặc sửa TF thật bằng Counter. Token không nằm trong document phải đóng góp 0. Thống nhất tokenization cho lúc index và query. |
| F02c | P1 | `hybrid_search.py:509–538`: `filters` chỉ dùng cho semantic; tài liệu lexical-only được gộp không qua filter. Fixture lọc “Serum” vẫn nhận cleanser. Lỗi này sẽ lộ rõ khi sửa F02a. | Áp cùng điều kiện eligibility cho cả hai nhánh và kiểm tra lại sau gộp. Exact product ID, loại sản phẩm, thành phần cần tránh, giá/stock không được “cứu” bởi rerank. |
| F03 | P1 | `survey_service.py:43–55` chỉ nhận chuỗi bắt đầu `AI:`/`SkinSyntax AI:`; `chatbot_flask.py:2710` phục hồi lịch sử thành dict `{sender,text}`. Fixture đang trả lời câu 1 nhưng `is_in_survey_flow=False`. | Chuẩn hóa history thành `{role,content}` ở biên API. Lưu survey_step, answers, pending_question trong state riêng. Không dò câu hỏi trên văn bản để quyết định bước. |
| F04 | P1 | `chatbot_flask.py:1138,1509`: phần trăm giảm giá = `((id % 4)+2)*5`; suy ngược giá gốc. ID 123, giá 100k → tự tạo giảm 25%, giá gốc 133.333đ. Không dựa vào dữ liệu khuyến mãi. | Xóa suy diễn giảm giá. Chỉ dùng giá bán và giá tham chiếu xác thực từ DB; trường thiếu là null và không hiển thị ưu đãi. Tính bằng số nguyên VND. |
| F05 | P1 | `chatbot_flask.py:1149,1516`: luôn gọi hướng dẫn ngẫu nhiên theo category, bỏ qua hướng dẫn thật. Fixture có `huong_dan_su_dung` nhưng không xuất hiện trong context. Những lời khẳng định như “hiệu quả gấp đôi” được đưa vào dữ liệu cho LLM. | Đọc hướng dẫn hãng theo phiên bản sản phẩm; thiếu thì nói chưa có hướng dẫn xác minh. Tách lời khuyên chung có nguồn khỏi cách dùng của một sản phẩm. Không dùng random để tạo dữ kiện. |
| F06 | P1 | `chatbot_flask.py:1776`: `', '.join(cart_conflicts)` nhận list dict từ `HomeController::detectCartIngredientConflicts`. Fixture gây `TypeError: expected str instance, dict found`. | Truyền list object theo schema hoặc serialize từng field. Test giỏ rỗng, không cảnh báo, một/nhiều cảnh báo; endpoint không được lỗi khi có cảnh báo. |
| F07 | P1 | PHP gửi `customer_profile.concerns` và sản phẩm `ingredients`; Python đọc `skin_issues/tinh_trang_da` ở 1719 và `thanh_phan_chinh/thanh_phan/summary` ở 2315. Hai sentinel bị mất khỏi prompt. | Một DTO chuẩn ở PHP/Flask. Adapter alias chỉ nằm tại biên; không map lại thủ công nhiều nơi. Test giữ nguyên nội dung concerns, sensitivity, ingredient list, source_url. |
| F08 | P1 | Tối ưu routine ở 2212–2258, sau đó thêm sản phẩm PHP ở 2301–2484. Routine không bị kiểm lại tổng ngân sách. Fixture 6×50k đã đạt 350k, sau gộp thêm 800k trả 1,1 triệu. | Gộp → hydrate DB → loại trùng → lọc → tối ưu → validate cuối. Phân biệt sản phẩm dùng để tham chiếu và sản phẩm trong kế hoạch. Không thêm product sau tối ưu mà bỏ qua validate. |
| F09 | P1 | Thành phần cần tránh đi vào rich_context/analysis nhưng không có bộ lọc cuối tương ứng. Fixture hồ sơ tránh retinol vẫn nhận retinol trong sản phẩm trả về. | Đối chiếu danh sách INCI đã chuẩn hóa bằng luật có nguồn. Sản phẩm thiếu INCI là “chưa xác minh”, không được coi là “không chứa”. Kiểm cả thành phần tránh ở query và hồ sơ. |
| F10 | P2 | `extract_budget_from_text:891` không xử lý dấu phẩy thập phân: “1,5 triệu” → 5.000.000. | Parse Decimal và đơn vị rõ ràng; phân biệt khoảng giá, giá một món/tổng tiền, giá cũ/ngân sách mới. Test 1,5 triệu; 1.5tr; 300–500k; 500.000đ; không giới hạn. |
| F11 | P1 | `rule_based_parse:923–1023` gặp “kem dưỡng” là trả sớm, không điền thương hiệu/thành phần tránh. “Kem dưỡng không chứa retinol” mất ràng buộc phủ định. | Rule chỉ trích trường chắc chắn, không coi kết quả một phần là parse hoàn chỉnh. Structured extractor cho toàn bộ constraints; lưu thông tin nguồn từng field; hỏi lại khi mơ hồ. |
| F12 | P1 (đánh giá) | `rag_evaluation.py:227`: chỉ cần một từ dài trùng là một câu được xem có căn cứ. “Sản phẩm không chứa retinol” / “Sản phẩm chứa retinol” đạt faithfulness=1.0. PHP còn mặc định ar/gr/cr=1.0 khi không có điểm, dòng 2207. | Đổi tên phép đo hiện tại thành lexical overlap nếu còn giữ; kết quả chưa đánh giá phải null. Chấm từng claim với đoạn chứng cứ, test phủ định/số tiền/sản phẩm, đối chiếu người chấm. Không dùng score này làm % chính xác hay % hợp da. |
| F13 | P2 | `chatbot_flask.py:1793`: profile account ghi đè loại da parse từ câu hỏi. Fixture hỏi cho mẹ da khô, account da dầu → analysis da dầu. | Xác định người được tư vấn, ưu tiên phát biểu hiện tại cho người đó. Không sửa hồ sơ tài khoản vì câu hỏi về người khác hoặc tình trạng tạm thời. |
| F14 | P2 | Classifier có 6 intent nhưng retrieval chỉ có 2 nhánh explicit và else. `GENERAL_SKINCARE_KNOWLEDGE` rơi vào retrieval/prompt bán sản phẩm. Fixture “Có cần dùng toner không” vẫn gọi tìm sản phẩm. | Router exhaustive enum: mỗi intent có handler riêng, thêm assertion/test để không có nhánh bỏ sót. Kiến thức chung không bắt buộc trả thẻ sản phẩm. |

P1/P2 là ưu tiên sửa trong audit này, không phải điểm rủi ro lâm sàng. Hình đính kèm là tham chiếu bố cục, không được coi là bằng chứng về code đang chạy.

## Vấn đề thiết kế bổ sung chưa đo tác động đầu cuối

1. Cold start `get_llms()` thực sự gọi “Hi” để thử nhiều nhà cung cấp/model/key trước khi xử lý; bước xử lý còn rewrite → classify → parse (nếu cần) → generate. `temperature=0` không sửa được dữ liệu sai hay đảm bảo đầu ra đúng. Nhiều constructor không đặt timeout ứng dụng rõ ràng; không suy ra thời gian chờ thực tế từ mặc định SDK.
2. Cross-encoder được gọi trong retrieval từng nhánh rồi lại rerank toàn bộ pool. Hàm rerank gọi compressor rồi gọi `.score()` thêm lần nữa trên top results để lấy score. Đây là phần công việc lặp có thể đo và giảm.
3. Chính model card của `static-similarity-mrl-multilingual-v1` ghi không hướng đến retrieval. Model rất nhẹ nhưng cần benchmark retrieval trên dữ liệu Việt trước khi tiếp tục chọn. Không kết luận nó là nguyên nhân duy nhất, hoặc E5 chắc chắn tốt hơn trong dataset này.
4. Import Chroma dùng model cùng tên nhưng không khai báo `normalize_embeddings=True`/metric, trong khi query khai báo normalize. Cần kiểm metadata và norm vector thực tế; chưa chứng minh mismatch trên collection đang chạy. Chroma hiện hành mặc định L2, không được mặc nhiên diễn giải `1-distance` là xác suất hay cosine. Khi thay embedding, tạo collection mới và re-index toàn bộ; không trộn vector 1024 chiều và 384 chiều.
5. Default path trong `chatbot_flask.py`/`retrieval.py` là `ai-service-flask/database/chroma_db`; importer ghi `database/chroma_db` ở root; Compose override `/app/chroma_data`. Cần health báo resolved path/collection/count để đối chiếu; chưa khẳng định env triển khai sai.
6. Runtime Python global được đọc metadata trên máy: Flask 2.2.5, Chroma 1.5.9, LangChain 1.3.1, langchain-core 1.4.0, community 0.4.1, openai 1.2.1, google-genai integration 4.2.2, huggingface integration 1.2.2, sentence-transformers 5.5.0, Pydantic 2.12.5. requirements trong repo có Flask==3.0.3, Chroma==0.5.20 và nhiều `>=`. Đây không phải version inventory của container hoặc process web. Phải chốt môi trường chạy trước khi sửa API thư viện.
7. “Không giới hạn ngân sách” khảo sát map về 0 nhưng profile_state xem budget<=0 là thiếu. Nên có `budget_mode=unknown|bounded|unlimited`, `budget_max_vnd=null` thay vì dùng 0 mang hai nghĩa.
8. `survey_service.py` xây hồ sơ bằng quét text câu trả lời, có cả lựa chọn khác trong quicksend. Kể cả sửa định dạng lịch sử, cần bỏ cách suy ra state từ đoạn chat và lưu câu trả lời có cấu trúc.
9. Nhánh knowledge chưa mang rich_context vào prompt như nhánh product; khảo sát hoàn tất cập nhật một số biến nhưng rich_context đã được xây trước đó. Cần snapshot hồ sơ duy nhất rồi render context sau cập nhật.
10. PHP gửi tối đa 6 message history; Flask khôi phục 10 message nếu có cuộc trò chuyện đăng nhập. Frontend đẩy câu hiện tại vào history rồi gửi lại thành message riêng. Chuẩn hóa “history trước turn hiện tại” để tránh lặp, không dùng slicing thay cho memory theo nhu cầu.

## Kiến trúc mục tiêu — một hướng AI tư vấn routine

Giữ PHP MVC + Flask + MongoDB. Giữ endpoint ngoài để không phải thay toàn bộ giao diện. Dùng một LangGraph StateGraph cho luồng xác định, không cần nhiều agent tự tranh luận.

### Bước 1 — Context có cấu trúc

PHP lấy user identity từ session server, kiểm quyền conversation, gửi JSON object trực tiếp. `message` không chứa một chuỗi JSON khác. Chuẩn hóa product field names và lấy đầy đủ dữ kiện từ DB; chỉ gửi context cần thiết. Các trạng thái thiếu thông tin/dữ liệu chưa xác minh đều explicit.

```json
{
  "schema_version": "2",
  "request_id": "uuid",
  "conversation_id": "server-owned-id",
  "message": "Mình muốn routine tổng dưới 400k",
  "history": [{"role": "user", "content": "Mình có sữa rửa mặt rồi"}],
  "profile": {
    "subject": "self", "skin_type": "oily", "concerns": ["acne_prone"],
    "sensitivity": "unknown", "avoid_ingredients": [],
    "budget_mode": "bounded", "budget_max_vnd": 400000,
    "budget_scope": "new_purchases", "version": 3
  },
  "owned_products": [{"product_id": "id-1", "confirmed_in_use": true}],
  "cart_product_ids": [],
  "current_product_id": null
}
```

Giỏ hàng, lịch sử mua và đồ thực sự đang dùng là ba khái niệm khác nhau. Hỏi người dùng xác nhận khi chưa rõ. Dữ liệu mô tả/nguồn bên ngoài là bằng chứng, không phải instruction được phép thay system prompt.

### Bước 2 — Route, trích xuất và điều kiện cần hỏi

Enum đề xuất: `GREETING`, `KNOWLEDGE`, `PRODUCT_DETAIL`, `PRODUCT_SEARCH`, `ROUTINE_PLAN`, `ROUTINE_REVIEW`, `CHECKIN`, `OUT_OF_SCOPE`. Tách điều kiện cần chuyển chuyên gia khỏi intent; dùng quy tắc chuyên môn đã duyệt và không khẳng định khả năng chẩn đoán.

- Một structured call để xác định intent, target person, referenced_product_ids, budget, required/excluded ingredients, missing_fields. Hạn chế gộp mọi quyết định chuyên môn vào một call.
- Quy tắc phần mềm kiểm tra dữ liệu đã đủ cho đúng intent. Giá một sản phẩm không cần khảo sát da; routine cá nhân hóa cần hỏi những trường ảnh hưởng quyết định.
- Mỗi lượt hỏi 1–2 câu ưu tiên; lưu pending question và câu hỏi gốc để tiếp tục đúng mục tiêu.
- Lịch sử gần: 8 lượt (16 message) làm điểm khởi đầu, nhưng ưu tiên giới hạn token. Hồ sơ/routine/state có cấu trúc là nguồn nhớ chính.

### Bước 3 — Ba đường lấy bằng chứng

1. Greeting: trả ngắn, không truy xuất sản phẩm hoặc Tavily mặc định.
2. Knowledge: retrieve kho kiến thức kiểm duyệt; nếu thiếu, thông báo giới hạn hoặc tra nguồn allowlist đã cấu hình. Không gọi mọi kết quả Tavily là “nguồn khoa học”. Nội dung chăm sóc da phải có nguồn phù hợp; mô tả marketing chỉ hỗ trợ dữ kiện sản phẩm.
3. Product/routine: exact ID/brand lookup trước nếu có thực thể; semantic + lexical cho tìm ứng viên; loại trùng bằng stable product_id; hydrate giá/stock/INCI từ MongoDB; bộ kiểm tra điều kiện áp dụng cho mọi nhánh.

### Bước 4 — Rerank và tối ưu

Gộp candidate đủ điều kiện, chấm cross-encoder một lần trên tập đã giới hạn. Rerank đo mức liên quan văn bản, không đo độ an toàn hay xác suất hợp da.

Với routine, lấy 3–5 ứng viên mỗi vai trò cần thiết thay vì chỉ top 6 chung (tránh mất một vai trò). Định nghĩa số bước theo nhu cầu và sản phẩm đang có, không mặc định 6 nhóm luôn bắt buộc. Python enumeration có nhánh bỏ qua bước tùy chọn đủ cho tập nhỏ; OR-Tools CP-SAT dùng khi cần thêm tổ hợp/lịch.

- Biến chọn sản phẩm, vai trò, thời điểm; giá là integer VND.
- Ràng buộc cứng: total_new_cost <= budget khi bounded; đáp ứng vai trò cần thiết; loại trừ đã xác minh; sản phẩm tồn tại/được bán; đồ ngoài catalog phải có dữ liệu đủ hoặc được đánh dấu chưa xác minh.
- Mục tiêu theo thứ tự: đáp ứng nhu cầu và điều kiện → tận dụng đồ có sẵn → ít chi phí mua thêm/ít bước trùng → sở thích và relevance. Trọng số nếu dùng phải công bố, không biến điểm ranking thành % phù hợp.
- Solver limit 0,5 giây là cấu hình thử. Phân biệt `OPTIMAL`, `FEASIBLE`, `INFEASIBLE`, `UNKNOWN`. `UNKNOWN` không có nghĩa không tồn tại phương án; `FEASIBLE` không bảo đảm tối ưu toàn cục.
- Nếu thiếu ứng viên, mở rộng retrieval trong giới hạn; không tự bỏ budget/exclusion. Nếu không tìm được phương án thì hỏi điều chỉnh hoặc đưa phương án một phần được ghi rõ.

### Bước 5 — Sinh câu trả lời và kiểm tra

LLM chỉ giải thích dữ liệu/plan đã kiểm tra. Đầu ra Pydantic/JSON Schema có `answer`, `claims[{text,source_id}]`, `product_ids`, `routine_id`, `warnings`, `missing_information`, `next_question`. Thẻ giá, tổng tiền, phần trăm giảm được render từ dữ liệu server, không parse lại con số tự sinh trong prose.

Validator sau generate kiểm tra schema, reference IDs, sản phẩm nằm trong tập được phép, budget, exclusions, nguồn hỗ trợ từng claim và phần dữ kiện còn thiếu. Có thể sửa đầu ra 1 lần trong giới hạn thời gian; vẫn thất bại thì trả thông báo rõ và dữ liệu đã xác minh. Không trả mặc định “Tôi đề xuất những sản phẩm này” khi API thất bại.

### Bước 6 — Lưu và theo dõi

MongoDB: conversations, skin_profiles với version, routines với version, routine_checkins, evidence_sources, ingredient_rules. LangGraph checkpointer lưu graph state; hồ sơ chuyên biệt vẫn cần schema riêng. Guest session phải riêng, TTL 24h là đề xuất; dữ liệu tài khoản cần quyền xem/xóa và chính sách lưu rõ ràng. Dùng check-in để ghi nhận trải nghiệm/tuân thủ/thay đổi sản phẩm, không tự suy ra bệnh hoặc quan hệ nhân quả từ một báo cáo.

## Thông số khởi đầu để triển khai và benchmark

| Thành phần | Hiện tại theo source | Đề xuất khởi đầu |
|---|---|---|
| Embedding | static-similarity-mrl-multilingual-v1; CPU; query normalize=True; mặc định model 1024 chiều | Benchmark `intfloat/multilingual-e5-small`: 384 chiều, CPU, normalize cả index/query; prefix `query:` / `passage:` kể cả tiếng Việt; batch_size=16. Chưa chọn thắng khi chưa đo. |
| Vector DB | Chroma `products`; repo pin 0.5.20; runtime global khác | Tạo `products_v2_e5` và `knowledge_v1_e5`; cosine; khai báo rõ metric và embedding fingerprint. Cấu hình collection theo API đúng version đã lock, không copy API 1.x vào 0.5.x. |
| Knowledge chunks | Chưa có kho kiến thức kiểm duyệt tách biệt trên đường gọi này | 250–350 token/chunk, overlap=50; token bằng tokenizer model. Giữ nguồn/heading/section/date; không cắt mất điều kiện áp dụng. |
| Product docs | Metadata + nội dung product, context cắt ingredients 300 ký tự, description 250 ký tự | Document retrieval ngắn theo sản phẩm; INCI đầy đủ giữ riêng và đưa vào tool kiểm tra. Không dùng đoạn 300 ký tự để xác nhận không có thành phần. |
| BM25 | Custom; k1=1.5, b=0.75; lỗi TF; active index rỗng | `rank-bm25` / BM25Okapi k1=1.5, b=0.75; chuẩn hóa Unicode/case/alias, giữ tên INCI và phủ định. Word segmentation tiếng Việt là biến thử thêm, không bắt buộc trước khi sửa TF. |
| Retrieve | k=min(max(requested or 3,3),10); k_total=max(2×top_n,6); routine 3/group | Dense top30 + lexical top30; kiểm điều kiện và gộp product IDs. Mở rộng top60 mỗi nhánh một lần nếu thiếu vai trò. |
| Fusion | Weighted RRF k=60; alpha=0.5 active, 0.6 trong retrieval module khác | Bắt đầu alpha=0.5, k=60; thử alpha∈{0.4,0.6,0.8} trên dev set. RRF(d)=Σ w/(60+rank_1_based). |
| Reranker | cross-encoder/mmarco-mMiniLMv2-L12-H384-v1; CPU; nhiều lượt | Giữ làm baseline; tối đa 30 ứng viên/lượt, batch_size=8, total query+doc max_length=512; 6 evidence passages cuối cho knowledge; routine giữ 3–5 sản phẩm/vai trò. Không phải model chuyên gia da liễu. |
| LLM provider | Thứ tự source Groq → OpenAI → Gemini → Zhipu → OpenRouter, phụ thuộc key và thử kết nối thành công | Một primary + tối đa một fallback đã benchmark tiếng Việt và hỗ trợ schema/tool cần dùng. Tạm giữ model cấu hình thực tế để đo trước/sau; không đồng thời đổi mọi model và pipeline. Không xác nhận hạn mức miễn phí trong comment cũ. |
| LLM parameters | temperature=0, max_tokens=4096, max_retries=0; nhiều call | Extractor 400–700 output tokens; trả lời 800–1200; temperature=0 nếu model hỗ trợ; tổng context mục tiêu <=6000 tokens. Đây là budget ứng dụng, không phải context limit của model. |
| Deadline | PHP set_time_limit120; gunicorn timeout120; SDK timeout không explicit ở nhiều provider | End-to-end deadline 30s; mỗi call timeout <= min(12s, thời gian còn lại); tối đa 1 fallback hoặc 1 repair nếu còn budget. Hủy downstream khi client ngắt nếu tích hợp hỗ trợ. |
| Cache | PHP file response TTL 7 ngày; Redis module không phải đường gọi này | Tắt response cache cá nhân hóa giai đoạn sửa; public knowledge cache thử 1h + knowledge/model/prompt version; hydrate giá/stock mỗi lần. |
| State | History strings/dicts lẫn; survey suy từ text | LangGraph StateGraph + persistent checkpointer; profile/routine version ở MongoDB; `pending_question`, `subject`, `budget_mode` rõ. |
| Đánh giá | Heuristic lexical overlap + mặc định perfect score | pytest behavior tests, endpoint contract test, Recall@30/MRR@10/nDCG@10, precision nguồn, ràng buộc vi phạm; p50/p95 + token usage/cost đo thật. |

Chốt version bằng môi trường sạch và lockfile sau smoke test/integration test. Không nâng toàn bộ LangChain/Chroma cùng lúc với bản vá logic. Các thư viện dự kiến: Flask, Pydantic v2, pymongo, redis, langgraph, provider SDK/integration đã chọn, sentence-transformers, langchain-huggingface/langchain-chroma nếu tiếp tục dùng adapter, rank-bm25; tùy giai đoạn thêm ortools và pytest. Không cần đồng thời LlamaIndex và LangChain cho cùng chatbot.

## Mẫu sửa ở mức thiết kế

Đây là mẫu để áp vào adapter mới, chưa phải patch đã tích hợp:

```python
# 1. Không ghép dict bằng join; giữ schema hoặc serialize có cấu trúc.
conflicts_json = json.dumps(cart_conflicts, ensure_ascii=False)

# 2. Chuẩn hóa alias tại đúng một nơi.
concerns = profile.get("concerns") or profile.get("skin_issues") or []
ingredients = item.get("ingredients") or item.get("thanh_phan_chinh")
usage = item.get("usage_instructions") or item.get("huong_dan_su_dung")
# None = thiếu dữ liệu; không random, không tự thêm dữ kiện.

# 3. Chọn rồi kiểm tra lần cuối, cả kế hoạch và danh sách thẻ trả về.
candidate_products = hydrate_products(merge_by_product_id(php_ids, retrieved_ids))
eligible = enforce_constraints(candidate_products, request_constraints)
plan = optimize_routine(eligible, owned_products, request_constraints)
validated = validate_plan(plan, request_constraints)
# Chỉ validated.product_ids mới được dùng làm sản phẩm khuyến nghị.
```

Khi đổi history, hỗ trợ chuyển đổi ba format ở đầu vào một lần: `{role,content}`, `{sender,text}`, string legacy. Nội bộ chỉ dùng một format. State khảo sát không còn phụ thuộc `get_last_ai_message()`.

## Kế hoạch sửa theo thứ tự

1. **Ổn định tính đúng:** tắt cache cá nhân hóa; bỏ discount/HDSD tự tạo; sửa cart dict crash, DTO mapping và history. Đừng để lỗi API bị che thành gợi ý chung; điểm chưa đo là null. Chạy lại regression cho F01/F03–F07/F12.
2. **Sửa retrieval và constraints:** BM25 thật + dữ liệu + filter cả hai nhánh; xử lý phủ định/budget/subject; gộp trước tối ưu; validator cuối; test F02/F08–F11/F13. Đây là bước tạo cải thiện trước khi đổi model.
3. **Thống nhất kiến trúc:** một orchestrator, route exhaustively; thêm LangGraph/state/structured extraction, duy trì endpoint và response contract. Có adapter chuyển dần; so sánh endpoint cũ/mới trên bộ test cố định.
4. **Chọn embedding bằng số liệu:** chạy retrieval benchmark hiện tại và E5 trên cùng corpus đã sửa, cùng 50–100 query có nhãn; tạo index riêng; chọn theo recall/latency/bộ nhớ thực tế. Không rebuild đè index đang dùng.
5. **Thêm chiều sâu sản phẩm:** nguồn kiến thức kiểm duyệt, đồ đang dùng, routine versions, check-in và giải thích lựa chọn/thay thế. Chỉ thêm OR-Tools khi enumeration không còn đủ.

## Bộ nghiệm thu tối thiểu

Các tình huống tái hiện hiện tại cần chuyển thành regression assertions cho hành vi đúng sau khi patch. Khi code đã sửa, script audit có thể báo NOT REPRODUCED; không coi đó là lỗi chạy test. Bổ sung endpoint tests thực sự để kiểm tra adapter/routing.

| Tình huống | Kết quả đúng cần kiểm |
|---|---|
| Hai user hỏi cùng câu có profile khác | Không replay câu trả lời, product list hay conversation_id của nhau. |
| Khảo sát 4 câu, refresh giữa chừng, reload Mongo history | Tiếp tục đúng bước, lưu đúng lựa chọn, không hỏi lại vô hạn. |
| 1,5 triệu / 1.5tr / 500.000đ / 300–500k | Parse đúng số và loại ràng buộc; “không giới hạn” khác “chưa biết”. |
| Không muốn retinol; mẹ da khô; user da dầu | Exclusion được giữ; target person đúng; không sửa profile self ngoài ý muốn. |
| Routine 350k, upstream gửi thêm sản phẩm 800k | Tổng phần phải mua <=350k hoặc trả trạng thái không có phương án; card không lén thêm món ngoài plan. |
| Sản phẩm không có INCI/HDSD/giá gốc | Không phát biểu “không chứa”, không tự bịa cách dùng hay giảm giá. |
| Cart có một cảnh báo dạng object | Không 500, nội dung cảnh báo được truyền và hiển thị đúng. |
| Câu hỏi kiến thức chung | Có câu trả lời dựa nguồn; không ép đưa combo mua hàng. |
| Vector DB rỗng hoặc BM25 rỗng bất thường | Health/trace thể hiện degraded mode; không giả vờ hybrid thành công. |
| Model timeout, provider lỗi hoặc schema invalid | Có deadline tổng; không báo ok=true kèm câu bán hàng vô nghĩa; không cache lỗi. |
| Nguồn “không chứa X”, trả lời “chứa X” | Evaluator/validator đánh dấu mâu thuẫn, không chấm perfect. |

Tập thử đề xuất 60 case: 10 hội thoại/state, 10 constraints, 15 retrieval có nhãn, 10 tính có căn cứ, 10 lỗi phụ thuộc, 5 ngoài phạm vi/chuyển chuyên gia. 40 case phát triển + 20 case giữ lại; đây là bộ đồ án nhỏ, chưa đủ khẳng định độ an toàn lâm sàng. Một người rà soát nội dung chuyên môn độc lập khi có điều kiện.

Mục tiêu release: 0 vi phạm ràng buộc cứng trong tập kiểm thử đã định nghĩa; báo tỷ lệ/denominator rõ, không suy rộng thành “100% đúng”. Retrieval chỉ tính metric khi có nhãn chuẩn; phần trả lời đánh giá riêng tính đúng dữ kiện và tính thực dụng. Ghi trace: request_id (ẩn thông tin nhận dạng), route, model/provider, fallback reason, index version, doc IDs, top scores, reject reasons, validation failures, stage latency và token usage. Theo dõi mọi stage giúp phân biệt lỗi dữ liệu, retrieval, luật và generation.

## Tài liệu chính thức đã đối chiếu

- [Static embedding model card](https://huggingface.co/sentence-transformers/static-similarity-mrl-multilingual-v1): 1024 chiều, static averaging, không hướng đến retrieval.
- [Multilingual E5 small](https://huggingface.co/intfloat/multilingual-e5-small): 384 chiều, prefix query/passage, normalize, giới hạn 512 token.
- [Cross-encoder mMARCO model card](https://huggingface.co/cross-encoder/mmarco-mMiniLMv2-L12-H384-v1): model xếp hạng đa ngôn ngữ; cần đo trên dữ liệu của dự án.
- [rank_bm25](https://github.com/dorianbrown/rank_bm25): BM25Okapi nhận corpus đã tokenize.
- [Chroma collection configuration](https://docs.trychroma.com/docs/collections/configure): metric và index configuration; tài liệu hiện hành không đồng nghĩa API của bản 0.5.20.
- [LangGraph](https://docs.langchain.com/oss/python/langgraph/overview) và [persistence](https://docs.langchain.com/oss/python/langgraph/persistence): workflow có trạng thái/checkpoints.
- [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) và [Function calling](https://developers.openai.com/api/docs/guides/function-calling): schema và công cụ; không bảo đảm factual correctness.
- [OR-Tools CP-SAT](https://developers.google.com/optimization/cp/cp_solver): ràng buộc integer và trạng thái solver; ứng dụng vào routine là thiết kế của báo cáo này.

Thông số đề xuất là engineering starting points, không phải tuyên bố nhà cung cấp khuyến nghị riêng cho SkinSyntaxVN.
