# Truth Source

Read this before deciding which agent writes a DB or RT collection, before
narrowing what a writer may do to the rows it holds, before declaring what an
agent or a framework seam reads, and when a write is refused with "no truth
source" or a read with "no reader interest". The machinery is
`framework/backend/Core/TruthSource/` (the grant, the operation axis, the
database registry), `framework/backend/TruthSource/RtTruthSourceRegistry.php`
for the runtime half, and `framework/backend/Core/Source/Interest/` for the
reader side.

Four words are fixed here, so that the next leaf does not coin its own. A
**claim** is one agent's statement that it owns a collection, wholly or by named
keys; a **grant** is what a registry keeps of it (`TruthSourceGrant`: the keys
and the operations). A **set** is the rows of a table cut out by the column its
Entity names in `_setVia` ([entity.md](../orm/entity.md)), and a **set key** is
the value of that column that names their owner: the notifications of one person
are a set of `hilos_notification`, and that person's id is its set key. Both
words belong to the third width a claim may have, *A Claim Over A Set*. Not the
*set of an entity* that [entity-libraries.md](entity-libraries.md) gives a
library — that is every row of the entity, and a set here is one slice of it.
*Truth source* and *owner* name the same thing — the one writer whose copy a
collection is — and this file says *owner*.

## Core Rule

A collection has exactly one full owner. Ownership is declared on the claimant's
class, the way an agent's reads are, and not made by a call inside a start hook:
what a class owns is a fact about the class. The claimant is usually an agent,
but not always — a test-only CLI command and the application class declare the
same way, and *Three Cases A Flat Constant Cannot Say* is where that is spelled
out. A claim of ownership is also the reader interest of its owner — the
collection an agent owns is a collection it reads, by the same statement and not
by a second one.

Everything else writes through the owner. A process that wants a row changed
and does not own it sends the owner a signal and lets the owner write; it does
not reach into the registry, and it does not list the collection among its reads
in order to write it. What the declaration looks like is in *Interest And
Ownership Are One Fact*; what it replaces is in *The Form That Is Gone*.

## Interest And Ownership Are One Fact

A claim raises the reader interest of its owner
(`SourceInterestRegistry::register()`, inside `OwnershipDeclaration::claimDb()`
and `OwnershipDeclaration::claimRt()`), and a claim that may add marks that
interest ready in the same movement (`SourceInterestRegistry::markReady()`).
Ready at once, because nothing is on its way: a writer that brings rows into
being holds the copy of what it writes, and on the database side the copy this
process caches lives only under an interest — a cache with anything in it means
a holder is registered and the frames are already coming, an empty one means
the next read goes to the database.

A claim that may not add is a **borrowed claim**, and it is not ready at once.
Its holder only edits rows somebody else brought into being, so it holds no copy
of them: its readiness arrives the way a reader's does — a snapshot for a runtime
collection, the master's acknowledgement for a database one, which drops the
cache read before it — and the worker waits for it at the start, beside
`READS_RT` and `READS_DB` and before the instance is built
(`OwnershipDeclaration::borrowedRtCollectionsOf()`,
`OwnershipDeclaration::borrowedDbCollectionsOf()`). The delivery channels are
the standing case: each edits the journal row of the attempt it runs
(`AbstractDeliveryChannelAgent`), while the notifications library adds and
prunes those rows. The operations are read folded — `BY_KIND` expanded and the
parents' records merged — so an heir that gives a borrowed record the right to
add owns the collection. A claim narrowed to named rows (`OWNS_DB_ROWS`,
`OWNS_RT_ROWS`) is never borrowed: the rows it names are its own. A claim over a
set is another matter, because the rows of a set are often brought into being by
somebody else: it is borrowed by the same test — no `Add` among its folded
operations — and waits at the start the same way. *A Claim Over A Set* has the
case.

The interest of a claim that may add is raised at the claim and not at the
report that follows it. An agent writing its first row inside `onStart()` reads
the collection before that report is built — the report goes out once the hook has returned
(`WorkerManager::handleAgentStart()`) — so an interest raised on the report
would refuse the owner its own first read.

`READS_DB` and `READS_RT` exist for somebody else's collection. A collection the
agent claims does not belong in them: the claim is the reader interest already,
and two lists for one fact would have to be kept in step. What belongs there is
what the agent reads out of a collection another agent owns.

That holds for a borrowed claim too, and this is the case that looks like an
exception. A holder of one operation over a collection somebody else owns reads
like a reader — it may update a row it never creates — and still an entry beside
its claim buys it nothing: the start already waits for the claim as it waits for
a read, under the same consumer and within the same deadline, and refuses the
same way when the state does not come. What makes that so is the start itself,
and not a coincidence of the tree: a borrowed claim over a collection the process
happens to hold from mounting (`RtContext::declareProcessWideReads()`,
`DbContext::processWideReadCollections()`) passes at once, because that hold is
waited on before the first agent is built
(`WorkerManager::handleWorkerRegistered()`), and one over a collection nothing
else here reads waits its round trip.

