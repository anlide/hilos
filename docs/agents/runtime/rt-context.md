# Runtime: RtContext

`RtContext` is the runtime state layer — in-memory data shared across agents in a worker, synced across workers via RT sync signals.

## Access

```php
Hilos::$rt->connections    // RtStates collection of Connection items
Hilos::$rt->userStates     // RtStates collection of ChatUserState items
Hilos::$rt->chatContexts   // RtStates collection of ChatContext items
```

Collections are defined in the project's `RtContext` subclass (e.g. `RtChatContext`).

## RtContext vs DbContext

| | RtContext (`Hilos::$rt`) | DbContext (`Hilos::$db`) |
|---|---|---|
| Persistence | In-memory, lost on restart | Persistent in MySQL |
| Speed | Very fast (no I/O) | Requires DB query |
| Use for | Live connection state, transient UI state | Business data, history |

## RtContext subclass

Project defines collections in a `RtContext` subclass:

```php
class RtChatContext extends RtContext {
    public const string connections = 'connections';
    public const string userStates  = 'userStates';
    public const string chatContexts = 'chatContexts';

    public Connections $connections;
    public UserStates  $userStates;
    public ChatContexts $chatContexts;
}
```

## Sync mechanism

RT changes are broadcast to all workers via `RT_SYNC_*` signals:
- Write in one worker → signal queued → daemon broadcasts → all workers apply via `RtSyncApplicator`

Only the **truth source** agent should write to a collection.
Other agents receive `onSignalRtSync*()` to stay in sync.
