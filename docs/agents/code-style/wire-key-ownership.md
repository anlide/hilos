# Wire Key Ownership

Read this before naming a row-payload key in a frontend admin module — adding or
renaming a table column, writing a row resolver, or moving a key between the core
headless and a view. For the words a field carries from DB to view see
[cross-layer-field-names.md](cross-layer-field-names.md); for the same question on
the PHP side see [internal-backend-api.md](internal-backend-api.md).

## Core Rule

A row-payload key is declared once, as a named constant in the `@hilos/core`
module that owns the view-model it resolves into. Every site that names that key
reads the constant. Do not repeat the key as a string literal.

The key of a table column is written as that constant when — and only when — the
same key is read from the payload by a resolver. A column key with no payload
behind it (`actions`) stays a literal.

Export the constant from the owning module, and re-export it from
`core/src/index.ts`, only when a view names it as a column key. A key that only
the resolver reads stays module-private.

Type a page's column list by the row it renders,
`HilosTableColumnOf<ItsRow>[]`, so a typo or a renamed view-model field fails
compilation instead of rendering an empty column in three frameworks at once. A
page with no core-owned row type keeps the plain `HilosTableColumn[]`.

## Workflow

1. Declare the key in the owning core module, as `UPPER_SNAKE` ending in
   `_FIELD`, in one block after the module's wire keys, with a TSDoc line
   `Row payload key of ...`.
2. Point the resolver, the table's `initialSort`, and any other in-module reader
   at the constant.
3. Export it and add it to the core barrel only if a view needs it as a column
   key; otherwise leave it `const`.
4. Declare the view's columns as `HilosTableColumnOf<ItsRow>[]` and replace the
   literal keys with the imported constants, in all three view packages.

## Preferred Shape

```ts
/** Row payload key of the creation instant (also the table's default sort field). */
export const BACKUP_CREATED_AT_FIELD = 'createdAt'

/** Row payload key of the recorded failure reason. */
const BACKUP_FAILURE_REASON_FIELD = 'failureReason'
```

Flat constants are the default. Use a `const` map (`HilosChannelRowKey`) only
when one module owns more than one row shape and their field names overlap, so
flat constants would need a disambiguating prefix per shape.

## The form is load-bearing

The `_FIELD` suffix and the `*RowKey` map name are not house style: they are how
`WIRE-KEY-CASE` tells a row-payload key from a signal type, an action name, a
table key or a slot name, none of which share the key's camelCase convention (see
[automated-checks.md](automated-checks.md)). A key declared outside the form is
invisible to the checker — it keeps the ownership rule above and loses the
machine that would catch a snake spelling on its way to the wire. Declare a new
key in the form even when a shorter name reads better at the use site.

## Anti-Patterns

```ts
// Wrong: the same key written in the resolver and again in each of three views.
value: readStringOrNull(slot, 'overrideValue')
const COLUMNS: HilosTableColumn[] = [{ key: 'key', label: 'Key' }]

// Wrong: two keys of different roles folded into one constant because the
// strings match today. A viewport filter name and a row-payload key are
// separate contracts and rename separately.
export const DELIVERY_FILTER_CHANNEL = DELIVERY_CHANNEL_FIELD
```

## Exceptions

- `actions` — the one virtual column key in the SDK; it names no payload field.
- Core unit tests keep string literals: a fixture that writes the wire key
  through the same constant the resolver reads stops detecting a rename.
- A page with no core-owned view-model has no key owner to name; leave it as is
  rather than inventing one in a view.

## Validation

`composer run test:framework:frontend` in docker (typecheck, unit, lint,
format-check). The typecheck is the load-bearing step: it is what turns a missed
key into a build failure.
