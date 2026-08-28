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
  use commands need no env changes). The default host is `127.0.0.1`: out of the
  box the channel is bound to the loopback, and a stack that needs it over the
  network names the address itself, the way every demo does.

**This page is the transport.** Which process a command's work happens in is a separate
question with its own rule — the daemon does the work, the CLI initiates it — and its own
page: [../cli/command-execution.md](../cli/command-execution.md). The two are independent
on purpose: `daemon:status` reaches the daemon over the HTTP status endpoint and is no less
daemon-executed for it, and the presence probe in front of a CLI-side write opens a socket
here without ever speaking the protocol above.

## Who may call a command — nobody is asked

The framework does not check who calls a command, and it will not. Not because it
delegates the check to the project — there is nothing to check. A shared secret or an
address list duplicate a closed port; mutual TLS is not code but network configuration;
and per-operator rights rest on an identity a CLI caller does not have — no session, no
user, only an open socket. Two things do the work instead, and both are in place: the
environment gate refuses destructive `test:` commands on a production-like node, and
binding the port closes access. The second is not advice but a condition of a correct
installation: `admin:grant`, `admin:revoke` and `admin:create` ride this channel and the
environment gate does not stop them, so the command port is never exposed where a
stranger can reach it.

**A shared secret differs from a closed port in exactly one scenario** — the one where
the port is reachable by strangers over the network. There it does not cure anything, it
substitutes for the cure. A single key per installation sits in the env of every
container and in every compose file, it hands out the feeling of protection, and it takes
away the operator's reason to close the port — while the closed port protects strictly
more.

**The environment gate judges the environment, not the caller.**
`NonProductionGate::admitted()` (`framework/backend/Environment/NonProductionGate.php`) is
fail-closed, and on this socket `CommandClient`
(`framework/backend/Socket/Client/CommandClient.php`) asks it above every branch of the
parse, so a stray caller meets it as surely as the CLI process does. What it reaches,
though, is only the commands that declared themselves test-only:
`framework/backend/Core/CLI/Commands/AbstractSetAdminCommand.php` and
`framework/backend/Core/CLI/Commands/AdminCreateCommand.php` are both declared
`implements CommandInterface, DatabaseFreeCommand`, neither extends `TestOnlyCommand`, and
so `admin:grant`, `admin:revoke` and `admin:create` pass the gate on any node at all. The
bound port is what stands between them and a stranger.

**The project owns the perimeter, not an authorization layer.** Whoever needs to know the
caller puts that in front of the door: a port that is not published, a network policy, a
separate entrance of their own for operators. The framework declares no extension point
inside the channel.

**The second door asks nobody either.** `daemon:status` and `daemon:monitor` do not travel
this channel at all — they read the HTTP status endpoint, an `AsyncHttpClient` on
`HILOS_DAEMON_HOST:HTTP_STATUS_PORT` opened by `StatusCommand::fetchDaemonStatus()`
(`framework/backend/Core/CLI/Commands/StatusCommand.php`) and by `CliMonitorManager`
(`framework/backend/Core/Daemon/CliMonitorManager.php`), against `ApiEndpoint::STATUS`
(`/status`, declared in `framework/backend/Constants/ApiEndpoint.php`). That door is
recognized as redundant and closes in HIL-749, which is why it is named here with an end
to it.

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

### `admin:create` — the first administrator (HIL-609)

The grant needs a user row that exists, and a fresh installation has none: the
admin pages are shut, so there is nothing to register through and no id to name.
`admin:create <sessionToken>` addresses a **session** instead — the value of the
`hilos_session_token` cookie, read in DevTools — and makes it an administrator,
minting the user row when the session carries none. Its two halves are
`AdminCreateCommand` on the CLI and
`AbstractSessionsLibraryAgent::handleAdminCreateCommand()` on the agent, with
`ensureAdminUser()` as the project seam that writes the row.

It routes to the **sessions library** (`hilos_sessions_library`), not to
`AbstractHilosIndexAgent` where the grant lands: the operation ends in a session
bind, and the session row is the library's. That is also why no reconnect is
needed — the bind ends in a `hilos_session_state` frame, and the project handler
on the far side of it re-points the session's live sockets and re-sends them the
handshake response, so the admin entry appears in the open tab.

**Every project that registers the library answers this command,** because the
mount stands on the abstract class (`AGENT_COMMANDS`) rather than being named per
project. One with nobody to mint — the chat demo, which has a login of its own —
answers the refusing default of `ensureAdminUser()`, and that refusal is the point:
an operator who typed the command at the wrong installation gets a no rather than a
command socket that never answers, which reads as a hang. Before HIL-710 the name
was carried by whichever agent chose to, so a project that did not carry it left
the socket silent.
