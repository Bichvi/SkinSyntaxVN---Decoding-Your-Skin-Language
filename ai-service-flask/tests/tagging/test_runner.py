import json

from tagging.labeler import ProductInput
from tagging.runner import Labeler, RateLimiter, extract_usage

VOCAB = {"benefits": {"cap_am": "Cấp ẩm"}, "concerns": {"mun": "Mụn"}, "skin_types": {"da_dau": "Da dầu"}}
PRODUCT = ProductInput(1, "Kem", "Kem dưỡng", ("Water",), "mô tả")
GOOD = json.dumps({"benefits": ["cap_am"], "concerns": [], "skin_types": [], "evidence": ["Water"]})


class Resp:
    def __init__(self, content, usage=None):
        self.content = content
        self.usage_metadata = usage or {"input_tokens": 100, "output_tokens": 20, "total_tokens": 120}
        self.response_metadata = {"model_name": "fake-model"}


class FakeLLM:
    def __init__(self, *replies):
        self.replies, self.calls = list(replies), 0

    def invoke(self, messages):
        self.calls += 1
        item = self.replies.pop(0)
        if isinstance(item, Exception):
            raise item
        return item


def make(llm, **kw):
    sleeps = []
    lab = Labeler(llm, VOCAB, rpm=0, sleep=sleeps.append, **kw)
    return lab, sleeps


def test_success_collects_usage_and_model():
    lab, sleeps = make(FakeLLM(Resp(GOOD)))
    res = lab.label(PRODUCT)
    assert res.parsed.ok and res.parsed.benefits == ["cap_am"] and res.attempts == 1
    assert res.model == "fake-model" and res.usage == {"input_tokens": 100, "output_tokens": 20, "total_tokens": 120}
    assert sleeps == []


def test_retries_llm_errors_with_exponential_backoff():
    llm = FakeLLM(TimeoutError("x"), RuntimeError("y"), Resp(GOOD))
    lab, sleeps = make(llm, base_backoff=2.0)
    res = lab.label(PRODUCT)
    assert res.parsed.ok and llm.calls == 3 and sleeps == [2.0, 4.0]


def test_retries_unparseable_output_then_gives_up():
    llm = FakeLLM(Resp("xin chào"), Resp("{broken"), Resp("???"))
    lab, sleeps = make(llm)
    res = lab.label(PRODUCT)
    assert not res.parsed.ok and res.attempts == 3 and llm.calls == 3
    assert res.usage["total_tokens"] == 360        # tokens of every attempt are counted


def test_error_message_is_not_leaked():
    llm = FakeLLM(*[RuntimeError("sk-SECRET-KEY in message")] * 3)
    lab, _ = make(llm)
    res = lab.label(PRODUCT)
    assert not res.parsed.ok and "SECRET" not in (res.parsed.error or "") and "RuntimeError" in res.parsed.error


def test_rate_limiter_spaces_calls():
    now = [0.0]
    sleeps = []

    def sleep(s):
        sleeps.append(round(s, 3))
        now[0] += s

    lim = RateLimiter(30, clock=lambda: now[0], sleep=sleep)     # 2s interval
    for _ in range(3):
        lim.wait()
    assert sleeps == [2.0, 2.0]
    assert RateLimiter(0).interval == 0


def test_extract_usage_variants():
    class R:
        usage_metadata = None
        response_metadata = {"token_usage": {"prompt_tokens": 5, "completion_tokens": 2, "total_tokens": 7}}
    assert extract_usage(R()) == {"input_tokens": 5, "output_tokens": 2, "total_tokens": 7}
    assert extract_usage(object()) == {}
