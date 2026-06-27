# Framework Development

Read this before changing shared Hilos framework APIs, facade globals, base
classes, framework settings access, subsystem exceptions, or cross-project
frontend components.

## Core rule

Framework code should make the global Hilos facade contract explicit instead of
threading it through incidental parameters. If code needs `Hilos::$db`,
`Hilos::$rt`, `Hilos::$setting`, `Hilos::$table`, `Hilos::$browser`,
`Hilos::$fs`, `Hilos::$sr`, or another facade singleton, read it through the
facade at the point where the current process-global instance is needed.

Do not pass those globals through constructors, helper parameters, or nested
value objects just to make them reachable. That creates a second dependency
contract, can capture stale instances before initialization changes, and hides
the fact that the framework source of truth is the active facade.

## Dependencies

Keep third-party dependencies deliberately low — most of all on the **backend**.
Hilos is a framework, so every dependency it takes is inherited by every project
built on it; each one is supply-chain and maintenance risk passed downstream.

- Before adding a library, prefer a hand-rolled or standard-library solution and
  justify why the dependency earns its place. On the backend, lean hand-rolled.
- A dependency is more acceptable on the **frontend** when it sits behind the SDK
  boundary and stays reversible: hidden behind a parse/adapter seam and swappable
  through a standard interface, so a consuming project never couples to it
  directly. The SDK's runtime schema-validation library is the precedent — it
  lives behind the SDK parse-boundary and a standard-schema interface.
- "It is convenient" is not a justification. Weigh the maintenance cost, the
  transitive tree, and whether the real need is one function or a whole library.

## Extension points

- Framework base classes expose project variation through protected factory or
  override methods.
- The default framework implementation should be simple, usually
  `return new FrameworkThing();`.
- Project subclasses customize catalog-backed accessors through protected
  catalog provider constants when only metadata changes.
- Project subclasses customize behavior by overriding the factory and returning
  `new ProjectThing();`.
- Do not make project code initialize framework metadata by passing DB, catalog,
  or facade objects through constructors when the framework can resolve them
  from its own facade contract.

Example shape:

```php
protected const string SETTINGS_CATALOG = SettingsCatalogStub::class;
```

Project override:

```php
protected const string SETTINGS_CATALOG = SettingsCatalog::class;
```

## Settings and catalogs

- Settings catalog definitions are allowed to be arrays because the catalog is a
  compact declarative map.
- Keep those arrays at the catalog boundary. Runtime readers and accessors
  should expose typed methods or typed value objects instead of accepting the
  whole catalog as constructor state.
- Use catalog provider class strings for project env/settings metadata; do not
  add empty accessor subclasses that only override `getCatalog()`.
- Catalog metadata and default values must not require DB initialization.
- Persisted values may require `Hilos::$db`; missing DB should fall back to
  catalog defaults only when the accessor contract explicitly supports that.
- If a reader method name encodes a type, such as `string()`, `int()`,
  `float()`, or `bool()`, it must reject catalog entries of another type.

## Exceptions

Framework subsystems should have their own caller-facing exception family when
the failure is not covered by an existing family.

- Use `DatabaseException` for SQL, connection, schema, migration, and DB
  infrastructure failures.
- Use `RtBaseException` and children for runtime state and runtime sync failures.
- Use `ValidationException` and children for user or business validation.
- Use `TableActionException` for table/page action validation returned to table
  UI.
- Use a subsystem base exception extending `HilosException` for framework
  subsystem contracts such as settings access, then add concrete child
  exceptions for key-not-found, type mismatch, unsupported mutation, and invalid
  values.
- Do not use a generic unrelated exception such as `UnsupportedOperationException`
  when the caller should understand a framework subsystem failure.

## PHPDoc

- Public and protected framework methods must document meaningful local
  contracts.
- Framework command methods should return `void` on success and throw
  subsystem exceptions on failure. Do not return `bool` as a success flag.
- `get*()` methods must not consume or clear state. Use names such as
  `consumeResult()` when retrieval intentionally changes future reads.
- Every `@throws` line needs a short reason.
- Add `@throws` only for exceptions thrown directly, documented by a callee, or
  deliberately exposed as the local caller-facing contract.
- Do not propagate broad assumptions like "DB might fail" through every private
  helper; document the failure at the boundary where it matters.

## Contract gate

The root `AGENTS.md` contract approval gate still applies before implementation.
Stop and ask for explicit confirmation before changing:

- DB entity persisted fields, mapping, migrations, or row contracts;
- RT item state fields and `create()`, `fromRow()`, `applyDiff()`, or
  `toArray()` shape;
- signal constants, DTO payloads, declarative routing, or page/worker routes.

## Validation

Use `$hilos-testing-cli` to choose composer scripts. Do not run PHPUnit or
frontend tooling directly when a composer script exists.
