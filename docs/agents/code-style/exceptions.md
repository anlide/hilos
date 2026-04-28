# Exceptions

Read this when choosing exception classes, changing `@throws`, or reviewing
error handling in Hilos PHP code.

## Exception taxonomy

| Exception family | Use for... |
|---|---|
| `ValidationException` and children | User input, DTO payloads, domain validation, missing entity for update/delete |
| `TableActionException` | Table/page action validation that should be returned as table action error |
| `DatabaseException` | SQL, DB connection, query execution, schema/migration/DB infrastructure failures |
| `RtBaseException` | Runtime state (`Hilos::$rt`) collections, RT actions, RT sync, RT truth-source failures |
| `PageSubscriptionException` and children | Page subscription failures that should become subscription page errors |
| `AgentException` and children | Agent lifecycle, agent lookup, daemon/worker agent registration failures |
| `LogicException` | Framework or application invariant violations, not user-correctable input |
| `HilosException` | Broad public contract when the caller only needs "Hilos failure" |

## Validation rules

Use `ValidationException` or a child for business-level validation, even when
the validation lives inside DB-backed actions.

Common choices:

- `EmptyValueException`: required value is empty after normalization.
- `ValueTooShortException`: value is shorter than the domain minimum.
- `ValueTooLongException`: value exceeds the domain maximum.
- `InvalidFormatException`: value shape or syntax is invalid.
- `DuplicateValueException`: unique domain value already exists.
- `ItemNotFoundForUpdateException`: item cannot be updated because it is missing
  or not persisted.
- `ItemNotFoundForDeleteException`: item cannot be deleted because it is missing
  or not persisted.

Do not use `RtBaseException` for DB action validation. `RtBaseException` is for
`Hilos::$rt` runtime state failures, not durable `Hilos::$db` business data.

Do not use `DatabaseException` for business validation. `DatabaseException`
means DB infrastructure or SQL execution failed.

## Page and table actions

For `Page::onAction()` handlers, throw validation exceptions from private
handlers and let the framework action dispatcher call
`AbstractPage::onActionException()`. Override `onActionException()` only when
the page has a specific user-facing fail/error contract; otherwise the default
`action_error` signal is sent to the initiator.

If `onAction()` receives a DTO that does not match the routed action name,
throw `InvalidActionPayloadException`. Do not log and return: a mismatched DTO
is a broken internal routing/parser contract, and the page action dispatcher is
responsible for converting the exception into the page's fail/error contract.

Use `TableActionException` for table-specific action validation where the
frontend expects `TABLE_ACTION_ERROR`.

Use concrete validation exceptions for non-table action flows when there is a
clear existing class. Example: profile rename should throw `EmptyValueException`
for an empty name.

## Agent signals

For `onSignalAgent()` in agents or pages, route by signal name with a `switch`.
Known signal names must validate the wrapped inner payload type:

- Throw `InvalidAgentSignalPayloadException` when the payload object does not
  match the signal contract.
- Throw `AgentUnknownSignalException` in the `default` branch.
- Do not call `logAgentError()` for these contract failures. `WorkerManager`
  catches `AgentException` around agent/page signal dispatch and logs it once.

## PHPDoc

Document the caller-facing contract:

```php
/**
 * Renames a user and broadcasts the update.
 *
 * @throws ValidationException When rename payload violates user validation rules
 * @throws HilosException On database error or broadcast failure
 */
private function handleRename(...): void
{
}
```

When the caller can act on specific failures, document the concrete children:

```php
/**
 * @throws EmptyValueException When name is empty
 * @throws ValueTooLongException When name exceeds maximum length
 */
public function rename(string $newName): void
{
}
```

Use a short reason for every `@throws` entry.

## Review checklist

- Does the exception family match the subsystem (`db`, `rt`, page, table, agent)?
- Is user/business validation under `ValidationException`?
- Are table action validation failures under `TableActionException`?
- Are DB infrastructure failures under `DatabaseException`?
- Does PHPDoc describe the caller-facing category instead of every incidental
  internal throw?