The rule is sufficient and not complete, and says so rather than promise
otherwise. A co-owner that may add rows as well holds no copy of the rows the
other owner wrote either, and the absence of `Add` does not catch it. The tree
has several — the cluster demo's `ClaimerAgent` holds `workerStatuses` whole
while every `WorkerAgent` holds its own row, and the chat demo holds five
collections whole under two or three owners at once (its
`Hilos::SHARED_DB_OWNERS`) — and none of them reads another owner's rows in its
start hook. No second form of borrowing exists for them until such a holder
does; a third constant beside `OWNS_RT` was weighed and turned down, because the
operations already say what borrowing is.

The declaration of ownership is a map, `OWNS_DB`, from a database collection key
to the operations the owner may perform on its rows, written on the class beside
`READS_DB`. The runtime half is `OWNS_RT`, the same shape over runtime collection
keys. Both live on the `TruthSourceOwner` interface, which is the one place they
are declared and the question a validator may ask of a class with nothing
running; `AbstractAgent` implements it, and so does everything else that may hold
a collection. A map and not a list of names, because some
claims narrow their operations and a list would need a second constant to say
so — two ways of saying one thing.

Beside each of them stands the same form at the other width: `OWNS_DB_ROWS` and
`OWNS_RT_ROWS`, on `AbstractAgent` rather than on the interface, for a
collection the owner holds not whole but by rows. A collection stands in exactly
one of the two maps of its half, and one named by both refuses the agent's
start — see *Three Cases A Flat Constant Cannot Say* for why the rows themselves
are not written there.

A third map joins them in the database half, `OWNS_DB_SET`, of the same form and
on `AbstractAgent` for the same reason, for a collection the owner holds by one
set of it. The maps of that half are then three and stay exclusive: a collection
stands in exactly one of them, and one named by two refuses the agent's start.
The runtime half has its third map, `OWNS_RT_SET`, the same way, and its three
maps are exclusive as well.

A record naming no operation gets `TruthSourceOperation::BY_KIND` and is answered
by the kind of the agent, which is the empty list under a name: a bare one would
say both "nothing may be done here" and "the set was never written".

## The Operation Axis

A right has two axes. The width of the claim says which rows are yours — the
whole collection, or keys named one by one; a third width, the rows of the set
its owner holds, is the next section's. The operations say what may be done with
them: `TruthSourceOperation::Add`, `TruthSourceOperation::Update`,
`TruthSourceOperation::Remove`. The two sit in one grant rather than in two
stores keyed by the same pair, because they are always answered together: a
refusal names both, and the guard that refuses a write says which operation it
refused along with the ones the source does hold.

A claim that names no operations gets `TruthSourceOperation::ALL`. Where that
default comes from is decided once per kind of agent, in
`AbstractAgent::defaultTruthSourceOperations()`: an ordinary agent owns its rows
outright and may do anything with them, and changing the answer for a whole
kind costs that one method and no walk of the call sites.

Narrowing is what a library does. `AbstractUsersLibraryAgent` answers with
adding and removing and never updating: a library brings a row into being and
takes it away again, but a fact another holder is keeping is not the library's
to reword, and it says what changed in a frame instead. One claim may still say
more than its kind does — the tables a library holds the commands for are edited
in place (a code is spent, a secret is rewritten, an attempt counter goes up), so
the claim over those tables names the update it performs.

The right to create is not a second mechanism. It is a claim of zero width — it
covers no rows — carrying the single operation `TruthSourceOperation::Add`
(`registerCreate()` on the database registry). Minting a record is not owning
one, and a grant limited to named rows cannot mint: a record that does not exist
yet is not among the rows it was given. Creation therefore asks for a grant that
allows adding and is not row-limited — the whole collection, or the mint-only
claim that owns no row at all.

Both runtime forms carry operations: the registry keeps them on every grant, the
declaration names them per collection, and the runtime seam takes them as its
third argument. That last one is recent, and it closed the one bypass this
document used to record — the chat's users library holds `connections` for
updating only, and until the seam had an axis to say so, it registered in the
registry by hand. Nothing in the tree goes round either seam today.

## A Claim Over A Set

The two widths above leave one owner unsaid: the agent that answers for one
instance — this person, this event — and owns what belongs to it. A claim over
the whole collection hands it every person's rows. A claim by keys makes it name
its rows one by one, and a row born after its start is not among them. The third
width is *the rows of my set*: a claim over one set of a table, named by its set
key.

The width was written down here before any of it was built, so that the leaves
building it cut by one answer, and it is built leaf by leaf. Every sentence
below that the code does not hold yet ends with the marker of the leaf that
lands it; a sentence with no marker describes the tree as it stands, or a
decision no code will change. The table closing the section says which leaf
lands what.

