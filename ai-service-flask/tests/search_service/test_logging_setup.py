import logging
import sys

import search_service


def test_configure_logging_idempotent_and_info_visible(capsys, monkeypatch):
    monkeypatch.delenv("SEARCH_LOG_LEVEL", raising=False)
    log = search_service.configure_logging()
    search_service.configure_logging()
    ours = [h for h in log.handlers if getattr(h, "_search_service_handler", False)]
    assert len(ours) == 1
    assert log.level == logging.INFO
    assert log.propagate is False
    # handler gắn stdout hiện tại của module khi dựng; chỉ cần kiểm tra là StreamHandler
    assert isinstance(ours[0], logging.StreamHandler)


def test_level_from_env(monkeypatch):
    monkeypatch.setenv("SEARCH_LOG_LEVEL", "warning")
    assert search_service.configure_logging().level == logging.WARNING
    monkeypatch.setenv("SEARCH_LOG_LEVEL", "bogus")
    assert search_service.configure_logging().level == logging.INFO
