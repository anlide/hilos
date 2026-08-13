# PHPDoc

Read this when writing or changing PHPDoc in project PHP code.

## Rules

1. Do not use `{@inheritDoc}`, `@inheritDoc`, or `@inheritdoc` in project code.
   Overrides must have their own PHPDoc that describes the local behavior.
   Vendor code is outside this rule.
2. Every public and protected method must have a PHPDoc block when the method is
   created or changed. Private methods need PHPDoc when they expose a local
   contract that callers inside the class should rely on.
3. Mandatory tags on every applicable method docblock:
   - `@param` for each parameter when the method has parameters
   - `@return` for each non-void method; omit for `void` methods and for ordinary
     constructors
   - `@throws` for each exception the method throws directly or propagates without
     catching or converting
   Every `@param`, `@return`, and `@throws` line must include a very short comment
   after the type. Repeating the native return type in `@return` is intentional
   and required for consistency.
4. The free-text summary is optional. Omit it when it would only repeat the method
   name, parameter names, native types, return type, or the short tag comments.
   Keep a compact summary only when it explains a non-obvious local contract, side
   effect, routing decision, validation rule, deliberate ignore, or
   caller-visible behavior that tags do not already express. As a rule of thumb,
   keep the summary within 1-3 lines. Do not restate the method body step by step.
5. Separate the free-text summary from `@param`, `@return`, `@throws`, and other
   tags with one blank PHPDoc line. When there is no summary, start the tag block
   immediately after the opening `/**`.
6. Keep tag comments compact: a few words for `@param` and `@return`, and a short
   caller-facing reason for `@throws`. Do not leave bare `@param Type $name`,
   `@return Type`, or `@throws Exception` lines without a comment.
7. For overridden public/protected methods, restate the meaningful local
   contract in the summary and/or tags: what the method sends, mutates, routes,
   validates, or deliberately ignores. Do not leave the reader to jump to a
   parent class for page-specific behavior.
8. Avoid `{@see ...}` in normal prose. Use it only when the docblock needs to
   point to a contract symbol that is not already visible in the method
   signature or method body, or when the target lives outside the local code path
   being documented. Do not wrap constants, DTOs, methods, or properties in
   `{@see ...}` just because they are mentioned by the code below.
9. In PHPDoc `{@see ...}` references, import the class with `use` and reference
   the short class name or alias:
   `{@see UserActions::rename}`, not
   `{@see \Demo\Chat\Database\Actions\Item\UserActions::rename}`. This holds even
   when the class is referenced only in the docblock and nowhere in code — add
   the `use` anyway rather than writing a leading-backslash fully qualified name.
   A name partially qualified against the current namespace is the same mistake
   wearing less punctuation: write `use Hilos\Backup\Agent\BackupAgent;` plus
   `{@see BackupAgent}`, not `{@see Agent\BackupAgent}`, which stops pointing at
   anything the day either class moves. And a bare short name with no import
   behind it points nowhere at all — an IDE cannot follow it, a reader cannot
   tell which of two same-named classes is meant, and nothing fails to say so.
   A constant, an enum case of this file, or a class of this very namespace
   needs no import: those the reader already has in front of them.
   Checked automatically: `PHPDOC-FQN`, see [automated-checks.md](automated-checks.md).
10. If two imported names conflict, alias the import and use the alias in
    PHPDoc, for example `use Foo\Bar\User as RuntimeUser;`.
11. Prefer `self::`, `static::`, or a short imported class name for links inside
    the current namespace. Do not use leading-backslash fully qualified names in
    docblocks unless there is no importable symbol.
12. PHPDoc type references must use imported class names too. For
    `@property-read`, `@method`, `@param`, `@return`, `@var`, `@throws`,
    `@extends`, and `@implements`, add a `use` import and reference the short
    class name or alias instead of writing a leading-backslash fully qualified
    class name in the docblock. A generic argument counts: write
    `@extends RtActions<ViewBackupHistory, BackupHistories, StateBackupHistories>`,
    not the same line with a fully qualified third argument. `@extends` sits next
    to the `@property-read` that repeats its state type, and the two spelling the
    same class two different ways is exactly what this rule exists to prevent.
    A type is spelled the way rule 9 spells a `{@see}`: neither a leading
    backslash nor a namespace fragment, and the short name has an import behind
    it. For a class named in executable code the same canon lives in
    [qualified-names.md](qualified-names.md).
    Checked automatically: `PHPDOC-FQN`, see [automated-checks.md](automated-checks.md).
