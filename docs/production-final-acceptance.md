# Nghiệm thu website SkinSyntaxVN production

**Ngày kiểm thử** 25/11/2026, múi giờ UTC+7. **Website** [skinsyntaxvn.app](https://skinsyntaxvn.app/).

**Kết luận** Giỏ khách, bảo vệ route với khách, Chatbot catalog, Groq, semantic retrieval, Search và bộ lọc loại da có bằng chứng chạy thực tế. Nghiệm thu còn mở cho luồng tài khoản có OTP, Agent SSE, chuyển đổi LLM khi provider lỗi và chất lượng Recommendation theo loại da.

Đợt này tiếp tục từ [runbook RAG](chatbot-rag-production.md), [báo cáo triển khai](../DEPLOYMENT_REPORT.md), hai manifest runtime trên VPS và các ca [Playwright trước đó](../output/playwright/production-web/). Kết quả bên dưới được đo lại trên HTTPS bằng Chrome/Playwright và qua các probe chỉ đọc trên VPS. `/index.php` được rút gọn thành `?r=…` trong bảng. PASS đòi hỏi nội dung hoặc trạng thái đúng với mục đích ca. BLOCKED có nghĩa là điều kiện thực hiện còn thiếu hoặc chưa được phép mô phỏng.

## Bằng chứng

- [Giỏ hàng JSON](../output/playwright/production-web/acceptance-cart.json) và [ảnh giỏ có hàng](../output/playwright/production-web/acceptance-cart-filled.png).
- [Xác thực, route bảo vệ, Search và HTTPS JSON](../output/playwright/production-web/acceptance-web.json), [kiểm tra bổ sung JSON](../output/playwright/production-web/acceptance-extra.json) và [ảnh form đăng ký](../output/playwright/production-web/acceptance-signup.png).
- [Chat API JSON](../output/playwright/production-web/acceptance-chat.json), [widget JSON](../output/playwright/production-web/acceptance-widget.json) và [ảnh widget](../output/playwright/production-web/acceptance-widget.png).
- [Trích log và probe VPS đã lọc](../output/playwright/production-web/acceptance-vps.txt). Mốc trong log dùng UTC. Không lưu giá trị cookie, credential hay log thô.

## A. Giỏ hàng

| Ca | URL / endpoint | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| A1. Thêm khi chưa đăng nhập | GET `?r=chitiet&id=3879`, POST `?r=them_gio_hang_ajax` | 200 → 200 | Từ giỏ trống, nút thêm ở trang chi tiết trả `ok=true`, `cart_count=1`. Trang giỏ hiển thị mã 3879. | PASS | Giỏ JSON `initial`, `detail`, `add`, `cart`. |
| A2. Tăng số lượng | POST `?r=giohang` | 200 | Nút tăng đưa số lượng từ 1 lên 2. Tạm tính 458.000 ₫, phí 30.000 ₫, tổng 488.000 ₫. | PASS | Giỏ JSON `increase`. |
| A3. Giảm số lượng | POST `?r=giohang` | 200 | Nút giảm đưa số lượng về 1. Tạm tính 229.000 ₫, phí 30.000 ₫, tổng 259.000 ₫. | PASS | Giỏ JSON `decrease`. |
| A4. Giữ phiên và tổng tiền | GET `?r=giohang` | 200 | Sau khi chuyển từ chi tiết sang giỏ và tải lại, sản phẩm còn đúng số lượng và tổng tiền. Đơn giá 229.000 ₫ khớp Atlas. Phí 30.000 ₫ áp dụng dưới ngưỡng 700.000 ₫. | PASS | Giỏ JSON `refresh` và VPS `exact RAG product_3879`. |
| A5. Xóa và tải lại | POST rồi GET `?r=giohang` | 200 → 200 | Nút xóa làm giỏ trống. Tải lại vẫn trống. | PASS | Giỏ JSON `remove`, `refreshEmpty`. |

Chỉ thao tác trên phiên khách riêng của bài kiểm thử. Không gửi yêu cầu tạo đơn hoặc thanh toán.

## B. Xác thực và phân quyền

| Ca | URL / endpoint | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| B1. Mở form và validation trình duyệt | GET `/`, modal Đăng ký và Đăng nhập | 200 | Hai form hiện ra. Giá trị trống và email sai định dạng không hợp lệ. Đăng ký có trường bắt buộc cho email, mật khẩu và hai ô đồng ý. | PASS | Web JSON `auth`, ảnh form. |
| B2. Đăng ký gửi payload rỗng | POST `?r=xulydangky` → `/index.php?auth=register` | 302 → 200 | Form hiện thông báo “Vui lòng nhập đầy đủ thông tin.” | PASS | JSON bổ sung `signup.errorVisible=true`. Validation server chặn trước bước CAPTCHA/OTP và tạo tài khoản. |
| B3. Đăng ký thành công với OTP | `?r=gui_otp_dang_ky`, `?r=xulydangky` | — | PHP runtime có `MAIL_DEMO_MODE=1`. Chưa có hộp thư thử nghiệm với OTP được giao qua luồng hợp lệ. Chưa gửi OTP hay tạo tài khoản. | BLOCKED | VPS `mail_demo=true`. Chủ dự án xác nhận phương án nhận OTP test. |
| B4. Đăng nhập, refresh và đăng xuất | `?r=xulydangnhap`, GET trang xác thực, `?r=dangxuat` | — | Chưa chạy luồng đăng nhập thành công và kiểm tra phiên sau logout. | BLOCKED | Cần tài khoản test riêng. |
| B5. Admin/Staff với khách | GET `?r=admin_dashboard`, `?r=staff_dashboard` | 302, 302 | Cả hai chuyển về `?r=dangnhap`. | PASS | Web JSON `guards`. Ma trận quyền giữa tài khoản khách, staff và admin vẫn BLOCKED tới khi có các tài khoản test tương ứng. |

## C. Chatbot, RAG và Agent SSE

| Ca | URL / endpoint | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| C1. Chào hỏi | POST `?r=ai_chat_assistant` | 200 | Lời chào Syna hiện đúng ngữ cảnh, `products=[]`. PHP xử lý lời chào theo mẫu có sẵn dù payload báo `fallback=false`. | PASS | Chat JSON `greeting`, nhánh `isGreetingMessage` trong `HomeController.php`. |
| C2. Hỏi serum Niacinamide qua API và widget | POST `?r=ai_chat_assistant` | 200, 200 | Widget hiển thị câu trả lời. Công cụ đồ thị `find_products` trả sáu sản phẩm. Tất cả khớp ID, tên và giá với Atlas. Atlas xác nhận Niacinamide trong cả sáu. | PASS | Chat JSON `ingredient`, widget JSON và ảnh, VPS `Chat Atlas recheck`. Ví dụ mã 288 giá 281.000 ₫ và mã 400 giá 130.000 ₫. |
| C3. LLM tạo câu trả lời | POST `?r=ai_chat_assistant` | 200, 200 | Widget báo `graph_tool.answer_source=llm`. Hai mốc AI `[STORE_SUPPORT] generated` trùng ngay trước hai request PHP hoàn tất, với model `openai/gpt-oss-120b`. Model này được cấu hình ở nhánh Groq trong file Flask đã đối chiếu SHA-256. | PASS | VPS log 06:03:17Z và 06:07:37Z, widget JSON. Lượt này kiểm chứng sinh câu trả lời, chưa đo tier hoặc quota. |
| C4. Báo lỗi yêu cầu rỗng | POST `?r=ai_chat_assistant` | 400 | `ok=false`, có lỗi validation, không trả câu trả lời hay sản phẩm. | PASS | Chat JSON `invalid` và Nginx log. |
| C5. Fallback từ đường Agent | POST `?r=ai_chat_agent_stream`, POST `?r=ai_chat_assistant` | 404, 200 | Gọi trực tiếp Agent nhận `fallback=true`. Widget dùng assistant thường. Cờ Agent tắt nên widget chưa đi qua luồng SSE rồi mới fallback. | BLOCKED | Widget JSON `agentAttempts=[]` và Chat JSON `agent`. Kiểm thử lại khi Agent được bật. |
| C6. Fallback LLM khi provider lỗi | POST `?r=ai_chat_assistant` / `/api/chat/auto` | — | Hai lượt sản phẩm dùng LLM. Chưa quan sát lượt `answer_source=template` hoặc provider thứ hai tiếp quản sau lỗi. Không tiêm lỗi hoặc đổi key trên VPS. | BLOCKED | Groq thực sự trả lời. Gemini và OpenAI thiếu cấu hình. Kiểm tra có kiểm soát ở môi trường riêng nếu cần nghiệm thu đường lỗi. |
| C7. Health và truy vấn semantic của RAG | GET nội bộ `/api/health`, Chroma `products_rag_v1` | 200 / truy vấn SDK | AI báo 2.853 documents. Truy vấn “serum niacinamide cho da dầu” trả 5/5 ID đúng, tên và giá khớp Atlas, 5/5 có Niacinamide trong metadata. | PASS | VPS `RAG semantic fresh query`. Đường widget C2 sinh truy vấn bằng Graph tools. Ca C7 chạy embedding và retrieval trực tiếp trên collection RAG. |
| C8. Lookup ID chính xác | GET `?r=chitiet&id=3879`, lookup Chroma/Atlas | 200 / truy vấn SDK | `product_3879` khớp ID, tên, giá 229.000 ₫ và Niacinamide. Trang chi tiết có đúng form thêm sản phẩm này. | PASS | Giỏ JSON và VPS `exact RAG product_3879`. |
| C9. Stream Agent SSE thực | POST `?r=ai_chat_agent_stream` → Flask POST `/api/agent/chat` | 404, 404 | PHP giữ route ở `backend/app/routes/chat_agent.php`. `ChatAgentController::stream()` trả 404 trước relay vì `SYNA_AGENT_V2` tắt và `CHAT_AGENT_INTERNAL_SECRET` thiếu. Flask chỉ đăng ký blueprint khi cùng cờ bật. Khi route được đăng ký, handler vẫn đòi internal token. | BLOCKED | Chat JSON và probe VPS PHP/AI. Chờ quyết định phạm vi bật Agent. Nếu duyệt, kiểm thử relay có xác thực và `text/event-stream` trong bước riêng. |

AI health báo Gemini 0 key, Groq configured, OpenAI chưa configured. Log đã lọc không có dấu hiệu lỗi auth, quota, model hoặc kết nối khớp mẫu trong cửa sổ hai lượt đã kiểm. Nếu tái hiện lỗi, phân loại theo phản hồi provider và lỗi client. 401/403 liên quan xác thực hoặc quyền, 429 liên quan rate/quota, 404 kèm model hoặc mã model cụ thể liên quan cấu hình model, DNS/TLS/timeout liên quan kết nối. Không suy trạng thái provider từ mã 200 của health.

## D. Search và Recommendation

| Ca | URL / endpoint | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| D1. Tìm theo thành phần | GET `?r=timkiem&q=niacinamide` | 200 | 12 ID sản phẩm trong kết quả đã lấy mẫu đều visible và có Niacinamide trong Atlas. | PASS | Web JSON `search`, VPS `Web ingredient search`. |
| D2. Semantic Search | GET `?r=timkiem&exact=1&q=tinh chất có niacinamide cho da dễ đổ dầu`; POST nội bộ `/api/search/recommend`; GET `?r=search_more` | 200, 200, 200 | Trang HTTPS hiển thị 8 thẻ semantic, đều visible và có Niacinamide trong Atlas. API nội bộ trả ba nhóm, có 8 thẻ semantic khác đã đối chiếu Atlas, `degraded=false`. `mode=fallback` là nhãn của Search. Khối `search_more` hiển thị `category_alt` và `trending` theo thiết kế của `SearchMoreBuilder`. | PASS | JSON bổ sung `semanticWeb`, `searchMore` và VPS `Internal Search`, `Browser semantic sample`. |
| D3. Lọc da dầu | GET `?r=tatca&loai_da%5B%5D=dau` | 200 | Bộ lọc được chọn. Cả 24 ID trên trang đã kiểm có nhãn Atlas khớp “dầu” hoặc “Mọi loại da” theo điều kiện của catalog. | PASS | Web JSON `skin`, VPS `Full browser oily skin filter`. |
| D4. Gợi ý khách và API Recommendation | GET `?r=goiy`; POST nội bộ `/api/recommend` | 200, 200 | Trang khách có 60 liên kết sản phẩm và 4 điều khiển hồ sơ. API `ok=true`, có sáu ID đều visible trong Atlas. | PASS | Widget JSON `routine`, VPS `POST /api/recommend`. |
| D5. Chất lượng theo hồ sơ da dầu | POST nội bộ `/api/recommend`, `skin_type="Da dầu"` | 200 | Trước sửa, mã 93 gắn nhãn da khô. Sau đợt 1, sáu sản phẩm đều có nhãn da dầu trực tiếp. ID, tên, giá và nhãn khớp Atlas. | PASS | Xem ma trận và log production ở mục triển khai đợt 1. |

## E. HTTPS, session và bảo vệ route

| Ca | URL / endpoint | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| E1. Thuộc tính cookie PHP | GET `?r=giohang` trên phiên mới | 200 | Header `Set-Cookie` của PHPSESSID có `Secure`, `HttpOnly` và `SameSite=Lax`. Session giỏ giữ qua điều hướng và tải lại. PHP config xác nhận cùng cờ. | PASS | Giỏ JSON `initial.cookie`, `refresh`, log VPS. |
| E2. Mixed content | Trang chủ, form, Search, catalog lọc da | 200 | Playwright không ghi request HTTP hay cảnh báo mixed content trong các lần mở được lấy mẫu. | PASS | Web JSON `mixed=[]`. |
| E3. Dấu hiệu secrets trong nội dung công khai | Trang chủ, Search, catalog, 15 JS cùng origin và partial Search | 200 | Không thấy mẫu key Gemini/Groq/OpenAI, URI MongoDB chứa credential hoặc private key trong các nội dung đã lấy mẫu. Giá trị token phiên hoặc CSRF không được lưu. | PASS | Web JSON `secretMarkers=[]`, JSON bổ sung `secretMarker=false`. Phạm vi giới hạn ở URL và mẫu đã kiểm tra. |
| E4. Route nhạy cảm với khách | GET `?r=admin_dashboard`, `?r=staff_dashboard`, `?r=thanhtoan`, `?r=internal_agent_catalog_revision` | 302, 302, 302, 404 | Admin, Staff và checkout yêu cầu đăng nhập. Nginx chặn route nội bộ Agent công khai. | PASS | Web JSON `guards`, quy tắc ingress `nginx/nginx.conf`. |

## F. VPS và tính toàn vẹn dữ liệu

| Ca | Điểm kiểm tra | HTTP | Kết quả thực tế | Trạng thái | Bằng chứng và xử lý |
| --- | --- | --- | --- | --- | --- |
| F1. Container và tài nguyên | Docker Compose, Docker inspect/stats, host metrics | — | 8 container chạy. AI, Chroma, Mongo local, Neo4j và Redis healthy. Cả 8 có 0 restart và OOM=false trước và sau probe semantic. VPS 2 vCPU, RAM khả dụng cuối 3.544/7.776 MiB, 0 swap, ổ gốc còn 12/29 GiB, 58% sử dụng. Load average cuối 0,59 / 0,50 / 0,42. AI sample 0,40% CPU và 2,211 GiB RAM. | PASS | VPS log 05:59:42Z và 06:07:58Z. Các số CPU/RAM là mẫu tại thời điểm đo. |
| F2. Atlas, Chroma, Redis, Neo4j | Catalog, collections, Redis PING, Neo4j count | — | Atlas visible 2.853. `catalog_products_v1` và `products_rag_v1` đều 2.853, tập mã chuẩn hóa trùng Atlas. Catalog dùng ID thô, RAG thêm tiền tố `product_`. Legacy `products=0`. Redis PONG. Neo4j giữ 7.320 nodes và 92.754 relationships. | PASS | VPS log `Atlas visible`, `Redis PONG` và `Neo4j`. |
| F3. Log Nginx, PHP-FPM, AI và Caddy | `docker logs` trên bốn container | 200, 302, 400, 404 xuất hiện trong log bài kiểm | Nginx ghi đúng các status thử nghiệm. Không thấy PHP Fatal/Warning/Notice/Parse hay lỗi provider khớp mẫu lọc. Caddy ghi một lỗi khi chuyển tiếp luồng phản hồi, thiếu HTTP status và chưa gắn được với route thử nghiệm. | FAIL | VPS `Docker log snapshot`, `Caddy recheck`. Đối chiếu với upstream và status nếu lặp lại. Các ca Playwright đã hoàn tất không ghi nhận HTTP 5xx. |
| F4. Manifest thủ công | `sha256sum -c` trên VPS | — | Bốn file RAG và ba file cấu hình web đều khớp SHA-256 của manifest. | PASS | VPS log `SHA-256 RAG OK` và `SHA-256 web OK`. |

## Bàn giao

- Chủ dự án cần xác nhận tài khoản test thường và các vai trò cần kiểm, phương án nhận OTP test, và Agent SSE có thuộc phạm vi nghiệm thu bản này hay không. Nếu cần nghiệm thu failover provider, dùng môi trường riêng hoặc duyệt một cửa sổ kiểm thử có kiểm soát.
- Hai mục cần theo dõi là Recommendation đưa sản phẩm nhãn da khô vào mẫu da dầu và một lỗi trong lúc Caddy chuyển tiếp luồng phản hồi. Release đang phục vụ các luồng đã đánh PASS.
- Local Git ở nhánh `fix/chatbot-rag-production`, HEAD `39a548a28f5c015f056b5b4e03281d16bdd7fa39`. Working tree có thay đổi đang làm ở backend cache/dictionary, Live Studio và thành phần frontend. Lượt nghiệm thu chỉ thêm báo cáo này cùng script, JSON, ảnh và log đã lọc dưới `output/playwright/production-web/`. Không stage, commit, push hoặc chạy CI/CD.
- VPS vẫn ở baseline Git `490e7c7ebfdf78784c76c6b2365e7cd965ee31c7` với bản RAG thủ công. Các file cần giữ khi tích hợp CI/CD gồm `ai-service-flask/chatbot_service/chatbot_flask.py`, `ai-service-flask/chatbot_service/retrieval.py`, `ai-service-flask/chatbot_service/sync_rag_catalog.py` và `ai-service-flask/tests/test_rag_catalog_recovery.py`. Manifest web ghi `web.override.yml`, `php-fpm.web.conf` và `php-session.web.ini` ngoài checkout trong `/home/ubuntu/.skinsyntax-web-release-490e7c7/`. Thư mục manifest RAG là `/home/ubuntu/.skinsyntax-rag-release-39a548a/`.
- Lượt nghiệm thu không deploy, restart, sửa secrets, chạy sync/reindex, ghi đè source production, tạo đơn hoặc bật service tùy chọn.

## Bản vá chờ duyệt ngày 25/11/2026

Kiểm tra local ngày 25/11/2026 đạt **372 test Python, 44 subtest và 1 skip** trên các suite Recommendation, profile, chatbot, RAG và Agent Graph. Chạy lại ca RAG bị skip với Chroma loopback phiên bản cài trên Windows đã khởi động được server, nhưng thiếu tệp của embedding model trong cache offline nên ca cutover vẫn BLOCKED. Ca fallback provider giả lập đạt. `node --test frontend/public/assets/js/tests/chat-agent-stream.test.js` đạt **22 test**, gồm parse SSE chia giữa nhiều chunk, hủy stream, OTP UI và kiểm redirect. Đây là test cục bộ bằng fixture. Đợt phát hành trên HTTPS vẫn NOT TESTED. C5 trong bảng đã được sửa từ PASS thành BLOCKED vì production đang tắt Agent.

### Recommendation

`POST /api/recommend` gọi structured engine. Candidate retrieval dùng BM25 và semantic từ vectorstore Chroma, ghép RRF rồi rerank. Atlas hydrate theo ID và bổ sung ứng viên sau đó. Neo4j phục vụ các luồng Search và Graph tools riêng. Neo4j không cung cấp trực tiếp tập ứng viên của route structured này.

Loại da trước đây chủ yếu nằm trong câu truy vấn và lý do tư vấn. Hard validation bỏ qua loại da. MongoDB bổ sung từ các sản phẩm visible đầu tiên khi tập ứng viên ít. Bản vá dùng nhóm nhãn loại da để lọc ở cả hai lần validation, ưu tiên nhãn trực tiếp trước nhãn mọi loại da, và quét MongoDB theo hai nhóm nhãn. Nếu thiếu ứng viên phù hợp, API có thể trả ít hơn sáu. Danh mục, ngân sách, thành phần tránh và thứ tự xếp hạng trong cùng nhóm vẫn được áp dụng. Không cập nhật nhãn catalog hoặc reindex.

**Phạm vi xin duyệt đầu tiên** gồm đúng `ai-service-flask/structured_recommendation.py` trên VPS và restart riêng `ai-service` trên image hiện hành. SHA-256 file VPS trước sửa là `90f63063a56d8ba48d6205bbf045b860760d3361b8fa3e17d6990bac2f2ab6ff`. Cả bốn file RAG và ba file web đã được đối chiếu với manifest trên VPS. Script mới nhận đúng gói Recommendation, kiểm checksum và bind mount, cất file trước sửa trong staging quyền 0700, rồi chạy health và smoke da dầu. Nếu thất bại, script khôi phục file và restart riêng AI. Sau một lần deploy đã thành công, có thể dùng file `previous.py` trong thư mục release để phục hồi theo cùng cơ chế thay file, sau đó restart AI và kiểm health. Giữ cả ba file Compose hiện hành và đối chiếu lại SHA sau rollback.

**Production** đã cập nhật D5 theo kết quả triển khai đợt 1 ở cuối báo cáo. GitHub Actions cục bộ đã đổi sang dispatch thủ công với environment `production`, gói một file từ commit được chọn và host verification bắt buộc. Chưa cấu hình reviewer hoặc chạy workflow trên GitHub. Gói Agent và các service media cần quy trình release riêng.

SHA-256 của file Recommendation cục bộ là `2fdf51ad52de1eca80856d46afa1fb2bbb687e9f92e7f3e95e7b7130c132965d`. Bộ tái lập cấu hình web gồm `docker-compose.web.prod.yml` và hai file trong `docker/php/production/`. Hai file PHP cấu hình trùng SHA-256 với manifest VPS. Overlay Compose dùng đường dẫn tương đối cho release sau. Báo cáo giữ riêng manifest runtime VPS để tránh nhầm với bản tái lập. Kiểm tra Compose với bốn file cấu hình, cú pháp Bash và YAML workflow đều PASS với cấu hình giả lập. Kiểm tra thiếu token Agent cũng từ chối cấu hình như dự kiến.

### Agent, chatbot và xác thực

- **Đã xác minh nguyên nhân qua mã PHP và Python.** Cart conflict từ PHP là danh sách object. Hai đường chatbot nối các phần tử như chuỗi và có thể phát sinh `sequence item 0: expected str instance, dict found`. Bản vá chuẩn hóa dữ liệu đưa vào prompt và giữ cấu trúc `conflicts` trả về UI. GitNexus đánh giá hàm điều phối chatbot chung CRITICAL. Phát hành nhóm này riêng với Recommendation và xác minh lại manifest RAG sau khi thay file đã được duyệt.
- **BLOCKED trên VPS.** Cả PHP và Flask đang tắt `SYNA_AGENT_V2` và thiếu hai Agent secret. `CHAT_AGENT_INTERNAL_SECRET` xác thực kênh service-to-service qua token nội bộ. `CHAT_AGENT_TOKEN_SECRET` ký và xác minh danh tính/phạm vi từ PHP, đồng thời liên quan chữ ký hiệu ứng giỏ. Dùng hai giá trị riêng, có mặt ở cả hai service. Overlay Agent yêu cầu đủ hai biến, giữ listener riêng `nginx:8081`, và cấp cờ cho cả PHP/Flask. Sau phê duyệt chỉ recreate hai service bằng image hiện hành. Rollback bỏ overlay và recreate hai service với ba file Compose web cũ. Luồng mua hàng có cờ riêng.
- **NOT TESTED trên VPS.** Tool calling Groq, lỗi `APIStatusError`, SSE nhiều lượt, hủy stream, timeout, mất kết nối và fallback khi stream đang bật. Nghiệm thu phải kiểm event `meta`/token/terminal và không lặp thao tác giỏ khi hủy. Provider failover cần fixture hoặc một lượt lỗi được kiểm soát, cùng provider thứ hai cấu hình hợp lệ. Lượt Groq Graph tools trước đây không thay thế ca Agent tool calling.
- **BLOCKED cho OTP thật và hồ sơ tài khoản.** PHP báo `MAIL_DEMO_MODE=1`, `SMTP_HOST`, `SMTP_USER` và `SMTP_PASS` đều thiếu. Mailer hỗ trợ SMTP TLS. Chọn hộp thư SMTP hiện có và xác minh quota, điều khoản cùng người quản lý hộp thư trước khi bật gửi thật. Chủ dự án duyệt địa chỉ nhận OTP và việc tạo riêng Customer, Staff, Admin. Sau đó dùng Playwright cho đăng ký, quên mật khẩu, duy trì phiên, đăng xuất và ma trận quyền đối với trang lẫn API.

### Caddy, Livestream và các worker

- **Điều tra Caddy** ghi nhận 13:05:29 UTC+7, GET `cart_analyze`, HTTP/2, lỗi `stream closed` khi copy response. Nginx trong cửa sổ đối chiếu ghi bốn 200 và một 204 cho route này. PHP và AI không có dấu hiệu upstream failure theo mẫu kiểm tra. Dấu hiệu phù hợp với stream phía client đã đóng. Vì không có request ID, chưa ghép được với một dòng upstream duy nhất. Giữ nguyên timeout và buffer.
- **NOT TESTED cho Livestream end-to-end và các service media.** Probe VPS có 2 vCPU, 3.477 MiB RAM khả dụng, 12 GiB đĩa và 0 swap. Image dự án hiện có là PHP 418 MB và AI 4,83 GB. Chưa thấy image AI Idol, voice hay LiveKit trong inventory được lọc. Tách từng đợt: xác minh Livestream và endpoint LiveKit đã có trước, thử TTS/Voice theo nhu cầu, tiếp đến AI Idol và worker theo queue cần dùng. Image AI Idol API và các Celery worker dùng chung tag, chỉ cần build tag một lần khi được duyệt. Worker catalog có lịch chạy đồng bộ/reindex cần tách khỏi đợt media. SadTalker/Wav2Lip có dependency CUDA/GPU và Syna renderer chạy ngoài Compose. VPS hiện chưa có bằng chứng đủ tài nguyên hoặc dependency để bật toàn bộ.
- Trước từng nhóm, chụp disk/RAM/CPU, xác minh dependency và image cache, chỉ khởi động service được duyệt, rồi kiểm container restart, OOM, chức năng HTTPS và tài nguyên. Tắt nhóm vừa bật nếu mất ổn định. Không xóa volume, thay AWS hoặc gọi dịch vụ có thể phát sinh phí khi chưa duyệt.

## Triển khai Recommendation đợt 1

**Thời gian** 25/11/2026, 22:38–22:40 UTC+7. **Kết quả** `DEPLOYED`, không phải rollback. Phạm vi production chỉ gồm `ai-service-flask/structured_recommendation.py` và recreate riêng `ai-service` bằng Compose với `--no-deps --no-build --pull never --force-recreate --wait`.

- **Preflight PASS.** Bind mount `/home/ubuntu/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask:/app`. SHA-256 file cũ trên host và trong container đều là `90f63063a56d8ba48d6205bbf045b860760d3361b8fa3e17d6990bac2f2ab6ff`. Lưu file cũ, checksum, ID container, image ID và tag, danh sách service, dấu vân tay phần source ngoài phạm vi trong thư mục rollback riêng.
- **Runtime PASS.** Container `b63beead05c3` được thay bằng `6e3a514a8db6`, cùng image `sha256:cf273fffed852b720100f07aaa07dd4eff01480bb2844f3972308a2d09ce1fb5`. SHA-256 file mới trên host và trong container đều là `2fdf51ad52de1eca80856d46afa1fb2bbb687e9f92e7f3e95e7b7130c132965d`. Health 200, `ok=true`, 2.853 documents bằng số sản phẩm visible trong Atlas. AI healthy, 0 restart, OOM=false.
- **Phạm vi PASS.** Bảy container khác giữ nguyên ID. Hash diff tracked và hash trạng thái Git ngoài file phát hành trùng baseline đã chụp. Manifest bốn file RAG vẫn khớp. Không đổi secrets, không build, không pull image, không sync/reindex. Git VPS giữ HEAD `490e7c7ebfdf78784c76c6b2365e7cd965ee31c7`.

Các ca dưới đây gọi `POST /api/recommend` nội bộ trong production. Mỗi ID, tên, giá và nhãn loại da đã đối chiếu với Atlas. Cột “Mọi loại da” đếm các sản phẩm tương thích chung đứng sau nhóm có nhãn trực tiếp.

| Hồ sơ | HTTP | Nhãn trực tiếp | Mọi loại da | ID từ API | Kết quả |
| --- | --- | --- | --- | --- | --- |
| Da dầu | 200 | 6 | 0 | 223, 21, 2455, 1067, 18, 1200 | PASS |
| Da khô | 200 | 6 | 0 | 814, 674, 1561, 3901, 108, 2427 | PASS |
| Da nhạy cảm | 200 | 6 | 0 | 2555, 1789, 4716, 4756, 2353, 754 | PASS |
| Da mụn | 200 | 6 | 0 | 964, 263, 5181, 3576, 1426, 4841 | PASS |
| Da thường | 200 | 6 | 0 | 2028, 503, 1978, 1101, 229, 2123 | PASS |
| Da hỗn hợp chung | 200 | 0 | 6 | 115, 173, 92, 98, 101, 725 | PASS |
| Da hỗn hợp thiên dầu | 200 | 6 | 0 | 1576, 2155, 165, 4406, 18, 1200 | PASS |
| Da hỗn hợp thiên khô | 200 | 6 | 0 | 924, 814, 674, 5097, 249, 4824 | PASS |
| Da dầu, ngân sách 1 ₫ | 200 | 0 | 0 | Không có sản phẩm | PASS |

Atlas có 313 sản phẩm visible mang nhãn hỗn hợp thiên dầu, 216 mang nhãn hỗn hợp thiên khô và 0 mang nhãn hỗn hợp chung. Giá dương thấp nhất trong snapshot là 9.000 ₫. Ca ngân sách 1 ₫ trả danh sách rỗng đúng như dự kiến.

- **HTTPS PASS.** Playwright mở `https://skinsyntaxvn.app/index.php?r=goiy`, HTTP 200, bộ lọc loại da hiện diện, có 61 liên kết sản phẩm trong DOM và 0 console error. Một lượt HTTP độc lập phân tích HTML và xác minh 26 ID liên kết duy nhất có mặt trong Atlas. Trang khách dùng đường PHP công khai. Ma trận theo hồ sơ kiểm engine đã vá qua API nội bộ.
- **Hồi quy Search PASS.** `POST /api/search/recommend` trả 200, không degraded và giữ 8 mục semantic. VPS sau kiểm thử còn 3.430 MiB RAM khả dụng và 12 GiB đĩa.
- **Rollback.** Thư mục `/home/ubuntu/.skinsyntax-recommendation-release-2fdf51ad-20261125/` lưu `previous.py`, `previous.sha256`, `deployment.log`, `outcome` cùng metadata trước/sau. Nếu phải rollback, kiểm `previous.sha256`, thay nguyên tử đúng file production từ `previous.py` với mode của file runtime, rồi recreate riêng `ai-service` bằng cùng ba file Compose và cờ ở trên. Kiểm SHA cũ trong container, image ID, health documents và bảy service còn lại.

## Chuẩn bị đợt 2 cho Chatbot và Agent V2

**Preflight production chỉ đọc, 23:03 UTC+7 ngày 25/11/2026.** SHA host và container khớp cho ba file Chatbot bên dưới. File Recommendation tiếp tục khớp `2fdf51ad52de1eca80856d46afa1fb2bbb687e9f92e7f3e95e7b7130c132965d`. AI `6e3a514a8db6` và PHP `a56a4123b720` đều dùng bind mount, restart bằng 0, OOM=false. Nginx đã nạp listener nội bộ 8081. Cả PHP và Flask vẫn tắt Agent và thiếu hai secret Agent. AI runtime báo Groq có cấu hình. Kết quả Groq của chatbot cũ không xác nhận tool calling Agent.

### Đợt 2A sửa ngữ cảnh Chatbot

| File dưới `ai-service-flask/chatbot_service/` | SHA-256 production trước thay | SHA-256 bản vá local |
| --- | --- | --- |
| `chatbot_flask.py` | `83915b0b8e8b8d354b0d5cdfa68e78dc331e0c664bd7a1519acacc5dec1b9eb4` | `a3dc0b03e1b763638fc31da6c5e61c89dd8946bd2099c94b0fde7cc30780fe51` |
| `pipeline.py` | `eb875384428d512a11791915bd0124ed34c579d671941d4799c8ab153fd688bf` | `f57bd763b72b4a668e2b15bfccb4b1a2c7ba1f00d1ae00583bc1cd5f11640822` |
| `profile_context.py` | `6b36c26d870e22ef613d26c1205b3d3fac0b63634a505e76f58e69c52a90872c` | `64dd9672b294a667f5477e9de81479fc7e7ae6832e18c2dcd5c2578fdf511b64` |

- Sao lưu ba file trong thư mục rollback quyền 0700 và lưu SHA, quyền file, image ID, mount cùng ID của mọi service. So SHA gói local ngay trước ghi. Thay ba file rồi recreate riêng `ai-service` với ba file Compose production hiện hành, dùng `--no-deps --no-build --pull never --force-recreate --wait`.
- PASS khi cả ba SHA trong container khớp gói, health và tài liệu khớp catalog, payload có xung đột dạng object đi qua API mà vẫn trả đúng cấu trúc, Chatbot HTTPS và Search/Recommendation có dữ liệu thật. Kiểm SHA Recommendation đợt 1 và ba file RAG còn lại. `chatbot_flask.py` là file thuộc manifest RAG cũ, ghi SHA mới vào manifest release 2A và giữ manifest ban đầu làm mốc rollback.
- Nếu kiểm tra trọng yếu FAIL, khôi phục đủ ba file theo SHA trước đổi, recreate riêng AI bằng cùng image rồi chạy lại health và đọc lại hashes. Đợt 2A giữ Agent và secrets ở trạng thái hiện hành.

### Đợt 2B bật Agent SSE ở chế độ tư vấn

SHA-256 gói local 2B

| File | SHA-256 |
| --- | --- |
| `backend/app/services/ChatAgentGate.php` | `08f49b96d720ad4122cbe4695555047db32a25be479b37ac2741ab45a12302fd` |
| `backend/app/controllers/ChatAgentController.php` | `0c447745d6404e0c76fea2103dfbb7ddd5fb33db63da977b6693401050866eba` |
| `docker-compose.agent.prod.yml` | `22820aba5a09dc452da59cfd6053864dc906a4dcf77e304021fa1ad016f39f27` |

- Gói gồm `docker-compose.agent.prod.yml`, `backend/app/services/ChatAgentGate.php` và `backend/app/controllers/ChatAgentController.php`. Overlay đặt `SYNA_AGENT_PURCHASE_DISABLED=1` ở PHP. Gate giữ quyền chat và đọc đơn được xác thực, chặn quyền ghi giỏ, tạo bản nháp và các bước OTP/xác nhận mua khi cấu hình này bật, kể cả khi Redis lỗi. Bật tính năng mua cần duyệt và kiểm thử riêng.
- Cấp hai secret riêng trực tiếp trên máy chủ bằng cấu hình quyền truy cập hạn chế, sau khi được duyệt. Secret nội bộ xác thực service-to-service. Secret danh tính ký token scoped của PHP để Flask thực thi quyền người dùng. Kích hoạt cờ cho cả hai service và kiểm biến bằng trạng thái có/thiếu. Không thay key Groq. Dùng overlay cùng ba file Compose hiện hành rồi recreate AI và PHP theo thứ tự, giữ nguyên Nginx/Caddy.
- Ca smoke sau khi duyệt dùng [Playwright Agent SSE](../output/playwright/production-web/acceptance-agent-v2.js). Kiểm content type, meta, token, thẻ sản phẩm có ID thật và đúng một terminal `done`. HTTP 200 có terminal `error`, stream thiếu terminal hoặc 404 fallback đều không đạt ca Agent. Đối chiếu ID với Atlas và dùng log đã lọc để xác minh Groq tool calling. Ngắt giữa stream theo ca riêng, kiểm không phát sinh tác vụ ghi. Hội thoại nhiều lượt của khách xác thực cần tài khoản test đã duyệt.
- Widget hiện chỉ tự chuyển assistant cũ khi nhận 404 JSON `fallback=true`. Lỗi SSE đang hoạt động hiện có hành vi hiển thị lỗi. Chạy lại cùng yêu cầu sau khi stream đã bắt đầu có thể lặp hiệu ứng, nên ca này không được chấm PASS fallback từ hành vi 404 lúc cờ tắt. Hai provider thực tế chưa có cấu hình cho failover.
- Nếu enable lỗi, bỏ overlay, khôi phục hai file PHP từ backup và recreate PHP/AI theo ba file Compose trước đó. Xác minh legacy assistant HTTPS, AI documents và checksum file Recommendation. Không xóa dữ liệu hoặc rollback đợt 1.

**Bằng chứng local.** 364 test Python và 44 subtest đạt, 1 skip cần fixture Chroma. Hai test mới đi qua cả hai đường xử lý chatbot với hồ sơ, lịch sử, giỏ và xung đột dạng object. 22 test Node đạt. Năm suite PHP đạt 142 test với `output_buffering=65536`. Lượt CLI đầu tiên thiếu buffering làm script test PHPUnit-style mất khả năng đặt HTTP status sau khi đã in kết quả đầu, chạy lại với buffering đã qua. Playwright Chrome với response giả lập phân loại đúng bốn trường hợp stream thành công, cờ tắt, terminal lỗi và stream thiếu terminal. Compose xác thực với biến giả. **Production đợt 2 chưa được triển khai.** C5 và C9 vẫn BLOCKED. Groq Agent tool calling, phiên đăng nhập nhiều lượt và failover thực tế vẫn NOT TESTED.

## Kết quả tiếp nối ngày 26/11/2026

| Ca | Endpoint / thao tác | HTTP | Kết quả và bằng chứng | Trạng thái |
| --- | --- | --- | --- | --- |
| Chatbot 2A payload thực | POST nội bộ `/api/chat/auto` | 200 | Hồ sơ, lịch sử và giỏ giả lập. Object xung đột giữ nguyên, trả sáu thẻ và `answer_source=llm`. | PASS |
| Chatbot 2A qua website | POST `?r=ai_chat_assistant` | 200 | Sáu thẻ thật, có câu trả lời và `answer_source=llm`. | PASS |
| Health và hồi quy AI | GET `/api/health`, POST `/api/recommend`, POST `/api/search/recommend` nội bộ | 200 | 2.853 tài liệu, Recommendation trả sáu thẻ tương thích thử nghiệm da dầu, Search có nhóm semantic và không degraded. | PASS |
| Agent SSE xuyên tầng | POST `?r=ai_chat_agent_stream` | 200 | Lượt 2 nhận `meta,ui,ui,error`, có năm ID nhưng không có token và không có `done`. Tự rollback, khôi phục SHA PHP và health RAG. | FAIL |
| Gmail SMTP | Gmail SMTP STARTTLS và AUTH từ VPS | — | TLS 1.3, chứng chỉ hợp lệ, Gmail AUTH 235, chưa gửi thư. | PASS |
| Auth deploy | PHP-FPM, GET `/`, GET `?r=gui_captcha_dang_ky` | 200 | PHP đã nạp cấu hình Gmail, demo OFF, sender trùng tài khoản SMTP. Cookie CAPTCHA Secure, HttpOnly, SameSite=Lax. | PASS |
| Playwright sau Auth deploy | Trang chủ, modal, Search, catalog | 200 | Login/signup hiển thị và từ chối dữ liệu trống/email sai. 15 tài nguyên JS cùng origin tải được. Không có mixed content. | PASS |
| Guest access guards | Admin, Staff, checkout và route nội bộ Agent | 302/404 | Các route giao diện yêu cầu login. Route nội bộ không công khai. | PASS |
| Ảnh catalog đối tác | Ảnh tại `media.hcdn.vn` | 404 | Tái xuất hiện một ảnh nguồn ngoài lỗi. | FAIL |
| Email nhận thực và tài khoản | Đăng ký, OTP resend, quên/reset mật khẩu, phiên và ma trận quyền | — | Chủ dự án đã duyệt email Customer test. POST gửi OTP đạt ở ca bổ sung bên dưới. Chờ chủ dự án nhận và nhập OTP. Staff/Admin vẫn thiếu tài khoản test riêng. | BLOCKED |
| Google và Facebook OAuth | `?r=auth_social_callback&provider=google`, `?r=auth_social_callback&provider=facebook` | — | Runtime chưa có đủ credentials. Credentials local có mặt nhưng chưa xác minh dashboard và liên kết định danh. | BLOCKED |
| Google reCAPTCHA | Form đăng ký và OTP | — | Ứng dụng hiện dùng challenge nội bộ. Chưa có bộ khóa reCAPTCHA khớp domain và chưa có tích hợp siteverify. | BLOCKED |
| Failover provider thật | Agent đang rollback, chưa có hai provider chạy được | — | Fixture cục bộ qua. Chưa có bằng chứng failover production hoặc hội thoại khách đăng nhập. | NOT TESTED |

Đợt 2A kết thúc lúc 00:07 UTC+7 và giữ nguyên các service ngoài AI. Backup, manifest mới và log đặt tại `~/.skinsyntax-chatbot-2a-a3dc0b03-20261126/`. Giữ manifest RAG ban đầu để rollback, file Flask mới lấy SHA từ manifest 2A.

Agent có hai lượt rollback. Lượt đầu dừng do thiếu hàm xác minh danh sách container trong script. Lượt sau thất bại ở terminal SSE. `~/.skinsyntax-agent-2b-20261126/` giữ log và backup. Secret nội bộ và secret ký token được tạo riêng trong file mode 0600. Sau rollback, overlay Agent không được nạp. Không đổi Groq key.

Auth deploy dùng lại image PHP, với source và overlay có checksum từ thư mục `~/.skinsyntax-auth-smtp-20261126/`. File SMTP trên VPS có mode 0600 và overlay chỉ inject vào PHP. Rollback khôi phục `previous.php`, bỏ overlay SMTP rồi recreate riêng PHP bằng Compose web hiện hành. Các lần recreate PHP tiếp theo cần giữ overlay SMTP. Test local của phần này gồm PHP lint, 14 Mailer, 24 AuthThrottle, 20 ChatOrderOtp và fixture chặn đăng nhập giả trên domain production. Google/Facebook vẫn chưa được bật.

### Voice, responsive và mobile

| Ca | Endpoint | HTTP | Kết quả | Trạng thái |
| --- | --- | --- | --- | --- |
| Voice startup | GET nội bộ `/api/health` | 200 | ONNX model loaded, service healthy, 0 restart, OOM=false. Không có host port binding. | PASS |
| Tổng hợp tiếng Việt | POST nội bộ `/api/tts` | 200 | WAV đọc được, 145.920 frames, 48 kHz. Smoke website sau Voice vẫn đạt. | PASS |
| Stream tiếng Việt | POST nội bộ `/api/tts/stream` | 200 | Content type `audio/pcm`, nhận được 512 byte đầu. | PASS |
| Search Worker | Docker health/heartbeat và trang Search HTTPS | 200 | Tái dùng image AI, không build, không publish port. Healthy, heartbeat 5,85 giây, 37,59 MiB RAM trên giới hạn 256 MiB, 0 restart, OOM=false. Trang Search vẫn 200. | PASS |
| Trang chủ responsive | GET `/` | 200 | Chrome Playwright tại 1440, 768 và 390 px. Không tràn ngang, không có uncaught page error. | PASS |
| Trang Livestream khách | GET `?r=live` | 200 | Có HTML thật. Chưa thử phát và nhận stream. | PASS |
| API Expo production | GET `?r=mobile_api&action=catalog.product&id=3879` và GET hồ sơ/giỏ/đơn với khách | 404 | Trả HTML 404 cho cả bốn URL. Mã controller và lớp lưu session/cart còn untracked, entrypoint frontend còn thay đổi local. Expo typecheck đạt, đồng bộ end-to-end chưa được nghiệm thu. | FAIL |
| LiveKit, AI Idol, avatar, worker | Luồng media và vận hành queue | — | LiveKit URL/key có cấu hình, chưa xác nhận credential/room hoạt động. VPS không có NVIDIA runtime. Scheduler/catalog worker chưa bật để tránh đồng bộ hoặc reindex ngoài phạm vi. | NOT TESTED |

Voice image 2,58 GB, một worker nội bộ, giới hạn 0,75 CPU và 1.400 MB RAM, một thread. Sau smoke Voice dùng khoảng 708 MiB. VPS còn khoảng 3.069 MiB RAM khả dụng và 7,7 GiB đĩa. Nếu cần rollback riêng Voice, dùng Compose production có overlay `~/.skinsyntax-voice-20261126/voice.override.yml` và `stop voice-service`. Giữ cache model và volume. Log build/smoke cùng thư mục. Bản overlay tái lập trong repo là `docker-compose.voice.prod.yml`. [Responsive JSON](../output/playwright/production-web/master-responsive.json)

### Callback OAuth cần chủ dự án xác minh

- Google Auth Platform, client Web. Origin `https://skinsyntaxvn.app`, redirect `https://skinsyntaxvn.app/index.php?r=auth_social_callback&provider=google`. Kiểm Branding, Audience, trạng thái publishing và tài khoản test. [Google OAuth Web Server](https://developers.google.com/identity/protocols/oauth2/web-server)
- Meta App Dashboard, App Domains `skinsyntaxvn.app`, Website URL `https://skinsyntaxvn.app/`, Valid OAuth Redirect URI `https://skinsyntaxvn.app/index.php?r=auth_social_callback&provider=facebook`. URI override trên VPS hiện khác URI production dự kiến. Kiểm app mode và quyền `email`, `public_profile` trước enable. [Meta App Dashboard](https://developers.facebook.com/apps/)
- Trong mã callback hiện tại, cần hoàn thiện liên kết định danh bền vững dựa trên provider subject và quy trình liên kết tài khoản có xác thực. Chưa nạp credential OAuth vào PHP production để tránh kích hoạt luồng liên kết cũ chỉ dựa trên email. [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect)

### Kết quả xác minh bổ sung

- **OTP gửi yêu cầu PASS.** Playwright điền email test đã được duyệt, nhập challenge đang hiển thị và gọi POST `?r=gui_otp_dang_ky`. HTTP 200, `ok=true`, response không trả OTP. Chờ chủ dự án xác nhận thư đến và nhập mã trực tiếp vào form. Đăng ký hoàn tất, nhận OTP qua hộp thư, resend, expiry, replay, reset mật khẩu và đăng nhập vẫn chưa qua nghiệm thu production.
- **Agent diagnostic FAIL, đã rollback.** Lượt chẩn đoán trả `meta,error`. Log lọc xác nhận model Groq `openai/gpt-oss-120b` bị `OpenAIInvalidRequestError` HTTP 400. Bằng chứng chưa xác định trường request nào bị từ chối. HTTP 400 này không phải bằng chứng quota hoặc mất kết nối. Khôi phục Agent off, giữ SMTP thật và xác minh AI vẫn có 2.853 tài liệu. Log ở `~/.skinsyntax-agent-2b-20261126/diagnostic/trial.log`. Bản chẩn đoán local ghi loại lỗi và status, không ghi body provider. 15 test unit đạt. [Groq Tool Calling](https://console.groq.com/docs/tool-use/local-tool-calling)
- **Fixture mobile và Live PASS.** MobileApiStoresTest 9 assertions. Sáu suite PHP local đạt về LiveKit Egress, phân quyền Live, khám phá, Admin, Studio và luồng dữ liệu Live. Đây là kiểm tra hợp đồng local. Luồng livestream phát/nhận thật và mobile production còn mở.
- **Rollback Search Worker.** Log tại `~/.skinsyntax-search-worker-20261126/`. Dùng Compose production `stop search-worker` nếu cần dừng riêng consumer. Giữ Redis và MongoDB.

**Chốt lượt hiện tại** gồm 13 PASS trong hai bảng, thêm 1 PASS gửi OTP, 3 FAIL, 3 BLOCKED và 2 NOT TESTED. Lượt chẩn đoán lặp lại thuộc cùng ca Agent FAIL. VPS đang chạy 9 container, còn 2.933 MiB RAM và 7,7 GiB đĩa. AI, PHP, Voice và Search Worker đều có 0 restart và OOM=false. Agent off, SMTP on. Git VPS giữ baseline `490e7c7` với các release thủ công. Git local ở `56545cb` trên `fix/chatbot-rag-production`, gồm bảy commit local trong đợt này. CI/CD remote chưa chạy.
