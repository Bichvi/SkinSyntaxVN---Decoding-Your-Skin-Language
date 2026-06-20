# -*- coding: utf-8 -*-
from __future__ import annotations

import logging
logger = logging.getLogger(__name__)

import os

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


def get_llms() -> list:
    global _llms
    if _llms is not None:
        return _llms

    _llms = []
    from langchain_openai import ChatOpenAI
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    if openai_key and openai_key.startswith("sk-"):
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
                _llms.append(llm)
                logger.debug(f"[LLM_POOL] OpenAI {model} ready")
                break
            except Exception as e:
                logger.warning(f"[LLM_POOL] OpenAI {model} init failed: {e}")

    logger.info(f"[LLM_POOL] Pool ready: {len(_llms)} LLM(s) available")
    return _llms


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
