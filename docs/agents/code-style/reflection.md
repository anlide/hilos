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

The ban has two reasons, both the owner's (HIL-2099): Reflection is very heavy,
and the code built on it comes out crooked. Comparing first-class callables is
not Reflection, and it is neither heavy nor crooked. For the question "is this
method overridden" it is allowed inside the boundary of
[Asking Whether a Method Is Overridden](#asking-whether-a-method-is-overridden) —
allowed, but better avoided.

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
   For the question "does a subclass override this hook" the plain-PHP
   computation is a callable comparison:
   [Asking Whether a Method Is Overridden](#asking-whether-a-method-is-overridden)
   gives its one form and says when a declaration is still the better answer.
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

## Asking Whether a Method Is Overridden

"Does the receiver override this method" is a question about behavior, and plain
PHP answers it by comparing two first-class callables. This is not Reflection
and needs no approval inside the boundary below. Outside the boundary it is not
a weaker form of the same answer — it is a mistake.

**One form.** `$this->m(...) != self::m(...)` — the same method on the same
object: the implementation the receiver resolves against the base one. Any other
pair of closures is either incomparable or answers a different question.

**The boundary.**

- The method is an instance method, `public` or `protected`, not `final`, and
  the base class gives it a default body. For a `private` method the answer is
  always "not overridden"; for an abstract one `self::m(...)` fails.
- The check stands in the class whose body is the default, not in a trait: in a
  trait `self` is the class that uses it.
- The operator is `!=`. `!==` compares two distinct `Closure` objects and
  silently always answers "overridden".
- The result is cached per instance (`??=`) and handed out by a `final` method
  named as the question — `hasOwnTick()`.
- A comment beside it says what is being asked and that the receiver's
  implementation is compared against the base one.

**The pattern** is `framework/backend/Core/Agent/AbstractAgent.php` —
`hasOwnStopCheck()`, `hasOwnTick()`, `hasCronRulesHook()` and the cache property
of each:

```php
/** Whether this instance resolves onTick() to a non-default implementation. */
private ?bool $hasOwnTick = null;

/**
 * @internal Worker hook detection; inherited overrides count as own implementations.
 * @return bool True when onTick() differs from the base implementation
 */
final public function hasOwnTick(): bool
{
    return $this->hasOwnTick ??= ($this->onTick(...) != self::onTick(...));
}
```

**A test holds the pair**, modeled on
`framework/tests/Unit/WorkerManagerEmptyTickTest.php`:
`testHookDetectionDoesNotExecuteHooksAndSurvivesParentCalls` with its provider
`hookImplementations` runs five cases — default, inherited default, override,
parent call (an override that calls `parent::`), inherited override. Such a test
also catches a "fix" of `!=` to `!==`. These five are what the test holds; do
not read the comparison as proven for a class shape outside them.

**Better avoided.** Declare the fact instead of comparing in three cases:

1. The fact is not the same as "the method is overridden" — a class overrides
   the method and the answer must still be "no", or the other way round.
2. The answer is needed without an instance — by class name, statically.
3. Correctness, not economy, depends on the answer. This is the main one.

In `WorkerManager::tickAgents()` a mistake toward "overridden" costs one extra
tick, so the comparison fits. Where a mistake changes behavior, declare the fact
by [Preferred Shape](#preferred-shape): a method next to what it describes, and
a test that holds the pair. `mountsRuntime()` there is the third case — its
answer decides whether activation is refused in `framework/backend/Hilos.php`,
and `testEveryDefinitionAnswersMountsRuntimeAsItsOwnMountSays` in
`framework/tests/Unit/Runtime/FeatureRuntimeMountTest.php` holds the pair.

**The earlier form.** `USE_*_CALLBACK` (HIL-1831) is how the same question, "is
this method overridden", was answered before. The three seams stay as they are —
`USE_DESTINATION_CALLBACK` and `USE_LOCAL_CLIENT_CALLBACK` in
`framework/backend/Core/Router/SignalRouter.php`, `USE_SUBSCRIPTION_CALLBACK` in
`framework/backend/Core/Daemon/DaemonManager.php`. New code does not add a
"constant set to true, plus the method" pair for this question. A constant is
still the right form for a real declaration of a choice — opting into another
mode, an answer needed without an instance, a fact wider than one method;
Preferred Shape stands.

## Anti-Patterns

```php
// Wrong: reading someone else's constant through Reflection because the
// visibility said no.
$declared = (new ReflectionClass($hilosClass))->getConstant('FEATURES');

// Wrong: deriving a fact from the class shape when the class can state it.
// The plain-PHP answer to "is mount() overridden" is a callable comparison,
// not Reflection — and here the answer decides activation, so the fact is
// declared: mountsRuntime().
return (new ReflectionMethod(static::class, 'mount'))->getDeclaringClass()->getName() !== self::class;

// Wrong: identity instead of equality. Each side is a new Closure object, so
// the two are never identical and the answer is always "overridden".
return $this->m(...) !== self::m(...);
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
branch and tells the owner that the merged delta added Reflection to production
code. Tests are not reported: the rule allows Reflection there (see Exceptions),
and reporting what is allowed is noise. It reports, it does not block, and
moving or re-wrapping an existing call stays silent.

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

The same grep also returns mentions in comments. The inventory decides
executable Reflection calls; a comment that names the ban stays. Under
`framework/backend` the only executable calls are in `EntitySchemaAudit`.
Every other executable call the grep returns is either new debt or a decision
the owner has taken.

A callable comparison passes both silently: the notification and the inventory
grep look for the word Reflection, and a comparison is not Reflection. That is
how they are built, not a gap in them. The comparisons have an inventory of
their own, a second grep:

```bash
grep -rnE '\(\.\.\.\) *!=' framework/backend demo/*/backend --include=*.php
```

Today it returns the hook checks of `AbstractAgent` and the `onTick()` check in
`WorkerManager`. It matches `!==` as well, so the wrong operator shows up in the
same list. No guard is added for the comparison: the decision above (HIL-538)
covers it too.
