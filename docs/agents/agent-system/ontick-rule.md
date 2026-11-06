# onTick Rule: short work, bounded idle wait

`onTick()` is work, not a timer. It must complete in under 0.1 seconds. A worker
may wait between passes while it has no short work to do; that idle wait has a
separate, explicit limit below. See [worker timing](../architecture/event-loop.md#timing).

## Idle wait permission

`AbstractAgent` defaults `MAX_IDLE_SLEEP_SECONDS` to `0.1`. An agent author may
raise it only after auditing every obligation that needs another pass while no
daemon message or tracked I/O is active. Opt in on the class whose complete
obligations were audited with `protected const MAX_IDLE_SLEEP_SECONDS = 600;`.
An empty or inherited `onTick()` is not itself permission for a longer wait.
Direct `AgentInterface` implementations and a worker with no agents keep the
`0.1`-second fallback.

Only an `int` or `float` whose value is finite and within `0.1` through `600`
seconds, inclusive, is valid. A numeric string, boolean, `NAN`, or `INF` is not
converted or clamped: validation refuses the agent through the ordinary
`start_failed` path with `AgentException`, before registration and `onStart()`,
and releases the start interest.

The worker takes the minimum allowance of all live agents. Each allowance is
measured from the beginning of the work pass, so the work already done spends
that time. It permits a wait while idle; it does not promise a fixed tick rate
or real-time deadline, and it never permits spending that time inside
`onTick()`. Tracked work, incoming messages, and earlier declared deadlines
can make the next pass happen sooner.

For an indexed instance owner, the existing `AgentRegistry`/`AgentIdleTracker`
idle-window end is another deadline ([idle stop](../architecture/agent-lifecycle.md#idle-stop)). A live subscription prevents that idle
stop but does not grant a different worker allowance. Stops still use the
ordinary path and retain the existing checks for silence, subscribers, work in
flight, and protected mode; a stop blocked by those checks does not create a
retry spin.

Before raising the limit, inspect the agent's effective own and inherited
`onTick()`, `shouldStop()`, and `cronRules()`, its traits, and every timer,
poll, backoff, expiry, and local queue. Check the gaps before an I/O client is
created and after the last active I/O finishes. Cron and I/O tracking do not
cover an arbitrary in-memory queue, a retry waiting to create a client, or a
timer outside `cronRules()`. If an obligation or its next wake is uncertain,
keep the default. Do not grant a wider allowance to an abstract family because
one leaf is safe; inspect its descendants and keep permission on the audited
concrete class. A directory watcher must keep the default; see
[filesystem watch](../architecture/filesystem-watch.md#idle-wait).

## A Schedule The Agent Keeps Itself

Every `CronRule` an agent checks must be returned from `cronRules()`. After
`onTick()`, the worker takes one snapshot of that set and uses it for both the
next wake and the Daemon report. The next matching minute comes from the project's
existing calendar and time zone, based on the start of the tick so a tick that
crosses a minute keeps the correct boundary. Do not register or forget rules
by hand; let `cronRules()` reflect the current set. A deadline already reached
skips the wait; a changed rule set or project time zone invalidates the cached
deadline. See
[Cron](../architecture/daemon-lifecycle.md#cron).

The Daemon report uses that same snapshot and keeps report-before-ack order.
Cron wakes still apply when the Daemon feature is disabled. A published,
non-empty report is repeated every 60 seconds only while Daemon is enabled; that
repeat limits the idle wait only in that case. An empty set withdraws a former
report once. It has no expiry or periodic empty-report repair, so do not promise
a new snapshot protocol or guaranteed repair of a lost withdrawal. See
[The Schedule](../architecture/daemon-section.md#the-schedule).

## Why this matters

Worker processes a single message at a time. If `onTick()` runs long:
- Other signals queue up and are delayed
- WebSocket responses become sluggish
- The whole worker becomes unresponsive

## What belongs in onTick

A tick that reads configuration complains about a value it cannot use on the
change of outcome, not on every check; see the rules wider than logs in
[../architecture/logs.md](../architecture/logs.md).

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
    $this->chatClient->tick(microtime(true) * 1000);
}
```

## What does NOT belong in onTick

```php
public function onTick(): void {
    // ❌ Synchronous HTTP request
    $result = file_get_contents('https://api.example.com/...');

    // ❌ Heavy DB query
    $rows = Hilos::$db->events->findAll(); // might be slow

    // ❌ Asking the database on every tick whether there is work - even a light query
    $next = Hilos::$db->dataExports->nextPreparing(); // ten to a hundred queries a second, idle

    // ❌ sleep / usleep
    sleep(1);

    // ❌ Long loop over large dataset
    foreach ($this->millionItems as $item) { ... }
}
```

Work reaches an agent through an event — its own action, a signal — so raise a flag or fill an
in-memory queue where it arrives, and read the database in `onStart()` only for what a restart left.

## Handling long operations

**Option 1: Async LLM client** — use `AsyncChatLLMInterface`, call `tick()` in `onTick()`, consume the result when `hasResult()` is true.

**Option 2: Monopolistic agent** — run the heavy work in a dedicated monopolistic worker that has no time pressure from WS signals.

**Option 3: Chunked processing** — process one item per tick, keep a queue.
What the screen sees between chunks and what happens after a failure → [work-in-portions.md](../signals/work-in-portions.md).

**Option 4: Cron** — schedule infrequent heavy work via daemon cron, send signal to agent.
