# Truth Source

Read this before deciding which agent writes a DB or RT collection, before
narrowing what a writer may do to the rows it holds, before declaring what an
agent or a framework seam reads, and when a write is refused with "no truth
source" or a read with "no reader interest". The machinery is
`framework/backend/Core/TruthSource/` (the grant, the operation axis, the
database registry), `framework/backend/TruthSource/RtTruthSourceRegistry.php`
for the runtime half, and `framework/backend/Core/Source/Interest/` for the
reader side.

Two words are fixed here. A **claim** is one agent's statement that it owns a
collection, wholly or by named keys; a **grant** is what a registry keeps of it
(`TruthSourceGrant`: the keys and the operations). *Truth source* and *owner*
name the same thing — the one writer whose copy a collection is — and this file
says *owner*.

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
Ownership Are One Fact*; what it replaces is in *The Form That Is Going Away*.

## Interest And Ownership Are One Fact

A claim raises the reader interest of its owner and marks that interest ready in
the same movement (`SourceInterestRegistry::register()` followed by
`SourceInterestRegistry::markReady()`, both inside the claim on
`AbstractAgent`). Ready at once, because nothing is on its way: a writer holds
the copy of what it writes, and on the database side the copy this process
caches lives only under an interest — a cache with anything in it means a holder
is registered and the frames are already coming, an empty one means the next
read goes to the database.

It is raised at the claim and not at the report that follows it. An agent
writing its first row inside `onStart()` reads the collection before that report
is built — the report goes out once the hook has returned
(`WorkerManager::handleAgentStart()`) — so an interest raised on the report
would refuse the owner its own first read.

`READS_DB` and `READS_RT` exist for somebody else's collection. A collection the
agent claims does not belong in them: the claim holds the copy already, and two
lists for one fact would have to be kept in step. What belongs there is what the
agent reads out of a collection another agent owns.

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

A record naming no operation gets `TruthSourceOperation::BY_KIND` and is answered
by the kind of the agent, which is the empty list under a name: a bare one would
say both "nothing may be done here" and "the set was never written".

## The Operation Axis

A right has two axes. The width of the claim says which rows are yours — the
whole collection, or keys named one by one. The operations say what may be done
with them: `TruthSourceOperation::Add`, `TruthSourceOperation::Update`,
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

## Who Reads The Declaration, And When

The worker reads the declaration off the class, before the instance exists.
`WorkerManager::agentReadsRt()` and `WorkerManager::agentReadsDb()` resolve the
agent type to its worker class through the topology (`Hilos::AGENTS`) and take
`READS_RT` and `READS_DB` from there; the worker raises the interest, waits for
the master's word that the state has landed, and only then creates the agent.
So `onStart()` opens on a collection and not on the emptiness before one. When
the state does not arrive, nothing has been created yet and nothing has to be
unwound but the interest: the worker releases it and refuses the start
(`AgentCreationFailedException`).

An unknown type reads nothing rather than raising. What a start does with a
type the topology does not know is decided by the factory a moment later, and
answering that question twice would put the refusal in the wrong place.

This is why ownership belongs on the class too. A call inside `onStart()` is
invisible to this reader: the hook runs after the instance exists, which is
after the question was asked, so a claim made there can be taken up only by
the agent that made it and never by the worker that is deciding whether to
build it.

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
action reached for them.

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

Merging also removes a fork. `AbstractSessionsLibraryAgent::onStart()` claims
two waiter collections and the reservations table only behind
`if ($this->hasSignInSurface())` — the base class deciding for the project. With
a merged map the fork is unnecessary: the project subclass that has a sign-in
surface declares the reservations table itself (not in the code yet — HIL-897)
and the two waiter collections the same way (not in the code yet — HIL-897),
while the base declares only what every sessions library owns.

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

**The collection's name is given by the project, not by the class.** The
framework's `AbstractUsersLibraryAgent` claims the account table under a name
only the project knows (`usersCollection()`), so it registers directly and
names the table at run time. The project subclass declares it, since it is the
one that knows its name, and the base declares only what it owns under names of
its own (not in the code yet — HIL-897).

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
settings, identities, sessions and notifications — and a project adds to the
list by overriding the method and calling the parent. The runtime twin is
`RtContext::declareProcessWideReads()`, which declares every `RtState` item and
the connections collection as held here.

