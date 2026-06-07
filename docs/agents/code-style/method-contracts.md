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