**The entity cuts the table; the agent names only its key.** Which column cuts a
table into sets is declared once, on the Entity, in `_setVia`
([entity.md](../orm/entity.md), *Whose set the table is part of*), and an agent
does not decide it again. It says two things, each in a form this page already
has: which collection it holds by a set, and which value of that column is its
own (owner's decision, 2026-09-19). The form turned down was an agent carrying
a predicate of its own — "my rows are those whose column Z equals my key". The
price of the choice is said rather than hidden: an agent cannot own a cut the
Entity did not declare — the chat's `EventMessage` is cut by `event_id`, so no
agent is given "the messages I wrote", although `author_user_id` lies on the
row — and a table that needs another cut changes its `_setVia`, for every reader
at once.

**The declaration does not come to depend on its reader.** HIL-862 left the gap
between the set declaration and the right open on purpose, and left a warning
beside it: the declaration is static and says whose set a table is part of, a
grant is runtime and says whose rows these are, and merging the two would make
the declaration depend on who reads it. This width does not merge them, because
it keeps their questions apart. *Which column cuts this table* stays static, on
the Entity, one answer for everybody. *Which value of that column is mine* stays
runtime, on the grant. No reader chooses the declaration, so nothing in it bends
to a reader.

**The sets of one table are a partition.** Every row reaches exactly one top of
its set tree, so every row stands in exactly one set, and whether two laid claims
meet is answered by comparing two set keys — no query, no look at the rows. Two
owners of different sets of one table are therefore lawful, and are the width
doing its work: the agent of each instance holding the rows of its own. Before
instances exist, the topology validator compares classes instead: a class has no
set key, one column cuts the table, and the keys of two classes come from the
same space, so two different classes holding sets of one table in full are
refused or recorded as a shared-owner debt. Instances of one class are not
compared; those are the different sets the width exists to hold. Under the form
turned down this would not be decidable without the database: two agents cutting
one table by different columns hold sets that overlap.

**The claim is written in two halves, like the narrow one.** WHICH collection is
held by a set is a constant on the class, `OWNS_DB_SET`, of the form
`OWNS_DB_ROWS` beside it has — a map from collection key to operations,
`TruthSourceOperation::BY_KIND` included — and on `AbstractAgent` rather than on
`TruthSourceOwner` for the reason the narrow width is: a command and the
application class have no instance to ask. WHICH set is a seam on the live
instance, `ownedDbSetKey(string $collection): string`, asked once, at the beat
`ownedDbRowKeys()` is asked. One key and not a list, on purpose: the table is
cut by one column, so an instance holds one set of it, and a plural seam would
quietly bring back the predicate that was turned down.

**A collection stands in exactly one of the three maps of its half.** `OWNS_DB`,
`OWNS_DB_ROWS` and `OWNS_DB_SET` are three widths of one claim, and a collection
named by more than one of them refuses the agent's start with
`ClaimWidthConflictException`, as one named by both of the two older maps does.
An empty set key refuses it too, with `ClaimedSetKeyMissingException` — the twin
of `ClaimedRowKeysMissingException`, and for its reason: a width of no rows is
already the right to create, so a set registered under no key would be a claim
over nothing that says so only at the first foreign write.

**Two floors refuse a set claim declared wrong.** The two refusals above belong
to the start of the agent, in `OwnershipDeclaration`, because they are what a
class and its instance can contradict between themselves. A third needs the
Entity and belongs to the topology: a set claimed in a collection whose Entity
declares `Entity::SET_STANDALONE`, a table cut by no column and so with no set
to claim — and so does a claim whose set tree climbs through a table that is not
mounted or that the claimant neither reads nor claims. It is judged in
`TopologyValidator::validateReferences()`: that half runs once the collections
are mounted and can walk from a mounted collection to its Entity, which is why
`validateBrowserJoinColumns()` is judged there. `SetOwnershipGuard` is not the
judge and gets no second subject. It answers whether a *table* declared its set,
and — where `_foreign` names the parent — whether that parent declared itself a
root, whether a declared `_setShortPath` is another column of a table in a set,
and whether the chain of parents ends rather than returning to itself; a claim
is an agent's statement, the guard reads no agents, and the width does not
repeat its cross-check. The runtime half has the twin of the topology floor: a
set claimed on a runtime collection whose row class names no `RtState::SET_VIA`
field is refused in the same pass, by `validateRtSetClaims()`, and there is no
climb to judge there — a runtime row has no tree.

**The width is a third named state of `TruthSourceKeys`.** Beside `all()` and
`listed()` stands the factory `TruthSourceKeys::set(string $setKey)`, with the
questions `coversSet(): bool` and `setKey(): string`, and the class docblock
names three states. Named `set` and not `bySet`: the factories of that class
call a width by the noun of what it covers. The set key is kept in a field of
its own rather than as a claim listing no row, because a claim listing no row is
already the right to create. The write door asks one question of every width,
`coversRow(string $key, array $setKeys): bool`, and each width answers it by its
own: the whole collection covers every row, named rows cover the keys they name,
a set covers the rows its key is carried by.

**Belonging is asked of the row's set column, not of its key.** The key of a row
says nothing about whose set it is in, so `TruthSourceKeys::covers()` has no
answer at this width: the guard is asked with the key at the top of the row's
set tree, reached from the value in its `_setVia` column, and compares it with
the set key of the grant. That value is already in hand at the door —
`DbActions::ensureCanWrite()` holds the object it is about to write, not only
its id, and so does every door that writes one row. Beside the id, the door
hands the guard the set keys the write touches as a closure
(`$this->touchedSetKeys(...)`): the top the row is stored under and the top an
unsaved edit moves it to, each once. The registry calls it only when a claim
over a set decides, at most once: reaching the top may read a parent that the
owner of the whole table never reads. A claim over a set covers the write only
when every key is its own. A row moved under another top is therefore not the
write of a set's owner — it writes into two sets, and only the owner of the
whole table moves it — and a row whose set column is empty is in nobody's set
and covered by no set claim. The two older widths do not look at the set keys. A
row born after the agent's start is covered by construction, because the grant
keeps a set key and not a list of rows collected at the start.

**One statement over one set asks for that set whole.** An UPDATE or DELETE cut
by one value of the set column — every notification of one recipient, every
backup code of one person — names no row, so the row door has nothing to ask,
and the collection door would demand the whole table. It asks
`DbWriteGuard::guardSetWrite()` instead, and the grant answers
`TruthSourceKeys::coversEveryRowOfSet()`: the whole table covers every set,
nobody's empty key included; a set covers its own; named rows cover none, since
the statement touches rows the claim does not name, born after it included. The
column comes from the Entity; the door is handed only its value, and beside it
the climb of that value (`SetTree::climb()`), which a claim over a set alone
asks for: it holds the statement when the value reaches its key at the top of
the tree. A statement across the table, and one moving rows between sets, still
asks the collection door, which keeps its one width. The living callers are
`Notifications::markAllReadForUser()` and the three `deleteForUser()` of the
second factor, through `DbActions::ensureCanWriteSet()`.

**The set tree is walked upward, by default and to any depth.** A set hangs on a
row that is itself in a set: a passkey credential is cut by `identity_id` and
the identity by `user_id`, so a credential is two steps from its person. A claim
over a set is laid by the key at the top of the tree — the person, the room — and
covers every row whose walk ends there (`SetTree::topOfSetKey()`). The parent of
a set column is the table its `_foreign` names; a soft reference, or a parent
whose rows are in nobody's set, is the top. The parent row is read by its stored
pointer through the guarded entrance, so a set claimant has to read every table
of the walk, and `validateSetClaims()` refuses its start otherwise; a row whose
parent is gone is in nobody's set. An Entity may declare `_setShortPath`, a
column that carries the top directly, and its rows answer without the walk — the
preferred form wherever such a column is kept true. `PasskeyCredential` walks
through its foreign key to the identity: its `user_id` stays undeclared until an
account merge keeps it true (HIL-1132). Owner's decision, 2026-09-19: declaring
the path is better and walking is allowed by default; forbidding the walk for a
direct column to the root was weighed and not chosen.

**A claim over a set may be borrowed.** The rows of a set are often brought into
being by somebody else: the agent of one person would edit that person's sign-in
methods while the users library goes on adding them, as it does today
(`AbstractUsersLibraryAgent::OWNS_DB` holds `identities` with every operation).
Such a claim carries no `Add`, and the test that exists already calls it
borrowed — `OwnershipDeclaration::isBorrowedClaim()` reads the absence of `Add`
off the folded operations and asks nothing about the width — so its holder waits
for the state at the start, beside its reads. The mechanism does not change with
the width. Only a claim by named keys is never borrowed, because the rows it
names are its own. Whether a person's agent is such a holder is not decided here
— applying the width to a person is epic HIL-1039.

**The runtime half is symmetric.** `OWNS_RT_SET` and
`ownedRtSetKey(string $collection): string` are declared, asked and refused as
the database pair is, and land in the same epic, so that a half one width behind
the other is not read later as a bug. A runtime row has no Entity to carry
`_setVia`, so the class of the row names the field that cuts its collection:
`RtState::SET_VIA`, the key of one of its `toArray()` fields, written by that
field's constant (`public const string SET_VIA = self::userId;`). The agent still
names only the value. The declaration is optional — a class naming none is cut
by no field, and a set claimed on its collection is refused by the topology. There
is no tree: the row carries its owner's key itself, which is the short path and
the only form. The row counts the keys a write touches, `RtState::touchedSetKeys()`
— the one it is stored under and the one it is edited to, each once — and every
runtime door hands them to `RtTruthSourceRegistry::checkCanWriteState()` as a
list; a row is born through the row door with `Add`, so a set claim brings into
being rows of its own set alone. There is no statement over many runtime rows: a
mass edit walks the rows, and each passes the row door. On a cluster a node
holding a set speaks for no row of it: the claim reaches the node map with no
key, the rows of the set travel as deltas of a partial owner, no snapshot of the
set is handed over and no foreign frame is refused. The limit is known and
accepted: a node that missed the creation of a row of the set does not learn it
until a set is handed over by snapshot, which is HIL-1116's (owner's decision,
2026-09-25).

