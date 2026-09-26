# Table Agents

Read this before answering a question about who serves a *table* — the surface
a page draws over one or several entities — rather than about the entities
themselves: who holds a viewer's window, who runs an action over "all rows
matching the filter", who says that one column of a composite row has fallen
behind, and what answers when nobody can. Read it too before declaring an agent
that draws a list, because the same page decides how that agent is addressed.

Five words are fixed here so the next leaf does not coin its own:

- **table agent** — the agent that answers for one table;
- **holder** — its running instance. The word is
  [entity-libraries.md](entity-libraries.md)'s for the running instance of a
  library, taken on purpose: the two approaches are read together, and a
  reader must not have to translate between them;
- **subject** — the entity the table is assembled around;
- **subject key** — the key of that entity; empty when the subject is a set
  rather than one instance;
- **window** — what one viewer sees of the table: filter, sort order, page, and
  the rows already handed over. The word is the one
  [../frontend/table-subscription.md](../frontend/table-subscription.md)
  already fixes.

The address of a table agent is the pair `(tableKey, subjectKey)`.

**Do not call the subject an "anchor".** The word is taken twice already, and a
third meaning would make it unreadable: `TableAnchorDTO` is the place a window
is addressed from — its edge keys — in
`framework/backend/Core/Router/TableViewportSubscription.php`, and the "anchor"
of a browser list is the source row a declarative list is scoped by
(`demo/chat/backend/Browser/List/ProfileIdentitiesBrowserList.php`).

**This document is an approach, not a description of code.** Nothing in it is
implemented: the tree has no table agent class, and today a table is assembled
by the browser context of whichever worker the connection happened to land in.
The names are fixed so that everything built against the approach is built
against the same words. What each leaf of epic HIL-782 owns is in *Open
Preconditions* and *What This Approach Does Not Decide*.

## Core Rule

A table has an owner. Its agent is addressed by the pair `(tableKey,
subjectKey)`: one holder per table per subject, cluster-wide. A viewer's window
is state inside that holder, not an agent of its own, and the viewer is not part
of the address — ten administrators looking at one list are served by one
holder, each with a window of their own.

