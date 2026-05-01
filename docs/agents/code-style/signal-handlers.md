# Signal Handlers

Read this when editing an agent or page method that receives named signals,
especially `onSignalAgent()` or `onSignalCron()`.

## Rules

1. Named signal handlers route by signal name. Always use `switch ($name)` with
   explicit `case SomeConstants::SIGNAL_NAME:` branches, even when the handler
   currently supports only one name.
2. Each `case` validates the expected payload type when the signal contract has
   a typed DTO. Prefer an inverted guard that throws the matching framework
   contract exception before delegating to behavior.
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
public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
{
    $payload = $data->data;

    switch ($name) {
        case ChatSignalConstants::MODERATION_RESULT:
            if (!$payload instanceof ModerationResultSignalData) {
                throw new InvalidAgentSignalPayloadException(
                    $name,
                    ModerationResultSignalData::class,
                    $payload,
                );
            }

            $this->handleModerationResult($payload);

            return;

        default:
            throw new AgentUnknownSignalException($name);
    }
}
```
