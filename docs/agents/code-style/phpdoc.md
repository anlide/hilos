# PHPDoc

Read this when writing or changing PHPDoc in project PHP code.

## Rules

1. Do not use `{@inheritDoc}`, `@inheritDoc`, or `@inheritdoc` in project code.
   Overrides must have their own PHPDoc that describes the local behavior.
   Vendor code is outside this rule.
2. For overridden public/protected methods, restate the meaningful local
   contract: what the method sends, mutates, routes, validates, or deliberately
   ignores. Do not leave the reader to jump to a parent class for page-specific
   behavior.
   Include meaningful `@return` tags for contract methods even when the native
   return type is explicit, such as runtime collection key and item id methods.
3. Keep the free-text PHPDoc body compact. Prefer a one-line summary. Add
   extra description only when it explains a non-obvious local contract, side
   effect, routing decision, validation rule, deliberate ignore, or
   caller-visible error behavior. As a rule of thumb, keep the description body
   within 1-3 lines. Do not restate the method body step by step.
4. Separate the description body from `@param`, `@return`, `@throws`, and other
   tags with one blank PHPDoc line. Do not place tags directly after the summary
   or details text.
5. Keep `@param` entries specific to the local meaning of the argument. Add
   `@throws` for exceptions the caller or caller-facing error path should know
   about.
6. Avoid empty PHPDoc. If a private method is obvious from its name and types,
   no docblock is better than a boilerplate docblock.
7. Avoid `{@see ...}` in normal prose. Use it only when the docblock needs to
   point to a contract symbol that is not already visible in the method
   signature or method body, or when the target lives outside the local code path
   being documented. Do not wrap constants, DTOs, methods, or properties in
   `{@see ...}` just because they are mentioned by the code below.
8. In PHPDoc `{@see ...}` references, import the class with `use` and reference
   the short class name or alias:
   `{@see UserActions::rename}`, not
   `{@see \Demo\Chat\Database\Actions\Item\UserActions::rename}`.
9. If two imported names conflict, alias the import and use the alias in
   PHPDoc, for example `use Foo\Bar\User as RuntimeUser;`.
10. Prefer `self::`, `static::`, or a short imported class name for links inside
    the current namespace. Do not use leading-backslash fully qualified names in
    docblocks unless there is no importable symbol.
11. PHPDoc type references must use imported class names too. For
    `@property-read`, `@method`, `@param`, `@return`, `@var`, and `@throws`,
    add a `use` import and reference the short class name or alias instead of
    writing a leading-backslash fully qualified class name in the docblock.

## `@throws` and error contracts

Read [exceptions.md](exceptions.md) before changing thrown exception classes or
documenting non-obvious error contracts.

- Document exceptions that are meaningful to the caller or caller-facing error
  path. Do not list every incidental infrastructure exception if a broader local
  contract is clearer.
- Do not add `@throws` from broad assumptions such as "DB access can fail",
  "signal enqueue can fail", or "this calls framework code". Add `@throws` only
  when the method throws that exception directly, calls another method whose
  local PHPDoc or signature documents that exception, or deliberately exposes
  that exception as a caller-facing contract.
- Before finalizing PHPDoc for a method, audit every direct callee in the
  method body: direct `throw` expressions, normal method calls, `parent::`
  calls, return expressions, `match` arms, and magic property or array access
  that resolves to a local `__get()` or `offsetGet()` contract. If a direct
  callee documents `@throws` and this method does not catch or convert that
  exception, propagate it in this method's PHPDoc with an imported short class
  name and a short caller-facing reason. If the exception is caught and
  converted, document the converted exception instead.
- For inherited helper methods such as runtime actions `ensureCanWrite()`,
  `sync()`, `remove()`, collection `getStateCollection()`, or framework
  accessors, audit the parent implementation too. If the parent PHPDoc is
  missing a documented callee exception, fix the parent contract first, then
  propagate the exception from the child method that exposes it.
- For magic property or array access with a statically known property/key, use
  the exact resolved branch rather than the whole generic `__get()` or
  `offsetGet()` contract. Do not propagate a broad default-branch exception
  when the known branch only returns a scalar/object field. Do propagate
  exceptions from explicit calls made by that known branch, such as
  `$connections->forUser(...)`.
- For normal `$item->property` reads of documented `@property-read` magic
  properties, do not propagate exceptions from the underlying `__get()` branch
  into the caller method's PHPDoc. Document those exceptions on the `__get()`
  method that implements the bridge. Only propagate them from caller methods
  when the caller explicitly invokes a documented throwing method, or when the
  caller itself is the magic method implementing that branch.
- Apply the same rule to documented context and facade magic properties such as
  `Hilos::$fs->published`, `Hilos::$db->users`, or
  `Hilos::$rt->connections`. Treat the property read as the declared
  `@property-read` type, then audit only explicit method calls or array access
  performed on that value.
- For private helpers, prefer no `@throws` unless the helper has a meaningful
  local contract that callers inside the class need to see. Do not propagate
  incidental infrastructure risks through every private helper. Document broad
  infrastructure failures at the nearest public/protected boundary where they
  matter to the caller.
- Prefer the narrowest useful exception when the caller can act on it
  (`EmptyValueException`, `ValueTooLongException`, `PageResourceNotFoundException`).
- Use the relevant base exception when the caller only needs the category
  (`ValidationException`, `HilosException`).
- Add a short reason to each `@throws` entry:
  `@throws ValidationException When rename payload violates user validation rules`.
- Keep `@throws` and `{@see ...}` imports consistent: import the class with
  `use` and reference the short class name in the docblock.

Before finishing, review the full direct-callee audit and every added or
changed `@throws`. Verify where each exception originates, whether the callee
documents it, whether the caller can act on it, and whether the method summary
still describes the local behavior.

## Example

```php
use Hilos\Core\Exception\ValidationException;

/**
 * Routes user-detail actions to their page handlers.
 *
 * @param string $acceptKey WebSocket accept key for the client
 * @param string $action Action name from the WebSocket envelope
 * @param ActionPayloadDTO $dto Parsed action payload
 */
public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
{
}

/**
 * Renames a user and sends page acks.
 *
 * @throws ValidationException When the new name violates user validation rules
 */
private function handleRename(...): void
{
}
```