| Piece | Lands with |
|---|---|
| the value of the width, and belonging asked of the row's set column | HIL-1109 |
| the declaration on the agent, the three exclusive maps, both floors of refusal, the borrowed set claim | HIL-1110 |
| the walk up the set tree, and the short path a row declares | HIL-1111 |
| what the creation door asks under this width | HIL-1112 — open, and not answered here |
| how one statement over many rows asks within one set | HIL-1113 |
| two owners of one set refused, and the receipt for a pair the project lives with | HIL-1114 |
| the runtime half | HIL-1115 |
| the width holding while its owners sit on different nodes | HIL-1116 |

## Who Reads The Declaration, And When

The worker reads the declaration off the class, before the instance exists.
`WorkerManager::agentReadsRt()` and `WorkerManager::agentReadsDb()` resolve the
agent type to its worker class through the topology (`Hilos::AGENTS`) and take
`READS_RT` and `READS_DB` from there; the worker raises the interest, waits for
the master's word that the state has landed, and only then creates the agent.
The wait parks the start, with the frames addressed to that agent, and the rest
of the worker's link is served meanwhile (HIL-1012).
So `onStart()` opens on a collection and not on the emptiness before one. The
borrowed claims of the class are taken in the same breath
(`WorkerManager::agentBorrowsRt()`, `WorkerManager::agentBorrowsDb()`) and
waited for in the same wait. When the state does not arrive, nothing has been
created yet and nothing has to be unwound but the interest: the worker releases
it and refuses the start (`AgentCreationFailedException`).

