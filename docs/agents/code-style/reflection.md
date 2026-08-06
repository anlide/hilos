# Reflection

Read this before adding a `Reflection*` call to production PHP, before changing
one that already exists, or when reviewing whether an existing call is still
justified.

## Core Rule

Do not reach for Reflection in production PHP. Adding or changing a Reflection
call is the owner's decision, not the agent's: propose the change, name what the
call asks and why nothing else answers it, and wait.

Reflection is allowed only where the question is genuinely about a
**declaration** — is this class abstract, does this property exist, what type
was it declared with — and plain PHP has no answer. Everything about *behavior*
has a plain-PHP answer, and that answer is the one to write.

The scope of this rule is production code: `framework/backend` and
`demo/*/backend`.

## Workflow

1. Name the question the call asks in one sentence. If it is about behavior —
   what a method returns, whether an object can do something — stop; Reflection
   is the wrong tool.
2. Look for a seam instead of a reader. A constant the caller may not see is a
   missing accessor on the class that owns it, not a case for
   `ReflectionClass::getConstant()`.
3. Look for a declaration instead of a computation. A fact the code can state
   next to what it describes does not need to be derived from the class shape.
4. If the call survives both, it is a proposal, not a decision: say what it asks
   and why plain PHP does not answer, and let the owner rule on it.
5. Every surviving call carries an inline comment in that same shape — what is
   being asked, why plain PHP does not answer it.

## Preferred Shape

A protected constant is unreadable from outside — so the class that owns it
grows a reader, and the caller stops guessing:

```php
// framework/backend/Hilos.php
public static function featuresOf(string $hilosClass): array
{
    return $hilosClass::FEATURES;
}

// caller
foreach (Hilos::featuresOf($hilosClass) as $feature) {
```

A fact about a class is declared where the thing it describes lives, and the
pair is held by a test:

```php
// FeatureDefinition
public function mountsRuntime(): bool
{
    return false;
}

// BackupFeature, next to the mount() it overrides
public function mountsRuntime(): bool
{
    return true;
}
```

## Anti-Patterns

```php
// Wrong: reading someone else's constant through Reflection because the
// visibility said no.
$declared = (new ReflectionClass($hilosClass))->getConstant('FEATURES');

// Wrong: deriving a fact from the class shape when the class can state it.
return (new ReflectionMethod(static::class, 'mount'))->getDeclaringClass()->getName() !== self::class;
```

## Exceptions

Two, and they are named rather than implied:

- **`framework/backend/Database/Schema/EntitySchemaAudit.php`** — asks whether a
  class is declared abstract, whether a property exists, whether it is static,
  and what type it was declared with. The audit compares the *declaration*
  against the live schema, so the declaration is precisely its subject;
  `class_exists()` is true for an abstract class, `property_exists()` sees a name
  but no type, and a typed property left uninitialized is invisible on an
  instance.
- **Test code as a class** — `framework/tests` and `demo/*/tests`. A test asks
  about declarations by nature (a declared type, private state, a signature), no
  plain-PHP API answers that, and the alternative is bending the design to keep
  the test writable. This exception is written down rather than left silent so
  that a test using Reflection is not read as debt.

## Validation

Nothing in the test suite fails on a new Reflection call. What happens instead
is a notification: a `post-commit` hook fires when a merge lands in the base
branch and tells the owner that the merged delta added Reflection — red for
production code, yellow for tests. It reports, it does not block, and moving or
re-wrapping an existing call stays silent.

**This rule has no machine check on purpose.** It is lexical, so it could have
one — [automated-checks.md](automated-checks.md) describes exactly how, and
[rule-authoring.md](../rule-authoring.md) says a decidable rule should get one.
The owner ruled otherwise here (HIL-538): a guard that fails the suite turns
every judgement call into a baseline entry, and the judgement is the part worth
keeping. Do not add a `REFLECTION-USE` guard as a tidy-up; changing this needs
the owner, like the rule itself.

The standing inventory is one grep:

```bash
grep -rn "Reflection" framework/backend demo/*/backend --include=*.php
```

Everything it returns outside `EntitySchemaAudit` is either new debt or a
decision the owner has taken; nothing else belongs there.
