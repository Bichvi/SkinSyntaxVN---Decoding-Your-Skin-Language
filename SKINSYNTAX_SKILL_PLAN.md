# Kế hoạch hai giai đoạn cho skill SkinSyntaxVN

Ngày 17/11/2026 · Bản điều chỉnh theo ưu tiên UI/UX và khóa luận · **ĐỀ XUẤT CHỜ DUYỆT**

## 1. Mục tiêu và quyết định đề xuất

**Giai đoạn A ưu tiên UI/UX có bản sắc, bộ UML/ERD bám mã, hoàn thiện báo cáo Word hiện có và phương pháp làm việc của Codex. Giai đoạn B giữ ba workflow chuyên sâu về checkout MongoDB, bảo mật PHP và hợp đồng PHP–Flask. Rủi ro P0 checkout là điều kiện bắt buộc phải giải quyết trước khi chốt bản demo hoặc bảo vệ.**

| Hạng mục | Quyết định đề xuất | Thời điểm |
|---|---|---|
| Frontend Design | Chuẩn hóa bản hướng dẫn đã có theo upstream Anthropic, bổ sung license và đóng gói thành skill repo | A1 |
| UI UX Pro Max | Đề xuất thử nghiệm có giới hạn một skill và dữ liệu cần thiết, từ commit đã kiểm tra. Chấp nhận vào bộ dùng thường xuyên sau kiểm tra giá trị bổ sung | A1, sau khi duyệt phạm vi file |
| lucian-uxui | Tái dùng skill đã cài khi cần tìm mẫu tương tác và port thành phần phù hợp | A1 |
| skinsyntax-uml-documentation | Tạo skill mới trong repo, sở hữu Use Case, đặc tả và bộ diagram có source cùng kiểm tra render | A2 |
| skinsyntax-thesis-support | Nâng cấp skill hiện có cho chỉnh sửa Word có bảo toàn nội dung, nguồn và hình. Đề xuất đưa bản chính về repo trong một bước di chuyển được duyệt | A3 |
| Quy trình Codex | Bổ sung vào workflow hiện có và kiểm tra điều kiện chọn skill bằng eval. Tái dùng GitNexus, safe-development, regression, debug/review hiện có | A4, đồng hành A1–A3 |
| Superpowers | Chưa đề xuất cài toàn bộ ở đợt đầu. Có lợi ích về phương pháp nhưng trùng nhiều bước và thêm cơ chế điều phối cần kiểm soát | Đánh giá lại sau A4 |
| Ba skill kỹ thuật trong kế hoạch trước | Giữ đầy đủ thiết kế checkout, PHP security và PHP–Flask contract trong backlog B | B1–B3, P0 trước mốc demo |

Các quyết định trên là đề xuất. Thao tác hiện tại chỉ cập nhật file kế hoạch này. Trạng thái cài skill, sửa ứng dụng, sửa Word, chạy test ứng dụng và xuất diagram là **NOT RUN**.

## 2. Căn cứ khảo sát và tình trạng hiện có

Phạm vi là checkout `D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1`, nhánh `fix/stability-and-bug-fixes`, HEAD `23de852890f7f1fca7e158a372520bab83e39368`. Có 16 mục tracked đang sửa từ trước. Kế hoạch dùng báo cáo audit, kế hoạch trước, hướng dẫn dự án, skill local, source/manifest upstream công khai. Các file nhạy cảm, dump và dữ liệu người dùng nằm ngoài phần đọc nội dung. [Báo cáo audit](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/CODEX_THESIS_AUDIT.md), [Hướng dẫn dự án](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/AGENTS.md).

**Đã xác minh tại local.**

- `.agents/skills/` có chín thư mục `SKILL.md` cho E2E, frontend-testing, lint và sáu skill GitNexus. Có thêm các hướng dẫn `testing.md`, `debugging.md`, `code-review.md`, `documentation.md` và `refactoring.md`.
- `.agents/skills/design.md` hiện chứa frontmatter `name: frontend-design` và nội dung hướng dẫn thiết kế. Nội dung khác bản upstream đã kiểm tra. Dòng license trỏ `LICENSE.txt`, trong khi file license đi kèm chưa được tìm thấy trong danh mục thư mục này. Đây là bản hướng dẫn cần chuẩn hóa discovery và nguồn gốc trước khi thêm bản khác.
- Bốn skill SkinSyntax safe-development, regression-testing, architecture-documentation và thesis-support đang có file tại `%USERPROFILE%/.codex/skills/`. Skill thesis hiện thiên về bằng chứng nghiên cứu, chưa có quy trình sửa Word hiện hữu theo từng phần.
- `lucian-uxui` có tại `%USERPROFILE%/.agents/skills/lucian-uxui/`. Documents/PDF/Presentations và Skill Creator được host cung cấp.
- Tám cặp skill GitNexus/lint/frontend-testing trong `.agents` và `.claude` có cùng nội dung theo lần kiểm tra trước trong kế hoạch. E2E có khác biệt. Đợt ưu tiên mới phát triển ở `.agents`.
- Có launcher Node, Python và Codex trong PATH. Có Mermaid CLI `12.2.0` theo package manifest, yêu cầu Node `>=22.13.0`. Phiên bản runtime thực tế và browser renderer chưa được xác minh. PlantUML/Graphviz chưa được nhận diện bằng tên lệnh trong PATH.

[Frontend Design đang có](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/skills/design.md), [Skill repo](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/skills), [Lucian đã cài](C:/Users/ACER/.agents/skills/lucian-uxui/SKILL.md), [Thesis Support hiện có](C:/Users/ACER/.codex/skills/skinsyntax-thesis-support/SKILL.md).

**Nguồn upstream đã đối chiếu qua API và raw source, với commit được cố định cho lần đánh giá này.**

| Nguồn chính thức | Commit | Phần đã xem |
|---|---|---|
| nextlevelbuilder/ui-ux-pro-max-skill | `e59ff376a41f7ca47b7541ad5c116495cbade3ee` | README, SKILL, template Codex, CLI manifest, installer/template và imports/IO của các script tìm kiếm, design system, preview |
| anthropics/skills | `e4bb5d960d87126ff3b3a8af1a104b7db67560ba` | `skills/frontend-design/SKILL.md`, LICENSE.txt và cây file của riêng skill |
| obra/superpowers | `22f0b463e7a5e579904dd6211a520321035526f7` | README, LICENSE, manifest Codex, package, hook manifest, using-superpowers, brainstorming, writing-plans, systematic-debugging, verification-before-completion và hướng dẫn Codex liên quan |

Đây là kiểm tra tĩnh có phạm vi. Tính tương thích thực thi, toàn bộ dependency bắc cầu, hành vi mọi script và trạng thái marketplace của tài khoản chưa được kiểm chứng.

## 3. Giai đoạn A1 — UI/UX và lựa chọn skill thiết kế

### 3.1. Vai trò của ba nguồn thiết kế

| Nguồn | Vai trò phù hợp SkinSyntaxVN | Sự phối hợp |
|---|---|---|
| Frontend Design | Định hướng thị giác theo nội dung thật, typography, bố cục, chi tiết tạo bản sắc và tự phê bình thiết kế | Dùng khi dựng mới hoặc cải thiện đáng kể màn hình. Design brief của SkinSyntaxVN là căn cứ chọn phương án |
| UI UX Pro Max | Tra cứu guideline về UX, màu/chữ, responsive, form, accessibility, biểu đồ và design tokens | Dùng cho câu hỏi UX cụ thể hoặc hệ thống thiết kế. Kết quả tra cứu cần đối chiếu nội dung và stack của dự án |
| lucian-uxui | Tìm mẫu thành phần tương tác, lấy mẫu đã chọn và port vào theme/framework của repo | Dùng khi cần mẫu tương tác hoặc người dùng yêu cầu Lucian. Kiểm tra mẫu có phù hợp bố cục đã chọn |