An unknown type reads nothing rather than raising. What a start does with a
type the topology does not know is decided by the factory a moment later, and
answering that question twice would put the refusal in the wrong place.

This is why ownership belongs on the class too. A call inside `onStart()` is
invisible to this reader: the hook runs after the instance exists, which is
after the question was asked, so a claim made there can be taken up only by
the agent that made it and never by the worker that is deciding whether to
build it.

The claims themselves are laid by one call, `OwnershipDeclaration::claimAll()`,
between the instance being built and its `onStart()`: both halves in every width
they have, whole collections off the class, and rows and the set key off the
instance.
`WorkerManager::handleAgentStart()` makes that call, and so does a harness that
starts an agent outside a worker — a test case's `startAgent()` — so the beat a
case runs under is the node's and cannot be copied in part. The call takes
nothing back when a claim is refused; the worker's catch around it gives back the
claims and the reader interest raised before them together.

The grant ends with the agent. After `onStop()` returns or throws,
`WorkerManager` unregisters the agent from both registries; a hook does not
unregister its own claims.

## Merging Up The Parent Chain

Ownership merges up the chain: a subclass that declares its own database
collections keeps every one its parents declared, and a collection both named
carries the union of the two operation sets
(`OwnershipDeclaration::dbCollectionsOf()`). The runtime map merges the same way
(`OwnershipDeclaration::rtCollectionsOf()`), through the same walk: both
resolvers hand one private method the constant to read.

Reading does not merge. A subclass declaring `READS_DB` replaces what its
parent declared, so one whose parent has a list of its own carries it:
`[...parent::READS_DB, …]`. The trap is silent — nothing complains, and the
reads the parent needed are refused at the moment they happen, in whatever
action reached for them. The rule is the same on every reading constant, the
agent's `READS_RT` and a page's own two alike: whichever one a subclass
writes, it replaces rather than adds to.

The two rules differ because the two misses cost differently. A lost read is
refused at the moment of the read, in the action that reached for it, and that
moment is usually the agent's own start — the defect is loud and early. A lost
claim refuses nothing at start: the guard sits on the write, and the write that
finds no owner may be a sweep an hour later, whose refusal is a line in a log.
Until then the collection stands without an owner, and nothing says so.

Resolving a declaration through the parents has a precedent in this repository.
`AbstractPage::REACH` is inherited, so a base answers for its whole branch and a
thin subclass writes nothing, and the guard that judges it walks the chain the
way PHP resolves an inherited constant — the class itself first, then its
parents (`SourceIndex::resolveConstant()`, used by `PageReachRule`). That is
the resolution reading gets — the nearest declaration wins and the walk stops;
the merge promised at the top of this section walks the same chain and keeps
everything it finds.

Merging also removed a fork. `AbstractSessionsLibraryAgent` used to claim two
waiter collections and the reservations table from `onStart()`, behind
`if ($this->hasSignInSurface())` — the base class deciding for the project. The
merged map made the fork unnecessary, and HIL-897 spent it: the base declares
only what every sessions library owns — the session set, the rotations, the
toast stacks and the identity rows a merge moves — and each project subclass
with a sign-in surface declares the three collections behind that question in
its own `OWNS_DB` and `OWNS_RT`. The verifier circle once took the same shape
and has since left it: while its table came only with `HilosFeature::BACKUP`,
the claim lived on the demos that declared the feature, because a class constant
has no feature flag to ask. When the circle moved under the freeze (HIL-1118)
its table became every freezing installation's, the question disappeared, and
`AbstractHilosIndexAgent` declares the circle itself — the merged map carries it
to every project subclass.

## Three Cases A Flat Constant Cannot Say

