# Signals: Work In Portions And What Reaches The Screen

Read this before writing work that runs over several ticks or handlers (a sweep,
a bulk action, a rebuild of a picture, an export), before sending a stream of
changes to an open page, or before writing records that only make sense together.

## Core Rule

A person acts on what the screen shows. Show a part only when it cannot be
taken for the whole. Never show the middle of a change to related records.
After a failure, start a new attempt that finds what is left in the data.

## The Stream To An Open Page

Send changes for an open page through the page's declared source. The worker
folds them per pass into one `page_response` per (connection, page), through
the [browser source fan-out](../architecture/browser-source-fanout.md#delivery).
Do not send them with `sendToUser()` or `sendToGroup()` in a loop.

Off the declared road, a frame still goes per change: a viewport table's row
delta, bulk progress per verdict, an explicit send. A section's own window —
logs aggregator 0.5 s, Daemon roster 0.5 s, node 5 s, collector 0.5 s — limits
how often the section recomputes its picture, not delivery. Do not add a section
window to throttle delivery.

Application text uses a separate queue on each TCP connection. Its window is the
positive `websocket.delivery_window_ms` setting (default 1000 ms), read through
the existing Settings catalog. Each ready worker reports its value to its node's
master, which applies it from a process-local memory mirror. The first nonempty
delivery after quiet goes in the next socket write pass. While busy, the next
deadline stays fixed from the previous nonempty delivery; producer writes do not
debounce it. Empty packets are never sent.

A validated browser application request hastens only its own connection.
Correlated action acknowledgements, `table_window` and `table_window_refused`
also hasten pending text. A tracked page subscribe or params update starts one
delivery grace window when its matching completion reaches the socket master,
so a delayed reply does not spend that grace before it arrives. Further stream
updates do not renew it. An already-open deadline or grace window keeps the
setting value with which it began; later decisions use the latest setting.

The HTTP 101 handshake, framework welcome, RFC control frames and binary
transport keep their transport paths. A session reply carried by
`HandshakeResponseSignalData` is an urgent application reply: it flushes queued
text and starts a fresh grace window on that socket, so a session-gated
`page_subscribe` does not wait for cadence. The DTO type selects this behavior;
the project signal name is arbitrary, and the wire shape is unchanged. An
active whole-subscription hold takes precedence over urgency: only its matching
completion releases the full response with accumulated text. Other ordinary
bootstrap notifications follow the delivery window unless independently
urgent; do not promise that the whole bootstrap is immediate. The batching and
safe coalescing rules are described in the
[wire protocol](../frontend/wire-protocol.md#signal-batches-and-delivery-coalescing).

The window does not rate-limit producers, replace the page's declared-source
fan-out, or make related business writes atomic. Queue and deadline state stay
private to the connection and its process; they are not domain RT state.

## Part Or Whole

Show work **as it goes** when every visible part is true on its own and nothing
missing passes for absent. In a sweep, a deleted row is deleted and a remaining
one is still there. A bulk action shows as a strip, under
[Showing work in progress](../frontend/table-subscription.md#showing-work-in-progress).

Show the **whole only** when a part makes the missing look absent: a cluster
picture, a total, an export file. Before the first whole, the screen says
"not known yet", never zero. Keep the last whole until the new one is ready.

Known exceptions: the Daemon agents and workers tables and the node page, and
the logs overview, draw the first portion of the cluster picture. The Daemon
overview waits for the full snapshot. The owner chose on 17.10.2026 to see it
live before deciding. The alternative — a part marked "still gathering" — is
not built; do not add it on your own.

## Related Records

Put related writes in one transaction in one handler. Their announcements leave
after the commit; see
[What waits for the commit, and what does not](../orm/transactions.md#what-waits-for-the-commit-and-what-does-not)
and [The transaction ends with its handler](../orm/transactions.md#the-transaction-ends-with-its-handler).
In portioned work, keep everything tied to one item in one portion and one
transaction. Never split a related change across ticks.

Put what cannot be rolled back — a file on disk, an outside call — after the
commit. An in-between state is allowed only when nobody can see it. Log a
failure after the commit and do not retry it. The example is
[`AbstractSessionsLibraryAgent::eraseAccount()`](../../../framework/backend/Auth/Library/AbstractSessionsLibraryAgent.php):
the rows leave together, and file removal and export cleanup follow the commit,
under [The Erasure](../architecture/account-deletion.md#the-erasure).

## After A Failure

Start a new attempt. Find what is left in the data, not at a saved place.
No cursor survives a restart; do not add one. Write a portion whole or not at
all, so a sweep simply selects what still lies there. See
[`AbstractUsersLibraryAgent::sweepVerifications()`](../../../framework/backend/Auth/Library/AbstractUsersLibraryAgent.php).

Build a whole result again from the start and drop the unfinished one unseen.
For [Export of acceptances](../architecture/legal-documents.md#export-of-acceptances),
a restart builds a preparing order again from its first line. The roster
builder likewise starts over when its source changes, in
[`DaemonManager::tickProcessRoster()`](../../../framework/backend/Core/Daemon/DaemonManager.php).

Do not promise exactly-once execution. An ambiguous write or an after-commit
step is not retried automatically; log it. A new attempt gets a new
[receipt](../architecture/change-log.md#one-scheduled-attempt-across-parts)
(HIL-1456). Each step must therefore be safe to repeat from the start.

## Anti-Patterns

- `sendToUser()` in a loop over changed rows → use the page's declared source.
- A section window to throttle delivery → use the delivery window (HIL-1565).
- A total drawn from the first portion → wait, or show "not known yet".
- A saved cursor to resume after a restart → select the remainder from the data.
- A related change split across ticks → keep it in one transaction.
