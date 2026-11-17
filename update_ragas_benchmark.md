# Benchmark RAGAS cho chatbot (17/11/2026)

## Mục tiêu
So sánh ba cấu hình truy xuất của chatbot tư vấn trên cùng một bộ câu hỏi:
- `semantic`: chỉ Chroma.
- `hybrid_rrf`: semantic + BM25, gộp bằng RRF (k=60, alpha=0.6).
- `hybrid_rerank`: hybrid RRF + cross-encoder rerank.

## File mới (không sửa file cũ nào)
- `ai-service-flask/tests/chatbot_eval/ragas_cases.json`: 30 câu hỏi tư vấn kèm luật gán nhãn bạc.
- `ai-service-flask/scripts/eval_ragas_build.py`: giai đoạn A, chạy trong container `skinsyntax-ai`. Bước này truy xuất top 10, sinh câu trả lời từ top 5 và tính các chỉ số theo nhãn.
- `ai-service-flask/scripts/eval_ragas_judge.py`: giai đoạn B, chạy trên máy host trong venv riêng có ragas 0.4.4. Bước này chấm điểm bằng LLM.
- `ai-service-flask/report/ragas-benchmark/run-20261116-full/`: chứa `stage_a.json`, `stage_b.json`, `stage_b_cache.json`, `summary.md`.

## Cách chạy
```bash
docker exec -w /app skinsyntax-ai python -m scripts.eval_ragas_build --run-dir report/ragas-benchmark/<run>
<ragas-venv>/Scripts/python.exe ai-service-flask/scripts/eval_ragas_judge.py --run-dir ai-service-flask/report/ragas-benchmark/<run> --judge-model gemini-3.5-flash-lite
```
Cả hai giai đoạn đều chạy tiếp được nếu bị ngắt: mẫu đã có kết quả sẽ không bị gọi API lại.

## Kết quả

| Chỉ số | semantic | hybrid_rrf | hybrid_rerank |
|---|---|---|---|
| Hit@5 (30 câu) | 0,000 | 0,200 | 0,233 |
| MRR@10 (30 câu) | 0,000 | 0,156 | 0,239 |
| nDCG@10 (30 câu) | 0,000 | 0,063 | 0,080 |
| Precision@5 / Recall@10 | 0 / 0 | 0,067 / 0,050 | 0,093 / 0,050 |
| Context Precision* (14 câu) | 0,810 | 0,532 | 0,546 |
| Answer Relevancy* (14 câu) | 0,799 | 0,780 | 0,736 |
| Faithfulness* (14 câu) | 0,192 | 0,324 | 0,323 |
| Độ trễ truy xuất, trung bình | 0,07 s | 0,15 s | 8,55 s |

\* Các chỉ số RAGAS do LLM chấm: giám khảo là `gemini-3.5-flash-lite` (temperature 0), embedding là `gemini-embedding-001`.

## Nhận xét
- **Semantic thuần không tìm đúng sản phẩm nào** trong 30 câu. Ví dụ, câu "sữa rửa mặt cho da dầu mụn" trả về son môi.
  - Index không hỏng: vector đã lưu khớp với embedding hiện tại (cosine 1,0).
  - Nguyên nhân nằm ở chất lượng của mô hình static embedding.
- **Hybrid và rerank cải thiện có thật nhưng còn thấp**: Hit@5 chỉ đạt 0,23. Rerank tốn khoảng 8,5 s mỗi câu trên CPU.
- **Context Precision cao của semantic không đáng tin.** Đây là biến thể không dùng đáp án chuẩn, nên ngữ cảnh sai vẫn được điểm cao nếu câu trả lời dựa vào chính ngữ cảnh đó.
- **Faithfulness thấp (0,19–0,32)**: câu trả lời còn đưa thông tin nằm ngoài ngữ cảnh.

## Hạn chế của lượt đo
- **Phần LLM chấm mới phủ 14/30 câu** (R01–R14, tức 42/90 mẫu), vì gói miễn phí của Gemini giới hạn 500 request/ngày.
  - Để chấm nốt, chạy lại lệnh giai đoạn B sau khi hạn mức được làm mới. Cần khoảng 400 request.
- **Giám khảo không phải Gemini 2.5 Flash như dự định**: model này chỉ có 20 request/ngày. Answer Relevancy dùng `strictness=1`.
- **Bộ sinh câu trả lời trộn hai model**: 80 câu từ Groq `gpt-oss-120b`, 10 câu còn lại từ `gemini-3.5-flash-lite`, vì Groq hết hạn mức trong ngày.
- **Nhãn là nhãn bạc** sinh từ metadata (danh mục, loại da, từ khóa thành phần, giá), chưa có người gán. Mỗi cấu hình chỉ chạy một lần và không áp bộ lọc metadata.

## Kiểm tra đã chạy
- `py_compile` cả hai script: đạt.
- `git diff --check` trên các file mới: không có lỗi.
- Không sửa file đã có trong git.
- Không in ra secret.

## Bước tiếp theo đề xuất
1. Chạy nốt giai đoạn B.
2. Thử embedding đa ngôn ngữ mạnh hơn, ví dụ `bge-m3` hoặc `multilingual-e5`, rồi so lại bằng chính bộ 30 câu này.
3. Gán nhãn tay một phần bộ câu hỏi.
