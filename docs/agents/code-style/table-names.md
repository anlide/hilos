# Table Names

Read this before naming a database table — the SQL table and its Entity
`_table`. For naming the columns inside it across layers see
[cross-layer-field-names.md](cross-layer-field-names.md); for the caller-facing
relation accessors see [../orm/db-item-bridges.md](../orm/db-item-bridges.md).

## Core Rule

A table name is `snake_case` and reads **entity first, then purpose**: the main
entity the table is about, then the logical meaning of the table.

```
<entity>_<purpose>
```

A table about a single entity leads with that entity. `user_rename` is the
`user` entity plus the `rename` purpose (the record of a rename). A root entity
table is just the entity: `user`, `bot`.

## Bridge Tables

A bridge (junction) table has **two primary entities** — it records a relation
between them (1-N or N-M). Name it with both entities first, in order, then the
logical purpose:

```
<entity1>_<entity2>_<purpose>
```

Order the two entities by how central each is to the project: the **more
frequently used — or more frequently to-be-used — entity comes first**. For an
N-M relation with no other asymmetry, that dominance is the tie-break.

The same dominance is **also declared to the machine**, on the Entity: `_setVia`
names the column the bridge's set is cut by, which is the leading entity's key.
The two are not alternatives — a name says it to a reader, the constant says it
to the gate that refuses a table nobody answers for, and one is not left out
because the other is there. See
[../orm/entity.md](../orm/entity.md).

## Preferred Shape

```
user                              root entity — just the entity
user_rename                       user + rename: the rename audit record
hilos_setting                     the hilos_setting entity (framework-owned)
user_group_membership   (bridge)  user × group + membership; user is the
                                  more-used entity, so it leads
order_product_return    (bridge)  order × product + the return record
```

## Anti-Patterns

```
event_user_rename     a parent/aggregator (`event`) led instead of the main
                      entity; renamed to `user_rename` (entity first)
rename_user           purpose led instead of the entity
usr_rnm               abbreviated entity or purpose
group_user_membership the less-used entity (`group`) placed before the
                      more-used (`user`) in a bridge
```

A reader scanning the table list should be able to group every table by its
leading entity. A purpose-first or aggregator-first name scatters one entity's
tables across the list, and a mis-ordered bridge hides which entity the relation
hangs from.

## Contract Gate

Naming a NEW table is free. RENAMING an existing table changes the DB entity
shape — the `_table` mapping and every dependent ORM class. Stop at the
`agents.md` Contract approval gate before renaming a table that an applied
migration already created.
