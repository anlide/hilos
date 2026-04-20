# Anti-pattern: Repository / Service Layer

**Do not introduce Repository or Service classes** as abstractions over `DbCollection`.

## Why this is wrong in Hilos

Hilos has a built-in layered data access pattern: `Entity → Object → DbCollection → Hilos::$db`.
Adding a Repository/Service layer on top duplicates this abstraction without benefit and breaks the convention that all code follows.

## Examples

```php
// ❌ Wrong: Repository wrapping DbCollection
class EventRepository {
    public function addMessage(int $userId, string $text): void {
        Hilos::$db->events->actions->addMessage($userId, $text);
    }
    public function getAll(): Events {
        return Hilos::$db->events;
    }
}

// ❌ Wrong: Service with business logic that belongs in agent or actions
class ModerationService {
    public function moderate(string $text): bool { ... }
}

// ✅ Correct: call DbCollection directly from agent or page
Hilos::$db->events->actions->addMessage($userId, $text);

// ✅ Correct: business logic in agent
class ChatAgent extends AbstractAgent {
    private function handleMessage(string $text, int $userId): void {
        Hilos::$db->events->actions->addMessage($userId, $text);
    }
}
```

## Where business logic belongs

- **Agent** — orchestration, state management, signal handling
- **Page** — action parsing, input validation, initial data delivery on subscribe
- **DbCollection actions** — DB mutations for a specific collection
- **RtStates actions** — RT state mutations

## Helpers (allowed exception)

Stateless utility helpers with no DI are acceptable:
```php
// ✅ OK: stateless utility (no constructor injection, no state)
class ChatLLMHelper {
    public static function buildPrompt(string $context): string { ... }
}
```
