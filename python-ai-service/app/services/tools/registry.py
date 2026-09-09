"""
Tool registry for the tool-calling path (see ../tool_calling_service.py) —
the real answer to why "is it in stock?" or "what does it cost right now?"
can't come from the regular RAG pipeline: retrieval only ever sees chunks
from the last content sync, a point-in-time snapshot, never the merchant's
actual live system.

Opt-in per chatbot: nothing here runs unless chatbots.enabled_tools
explicitly names a tool (see ChatService::gatewayPayload() on the Laravel
side and this file's get_enabled_tools()). Every existing chatbot's
behavior is completely unchanged by this module's mere existence.
"""
from dataclasses import dataclass
from typing import Callable, List


@dataclass
class Tool:
    name: str
    description: str
    # JSON schema for the parameters object — sent to the model verbatim
    # as the OpenAI tool-calling "function.parameters". Deliberately never
    # declares chatbot_id/schema_name/db as a property here: those always
    # come from the real request context the loop is already running
    # under, never from the model's own tool-call arguments — see
    # tool_calling_service._execute_tool_call(), which strips any such key
    # even if a model tries to supply one anyway. A tool's own `handler`
    # signature takes them as explicit keyword arguments the loop injects.
    parameters: dict
    handler: Callable[..., dict]
    access_level: str  # "read" | "write" — see the loop for why "write"
                        # is refused unconditionally today (no real
                        # in-conversation confirmation flow exists yet).


_REGISTRY: dict[str, Tool] = {}


def register(tool: Tool) -> None:
    _REGISTRY[tool.name] = tool


def get_enabled_tools(enabled_names: List[str]) -> List[Tool]:
    """Only tools that are BOTH registered AND explicitly named in the
    calling chatbot's own enabled_tools — an unknown name in that list is
    silently ignored (not an error), same posture as an unknown widget
    quick-question or any other admin-configurable list elsewhere in this
    app: a stale/mistyped entry degrades gracefully, it doesn't 500."""
    return [_REGISTRY[n] for n in enabled_names if n in _REGISTRY]


def to_openai_schema(tools: List[Tool]) -> List[dict]:
    return [
        {
            "type": "function",
            "function": {
                "name": t.name,
                "description": t.description,
                "parameters": t.parameters,
            },
        }
        for t in tools
    ]


def all_tool_names() -> List[str]:
    return list(_REGISTRY.keys())
