# Anti-pattern: Blocking Operations in onTick / Signal Handlers

Any blocking call in `onTick()` or a signal handler stalls the entire worker.

## What blocks

```php
// ❌ All of these block the event loop:
sleep(1);
usleep(500_000);
file_get_contents('http://api.example.com/data');  // sync HTTP
curl_exec($ch);                                     // sync curl
$pdo->query('SELECT SLEEP(5)');                    // slow DB query
json_decode(file_get_contents('large.json'));       // large file read
```

## How to spot blocking code

- Worker becomes unresponsive for a period
- WS messages pile up and are delivered in bursts
- Response times spike inconsistently

## Correct approaches

### Async LLM calls

Use `AsyncChatLLMInterface` — non-blocking, poll with `tick()`:

```php
private AsyncChatLLMInterface $client;

public function onTick(): void {
    $this->client->tick(); // poll for completion, calls callback when done
}

private function startModeration(string $text): void {
    $this->client->generate($messages, $options, function($result) {
        $this->handleModerationResult($result);
    });
}
```

### Queue + one item per tick

```php
public function onTick(): void {
    if (!empty($this->queue)) {
        $this->processOne(array_shift($this->queue));
    }
}
```

### Monopolistic agent for heavy work

Isolate slow operations in a monopolistic agent.
It still must not call `sleep()`, but it can take longer per tick
since it doesn't handle real-time WS signals.

### Cron for periodic heavy work

Schedule infrequent work via `addCronRule()` in daemon, send signal to agent.
