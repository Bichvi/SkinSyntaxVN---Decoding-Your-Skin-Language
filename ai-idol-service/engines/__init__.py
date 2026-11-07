"""AI Idol media engines.

The pipeline imports heavy engines only at the stage that needs them.  This
module keeps that lazy-loading behavior while exposing the submodules through
``engines.<name>`` for integrations and test doubles that patch a module
directly.
"""

from importlib import import_module


_LAZY_MODULES = {
    "avatar_engine",
    "content_agent",
    "knowledge_retriever",
    "product_collector",
    "program_composer",
    "script_validator",
    "syna_engine",
    "video_composer",
    "voice_engine",
}


def __getattr__(name: str):
    if name in _LAZY_MODULES:
        module = import_module(f"{__name__}.{name}")
        globals()[name] = module
        return module
    raise AttributeError(f"module {__name__!r} has no attribute {name!r}")


__all__ = sorted(_LAZY_MODULES)
