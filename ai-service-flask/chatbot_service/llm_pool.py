# -*- coding: utf-8 -*-
from __future__ import annotations

import logging
logger = logging.getLogger(__name__)

import os
import threading

from langchain_core.output_parsers import StrOutputParser, JsonOutputParser
from langchain_core.prompts import ChatPromptTemplate
from langchain_core.runnables import Runnable

OPENAI_MODEL = os.getenv("OPENAI_CHAT_MODEL", "gpt-4o-mini").strip()
OPENAI_MODELS = [
    m.strip()
    for m in os.getenv(
        "OPENAI_CHAT_MODELS",
        "gpt-4o-mini,gpt-4o",
    ).split(",")
    if m.strip()
]

_llms: list | None = None
_llms_lock = threading.Lock()


def get_llms() -> list:
    """Double-checked locking: builder chạy đúng 1 lần dù nhiều luồng gọi đồng thời,
    và không luồng nào thấy danh sách đang dựng dở."""
    global _llms
    cached = _llms
    if cached is not None:
        return cached
    with _llms_lock:
        if _llms is not None:
            return _llms
        built = _build_llms()
        _llms = built  # gán nguyên tử sau khi dựng xong
        return built


def _build_llms() -> list:
    built: list = []
    try:
        from langchain_openai import ChatOpenAI
    except Exception:
        try:
            from langchain_community.chat_models import ChatOpenAI
        except Exception:
            ChatOpenAI = None
    # (a) Groq — free, ưu tiên đầu tiên (không gọi mạng khi dựng)
    groq_key = os.getenv("GROQ_API_KEY", "").strip()
    if ChatOpenAI is not None and groq_key:
        try:
            groq_model = os.getenv("GROQ_CHAT_MODEL", "openai/gpt-oss-120b").strip() or "openai/gpt-oss-120b"
            groq_kwargs = dict(
                openai_api_key=groq_key,
                openai_api_base="https://api.groq.com/openai/v1",
                model_name=groq_model,
                temperature=0,
                max_tokens=4096,
                max_retries=0,
                request_timeout=20,
            )
            if groq_model.startswith("openai/gpt-oss"):
                groq_kwargs["reasoning_effort"] = "low"
            built.append(ChatOpenAI(**groq_kwargs))
            logger.debug(f"[LLM_POOL] Groq {groq_model} ready")
        except Exception as e:
            logger.warning(f"[LLM_POOL] Groq init failed: {type(e).__name__}")

    # (b) Gemini — free, thứ hai
    gemini_key = os.getenv("GOOGLE_API_KEY", "").strip()
    if not gemini_key:
        gemini_key = next(
            (k.strip() for k in os.getenv("GOOGLE_API_KEYS", "").split(",") if k.strip()), ""
        )
    if gemini_key:
        try:
            from langchain_google_genai import ChatGoogleGenerativeAI

            gemini_model = os.getenv("GEMINI_CHAT_MODEL", "gemini-2.5-flash").strip() or "gemini-2.5-flash"
            built.append(
                ChatGoogleGenerativeAI(
                    model=gemini_model,
                    temperature=0,
                    max_tokens=4096,
                    max_retries=0,
                    google_api_key=gemini_key,
                )
            )
            logger.debug(f"[LLM_POOL] Gemini {gemini_model} ready")
        except Exception as e:
            logger.warning(f"[LLM_POOL] Gemini init failed: {type(e).__name__}")

    # (c) OpenAI — trả phí, chỉ là fallback cuối cùng
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    if ChatOpenAI is not None and openai_key and openai_key.startswith("sk-"):
        for model in OPENAI_MODELS or [OPENAI_MODEL]:
            try:
                llm = ChatOpenAI(
                    openai_api_key=openai_key,
                    model_name=model,
                    temperature=0,
                    max_tokens=4096,
                    max_retries=1,
                    request_timeout=20,
                )
                built.append(llm)
                logger.debug(f"[LLM_POOL] OpenAI {model} ready (paid fallback, last)")
                break
            except Exception as e:
                logger.warning(f"[LLM_POOL] OpenAI {model} init failed: {type(e).__name__}")

    logger.info(f"[LLM_POOL] Pool ready: {len(built)} LLM(s) available")
    return built


def get_classifier_llm():
    llms = get_llms()
    return llms[0] if llms else None


def build_str_chain(prompt: ChatPromptTemplate, llms: list) -> Runnable | None:
    if not llms:
        return None
    chains = [prompt | llm | StrOutputParser() for llm in llms]
    return chains[0].with_fallbacks(chains[1:], exceptions_to_handle=(Exception,))


def build_json_chain(prompt: ChatPromptTemplate, llms: list) -> Runnable | None:
    if not llms:
        return None
    chains = [prompt | llm | JsonOutputParser() for llm in llms]
    return chains[0].with_fallbacks(chains[1:], exceptions_to_handle=(Exception,))
