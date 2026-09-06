# Table Sort Orders

> **The rules below are the target, and the code is still catching up (not in
> the code yet — HIL-783).** Until that epic closes, a table in this repository
> may still behave the way this document no longer describes. What is still
> outstanding is on the epic's board and deliberately not listed here: the board
> stays current, a copy in a doc does not.

Read this before adding, changing, or refusing a sort order on a Hilos table.

A composite order is a **declared capability**: the table lists the orders it
supports, and under every one of them lies an index. There is no free builder —
a reader picks from the list and cannot assemble an arbitrary pair of columns.

A table declares its orders in two places, and which one depends on how many
columns the order runs by. `TableDefinition::sortableFields()` is the sort
vocabulary — `wire field => column` — and at the same time the declaration of
every single-column order, each offered in both directions, which is what a click
on a column header asks for. `TableDefinition::sortOrders()` declares the orders
of more than one column, one per key: the key is the order's own slug, the one the
frontend builds its `hilos-table-order-<orderKey>` selector out of, and it stays
on this side of the wire — a client picks an order and echoes the order itself
back. `TableSortWhitelist` holds every query path to both: `holdComposite()` asks
whether an order of more than one column was offered at all, `resolve()` turns
each of its components into a column, and either refusal costs the window its
order and nothing else.

This document is written for the agent handed the task "add a sort": it answers
in the same reply what can be done, what cannot, and why, instead of finding
that out later on a production log.

The mechanics of the window — the descriptor, the key-anchored window, the
live-change taxonomy — are in [table-subscription.md](table-subscription.md).
What the header and the "Order" menu look like is the mockup
`hilos-ops/mockups/components/table/index.html` (section 7), not this document.

## Why not a free builder

An order is served by an index only when the index matches it in both the
sequence of its columns and their directions. Any two columns out of eight give
dozens of combinations, and they cannot all be indexed. An uncovered combination
means the database sorts the whole filtered set on every show of the window:
unnoticeable on two hundred rows, seconds per window on a log of millions —
exactly the cost the key-anchored window exists to avoid.

**To declare an order is to promise that an index lies under it.** A declaration
without an index does not fail and does not complain; it lies fast and quietly.
That is why a legitimate request closes with **one change**: the declaration and
its index travel together. When the index goes as a leaf of its own, the order is
not declared until that leaf is merged.

## What a table may declare, what it refuses, and what it answers

The third column is a sample reply, not a script. Every refusal carries three
parts, and none may be dropped: what cannot be done, why, and what can be done
instead — a refusal without the third part is worked around, not followed. The
samples are in English like the rest of these docs; answer in the language of
whoever asked, keeping the three parts.

| Rule | Why | What to answer when asked for something else |
|---|---|---|
| **Under every declared order lies an index that matches it in both columns and directions.** | Otherwise the database sorts the whole filtered set on every show of the window. | "There is no such order. For it to exist we need an index `(channel, created_at, id)` — we add it, or we take one of the declared orders." |
| **The last component of every order is the primary key.** The query boundary settles it, so a declaration does not carry it: `Objects::queryPage()` appends the primary-key columns in the direction of the order's last component (HIL-786, HIL-789). | Without it the order is not total: on a column with repeats two adjacent pages show one row twice and another not at all, and the server cannot say where an arriving row falls relative to the window. | "I am adding the primary key at the end — without it the window drifts." |
| **A computed field is not part of an order.** The sign is a property, not a list: the value is not a column of the entity whose page the query takes. The live example is `presence` in a user row (`AbstractHilosUserTableRow`): it is merged in from a runtime source and has no column. | Presence, a session count, a value from another source cannot be given to an index. | "This field cannot be sorted by: it is not in the database, it is computed on the fly. Either we materialize it as a column, or we take another field." |
| **Directions are not mixed inside an order.** Not because the database refuses: MariaDB 11.4 keeps a descending index and serves a mixed order from it (measured 2026-09-05 on `mariadb:11.4.12` — `KEY ix (a, b DESC, id)` is stored, and a mixed `ORDER BY` over it reads 20 rows `Using index` where the mismatched direction reads 5000 rows `Using filesort`). It is refused because a direction is neither declared nor checked here: an index declaration carries only columns and uniqueness (`Entity::INDEX_COLUMNS`, `Entity::INDEX_UNIQUE`), and the schema audit does not read `COLLATION` at all (`EntitySchemaAudit::liveStatistics()`) — it holds an index by its ordered columns and its uniqueness. The condition for lifting this row is HIL-901. | A `DESC` written as bare DDL passes the gate, and the next migration that recreates the index from its declaration silently drops it. The query stays correct and becomes a full sort. | "Mixing directions is not possible yet — an index direction is neither declared nor checked by the gate (HIL-901). We turn both columns the same way." |
| **A column of a stale source is not sorted at all.** No mechanism of its own: this is the rule of [Per-source staleness](table-subscription.md#per-source-staleness). | An order over stale values is indistinguishable from a wrong one. | "This column's source is lagging right now — an order over it would be a lie." |
| **A table whose set is entirely in memory declares any order — and still ends it with the primary key.** The sign of "in memory": the window is assembled by filtering in PHP rather than by a page query — `TableDefinition::queryDbCollection()` hands a collection that has no ORM object collection, or one loaded whole, to `filterInMemory()` and `InMemoryTableFilter`. | There are no indexes there and sorting costs nothing, so the index rule and the direction rule do not apply. The primary-key rule does: the in-memory filter settles the order by the same key and logs a warning when a row lacks the key field. Without the key an in-memory table duplicates rows between pages exactly as a queried one would. | "Here anything goes — the set is entirely in memory. The primary key still goes at the end." |

## What the rule does not guarantee

That an index lies under a declared order is checked by nobody: not by the code
that reads the declaration, not by the schema audit — which holds a declared
index against the live one and knows nothing of the orders a table declares —
and not by review. It is a promise made by a person, and the rule buys only
that the promise is made knowingly.
