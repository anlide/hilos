# Ollama Configuration

Ollama runs as a separate Docker service. The chat demo connects to it via HTTP.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `LLM_LOCAL_URL` | `http://host.docker.internal:11434` | Ollama base URL |
| `CHAT_CONTEXT_ANALYZER_URL` | falls back to `LLM_LOCAL_URL` | Context analyzer Ollama URL |
| `CHAT_CONTEXT_ANALYZER_MODEL` | `qwen2.5:0.5b` | Context analyzer model |
| `CHAT_CONTEXT_ANALYZER_PROVIDER` | `local` | `local` or `external` |

## Starting Ollama (from repo root)

```bash
composer run ollama:start                  # CPU
composer run ollama:start-gpu-nvidia       # NVIDIA GPU
composer run ollama:start-gpu-amd          # AMD GPU

composer run ollama:pull                   # pull default model
composer run ollama:pull-gpu-nvidia
composer run ollama:pull-gpu-amd
```

## Moderation provider settings (via DB)

Moderation URL and model are stored in `ChatDbContext::settings` (seed 003 populates defaults).
Override at runtime via `/hilos/settings` or `/admin` → Settings table.

`Hilos::$setting` reads these at runtime:
```php
Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_URL]->string()
Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_MODEL]->string()
Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_PROVIDER]->string()
```

## External provider (OpenAI-compatible)

Set `chat_moderation_provider` to `external` in settings.
`ClientFactory::createChatClient()` uses `OPENAI_API_KEY` env var and standard OpenAI endpoint.

## Model recommendation

`qwen2.5:0.5b` — lightweight, fast, good for allow/block classification.
For better quality moderation use `qwen2.5:1.5b` or larger.
See `docs/docker-ollama-gpu.md` for GPU setup.
