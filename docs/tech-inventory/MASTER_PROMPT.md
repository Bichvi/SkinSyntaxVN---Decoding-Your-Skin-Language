# MASTER PROMPT (yêu cầu gốc của người dùng, 2026-10-17)

> Ghi chú của phiên chính: người dùng cho phép ghi vào file riêng `update_technology_inventory.md` thay cho `UPDATE.md`.
> Mọi chỗ dưới đây nói "UPDATE.md" hiểu là `update_technology_inventory.md`; `update.md` chỉ nhận một mục trỏ sang file mới.

MASTER PROMPT — TECHNOLOGY INVENTORY, TECHNICAL TRACEABILITY & PROJECT HANDOVER

VAI TRÒ
Bạn là Principal Software Architect, AI Systems Engineer, Technical Documentation Specialist và AI Coding Agent Handover Engineer.
Hãy kiểm tra toàn bộ repository hiện tại và bổ sung vào file `UPDATE.md` một phần chuyên sâu mang tên:
TECHNOLOGY INVENTORY & IMPLEMENTATION TRACEABILITY
Mục tiêu là ghi lại ĐẦY ĐỦ mọi công nghệ, framework, thư viện, mô hình AI, thuật toán, kỹ thuật xử lý, database, service, API, công cụ triển khai và kiến trúc đã được áp dụng trong dự án.
Không chỉ liệt kê tên công nghệ. Phải giải thích chính xác cách công nghệ đó được sử dụng trong source code thực tế.
Tài liệu phải giúp một AI Coding Agent mới hiểu:

* Công nghệ nào đang được sử dụng?
* Công nghệ nào đã triển khai hoàn chỉnh?
* Công nghệ nào mới được triển khai một phần?
* Công nghệ nào chỉ nằm trong kế hoạch?
* Vì sao lựa chọn công nghệ đó?
* Công nghệ giải quyết vấn đề gì?
* Được triển khai ở module nào?
* File, class, function nào đang sử dụng?
* Đầu vào và đầu ra là gì?
* Dữ liệu lấy từ đâu và lưu vào đâu?
* Công nghệ kết nối với thành phần nào?
* Luồng xử lý và thuật toán hoạt động như thế nào?
* Có những giới hạn, lỗi hoặc rủi ro nào?
* Có thể thay thế, mở rộng hoặc tối ưu như thế nào?

I. TECHNOLOGY DISCOVERY & AUDIT
Kiểm tra các nguồn bằng chứng:

1. Dependency manifests: requirements.txt, pyproject.toml, package.json, composer.json, lockfiles và các file tương đương.
2. Import statements và dependency injection.
3. Dockerfile, docker-compose và container configuration.
4. Environment variable names và config files.
5. Source code thực thi.
6. API clients và service integrations.
7. Database schema, migrations, indexes.
8. Model loading và inference code.
9. Agent orchestration.
10. Search/retrieval pipelines.
11. Background jobs và queues.
12. Build, deployment và test scripts.
13. Git history và implementation documentation.

Phân biệt rõ:

* INSTALLED: Có dependency được khai báo/cài đặt.
* REFERENCED: Có import hoặc cấu hình tham chiếu.
* IMPLEMENTED: Có logic triển khai trong source code.
* INTEGRATED: Được kết nối vào luồng hoạt động.
* VERIFIED: Có bằng chứng chạy/test thành công.
* PARTIAL: Chưa hoàn chỉnh.
* PLANNED: Chưa triển khai.
* DEPRECATED: Không còn được sử dụng.
* UNKNOWN: Chưa xác minh.

Một thư viện xuất hiện trong requirements.txt không đồng nghĩa công nghệ đó đang hoạt động.

II. COMPLETE TECHNOLOGY MATRIX
Tạo bảng tổng hợp:
| ID | Technology | Category | Version | Purpose | Module | Status | Evidence |
Phân loại tối thiểu:
A. Frontend
* Languages
* UI frameworks
* CSS frameworks
* JavaScript libraries
* State management
* Video players
* Realtime communication

