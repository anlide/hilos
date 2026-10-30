# Moderation Settings

Chat consumes the framework moderation profiles and owns no moderation profile
settings or prompt-piece table. The shared settings, environment defaults,
supported input types and runtime behavior are documented in the canonical
[moderation guide](../../../../docs/agents/architecture/moderation.md) and
[LLM routing guide](../../../../docs/agents/architecture/llm-routing.md).

Chat-specific bot profile settings remain separate from moderation. Its bot
and context-analyzer configuration is summarized in
[`ollama-config.md`](ollama-config.md).
