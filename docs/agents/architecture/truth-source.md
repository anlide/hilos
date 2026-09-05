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

A collection has exactly one full owner. Ownership is declared on the agent's
class, the way its reads are, and not made by a call inside a start hook: what a
class owns is a fact about the class. A claim of ownership is also the reader
interest of its owner — the collection an agent owns is a collection it reads,
by the same statement and not by a second one.

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
keys (not in the code yet — HIL-894). A map and not a list of names, because some
claims narrow their operations and a list would need a second constant to say
so — two ways of saying one thing.

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

The runtime registry keeps operations on every grant already, and so does the
runtime declaration (not in the code yet — HIL-894). What has no way to name
them today is the claim as an agent makes it through its runtime seam: that
seam takes no operations, which is why a runtime claim that narrows — the chat's
users library holding `connections` for updating only — bypasses the seam and
registers directly.

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
(not in the code yet — HIL-894).

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
and the two waiter collections the same way (not in the code yet — HIL-894),
while the base declares only what every sessions library owns.

## Three Cases A Flat Constant Cannot Say

Three kinds of claim cannot be written as a constant on the agent class alone.
Each has its answer, and none of them is a second mechanism.

**The keys are known only to the live instance.** The chat's `BotAgent` claims
`botAgentStatuses` by its own bot id; the cluster's `WorkerAgent` claims
`workerStatuses` by its own worker index. A claim by keys is ownership of those
entities and not of the collection around them — every node runs members of the
same collection, each owning its own rows — and the class cannot know an id that
exists only once the instance is built. The class declares statically WHICH
collection is held narrowly, and the keys come from a seam on the instance
(not in the code yet — HIL-895).

**The collection's name is given by the project, not by the class.** The
framework's `AbstractUsersLibraryAgent` claims the account table under a name
only the project knows (`usersCollection()`), so it registers directly and
names the table at run time. The project subclass declares it, since it is the
one that knows its name, and the base declares only what it owns under names of
its own (not in the code yet — HIL-897).

**The claimant is not an agent at all.** The test-fixture CLI commands register
under a `TRUTH_SOURCE_ID` of their own — a seed such as `UserTestSeedCommand`
mutates a table from a process that has no agent — and the chat's bootstrap
registers the users collection under `test-cli` for the same reason. The answer
is the same constant on the command class, raised by the runner that starts the
command (not in the code yet — HIL-896). Not a separate entry for fixtures: that
would be a second form of the declaration for the sake of its rarest user
(owner's decision, 2026-09-05).

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

The guard on reading stands on the View layer only. `DbContext::assertReadable()`
refuses a mounted collection nobody here reads, and says which of two defects it
found — no consumer declared it, or one did and the master's confirmation has
not landed yet. The object layer checks nothing: `DbContext::getObjectCollection()`
hands the collection back to whoever asks. The interest reaches the object layer
as well, so that a read there is judged and not merely trusted
(not in the code yet — HIL-900).

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
the chain (`DeclaredDbOwnershipTest`), the operation axis and the guards on it
(`TruthSourceRegistryTest`, `AgentTruthSourceOperationsTest`,
`DbWriteGuardLazyCollectionsTest`), the grants a stop takes back
(`WorkerManagerStopCleanupTest`), the node-level map of runtime owners
(`RtNodeSourceMapTest`), and the markdown rules that keep this file's links
intact (`AgentDocGuardTest`, `DOC-LINK`). The guard that refuses the call itself
is HIL-898's, and is described in *The Form That Is Going Away*.
