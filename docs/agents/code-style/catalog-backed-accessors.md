# Catalog-Backed Accessors

Read this before reading a value out of a catalog-backed accessor — `Hilos::$env`
or `Hilos::$setting` — and before adding a method to one.

A catalog-backed accessor is a class that implements `ArrayAccess` over a catalog
(`Hilos\Core\Catalog\CatalogProviderInterface`) and whose index hands back a typed
reader. Two exist today:

| Accessor | Class | Reader |
|---|---|---|
| `Hilos::$env` | `framework/backend/Environment/EnvAccessor.php` | `framework/backend/Environment/EnvValue.php` |
| `Hilos::$setting` | `framework/backend/Database/Settings/SettingsAccessor.php` | `framework/backend/Database/Settings/SettingValue.php` |

The rule is written for the property, not for these two names: the next accessor
built the same way falls under it without a line being added here.

## Core Rule

| What is asked | How it is written | What comes back |
|---|---|---|
| the value | `Hilos::$env[KEY]->string()` | a typed reader, then the type |
| whether the key is there | `isset(Hilos::$env[KEY])` | `offsetExists()`, no reader built |
| whether env is here at all | `isset(Hilos::$env)` | the static property, not a key |

`Hilos::$setting` reads the same way.

- The value is read through the index.
- The type is asked on the reader the index returned — `string()`, `int()`,
  `float()`, `bool()`.
- A typed method beside the index is not added: there is exactly one way to read
  a key, and the index is it.

## What is not a second door

Four forms stand in the tree next to the index, and all four are legitimate. The
rule is about how a value is read, not about these:

1. **A local alias of the accessor.** `$env = Hilos::$env`, then
   `$env[KEY]->int()`. The alias exists for the null check: the accessor may be
   absent in this process, and the layer then answers with its own floors.
   `ThrottlePolicy::fromEnv()` (`framework/backend/Auth/Throttle/`) carries both
   halves in one method — the check and the reads.
2. **`effectiveValueFor($key)`.** The raw value, for a caller that does not know
   the type yet. It does not answer "give me the string": it hands back the
   effective value — loaded value or catalog default — and which type was asked
   for is not its question; the catalog or a validation rule decides that at run
   time. Its production readers are `LogWriteLevelResolver` and
   `LogSettingsResolver` (`framework/backend/Log/`) and `SettingPresetResolver`
   (`framework/backend/Database/Settings/Preset/`), where the value goes into a
   rule before its type is known. It is also the seam a test substitutes values
   through, by overriding the method on a subclass of the accessor. Application
   code that knows the type it needs does not call it.
3. **Questions about the catalog itself,** not about a key's value: `catalog()`,
   `typeFor()`, `defaultValueFor()`, `defaultReferenceKeyFor()`, `ruleFor()`.
   Their readers are the orphan and setting-override CLI commands and the project facades —
   `OrphanTestCreateCommand` (`framework/backend/Core/CLI/Commands/`) hands
   `Hilos::$setting->catalog()` to the settings action.
4. **The inside of the reader.** `EnvValue::resolveValue()` calls
   `effectiveValueFor()` on the accessor it holds, and it holds one for the
   reason its class docblock gives: env genuinely lives in more than one
   instance — a test swaps the global for the length of a case — so a reader
   that went to the global would answer from a different accessor than the index
   it came out of.

## Why the index, and not a method

Env used to have both: `string()`, `int()`, `float()` and `bool()` on the
accessor itself, beside the index. For as long as the throws guard did not read
the index, two doors led to one value and one of them was checked; the form was
chosen by habit, per call site, and the two contracts drifted apart without
anyone seeing it. HIL-863 taught the guard to read an index as the `offsetGet()`
it is, and HIL-885 removed the second door from env. Settings never had one — not
by decision, but because nobody had written that it may not be added. This rule
is that sentence.

In general terms: two doors to one value are two PHPDoc declarations that have to
agree, and nothing makes them agree. They drift silently and are discovered on
the call that picked the second one.

How the guard reads an index is written where the guard is: the
`THROWS-PROPAGATION` section of [automated-checks.md](automated-checks.md), and
the `SCOPE` constant of
`framework/tests/CodeStyle/Throws/ThrowsPropagationRule.php`, which a failing run
prints. It is not restated here.

## Adding a type

A new type is a method on the **reader** — `EnvValue`, `SettingValue` — not on
the accessor. The reader already holds the key, so a type costs one method, not
one overload of every door; `EnvValue::string()` and `SettingValue::string()` are
the shape. Before adding one, make sure the catalog declares the type: the reader
compares the declared type with the requested one and refuses a mismatch.

## Asking the catalog

The accessor asks its provider once per instance. `getCatalog()` keeps the
first answer on the accessor and hands it to every later read:

```php
private ?array $catalogCache = null;

return $this->catalogCache ??= $this->catalogClass::getCatalog();
```

Four things hold for any accessor built this way, the next one included:

- **The cache lives on the accessor instance, not statically on the catalog
  class.** A test swaps the accessor for the length of one case — the reason
  the reader holds its accessor, above — and a static cache would answer the
  next case out of the catalog the previous one declared.
- **It fills lazily.** Nothing warms it: the constructor stores the provider
  class and nothing else, and the first question builds the catalog — a key
  read or a question about the catalog itself. An accessor nobody asks builds
  none.
- **No method empties it, and none is added.** `EnvAccessor` does carry
  `clearCache()` and `reload()`, and both are about the loaded `.env` values,
  not about the catalog: a declaration assembled from literals has nothing to
  go stale against.
- **Another catalog is another accessor.** The provider class is a constructor
  argument, so a second catalog is reached by constructing a second accessor,
  never by re-pointing the first.

Keeping the first answer is safe because a catalog is a declaration, not a
value: the provider builds the same array from the same literals every call, so
remembering it changes nothing an owner can observe. What it saves is real —
one index read consults the catalog three times, the key check, the type and
the value, and a settings screen row asks four or more times. The `getCatalog()`
docblock of `EnvAccessor` carries what a rebuild per lookup costs, and the one
of `SettingsAccessor` carries the single case where staleness was weighed and
ruled out; neither is restated here.

## Two things called settings

```php
Hilos::$db->settings[$key];       // a row of the settings table: a DB item with its own actions
Hilos::$setting[$key]->string();  // a catalog-backed accessor: a value with a declared type and default
```

Different things about different things. This rule is about the second; the
first is a collection and follows
[accessor-contracts.md](../orm/accessor-contracts.md).

## Workflow

1. You need the value: `Hilos::$env[KEY]->string()` — or `int()`, `float()`,
   `bool()`, by the type the catalog declares.
2. You need to know whether the key is there: `isset()` on the index; no reader
   is built.
3. The type is not known until run time: `effectiveValueFor()`, with the docblock
   saying who decides the type.
4. You want a convenience method on the accessor: do not add it. You need a new
   type: add a method on the reader.
5. You write the docblock: propagate the `@throws` of the direct callee — the
   index is a call to `offsetGet()`, and `THROWS-PROPAGATION` asks for its whole
   contract.
6. You are building a third accessor of this kind: the catalog is asked once
   per instance, and the four consequences are in *Asking the catalog* above.