B. Backend
* Languages
* Web frameworks
* API frameworks
* Authentication
* Authorization
* Middleware
* Background processing

C. Databases
* Relational database
* Document database
* Vector database
* Graph database
* Cache
* Object/file storage

D. Artificial Intelligence
* LLM providers
* LLM models
* Embedding models
* Rerankers
* Intent classification
* Entity extraction
* Structured output
* Tool calling
* RAG
* GraphRAG
* Hybrid Search
* Recommendation algorithms
* AI Agents
* Multi-Agent systems
* State machines
* Constraint optimization
* Evaluation and guardrails

E. Multimedia
* Text-to-Speech
* Speech-to-Text
* Avatar generation
* Video generation
* Lip synchronization
* FFmpeg
* Audio mixing
* Video encoding
* Media storage
* Video publishing

F. Infrastructure
* Docker
* Queues
* Workers
* Scheduling
* CI/CD
* Monitoring
* Logging
* Tracing
* Testing
* Deployment

Không giới hạn ở danh sách trên. Nếu phát hiện công nghệ khác, phải bổ sung.

III. DETAILED TECHNOLOGY PROFILE
Với MỖI công nghệ quan trọng, tạo một hồ sơ theo mẫu:
TECH-ID: [Technology Name]
1. Identification
* Technology:
* Version:
* Category:
* Current status:
* First known implementation:
* Related commits:
* Source evidence:

2. Business Purpose
* Bài toán cần giải quyết.
* Tại sao cần công nghệ này.
* Giá trị mang lại cho hệ thống.

3. Technical Purpose
* Vai trò kỹ thuật.
* Thuật toán hoặc cơ chế hoạt động.
* Vì sao phù hợp với kiến trúc hiện tại.

4. Implementation Location
* Module:
* Directory:
* Files:
* Classes:
* Functions:
* Configuration:
* Dependencies:

5. Inputs
* Input source.
* Input schema.
* Data types.
* Validation.
* Preconditions.

6. Processing Logic
Mô tả từng bước xử lý thực tế, bao gồm thuật toán, quy tắc và cách xử lý ngoại lệ.

7. Outputs
* Output schema.
* Destination.
* Side effects.
* Database writes.
* API responses.
* Intermediate artifacts.

8. Data Integration
* Database/collection/table.
* Fields read.
* Fields written.
* Indexes.
* Data ownership.
* Synchronization.
* Source of truth.

9. Architecture Integration
* Thành phần gọi công nghệ này.
* Thành phần được công nghệ này gọi.
* API/service boundaries.
* Synchronous/asynchronous behavior.
* Error propagation.

10. Configuration
* Environment variable names.
* Model identifiers.
* Config parameters.
* Timeout.
* Retry.
* Resource requirements.
Không ghi secrets.

11. Performance
* Latency nếu có số đo.
* Memory/CPU/GPU usage nếu có.
* Throughput.
* Rate limits.
* Cost.
* Bottlenecks.

12. Reliability
* Failure scenarios.
* Retry strategy.
* Fallback.
* Logging.
* Recovery.
* Known limitations.

13. Verification
* Test files.
* Test commands.
* Test results.
* Runtime evidence.
* Verification date.

14. Technical Decisions
* Lý do lựa chọn.
* Alternatives considered.
* Trade-offs.
* Risks.
* Future improvements.

15. Replacement & Migration
* Có thể thay thế bằng công nghệ nào?
* Các module bị ảnh hưởng nếu thay thế.
* Data migration requirements.
* Compatibility risks.
* Rollback considerations.

Không tự bịa thông tin thiếu bằng chứng.

IV. AI TECHNOLOGY DEEP DIVE
Đối với các công nghệ AI, yêu cầu mức độ chi tiết cao hơn.

4.1. LLM
Ghi rõ: Provider, Model ID, API/client library, Calling module, Prompt source, System instructions, Temperature và các tham số nếu có, Context construction, Input/output schema, Token usage, Timeout, Retry, Fallback model, Structured output validation, Hallucination prevention.