Frontend Design xử lý quyết định thị giác. UI UX Pro Max bổ sung kho tra cứu và tiêu chí chất lượng. Lucian cung cấp mẫu có hành vi. Với thay đổi nhỏ, chọn thành phần hiện có hoặc một công cụ đáp ứng yêu cầu. Luồng thiết kế lớn có thể dùng cả ba theo từng công việc khi có giá trị cụ thể. [Frontend Design chính thức](https://github.com/anthropics/skills/blob/e4bb5d960d87126ff3b3a8af1a104b7db67560ba/skills/frontend-design/SKILL.md), [UI UX Pro Max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/blob/e59ff376a41f7ca47b7541ad5c116495cbade3ee/.claude/skills/ui-ux-pro-max/SKILL.md).

### 3.2. Tương thích, dependency, license và bảo mật

| Tiêu chí | UI UX Pro Max | Frontend Design | lucian-uxui đã cài |
|---|---|---|---|
| Codex Desktop | Template Codex trỏ `.agents/skills/ui-ux-pro-max/`, phù hợp cơ chế discovery local. README nêu Codex CLI. Desktop cần kiểm tra discovery và chạy script trong project thật | SKILL dạng Markdown có metadata chuẩn, có thể đóng gói vào `.agents/skills/frontend-design/`. Đây là suy luận tương thích theo định dạng, cần thử trên Desktop | Skill đang có trong danh mục phiên. Khả năng chạy search/get với mạng/cache cần kiểm tra khi giao nhiệm vụ |
| Dependency thực thi | Python 3.x và dữ liệu/script local. Các file Python đã rà import chủ yếu standard library và module cùng gói. CLI installer 2.5.0 có dependency Node riêng | Cây riêng của skill gồm SKILL.md và LICENSE.txt, chưa thấy script hoặc package cần chạy cho phần hướng dẫn | CLI Node đã kèm skill, truy cập catalog/snippet qua HTTPS, có cache và fallback mirror |
| License | Repo khai báo MIT. Giữ LICENSE và thông tin nguồn cho phần lấy vào repo | File riêng skill ghi Apache-2.0. Giữ LICENSE.txt, các notice liên quan và ghi thay đổi nếu sửa bản lấy về | Hướng dẫn yêu cầu giữ dòng thông tin MIT trên snippet được dùng. Kiểm tra notice của từng mẫu khi lấy |
| Điểm ghi dữ liệu | Search có lựa chọn persist, preview và tokens tạo file. Installer tạo skill và có thao tác dọn cấu trúc cũ | Chỉ thị hướng dẫn vẫn có thể dẫn tới sửa UI khi tác vụ cho phép, nên scope nhiệm vụ cần rõ | CLI có thể ghi cache, tùy chọn output và snippet có script cần đọc trước port |
| Đánh giá bảo mật hiện tại | Rà imports của năm file Python không thấy các mẫu network-import hoặc eval/exec đã kiểm tra. Kết quả này chưa chứng minh toàn gói an toàn. Installer là phạm vi IO riêng | Bề mặt thực thi trực tiếp nhỏ ở hai file văn bản. Nội dung vẫn là tài liệu ngoài cần rà và giới hạn theo yêu cầu của user | Mã snippet từ mạng cần rà DOM insertion, event handling, fetch/storage và URL trước khi tích hợp |

[Template Codex](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/blob/e59ff376a41f7ca47b7541ad5c116495cbade3ee/src/ui-ux-pro-max/templates/platforms/codex.json), [CLI manifest](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/blob/e59ff376a41f7ca47b7541ad5c116495cbade3ee/cli/package.json), [MIT của UI UX Pro Max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/blob/e59ff376a41f7ca47b7541ad5c116495cbade3ee/LICENSE), [Apache-2.0 của Frontend Design](https://github.com/anthropics/skills/blob/e4bb5d960d87126ff3b3a8af1a104b7db67560ba/skills/frontend-design/LICENSE.txt), [Lucian local](C:/Users/ACER/.agents/skills/lucian-uxui/SKILL.md).

**Điểm cần xử lý trước khi cài UI UX Pro Max.** Installer hiện tại gọi hàm copy các skill sibling, sinh tổng cộng bảy skill gồm orchestrator và các gói phụ như brand, design, slides. Có cả logic dọn cấu trúc cũ. Gói slides chồng với Presentations hiện có. Đề xuất lấy đúng phần search/guideline cần dùng từ commit đã duyệt, bảo toàn dữ liệu/module/reference mà SKILL thực sự dùng, rồi kiểm tra cấu trúc đã đóng gói. Nếu chọn installer, phải xem danh sách thao tác trong một sandbox trước và chỉ chấp nhận phạm vi được duyệt. [Template/install logic](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill/blob/e59ff376a41f7ca47b7541ad5c116495cbade3ee/cli/src/utils/template.ts).

**Điểm cần xử lý với Frontend Design.** Chuẩn hóa bản đang có sau khi so sánh local–upstream và giữ nội dung tùy biến có giá trị. Đề xuất đưa nội dung về thư mục `frontend-design/SKILL.md`, kèm license và ghi nguồn/commit. Xác minh các tham chiếu tới `design.md` trước khi chuyển vị trí. Việc cập nhật bản chính tránh để hai bản hướng dẫn khác nội dung cùng được dùng như nguồn chính.

**Tương thích stack.** SkinSyntaxVN hiện dùng PHP SSR, Bootstrap và JavaScript thuần. Các ví dụ HTML+Tailwind, React hay Laravel trong kho tra cứu chỉ có thể dùng phần nguyên tắc phù hợp. Chọn cách triển khai bằng hệ thống class, asset và handler hiện có. UI mới vẫn đi qua dữ liệu và action thật của dự án.

### 3.3. Workflow UI/UX đề xuất

**Kích hoạt** khi người dùng yêu cầu thiết kế màn hình, cải thiện thẩm mỹ/UX, rà responsive/accessibility, xây design system hoặc tìm mẫu tương tác.

1. Khảo sát đối tượng khách hàng/staff/admin, mục tiêu màn hình và hành trình tìm kiếm → chi tiết → giỏ. Kiểm kê logo, màu, chữ, ảnh sản phẩm, cấu trúc navigation và thành phần có sẵn.
2. Lập brief ngắn cho SkinSyntaxVN với bố cục, token chữ/màu/khoảng cách, nội dung và một chi tiết thị giác có lý do. Tránh mặc định ghép gradient tím–xanh, card lặp và hiệu ứng trang trí không phục vụ thao tác.
3. Dùng Frontend Design cho phương án bố cục. Tra UI UX Pro Max theo vấn đề như lỗi form, focus, điều hướng hoặc density. Dùng Lucian khi cần mẫu phù hợp, ghi ID/nguồn/license.
4. Chọn một màn hình thử có phạm vi rõ, đề xuất trang chi tiết hoặc trang chủ. Tài liệu phân tích và mockup có thể thực hiện trước. Mọi đợt chỉnh source được giao riêng sau khi duyệt phạm vi.
5. Đối chiếu thiết kế với ngôn ngữ Việt, dữ liệu sản phẩm, trạng thái tải/trống/lỗi/hết hàng và thao tác bằng touch/bàn phím.
6. Khi đã được giao triển khai, dùng browser/Playwright xác minh desktop và mobile, focus, giảm chuyển động, dark mode tại phần có hỗ trợ, UI feedback và handler thật. Rà ảnh trước/sau theo cùng fixture.
7. Lưu quyết định thiết kế và các giới hạn đã kiểm tra, giữ thay đổi có bằng chứng cải thiện.

**Tiêu chí hoàn thành.** Có brief, token và phương án bám nội dung SkinSyntaxVN. Các thành phần dùng lại và license có nguồn. Khi giao code, luồng chính hoạt động qua entrypoint thật, có bằng chứng trình duyệt ở màn hình rộng/hẹp và lỗi còn tồn tại được ghi cụ thể. Chất lượng thẩm mỹ được duyệt qua hình/render, cùng với các kiểm tra hành vi có assertion.

**Đầu ra.** Brief/design tokens, bản đề xuất màn hình, danh sách snippet được chọn, ảnh so sánh và báo cáo kiểm chứng. Tài liệu dự kiến ở `docs/design/skinsyntax-ui-brief.md` chỉ tạo khi thực hiện A1.

## 4. Giai đoạn A2 — Skill mới skinsyntax-uml-documentation

**Mục tiêu.** Tạo bộ diagram và đặc tả khóa luận truy vết được về implementation, đồng thời giữ source chỉnh sửa được. Skill mới tập trung ngữ nghĩa UML, mô hình dữ liệu và công việc xuất hình. `skinsyntax-architecture-documentation` tiếp tục cung cấp khảo sát kiến trúc, API và ranh giới hệ thống làm bằng chứng đầu vào.

**Kích hoạt** với yêu cầu Use Case Diagram/đặc tả Use Case, Activity, Sequence, Class, ERD, mô hình MongoDB/Neo4j hoặc sơ đồ kiến trúc SkinSyntaxVN. Yêu cầu làm đẹp một màn hình chỉ dùng workflow UI liên quan.

### 4.1. Trích xuất actor và Use Case

1. Xác định entrypoint/routes đang hỗ trợ, alias và controller/service/model được gọi. Với GitNexus, gắn đúng repo/worktree/index, rồi đối chiếu guard và call HTTP thủ công khi graph thiếu cạnh.
2. Đọc kiểm tra session, role và ownership trong handler và lớp dùng chung. Lập ma trận actor → mục tiêu nghiệp vụ → hành động → điều kiện → bằng chứng file/symbol.
3. Gom nhiều route phục vụ cùng mục tiêu thành use case nghiệp vụ. Endpoint nội bộ hoặc bước kỹ thuật được thể hiện theo vai trò trong luồng.
4. Đối chiếu đặc tả đã viết với hành vi đã kiểm tra. Ghi phần thiếu, sai khác, route mồ côi hoặc policy chưa rõ để xử lý có căn cứ trước khi sửa tài liệu.
5. Vẽ actor, biên hệ thống, association và quan hệ include/extend khi có ý nghĩa nghiệp vụ được xác minh.

**Mẫu đặc tả bắt buộc** gồm mã/tên, mục tiêu, actor chính/phụ, trigger, tiền điều kiện, hậu điều kiện thành công, bảo đảm khi thất bại, luồng chính theo bước, luồng thay thế tham chiếu bước gốc, ngoại lệ/khôi phục, dữ liệu vào–ra, quy tắc nghiệp vụ/phân quyền, nguồn code và ca kiểm thử liên quan. Policy chưa xác minh được gắn nhãn mở.

### 4.2. Activity, Sequence và Class

| Sơ đồ | Cách dựng bám code | Kiểm tra nội dung |
|---|---|---|
| Activity | Bắt đầu từ handler thực, lần qua validation, quyết định, truy cập dữ liệu và hành động. Thêm nhánh lỗi, retry hoặc bù trừ theo bằng chứng | Điều kiện nhánh rõ, merge/join hợp nghĩa, kết thúc và hậu điều kiện khớp use case |
| Sequence | Lần theo browser → PHP → Flask/worker/DB hoặc luồng thực khác. Vẽ lifeline, message, response, alt/loop và timeout | Khớp method/route/thứ tự gọi. Ghi transaction boundary hiện có và phần đề xuất. Tách request bất đồng bộ khỏi lời gọi đồng bộ |
| Class | Chọn lớp cốt lõi ở controller/service/model, chữ ký public quan trọng và quan hệ khởi tạo/gọi/kế thừa | Có nguồn cho dependency, association, inheritance. Aggregation/composition/cardinality cần căn cứ vòng đời hoặc data model |
| Kiến trúc | Phân biệt component và deployment theo môi trường đã chốt | Đúng entrypoint, biên public/internal, kho gốc/dẫn xuất, thành phần có điều kiện |

Những source quan trọng gồm [Router](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/public/index.php), [Controllers](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers), [Services](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/services), [Use case đã viết](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/report/USECASE_SPEC.md), [Schema Neo4j](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/docs/neo4j/schema.md).

### 4.3. Mô hình dữ liệu

**MongoDB.** Mô tả collection và document, field/type, document nhúng, array, reference logic, ObjectId và mã nghiệp vụ cũ. Thể hiện cardinality đã xác minh, index/unique/TTL/validator theo source hoặc migration. Nếu dùng hình ERD trong báo cáo, có chú giải phân biệt liên kết logic với ràng buộc được MongoDB thực thi. Bổ sung ví dụ cấu trúc từ fixture tổng hợp.

**Neo4j.** Tạo sơ đồ riêng cho label/property/relationship và constraint/index, đối chiếu Cypher migration và mã sync. Ghi nguồn gốc sản phẩm từ MongoDB và cơ chế đồng bộ khi có bằng chứng. Schema SQL lịch sử được chú giải theo vai trò di chuyển dữ liệu đã xác minh.

### 4.4. Source và render

Đề xuất `docs/diagrams/src/` cho source và `docs/diagrams/rendered/` cho SVG/PNG. Bảng nguồn, caption, trạng thái và mốc code ở `docs/diagrams/traceability.md`. Tên diagram dùng theo mục tiêu nghiệp vụ, ví dụ `usecase-customer`, `sequence-checkout`, `mongodb-documents`, `neo4j-schema`.

- Mermaid dùng cho các loại hỗ trợ như Sequence, Class, flowchart và mô hình tài liệu có chú giải.
- Với Use Case và Activity cần ký pháp UML chuẩn, ưu tiên source PlantUML `.puml` hoặc định dạng chỉnh sửa được phù hợp yêu cầu khoa. Kiểm tra renderer local và dependency Java/Graphviz khi chọn PlantUML.
- Có Mermaid CLI local theo manifest. Cần xác minh Node/browser và chạy smoke render khi A2 được duyệt. Phần chưa có renderer được ghi BLOCKED về xuất hình, kèm source đã đối chiếu.
- Mỗi diagram cần render SVG và PNG, kiểm exit code và file đầu ra, kiểm nhãn tiếng Việt, kích thước, vùng cắt, mũi tên và khả năng đọc ở tỷ lệ chèn Word. Ưu tiên renderer local để giữ source dự án trong môi trường được phép.
- Kiểm cú pháp và kiểm ngữ nghĩa là hai bước nghiệm thu. Cú pháp hợp lệ vẫn cần đối chiếu actor, message, điều kiện và dữ liệu với implementation.

**Tiêu chí hoàn thành.** Có đủ loại sơ đồ được giao cùng đặc tả Use Case, source chỉnh sửa được, SVG/PNG đã xem, caption và traceability tới source/role/field/test. Mâu thuẫn giữa code và nội dung đã viết có danh sách xử lý rõ ràng. Phần chưa xác minh giữ nhãn phù hợp.

**Đầu ra skill.** `.agents/skills/skinsyntax-uml-documentation/SKILL.md` cùng các reference dùng thật gồm `use-case-template.md`, `diagram-evidence.md` và `rendering.md`. Helper render chỉ thêm nếu cần tự động hóa lặp theo renderer đã xác minh.

## 5. Giai đoạn A3 — Nâng cấp skinsyntax-thesis-support cho Word hiện tại

### 5.1. Quyền sở hữu skill và phạm vi nâng cấp

Bản hiện có nằm ở `%USERPROFILE%/.codex/skills/skinsyntax-thesis-support/`. Đề xuất đưa **chính skill này** về `.agents/skills/skinsyntax-thesis-support/` và nâng cấp tại repo, giữ tên và các nguyên tắc evidence đang có. Bước chuyển cần kiểm tra references, hash và discovery. Bản cũ có thể được sao lưu ngoài các thư mục quét skill theo vị trí đã duyệt, rồi chuyển nguồn chính sang repo. Đây là thay đổi vị trí global cần được người dùng duyệt cụ thể trước khi thực hiện.

Nếu phạm vi duyệt chưa gồm việc chuyển bản global, dùng skill hiện có cùng tài liệu review riêng của repo cho đợt đó. Việc thêm cùng tên ở hai nơi cần tránh vì Codex có thể hiển thị cả hai, khả năng ưu tiên tự động cần được xác minh. [Quy tắc discovery](https://learn.chatgpt.com/docs/build-skills).

**Nội dung nâng cấp** gồm chế độ review/chỉnh Word có sẵn, bảng gap theo chương, kiểm chứng citation, registry sơ đồ, truy vết claim và báo cáo thay đổi. Documents đảm nhiệm OOXML, styles và render. PDF kiểm bản xuất. Presentations dùng nội dung và hình đã duyệt khi được giao slide.

### 5.2. Workflow sửa báo cáo có bảo toàn nội dung

**Kích hoạt** khi người dùng yêu cầu hoàn thiện hoặc rà báo cáo Word SkinSyntaxVN, bổ sung phần thiếu, sửa trích dẫn, đồng bộ sơ đồ hoặc nội dung thực nghiệm.

1. Xác định đúng bản Word được dùng. Lấy `report/BaoCao_KLTN_SkinSyntaxVN.docx` làm ứng viên hiện có cần xác nhận khi thực hiện, so với mẫu khoa và phiên bản người dùng chỉ định. Lưu fingerprint và bản làm việc trước khi sửa.
2. Trích cấu trúc chương, heading, bảng, caption, citation, hình và relationships OOXML. Lập inventory nội dung đã có, gap và mâu thuẫn có bằng chứng. Giữ nội dung tác giả đã viết ngoài những đoạn được giao bổ sung hoặc chỉnh.
3. Lập bảng thay đổi dự kiến theo vị trí Word, lý do, nguồn code/tài liệu và diagram liên quan. Đề xuất bổ sung ngắn cho phần thiếu. Sửa lỗi nội dung trong phạm vi người dùng giao, đồng thời ghi thay đổi có ảnh hưởng ý nghĩa.
4. Dùng workflow UML cho diagram và đặc tả. Registry ghi mã use case, tên/source hình, hash, caption, chương, mốc code và các cross-reference. Đồng bộ cả mô tả văn bản lẫn hình.
5. Với trích dẫn, đọc nguồn gốc, xác minh tác giả/năm/tiêu đề/DOI hoặc URL và nội dung hỗ trợ claim. Ghi các nguồn không truy cập được là chưa xác minh. Bổ sung nguồn có thể kiểm tra và đối chiếu thứ tự trích dẫn theo quy định khoa.
6. Với benchmark/test, đối chiếu raw result, script, dataset nguồn gốc, version, môi trường, seed/cấu hình và cách tổng hợp. Ghi đúng phạm vi bằng chứng cùng giới hạn. Lần chạy mới trong phạm vi kiểm thử dùng fixture tổng hợp cô lập. Kết quả chưa đo hoặc chưa tái lập được giữ trạng thái đó.
7. Sửa trên bản làm việc theo mức độ can thiệp đã giao. Ưu tiên chỉnh OOXML có mục tiêu để giữ bảng, styles, hình và thứ tự nội dung. Khi cần, dùng tracked changes hoặc change log để người dùng duyệt được từng phần.
8. Render DOCX và PDF theo công cụ hiện có, xem từng trang. Kiểm font Việt, mục lục, số hình/bảng, header/footer, page break, caption và cross-reference. Đối chiếu text/tables/media trước–sau để phát hiện mất nội dung ngoài ý muốn.
9. Giao bản review riêng, ví dụ `report/BaoCao_KLTN_SkinSyntaxVN.review.docx`, cùng bảng thay đổi và phần còn thiếu chứng cứ. Việc thay thế bản chính theo quyết định của người dùng.

**Tiêu chí hoàn thành.** Nội dung gốc được bảo toàn theo phạm vi sửa, gap đã xử lý hoặc có lý do mở, citation dùng trong bản giao đã xác minh, hình và mô tả khớp cùng phiên bản source, DOCX/PDF render đọc được. Phần số liệu chưa kiểm chứng được gắn nhãn rõ.

**Reference dự kiến.** Giữ `references/evidence-protocol.md`, bổ sung `references/word-review.md` và `references/citations-and-diagrams.md`. Mẫu trường và asset chỉ thêm khi được cung cấp hoặc xác nhận.

## 6. Giai đoạn A4 — Quy trình Codex và đánh giá Superpowers

### 6.1. Phần có sẵn và phần còn thiếu

Repo đã có workflow phát triển feature, sửa lỗi, code review, test và tài liệu. AGENTS yêu cầu dùng brainstorming/writing-plans cho việc nhiều bước, nhưng chưa thấy hai skill có tên đó ở các thư mục local đã kiểm tra. Thư mục `docs/superpowers/` cho thấy có hiện vật thiết kế/kế hoạch, sự hiện diện này chưa xác nhận plugin đang hoạt động. [Feature workflow](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/workflows/feature-development.md), [Bug-fix workflow](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/workflows/bug-fix.md), [Debugging](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/skills/debugging.md), [Code review](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/.agents/skills/code-review.md).

| Năng lực | Superpowers bổ sung | Phần đã có và mức trùng |
|---|---|---|
| Brainstorming | Khai thác yêu cầu, so sánh phương án, tài liệu thiết kế | Trùng một phần với feature-development/design. Có thêm quy trình phê duyệt và bước review, cần áp dụng theo quy mô |
| Writing plans | Tách việc theo file, giao kèo và cách kiểm chứng | Trùng một phần với workflow hiện có. Có giá trị khi nhiệm vụ dài thường mất quyết định giữa chừng |
| Systematic debugging | Thu bằng chứng, giả thuyết, thử tối thiểu, tái kiểm | Trùng cao với debugging.md, bug-fix và hướng dẫn cá nhân |
| Verification/review | Kiểm chứng trước kết luận, review theo milestone | Trùng cao với regression-testing, e2e, code-review và safe-development |
| GitNexus | Superpowers có phương pháp làm việc, GitNexus có graph/caller/impact | Có thể phối hợp vì công cụ graph vẫn cần cho thay đổi code theo quy tắc repo |
| Worktree/subagents/branch finishing | Điều phối thực thi, môi trường tách và kết thúc branch | Có thể thêm thao tác Git, commit hoặc delegation ngoài nhu cầu hiện tại, cần scope cụ thể |

### 6.2. Tương thích và rủi ro của Superpowers

Repo chính thức có manifest Codex **7.0.2**, khai báo bộ skills, README hướng dẫn Codex App qua marketplace và license MIT. Manifest Codex được xem có `hooks: {}`. Repo còn có hook SessionStart phục vụ các host khác. Cần xác minh loader và quyền thực tế ở Desktop trước khi kết luận hook nào được chạy. [Manifest Codex](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/.codex-plugin/plugin.json), [README chính thức](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/README.md), [License](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/LICENSE).

`using-superpowers` có phạm vi kích hoạt rất rộng từ đầu hội thoại. Brainstorming và writing-plans có các bước duyệt, tài liệu hoặc commit/delegation theo workflow. Các mặc định này có thể tăng context và số bước so với yêu cầu nhỏ. Hướng dẫn người dùng tiếp tục quyết định phạm vi. [Using Superpowers](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/skills/using-superpowers/SKILL.md), [Brainstorming](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/skills/brainstorming/SKILL.md), [Writing plans](https://github.com/obra/superpowers/blob/22f0b463e7a5e579904dd6211a520321035526f7/skills/writing-plans/SKILL.md).

Các file skill văn bản có thể đọc trực tiếp. Tính năng visual companion tùy chọn có server/script và README công bố cơ chế tải logo từ website kèm phiên bản Superpowers. Nếu xem xét tính năng này, kiểm tra dependency, địa chỉ bind, dữ liệu gửi đi và lựa chọn telemetry trước. Đánh giá hiện tại ghi nhận hành vi được tác giả công bố, chưa chạy server hoặc hook.

**Khuyến nghị.** Đợt đầu hoàn thiện feature-development và bug-fix hiện có bằng checklist acceptance, giả thuyết, ranh giới thay đổi và bằng chứng. Chỉ xem xét cài Superpowers sau khi có ca thực chứng minh thiếu phương pháp mà hướng dẫn hiện tại chưa đáp ứng, và so sánh được lợi ích với số bước, context và thao tác thêm. Nếu thử, chọn các workflow cần thiết cùng dependency tham chiếu đầy đủ, giữ nguồn/commit/license, kiểm tra activation và phạm vi Git. Bản tách nhỏ cần ghi rõ là phiên bản chọn lọc của repo upstream.

### 6.3. Workflow phương pháp cho Codex

**Kích hoạt** với nhiệm vụ feature nhiều bước, sửa lỗi, refactor hoặc review có rủi ro.

1. Đọc yêu cầu và trạng thái đã ghi, xác định đầu ra và acceptance criteria theo tác vụ.
2. Tìm source/caller/entrypoint. Dùng GitNexus đúng repo trước khi sửa symbol khi quy tắc dự án yêu cầu. Ghi UNKNOWN hoặc index cũ nếu chưa giải quyết được.
3. Với feature, so sánh phương án có ảnh hưởng và lập kế hoạch theo file/contract/test. Với bug, tái hiện hoặc thu bằng chứng, nêu giả thuyết rồi kiểm thử tối thiểu.
4. Thực hiện thay đổi trong phạm vi được giao, dùng logic và test có sẵn, kiểm tra theo điểm vào thực tế.
5. Tự review diff, chạy tập kiểm chứng phù hợp và ghi trạng thái. Cập nhật quyết định chỉ khi có thay đổi đáng kể.
6. Giao kết quả với file, bằng chứng và phần chưa xác minh. Các hành động commit/push/deploy/điều phối agent tuân theo phạm vi nhiệm vụ.

**Đầu ra và nghiệm thu.** Kế hoạch có acceptance và file rõ, bug có nguyên nhân kèm bằng chứng, review có vị trí và tác động, kiểm chứng có lệnh/kết quả/giới hạn. Đánh giá workflow trên một nhiệm vụ UI, một nhiệm vụ docs và một bug fixture để kiểm tra mức chi tiết phù hợp.

## 7. Giai đoạn B — Backlog kỹ thuật và cổng nghiệm thu P0

Ba workflow dưới đây giữ các thủ tục và ca kiểm thử chuyên sâu từ kế hoạch trước. Thời điểm triển khai đầy đủ theo ưu tiên B, đồng thời **P0 checkout phải được kiểm chứng và khắc phục trước khi chốt bản demo hoặc bảo vệ**. Nếu đến mốc chốt mà chưa đủ bằng chứng, trạng thái release/demo được ghi chưa đạt cổng P0.

Cổng P0 cần có test trên MongoDB cô lập cho thành công, lỗi sau giữ tồn/ghi đơn/ghi chi tiết/voucher, retry và tranh chấp tồn. Lỗi dữ liệu phải có sửa chữa được giao riêng và tái kiểm. Quyết định đạt cổng dựa vào trạng thái đơn, tồn, voucher và side effect sau cùng. Việc hoàn thành thiết kế UI hoặc render báo cáo chưa cung cấp bằng chứng này.

### B1 — MongoDB Transaction & Checkout Testing

**Mức ưu tiên P0.** Đề xuất `skinsyntax-mongodb-checkout-testing`. Giá trị bổ sung là thủ tục kiểm tra toàn vẹn đơn hàng trong MongoDB thực, có lịch chạy đồng thời và điểm lỗi cụ thể. Skill regression tiếp tục đảm nhiệm việc xác định phạm vi hồi quy tổng thể.

**Điều kiện kích hoạt.** Người dùng yêu cầu kiểm thử checkout, tồn kho, voucher, transaction, rollback, retry hoặc xử lý callback trùng, hoặc cần xác minh tác động của thay đổi tại các luồng này. Skill đọc `CheckoutCore`, controller checkout, `HoaDon`, model voucher và test liên quan theo phạm vi nhiệm vụ. [CheckoutCore](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/services/CheckoutCore.php), [HoaDon](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/HoaDon.php), [CustomerVoucher](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php), [Payment boundary unit test](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/payment_boundary_unit_test.php).

**Các bước thực hiện sau phê duyệt.**

1. Lập bản đồ checkout từ request thực tới giữ tồn, tạo đơn, ghi dòng, cập nhật voucher, thanh toán và side effect. Xác định trạng thái trung gian có chủ ý, transaction boundary, cơ chế bù trừ và idempotency đang có. Ghi thiếu sót theo bằng chứng.
2. Chuẩn bị replica set MongoDB riêng cho harness, với container/process, DB, volume, network, port và namespace riêng cho mỗi lượt chạy. Dùng cấu hình test độc lập với môi trường ứng dụng đang dùng. Xác minh phiên bản server, PHP extension/library, primary readiness, khả năng dùng session và transaction. Phiên bản trong lockfile audit là PHP library 1.19.1 và image MongoDB 7, cần kiểm tra runtime trước khi dùng API.
3. Dựng fixture tổng hợp tối thiểu cho hai khách, sản phẩm có tồn kiểm soát, giỏ nhiều dòng và voucher có giới hạn. Xác định số lượng, giá, tổng đơn và trạng thái trước mỗi ca. Dùng khóa nhận diện ca/lượt chạy để đối chiếu document liên quan.
4. Tận dụng unit/static tests làm lớp kiểm tra nhanh sau khi đọc khả năng ghi dữ liệu của script. Chạy luồng model thật với Mongo test và luồng HTTP checkout được hỗ trợ. Thực hiện các ca dưới đây với điểm lỗi được quan sát rõ.
5. Thu trạng thái CSDL từ kết nối quan sát độc lập sau abort/commit hoặc kết thúc quá trình phục hồi. Kiểm tra bất biến dữ liệu cho mọi ca. Ghi số lần thử thực tế và trạng thái commit có thể chưa xác định.
6. Dùng Playwright kiểm tra ít nhất luồng thành công, lỗi có thông báo và gửi lại thao tác từ UI khi thuộc phạm vi. Điều phối race qua các process và session độc lập. PHP session lock hoặc thao tác browser tuần tự có thể che race, cần xác nhận request thực sự chồng thời gian.
7. Xuất bảng kết quả với dữ liệu trước/sau tối thiểu đã lọc và hướng tái lập. Việc dọn môi trường test chỉ áp dụng đúng resource đã tạo cho lượt đó, sau khi kiểm tra định danh. Giữ bằng chứng lỗi cần thiết trước khi dọn.

MongoDB cần topology hỗ trợ multi-document transactions. Mỗi transaction dùng session thuộc đúng client. Các worker test đồng thời phải dùng session/transaction độc lập. Tài liệu PHP library hiện hành là dòng 2.x, nên phải kiểm tra API tương thích 1.19.1 trước khi viết harness cho checkout này. [MongoDB transactions](https://www.mongodb.com/docs/php-library/current/crud/transaction/), [Điều kiện topology](https://www.mongodb.com/docs/manual/core/transactions-production-consideration/).

**Ma trận lỗi, retry và đồng thời bắt buộc trong thiết kế.** Các bất biến dưới đây là tiêu chí đề xuất cần đối chiếu với yêu cầu nghiệp vụ. Khả năng idempotency, phục hồi và retry đang có phải được khảo sát riêng trước khi chạy, rồi ghi rõ phần đáp ứng và phần thiếu.

| Nhóm ca | Cách gây tình huống trong sandbox | Bất biến hoặc kết quả cần kiểm |
|---|---|---|
| Thành công | Đơn nhiều dòng, voucher hợp lệ | Đúng một đơn hoàn chỉnh cho thao tác, đủ dòng, tổng tính đúng, tồn giảm đúng, voucher tiêu thụ đúng |
| Lỗi sau giữ tồn | Lỗi có kiểm soát trước ghi đơn | Tồn phục hồi hoặc có trạng thái giữ chờ phục hồi được định nghĩa và xử lý trong thời hạn đã thống nhất |
| Lỗi sau ghi header | Chặn lần ghi kế tiếp sau tạo đơn | Transaction abort hoặc bù trừ hoàn chỉnh, document dở dang được phát hiện |
| Lỗi sau dòng đầu | Đơn nhiều dòng, lỗi ở dòng tiếp theo | Đủ toàn bộ đơn sau phục hồi hoặc hủy nhất quán theo hợp đồng, tồn và voucher khớp trạng thái cuối |
| Lỗi quanh voucher | Gây lỗi trước và sau cập nhật mức dùng voucher | Voucher không bị tính thừa hoặc mất lượt khi đơn thất bại |
| Retry sau lỗi tạm thời | Lỗi DB tạm thời có nhãn, giới hạn số lần/thời gian thử cấu hình trong harness | Trạng thái cuối nhất quán, số lần thử hữu hạn, retry side effect được quan sát |
| Mất phản hồi sau commit | Giả lập server đã commit nhưng response tới client bị mất | Gửi lại cùng định danh thao tác trả kết quả theo hợp đồng và chỉ tiêu thụ tồn/voucher một lần |
| Commit chưa rõ kết quả | Giả lập mất phản hồi của commit bằng cơ chế test phù hợp | Phân biệt retry commit với chạy lại nghiệp vụ, xác minh đơn theo định danh trước khi kết luận |
| Hai khách tranh đơn vị tồn cuối | Barrier đồng bộ hai process cùng đặt sản phẩm tồn 1 | Có đúng một đơn thành công, tồn cuối bằng 0, đơn thua không nhận voucher hoặc phát hành side effect thành công |
| Cùng thao tác gửi đồng thời | Hai request cùng định danh idempotency, gồm cả payload khác nhau | Kết quả được quy định cho payload trùng/xung đột, giới hạn một hiệu lực nghiệp vụ |
| Voucher cuối cùng/callback trùng | Hai đơn tranh lượt voucher cuối, callback giả lập lặp hoặc đến đồng thời | Tôn trọng quota, chuyển trạng thái thanh toán và phát thưởng tối đa theo nghiệp vụ |
| Phục hồi khi bù trừ cũng lỗi | Nếu ứng dụng đang dùng bù trừ, chèn lỗi ở bước phục hồi rồi chạy lại cơ chế phục hồi | Trạng thái trung gian có thể truy vết và hội tụ về bất biến cuối, giới hạn retry rõ ràng |

Cơ chế chèn lỗi được chọn sau khi xác định điểm ghi thật. Có thể dùng điều kiện ràng buộc hoặc Mongo failpoint trên instance test, hoặc seam đã sẵn có trong test harness. Cần chứng minh lỗi xảy ra tại bước dự định. Mock thuần chỉ dùng cho lớp unit. Nếu một điểm lỗi chưa thể tiếp cận an toàn với cấu trúc hiện tại, ghi BLOCKED cho ca đó và đề xuất thay đổi harness cụ thể trong nhiệm vụ tiếp theo.

Đối chiếu dữ liệu cuối gồm tồn không âm, lượng tồn đã giảm bằng lượng hàng của đơn có hiệu lực, header và chi tiết khớp, tổng giá/voucher khớp, quota không vượt, giỏ và quyền sở hữu đúng. Side effect ngoài MongoDB như gửi thông báo, phát event hoặc ghi nhận thanh toán dùng stub và bộ đếm để phát hiện lặp. Việc abort transaction không tự hoàn tác side effect ngoài CSDL.

**Tiêu chí hoàn thành.** Mỗi ca trong ma trận có kết quả thực chạy hoặc lý do BLOCKED/NOT RUN, gắn với môi trường và artifact. Race phải có bằng chứng chồng thời gian. Kết luận nghiệm thu checkout chỉ được ghi PASS cho phạm vi đã chạy khi các bất biến liên quan đều đạt. Lỗi còn lại phải có bước tái hiện và hậu quả dữ liệu.

**Đầu ra kiểm chứng được.** Báo cáo checkout, bảng ca máy đọc được, fixture manifest/hash, topology và phiên bản đã lọc, timeline điểm lỗi/commit/retry, số lượng document và số dư tồn/voucher trước–sau, lệnh tái lập cùng artifact Playwright khi có.

### B2 — PHP Security, Session, CSRF & RBAC Audit

**Mức ưu tiên P1, nâng P0 khi có bằng chứng vượt quyền hoặc ảnh hưởng thanh toán.** Đề xuất `skinsyntax-php-security-audit`. Skill bổ sung phân tích PHP/session/RBAC theo kiến trúc repo. Khi cần rà JS hoặc Python ở biên liên quan, dùng Security Best Practices hiện có.

**Điều kiện kích hoạt.** Có yêu cầu audit/hardening bảo mật PHP, rà session/CSRF/RBAC/ownership, hoặc xác minh an toàn của thay đổi auth và endpoint dùng session. Yêu cầu sửa giao diện thông thường chưa đủ để chạy một cuộc audit bảo mật toàn repo.

**Các bước thực hiện sau phê duyệt.**

1. Xác định entrypoint thực được hỗ trợ, route alias, phương thức HTTP và toàn bộ lớp bootstrap. Lập bảng hành động đọc/ghi, loại caller và policy xác thực cho customer, staff, admin hoặc caller nội bộ.
2. Kiểm tra vòng đời session từ đăng nhập, nâng quyền, đổi mật khẩu, reset và đăng xuất tới hết hạn. Đọc cách tạo/lưu session, strict mode, đổi session ID và hiệu lực của cookie flags theo HTTPS/proxy thực tế của môi trường test. Kiểm tra session cũ có còn thực hiện được thao tác sau logout hoặc thay đổi quyền.
3. Với mutation dùng cookie, lần theo CSRF token từ cấp phát tới form/JSON/header và guard. Kiểm tra thiếu token, sai token, token của session khác, sai HTTP method và request khác origin. Quan sát dữ liệu để xác nhận request bị từ chối trước ghi.
4. Kiểm tra RBAC kết hợp ownership theo ma trận dưới. Ghi rõ chính sách trong source và yêu cầu nghiệp vụ chưa thống nhất, gồm ID cũ/ObjectId. Trao đổi về chính sách chưa rõ trước khi chọn expected result cho ca nghiệm thu.
5. Rà validation, phép so sánh khóa ký, phản hồi lỗi và log tại biên liên quan. Với callback server-to-server, đánh giá chữ ký, replay và quyền caller theo giao kèo đó. Đối chiếu cách bảo vệ riêng của callback khi lập ma trận CSRF.
6. Thực hiện negative tests bằng HTTP và Playwright trên môi trường fixture. Dùng browser context riêng cho từng vai trò, tài khoản A/B và session. Các khóa ký, CSRF token và cookie tổng hợp chỉ phục vụ harness.
7. Ghi phát hiện theo mã, mức độ, route/method/file/dòng, điều kiện khai thác, quan sát, tác động, hướng sửa tối thiểu và giới hạn xác minh. Đối chiếu với test sẵn có trước khi đề xuất test bổ sung.

| Trường hợp | Kết quả cần kiểm |
|---|---|
| Guest gọi thao tác customer/staff/admin | Phản hồi xác thực phù hợp với giao kèo, dữ liệu giữ nguyên |
| Customer gọi API staff/admin | Bị từ chối dù truy cập URL/API trực tiếp |
| Customer A truy cập đơn, hồ sơ hoặc hội thoại của B | Hành động bị từ chối, phản hồi không lộ nội dung đối tượng |
| Staff gọi hành động riêng admin | Đúng ma trận quyền của dự án trên từng alias/entrypoint hỗ trợ |
| Admin thực hiện thao tác hợp lệ | Luồng được phép vẫn hoàn thành, có các guard liên quan |
| CSRF thiếu/sai/thuộc session khác | Mutation bị từ chối trước side effect |
| Session hết hạn/logout hoặc nâng quyền | Session ID và trạng thái truy cập phản ánh vòng đời đã quy định |
| Request nội bộ thiếu/sai chữ ký hoặc quá hạn | Bị từ chối, thông điệp lỗi không lộ chi tiết nhạy cảm |

Các điểm bắt đầu có bằng chứng là `AuthController`, `QuanTriController::requireRole`, các handler xóa voucher/tài khoản, `ChatAgentInternalController`, `LabelScanController` và ba front controller. Những nghi vấn audit trước cần kiểm tra cả bootstrap và cấu hình test hiệu lực để kết luận. [AuthController](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/AuthController.php), [QuanTriController](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/QuanTriController.php), [Chat internal](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/ChatAgentInternalController.php), [Test CSRF/RBAC đã có](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/test_admin_csrf_rbac.php).

**Tiêu chí hoàn thành.** Mỗi route thuộc phạm vi có actor/method/policy và tình trạng guard được truy vết. Các ca quan trọng có kiểm chứng HTTP và đối chiếu side effect, hoặc giới hạn được ghi cụ thể. Kết luận bảo mật thể hiện mức độ đã chứng minh và phần còn chưa xác minh.

**Đầu ra kiểm chứng được.** Ma trận route–actor–ownership–CSRF, bảng vòng đời session, báo cáo phát hiện có dòng mã, request/response đã lọc, kết quả kiểm tra side effect và danh mục regression test có thể dùng lại.

Nguồn guideline PHP và OWASP được dùng làm chuẩn đối chiếu có ngày kiểm tra. Chúng hướng dẫn xác minh session và kiểm soát truy cập từng request. [PHP Session Management](https://www.php.net/manual/en/features.session.security.management.php), [OWASP CSRF](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html), [OWASP Authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

### B3 — PHP–Flask Integration & Contract Testing

**Mức ưu tiên P1.** Đề xuất `skinsyntax-php-flask-contract-testing`. Skill sở hữu ma trận giao diện hai phía, mẫu dữ liệu và thủ tục kiểm chứng contract. Skill regression chọn tập ca cho mỗi thay đổi. B1 sở hữu bất biến giao dịch checkout, B2 sở hữu chính sách quyền.

**Điều kiện kích hoạt.** Thay đổi hoặc lỗi liên quan client PHP gọi Flask, callback Flask → PHP, chat stream, search/recommend/cart-analyze/label-analyze, alias route, auth nội bộ hoặc cấu trúc phản hồi. Dùng khi cần xác minh integration hoặc contract cho các luồng này.

**Các bước thực hiện sau phê duyệt.**

1. Đọc Docker/entrypoint hiệu lực, client PHP, route Flask/blueprint, schema và consumer JS/PHP. Lập từng cạnh HTTP có caller và callee xác minh được. Dùng GitNexus cho caller nội repo và kiểm tra thủ công đường HTTP.
2. Ghi giao kèo hiện tại theo method/path/alias, request header/body, ID, field bắt buộc/tùy chọn, null/empty, numeric types, content-type, status/error và timeout. Phân biệt hành vi hiện tại với thay đổi mong muốn của yêu cầu.
3. Tái dùng test `search_service/test_contract.py`, `label_service/test_label_contract.py`, `agent_graph/test_profile_state_contract.py` và test PHP liên quan sau khi kiểm tra fixture và khả năng side effect. Tạo mẫu tổng hợp chung cho hai phía nếu nhiệm vụ thực thi cần thêm coverage.
4. Kiểm tra consumer PHP bằng provider stub để chủ động mô phỏng lỗi. Kiểm tra provider Flask bằng test client và request fixture tương ứng. Ghép PHP thật → Flask thật trong mạng test với Mongo/Redis/vector/LLM test hoặc stub đúng theo phạm vi.
5. Kiểm tra các tuyến qua entrypoint PHP và reverse proxy được hỗ trợ. Đối chiếu field thực client gửi và cách server parse, auth nội bộ cùng identity context, mapping status và thông báo lỗi công khai.
6. Nếu có stream, xác minh frame theo hợp đồng thực, thứ tự event, chia chunk giữa ký tự UTF-8, event lỗi, kết thúc, ngắt kết nối và reconnect. Quan sát buffering tại proxy và việc lặp hành động có side effect khi client gửi lại.
7. Xuất sai lệch hợp đồng theo cặp client/provider, mức ảnh hưởng và test tái hiện. Chuyển phát hiện về bất biến CSDL sang workflow checkout và phát hiện quyền sang workflow security qua cùng mã bằng chứng.

| Nhóm ca | Điều kiện và assertion |
|---|---|
| Thành công | Đúng kiểu/status/field ở cả PHP và Flask, consumer render hoặc xử lý đúng |
| Biên dữ liệu | Thiếu field, sai kiểu, null, list rỗng, Unicode tiếng Việt, ID cũ/ObjectId khi được hỗ trợ |
| Identity và auth nội bộ | Context lấy từ nguồn đã xác thực, caller không chiếm quyền bằng field body |
| Lỗi phụ thuộc | Timeout, connection refused, response hỏng JSON, sai content-type, 4xx/5xx, phụ thuộc AI không sẵn sàng |
| Route/alias và fallback | Chỉ những alias đang hỗ trợ được đánh giá theo contract, fallback giữ phản hồi phù hợp với consumer |
| Streaming | Bắt đầu, chunk, event lỗi và kết thúc đúng protocol, xử lý disconnect/retry theo nghiệp vụ |
| Callback có side effect | Wire contract và status đúng, duplicate/replay được đối chiếu kết quả B1 và B2 |

Nguồn cần kiểm tra gồm `SearchRecommendClient`, `HomeController`, route chat-agent, Flask chatbot entrypoint và search/label blueprints. Alias mang tên thuật toán được truy vết tới implementation thực khi dựng contract. [Client PHP](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/services/SearchRecommendClient.php), [Route chat agent](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/routes/chat_agent.php), [Flask entrypoint](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/ai-service-flask/chatbot_service/chatbot_flask.py), [Search routes](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/ai-service-flask/search_service/routes.py), [Search contract tests](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/ai-service-flask/tests/search_service/test_contract.py), [Label contract tests](D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/ai-service-flask/tests/label_service/test_label_contract.py).

**Tiêu chí hoàn thành.** Các cạnh HTTP thuộc phạm vi có contract map và ca thành công/lỗi. Hai phía được kiểm tra với cùng fixture. Luồng kết nối thật và stream có bằng chứng thực chạy hoặc trạng thái BLOCKED/NOT RUN. Kết quả chỉ có stub phải ghi chính xác phạm vi xác minh.

**Đầu ra kiểm chứng được.** Danh mục endpoint/caller/callee, bảng schema và compatibility, request/response mẫu đã lọc, ma trận contract tests, log giả lập lỗi và trace qua proxy. Nếu cần OpenAPI/JSON Schema, tạo theo phần được xác minh trong nhiệm vụ tài liệu tương ứng.


## 8. File dự kiến tạo hoặc thay đổi khi triển khai được duyệt

### 8.1. Nhóm A

| File/nhóm file | Hành động dự kiến | Điều kiện và mục đích |
|---|---|---|
| `.agents/skills/design.md` → `.agents/skills/frontend-design/SKILL.md` | Chuẩn hóa bản đang có, đối chiếu upstream, chuyển vị trí sau kiểm tra tham chiếu | Một bản chính cho Frontend Design, giữ phần tùy biến đã xác định |
| `.agents/skills/frontend-design/LICENSE.txt` | Thêm license từ đúng commit upstream | Giữ thông tin license và notice đi cùng bản đã chọn |
| `.agents/skills/ui-ux-pro-max/SKILL.md`, `data/`, `scripts/`, `references/`, `LICENSE` | Chỉ thêm nếu duyệt thử UI UX Pro Max | Manifest file cần dùng, pin commit/hash, kiểm script path và dependency local |
| `.agents/skills/skinsyntax-uml-documentation/SKILL.md` | Tạo mới bằng Skill Creator | Scope riêng cho UML/đặc tả/data model/render |
| `.agents/skills/skinsyntax-uml-documentation/references/use-case-template.md` | Tạo mẫu đặc tả và đối chiếu actor/role | Mẫu dùng lại cho các use case trong báo cáo |
| `.agents/skills/skinsyntax-uml-documentation/references/diagram-evidence.md` | Tạo hướng dẫn các loại sơ đồ và bảng nguồn | Quy tắc bám code, model Mongo và Neo4j riêng |
| `.agents/skills/skinsyntax-uml-documentation/references/rendering.md` | Tạo quy trình theo renderer được xác minh | Source, SVG/PNG, cú pháp, font và khả năng đọc |
| `.agents/skills/skinsyntax-thesis-support/SKILL.md` và `references/evidence-protocol.md` | Di chuyển bản chính đang có và nâng cấp theo A3 nếu duyệt bước chuyển | Bảo toàn nội dung cũ, bổ sung review Word và đồng bộ bằng chứng |
| `.agents/skills/skinsyntax-thesis-support/references/word-review.md`, `citations-and-diagrams.md` | Thêm hướng dẫn Word hiện hữu, citation và registry hình | Dùng lại Documents/PDF cho định dạng và render |
| `%USERPROFILE%/.codex/skills/skinsyntax-thesis-support/` | Xử lý bản cũ trong bước di chuyển đã duyệt, có sao lưu ngoài vùng discovery | Thay đổi global cần được nêu riêng trong phạm vi duyệt. Nếu chưa duyệt, dùng skill tại chỗ cùng tài liệu bổ sung repo |
| `.agents/workflows/feature-development.md`, `bug-fix.md`, `pull-request.md` | Bổ sung acceptance, giả thuyết, chọn kiểm chứng và ghi giới hạn khi cần | Tận dụng workflow sẵn có, giữ mức chi tiết tương xứng nhiệm vụ |
| `.agents/skills/debugging.md`, `code-review.md`, `testing.md` | Chỉnh tập trung nếu thiếu điều kiện theo workflow mới | Rà trùng nội dung trước khi bổ sung |
| `.agents/skill-evals/trigger-cases.jsonl`, `scripts/eval_skill_triggers.ps1` | Đề xuất tạo bộ ca và runner đánh giá activation | Chỉ sau khi xác minh CLI/runtime và giới hạn thực thi |
| `agents/openai.yaml` bên trong từng skill mới/nâng cấp | Chỉ thêm nếu cần metadata giao diện hoặc policy cụ thể | Giữ implicit invocation theo mặc định, kiểm tra parser/host trước khi đặt trường mới |

Một số bản `SKILL.md` upstream UI UX Pro Max dùng biến môi trường của Claude. Đóng gói cho Codex cần dùng template/đường dẫn phù hợp `.agents/skills/`, kiểm tra từ repo root và thư mục con. Các script/module/reference liên quan được giữ đủ. Danh sách định danh/version/hash và thay đổi so với upstream ghi trong metadata của bản đóng gói hoặc hồ sơ eval.

### 8.2. Hiện vật UI và khóa luận chỉ tạo khi được giao thực hiện

| Nhóm | Đường dẫn ứng viên |
|---|---|
| Design brief | `docs/design/skinsyntax-ui-brief.md` |
| Source và hình diagram | `docs/diagrams/src/*`, `docs/diagrams/rendered/*`, `docs/diagrams/traceability.md` |
| Đặc tả Use Case | Cập nhật có mục tiêu `report/USECASE_SPEC.md`, đối chiếu phần Word liên quan |
| Word | Bản nguồn `report/BaoCao_KLTN_SkinSyntaxVN.docx` được dùng để đối chiếu. Bản review dự kiến `report/BaoCao_KLTN_SkinSyntaxVN.review.docx`, cùng change log trong `report/` |
| UI source | Chỉ xác định file chính xác sau khi chọn màn hình. Các ứng viên gồm `frontend/views/home.php`, `chitiet.php`, `layouts/`, `partials/` và asset liên quan |
| Test và ảnh UI | Tập ca hiện có dưới `e2e/`, `backend/tests/` và `frontend/public/assets/js/tests/` theo hành vi ảnh hưởng. Ảnh/trace tại `output/playwright/` |

Đây là danh mục cho các nhiệm vụ sau khi duyệt. Scope chỉnh giao diện, bổ sung sơ đồ và biên tập Word được thể hiện ở từng nhiệm vụ triển khai. Giai đoạn đề xuất hiện tại chỉ thay đổi `SKINSYNTAX_SKILL_PLAN.md`.

### 8.3. Nhóm B giữ trong backlog

| Skill dự kiến | References | Tình trạng |
|---|---|---|
| `.agents/skills/skinsyntax-mongodb-checkout-testing/SKILL.md` | `references/checkout-invariants-and-cases.md` | Giữ ca lỗi, retry, concurrency và bất biến theo B1 |
| `.agents/skills/skinsyntax-php-security-audit/SKILL.md` | `references/php-session-csrf-rbac.md` | Giữ ma trận session, CSRF, role và ownership theo B2 |
| `.agents/skills/skinsyntax-php-flask-contract-testing/SKILL.md` | `references/php-flask-contract-cases.md` | Giữ consumer/provider, lỗi và stream theo B3 |

Môi trường MongoDB test, harness chèn lỗi và sửa code chỉ được xác định trong nhiệm vụ kỹ thuật tương ứng. Cổng P0 checkout cần triển khai phần B1 thiết yếu trước mốc demo, ngay cả khi các hạng mục B khác còn trong backlog.

## 9. Kiểm thử tự động điều kiện kích hoạt skill

### 9.1. Phân biệt discovery, activation và chất lượng đầu ra

Codex dùng tên/mô tả để chọn skill phù hợp. Implicit invocation mặc định được phép, song kết quả chọn skill cần kiểm chứng bằng prompt thực. Danh mục skill tồn tại chưa chứng minh agent đã đọc và áp dụng. Skill trùng tên ở hai vị trí có thể xuất hiện song song. [Tài liệu skill chính thức](https://learn.chatgpt.com/docs/build-skills).

Đề xuất bộ eval có ba lớp.

1. **Discovery và cấu trúc.** Dùng Skill Creator validator cho skill mới/nâng cấp, kiểm YAML, tên, mô tả, reference và file cần thiết. Xác minh trong Desktop tại checkout chính. Frontend Design hiện ở `design.md` là ca cần đối chiếu trước/sau.
2. **Implicit activation.** Gửi prompt tự nhiên không chứa tên skill hoặc đường dẫn. Chạy từng ca ở phiên mới để tránh skill đã nạp làm sai kết quả. Ghi các skill đã đọc hoặc được host báo đã nạp từ event/tool trace thực.
3. **Áp dụng workflow.** Kiểm artifact hoặc hành động quan sát được, như đã tra UX đúng chủ đề, đã ghi actor/guard từ fixture, có alternate/exception trong đặc tả, có source diagram và có bảng bảo toàn nội dung Word. Một lời tự nhận đã dùng skill chưa đủ làm bằng chứng.

Thêm một ca gọi rõ tên cho mỗi skill làm đối chứng discovery. Ca đó kiểm tra khả năng gọi tường minh, kết quả được lưu riêng với implicit activation.

### 9.2. Ma trận prompt và điều kiện chọn

| Skill/nhóm | Prompt dương tính không gọi tên skill | Prompt âm tính hoặc giới hạn | Bằng chứng mong đợi |
|---|---|---|---|
| Frontend Design | “Đề xuất bố cục trang chi tiết SkinSyntaxVN có bản sắc, dùng PHP và Bootstrap hiện tại” | “Sửa sai tổng tiền đơn hàng” | Đọc skill thiết kế, đưa brief/token/bố cục bám nội dung. Ca âm tập trung logic được giao |
| UI UX Pro Max, nếu được chọn | “Rà UX form đăng ký, trạng thái lỗi và focus trên điện thoại” | “Dựng sơ đồ collection MongoDB” | Chọn skill theo UX, tra domain liên quan, ghi giới hạn của dữ liệu gợi ý |
| lucian-uxui | “Tìm mẫu thành phần tương tác cho menu lọc, lấy mẫu phù hợp rồi lập kế hoạch port” | “Chỉ đổi nội dung một nhãn bằng thành phần sẵn có” | Search/get đúng phần khi cần mẫu, giữ ID/notice. Ca nhỏ dùng component hiện có khi đủ |
| skinsyntax-uml-documentation | “Tạo Use Case và đặc tả cho khách hàng từ route và quyền thực tế trong fixture” | “Làm đẹp nút tìm kiếm” | Ma trận actor–guard–use case, đặc tả và source hình có truy vết |
| skinsyntax-uml-documentation, mô hình dữ liệu | “Vẽ cấu trúc document MongoDB và đồ thị Neo4j riêng” | “Tính số lượng sản phẩm bán” | Phân biệt embedded/reference và graph relation, ghi constraints có bằng chứng |
| skinsyntax-thesis-support | “Bổ sung phần Sequence vào bản Word đã viết, giữ các chương và câu chữ khác” | “Debug lỗi API trả 500” | Gap/change map, cách bảo toàn nội dung, citation và liên kết source hình |
| skinsyntax-architecture-documentation | “Giải thích ranh giới PHP–Flask–worker từ source” | “Chỉnh caption Word theo mẫu” | Đọc entrypoint/client/provider và ghi giới hạn, chỉ gọi UML/Word khi đầu ra cần |
| skinsyntax-safe-development và workflow repo | “Lập kế hoạch thay đổi nhiều bước, nêu file, hợp đồng và kiểm chứng” | “Đọc tên một file tài liệu” | Acceptance và phạm vi hợp lý. Một câu hỏi hẹp không sinh kế hoạch dài |
| GitNexus debugging/impact | “Lần theo caller để tìm nguyên nhân lỗi search” | “Thêm nguồn tham khảo vào Word” | Repo/index được xác định, graph và source được đối chiếu. Tool thiếu có giới hạn cụ thể |
| skinsyntax-regression-testing / e2e | “Kiểm chứng thay đổi UI qua luồng tìm kiếm trên browser test” | “Chỉ đề xuất hướng thiết kế” | Chọn tập ca/entrypoint, browser evidence nếu được phép chạy, đúng trạng thái |
| Documents / PDF / Presentations | “Rà định dạng DOCX”, “Kiểm PDF”, hoặc “Dựng slide từ nội dung đã duyệt” trong từng ca riêng | “Rà query MongoDB” | Chọn skill theo đúng định dạng, render và QA theo khả năng môi trường |
| MongoDB checkout trong B | “Thiết kế lỗi sau ghi header rồi retry và hai khách tranh tồn 1” | “Thay đổi typography” | Bất biến, điểm lỗi và sandbox rõ, giữ trạng thái chưa chạy nếu chỉ thiết kế |
| PHP security trong B | “Audit session, CSRF và quyền xóa voucher” | “Tạo Class Diagram controller” | Guard/actor/method/ownership và negative tests phù hợp |
| PHP–Flask contract trong B | “Kiểm giao kèo JSON và stream khi Flask timeout” | “Chỉnh màu trang chủ” | Consumer/provider/fixture/lỗi được truy vết |
| Superpowers, chỉ nếu duyệt pilot | Các ca feature, plan, debug riêng với fixture | Ca sửa một câu hoặc tác vụ chỉ đọc hẹp | So sánh với baseline workflow repo, ghi số bước và tác dụng thêm, kiểm thao tác Git và delegation |

Bảng nêu các skill chính. Có thể cho phép skill phụ phù hợp, ví dụ Frontend Design cùng guideline UX, hoặc UML cùng Thesis Support khi đầu ra gồm cả Word. Bộ eval ghi `required`, `allowed` và `unexpected` cho từng ca để nhận diện chọn thiếu hoặc chọn lan.

### 9.3. Runner tự động đề xuất

- Dùng Codex CLI đã cài với JSONL event ở chế độ `codex exec --json`, xác minh cú pháp/khả năng phiên bản hiện có khi xây runner. Lưu prompt/case, model, reasoning, phiên bản Codex, skill hash, tool availability và trạng thái kết thúc.
- Chạy trong workspace tạm có fixture source/Word tổng hợp, không chứa `.env`, dữ liệu người dùng, khóa thật hoặc mount DB hiện hành. Dùng mức quyền thích hợp cho ca và đặt timeout/giới hạn lượt. Cấu hình eval tách biệt phải được ghi rõ để đối chiếu với Desktop.
- Ca chọn skill ban đầu giữ phạm vi đọc/phân tích, chặn thao tác ứng dụng và dịch vụ thật. Các script có thể ghi cache/output hoặc gọi mạng dùng sandbox hoặc stub có dấu vết khi mục tiêu chỉ là kiểm activation.
- Đọc event thực để xác định file skill/tool invocation. Với cơ chế nạp skill không lộ qua event của phiên bản đang dùng, ghi mức quan sát hạn chế và dùng bằng chứng của host nếu có. Tránh suy kết quả từ câu trả lời tự báo.
- Chạy lại các prompt diễn đạt khác nhau, đề xuất ba lượt cho mỗi ca quan trọng trong vòng pilot, khóa điều kiện để so sánh baseline và bản nâng cấp. Ghi raw outcomes và tỷ lệ quan sát được sau khi chạy.
- Kiểm kết quả CLI và smoke test trên Desktop tách nhau. CLI không tự chứng minh discovery, quyền tool và chọn skill ở Desktop giống hệt.
- Eval đòi hỏi tạo file và gửi yêu cầu model, nên chỉ chạy sau khi duyệt phạm vi và số lượt. Trong bản kế hoạch này mọi kết quả eval là NOT RUN.

Codex có JSONL event để theo dõi command, file change, MCP call và trạng thái phiên. Runner phải kiểm event schema của bản cài trước khi đặt assertion. [Codex non-interactive mode](https://learn.chatgpt.com/docs/non-interactive-mode).

**Tiêu chí nghiệm thu activation.** Các ca tường minh nhận diện được skill và path đúng. Các ca implicit quan trọng có bằng chứng chọn/ap dụng phù hợp, các ca âm không dẫn tới thao tác hoặc workflow ngoài scope. Mọi thất bại được giữ trong kết quả, thiếu observability được ghi giới hạn. Các mức mục tiêu và số lượt được chốt trước khi chạy pilot. Báo cáo chỉ ghi tỷ lệ đã đo.

## 10. Trình tự triển khai, rủi ro và phạm vi cần duyệt

| Mốc | Nội dung | Điều kiện hoàn thành |
|---|---|---|
| A0 | Chốt file/nguồn/commit, xử lý bản Frontend Design đang có, xác nhận di chuyển Thesis Support và chọn renderer | Có phạm vi file và quyền thực hiện rõ |
| A1 | Chuẩn hóa Frontend Design, thử UI UX Pro Max nếu được duyệt, dùng Lucian theo nhu cầu, lập brief UI | Discovery và activation có kết quả, pilot UI bám stack và có hình/kiểm chứng theo scope |
| A2 | Tạo UML skill và bộ diagram/đặc tả cho phạm vi được chọn | Source + SVG/PNG, đặc tả chuẩn, traceability và render QA |
| A3 | Nâng cấp Thesis Support và hoàn thiện bản Word review | Nội dung giữ theo scope, gap/citation/hình được xử lý, render QA |
| A4 | Cải thiện workflow hiện có, chạy eval chọn skill và đánh giá lại nhu cầu Superpowers | Có bằng chứng tiến bộ và phần trùng, quyết định cài dựa trên kết quả |
| B1/P0 | Checkout trên MongoDB cô lập, sửa lỗi được giao và tái kiểm | Đạt bất biến và ca failure/retry/concurrency trước chốt demo/bảo vệ |
| B2 | Session/CSRF/RBAC/ownership và negative tests | Báo cáo có request/response/side effect đã lọc và residual risk |
| B3 | PHP–Flask contract, fallback và stream | Consumer/provider và luồng kết nối có bằng chứng tương ứng |

**Rủi ro cần theo dõi.** Installer mang nhiều skill hơn phạm vi cần, license/notice thiếu, hai skill cùng tên, đường dẫn script theo host khác, package tự tải, activation quá rộng, thay đổi nội dung Word ngoài ý muốn, diagram đúng cú pháp nhưng sai nghiệp vụ, source chưa render được và benchmark thiếu raw result. Các điều kiện kiểm tra tương ứng nằm trong A1–A4 và mục 9.

Sau phê duyệt, dùng Skill Creator để soạn/nâng cấp đúng các skill đã chọn, kiểm cấu trúc và hành vi. Gói bên ngoài được lấy từ commit đã chốt và rà IO trước khi chạy script. Việc thay đổi `AGENTS.md`, cấu hình global, source ứng dụng, dữ liệu và báo cáo Word phải có phạm vi cụ thể được giao ở bước triển khai tương ứng.

**Bản hiện tại chờ người dùng duyệt.** Thay đổi trong nhiệm vụ này là cập nhật `SKINSYNTAX_SKILL_PLAN.md`. Những file, skill, artifact và lệnh eval nêu ở trên là phương án triển khai sau duyệt.
