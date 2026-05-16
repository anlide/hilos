# ORM

Start here for any Hilos ORM change. This file chooses the mandatory follow-up
documents by touched surface; it does not replace them.

## Mandatory Reading Matrix

| Touched surface | Read |
|---|---|
| `Hilos::$db`, `DbContext`, `DbCollection`, actions, or collection lookup | `db-collection.md` |
| `Database/View/Item/*` relation properties, magic bridges, nullable relation access, or `Database/View/Collection/*` offset semantics | `db-item-bridges.md` and `accessor-contracts.md` |
| Choosing between magic properties, `[]`, result accessors, and `findBy*()` | `accessor-contracts.md` |
| Entity table mapping, persisted fields, primary keys, foreign keys, indexes, or row contracts | `entity.md` |
| Object item mapping, object collection loading, object enrichment, or `getIdString()` | `object.md` |
| Browser-facing DB/RT payloads, BrowserContext rows, frontend projections, legacy `toFrontend`, calculated fields, or table rows | `frontend-representation.md` |
| Schema migrations, rollback files, seeds, or schema checks | `migrations.md` |

When a change touches more than one surface, read every matching document before
editing. DB entity shape, RT item shape, signal DTOs, and routes still require
the project contract approval gate before implementation.

## Document Roles

- `db-collection.md` explains the ORM layers and where read/write behavior
  belongs.
- `db-item-bridges.md` defines direct relation bridge properties on View items
  and the collection contracts they rely on.
- `accessor-contracts.md` explains when to use magic properties, array access,
  named finders, and existing result accessors.
- `entity.md` describes raw persisted DB rows.
- `object.md` describes object wrappers between entities and View items.
- `frontend-representation.md` describes BrowserContext rows, typed frontend
  projections, and serialization boundaries.
- `migrations.md` describes schema change workflow and validation.

## Working Rule

Keep persistent rows scalar in Entity/Object layers. Expose caller-facing
relations through typed View item bridge properties or documented View
collection accessors. Serialization is a separate explicit contract: adding a
bridge property does not automatically mean it belongs in `toArray()` or the
frontend payload. Direct one-to-one DB/RT overlays should expose both direct
View-item bridge directions by default.
