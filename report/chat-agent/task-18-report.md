# Task 18 report

## Result

Implemented the router evaluation script and dry-run pytest coverage. Real-LLM evaluation was not run because it would call paid APIs.

## Impact analysis

No GitNexus impact command was required or run: both edited source files are new files, and no existing function, method, route, controller, or shared service was edited. Existing `agent_graph.router.classify`, `agent_graph.state.ConvState`, `agent_graph.llm.build_router_llms`, and `agent_graph.llm.with_schema` were read-only dependencies.

Risk: not applicable to an edited existing symbol. No HIGH/CRITICAL impact was returned.

## Files

- `ai-service-flask/scripts/eval_agent_router.py`
  - Loads only the YAML cases and an explicit dotenv allow-list.
  - Removes MongoDB, Neo4j, and Redis environment variables before importing `agent_graph`.
  - Supports `--dry-run`, real router construction, label precision/recall, overall accuracy, BUY/ORDER_STATUS accuracy, entity checks, threshold exit status, and timestamped JSON reports.
  - Omits case messages and environment values from the JSON report.
- `ai-service-flask/tests/agent_graph/test_eval_router_script.py`
  - Covers dry-run execution, threshold success, and report contents.

## Commands and outputs

1. Initial focused test (before implementation): **FAIL** as expected because `scripts.eval_agent_router` did not exist.

2. Required focused command:

   ```text
   env -u MONGO_URI -u NEO4J_URI -u NEO4J_PASSWORD -u REDIS_URL -u OPENAI_API_KEY -u GOOGLE_API_KEY -u GOOGLE_API_KEYS -u GROQ_API_KEY ../.venv/Scripts/python.exe -m pytest tests/agent_graph -q -p no:cacheprovider
   ```

   Output: `114 passed in 15.71s`

3. Syntax check:

   ```text
   ..\\.venv\\Scripts\\python.exe -m py_compile scripts/eval_agent_router.py
   ```

   Output: passed with no errors.

4. Whitespace check:

   ```text
   git diff --check -- scripts/eval_agent_router.py tests/agent_graph/test_eval_router_script.py
   ```

   Output: passed with no errors.

## Concerns / skipped checks

- Real-LLM scoring was intentionally skipped; no API keys were used and no paid request was made.
- No commit, add, stash, reset, or checkout was performed.
- The workspace contains unrelated pre-existing owner changes; they were left untouched.
