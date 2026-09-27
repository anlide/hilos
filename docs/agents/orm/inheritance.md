# ORM: Extending A Framework Entity

Read this before a project needs a column of its own on a framework table, before
subclassing any concrete ORM class of the framework, or before mounting such a
subclass under a framework collection key. The rule is written ahead of its code:
every sentence about a mechanism that does not exist yet carries a marker naming
the leaf that lands it, and that leaf clears the marker in the same commit
([../rule-authoring.md](../rule-authoring.md), *A Rule Written Ahead Of Its Code*).

## Core Rule

Every concrete ORM class of the framework is designed for inheritance, and none
of them is `final` (not in the code yet — HIL-1190). A project that needs
something of its own on a framework table extends THAT table with its own
columns — it does not open a side table of "additions" next to it (owner's
decision B of the epic, 2026-09-24).

Extending a framework table means subclassing its whole chain, re-pointing the
links between the layers at the subclasses, and mounting the chain under the
framework's own collection key. One layer alone is not an extension; see
*Anti-Patterns*.

Two precedents already in the tree show the shape without deciding the mount
point's form: the inheritable RT row — `HilosSessionConnection` is the framework
base and the project adds its hooks
([../runtime/rt-state.md](../runtime/rt-state.md), *The base half of the row
cannot be skipped*) — and the class swapped through a facade constant
(`Hilos::ADMIN_AUDIENCE`, read through `static::appClass()`).

The first live consumers: the chat demo's subclass of the framework's person
table keeps `merged_into` on it (not in the code yet — HIL-1192) until the merge
tombstone table takes it over (HIL-1199); the chat demo's rename journal is a
subclass of the framework's rename journal (not in the code yet — HIL-1196).

## The Whole Chain

A framework table is one chain of classes, and a subclass replaces the chain, not
a link of it. The layers to subclass are, for the table's key:

| Layer | Base | Where |
|---|---|---|
| Entity item | `Entity` | `Database/Entity/Item/` |
| Entity collection | `EntityCollection` | `Database/Entity/Collection/` |
| Object item | `Object_` | `Database/Object/Item/` |
| Object collection | `Objects` | `Database/Object/Collection/` |
| View item | `DbItem` | `Database/View/Item/` |
| View collection | `DbCollection` | `Database/View/Collection/` |
| Collection actions | `DbActions` (collection) | `Database/Actions/Collection/` |
| Item actions | `DbActions` (item) | `Database/Actions/Item/` |

The two action layers are subclassed where the framework registers them for that
key — they arrive as the third and fourth argument of the registration. A key the
framework registers without actions gets none from the subclass either: the
subclass does not invent an action layer the base never had.

Why the whole chain and not the one class the project cares about: the layers are
coupled by class constants, and a subclass of one layer leaves the others
building the framework's classes. The project's column would then be hydrated
into an Entity nobody reads: the object collection would still build the base
Object over the base Entity, the view collection the base item, and the column
silently never reaches the object. The owner's word (2026-09-27): this is how it
is to be done; there is no "shorten the chain" mechanism, and none is proposed.

The lowest classes of the project's chain may be `final`: nobody subclasses them
further. The framework's classes may not — see
[../code-style/static-factories.md](../code-style/static-factories.md).

## Re-pointing The Links

Each link constant names the subclass, so that every layer builds the project's
class rather than the framework's:

| Constant | On | Names |
|---|---|---|
| `ENTITY_CLASS` | the entity collection | the project's Entity |
| `ENTITY_CLASS` | the object item | the project's Entity |
| `OBJECT_CLASS` | the object collection | the project's Object |
| `ENTITY_COLLECTION_CLASS` | the object collection | the project's entity collection |
| `DB_ITEM_CLASS` | the view collection | the project's view item |
| `OBJECT_COLLECTION_CLASS` | the view collection | the project's object collection |

Two constants stay the framework's and are NOT re-pointed:

- `Entity::_table` — the table is one. A subclass that names another table has
  left the framework's table, and this rule no longer describes what it did.
