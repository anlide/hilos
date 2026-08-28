# Blocking Resolution

Read this before turning a host name into an address in backend PHP, and whenever
the `BLOCKING-RESOLUTION` guard fails on a call you added.

## Core Rule

These six PHP builtins are not called anywhere in Hilos:

| Function | What it waits for |
|---|---|
| `gethostbyname()` | an A record |
| `gethostbynamel()` | every A record of a name |
| `gethostbyaddr()` | a PTR record |
| `dns_get_record()` | any record type |
| `dns_get_mx()` | the MX records of a domain |
| `checkdnsrr()` | the existence of a record |

They all do the same thing: send a question to a nameserver and **block the whole
process** until it answers or gives up. There is no timeout argument on any of
them, and the resolver's own timeout is measured in seconds.

## Why

Every Hilos process is a loop. The daemon accepts connections in one, a worker
serves its clients in one, an agent gets a tick in one, and the watchdog polls the
daemon in one. Blocking any of them for a second is not slowness — it is the node
being unreachable for that second: connections queue unaccepted, ticks are
skipped, heartbeats go out late enough to be read as a dead node.

The failure is also invisible at the call site. `gethostbyname($host)` reads like
looking a value up in a table, and on a developer's box with a warm cache it
behaves like one. It becomes a stall only where it matters: a container whose
nameserver went away, a network split, a DNS server under load. That is why this
is a rule rather than a review habit — nothing in the code says the call is slow.

The cure is not a faster resolver. A name is resolved **outside the loop**: at
startup, into configuration, or by the layer that already does I/O
asynchronously. In practice the address is usually a setting to begin with, and
the resolution belongs to whoever wrote it there.

## What this is not about

`gethostname()` is **not** in the family and is legal everywhere. It reads the
local host name through `uname(2)`: a system call into the kernel, with no socket,
no nameserver, and nothing to wait for. The names look alike, so the difference
is stated here rather than left to be rediscovered.

Neither is anything that resolves a name as a side effect of connecting —
`fsockopen()`, `stream_socket_client()`, cURL. Those belong to the event-loop
rules ([event-loop.md](../architecture/event-loop.md)), which govern them by
whether the socket is non-blocking, not by the name behind it. Non-blocking there
means the *connect*: the name in front of it is still resolved synchronously, on
the resolver's own clock, and no timeout in Hilos covers that. Where it matters
the address is a setting to begin with — `WATCHDOG_ALERT_SMTP_HOST` is the
written-up case, see [daemon-lifecycle.md](../architecture/daemon-lifecycle.md).

## Adding an exception

The rule carries a list of files allowed to make such a call, and it is **empty**
today: there is not one of these calls in the tree. That is the state to keep.

If a call genuinely has to happen, add the file's path to `ALLOWED_PATHS` in
`framework/tests/CodeStyle/Rule/BlockingResolutionRule.php` and put the reason in
that file's own docblock. The reason has to answer one question: why the loop this
call sits in can afford to stop. "Only at startup" is such an answer; "it is
usually fast" is not.

## Scope

Every scanned root, tests included. A suite that resolves a name is wrong the same
way and hangs the run instead of the node, which is why this id is absent from the
production-only list in `RootKind`.

Checked automatically: `BLOCKING-RESOLUTION`, see
[automated-checks.md](automated-checks.md).
