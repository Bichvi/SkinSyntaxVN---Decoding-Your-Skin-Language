import re
from pathlib import Path

from agent_graph.prompts import TOOL_DOCS
from agent_graph.tools import TOOL_SCOPES


def test_no_tool_named_like_order_creation():
    assert not any(re.search(r"create_order|place_order|confirm", name) for name in TOOL_DOCS)


def test_php_client_never_calls_confirm():
    root = Path(__file__).parents[2] / "agent_graph"
    assert "chat_order_confirm" not in "\n".join(path.read_text(encoding="utf-8") for path in root.glob("*.py"))


def test_every_write_tool_has_scope():
    read_only = {name for name, scope in TOOL_SCOPES.items() if scope is None}
    write_tools = set(TOOL_DOCS) - read_only
    assert write_tools
    assert all(TOOL_SCOPES[name] for name in write_tools)


def test_scope_missing_tool_can_be_invoked_directly():
    from agent_graph.state import ConvState
    from agent_graph.tools import ToolContext, _build

    ctx = ToolContext(None, None, [], lambda *_: None, ConvState(), None, lambda _request: {"sections": []})
    result = _build(ctx, "view_cart").invoke({})
    assert '"error": "login_required"' in result


def test_build_tools_names_match_tool_docs_for_full_scopes():
    from agent_graph.auth import AgentClaims
    from agent_graph.state import ConvState
    from agent_graph.tools import ToolContext, build_tools

    ctx = ToolContext(AgentClaims("7", "x@y.test", "s", ("cart:write", "draft:create", "order:read"), 2_000_000_000, "j"), None, [], lambda *_: None, ConvState(), None, lambda _request: {"sections": []})
    assert {tool.name for tool in build_tools(ctx)} == set(TOOL_DOCS)
