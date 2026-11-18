# RAGAS benchmark: semantic vs hybrid_rrf vs hybrid_rerank

- Date: 2026-11-17
- n = 2 queries x 3 configs (6 samples)
- Judge LLM: gemini-3.5-flash-lite (temperature 0); embeddings for Answer Relevancy: models/gemini-embedding-001
- Generator: openai/gpt-oss-120b (6 answers)
- ragas 0.4.4, langchain-google-genai 4.5.0, langchain-core 1.8.1

| Metric | semantic | hybrid_rrf | hybrid_rerank |
|---|---|---|---|
| Context Precision (RAGAS, LLM) | 1.000 | 0.162 | 0.877 |
| Precision@5 (labels) | 0.000 | 0.000 | 0.000 |
| Recall@10 (labels) | 0.000 | 0.000 | 0.000 |
| Hit@5 | 0.000 | 0.000 | 0.000 |
| MRR@10 | 0.000 | 0.000 | 0.000 |
| nDCG@10 | 0.000 | 0.000 | 0.000 |
| Answer Relevancy | 0.790 (n=1/2) | 0.714 (n=1/2) | 0.793 |
| Faithfulness | 0.071 | 0.375 | 0.222 |
| Retrieval latency, mean s/query | 0.10 | 0.15 | 7.02 |

NaN / failed judge scores are excluded from the mean; where any exist the cell shows n_valid/n.

## Method and caveats

- Corpus: Chroma collection `products` (6,377 docs). All 3 configs retrieve top-10 with NO metadata filters (ranking quality only); the answer is generated from the top-5 docs (each truncated to 1200 chars).
- Label metrics (Precision@5, Recall@10, Hit@5, MRR@10, nDCG@10) use silver labels derived from product metadata: a doc is relevant iff category matches AND skin type matches (the value `Da thường/Mọi loại da` counts as suitable for any skin type; `Unknown` never matches) AND ingredient keyword appears in the document text AND price <= budget. Labels are heuristic, not human-annotated; keyword matching on text can miss synonyms. Recall@10 = relevant in top-10 / min(|relevant|, 10).
- RAGAS metrics are LLM-judged with a single judge model (gemini-3.5-flash-lite) on Vietnamese text; the judge prompts are English, scores are noisy and have no human validation. Context Precision is the reference-free variant (judged against the generated answer, not a gold answer), so it rewards contexts that support the answer actually produced.
- Answers were produced by a fixed Vietnamese advisory prompt (answer only from context, max 3 products, say so if no suitable product). When the context lacks a suitable product the model is told to say so, which can lower Answer Relevancy but raise Faithfulness.
- Single run, temperature 0, 30 queries: differences of a few hundredths are within noise. Reranker/BM25/embedding settings are the production defaults (RRF k=60, alpha=0.6).
- Generator and judge may be rate-limited; see generator model counts above for fallbacks.