The rule of admission is the process, not the reader. A seam belongs here when
it answers a question in any process at all and so is named by nothing that
takes interest up and lets it go — a page subscription, an agent's start. A
page nobody subscribes to reads the same way and belongs here for the same
reason: its `READS_DB` would be taken up on a subscription that never comes
([subscriptions.md](../signals/subscriptions.md)). The interest is never given
back — a seam has no end the way a subscription or an agent does; it stops being
read when the process stops. And the list is named rather than derived: nothing
in a mounted collection says who reads it.

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

The topology validator knows nothing of ownership today: `TopologyValidator`
mentions neither registry. With the declaration on the class it gains two
refusals at start. Two static claims on one collection with overlapping
operations that are not declared as co-ownership are refused before the first
agent runs (not in the code yet — HIL-899). An agent that lists in `READS_DB` or
`READS_RT` a collection it owns itself is refused the same way
(not in the code yet — HIL-899).

What it will not refuse is a collection without an owner. That check is
statically unreachable while claims come from things that are not agents — a
command, a bootstrap — and promising it would make a rule that lies about its
own completeness, which is worse than no rule. A collection without an owner is
caught where it is caught today: at the write, by the registry's guard, with
"no truth source registered".

## The Form That Is Going Away

Today an agent claims in `onStart()`, through two helpers on its base class:
`AbstractAgent::registerDbTruthSource()` for a database collection and
`AbstractAgent::registerRtTruthSource()` for a runtime one. Each registers the
grant with its registry and raises the owner's reader interest, ready, in the
same call; a claim written against the registry directly
(`TruthSourceRegistry::register()`, `RtTruthSourceRegistry::register()`) is the
same form without the seam. After `onStop()` returns or throws, `WorkerManager`
takes the grants back.

The helpers are not kept as a deprecated second way. Once every live claim is
declared (HIL-897) no caller remains, and a second way of saying one thing with
zero users is a fork every reader has to learn and every guard has to allow.
The order is the owner's (2026-09-05): deprecate, then refuse a new call by
guard, then remove. A code-style guard refuses a new call to either helper, and
the helpers are removed (not in the code yet — HIL-898).

## Anti-Patterns

- **Writing into a collection you do not own.** Send the owner a signal and let
  it write; a write from anywhere else is refused by the guard, and a write that
  gets through by borrowing a claim makes two writers of one row.
- **Listing your own collection in `READS_DB` or `READS_RT`.** The claim is the
  interest already; the second list has to be kept in step with the first and
  will not be.
- **Declaring in a subclass and losing what the parent declared.** For reads,
  `READS_*` replaces — write `[...parent::READS_DB, …]`. For ownership the same
  loss is an `onStart()` override that does not call up: every claim the base
  made is gone, and nothing says so until a write is refused. *Merging Up The
  Parent Chain* is what closes the second half.
- **Taking the right to create for a mechanism of its own** — a flag, a second
  registry, a special seam. It is a claim of zero width with the single
  operation `Add`, and the same guard judges it.

## Validation

`composer run test:framework:unit` — ownership read off the class and merged up
the chain, over each half (`DeclaredDbOwnershipTest`,
`DeclaredRtOwnershipTest`), over a claimant that is not an agent
(`DeclaredCommandOwnershipTest`) and over the narrow width with its two
refusals (`DeclaredRowOwnershipTest`), the operation axis and the guards on it
(`TruthSourceRegistryTest`, `AgentTruthSourceOperationsTest`,
`DbWriteGuardLazyCollectionsTest`), the grants a stop takes back
(`WorkerManagerStopCleanupTest`), the node-level map of runtime owners
(`RtNodeSourceMapTest`), and the markdown rules that keep this file's links
intact (`AgentDocGuardTest`, `DOC-LINK`). The guard that refuses the call itself
is HIL-898's, and is described in *The Form That Is Going Away*.