Three kinds of claim cannot be written as a constant on the agent class alone.
Each has its answer, and none of them is a second mechanism.

**The keys are known only to the live instance.** The chat's `BotAgent` claims
`botAgentStatuses` by its own bot id; the cluster's `WorkerAgent` claims
`workerStatuses` by its own worker index. A claim by keys is ownership of those
entities and not of the collection around them — every node runs members of the
same collection, each owning its own rows — and the class cannot know an id that
exists only once the instance is built.

So the claim is written in two halves. WHICH collection is held narrowly is a
constant like any other, `OWNS_RT_ROWS` or `OWNS_DB_ROWS`, which is what leaves
the width readable by a validator with nothing running. WHICH rows comes from a
seam on the instance, `ownedRtRowKeys()` and `ownedDbRowKeys()`, asked once at
the beat the whole-collection claims are laid down — between the instance being
built and its `onStart()`.

Two things refuse the agent's start there rather than being resolved. A seam
that names no row is not a claim over nothing: that width is already the right
to create, and a collection registered with no holder of its rows would say so
only at the first foreign write. And a collection named by both maps of one half
is a contradiction with no reading — the registry keeps one grant per
(collection, agent) pair and a repeated registration replaces it, so the order
of the two calls would otherwise decide the width in silence. Both are read on
the folded maps, so a parent contradicting its subclass is caught as readily as
a class contradicting itself.

A set key is known only to the live instance in the same way — an agent learns
whose agent it is when it is built — so a claim over a set is written in the
same two halves. WHICH collection is held by a set is the constant,
`OWNS_DB_SET`; WHICH set is the seam, `ownedDbSetKey()`, asked once at that same
beat. The same two things refuse the start there: a seam that answers with an
empty key, and a collection named by more than one of what are then three maps
of the half. The runtime pair, `OWNS_RT_SET` and `ownedRtSetKey()`, is declared,
asked and refused the same way. *A Claim Over A Set* has the width itself.

**The collection's name is given by the project, not by the class.** The
framework's `AbstractUsersLibraryAgent` needs the account table, under a name
only the project knows. It used to ask for the name at run time through a seam,
`usersCollection()`, and register the claim against the registry directly. The
project subclass declares it now, since it is the one that knows its name, and
the base declares only what it owns under names of its own — so the seam had no
caller left and went with the claim (HIL-897). The operations are spelled out
there rather than left to the kind: the library's default is adding and
removing, and a project that renames somebody edits the row.

**The claimant is not an agent at all.** A test-fixture CLI command such as
`UserTestSeedCommand` mutates a table from a process that has no agent, and the
chat's bootstrap writes the users collection for the same reason. Both declare
it the same way anything else does: `OWNS_DB` on the class, because both
implement `TruthSourceOwner`. The claim is laid by the runner —
`TestOnlyCommand::execute()`, which claims for the command class and for
`Hilos::appClass()` under one id, `TestOnlyCommand::TRUTH_SOURCE_ID`, and takes
both back in a `finally`. Not a separate entry for fixtures: that would be a
second form of the declaration for the sake of its rarest user (owner's
decision, 2026-09-05).

The runner is `execute()` and not `CliManager::run()` because the tests of these
commands construct one and call `execute()` directly, so a claim raised further
out would leave a command refused inside its own test; and because CliManager
runs commands whose process lives on afterwards. The application class is
claimed beside the command every time, not only for the seed, since splitting it
by command would be that second form again — the cost is that the project's
users collection stands claimed for the length of any test-only command, inert
and released with the rest. Taking the claim back matters even though a CLI
process dies at once: called from a test, the body returns into a process that
lives on, and the manual cleanup that used to cover it is gone.

One writer stands outside all three: the daemon master writes a framework
singleton under `RtTruthSourceRegistry::DAEMON_SOURCE_ID` by its own decision,
with no agent and no command behind the write. No class is started for it, so
there is no class to declare on, and it is not among the claimants this section
answers for.

## Readers Past The Agent

Some collections are read in whatever process happens to be running — whose
session is this, what is this setting — and are therefore named by no page's
topology and by no agent's `READS_DB`. Without a declaration those reads would
be refused in every worker that runs no page and no agent of its own, which is
most of them. The process-wide list is where a layer says so:
`DbContext::processWideReadCollections()` names them, and
`DbContext::declareProcessWideReads()` registers an interest for each under a
feature consumer. The framework's own entries are in `HilosDbContext` —
settings, identities, sessions, notifications, OAuth providers and the
verifier circle — and a project adds to the list by
overriding the method and calling the parent. The circle is here because the
freeze photographs it in the initiator's worker and any agent that asks for a
freeze is an initiator: the read runs in whichever process asked, and naming
it per initiator is the silent trap of `AbstractAgent::READS_DB` — a subclass
list replaces the parent's. HIL-1096 is the refused read this answers, taken
in a worker the circle's owner never shares. The runtime twin is
`RtContext::declareProcessWideReads()`, which declares every `RtState` item and
the connections collection as held here.

