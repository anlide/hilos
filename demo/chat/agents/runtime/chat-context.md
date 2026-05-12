# ChatContext

**Collection:** `ChatRtContext::chatContexts` | **Key:** `ChatContext::ID_MAIN = 'main'`

Single-instance RT state that holds the AI-generated summary of the current chat conversation.
Written by `ChatContextAnalyzerAgent`, consumed by `BotAgent` for LLM prompts.

## Fields

Defined in `Runtime/State/Item/ChatContext.php` (check file for current fields).
Typically contains: `summary` (string), `updatedAt` (int), `eventCount` (int).

## Lifecycle

- **Created**: `ChatContextAnalyzerAgent::onStart()` → `chatContexts->actions->init()` if collection empty
- **Updated**: after each LLM summarization run in `ChatContextAnalyzerAgent`
- **Persists** across connections (RT state, not per-user)

## Truth source

`ChatContextAnalyzerAgent` owns `ChatRtContext::chatContexts`.

## Usage in BotAgent

```php
$ctx = Hilos::$rt->chatContexts->getStateCollection()->get(ChatContext::ID_MAIN);
$summary = $ctx?->summary ?? '';
// Include $summary in LLM prompt to give bots context of the conversation
```

## Location

`backend/Runtime/State/Item/ChatContext.php` — state item
`backend/Runtime/View/Context/ChatRtContext.php` — context definition
`backend/Runtime/View/Collection/ChatContexts.php` — collection
`backend/Runtime/View/Actions/Collection/ChatContextsActions.php` — write actions
