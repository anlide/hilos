# Static Factories

Read this when adding or changing a static factory method (`fromArray`,
`fromRow`, `fromPageRouteParams`, `create`, or a named constructor) or its
`self`/`static` return contract.

## Core Rule

A static factory whose declared return type is `static` (native `: static` or
`@return static`) must construct with `new static(...)`, never `new self(...)`.

`new self()` always builds the class that declares the method. When a subclass
inherits a `: static` factory, `new self()` returns the parent instance, which
violates the `static` return type and throws a `TypeError` at runtime. The bug
stays latent until a subclass relies on the inherited factory, so fix the
contract even when no subclass exists yet.

Keep the three signals consistent in one method:

- `: static` / `@return static` → `new static(...)`
- `: self` / `@return self` → `new self(...)`

If a class is not designed for inheritance, mark it `final`. With a `final`
class `self` and `static` resolve to the same type; prefer `new static()` for
consistency, or keep an intentional `self` value-object contract.

## Workflow

1. Decide the contract: does the factory return a polymorphic subtype
   (`static`) or a type fixed to this class (`self`)?
2. The framework bases `BaseDTO::fromArray()`, `AbstractTableRow`,
   `RtState::fromRow()`, and `AbstractPageSubscribeParamsDTO::fromPageRouteParams()`
   declare `: static`. Every override must build with `new static(...)`.
3. If the contract is `static` and the class has subclasses, confirm each
   subclass either overrides the factory or has a constructor compatible with
   the arguments the inherited factory passes. If `new static()` would break a
   subclass with a different constructor, stop and resolve that mismatch first.
4. If the class has no subclasses and is not meant to be extended, mark it
   `final` and keep the body, native return type, and `@return` aligned.

## Preferred Shape

```php
final class HilosUserPageSubscribeParams extends AbstractPageSubscribeParamsDTO
{
    public function __construct(public readonly int $userId) {}

    public static function fromPageRouteParams(PageRouteParams $params): static
    {
        return new static(
            userId: $params->requirePositiveInt(HilosPageRouteParams::HILOS_USER_USER_ID),
        );
    }
}
```

## Anti-Patterns

```php
// Wrong: : static contract, but builds the declaring class.
public static function fromArray(array $data): static
{
    return new self($data); // returns parent, not static, for subclasses
}
```

Replace `new self(...)` with `new static(...)`.

```php
// Wrong: @return static disagrees with : self and new self().
/** @return static Empty collection */
public static function empty(): self
{
    return new self();
}

// Right: align the docblock to the self contract.
/** @return self Empty collection */
public static function empty(): self
{
    return new self();
}
```

## Exceptions

- Intentionally non-polymorphic value objects, config objects, and collections
  declare `: self` with `@return self` and keep `new self(...)`
  (`SqlParam`, `AgentId`, `ExecutionFrame`, `RequestQueryParams`,
  `ObjectCollection`, the named `SourceChange::db*/rt*` constructors). Do not
  promote these to `static`.
- A subclass factory that deliberately rejects deserialization overrides with a
  throw and constructs nothing, for example a server-to-client
  `fromArray()` that always throws `TableSignalNotDeserializableException`. The
  `: static` placeholder return type is fine because the method never returns.

## Validation

- Run `php -l` on every changed file.
- Run `composer run test:framework:unit`, plus the owning project's unit suite
  for app-level DTOs (for the chat demo, `composer run test:unit` in
  `demo/chat`).
- Grep audit: every `return new self(` must sit in a `: self` method.
  Run `rg -n "return new self\(" framework demo` and confirm no hit belongs to a
  method typed `: static` or documented `@return static`.