- `Objects::COLLECTION_KEY` — process-to-process synchronization is routed by
  the collection key (`DbSyncApplicator` looks the mounted collection up by it),
  so a subclass under another key is a second collection, not an extension.

Factories on the inheritable classes return `static` and build with
`new static(...)`, so an inherited factory constructs the subclass — the
contract in [../code-style/static-factories.md](../code-style/static-factories.md).

## Metadata And Verdicts

The Entity's metadata is read through `static::` (`_columns`, `_types` in the
save paths), so a subclass's constants are what the base code sees. The subclass
therefore COMPOSES its metadata from the base's rather than restating it:
`_columns`, `_types`, `_indexes`, `_foreign`, `_pii`, `_piiNotPersonal` each
start from the framework's declaration and add the project's columns. The exact
spelling of that composition is introduced by the leaf that opens the classes
(not in the code yet — HIL-1190); a subclass that copies the base's lists by
hand drifts from them on the base's next migration.

`_setVia` and `_setRoot` are inherited as they are: the table's set is a fact of
the table, not of the project's columns.

The project's columns arrive by a project migration, like any column of a table
whose DDL the project carries. The personal-data verdict on every one of them is
declared by the subclass — the same rule as for any Entity
([entity.md](entity.md), *The personal-data verdict*): a column added is a column
classified, in the class it was added to. The base's verdicts are inherited.

## Mounting Under The Framework Key

The subclass chain is mounted under the FRAMEWORK'S collection key — `identities`
stays `identities` — through an explicit substitution point, not by overwriting
the key after `parent::configure()` (not in the code yet — HIL-1190). Today
`DbContext::setRepresent()` overwrites a key in silence; a mount done that way is
one of the anti-patterns below. The name and the form of the substitution point
are introduced by HIL-1190; this page does not name them.

Once mounted, the machine sees the subclass and not the base (not in the code
yet — HIL-1190): the schema consistency audit checks the mounted subclass, so the
project's columns are its columns; the anonymization registry collects the
subclass's verdicts; the set-ownership guard reads the subclass's set
declaration. A base class that kept answering for a mounted subclass would pass
an unclassified project column through every one of those gates.

## What Refuses The Start

A node refuses to start over a half-extended framework entity, and says why
(not in the code yet — HIL-1191). The start guard reads constants alone — no
database, no Reflection — and refuses on each of:

- the chain is not inherited whole: a layer the framework registers for the key
  is still the framework's class, and the refusal names which layer;
- a link constant still names a framework class where the chain has a subclass
  for that layer;
- the subclass's table or collection key differs from the framework's;
- the subclass lost a column or a type of the base;
- two classes are mounted over one table.

On the verdicts of the project's new columns this page says only the general
rule above — the subclass declares them. Whether the start guard checks that in
a project that runs no backup is a question of HIL-1191's own interview and is
not decided here.

## Anti-Patterns

- Subclassing one layer — an Entity with the project's column and nothing else.
  The object collection keeps building the base Object over the base Entity,
  and the column never reaches a page. Subclass the whole chain.
- Mounting by overwriting the key after `parent::configure()`. It works by
  accident of order and says nothing when the framework re-registers the key.
  Mount through the substitution point.
- Restating the base's `_columns` / `_types` / `_pii` by hand in the subclass.
  The copy drifts from the base's next migration. Compose them from the base's
  constants.
- Mounting the subclass under another key, or pointing `_table` at another
  table. That is a second collection with a copy of the framework's shape, and
  the framework keeps writing the original.
- Keeping the project's columns for a framework table in a side table of the
  project's own. The framework table is the one to extend (owner's decision B).

## Related

- [entity.md](entity.md) — one mounted class per table; the verdict and the set
  an Entity declares.
- [../code-style/static-factories.md](../code-style/static-factories.md) — the
  `static` factory contract the inheritable classes keep.
- [../architecture/backup-anonymization.md](../architecture/backup-anonymization.md)
  — where a verdict is declared and how the registry collects it.
- [../architecture/truth-source.md](../architecture/truth-source.md) — who owns
  the collection the chain is mounted under.
- [../architecture/people-table.md](../architecture/people-table.md) — the
  first framework table a project extends this way: the person.
