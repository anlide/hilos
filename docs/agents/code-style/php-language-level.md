# PHP Language Level

Read this before choosing between an old and a new PHP syntax form, or when
wondering whether an 8.4-only construct is allowed.

## Core Rule

The minimum language level is **PHP 8.4**. It is already declared by
`"php": ">=8.4"` in the root `composer.json` and in every demo `composer.json`,
so a project running Hilos always has 8.4 available. Therefore 8.4-only syntax is
**allowed and preferred** where it reads more clearly — it is not something to
work around for the sake of an older runtime.

## Preferred Shape

The settled case is the parentheses around `new` when a member is accessed on the
new instance. PHP 8.4 lets you write the member access directly on `new`, so drop
the outer parentheses:

```php
// Preferred (PHP 8.4)
return new DateTimeImmutable()->modify('-1 day');
$server = array_find($this->servers, fn($s) => $s instanceof WorkerServer);
```

```php
// Avoid: the outer parentheses are no longer needed
return (new DateTimeImmutable())->modify('-1 day');
```

Strip only the outer pair, and only when a member is accessed with `->` or `::`
on the result. A parenthesized `new` that is not followed by a member access, or
one whose parentheses belong to an enclosing call (`foo(new Bar())->baz` — the
`->baz` applies to `foo(...)`, not to `new Bar()`), is left unchanged.

## Exceptions

None for the language floor itself: do not reintroduce compatibility shims for
PHP versions below 8.4.

## Validation

The change is purely syntactic, so the existing test suite plus `php -l` over the
touched files is the regression net.
