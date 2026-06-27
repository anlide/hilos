# Command Server (CLI ↔ Daemon Control Plane)

Operator commands (e.g. granting a user admin) reach the running daemon over a
**dedicated socket control plane**, not over HTTP. A web protocol is deliberately
kept out of the control plane: the command channel is its own server alongside
the HTTP-status and WebSocket servers.

- `CommandServer` / `CommandClient` — newline-delimited JSON framing.
- `AsyncCommandClient` — the outgoing client a CLI command uses (PHP has no
  outgoing WS client, only server-side representations — hence a purpose-built
  socket).
- `COMMAND_HOST` / `COMMAND_PORT` env (catalog defaults, so projects that do not
  use commands need no env changes).

## Request / reply DTOs

```php
new CommandRequestDTO(correlationId: 'ab12…', command: 'setAdmin', payload: [...]);
CommandReplyDTO::ok($correlationId, $payload);
CommandReplyDTO::error($correlationId, 'No such user: 7');
```

Both implement `SignalDataInterface`; `SignalDTO` serializes `dataType =
get_class($data)` and restores via `::fromArray`, so no separate signal-data
classes are needed.

## Flow

1. **CLI** opens an `AsyncCommandClient`, sends a `CommandRequestDTO`, and polls
   `tick()` / `hasResult()` until `consumeResult()` (with a wall-clock timeout).
2. **Master** — a health command like `ping` is answered synchronously in the
   master. Any other command **parks**: the master holds the client connection
   (keyed by `correlationId`), queues a `COMMAND_REQUEST` signal, and stops
   parsing that socket until the reply.
3. **Routing** — `SignalRouter` resolves `COMMAND_REQUEST` to an `AgentDestination`
   via `Hilos::getCommandAgentRoutes()[command]`; the worker delivers it to the
   agent's `onSignalCommand()`.
4. **Agent** does the work and calls the `replyToCommand(CommandReplyDTO)` seam,
   which queues a `COMMAND_REPLY` (signal name = the `correlationId`).
5. **Master** routes `COMMAND_REPLY` to a `CommandReplyDestination{correlationId}`
   → delivers to the held client → the CLI's `hasResult()` turns true.

A parked command times out (the client returns no reply) if the agent never
answers; the held connection is forgotten on close.

## Wiring a command

Route the command to its owning agent in the project facade:

```php
// Project Hilos
public static function getCommandAgentRoutes(): array
{
    return [
        ChatCommandConstants::SET_ADMIN => AgentType::CHAT,
    ];
}
```

Handle it on that agent and always reply (ok or error):

```php
public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
{
    if ($data->command !== ChatCommandConstants::SET_ADMIN) {
        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

        return;
    }

    $user = Hilos::$db->users[(int) $data->payload[ChatCommandConstants::FIELD_USER_ID]] ?? null;
    if ($user === null) {
        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'No such user'));

        return;
    }

    $user->actions->setAdmin((bool) $data->payload[ChatCommandConstants::FIELD_ADMIN]);
    $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $data->payload));
}
```

Write the CLI caller by mirroring `PingCommand` / `AbstractSetAdminCommand`:
`AsyncCommandClient` → `CommandRequestDTO` → poll `tick()`/`hasResult()` →
`consumeResult()` → print the outcome.

## Worked example — `admin:grant` / `admin:revoke`

The grant itself is an **ordinary user action**, not a bespoke signal: the agent
calls `UserActions::setAdmin($bool)`, which persists and `sync()`s, and the
existing browser source fan-out pushes the changed user to everyone viewing the
users list. The command channel only carries the request and the outcome
(success / "no such user" / already-set). The two `AbstractSetAdminCommand`
subclasses (`admin:grant`, `admin:revoke`) are real operator commands — not
`TestOnlyCommand`.

This is what makes a `BrowserGuardType::ACCESS` gate usable against guest auth:
identity is a persistent httpOnly cookie, so **one browser is a stable user**.
Register as user X (cookie) → `admin:grant X` → reconnect the same browser → X is
admin → the gated pages open. See
[page-access-control.md](page-access-control.md) for the gate the grant unlocks,
and [../signals/routing.md](../signals/routing.md) for how `COMMAND_REQUEST` /
`COMMAND_REPLY` are routed.
