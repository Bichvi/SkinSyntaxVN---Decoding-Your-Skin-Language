import json
import os
import sys
import unittest
from types import SimpleNamespace

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from providers.llm_provider import LLMProvider


class FakeClient:
    def __init__(self):
        self.options = []
        self.chat = SimpleNamespace(completions=SimpleNamespace(create=self._create))

    def _create(self, **options):
        self.options.append(options)
        message = SimpleNamespace(content=json.dumps({"ok": 1}))
        return SimpleNamespace(choices=[SimpleNamespace(message=message)])


def provider(client):
    llm = LLMProvider.__new__(LLMProvider)
    llm.pool = [client]
    llm.models = ["test-model"]
    return llm


class CompletionTokenTests(unittest.TestCase):
    def test_default_cap_unchanged(self):
        client = FakeClient()
        provider(client).invoke_json_schema("s", "u", schema={"type": "object"}, schema_name="x")
        self.assertEqual(client.options[0]["max_completion_tokens"], 700)

    def test_override_is_used(self):
        client = FakeClient()
        provider(client).invoke_json_schema("s", "u", schema={"type": "object"}, schema_name="x",
                                            max_completion_tokens=2000)
        self.assertEqual(client.options[0]["max_completion_tokens"], 2000)


if __name__ == "__main__":
    unittest.main()
