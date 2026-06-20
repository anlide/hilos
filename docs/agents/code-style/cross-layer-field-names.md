# Cross-Layer Field Names

Read this before naming a data field that crosses layers — a DB column or RT
item field, its PHP entity/object/view property, the wire/DTO payload key, and
the TypeScript entity field. For PHP `use` aliases and helper names see
[import-aliases-and-helper-names.md](import-aliases-and-helper-names.md); for the
English dialect of identifiers see [spelling.md](spelling.md).

## Core Rule

One data field carries one concept name through every layer it crosses
(DB or RT state → PHP → wire → TS). Use the same word or words at each layer and
change only the case convention — `snake_case` for the SQL column and the PHP
entity field, `camelCase` for the wire payload key and the TypeScript field. Do
not change the words, swap a synonym, add a predicate prefix, or change the
grammatical form at a boundary. A reader must recognize the same field at the
DB, in PHP, on the wire, and in the view without a translation table.

## Preferred Shape

```
admin                          identical at the SQL column, PHP entity, wire key, TS field
block                          identical at every layer
last_activity → lastActivity   same words; snake_case (SQL/PHP) to camelCase (wire/TS)
```

## Anti-Patterns

```
block (SQL/PHP) → blocked (TS)              grammatical form changed
admin (SQL/PHP) → superadmin (TS)           different word
block (SQL/PHP) → is_blocked / isBlocked    predicate prefix added
```

Each forces a per-boundary rename and a mental translation table — the cost this
rule removes. The framework panel-operator fields were deliberately renamed
`superadmin`→`admin` and `blocked`→`block` so one short token spans DB, PHP,
wire, and TS unchanged.

## Exceptions

The `snake_case` ↔ `camelCase` shift at the PHP→wire boundary is the one
permitted difference: same words, layer-appropriate case. Where a layer must
carry a foreign name the project does not own — a third-party column, an
external API key — keep that given name at that layer and document the seam.

## Contract Gate

Naming a NEW field is free. RENAMING an existing field changes DB entity shape
and signal/DTO payload keys — contract surfaces. Stop at the `agents.md`
Contract approval gate before renaming a field that is already persisted or on
the wire.
