"""Make ``ai-service-flask`` importable for home_service tests."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


def pytest_configure(config):
    config.addinivalue_line(
        "markers", "integration: needs a real local Neo4j (NEO4J_TEST_URI/NEO4J_URI + NEO4J_PASSWORD), else skipped",
    )