4.2. Embeddings & Vector Search
Ghi: Embedding model, Vector dimensions, Chunking strategy, Normalization, Collection/index, Metadata, Similarity metric, Query generation, Filtering, Reindex strategy, Update/delete synchronization, Latency, Error handling.

4.3. Graph Database / GraphRAG
Ghi: Node labels, Relationship types, Node properties, Edge properties, Constraints, Graph construction, Graph update process, Cypher queries, Graph traversal logic, Graph retrieval, Integration with vector search, Evidence validation, Graph synchronization.

4.4. Hybrid Search
Ghi: Exact matching, Full-text search, BM25 nếu có, Fuzzy search, Vector search, Graph traversal, Candidate merging, Duplicate removal, Hard filters, Ranking, Reranking, Fallback, Score normalization, Evaluation metrics.

4.5. Agent Framework / Orchestration
Ghi: Framework, Agent roles, Tool registry, Planner, Executor, Validator/Critic, State schema, State transitions, Checkpointing, Resume, Retry, Human-in-the-loop, Autonomous mode, Failure recovery.

4.6. Constraint Optimization
Nếu có OR-Tools hoặc solver tương đương: Decision variables, Hard constraints, Soft constraints, Objective function, Constraint weights, Feasibility checking, Conflict detection, Optimization strategy, Solver timeout, Infeasible results, Fallback strategy, Determinism and reproducibility.

4.7. Recommendation System
Nếu có recommender: Content-based filtering, Collaborative filtering, Hybrid recommendation, Customer segmentation, Feature engineering, Similarity calculation, Ranking, Cold-start handling, Evaluation metrics, Personalization data.

4.8. AI Video Production
Ghi: Script generation, Product grounding, Claim verification, Voice generation, Avatar animation, Lip synchronization, FFmpeg pipeline, Audio ducking, Video rendering, Quality assurance, Scheduling, Publishing, Artifact storage.
Với mỗi bước phải ghi rõ input, output, file thực thi, trạng thái và phụ thuộc.

V. TECHNOLOGY DEPENDENCY GRAPH
Tạo Mermaid diagram mô tả: công nghệ nào phụ thuộc công nghệ nào; module nào sử dụng công nghệ nào; dữ liệu truyền giữa các thành phần; điểm lỗi có thể lan truyền; các thành phần có thể thay thế độc lập. Phải phản ánh code thực tế.

VI. TECHNOLOGY-TO-FEATURE TRACEABILITY
Tạo bảng:
| Feature | Technology | Implementation File | Database | Input | Output | Status |
Ví dụ một feature tìm kiếm sản phẩm có thể liên quan đến: LLM Intent Parser, Database query, Vector Search, Graph Retrieval, Ranking, Constraint Solver.
Phải mô tả thứ tự thực thi thực tế.
Không tự gán công nghệ cho một feature chỉ vì công nghệ đã được cài đặt.

VII. TECHNOLOGY CHANGE HISTORY
Với mỗi lần thêm, sửa, nâng cấp hoặc loại bỏ công nghệ, ghi: Change ID, Date, Technology, Previous implementation, New implementation, Reason for change, Files changed, Dependencies changed, Database changes, API changes, Performance impact, Compatibility impact, Test results, Rollback procedure, Related commit, Status.
Phải ghi được cả thay đổi công nghệ lẫn thay đổi logic ứng dụng.

VIII. TECHNOLOGY ROADMAP
Tạo mục riêng cho các công nghệ đang cân nhắc. Với mỗi công nghệ: Proposed technology, Problem to solve, Expected improvement, Required prerequisites, Integration design, Affected modules, Estimated complexity, Risks, Acceptance criteria, Implementation priority.
Đặc biệt kiểm tra trạng thái thực tế của các công nghệ sau nếu có liên quan đến dự án: LangGraph, Multi-Agent Architecture, GraphRAG, ChromaDB, Neo4j, OR-Tools CP-SAT, Reciprocal Rank Fusion, Neural Reranking, Langfuse, OpenTelemetry, Contextual Bandit, LLM Structured Output, Product Knowledge Graph, Autonomous Decision Engine.
Không được đánh dấu các công nghệ này là IMPLEMENTED nếu repository chưa có bằng chứng.