The rule of admission is the process, not the reader. A seam belongs here when
it answers a question in any process at all and so is named by nothing that
takes interest up and lets it go — a page subscription, an agent's start. A
page nobody subscribes to reads the same way and belongs here for the same
reason: its `READS_DB` — and its `READS_RT` beside it — would be taken up on a
subscription that never comes ([subscriptions.md](../signals/subscriptions.md)).
The interest is never given back — a seam has no end the way a subscription or
an agent does; it stops being read when the process stops. And the list is named
rather than derived: nothing in a mounted collection says who reads it.

The guard on reading stands wherever the application reads.
`DbContext::assertReadable()` refuses a mounted collection nobody here reads, and
says which of two defects it found — no consumer declared it, or one did and the
master's confirmation has not landed yet. It is called from the View layer's
`__get()` and from `DbContext::getObjectCollection()`, the way into the object
layer, so a read past the agent is judged rather than merely trusted (HIL-900).

The layer's own machinery goes by a second name, `DbContext::mountedObjectCollection()`,
which answers what is mounted without asking whether it is readable. Delivery
cannot be judged by the guard it feeds: applying an incoming row change is what
makes a collection readable in the first place, so a judged delivery would refuse
to deliver the very state it was refused for lacking. The two are told apart by
the name and not by the caller — reading the stack is out — and the familiar name
is the judged one, so a reader who reaches for it without thinking lands on the
guard. `DbContext::getDbItemCollection()`, which repairs a view cache after that
same delivery, is unjudged for the same reason.

The readers this guard found were named on their class rather than in the
process-wide list. A delivery channel agent reads the notification it is sending
(`AbstractDeliveryChannelAgent::READS_DB`), and the push channel also reads the
recipient's devices (`PushDeliveryChannelAgent::READS_DB`, which spells the
parent's list out because `READS_DB` is not merged up the chain). Both collections
belong to the notifications library, so a channel declares a read and never a
claim. The third is `AbstractSessionsLibraryAgent::READS_DB`, which names the
verifications: every handshake asks whether the code a session is parked on is
still alive, and that row belongs to the users library. It is declared without a
condition because a class constant has none to ask — a project that carries
sessions with no login mounts the collection all the same and never reaches it.

## Why The Unit Is A Collection

The unit of interest is the collection, not the row. For a page that is true by
nature rather than by convenience: a browser table has to hear of every change
in its collection, because a row created a moment ago may enter its selection,
and the filter and the sort that decide whether it does live on the server —
an interest narrowed to the rows the page already holds would miss exactly the
row that matters. `SourceReaderMap` records, per holder, the collections it
reads, and replaces the list whole on every report.