The table agent reads and never owns: the rows it draws are written by the
figures that already own them — the entity's library for the set, the instance
owner for the row (owner's decision, 2026-09-06).

## The Unit: A Table And Its Subject

The unit is a table definition parameterized by the key of its subject. There
are two kinds of subject, and the address is the same pair for both:

| Subject | Subject key | Holders | Where the rows come from |
|---|---|---|---|
| a **set** — the list of every user in the admin | empty | one per installation | that entity's library ([entity-libraries.md](entity-libraries.md)) |
| an **instance** — my ways of signing in; the messages of one room | the instance's key | one per instance | the owner of that instance (epic HIL-626) |

Nothing else is a subject. The decision of 2026-09-06 fixes both rows in as
many words: the list of every user goes to the users library, and a table
opened inside one user goes to the agent of that user — and in both cases the
table agent stands beside that figure, not instead of it.

**Three figures, working in pairs.** The library answers for the set, the
instance owner for one row and its contents, and the table agent for the
surface: assembling a composite row from several sources, holding the windows
of its viewers, running a bulk action. On the users list the pair is library
plus table agent; on "my ways of signing in" it is that user's own agent plus
table agent. The table agent replaces neither of the other two, and this page
adds the third figure without rewriting the first two.

**The viewer is not in the address.** Ten administrators who open one list are
served by one holder; each of them gets a window — filter, sort, page, and the
digests of the rows already delivered — and the window is named inside the
holder. A second viewer does not raise a second agent: it attaches to the same
one. This is the sentence most often read backwards, so it is written first.

The rejected unit is the window: an agent per viewer per table. It was rejected
on three facts read off the tree, not on taste:

1. **A bulk action outlives the window that started it.** "All rows matching
   the filter" is a condition, not a list of keys, and the server judges every
   row and reports the untouched ones by name (HIL-799;
   [../frontend/table-subscription.md](../frontend/table-subscription.md),
   *Selection and bulk actions*). An agent whose unit is the window has nothing
   to run that action in — the window closes before the action ends, and there
   is nobody left to report.
2. **The window is addressed by the thing that dies first.** Today a
   `TableViewportSubscription` sits in the worker's `SubscriptionRegistry` under
   the connection's accept key
   (`framework/backend/Core/Router/SubscriptionRegistry.php:28-29`), and is
   erased when the page is subscribed again (`:55`), unsubscribed (`:146`), and
   when the connection goes (`:248`). Detaching such an agent from the
   connection does not save the unit — it turns it into an agent per *viewer*,
   which is a different answer, not a repair of this one.
3. **Interest in a source is declared per consumer**
   (`framework/backend/Core/Source/Interest/SourceInterestRegistry.php`). One
   holder per (table, subject) is one interest record per collection it draws
   from; an agent per window would register one per viewer, all reading the
   same collections.

What no longer decides this on its own is the count of processes. Since HIL-628
an instance agent comes up on the first frame addressed to it and stops after
an idle window (`AgentRegistryKey::IDLE_TIMEOUT`;
[agent-lifecycle.md](agent-lifecycle.md), *Idle stop*), so "an agent per window
would leak ten thousand processes" is not by itself true anymore. The answer
stands on the three facts above, and that is written down so the next reader
does not reopen it with the leak argument.

**The declaration lives on the table definition, not on the page.** Who serves
a table is said by the table
(`framework/backend/Core/Table/Definition/ViewportTable.php` and what implements
it), because a page hosts several tables — one table belongs to one page, one
page may host many ([../frontend/data-model.md](../frontend/data-model.md)) —
and they need not share a subject: a list of every user and "my ways of signing
in" on one screen are served by different holders. A declaration on the page
would fall apart on the first such screen. The page stays the gatekeeper:
level, freeze, declared params and guards are checked by `PageSignalRouter`
before any read ([browser-source-fanout.md](browser-source-fanout.md), *Page
Snapshots*); the table agent reads, it does not check a second time.

**The subject key is taken from the subscription params** the way the
instance-agent route already takes its index —
`AbstractPage::SUBSCRIPTION_AGENT_INDEX`,
`framework/backend/Core/Page/Config/PageAgentIndexKey.php` and
`framework/backend/Core/Topology/PageAgentIndexRouteRegistry.php` (HIL-627). An
empty declaration means a set subject: one holder per installation, routed as
a type. This approach introduces no addressing of its own; it says what the
existing address is parameterized by.

**The price, named.** The viewers of one table over one subject are served by
one holder, so a hot table with many open windows is a queue on one process,
and the composite rows are assembled there too. The address provides no split
finer than the subject; if one is ever needed it is a leaf of the epic, not a
switch on this rule.

The upper bound on live holders for set-subject tables is the number of table
definitions an installation registers — sixteen table classes in the tree
today, across the framework and three demos, the same order as the number of
entity libraries — and not the number of viewers. For instance-subject tables
the bound is held by the idle window, not by topology.

## The Window Is State, Not An Agent

Today the window lives in the process that holds the connection: the worker's
`SubscriptionRegistry` keeps one `TableViewportSubscription` per accept key per
table (`framework/backend/Core/Router/SubscriptionRegistry.php:28-29`) — the
descriptor a connection asked for and a digest of every row delivered to it —
and drops it on a fresh page subscription (`:55`), on unsubscribe (`:146`) and
when the connection is torn down (`:248`). Nothing outlives the socket.

Under this approach the window moves inside the table agent. It is named
there, per viewer, and it is what the holder judges every source change
against: a row that changed is in this window or it is not, and it is the row
this viewer was given or it is not. The digest-per-delivered-row shape the
worker keeps today is the shape the holder keeps tomorrow; what changes is who
keeps it.

What the move buys is the requirement the surface already makes: **after a
break the window comes back the same** — filter, sort and page in place, and
whatever had accumulated before the break dropped rather than replayed, because
the window that arrives outranks it
([../frontend/table-subscription.md](../frontend/table-subscription.md), *The
window changes only by explicit user action*). A window that lives in the
connection cannot do this; a window that lives in the holder can, because the
holder is still there when the viewer returns.

Two things are deliberately not said here: the name a window carries so that it
survives the accept key, and how long a window lives with no viewer attached.
Both belong to the window-ownership leaf of the epic (*What This Approach Does
Not Decide*).

## Reading Without Owning

The table agent is a reader. It declares interest in the collections it draws
from exactly as any other reader does — through
`framework/backend/Core/Source/Interest/SourceInterestRegistry.php`, the map of
who reads which collection that HIL-717 and HIL-750 made an announced fact —
and it learns of a change the way every declared reader learns of it: the fact
arrives, and the holder judges it against each of its windows.

It takes no claim. The rows are written by the figures that own them
([truth-source.md](truth-source.md); [entity-libraries.md](entity-libraries.md),
*Writing Without The Owner*): the library adds and removes rows of the set, the
instance owner edits its own row. A mutation a viewer sends from a table —
rename this user, delete these three — remains an action the owner performs;
the table agent does not intercept it and does not answer for it. The
collections it reads belong in its `READS_DB` / `READS_RT`, which is where an
agent names what it reads out of somebody else's collection, and in neither
`OWNS_DB` nor `OWNS_RT`.

This is the line the owner drew without discussion, and the reason is the rule
HIL-771 was written for: a table has one owner. A table agent that wrote rows
would make that rule come apart a second time — two writers for one
collection, one of them a surface.

## Bulk Work Outlives The Window

A bulk action belongs to the table agent, not to the window. The window starts
it — "delete all rows matching the filter" — and the holder runs it, judges
each row separately, and holds the report that names the rows it did not touch
and why. The run, the per-row verdict and the report are in the code — the run
is `framework/backend/Core/Table/Bulk/TableBulkRun.php`, walked a handful of
rows per worker tick by `PageSignalRouter` — but the HOLDER is not the figure
this page describes: today the run lives in the worker that took the action and
lasts as long as that connection does, and a table agent holding it is still a
leaf of HIL-782. The shape is `rowKeys` or `filter`, exactly one of the two; the
reply answers acceptance and carries the run's key, and `touched` and
`untouched: [{ rowKey, reason }]` arrive after it on a `table_bulk_report` frame
([../frontend/table-subscription.md](../frontend/table-subscription.md),
*Selection and bulk actions*). The outcome was given a frame of its own for
exactly the move this page is about: whoever ends up holding the run, the wire
does not change.

Two consequences follow, and both are the reason the unit is not the window. A
closed tab does not cancel the operation: the holder is still running it. And
the result is not lost with the tab: the holder still holds the report. How the
report reaches a viewer who left and came back is the window-ownership leaf's
question, not this one's; what this page fixes is who has the report to give.

The action itself stays an ordinary table action the page names, closed by the
page's level, and the write inside it is done by the entity's owner — the table
agent carries the condition to the owner and collects the verdicts, and that is
all *owning the bulk action* means here. The lock of the action does not move
([entity-libraries.md](entity-libraries.md), *The Lock Does Not Travel With The
Name*).

## Refusals

| Situation | What happens |
|---|---|
| no holder — not up, quorum lost, the node it lived on is gone | the table answers with a refusal, not with an empty list; the page around it may still be fine |
| one source of a composite row has fallen behind | the row is delivered, and the holder marks the lagging source inside it |
| a mutation arrives from a viewer | it goes to the owner of the row or of the set; the table agent does not write |

**An empty list where the holder is missing is forbidden.** `[]` is a statement
about the data — "there are no rows" — and the viewer cannot tell it from
"nobody could answer". This is the sentence [entity-libraries.md](entity-libraries.md)
writes in its *Refusals*, at the same boundary, and it is the finding the whole
epic was raised on: HIL-781 was six rows in the database, an empty list on the
screen, neither an error nor a log line — and nobody to ask, because the browser
context that assembled the list is no one's agent. Today a window that cannot
be built logs a line and answers `table_window_refused` (or lands in
`refusedWindows` of the page answer), so the tab draws "List unavailable" in
that table's body and leaves the rest of the page standing; the page-level
`subscription_page_error` is the wrong address for it — a page may carry two
tables, and one of them failing is not the page failing.

The refusal frame a table answers with, addressed by `(page, table)`, is
HIL-943's contract, and its first source is the window that could not be built.
The table agent becomes the second source of
the same frame: a holder that is missing answers exactly as a window that could
not be built does, and the epic raises no refusal leaf of its own.

**A window whose live road broke freezes and says so.** A window that was built
and then stopped receiving its changes — a change the table could not build for
it, a freshness move it could not build, any throw further down that one
window's live road — keeps its rows on the screen and tells its connection with
one `table_viewport_frozen` frame (`page`, `tableKey`, `since`, HIL-1139); the
tab says "not updated since" in the table's live line. The first delivery that
reaches the window without a throw brings it the whole window as a
`table_window`, and the mark goes away. This too is contained to the one window
of one connection: the page, its lists and the neighboring tables stay live.
The table agent inherits the frame as it is — the second source of the same
word, the way it is the second source of the refusal.

**A source that fell behind is marked, not hidden.** A composite row whose one
source stopped updating is still delivered, with the lagging source named
inside it; the cells of that source are marked, the rest stay live, and an
order over the stale column is refused
([../frontend/table-subscription.md](../frontend/table-subscription.md),
*Per-source staleness*). Who names the frozen source is who assembled the
fragment (HIL-800) — and under this approach that is the table agent, because
assembling the composite row is its job. This is not a lost connection and is
not answered as one: the transport is up and the other columns are true.

## Placement

A table agent declares the silhouette of a library: `AgentScope::CLUSTER` and
`AgentPlacement::POLICY` — one holder cluster-wide, on the node the placement
policy picks (the two axes are HIL-667's;
[entity-libraries.md](entity-libraries.md), *Placement Is Two Axes, Not One
Flag*). No new cell of the matrix is introduced; a table agent names an
existing one.

For a table over an instance subject the agent is `INDEXED` — one holder per
subject key, addressed by it, exactly as the instance owner it stands beside —
and it declares an idle window (`AgentRegistryKey::IDLE_TIMEOUT`;
[agent-lifecycle.md](agent-lifecycle.md), *Idle stop*). The first frame to the
address starts it; the number of live holders is held by the idle window, not
by topology, and the three conditions for stopping — nothing addressed it, no
live subscription, no work in flight — are already the framework's. An open
window is a live subscription: a holder with a viewer attached is not stopped
for idleness, and a bulk action still running is work in flight.

A table over a set subject is not indexed: it comes up from the bootstrap like
a library and lives as long as its worker does.

## Open Preconditions

Both preconditions are figures rather than mechanisms, and neither is built by
the leaf that wrote this page.

1. **The instance owner does not exist yet.** A table over an instance subject
   takes its rows from the owner of that instance (epic HIL-626), and the owner
   as a figure — the agent that owns one user's row and its child entities — is
   not built (not in the code yet — HIL-630). This page does not wait for it:
   it writes the approach. The first leaf of the epic that takes a table over
   an instance subject stands behind HIL-630; a table over a set subject does
   not, because the users library is in the code
   (`framework/backend/Auth/Library/AbstractUsersLibraryAgent.php`).
2. **The table agent does not exist yet either.** Today the reader of every
   table is the browser context of whichever worker holds the connection, and a
   declaration such as `ProfileIdentitiesBrowserList::BROWSER` — three sources
   and the rules for joining them — has no class, no method and no process
   behind it. That is why HIL-781 could fail with nobody to ask. The figure,
   its base class and its lifecycle are the epic's first leaves.

What is **not** a precondition: HIL-642, which decided *when* the backend
creates a window's subscription, not who owns it. It landed with the window
opened on the page subscription and its state in the connection process, so the
epic moves that state into the holder afterwards; that is the cost of a move,
not a wrong answer to the question this page answers.

## What This Approach Does Not Decide

The leaf that wrote this page delivers the approach and no executable code. The
epic (HIL-782) is sliced after it, on this answer; the pieces and where they
land:

| Piece | Where it lands |
|---|---|
| the lifecycle of a table agent — when it comes up, when it lets go, the base class | a leaf of HIL-782 |
| the name of a window that survives the accept key, and how long a window lives with no viewer | a leaf of HIL-782 (*window ownership*) |
| assembling a composite row inside the holder | a leaf of HIL-782 |
| the form in which a table agent declares its interest through the reader map | a leaf of HIL-782 |
| the refusal frame addressed by (page, table), and its first source | HIL-943; the holder answers with that frame and needs no leaf of its own |
| the bulk action's request, reply and per-row report | HIL-799 |
| behaviour on a cluster beyond the placement silhouette above | a leaf of HIL-782 |
| the composition of the epic's leaves | the decomposition of HIL-782 |

Also outside it: any framework code; the entity libraries and the instance
owners, beside which this page adds a third figure and which it does not
rewrite; the browser-source mechanics as a transport;
`docs/agents/frontend/table-subscription.md`, which this page links to and does
not edit.

## Anti-Patterns

```php
// Wrong: an agent per window - the unit is the viewer's connection.
public const array AGENTS = [
    AgentType::USERS_TABLE_WINDOW => [
        AgentRegistryKey::INDEXED => true, // indexed by accept key
    ],
];
```

The address is `(tableKey, subjectKey)`. A window is state inside the holder,
and an accept key is the thing that dies first.

```php
// Wrong: an agent per viewer - the same mistake with a longer-lived key.
AgentRegistryKey::INDEXED => true, // indexed by user id, one per administrator
```

Ten administrators of one list attach to one holder and get ten windows. The
viewer is not in the address.

```php
// Wrong: a table agent that owns the rows it draws.
public const array OWNS_DB = [HilosDbContext::identities => TruthSourceOperation::BY_KIND];
```

The table agent reads; the library and the instance owner write. What it reads
goes in `READS_DB` / `READS_RT`.

```php
// Wrong: an empty list when nobody could answer.
return [];
```

Answer with the table's refusal frame. An empty list is a claim about the data.

## Related

- [entity-libraries.md](entity-libraries.md) — the library, the figure that
  answers for the set; the words *holder* and *instance owner* are its.
- [agent-lifecycle.md](agent-lifecycle.md) — `onStart()` / `onStop()`, the idle
  window, and what addressing an agent does.
- [truth-source.md](truth-source.md) — who owns a collection and who reads it;
  the table agent is on the reading side.
- [browser-source-fanout.md](browser-source-fanout.md) — how a source change
  reaches a browser today, through the context this approach replaces as the
  reader.
- [../frontend/table-subscription.md](../frontend/table-subscription.md) — the
  window, its descriptor, selection and bulk actions, per-source staleness: the
  surface this approach owns on the server side.
- [../frontend/data-model.md](../frontend/data-model.md) — one table belongs to
  one page, one page hosts many; why the declaration is on the table.
