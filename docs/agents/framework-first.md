# Framework First

Read this before writing a query of your own or reaching the database past
`Hilos::$db`, and before giving a class a static property, an instance field, or
a local array to hold state. It applies the moment the intention is there — "I
will query this", "I will keep this in a static" — whether or not the task has
named either facade. It routes to the ORM and runtime rules; it does not restate
them.

## Core Rule

Before writing SQL, research `Hilos::$db` for the mechanism that already answers
the need. Before storing state on a class, consider `Hilos::$rt`, and settle the
value's lifetime and owner first. Use the existing mechanism, or extend it
minimally on its owning layer; introduce a new one only for a gap you found in
the tree and can name.

## Workflow

1. Find the mechanism the framework already has for the need.
2. Open the applicable profile rule: the ORM documents for SQL, the runtime and
   truth-source documents for state. Each entry below names them.
3. Check the implementation and its limits in the tree — the mounted collection,
   its load strategy, keys, bridges and actions; the runtime collection, its
   owner and its fields — and a fitting example of a caller using it.
4. Choose: use it as it is, or add the smallest missing piece on the owning
   layer. A new mechanism is justified only by the gap step 3 showed.
5. Say what you found — the mechanism, the profile source, and why you chose
   as you did — in the plan, the note, or the commit text, as part of the
   ordinary description of the change. Not a report of its own.

Two things do not count as evidence in step 3:

- **A neighbouring example is not proof.** Code next to yours that queries by
  hand or keeps a static was written under the same pressure and may be the
  debt you are about to copy. Its existence says nothing about what the
  framework offers.
- **An agreed future is not an existing API.** A document may describe an
  approach ahead of its code — [architecture/entity-libraries.md](architecture/entity-libraries.md)
  opens by saying so of itself, and a sentence anywhere may carry
  `(not in the code yet — HIL-<n>)`. Build against what the tree has. What is
  only agreed is a gap, and building toward it is a plan decision, not a line
  slipped into a page.

## SQL: Research `Hilos::$db` Before A Workaround Query

A query of your own is raw SQL, a `PDO` or `mysqli` call, or an Entity or
Object layer reached from a page, table, agent, or handler. Before writing one,
walk "Finding Existing Logic" in [orm/db-collection.md](orm/db-collection.md):
the mounted collection and its `setRepresent()`, the View collection's
documented offsets and finders, the Object collection's loaders, the actions.
Three things decide most cases:

- **Load strategy.** How a collection is mounted decides what a read costs and
  what a walk returns. `LAZY_STRATEGY_NONE` fills the whole table on the first
  read by any entrance; `LAZY_STRATEGY_KEY` loads row by key and refuses a walk
  until a holder has preloaded it. The four strategies are tabled in
  [architecture/entity-libraries.md](architecture/entity-libraries.md), section
  "Declaring The Set Complete, And Refusing When It Is Not". A query written
  "because the collection is lazy" is often a query against a collection that
  is not.
- **Keys and finders.** The documented offset (`Hilos::$db->users[$id]`), the
  named finders (`Hilos::$db->sessions->findByToken()`), and the rows of one
  column value (`whereColumnIs()`, `whereColumnIn()` on `DbCollection`, over
  `Objects::loadByColumn()`): [orm/accessor-contracts.md](orm/accessor-contracts.md)
  chooses between them.
- **Bridges.** A relation from a loaded item is a bridge property on its View
  item, not a join written at the call site:
  [orm/db-item-bridges.md](orm/db-item-bridges.md).

An example from the tree: the language and country reference — `languages`,
`countries`, `locales` and their name tables — is mounted whole by
`HilosDbContext::configure()` under `LAZY_STRATEGY_NONE`, and the `Country`
View item exposes `$country->defaultLocale` as a bridge. A query joining
countries to their default locales rebuilds what the first read of either
collection already holds.

If nothing fits, add the smallest missing method where
[orm/db-collection.md](orm/db-collection.md), section "Choosing Where New Logic
Belongs", places it — a loader on the Object collection, a finder on the View
collection, a bridge on the item — and not a query in the page, table, or
agent. [orm/README.md](orm/README.md) chooses which ORM document that extension
needs.

## State: Consider `Hilos::$rt` Before Static Or Instance Storage

Before a static property, an instance field, or a class-local array holds state,
answer two questions:

- **Lifetime.** Must the value outlive this process, be seen by another worker
  or another node, or come back after a restart? Memory of one process is one
  worker's and dies with it. Durable data belongs in `Hilos::$db`; live state
  shared across workers belongs in `Hilos::$rt`. The boundary is the table
  "RtContext vs DbContext" in [runtime/rt-context.md](runtime/rt-context.md).
- **Owner.** Who writes it? A runtime collection has exactly one owner per
  cluster and is written only through its actions
  ([architecture/truth-source.md](architecture/truth-source.md)). A value in a
  static has no owner: a second worker holding its own copy is a second truth
  nobody declared.

Then walk "Finding Existing Logic" in [runtime/rt-context.md](runtime/rt-context.md):
an existing collection, single-item alias, or item field may already carry the
value, and the owner's claim says who may change it.

A static or a local field is the right answer when the value is private to one
process and one instance for the span of its work — a parsed constant, a
counter for the current tick, a handle the instance opened — needs no reader
elsewhere, and loses nothing on restart. Considering `Hilos::$rt` is a decision,
not a transfer: every runtime write is replicated to every worker and node and
needs a declared owner, so a value that no one else reads does not belong
there.

## Anti-Patterns

- A `SELECT` of your own, or a `PDO` handle, in a page or an agent, with the
  collection mounted a few lines away in the context. Use the collection; add
  the loader to it if one is missing.
- `private static array $cache` holding rows another worker writes. That value
  has an owner; its collection in `Hilos::$rt` is where it lives.
- "The chat demo does it this way." The neighbour is not the rule; the profile
  document is.
- Writing against a collection or a bridge a document describes and the tree
  does not have. The document is an approach; the gap is a plan item.
- Moving every field of a class into runtime rows "to be safe". A value nobody
  else reads stays local.

## Exceptions

- Migrations and seeds are SQL by design ([orm/migrations.md](orm/migrations.md)).
- The ORM and runtime layers themselves — `framework/backend/Database/**` and
  `framework/backend/Runtime/**` — are where the query and the backing state
  are written; this rule is about reaching them from outside.

## Validation

The choice is not machine-checked; it is named in the plan or the commit text.
What a guard does catch is around it: `RT-STATE-REACH` and `RT-STATE-MUTATE` in
[code-style/automated-checks.md](code-style/automated-checks.md) refuse backing
runtime state reached or written outside its layer, and a walk over a lazy
collection that no holder preloaded refuses at runtime with
`CollectionNotFullyLoadedException`.