IX. TECHNOLOGY HANDOVER FOR CODEX
Tạo mục: TECHNOLOGY QUICK REFERENCE FOR NEXT AI AGENT
Mỗi công nghệ chính cần có bản tóm tắt: công nghệ đang dùng; module sử dụng; entry point; configuration; database liên quan; input/output; chức năng liên quan; những file quan trọng; cách kiểm thử; rủi ro khi sửa; công nghệ liên quan khác.
Codex phải có thể trả lời nhanh:
"Nếu tôi sửa Hybrid Search, cần kiểm tra những file, database, API và công nghệ nào?"
"Nếu tôi thay embedding model, có phải reindex ChromaDB không?"
"Nếu tôi thay Agent orchestration, những state transition nào bị ảnh hưởng?"
"Nếu tôi thay FFmpeg composer, các video artifact và publishing job có bị ảnh hưởng không?"

X. AUTOMATIC DOCUMENTATION UPDATE RULE
Sau mỗi lần thay đổi code, bắt buộc:
1. Kiểm tra Git diff.
2. Phát hiện công nghệ mới được thêm.
3. Phát hiện công nghệ bị xóa hoặc thay thế.
4. Kiểm tra dependency versions.
5. Cập nhật Technology Matrix.
6. Cập nhật Detailed Technology Profiles.
7. Cập nhật Technology-to-Feature Traceability.
8. Cập nhật Dependency Graph nếu cần.
9. Cập nhật Technology Change History.
10. Cập nhật AI Agent Handover.
11. Cập nhật trạng thái kiểm thử.
12. Kiểm tra tính nhất quán giữa code và tài liệu.
Nếu chỉ sửa một function nhỏ, chỉ cập nhật các mục bị ảnh hưởng.
Không ghi đè hoặc xóa lịch sử quan trọng.
Không tạo nội dung trùng lặp không cần thiết.
Không làm file quá dài bằng cách sao chép nguyên source code.

XI. QUALITY GATES
Trước khi hoàn thành, kiểm tra:
* Mọi công nghệ quan trọng có bằng chứng source code.
* Version được xác minh từ manifest/lockfile/runtime nếu có.
* Mọi công nghệ có mục đích rõ ràng.
* Các công nghệ AI có input/output rõ ràng.
* CSDL liên quan được ánh xạ chính xác.
* Dependency graph phản ánh kiến trúc thực tế.
* Phân biệt INSTALLED, IMPLEMENTED và VERIFIED.
* Không có công nghệ PLANNED bị ghi thành đã triển khai.
* Có chỉ dẫn cho Codex tiếp tục.
* Không chứa API key, token, password hoặc secrets.
* Không có benchmark hoặc test result bịa đặt.

XII. EXECUTION
1. Audit toàn bộ công nghệ đang được sử dụng.
2. Đối chiếu dependency, configuration và implementation.
3. Xác minh trạng thái từng công nghệ.
4. Bổ sung toàn bộ thông tin vào file đích.
5. Cập nhật kiến trúc, pipeline, CSDL và implementation history nếu có thay đổi liên quan.
6. Tạo Technology Dependency Graph.
7. Tạo Technology-to-Feature Traceability Matrix.
8. Cập nhật phần handover cho Codex.
9. Kiểm tra tài liệu với source code.
10. Báo cáo kết quả.
Không tự ý refactor hoặc thay đổi logic nghiệp vụ trong tác vụ tài liệu này.
KẾT QUẢ BẮT BUỘC: file đích phải trở thành nguồn tài liệu bàn giao kỹ thuật tập trung, giải thích được cả kiến trúc dự án lẫn công nghệ thực sự được áp dụng, đủ để AI Coding Agent mới tiếp tục phát triển với ít thời gian khám phá repository nhất.
