# Signal Handlers

Read this when editing an agent or page method that receives named signals,
especially `onSignalAgent()` or `onSignalCron()`.

## Rules

1. Named signal handlers route by signal name. Always use `switch ($name)` with
   explicit `case SomeConstants::SIGNAL_NAME:` branches, even when the handler
   currently supports only one name.
2. Each known `case` that consumes a concrete DTO validates its type before
   reading its fields or passing it onward. For `AgentSignalData`, follow
   [Inner payload narrowing](#inner-payload-narrowing), including when topology
   already declares the DTO.
3. Do not route by a single top-level `if ($name === ...)` or by
   `match (true)`. Those shapes hide that the method is a signal router.
4. The switch is routing; private handlers are behavior. Keep business logic out
   of the routing branch when it is large enough to deserve a named method.
5. For exhaustive agent-to-agent or page-routed handlers, the `default` branch
   throws `AgentUnknownSignalException`.
6. If the framework deliberately broadcasts a shared signal type to the agent
   and this handler owns just a subset of names, omit the `default` branch when
   it would only `return` or `break`. Document that ignore contract in the
   method PHPDoc.
7. Do not add empty `default` branches. A `default` branch must perform real
   fallback behavior, delegate to a parent handler, or throw the correct
   unknown-name exception.
8. Do not wrap signal routing in a local `try/catch`. Let the worker/page signal
   dispatcher own contract-error logging and failure handling.

## Inner payload narrowing

Inside each known `onSignalAgent()` case that consumes `$data->data` as a
concrete DTO, narrow that inner payload with `instanceof` before reading its
fields or passing it to a typed method. This applies in agents and pages even
when `Agent::AGENT_SIGNALS` or `Page::SIGNALS` already declares the DTO.

Prefer a local variable and an inverted guard. On a mismatch, throw
`InvalidAgentSignalPayloadException($name, ExpectedSignalData::class, $payload)`
with the signal name, expected class and actual inner object. Do not log and
return or silently skip the case. After the guard, use the narrowed object.
Keep grouped cases when they consume the same DTO type; one guard in their
shared body is enough.

The `instanceof` condition gives PhpStorm the concrete type and makes a direct
call that bypasses the dispatcher fail with the precise contract exception,
before a `TypeError` or a read of another DTO's fields. An `@var` annotation or
an IDE suppression does not replace this check.

Dispatch-time parsing still builds the declared DTO on the normal delivery
path. Do not reparse the payload in the handler; the guard checks the object
already received, and a matching DTO continues through the existing behavior.
If a signal case consumes no typed inner content, do not invent a DTO or a
guard for it.

Checked automatically: `AGENT-SIGNAL-PAYLOAD`, for a limited lexical form in
production roots. In `onSignalAgent()` with a parameter spelled
`AgentSignalData $anyName`, the check reads brace-form switches directly in the
method body. It reports a whole `$anyName->data` or a simple local alias passed
as a positional call argument without an earlier `instanceof` of that same
expression in the case. An alias is one assignment from the wrapper, inside
that case; a wrapper check before the assignment also covers the alias.
Consecutive case labels share one body. Comments, strings, other methods,
nested functions and other cases cannot supply the check.

This is narrower than the rule above: it does not verify the expected DTO,
the exception, the guard's branch or control flow. It does not judge field
reads, named or compound arguments, alias chains, aliases established outside
the case, nested switches, alternative switch syntax, or an imported alias
for the `AgentSignalData` type. It does not track mutation of the wrapper or
infer callee types. A green report is not proof of safe handling; the direct
runtime refusal test and review still answer for those parts.

## Shape

```php
public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
{
    switch ($name) {
        case ChatCronConstants::CLEANUP_HISTORY:
            $this->handleHistoryCleanup();

            return;
    }
}
```

```php
public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
{
    switch ($name) {
        case ChatSignalConstants::ATTACHMENTS_PUBLISHED:
            $published = $data->data;
            if (!$published instanceof FilesPublishedSignalData) {
                throw new InvalidAgentSignalPayloadException(
                    $name,
                    FilesPublishedSignalData::class,
                    $published,
                );
            }

            $this->handleAttachmentsPublished($published);

            return;

        default:
            throw new AgentUnknownSignalException($name);
    }
}
```
