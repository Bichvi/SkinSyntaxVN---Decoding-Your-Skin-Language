# Proposal & Implementation Plan: Recommendation Evaluation Framework

**Tài liệu phương án đánh giá độc lập cho Recommendation Engine (Phân biệt với Conversational Chatbot Evaluation).**

---

## 1. Lý Do Cần Đánh Giá Độc Lập

Hiện tại dự án có bộ công cụ `eval_trulens.py` dùng để đo lường Chatbot:
- **Intent Accuracy**: Tỉ lệ phân loại ý định đúng.
- **Answer Relevance**: Mức độ liên quan của câu trả lời natural language.
- **Groundedness**: Mức độ trung thực so với văn bản gốc.

Tuy nhiên, **Recommendation Engine (`/api/recommend`) không trả về văn bản tự do** mà trả về Structured Output (`fit_status`, `reasons[]`, `warnings[]`, `matched_concerns[]`, `matched_ingredients[]`, `data_confidence`). 

Vì thế, việc chỉ dùng *Answer Relevance* của chatbot để đánh giá Recommendation là **không chính xác về mặt kỹ thuật**.

---

## 2. 6 Tiêu Chí Đánh Giá Cho Structured Recommendation Engine

Khung đánh giá Recommendation mới sẽ đo lường 6 tiêu chí độc lập:

| STT | Tiêu chí (Metric) | Mô tả & Công thức | Mục tiêu (Target) |
|---|---|---|---|
| 1 | **Constraint Correctness** | Tỉ lệ tuân thủ các hard constraints (ngân sách `budget`, loại trừ `avoid_ingredients`, loại sản phẩm). Ví dụ: Không được đề xuất sản phẩm > 500k khi budget = 500k. | **100.0%** |
| 2 | **Retrieval Relevance (P@K & MRR)** | Mức độ liên quan của Top-K sản phẩm được retrieve từ ChromaDB với loại da (`skin_type`) và vấn đề da (`concerns`). | **P@5 >= 0.85** |
| 3 | **Ingredient Grounding** | Mức độ chính xác của `reasons[]` và `warnings[]` so với dữ liệu thành phần thực tế trong MongoDB. Tuyệt đối không bịa lý do. | **100.0%** |
| 4 | **Profile Consistency** | Đảm bảo hệ thống sử dụng đúng hồ sơ khảo sát da của user ID hiện tại thay vì profile mặc định/khách. | **100.0%** |
| 5 | **Data Completeness Behavior** | Khi sản phẩm thiếu dữ liệu thành phần (`thanh_phan_chinh` rỗng), hệ thống phải trả `fit_status = "chua_du_du_lieu"` và `data_confidence = "low"`. Không được tự gán `phu_hop`. | **100.0%** |
| 6 | **Recommendation Latency** | Thời gian phản hồi của API `/api/recommend` (chạy hybrid search + deterministic rule matching, không qua LLM 70B). | **< 1,500 ms** |

---

## 3. Dataset Test Cho Recommendation

Xây dựng tập test dataset `test_recommendation_cases.json` gồm 20 kịch bản người dùng thực tế:

```json
[
  {
    "case_id": "REC_01",
    "profile": {
      "skin_type": "Da dầu mụn",
      "concerns": ["Mụn đầu đen", "Lỗ chân lông to"],
      "avoid_ingredients": ["Cồn khô", "Mineral Oil"],
      "budget": 400000
    },
    "expected_constraints": {
      "max_price": 400000,
      "forbidden_tokens": ["alcohol denat", "mineral oil", "paraffinum liquidum"]
    }
  },
  {
    "case_id": "REC_02_MISSING_ING",
    "profile": {
      "skin_type": "Da nhạy cảm",
      "concerns": ["Kích ứng"],
      "avoid_ingredients": ["Fragrance"]
    },
    "test_missing_data_handling": true
  }
]
```

---

## 4. Cấu Trúc Script Evaluator (`eval_recommendation.py`)

Tạo script `ai-service-flask/eval_recommendation.py` độc lập để tự động hóa quy trình kiểm thử:

```python
# ai-service-flask/eval_recommendation.py
import json
import time
import requests

def run_recommendation_evaluation():
    # 1. Load test cases
    # 2. Call POST http://127.0.0.1:5001/api/recommend for each profile
    # 3. Assert constraint correctness (budget, avoid ingredients)
    # 4. Assert data completeness behavior (chua_du_du_lieu status when ingredients empty)
    # 5. Measure latency per request
    # 6. Generate JSON & Markdown report
    pass
```

---

## 5. Lộ Trình Triển Khai Đánh Giá (Execution Steps)

- **Bước 1**: Đấu nối API `/api/recommend` lên môi trường dev server.
- **Bước 2**: Chạy `eval_recommendation.py` để lấy số liệu cơ sở (Baseline Metrics).
- **Bước 3**: Sau khi bổ sung thêm `SKIN_CONCERN_INGREDIENT_KNOWLEDGE_MAP` ở các phiên bản tới, chạy lại `eval_recommendation.py` để so sánh cải thiện chất lượng.
