# Phase 2D.2: Reason Tag Policy & Grounded Explainability

## 1. Principles of Grounded Explainability
1. **Evidence Grounding:** A reason tag is ONLY displayed if the corresponding signal exists and meets an empirical relevance threshold.
2. **One Tag Limit:** Each deal card displays at most ONE primary reason badge to prevent visual clutter in product grids.
3. **No Deceptive Percentages:** The UI strictly forbids fake percent match scores (e.g. "98% phù hợp", "95% tương thích", "AI chọn cho bạn").
4. **No Medical / Clinical Language:** The UI strictly prohibits unauthorized clinical claims (e.g. "Chuẩn y khoa", "Trị dứt điểm mụn", "An toàn tuyệt đối").

---

## 2. Priority Hierarchy for Reason Tag Selection

When a deal card is rendered, reason tags are selected according to the following deterministic hierarchy:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Search Evidence Match (search_similarity >= 0.40)       │
│    Tag: "Theo tìm kiếm gần đây"                             │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. View Evidence Match (view_similarity >= 0.40)           │
│    Tag: "Tương tự sản phẩm bạn vừa xem"                     │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Skin Type Match (skin_score >= 1.00)                     │
│    Tag: "Phù hợp loại da của bạn"                           │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Budget Fit (budget_score >= 1.00 on Flash Price)         │
│    Tag: "Trong ngân sách của bạn"                           │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Deep Discount (discount_percent >= 35%)                  │
│    Tag: "Giảm sâu {N}%"                                     │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. Review / Reputation Evidence (v >= 21.0 && R >= 4.5)     │
│    Tag: "Được đánh giá tốt"                                 │
└──────────────────────────────┬──────────────────────────────┘
                               │ (if false)
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. Generic Fallback                                         │
│    Tag: "Giảm {N}%"  or  "Ưu đãi nổi bật"                   │
└─────────────────────────────────────────────────────────────┘
```

*Note: This prioritization reflects UX communication value (highlighting user-specific intent first) rather than an exact mathematical decomposition.*
