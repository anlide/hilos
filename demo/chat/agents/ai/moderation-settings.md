# Moderation Settings

All moderation configuration is stored in the DB `settings` table and editable at runtime.

## Settings table

Accessed via `Hilos::$db->settings` (key/value store).
Seed `003` populates default moderation settings on first run.

## Reading settings

```php
$url = Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_URL]->string();
$model = Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_MODEL]->string();
$provider = Hilos::$setting[ChatSettingsConstants::CHAT_MODERATION_PROVIDER]->string();
```

## Editing settings

- Admin UI: `/hilos/settings` or admin panel settings table
- Direct: `Hilos::$db->settings[$key]?->actions->updateValue($value)`

## Key settings (seed 003 defaults)

| Key | Default | Meaning |
|---|---|---|
| `chat_moderation_url` | empty, falls back to `LLM_LOCAL_URL` | LLM base URL |
| `chat_moderation_model` | `qwen2.5:0.5b` | Model for user message moderation; messages may include attachment metadata |
| `chat_moderation_provider` | `local` | `local` or `external` |

## Context analyzer

Configured via **env vars** (not DB settings) — see `ai/ollama-config.md`.
Rationale: context analyzer runs in monopolistic worker that starts before DB settings are loaded.

## Moderator prompt pieces

Stored in `ChatDbContext::moderatorPromptPieces` table.
Managed via `/admin/moderator` → `ModeratorPromptPiecesTable`.
`ModeratorAgent` builds its system prompt from these pieces on each LLM call.
Allows dynamic prompt tuning without restart.
