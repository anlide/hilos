# Ollama Configuration

Ollama runs as a separate Docker service. The chat demo connects to it via HTTP.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `LLM_LOCAL_URL` | `http://host.docker.internal:11434` | Ollama base URL |
| `CHAT_CONTEXT_ANALYZER_URL` | falls back to `LLM_LOCAL_URL` | Context analyzer Ollama URL |
| `CHAT_CONTEXT_ANALYZER_MODEL` | `qwen2.5:0.5b` | Context analyzer model |
| `CHAT_CONTEXT_ANALYZER_PROVIDER` | `local` | `local` or `external` |
| `HILOS_MODERATION_TEXT_*` | framework defaults | Framework profile that checks text and attachment metadata |
| `HILOS_MODERATION_IMAGE_*` | framework defaults | Framework profile that checks images and profile photos |

## Starting Ollama (from repo root)

```bash
composer run ollama:start                  # CPU
composer run ollama:start-gpu-nvidia       # NVIDIA GPU
composer run ollama:start-gpu-amd          # AMD GPU

composer run ollama:pull                   # pull default model
composer run ollama:pull-gpu-nvidia
composer run ollama:pull-gpu-amd
```

## Moderation profiles

Chat messages, attached images, profile photos and own-name checks use the
framework moderation profiles. Configure their `moderation.text.*` and
`moderation.image.*` settings through `/hilos/settings`; environment defaults
use `HILOS_MODERATION_TEXT_*` and `HILOS_MODERATION_IMAGE_*`. For exact keys,
defaults, provider selection and check deadlines, see the canonical
[moderation guide](../../../../docs/agents/architecture/moderation.md).

## Model recommendation

The framework defaults are `qwen2.5:0.5b` for text moderation and
`qwen2.5vl:3b` for image moderation. Chat's bot and context-analyzer profiles
remain separate from those moderation roles.
See `docs/docker-ollama-gpu.md` for GPU setup.
