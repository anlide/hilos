# Method Contracts

Read this before changing PHP method names, return types, or error contracts.

## Rule

Do not use `bool` as a success flag for methods that perform work.

Methods that start, create, update, delete, send, consume, reset, register,
unregister, parse, persist, or otherwise mutate state should either:

- return the domain value they produce; or
- return `void` when successful and throw an exception when they cannot complete.

Use `bool` only for predicates that answer a question without hidden mutation,
such as `hasResult()`, `isBusy()`, `shouldReact()`, or `supportsTls()`.

## Naming

A `get*()` method must not change future calls to itself or otherwise consume
state. If retrieving data clears it, name the method for the mutation:

```php
$response = $client->consumeResult();
```

Avoid names such as:

```php
$response = $client->getResult(); // wrong if this clears the result
```

## Errors

Do not encode failure as `false`, `null`, or an empty string when the method
contract is expected to perform work. Throw the narrowest exception family for
the subsystem and document that exception at the boundary where callers are
expected to handle it.

Allowed nullable returns must represent domain absence, not operational failure.
For example, `findById(): ?Item` may return `null` when the item is not present.
By contrast, `startRequest(): bool` or `consumeResult(): ?Result` should be
`void`/`Result` with exceptions for busy clients, transport failures, malformed
responses, or missing required configuration.

## The empty string is not a value meaning "no value"

Checked automatically: `EMPTY-STRING-SENTINEL`
(see [automated-checks.md](automated-checks.md)).

A violation is an empty string the code **mints itself** in place of a value that
is absent, on an internal boundary — between framework components, in a DTO, on
the worker-daemon pipe:

```php
$agentType = $pageRoutes[$page] ?? '';   // wrong: absence becomes a value
if ($agentType === '') {
    continue;
}
```

The cure is the type, not a constant and not `empty()`:

- **Optional field** — the one whose emptiness means "take the default": make it
  `?string` and let it be `null`.
- **Required field** — the one whose emptiness means "the payload is broken":
  make it a field that cannot be left unfilled, and reject a payload without it
  with an exception.

```php
$agentType = $pageRoutes[$page] ?? null;
if ($agentType === null) {
    continue;
}
```

`empty()` is not a shorter spelling of either check: it also treats `'0'`, `0`
and `[]` as absent, so it answers a different question than the one asked.

### What is not a violation

Checking an incoming string for emptiness is the opposite of minting one, and
stays right where it is. Input is anything the process did not author itself:
project topology constants (`PAGES`, `AGENTS`, `ACTIONS`, `AGENT_SIGNALS`) and
their validators, env, argv, `/proc`, the output of an external process, strings
read from the database, HTTP and SMTP responses, and user input. Normalizing such
an input to `null` at the boundary is how it stops being an input problem:
`ActionRouteConfig::getPageForAction()` and `SignalRouteConfig::getPageForSignal()`
both answer `?string` and never hand an empty page name downstream.

Samples of the convention already in the tree: `Cluster/Peer/DTO/**` (an empty
required field raises `PeerTransportException`, an optional one is normalized
`'' -> null`) and `Socket/Client/WebSocketClient::onFrame()` (an empty frame field
raises `InvalidFrameException`).

### Naming a legal empty string in place: `// external-boundary:`

A legal reading of outside input sometimes sits inside a directory the rule scans.
Mark that one occurrence with a reason on the line above it:

```php
// external-boundary: created_at is NOT NULL, so the driver always hands its stored value over
createdAt: (string) ($row['created_at'] ?? ''),
```

The marker covers **one occurrence**, not the method and not the file, and the
reason after the colon is mandatory — a marker without one is itself a violation.
Both rules exist for the same purpose: the reason is the classification (why this
value comes from outside), and a marker that covered a whole method would stop
being a classification and become a mute button.

This is the same device as `// warning-suppressed:` in
[error-suppression](automated-checks.md), deliberately: one marker convention in
the repository, so a reader who knows either one reads the other without the docs.
Its cost is the same, too — it is also the way to get past the rule, so a marker
whose reason does not name an outside source is a review finding.

## Polling APIs

For async polling APIs, keep state checks separate from state consumption:

```php
$client->startRequest($nowMs);
$client->tick($nowMs);

if ($client->hasResult()) {
    $response = $client->consumeResult();
}
```

`hasResult()` is a predicate. `consumeResult()` is a command/query hybrid whose
name explicitly advertises that it mutates the result buffer.

## Preconditions vs domain absence

Distinguish two kinds of "no value":

- Domain absence — a lookup that may legitimately miss, such as
  `findById(): ?Item` or `getAgent(): ?Agent`. Keep the nullable return.
- Precondition / lifecycle state — a dependency that is wired up during a setup
  phase and must be present before use, such as a worker client attached after
  the agent daemon is linked. Do not expose this as a nullable getter. Provide a
  predicate plus a throwing accessor:

```php
if ($daemon->hasWorkerClient()) {
    $client = $daemon->getWorkerClient(); // never null, never throws here
}
```

`getWorkerClient(): WorkerClient` throws the subsystem exception
(`AgentNotLinkedToWorkerException`) when called out of order. The backing field
may stay nullable for two-phase initialization; the public contract must not.
