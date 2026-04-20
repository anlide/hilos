# onTick Rule: < 0.1s

`onTick()` is called on **every worker loop iteration**. It must complete in under 0.1 seconds.

## Why this matters

Worker processes a single message at a time. If `onTick()` runs long:
- Other signals queue up and are delayed
- WebSocket responses become sluggish
- The whole worker becomes unresponsive

## What belongs in onTick

```php
public function onTick(): void {
    // ✅ Check a flag and do small work
    if ($this->pendingUpdate) {
        $this->processPendingUpdate(); // fast operation
    }

    // ✅ Drain a small queue (one item per tick)
    if (!empty($this->queue)) {
        $this->processOneItem(array_shift($this->queue));
    }

    // ✅ Poll async LLM client for completed response
    $this->chatClient->tick();
}
```

## What does NOT belong in onTick

```php
public function onTick(): void {
    // ❌ Synchronous HTTP request
    $result = file_get_contents('https://api.example.com/...');

    // ❌ Heavy DB query
    $rows = Hilos::$db->events->findAll(); // might be slow

    // ❌ sleep / usleep
    sleep(1);

    // ❌ Long loop over large dataset
    foreach ($this->millionItems as $item) { ... }
}
```

## Handling long operations

**Option 1: Async LLM client** — use `AsyncChatLLMInterface`, call `tick()` in `onTick()`, handle result in callback.

**Option 2: Monopolistic agent** — run the heavy work in a dedicated monopolistic worker that has no time pressure from WS signals.

**Option 3: Chunked processing** — process one item per tick, keep a queue.

**Option 4: Cron** — schedule infrequent heavy work via daemon cron, send signal to agent.
