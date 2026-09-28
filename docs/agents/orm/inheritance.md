# ORM: Extending A Framework Entity

Read this before a project needs a column of its own on a framework table, before
subclassing any concrete ORM class of the framework, or before mounting such a
subclass under a framework collection key. The rule is written ahead of its code:
every sentence about a mechanism that does not exist yet carries a marker naming
the leaf that lands it, and that leaf clears the marker in the same commit
([../rule-authoring.md](../rule-authoring.md), *A Rule Written Ahead Of Its Code*).

## Core Rule

Every concrete ORM class of the framework is designed for inheritance, and none
of them is `final`; the `ORM-CHAIN-OPEN` guard refuses one that is
([../code-style/automated-checks.md](../code-style/automated-checks.md)). A
project that needs something of its own on a framework table extends THAT
table with its own columns — it does not open a side table of "additions" next
to it (owner's decision B of the epic, 2026-09-24).

Extending a framework table means subclassing its whole chain, re-pointing the
links between the layers at the subclasses, and mounting the chain under the
framework's own collection key. One layer alone is not an extension; see
*Anti-Patterns*.

The mount point is a method of the database context, overridden the way
`processWideReadCollections()` is (owner's decision, 2026-09-27); see *Mounting
Under The Framework Key*. Two precedents already in the tree show the shape of
an inheritable framework class: the inheritable RT row — `HilosSessionConnection`
is the framework base and the project adds its hooks
([../runtime/rt-state.md](../runtime/rt-state.md), *The base half of the row
cannot be skipped*) — and the class swapped through a facade constant
(`Hilos::ADMIN_AUDIENCE`, read through `static::appClass()`).

The first live consumers: the chat demo's subclass of the framework's person
table keeps `merged_into` on it (`demo/chat/backend/Database/Entity/Item/User.php`)
until the merge tombstone table takes it over (HIL-1199); the chat demo's rename
journal is a
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

A framework class of the chain builds the rows of its own chain only through the
link constants — `static::OBJECT_CLASS` for the object, and for the Entity behind
it `static::entityClass()` on `Objects`, which reads `ENTITY_CLASS` off that
object — never by naming the framework class. An inherited search that named it
would hand a subclass the base object, which `Objects::offsetSet()` drops in
silence and `DbCollection::createDbItem()` refuses, and the project's column
would never reach a row. Reading a constant off the framework Entity —
`EntityX::_table`, a column name — stays legal, since no row is built. The
`ORM-CHAIN-OPEN` guard refuses both a `final` on a chain class and a row built by
name, and it judges the framework alone: the lowest classes of a project's chain
may be `final`, and a project builds its own chain as it likes.

## Metadata And Verdicts

The Entity's metadata is read through `static::` (`_columns`, `_types` in the
save paths), so a subclass's constants are what the base code sees. The subclass
therefore COMPOSES its metadata from the base's rather than restating it:
`_columns`, `_types`, `_indexes`, `_foreign`, `_pii`, `_piiNotPersonal` each
start from the framework's declaration and add the project's columns. The
spelling is the constant expression's own unpacking:

```php
public const string note = 'note';

public const array _columns = [...parent::_columns, self::note];
public const array _types = [...parent::_types, self::note => PhpType::STRING->value];
public const array _pii = [...parent::_pii, self::note => AnonymizationStrategy::NULLIFY];
```

and the same for `_indexes`, `_foreign` and `_piiNotPersonal` where the project
adds to them. `_table`, `_primary` and the collection key are inherited and not
restated. A table the framework purges whole (`_pii = AnonymizationStrategy::PURGE`)
stays purged whole: the subclass restates neither `_pii` nor `_piiNotPersonal`,
and the purge covers its column too. A subclass that copies the base's lists by
hand drifts from them on the base's next migration. The test chain over the
verifier circle, `framework/tests/Unit/Database/Fixtures/Extension/`, is the
worked example of the whole chain.

`_setVia` and `_setRoot` are inherited as they are: the table's set is a fact of
the table, not of the project's columns.

The project's columns arrive by a project migration, like any column of a table
whose DDL the project carries. The personal-data verdict on every one of them is
declared by the subclass — the same rule as for any Entity
([entity.md](entity.md), *The personal-data verdict*): a column added is a column
classified, in the class it was added to. The base's verdicts are inherited.

## Mounting Under The Framework Key

The subclass chain is mounted under the FRAMEWORK'S collection key — `identities`
stays `identities` — through `HilosDbContext::frameworkExtensions()`, a method the
project's context overrides the way it overrides `processWideReadCollections()`.
It answers, per framework key, one `FrameworkExtension` naming the project's view
collection and, where the framework registers them for that key, the project's
collection actions and item actions. The view names the rest of the chain
through its own constants, so nothing else is declared:

```php
protected function frameworkExtensions(): array
{
    return [
        ...parent::frameworkExtensions(),
        self::verifierCircle => new FrameworkExtension(
            NotedMembers::class,
            NotedMembersActions::class,
            NotedMemberActions::class,
        ),
    ];
}
```

`HilosDbContext::configure()` reads the declarations once and mounts every
framework key with the chain that answers for it. The loading strategy stays the
framework's whichever chain is mounted: how a table is read is its owner's
decision, not the subclass's. The mount refuses, with
`FrameworkExtensionException` and the node or CLI not starting, what the
declaration got wrong on its own: a key the framework does not mount, a value
that is not a `FrameworkExtension`, a class that does not extend the framework's
class of the same layer under that key (the framework's own class included), and
an action layer declared for a key the framework registers without one. An
action layer left undeclared stays the framework's — a half-inherited chain,
which the start guard refuses (*What Refuses The Start*), not the mount.

Overwriting the key after `parent::configure()` no longer works at all:
`DbContext::setRepresent()` refuses a second view under a mounted key with
`CollectionAlreadyMountedException`, naming the substitution point.

Once mounted, the machine sees the subclass and not the base. The anonymization
registry and the set-ownership guard walk the mounted collections, so they read
the subclass's verdicts and its set declaration with no step of their own; the
schema consistency audit is handed the mounted class by
`EntitySchemaAudit::mountedClassOf()` — the subclass mounted over the same table
when there is one, the class asked about otherwise — which is how a demo's
schema test audits a framework Entity the demo extended, and the project's
columns are its columns. A base class that kept answering for a mounted
subclass would pass an unclassified project column through every one of those
gates. The framework's extension integration test writes a row through the
framework's own action and reads it back through the framework's own finder,
and asks all three gates over the test chain.

## What Refuses The Start

A node refuses to start over a half-extended framework entity, and says why:
`FrameworkExtensionGuard::assertMountedExtensionsWhole()` throws
`IncompleteFrameworkExtensionException` with every finding in one message,
because the reader is the author of the chain and one edit answers all of them.
The guard judges what is MOUNTED, not what was declared — the framework's own
registration under each key, read through
`HilosDbContext::frameworkRegistrations()`, is held against the chain the
context mounted there — and it reads constants and the mounted map alone: no
database, no Reflection. A key counts as extended when the view mounted under
it is not the framework's. The refusals:

- the chain is not inherited whole: one of the eight layers is still the
  framework's class — a mounted one (the view collection, and the action layers
  where the framework registers them) or one of the five reached through the
  link constants. The refusal names the layer, the constant and the class that
  carries it, and asks for both steps at once — subclass it and point the
  constant at the subclass — because without Reflection a subclass the constant
  does not name is invisible, and the cure is the same either way;
- the two constants naming the Entity disagree: `ENTITY_CLASS` on the entity
  collection and `ENTITY_CLASS` on the Object have to name the one Entity of
  the chain, or searches build one Entity and objects another;
- a framework key is written over: the object collection mounted under the key
  is not the one its view names, which is a mount that went around
  `frameworkExtensions()`. Asked of every framework key, extended or not;
- the subclass did not keep the base's declaration — one rule behind both "the
  table and the collection key stay the framework's" and "no column or type of
  the base is lost": `_table`, `_primary`, `_setVia`, `_setRoot`,
  `_setShortPath` and a purge strategy in `_pii` keep their value; `_columns`
  and `_piiNotPersonal` keep every element of the base's; `_types`, `_foreign`,
  `_indexes` and a `_pii` map keep every key of the base's with the same value
  under it; `Objects::COLLECTION_KEY` stays the same. Adding is allowed
  anywhere but on the scalars, and the refusal names the constant and what was
  lost or changed;
- a column carries two verdicts, or a column the subclass added carries none:
  no column is in both `_pii` and `_piiNotPersonal` — asked over the whole
  verdict, so a base column the subclass adds to `_pii` while the inherited
  `_piiNotPersonal` still lists it is caught — and every column of the
  subclass's `_columns` beyond the base's is in one of the two; a table purged
  whole covers them all and is not asked;
- two chains are mounted over one table — asked over every collection the
  context mounted and not only the framework's keys, so a subclass mounted
  under a key of its own beside the framework's chain is caught here.

The verdict on a new column is asked in EVERY project, backup or not (owner's
decision at the HIL-1191 interview, 2026-09-27). The framework keeps its own
tables classified whole without any condition on backup —
`FrameworkEntityPiiVerdictTest` holds every column of every framework Entity to
a verdict — and a subclass mounted under the framework's key IS that table to
the machine: one column without a verdict leaves it half-classified. The
verdict also has a reader past the backup, the admin view mode
([../architecture/admin-view-mode.md](../architecture/admin-view-mode.md)): it
shows a column when `_piiNotPersonal` names it and hides one `_pii` names, and
a column in neither is hidden even where its author would have called it
harmless. The check costs nothing: constants only.

Where it stands: `DaemonApplication::run()`, first of the startup guards,
because the set-ownership guard, the anonymization registry and the schema
audit all read the mounted Entity, and over a half-extended chain they would
judge the framework's class in the project's place and say nothing
([../architecture/daemon-lifecycle.md](../architecture/daemon-lifecycle.md)).
Only the daemon carries it: the worker inherits the decision, and the CLI is
where a chain is repaired. Each demo asks the same question in its topology
unit test, `testFrameworkExtensionsAreWhole()`, over its own context and
without a database, so a refused start is named in seconds rather than as a
stand that did not come up.

What it stays silent about: a framework key nobody extended, and a project's
own tables, which are no chain over a framework key; a column the project's
migration added to a framework table without mapping it in the subclass's
`_columns` — only a live schema knows about it, and `AnonymizationStartupGuard`
judges it where the project takes backups.

## Anti-Patterns

- Subclassing one layer — an Entity with the project's column and nothing else.
  The object collection keeps building the base Object over the base Entity,
  and the column never reaches a page. Subclass the whole chain.
- Mounting by overwriting the key after `parent::configure()`. It used to hold
  by accident of order and say nothing when the framework re-registered the key;
  now `setRepresent()` refuses the second mount. Declare the chain in
  `frameworkExtensions()`.
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
