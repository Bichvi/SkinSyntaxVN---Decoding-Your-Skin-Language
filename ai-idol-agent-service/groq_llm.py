from __future__ import annotations

from typing import Any, Dict, List, Optional, Union

from crewai import BaseLLM
from groq import Groq


class GroqCrewLLM(BaseLLM):
    """CrewAI adapter that sends only fields accepted by Groq's chat API."""

    def __init__(
        self,
        model: str,
        api_key: str,
        *,
        temperature: float,
        max_tokens: int,
    ):
        clean_model = model.removeprefix("groq/")
        super().__init__(model=clean_model, temperature=temperature)
        self.client = Groq(api_key=api_key)
        self.max_tokens = max_tokens

    @staticmethod
    def _sanitize_messages(
        messages: Union[str, List[Dict[str, Any]]],
    ) -> List[Dict[str, str]]:
        if isinstance(messages, str):
            return [{"role": "user", "content": messages}]
        clean: List[Dict[str, str]] = []
        for message in messages:
            role = str(message.get("role") or "user")
            if role not in {"system", "user", "assistant"}:
                role = "user"
            content = message.get("content")
            if isinstance(content, list):
                content = "\n".join(
                    str(item.get("text") if isinstance(item, dict) else item)
                    for item in content
                )
            clean.append({"role": role, "content": str(content or "")})
        return clean

    def call(
        self,
        messages: Union[str, List[Dict[str, Any]]],
        tools: Optional[List[dict]] = None,
        callbacks: Optional[List[Any]] = None,
        available_functions: Optional[Dict[str, Any]] = None,
        **kwargs: Any,
    ) -> str:
        del tools, callbacks, available_functions, kwargs
        options: Dict[str, Any] = {
            "model": self.model,
            "messages": self._sanitize_messages(messages),
            "temperature": self.temperature,
            "max_completion_tokens": self.max_tokens,
        }
        if self.model.startswith("openai/gpt-oss"):
            options.update({"reasoning_effort": "low", "include_reasoning": False})
        elif self.model.startswith("qwen/qwen3"):
            options["reasoning_effort"] = "none"
        response = self.client.chat.completions.create(**options)
        content = (response.choices[0].message.content or "").strip()
        if not content:
            raise RuntimeError("Groq returned an empty response")
        return content

    def supports_function_calling(self) -> bool:
        return False

    def supports_stop_words(self) -> bool:
        return False

    def get_context_window_size(self) -> int:
        return 32768
