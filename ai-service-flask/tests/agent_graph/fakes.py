from __future__ import annotations

import time
import threading
from typing import Any

from langchain_core.language_models import BaseChatModel
from langchain_core.messages import AIMessage, BaseMessage
from langchain_core.outputs import ChatGeneration, ChatResult
from langchain_core.runnables import RunnableLambda
from pydantic import Field


class ScriptedChatModel(BaseChatModel):
    """Small deterministic model fake for graph tests."""

    responses: list[AIMessage] = Field(default_factory=list)
    structured_outputs: list[Any] = Field(default_factory=list)
    calls: list[list[BaseMessage]] = Field(default_factory=list)
    bound_tool_names: list[str] = Field(default_factory=list)
    delays: list[float] = Field(default_factory=list)
    raise_base_exception: bool = False
    raise_runtime_exception: bool = False

    @property
    def _llm_type(self) -> str:
        return "scripted-chat-model"

    def _generate(self, messages: list[BaseMessage], stop: list[str] | None = None, **kwargs: Any) -> ChatResult:
        self.calls.append(list(messages))
        if self.delays:
            time.sleep(self.delays.pop(0))
        if self.raise_base_exception:
            raise KeyboardInterrupt("fake worker failure")
        if self.raise_runtime_exception:
            raise RuntimeError("fake worker failure")
        if not self.responses:
            raise AssertionError("scripted model ran out of responses")
        return ChatResult(generations=[ChatGeneration(message=self.responses.pop(0))])

    def bind_tools(self, tools: list[Any], **kwargs: Any) -> ScriptedChatModel:
        self.bound_tool_names = [tool.name for tool in tools]
        return self

    def with_structured_output(self, schema: Any, **kwargs: Any) -> RunnableLambda:
        def invoke(_input: Any) -> Any:
            if self.structured_outputs:
                return self.structured_outputs.pop(0)
            return self.invoke(_input)

        return RunnableLambda(invoke)


class ScriptedRouter:
    def __init__(self, output: Any):
        self.output = output
        self.calls: list[Any] = []

    def invoke(self, messages: Any) -> Any:
        self.calls.append(messages)
        return self.output


class FakePhp:
    def __init__(self, responses: dict[str, dict] | None = None):
        self.responses = responses or {}
        self.calls: list[tuple[str, dict | None]] = []

    def call(self, route: str, payload: dict | None = None) -> dict:
        self.calls.append((route, payload))
        return self.responses.get(route, {"ok": True})


class FakeGraphTools:
    def __init__(self, result: dict | None = None):
        self.result = result or {
            "products": [
                {"id": "p1", "name": "Sữa rửa mặt", "price": 100000},
                {"id": "p2", "name": "Kem dưỡng", "price": 200000},
            ]
        }
        self.calls: list[tuple[str, dict]] = []

    def call(self, name: str, args: dict) -> dict:
        self.calls.append((name, args))
        return self.result


class LateEmitTool:
    name = "prepare_order_draft"

    def __init__(self, ctx: Any, delay: float = 0.08):
        self.ctx = ctx
        self.delay = delay
        self.finished = threading.Event()

    def invoke(self, _args: dict) -> str:
        try:
            time.sleep(self.delay)
            self.ctx.state.open_draft_id = "late-draft"
            self.ctx.emit("cart_effect", {"effect": {"late": True}})
            return "late result"
        finally:
            self.finished.set()


class BlockingEmitTool:
    name = "search_products"

    def __init__(self, ctx: Any):
        self.ctx = ctx
        self.started = threading.Event()
        self.release = threading.Event()
        self.calls = 0

    def invoke(self, _args: dict) -> str:
        self.calls += 1
        self.started.set()
        self.ctx.emit("token", {"text": "first step"})
        self.release.wait(1.0)
        return "released"