Narrowing to rows pays only under a profile that has not appeared: a hot lazily
loaded collection, many writes a second, several workers each holding a few
rows of it. Until it does, the gain is smaller than the cost of reporting to
the master on every lazy load (owner's decision, 2026-08-27, on HIL-750).

## What The Start Refuses

The topology validator reads the declarations off the classes before a single
process is built, and refuses four contradictions there (HIL-899, HIL-1114).

Two owners holding one collection in full, where full is the word the runtime
guard already uses: every operation, over rows that overlap. A claim over the
whole collection covers any rows, so a full owner beside a by-row or set owner
of the same collection is that same refusal rather than a second rule, when both
claims carry every operation. A set owner without `Add` beside the library that
creates the rows is a declared shape and not a collision. Two by-row claims and
a set-plus-row pair are not judged at all — which rows an instance holds, and
whether a named row lies in the set, only the instance and database know.

The width over a set brings a case that can be judged: two different classes
holding sets of one table in full are refused as potential owners of the same
set, or covered by the same `SHARED_DB_OWNERS` receipt of classes and debt as
other shared owners. No moment compares their set keys. The topology check runs
before the instances that carry those keys exist, and judges the pair of classes
because one column cuts the table and both keys come from its one space. Moving
the decision to runtime would require new database claim frames: the database
ownership registry is local to each process, while only runtime ownership has a
leader-side cluster claim registry that sees every agent's grants (HIL-696). The
price is explicit: classes whose keys are known by the project never to coincide
still need a receipt explaining why, while two instances of one class with the
same key are not caught, just as two by-row claims are not caught.

The width brings refusals of the topology as well, judged a moment later than
the four here, in `TopologyValidator::validateReferences()`, once the collections
are mounted: a set claimed in a collection whose Entity declares
`Entity::SET_STANDALONE`, and one whose set tree climbs through a table that is
not mounted or that the agent neither reads nor claims.
See *A Claim Over A Set*.

A class that names one collection both in its reads (`READS_DB`, `READS_RT`) and
in its claims: a claim is the reader interest already, so the second list says
the same thing in a form that can drift away from it.

A pair the project already lives with is written down instead of argued at every
start. `Hilos::SHARED_DB_OWNERS` and `Hilos::SHARED_RT_OWNERS` name the owners
of such a collection and, through `SharedOwnersKey::DEBT`, the leaf that will
part them. The list is a debt under lock, and it is read in both directions: a
colliding pair no row covers refuses the start, and a row whose owners no longer
collide refuses it too, so parting them for real takes the receipt away in the
same commit. What holds the length is the topology snapshot of each project — red
on any addition, silent on a removal, because nothing should stand in the way of
a debt getting smaller.

What it will not refuse is a collection without an owner. That check is
statically unreachable while claims come from things that are not agents — a
command, a bootstrap — and promising it would make a rule that lies about its
own completeness, which is worse than no rule. A collection without an owner is
caught where it is caught today: at the write, by the registry's guard, with
"no truth source registered".

One refusal belongs beside these although the validator does not make it: the
start of a single agent whose borrowed claim does not get its state within the
window a read gets (`AgentConstants::START_DEADLINE_SECONDS`) is refused
exactly as an undelivered read refuses it — see *Interest And Ownership Are One
Fact*.

## The Form That Is Gone

An agent used to claim in `onStart()`, through two helpers on its base class:
`AbstractAgent::registerDbTruthSource()` for a database collection and
`AbstractAgent::registerRtTruthSource()` for a runtime one. Each registered the
grant with its registry and raised the owner's reader interest, ready, in the
same call. Neither exists any more, and neither is kept as a deprecated second
way: a second way of saying one thing, with zero users left, is a fork every
reader has to learn and every guard has to allow. The order was the owner's
(2026-09-05): deprecate, then refuse a new call by guard, then remove.

What is left of that road is the registry itself. `TruthSourceRegistry::register()`
and `RtTruthSourceRegistry::register()` are the same form without the seam, and
they are refused in production code by a guard. Exactly four files may still
reach them: the resolver, which lays the claim down having read the constant off
the class, and the three registries, where `register()` is their own method and
an inside road to it is a claim being carried out rather than declared. A test
suite is not judged at all — an agent a test starts outside `WorkerManager` gets
no claim from a declaration, because the resolver runs on the worker's start
path, so there the call is the only form there is.

Checked automatically: `TRUTH-SOURCE-CLAIM`, see
[automated-checks.md](../code-style/automated-checks.md).

After `onStop()` returns or throws, `WorkerManager` takes the grants back — that
half never moved, and a declared claim is taken back the same way.

## Anti-Patterns

- **Writing into a collection you do not own.** Send the owner a signal and let
  it write; a write from anywhere else is refused by the guard, and a write that
  gets through by borrowing a claim makes two writers of one row.
- **Listing your own collection in `READS_DB` or `READS_RT`.** The claim is the
  interest already; the second list has to be kept in step with the first and
  will not be.
- **Declaring in a subclass and losing what the parent declared.** For reads,
  `READS_*` replaces — write `[...parent::READS_DB, …]`. For ownership the same
  loss used to be an `onStart()` override that did not call up: every claim the
  base made was gone, and nothing said so until a write was refused. *Merging Up
  The Parent Chain* is what closes that half — a subclass writes its own map and
  keeps everything above it.
- **Taking the right to create for a mechanism of its own** — a flag, a second
  registry, a special seam. It is a claim of zero width with the single
  operation `Add`, and the same guard judges it.

## Validation

`composer run test:framework:unit` — ownership read off the class and merged up
the chain, over each half (`DeclaredDbOwnershipTest`,
`DeclaredRtOwnershipTest`), over a claimant that is not an agent
(`DeclaredCommandOwnershipTest`) and over the narrow width with its two refusals
(`DeclaredRowOwnershipTest`), over the set width with the three exclusive maps,
the empty set key and the borrowed wait (`DeclaredSetOwnershipTest`), the one
call that lays every map (`DeclaredClaimAllTest`), the operation axis and the
guards on it (`TruthSourceRegistryTest`, `AgentTruthSourceOperationsTest`,
`DbWriteGuardLazyCollectionsTest`, the set door beside the collection door
there), the third width answered by the row's set column at the value, the
registry and the door, a row born after the declared start included, and one
statement over one set asked at the value and the registry
(`TruthSourceSetWidthTest`, with the set keys asked lazily and once), the same
width on the runtime half at the row, the registry and every runtime door
(`RtTruthSourceSetWidthTest`) and its declaration (the runtime cases of
`DeclaredSetOwnershipTest`), the walk
up the set tree, the short path, the parent that is gone and the statement over
a set below the top (`SetTreeTest`), the short path and the chain of parents the
startup gate refuses (`SetOwnershipGuardTest`), the set claimed on a table cut
by no column, through a table the claimant cannot reach, two classes holding
sets and a whole owner beside a set owner with and without shared-owner
receipts, the runtime set on a collection cut by no field and two classes holding
runtime sets, and the reads that repeat a claim (`TopologyValidatorTest`), the
grants a stop takes back
(`WorkerManagerStopCleanupTest`), the node-level map of runtime owners and the
set claim that speaks for no row there (`RtNodeSourceMapTest`), and the markdown rules that keep this file's links
intact (`AgentDocGuardTest`, `DOC-LINK`). The guard that refuses the call itself
is `TRUTH-SOURCE-CLAIM`, and is described in *The Form That Is Gone*.
