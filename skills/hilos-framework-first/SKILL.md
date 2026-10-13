---
name: hilos-framework-first
description: "Check Hilos::$db before writing SQL and consider Hilos::$rt before holding state on a class. Use when designing, writing, or reviewing a query of your own — raw SQL, a PDO or mysqli call, an Entity or Object layer reached from a page, table, agent, or handler, any read of the database past Hilos::$db — and when choosing a static property, an instance field, or a class-local array to hold state between calls, ticks, requests, workers, or nodes. Use it even when the task names neither facade: the trigger is the intention to query or to store, not the words Hilos::$db or Hilos::$rt."
---

# Hilos Framework First

Use this skill before a query of your own or a class-local store for state. It
routes to the canon and to the profile documents of the entry you are at; it
does not restate them. Reading it is not the decision: the decision is what you
found in the tree and why you chose it, and that is what the plan or the commit
text carries.

## Read First

- The canon, both entries: `docs/agents/framework-first.md`
- SQL or direct database access — the ORM entry:
  - `docs/agents/orm/README.md` — which ORM document the touched surface needs
  - `docs/agents/orm/db-collection.md` — "Finding Existing Logic" and "Choosing
    Where New Logic Belongs"
  - `docs/agents/orm/accessor-contracts.md` — offsets, finders, result accessors
  - `docs/agents/orm/db-item-bridges.md` — a relation is a bridge, not a join
  - `docs/agents/architecture/entity-libraries.md` — the four load strategies,
    and which of its sentences describe an approach rather than code
- State on a class — the runtime entry:
  - `docs/agents/runtime/rt-context.md` — "RtContext vs DbContext" and
    "Finding Existing Logic"
  - `docs/agents/architecture/truth-source.md` — who owns a collection and what
    a claim allows

Read the entry you are at; both when the change needs both.

## Workflow

1. Name the need in one line: the rows or the value you want, and who else
   reads or writes it.
2. Find the framework's mechanism for it — the mounted collection with its load
   strategy, keys, bridges and actions; or the runtime collection, alias, or
   item field and its owner.
3. Check the implementation and its limits in the tree, and a fitting example
   of a caller using it. A neighbour's workaround is not an example, and a
   document's agreed future is not an API.
4. Choose: use it; add the smallest missing piece on the owning layer; or, for
   a gap you can name, carry the new mechanism into the plan.
5. Put the mechanism, the profile source, and the ground of the choice into the
   ordinary plan, note, or commit text.

## Hard Rules

- Do not write SQL, or reach the Entity or Object layer, from a page, table,
  agent, or handler before walking "Finding Existing Logic" for the collection.
- Do not treat a neighbouring query or static as precedent.
- Do not build against a collection or bridge a document describes and the
  tree does not have.
- Do not hold on a static or an instance field a value another worker or node
  reads or writes; it has an owner, and the owner's collection is where it
  lives.
- Do not move every variable into `Hilos::$rt`: a value private to one process,
  one instance, and one span of work stays local.
