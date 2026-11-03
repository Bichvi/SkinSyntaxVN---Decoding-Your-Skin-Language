from __future__ import annotations

from types import SimpleNamespace

from agent_graph import audit


def test_mask_phone_address_tokens_nested_and_does_not_mutate():
    params = {
        "phone": "0912345678",
        "address": "12 Nguyễn Văn A, Q.1, TP.HCM",
        "AUTHORIZATION": "Bearer secret",
        "nested": [{"sdt_nguoi_nhan": "+84 912 345 678", "csrf": "secret"}],
    }

    masked = audit.mask(params)

    assert masked == {
        "phone": "09xx xxx 678",
        "address": "…, TP.HCM",
        "AUTHORIZATION": "***",
        "nested": [{"sdt_nguoi_nhan": "84xx xxx 678", "csrf": "***"}],
    }
    assert params["phone"] == "0912345678"
    assert params["nested"][0]["csrf"] == "secret"


def test_mask_short_phone_and_address_without_comma():
    assert audit.mask({"sdt": "12345", "dia_chi": "No comma"}) == {
        "sdt": "***",
        "dia_chi": "…",
    }


def test_sensitive_container_values_are_fully_masked():
    assert audit.mask({"token": {"a": "b"}, "phone": ["0912345678"]}) == {
        "token": "***",
        "phone": "***",
    }


def test_record_builds_document_and_uses_insert():
    documents = []

    audit.record("user-1", "order.create", {"phone": "0912345678"}, "ok", insert=documents.append)

    assert len(documents) == 1
    assert documents[0]["actor"] == "user-1"
    assert documents[0]["action"] == "order.create"
    assert documents[0]["params"] == {"phone": "09xx xxx 678"}
    assert documents[0]["outcome"] == "ok"
    assert documents[0]["source"] == "flask"
    assert "ts" in documents[0]


def test_insert_failure_is_swallowed():
    def failing_insert(document):
        raise RuntimeError("insert failed")

    audit.record("user-1", "order.create", {}, "failed", insert=failing_insert)


def test_default_insert_uses_real_getter(monkeypatch):
    inserted = []
    fake_db = SimpleNamespace(chat_agent_audit=SimpleNamespace(insert_one=inserted.append))
    monkeypatch.setattr("chatbot_service.profile_service.get_mongodb_connection", lambda: fake_db)

    audit.record("user-1", "order.create", {}, "ok")

    assert len(inserted) == 1
    assert inserted[0]["source"] == "flask"


def test_mask_extended_phone_address_and_name_keys():
    masked = audit.mask({
        "phone_number": "0912345678", "so_dien_thoai": "0912345678", "mobile": "0912345678",
        "full_address": "12 Nguyễn Văn A, Q.1, TP.HCM", "street": "12 Lê Lợi", "duong": "12 Lê Lợi",
        "ten_nguoi_nhan": "Nguyễn Văn A", "receiver_name": "Bich Vi", "full_name": "Bich Vi",
        "recipient_name": "Bich Vi", "ho_ten": "Ốc",
        "new_address": {"phone_number": "0987654321", "ten_nguoi_nhan": "Lan"},
    })

    assert masked["phone_number"] == masked["so_dien_thoai"] == masked["mobile"] == "09xx xxx 678"
    assert masked["full_address"] == "…, TP.HCM"
    assert masked["street"] == masked["duong"] == "…"
    assert masked["ten_nguoi_nhan"] == "N***"
    assert masked["receiver_name"] == masked["full_name"] == masked["recipient_name"] == "B***"
    assert masked["ho_ten"] == "Ố***"
    assert masked["new_address"] == {"phone_number": "09xx xxx 321", "ten_nguoi_nhan": "L***"}


def test_name_container_values_are_fully_masked():
    assert audit.mask({"full_name": ["a"]}) == {"full_name": "***"}
