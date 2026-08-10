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

The operator does not matter — a ternary branch and a `match` `default` arm mint
the same label, and are the same violation:

```php
$reason = isset($payload['reason']) ? $payload['reason'] : '';   // wrong, the same way
$group = match ($signal) { 'chat_message' => 'chat', default => '' };   // wrong, the same way
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

Every DTO shares one spelling of the required-field cure: `BaseDTO::requireString()`
and its siblings refuse a key that is absent or holds the wrong type, and
`PageSignalRouter::dispatchAction()` builds an action DTO inside the try that turns
an action failure into the client's fail-ack — so a broken payload is answered like
any other failed action. An empty string passes the check on purpose: a field the
user left blank is real input, and the handler answers it with its own validation
message. See [signals/dto-convention.md](../signals/dto-convention.md).

### Test code is judged by the same rule

A suite is allowed things production is not, but this is not one of them. The rule
reads the **minting** of the value and never the data a test writes down, so a
fixture array that carries an empty string on purpose — a deliberately incomplete
payload, a binary vector whose tail is empty — is invisible to it and always was.
What it does see in a suite is a test double of a production hydrator, and that
double has to repeat the cure of its original: a `getRowKey()` that falls back to
`''` collapses two rows of a window into one in the double exactly as it does in
the real table. Left alone, such a double preserves in the suite the very defect
the production code was cleaned of, and offers it back for copying.

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

## Reading a payload does not mint a stub

Checked automatically: `PAYLOAD-SENTINEL`
(see [automated-checks.md](automated-checks.md)).

The rule above is about a value the code mints anywhere. This one is about the
one place where minting is most tempting and most expensive: `fromArray()` and
`fromJson()`, where a frame somebody else sent is turned into an object.

**A payload field has two roles and no third one.**

- **Required** — the signal has no meaning without it. An absent key, or a key
  holding another type, means the frame is broken, and the reader refuses it
  with `InvalidFormatException`.
- **Legitimately absent** — the sender is allowed to leave it out, which is what
  an omitted optional field or an empty payload section means. The property is
  nullable and the reader answers `null`.

```php
// wrong: three fields the signal is defined by, and a frame missing any of them
// still builds an object that reads as data
return new static(
    page: (string)($data['page'] ?? ''),
    httpCode: (int)($data['httpCode'] ?? 500),
    requestId: (string)($data['requestId'] ?? ''),
);

// right: two required, one legitimately absent
return new static(
    page: self::requireString($data, 'page'),
    httpCode: self::requireInt($data, 'httpCode'),
    requestId: self::optionalString($data, 'requestId'),
);
```

`BaseDTO` owns both halves, so a DTO neither writes the check nor picks the
exception: `requireString()`, `requireInt()` and `requireArray()` refuse an
absent or mistyped key; `optionalString()`, `optionalInt()` and `optionalArray()`
answer `null` to an absent key and refuse a present one of the wrong type. The
set grows on demand — add the reader the payload needs, not the six it might.
The type is checked and never cast: `(int)` turns `null`, `false` and `'abc'`
into a `0` indistinguishable from a sent one.

`''`, `0` and `0.0` are the three values that quietly pretend to be a third role,
and the machine reads the three spellings that mint them — `??`, a ternary
branch, and a `match` `default` arm. `?? null` and `?? []` are neither: the first
is how a legitimately absent field arrives, and the second is what an omitted
section of a payload means.

What the check does **not** judge is agreement between fields — that one field is
required only because another one is set, or that two of them cannot both be
filled. That stays in the constructor, where the whole object is visible;
`MailSendSignalData` is the sample. `fromArray()` answers presence and type, and
nothing else.

The `// external-boundary: <reason>` marker legalizes one occurrence here as
well, and means the same thing: the value really did arrive from outside as that
literal.

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