13. On a View item the class-level `@method __construct(...)` documents an
    *inherited* constructor. Keep it on a DB View item, which inherits the base
    constructor, so the construction contract stays visible. Do **not** put it on
    an RT View item that overrides `__construct` for real — the real override
    already carries its own docblock, and an `@method` next to a real method of
    the same name triggers a "Method already defined" warning.

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
- A long-running loop entrypoint (a `run()` loop, a tick dispatcher) should
  **contain** failures of the per-iteration hooks it calls — wrap the call, log
  via the agent error logger, and continue — so the loop truthfully declares only
  its own narrow exceptions instead of a broad `@throws Throwable` propagated from
  a hook. A narrow `@throws` sitting next to an uncaught broad-`Throwable` callee
  is a contradictory contract; fix it by containing the hook failure, not by
  widening the loop's declared contract.
- Prefer the narrowest useful exception when the caller can act on it
  (`EmptyValueException`, `ValueTooLongException`, `PageResourceNotFoundException`).
- Use the relevant base exception when the caller only needs the category
  (`ValidationException`, `HilosException`).
- Add a short reason to each `@throws` entry:
  `@throws ValidationException When rename payload violates user validation rules`.
- Keep `@throws` and `{@see ...}` imports consistent: import the class with
  `use` and reference the short class name in the docblock.

Checked automatically: `THROWS-PROPAGATION`, see
[automated-checks.md](automated-checks.md). The check is narrower than this
section by declaration — it judges the call forms whose target is written down,
inside the zone it has reached so far — so a green run answers for that zone and
those forms, and the audit above answers for everything else.

Before finishing, review the full direct-callee audit and every added or
changed `@throws`. Verify where each exception originates, whether the callee
documents it, whether the caller can act on it, and whether any kept summary
still describes the local behavior.

### Why this rule is the one that rots

`@throws` is missed more systematically than any other rule in this guide, and
the four reasons are worth knowing, because they say where to spend the effort:

1. **Nothing pushes back while you write.** PHP has no checked exceptions, the
   IDE stays quiet, and the tests stay green. Breaking this rule produces no
   feedback at all, at any point.
2. **It is the only style rule you cannot satisfy from one screen.** Declaring
   `@throws` means reading the PHPDoc of every callee, and of *their* callees.
   The cost of compliance grows with stack depth; the cost of violation is zero.
3. **The contract rots at a distance.** It breaks not when this method is
   edited, but when a `throw` appears two floors below it. Nobody walks the
   callers of the callers at that moment.
4. **A wrong tag is worse than a missing one.** A gap reads as "the author did
   not say"; a wrong tag reads as a reliable answer, so the caller catches the
   wrong thing — or, worse, catches nothing. `ServerInterface::start()`
   declaring no throws over an `AbstractServer::start()` that throws
   `SocketException` was exactly this, and everyone reading through the
   interface was sure the call was safe.

Consequence: the callee audit on every edit is written above as a step and not as
advice, because for the whole life of this guide it was **the only defense**. It
is no longer alone — `THROWS-PROPAGATION` now checks the propagation by machine —
but it is still the defense everywhere the check does not reach, and the check
says out loud where that is. The reason it was expected to be impossible turned
out to be wrong in an instructive way: the job needs an index of the tree, not
type inference, and the receiver forms an index cannot resolve are declined by
declaration rather than guessed at. See
[automated-checks.md](automated-checks.md) for what it judges and what it leaves
to the audit.

## Example

```php
use Hilos\Core\Exception\ValidationException;

/**
 * @param string $acceptKey WebSocket accept key for the client
 * @param string $action Action name from the WebSocket envelope
 * @param ActionPayloadDTO $dto Parsed action payload
 */
public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
{
}

/**
 * @return FsContext Owning FS context
 */
public function getContext(): FsContext
{
}

/**
 * Delete the file (no-op when already absent).
 *
 * @throws FileDeleteException If the file exists but cannot be removed
 */
public function unlink(): void
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
