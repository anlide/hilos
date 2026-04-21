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
3. Use a short summary line, then a blank line, then details only when they add
   information. Keep `@param` entries specific to the local meaning of the
   argument. Add `@throws` for exceptions the caller or caller-facing error path
   should know about.
4. Avoid empty PHPDoc. If a private method is obvious from its name and types,
   no docblock is better than a boilerplate docblock.
5. In PHPDoc `{@see ...}` references, import the class with `use` and reference
   the short class name or alias:
   `{@see UserActions::rename}`, not
   `{@see \Demo\Chat\Database\Actions\Item\UserActions::rename}`.
6. If two imported names conflict, alias the import and use the alias in
   PHPDoc, for example `use Foo\Bar\User as RuntimeUser;`.
7. Prefer `self::`, `static::`, or a short imported class name for links inside
   the current namespace. Do not use leading-backslash fully qualified names in
   docblocks unless there is no importable symbol.

## Example

```php
use Demo\Chat\Database\Actions\Item\UserActions;

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
 * Renames a user through {@see UserActions::rename} and sends page acks.
 */
private function handleRename(...): void
{
}
```
