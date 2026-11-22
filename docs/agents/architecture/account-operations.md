# Account Operations: Erasure And Merge Across Owners

Read this before designing or cutting work on account erasure and merge across
owners, before giving a library, a channel or the sessions holder a write it
would hold only for one of those operations, and before deciding who the single
writer of a person's row is after that row has been created.

**This document preserves the approved research of HIL-1675 and now records
the three implementation phases agreed in HIL-1888, HIL-1889 and HIL-1890.**
The detailed erasure and merge norms are recorded here, separately from the
leaves that prepare their mechanisms and their single live switches. Erasure has
run through its owners since HIL-1952, and merge since HIL-1999.
This documentation adds no callable API, right, table or frame. The
erasure and the merge that run in the tree are the ones described in
[The Erasure](account-deletion.md#the-erasure),
[A Merged Account](people-table.md#a-merged-account) and
[Operations Over Many Instances](instance-owners.md#operations-over-many-instances),
and an executor working on today's code follows those pages. What this page
adds is the agreed target, the facts of the present that the target replaces,
and the single changing writer of each row in the tables they touch — so that
the leaves which build it do not choose again between the product options the
owner has already rejected.

## Scope And Status

**What the document settles.** The order of an erasure and of a merge without
one transaction across other owners' tables: which fact is recorded and by
whom, which signal every owner receives, what each owner deletes or transfers
itself, when an erasure counts as finished, what is visible in between, and
what an owner that slept through the signal does when it starts. For each of
the 20 framework tables and the 4 chat tables the sessions holder writes today
it names the single writer, and beside them the holder's own tables and the
service records the operation needs. It names the price of every piece and the
historical inputs to the cut of HIL-1674. That cut now has named leaves:
ordinary moves below, then erasure and merge. This page records their approved
boundaries; it does not create or edit tickets.

**What it covers and what it does not.** The database only (owner's decision,
2026-10-18, rendered below). Runtime collections are a separate matter and are
not re-cut here. A file on disk, an outside service and a frame in flight get no
new guarantee from a protocol over database rows; the existing post-commit
steps — the files library's removal, the export agents' file sweeps — stay
what they are and are not declared automatically repeated. No new DB ownership
width or mode is introduced: a claim is still over the whole collection, over
named rows or over one set ([truth-source.md](truth-source.md)). The mint-only
claim stays what it is — the right to bring a row into being, not the right to
edit one that exists — and the library that builds a complex entity with its
children does not edit what it built. An executor's own working rows stay the
executor's: a separate and lawful responsibility, not a content edit.

**Status of the words below.** The ordinary norms name their implementing
leaves with the literal markers required by
[rule-authoring.md](../rule-authoring.md), *A Rule Written Ahead Of Its Code*.
HIL-1952 connected the erasure participants to the live path and retired the
old erasure writes. HIL-1999 connected the merge participants to the live
administrator and CLI paths and retired the old merge writes. Operation,
assignment, preparation, result, receipt and copy name logical obligations,
authorship and order. HIL-1936 and HIL-1937 introduced the erasure operation,
assignment and result storage. HIL-1987 gives them common `AccountOperation*`
carriers for erasure and merge, and adds the merge opening record and its
initial assignments. It does not add a second operation journal or participant
catalog. HIL-1952 connected the erasure participants and HIL-1999 connected
the merge participants to those shared records. No reservation field, receipt
field, admission metadata, partition, frame or business stage beyond the
initial `preparing` storage value is declared as an API here. The contract gate
stands unchanged.

**Where the facts come from.** The historical census was read at research base
`1d579875e`. HIL-1891's documentation base is `a48d6239b`; the current claims,
expanded operations and call sites below were checked again at `f6a5f2e8f`
on 2026-11-07. Merge facts were rechecked at `e473fd55c` on 2026-11-08:
account-pair validation, password settlement and collision, the immediate stop
on a merge row, the set guard, DB sync application, the CLI and tracked action,
and chat's project seam. The historical base is kept as
evidence, not presented as a fresh audit. Current implementation facts are
not an approved future contract: where later code differs, update the facts
and clear only the markers whose behavior actually landed.

## Three Phases

| Phase | Boundary and remaining writers | Documentation and live switch |
|---|---|---|
| 3.1 — ordinary writes (HIL-1888) | One changing writer after creation for each DB row; ordinary overlaps are exhausted by the named moves below. Only the explicitly named old erasure and merge paths remain afterward. RT ownership, new DB widths and a re-cut of `_setVia` are outside the phase. | HIL-1891 records the norm; HIL-1892 defines the strict judge and executable closed debt list; HIL-1893–HIL-1910 land the ordinary moves. HIL-1911 aligns applicable e2e. Existing restriction integration covers the move; failover 4/19 is recorded, and cold person 39 remains quarantined as F-015. Binance scenarios 58–60 add the erasure cluster and backup evidence described in [Recovery](#recovery). |
| 3.2 — erasure (HIL-1889) | HIL-1952 switched the due sweep and force-purge to owner-local work, removed erasure debt and retired the old erasure writes, keeping rights still needed by merge. Participants commit their own work and results; unfinished operations resume from durable facts. | HIL-1935 records the detailed [erasure norms](#erasure); HIL-1936–HIL-1951 prepared the records and participants, and HIL-1952 connected them to the live path. HIL-1963 aligns the remaining e2e; Binance scenarios 58–60 record the erasure recovery evidence in [Recovery](#recovery). |
| 3.3 — merge (HIL-1890) | HIL-1999 connected the browser and CLI entrances to the owner protocol and retired the remaining borrowed merge writes and debt. | The detailed [merge norms](#merge) are recorded here; HIL-1987–HIL-1998 and HIL-2000–HIL-2011 prepared the shared records, owners and surrounding behavior, and HIL-1999 connected them to the live path under [cutover and observation](#merge-cutover-and-observation). |

Every implementing leaf removes its obsolete foreign grant, comment, debt
entry and markers in the commit that actually enables its move. Search by key,
including wrapped markers. A new handler that no live caller uses is not that
move. A shared right stays as long as the other terminal path needs it.
The Writer Map below remains a text census, not an executable registry. The
strict DB judge matches declarations to the closed states supplied by the
transition catalog, while `SHARED_DB_OWNERS` selects each collection's exact
state with its outstanding implementing leaves. The framework has an
independent ceiling over allowed states, and project topology guards check
specific permitted shrinks; see [truth-source.md](truth-source.md#what-the-start-refuses).

## Ordinary Transitions

These are the remaining ordinary paths, not a general permission for partial
DB co-owners. `Add` creates a new row; initial secret population immediately
after insert completes that creation. Deleting old rows, renewal and an
upsert of a known key are later writes. A sole library owner such as `settings`
and an executor's own working rows retain their legitimate writes.

| Leaf | Current writer and operation | Agreed move |
|---|---|---|
| HIL-1892 | The DB topology judge compares every `Update`/`Remove` pair between distinct registered worker classes, even when operation sets are incomplete or widths differ; creator `Add` alone is compatible. Chat's users library now declares `Add` only for events and registration details. | The closed catalog admits exact states for current DB claims and their required partition proofs. `SHARED_DB_OWNERS` selects the outstanding implementing leaves; the independent framework ceiling and demo guards reject unapproved states and shrink only along named transitions. |
| HIL-1893 (implemented) | At this cutover, delivery channel agents kept the existing whole-form `OWNS_DB` declaration with `Update` as their effective operation ceiling and `NotificationChannelShard` as the per-item proof. Tasks and polls use standalone `SmsRawSendAgent` for typed `HILOS_SMS_SEND` frames and settings reads, with no DB/RT ownership claims, delivery partition, durable delivery route or durable delivery intake. Chat and binance keep raw and durable delivery intakes on mixed `SmsDeliveryChannelAgent`s. | Each addressed row write rereads saved delivery id, notification id, channel and `routing_key`, and refuses identity changes; every channel claimant must match the current unique registered descriptor/route and owner computed from saved channel/key (legacy `NULL` uses `notification_id`). Whole/set writes by a delivery claimant are refused regardless of operation. Delivery id keys intake, transport and mutation/settlement checks; bounded recovery scans advance across foreign ids. An address change does not rewrite a saved key; a new delivery stores the routing key produced for its current recipient state, or `NULL` for a singleton. Pool-width config is expected to agree across nodes after the normal restart/freeze process; no rolling mixed-environment or split-brain fence is promised. No new DB width, `OWNS_DB_ROWS` snapshot or `_setVia` change. |
| HIL-1894 | Pending adoption and service duties depend on a registered channel agent; dispatch reads the enabled setting. | Keep the disabled channel's owner available for its journal, pending obligations, retention and deletion; close new sends. Disabling does not discard rows or introduce a shared cleaner. |
| HIL-1895 | Notifications library `Update`: `markGone()` on the transport's report. | Implemented: the push delivery channel reports the dead endpoint to its user's personal owner with a `(userId, subscriptionId, revision)` snapshot without an answer, and the personal agent verifies the fresh persisted revision before marking `gone_at`. Identical `last_seen_at` moments do not prove an identical subscription version; persisted `revision` strictly monotonically increments on each accepted renewal. |
| HIL-1896 | Users library creates the rename feed event and sends its link frame. | Implemented: the renamed person's owner writes the journal link; creating the feed event at the library remains creation. |
| HIL-1897 | ChatAgent reads linked journal rows while clearing history; the journal's personal owner unlinks them on request. | Implemented: obtain confirmed unlink from the journal's personal owner before deleting chat events. |
| HIL-1898 | The sessions holder asks the users library to create the first administrator; it authorizes and binds the session. | Implemented: the users library creates the person, and the holder authorizes and binds the session. |
| HIL-1900 (implemented) | The holder applies the trust term from the current settings apply request. | The settings library records a trust restriction (`hilos_second_factor_trust_restriction`: the save moment and a term of 0–365 days) in the transaction of the save that shortened the term or turned the factor off while forgetting trusts, and the frame to the holder names that restriction (HIL-1908). Each trust keeps the moment it was last granted, and the sign-in gate reads the restrictions from the table, so a restriction ends a trust before any owner has applied it. The person's agent applies them on `hilos_user_second_factor_trust_restrict`; the sessions holder's sweep sends that frame and the owner's start applies the saved restrictions without it (HIL-1901), and the switch-off and the term send it through the sweep's await request (HIL-1909, HIL-1908). |
| HIL-1901 (implemented) | The holder's apply call changes trusts installation-wide; there is no owner recovery of a missed policy hand-over. | The sessions holder sweeps once a minute from a fresh database query for people whose trusts violate a saved restriction, sends each the frame above in portions of at most 100 in flight and hears `hilos_user_second_factor_trust_restrict_done`; a new pass starts unasked only when none has run or an hour has passed. The personal owner applies every saved restriction when it starts, so a sleeping owner applies what it owes without a frame. The sweep's state is the holder's memory and adds nothing to the database; the switch-off and the term go through the same sweep (HIL-1909, HIL-1908). |
| HIL-1902 (implemented) | Notifications library `Remove`: its former raw batched deletion of delivery rows. | The channel/shard owns retention of its terminal delivery rows through bounded pages; pending rows are not pruned and no general cleaner becomes their writer. The library keeps `Add` alone; retry moved to the channel/shard in HIL-1910. |
| HIL-1903 (implemented) | Users library `Remove` beside `Add`: `startEnrolment()` removed an unfinished enrollment. | The personal owner checks addressability and reservation, then removes unfinished enrollments and creates the replacement in one transaction. The secret and URI return in the done answer only after commit; failure rolls back the replacement and preserves the earlier enrollment. The users library no longer creates the factor or runs a drop/create handover. |
| HIL-1904 (implemented) | Users library `Remove` beside `Add`: `issueSet()` deleted old backup codes. | The personal owner checks addressability and reservation, then deletes the old code set and creates its replacement in one transaction. Display codes return in the done answer only after commit; failure rolls back and preserves the earlier set. The users library no longer creates codes or runs a drop/create handover. |
| HIL-1905 (implemented) | Notifications library `Update`: `subscribe()` upserted the person's known device. | The personal owner renews an existing device by its captured subscription id; the library creates new subscriptions. A foreign endpoint is released by its former owner (HIL-1906). |
| HIL-1906 (implemented) | Notifications library `Update`: a foreign endpoint was temporarily rebound in place, its `user_id` changed to the new person. | G1-A: the library asks the former personal owner to release the endpoint (`hilos_user_push_release`); the owner deletes its own row and answers (`hilos_user_push_release_done`); the library resumes the same press and creates a new row for the new person, so the id may change. A refusal by the former owner refuses the press in its words and writes nothing. The library keeps `Add` alone. |
| HIL-1907 (implemented) | Sessions holder `Add`/`Update`: `trust()` created or extended a session/person pair. | The personal owner performs the grant or renewal through `grantOrRenew()` after rechecking the person, session, proof and current policy, and applies saved restrictions in the same transaction. The holder has no ordinary `Add`, insert or re-grant fallback if the owner cannot answer. Trust is optional after successful sign-in; a refusal is logged without undoing sign-in. HIL-1989 moved new-pair creation to the same owner path. The holder's `Update` for the term went with HIL-1908. |
| HIL-1908 (implemented) | Sessions holder `Update`/`Remove`: the former term cap and zero-term delete. | Personal owners apply the cap or zero-term removal using the saved policy; no earlier expiry is extended. The settings library's frame carries `restrictionId` (the restriction the shortening wrote; on a repeat of the same term the latest one; with none, the answer is success at once and no frame); the holder writes nothing, waits on `SecondFactorTrustRestrictionSweep::await(restrictionId)` and answers by the result (0 still owed is success, more than 0 a refusal sentence; the term itself stands). HIL-1952 retired the holder's erasure `Remove`, and HIL-1999 retired its merge `Remove`. Price: the same as the switch-off — with 100 or more people holding a trust, a save in the middle of a running pass or a silent owner, the answer comes after the browser's 30 s and the administrator sees "The action timed out." while the term already holds (owner's decision, 13.11.2026). |
| HIL-1909 (implemented) | Sessions holder `Remove`: the former `deleteAll()` on installation switch-off. | Each personal owner removes its trusts when the switch-off policy requires it: the settings library records the (S, 0) restriction and its frame to the holder carries `restrictionId`; the holder lets the code waits go, waits on `SecondFactorTrustRestrictionSweep::await(restrictionId)` and answers by the result (0 still owed is success, more than 0 a refusal sentence; the switch-off itself stands). HIL-1952 retired the holder's erasure `Remove`, and HIL-1999 retired its merge `Remove`. Price: the answer waits for the owners; with more than 100 people holding a trust, or a silent owner, it comes after the browser's 30 s and the console's 15 s, and the administrator sees the earlier "no answer" while the switch-off is already in force. |
| HIL-1910 (implemented) | Notifications library `Update`: its former `NotificationDispatcher::requeue()` called `resetForRetry()`. | Retry is a signal to the delivery's channel/shard; the library reads the saved identity, finds the owner and passes the request on. The owner checks the coordinates and the shard, refuses under a freeze, resets its own failed row in a transaction, queues it and answers the page itself; the library writes nothing and answers only before the hand-over. |

These moves preserve the selected protection, expiry and browser outcomes.
Their exact private messages and APIs are for their own contracts. They do not
introduce a terminal transfer protocol, a distributed transaction or automatic
retry of external actions.

**The rename feed link and the history clear.**

1. A feed link is written only while its event is live; a late frame after event
   deletion writes nothing.
2. The same personal owner writes the unlink and answers the request. Chat
   deletes events only after all answers arrive.
3. A link frame received after unlink and before event deletion is allowed.
   Deleting the event clears `event_id` in the database (`ON DELETE SET NULL`).
   Readers may retain the deleted event id in memory, but nobody reads it
   afterward: feed lookup follows a live event, while cleanup and erasure
   selections read the table. HIL-1897 needs no separate protection.
4. The clear asks every person whose journal row still links an event (read
   from the table), and deletes everything the events table holds at the last
   answer, then writes the `chat_cleared` line. A refusal or a missing answer
   leaves the history; the next cron starts again from the table.

## Owner Decisions

The owner's answers were given in Russian. What follows renders each in
English and states what was accepted from it; a sentence in italics is a
rendering of the owner's words, not a quotation in English. None of these is
open: the six questions of the proposal P-547 and the four questions of the
HIL-1675 interview are answered, and no question to the owner remains.

### The frame of the proposal P-547 (2026-10-18)

- **The guarantee.** Asked whether this holds — *from the moment of the fact
  nobody sees the person: sign-in is refused, sessions are dropped, agents are
  stopped; the rows lie there for the flight time of the signal, and for an
  owner that is asleep until it starts; a backup taken in that window carries
  part of the person's rows; the people list shows "erasure in progress"* — the
  owner answered, rendered: *it suits us, and there is nothing we can do about
  it anyway — a backup is taken asynchronously, we cannot half-freeze the
  system. Fine.* Accepted: the window exists and is the accepted price; the
  backup is not frozen for it.
- **What a library is.** Rendered: *by the original idea the library always
  creates an entity in some form — of any complexity and fullness, with its
  child entities — and that is all. The library does not go on to edit. That
  was the design from the start, and in my view we should strive for it.*
  Accepted: "the library creates, the person's agent edits" is not a second
  writer, provided the library does not touch the row after creating it; edits,
  deletion and transfer belong to the instance owner.
- **Coverage.** Rendered: *let us do the database only for now. RT is somewhat
  different there. And simpler, let it be.* Accepted: this page is about
  `Hilos::$db`; runtime collections are not re-cut.
- **Delivery status.** The owner left it *at your discretion*; the resolution
  follows from the answer about libraries: the notifications library creates
  the delivery row and does not touch it again; from then on the single writer
  is the channel, and removing old rows is the channel's too. The research
  checks the price — for instance, who removes the rows of a channel the
  project switched off (answered by question 4 below).
- **The erased person's chat messages.** Asked whether the decision of
  2026-10-04 — erase them atomically whoever owns the room — stands, or whether
  the room owner deletes them on a signal, the owner answered *yes*. The reading
  accepted by that approval: the decision stands, the erased person's messages
  vanish from every room at once. It does not contradict one writer: one
  monopolistic `ChatAgent` holds every room
  (`demo/chat/backend/Agents/ChatAgent.php`), and it deletes them itself, on a
  signal, in one transaction of its own.
- **The merge.** The owner asked for a recommendation and accepted it,
  rendered: *approved, yes.* The loser's agent gives its rows away: in its own
  transaction it moves them into the survivor's set — an edit of the "whose"
  column, so the rows keep their ids and the references from other tables stay
  whole — blocks its own row, and sends the survivor's agent a frame carrying
  the rows themselves; the survivor puts them into memory and does not read
  the database. Rejected: *the survivor takes* — the rows would have to be
  deleted and created again (new ids, broken references, a sign-in address that
  does not work in between, a failure midway that loses a way in); *the merge
  as an exception of the whole-table owner* — a second writer stays, with its
  lock and its re-read, and contradicts the answer about libraries.
- **What the research had to settle about the merge.** The right to give a row
  from one's own set to another's — today
  [instance-owners.md](instance-owners.md) grants the move only to the holder of
  the whole table — and its guard; and the two-step check of both sides that
  today runs under one lock (`Second-factor protection changed`): first the
  survivor's agent confirms its state, then the fact is recorded, with a window
  of about a hundred milliseconds until the survivor holds the frame (an agent
  that is asleep reads its set at start). Question 2 below bounds that window
  differently.

### The interview of HIL-1675 (2026-10-21)

- **Question 1 — a durable operation and the owners' obligations until each
  confirms.** Rendered: *1 — approved. I do not like this solution, but the
  alternative is a horror.* Accepted: the state of the operation and the
  obligations of the owners survive a restart; only the safe steps of an
  unfinished operation are repeated; completion is confirmed by every required
  participant, soft references counted; the foreign keys are the last safeguard
  and not the proof. Once an erasure has begun, the operation is finished. The
  exact schema and the messages remain the future leaves' to define.
- **Question 2 — restricting changes to the ways in and the protection of
  both accounts until the rows are confirmed received.** Rendered: *2 — yes.*
  Accepted: both owners are prepared before the merge fact; changes to the
  ways in and the second factor are restricted until the receipt of the rows is
  confirmed; a sign-in through a transferred way in waits for the chosen
  protection to be applied at the survivor. No SQL transaction is held between
  messages; after the fact comes completion with safe repetition. After a
  failure the wait may last until the operation is recovered: the hundred
  milliseconds above are an expectation, not a guaranteed bound.
- **Question 3 — the same personal agent finishes its part.** Rendered:
  *3 — approved.* Accepted: ordinary actions close at once; the same agent of
  the person finishes the deletion of its part, or the confirmed transfer of
  its rows, and then stops. After a failure it may be raised only to finish
  that operation; a late ordinary frame is still refused. This refines the
  earlier "agents are stopped".
- **Question 4, in its corrected form only — a switched-off channel.**
  Rendered: *4 — fits; and what was the question?* Accepted: switching a
  channel's sending off keeps its journal and its retention; the former agent
  stays available to delete rows by retention and on an account erasure. No
  shared clean-up agent is introduced — the proposal of one was withdrawn — and
  the last owner of retained rows cannot be taken away entirely without a
  separate decision. Switching off never deletes data.

### What was decided before, and stands

- The person's agent is the sole writer of ordinary content edits of its
  instance, its row and every row whose set tree ends at it
  ([instance-owners.md](instance-owners.md), owner's decisions of 2026-09-17
  and 2026-10-04); the erasure and the merge are the declared exceptions this
  page now replaces with a protocol.
- One person: an erasure takes the whole circle of merged accounts (owner's
  decision, 2026-09-30). The circle is fixed before it is broken.
- A person's rows point at `hilos_user` with `RESTRICT`; a mention in
  somebody else's row is `SET NULL`; no `CASCADE`
  ([people-table.md](people-table.md), owner's answer of 2026-10-03). The
  person row therefore cannot go while a child with a hard key lives: the end
  of an erasure is a fact, not a timer.
- The soft references — a delivery to its notification, a deletion request to
  its person — are kept each with its own reason
  ([people-table.md](people-table.md#foreign-keys-onto-the-person)) and are the
  rows a foreign key will never catch.
- `HIL-1529` wrote the rule of related records and after-commit steps
  ([work-in-portions.md](../signals/work-in-portions.md)); `HIL-1613` separated
  the external writer of the journal in the Core Rule of
  [truth-source.md](truth-source.md). Both stand. This page builds neither
  mechanism again: it corrects the example — one transaction is one owner's;
  between owners there is a fact and a signal — and names the boundary where
  the owners differ.

## Current Implementation

Facts rechecked at `f6a5f2e8f` on 2026-11-07, separately from the historical
research census. Paths are under `framework/backend/` and `demo/chat/backend/`.
`ALL` and the general agent's `BY_KIND` expand to `Add`, `Update`, `Remove`;
the users-library default expands to `Add`, `Remove`, and the channel default
to `Update`. Read both that grant and the actual call below.

**The sessions holder's claims.** `AbstractSessionsLibraryAgent::OWNS_DB`
(`Auth/Library/AbstractSessionsLibraryAgent.php`) names twenty-five
collections. Three are its own whole: `sessions`, `userMerges`,
`accessLogEntries`; two are the working records of the coordinator,
`accountOperations` with `Add` and `Update` and `accountOperationAssignments`
with `Add`; `secondFactorTrusts` carries `Add` (the pair written on the
way in) and `Remove` for the merge since HIL-1908; one is held whole for the
merge's upsert and deletion, `secondFactorSettings`; `users` carries `Update`
for the loser's block; first-administrator creation is no longer a
holder write. The other seventeen are borrowed — `Update` and `Remove`, or `Remove`
alone, each with a comment naming its current purpose and pointing at
[instance-owners.md](instance-owners.md#operations-over-many-instances). Beside
them `OWNS_DB_SET` holds one set claim since HIL-1943: `accountOperationResults`
with `Add`, the holder's own results as a participant (*The participant result
claim* below). The
chat's `SessionsLibraryAgent` (`Agents/Hilos/SessionsLibraryAgent.php`) adds
four more borrowed claims over the room tables for the same two operations.

**The erasure.** The holder's due sweep and `test:account:force-purge` open or
observe a durable operation for the fixed circle. Folded accounts are ordered
before their survivors. The personal owner conditionally records the start;
when a forced start commits, that owner moves the erasure moment to now in the
same transaction as the start result. Deferred opening does not move the date.
A rolled-back start restores the prior date and leaves the durable operation
open; a refusal after a committed start leaves the date in place and the
operation unfinished. Each participant deletes its own rows and records its
result in one local transaction. A failure rolls back only that owner's current
work, while earlier commits remain recorded for recovery. The personal owner's
final transaction deletes its merge row, rename journal, capture and person row
after required results, then records completion. A `RESTRICT` refusal rolls
back only that final transaction and leaves the operation open with a safe
reason. After database commits, existing file removal and forget-frame work
keeps its prior limits: failures are logged and not retried. `AccountDeletion`
(`Database/Entity/Item/AccountDeletion.php`) carries `requested_at`,
`effective_at`, `started_at` (the conditional start of *The erasure start*),
`canceled_at` and `completed_at`; its `user_id` deliberately has no foreign key,
so the row survives the person as the trace of the request, and under
anonymization it is purged.

**The merge.** The administrator action and `account:merge` open and drive a
durable merge operation through the sessions holder. Each personal owner checks
its own protection and reservations, then commits its assigned work and strict
result in a local transaction. The loser transfers its own identities,
passkeys and second-factor rows under their existing ids, applies the password
fate to its own captured password, writes its merge row and block, and keeps a
separate private hand-over copy. The survivor admits the hand-over and applies
its own captured password and protection writes with the exact receipt. The
holder and registered project owners perform their own assigned work and
recorded results; the operation ends only after the receipt, every required
result and physical copy cleanup. The sessions holder ends the loser's
sessions after the give and ends the survivor's sessions after receipt when
the loser's factor was retained.
`UserMerge` (`Database/Entity/Item/UserMerge.php`) carries the folded account
(`RESTRICT`), the survivor (`SET NULL`) and `merged_at`; it has no receipt, no
fence, no phase and no record of what reached the survivor.

**The shared account-operation carriers.** The framework now mounts
`Hilos::$db->accountOperations`, `accountOperationAssignments` and
`accountOperationResults` over `hilos_account_operation`,
`hilos_account_operation_assignment` and `hilos_account_operation_result`.
The operation stores its kind and either the erasure request and circle or the
merge pair and choice. Assignments carry a stage and stable owner address;
results carry an immutable participant payload. All three tables are purged
on anonymized restore and are not journaled. `AccountMergeOpening::open()`
records a merge operation and the survivor's and loser's preparing assignments
in one primary-database transaction, addressed as `hilos_user:<id>`, with the
project's expected composition (see the project owners below). The protected
`AbstractSessionsLibraryAgent::openAccountMerge()` entry calls it and drives the
coordinator used by the live browser and CLI paths since HIL-1999. The
kind-required `openPage()` and `openForOwner()` keep erasure and merge reads
separate, and `AccountOperation::mergeFact()` reads the loser's go/give result;
the coordinator does not write that result.

**The merge preparation.** `AccountMergePreparation::run()` checks one side of
the merge against that account's own rows and records either a refusal or its
reservation in the participant's transaction. A reserved result has exactly
`prepare: "reserved"`, `passwordIdentityId` (the password identity id or
`null`) and `secondFactorIds` (the confirmed factor ids, ascending and unique).
A refused result has exactly `prepare: "refused"` and `refusal`, one of
`missing`, `survivor_merged`, `loser_merged`, `being_deleted`, `occupied`,
`protection_changed`, `second_factor_fate_required`,
`loser_without_second_factor`, `survivor_without_second_factor`,
`loser_pending_removal`, `survivor_pending_removal`, in that priority order.
The checks cover account existence and merge state, a started or open erasure,
another merge's saved reservation, the protection flag shown by the requester,
the selected factor fate and pending factor removal. `heldBy()` walks
`openMergesNaming()` in ascending operation-id order and returns the first
merge with a saved reserved result for that account and no saved release result
for that side. A release leaves the preparation result unchanged: after one
owner releases, that side no longer holds the account even while the other
owner's release is pending. An exact preparation repeat still returns its
saved result, while a new preparation on a released side is refused. The
paged, fresh-SQL selection and result lookup serve the coordinator's
preparation dispatch; no account row changes during preparation. HIL-1999
connected the administrator and CLI callers to this operation path.

The operation table keeps `open_loser_user_id` as an ordinary nullable `INT`
in `_columns` and `_types`, with a UNIQUE index. `recordMerge()` sets it, and
`finish()` clears it in the same UPDATE that records the finish. The current
FULL restore structure replay cannot model generated columns, so the
open-merge key remains a stored column. One immutable Change creates the three
new tables and a second drops the three former erasure tables; the changes do
not use `RENAME`. The historical
`account_erasure_*` schema-unit keys stay in place because immutable Change and
install references already name them; the restore mechanism is unchanged.

**The erasure coordinator.** `AccountErasureCoordinator::begin()` and
`afterAnswer()` coordinate an open erasure from its operation, assignment and
result records. `AbstractSessionsLibraryAgent::driveAccountErasure()` is the
live entry: it calls `begin()` and sends the returned assignments. When an
operation closes, `driveAccountErasure()` and the answer handling reply to the
`test:account:force-purge` commands parked on it (`ForcePurgeWaits`). The due
The due sweep opens erasure operations, and force-purge opens one if needed or
observes the account's existing operation; the holder's recovery pass and an
owner's start resume one that is already open (*The erasure recovery*). `begin()` checks saved finals
before recording captures, so a lost answer to the last final does not reopen
capture work.

The root's `start:personal:hilos_user:<id>:<id>` assignment is recorded when
missing. An open start without a result is sent again; `{"start":"lost"}`
finishes the operation as `NotStarted`, while `{"start":"started"}` records
captures and missing finals, then selects every ready assignment without a
result. Readiness follows durable facts: the start is always ready; after it
starts, personal capture, sessions and data export are ready; the personal
capture result unlocks the users library, personal and project parts; the
personal result unlocks delivery-channel and legal parts plus that account's
final; another account part unlocks its final; a final is ready only when
`missingPartsOf($operation->id, $userId)` is empty and the account has no
folded child. Delivery-capture and wait assignments are never sent.

The holder accepts an answer only when its assignment exists, belongs to the
operation, and the frame, assignment and open erasure have the same stage. A
`done` answer advances only when the assignment has a durable result: start
opens the first wave, capture opens that account's parts, personal opens its
delivery, legal and final parts, another part opens the final, and a final runs
`end()` then opens newly ready finals. `uncaptured` records new captures and
resends that personal part only when capture changed; `waits` does nothing;
`final_refused` records the safe table reason and opens newly ready parts;
`refused` and `failed` leave the assignment open for the next recovery pass.
`parts[].done` is true when its assignment has a persisted result.

The holder runs its sessions and access-log part inline through
`AccountOperationAsks::answer()` and feeds that answer through the same
`afterAnswer()` queue. Other owners receive their declared account-operation
step signal with the owner's `ownerIndex`, when indexed.

**The erasure recovery.** A frame or an answer that died on the way leaves
durable facts behind. The holder pass and an owner's start resume open Erasure
and Merge operations from those facts without a new external event.

The holder enters every open erasure and merge through the cron rule
`hilos_account_erasure_recovery` (`'0 * * * *'`), armed only where the project
has a sign-in surface. A new holder owes its first pass to its first tick: the
hourly rule fires only in the first minute of an hour, whatever its last run
says, so the arming sets a continuation flag and the holder, not the rule, takes
up what its predecessor left. The rule then keeps the pass of every hour after.
A pass walks all open account operations in ascending operation id order, ten
total to a tick, through `driveAccountErasure()` or `driveAccountMerge()` by
kind. Each entry begins or continues from its durable facts and sends its ready
duties. A full portion asks the next tick to go on without waiting for the rule,
and a short one ends the pass. The position lives in the holder's memory only;
a new holder starts from the first operation and reads the same facts again. A
tick with no pass running and no hour struck reads and writes nothing, so the
database is not polled on every tick. The pass runs under one cron attempt
receipt, opened by its first portion and closed by its end or by the holder's
stop. Each operation has its own trap: a failure logs only its id and kind,
leaves the obligation open, and the pass goes on. No failure is written to the
database; the next pass reads the same facts.

An owner that starts reads what it is owed. `AccountOperationAsks::resume()`
reads ready Erasure and Merge assignments independently through
`AccountErasureCoordinator::owedTo()` and `AccountMergeCoordinator::owedTo()`,
then sorts their combined duties by assignment id. After `onStart()` returns or
throws, the worker calls
`AccountOperationAsks::resume($agent, $ownerType, $ownerIndex)`, in the frame
`onStart()` ran in. An agent with no `ACCOUNT_OPERATION_SIGNAL`, or a project
without the AUTH feature, returns at once. Each duty goes through the agent's
own assignment handler in the process, with the frame the coordinator would
have sent, so the step, the outcome and the answer to the holder are the
handler's own. A failed read of one kind is logged and does not hide duties of
the other kind; a failed duty is logged and does not prevent the remaining
duties from running. No frame is sent to the agent itself. Once the agent
requests a stop, resume leaves the remaining duties to the coordinator. It
never throws, and the start goes on.

Doubles are the accepted cost. An owner woken by the frame itself runs the duty
at its start and answers that frame again with the saved result, and at a daemon
start the holder's pass and the owners' resume reach the same duty together: the
step is atomic, and the second entry answers with the stored result. The
coordinator keeps such doubles harmless: a repeated answer records nothing new,
and the successors that still have no result are assigned again, each a double
of the same kind ([Recovery](#recovery)).

**The stop.** `AbstractUserAgent` requests a stop when its own person row is
deleted (`onSignalDbSyncDeleted()`). When the loser's merge row appears,
`onSignalDbSyncCreated()` restores the finishing context, and [source
cleanup](agent-lifecycle.md#idle-stop-an-agent-that-lives-as-long-as-it-is-spoken-to)
requests the stop after its result commits or a saved result is returned.
`AddressablePerson::require()` (`Users/AddressablePerson.php`) refuses a
missing, folded or erasing person before the hop, and the agent repeats the
check. Service continuation is limited to the operation's own work. When
erasure begins, the agent keeps running in the state described in *The closed
account*. In *The erasure final*, `finishAccountErasure()` requests a stop after
the final commits or a saved result is returned. A refusal or a wait leaves the
agent running, and deletion of its person row remains a second stop trigger.

**The write guard.** A claim over a set covers a write only when every set key
the write touches is its own (`TruthSourceKeys::coversRow()`,
`Core/TruthSource/TruthSourceKeys.php`); a row moved under another top writes
into two sets and asks the collection door, which only the holder of the whole
table passes (`DbWriteGuard::guardSetWrite()`,
`Core/TruthSource/DbWriteGuard.php`;
[A Claim Over A Set](truth-source.md#a-claim-over-a-set)). The merge
coordinator dispatches the work to the owners. Moving existing sign-in or
protection rows between account sets uses the operation-scoped right in [The
Transfer Right](#the-transfer-right) through `AccountMergeTransfer::run()`
(HIL-1991). Ordinary `Update` still requires the normal claim. A set claim with
no `Add` is a borrowed claim and waits for the state at start.

**The person's agent's claims.** `OWNS_DB_ROWS` holds its own `users` row with
`Update` alone; `OWNS_DB_SET` holds sixteen child sets, borrowed except the
four the owner brings into being (`secondFactorSettings` with `Add`, `Update`
and `Remove`, the last for its erasure part, `stepUps`,
`notificationPreferences` and `userPhotos` whole), the rename journal with `Add`
and `Update`, and the results of its own steps in an account operation
(`accountOperationResults`) with `Add` and `Remove`, keyed by the agent's full id
and not by the person's (HIL-1939; the `Remove` is for the one capture its final
deletes, HIL-1942; see *The participant result claim*). `READS_DB`
names the stored trust restrictions, the account operations and their
assignments, and the notification deliveries, which its erasure part reads to
see every delivery captured. Under these claims the agent deletes its child sets
as its part of the erasure, `eraseAccountPersonalPart()` (live in the HIL-1952
erasure path). A project that keeps sets of its own on the person's agent extends both maps in
its subclass through `...parent::OWNS_DB_SET` and `...parent::READS_DB`, names
those collections in its directory's `personal()` (see the directory below), and
deletes them in `eraseProjectChildren(int $userId): AccountErasure`: after the
framework's sets, inside the same part's transaction, opening none of its own.
A throw there rolls the whole part back, the framework's sets with it, and the
part's result names the project's rows per family and their registry files beside
the photo's (`AccountErasure::plus()`). Its final uses the personal agent's
production `Remove` claims to delete its
`users` row, rename journal and merge row; HIL-1952 removed the holder's borrowed
erasure rights. `renamePerson()` is the real atomic example
the target follows: the name and its journal row in one transaction of one
owner, and what follows the commit is the library's, on the answer.

The same subclass carries the project's part of a merge by implementing
`AccountMergePersonalJoin`. A row of those sets belongs to one person's agent
and no agent writes into another person's set, so the part is three methods and
not a move, and none of them opens a transaction of its own:
`personalRowsToGive(AccountMergePart)` on the loser reads its rows of the sets
and writes nothing, returning them as a JSON object with named keys (empty when
there is nothing); `joinPersonalRows(AccountMergePart, array)` on the survivor
writes the given rows into its own sets by the project's rule, inside the
participant step's transaction, and returns the rows joined per family;
`deleteGivenPersonalRows(AccountMergePart)` on the loser deletes its own rows of
the sets, inside its step's transaction, and returns the rows deleted per
family. The project decides the format between the first two; the framework puts
the object into the frame as it is and checks only that it is an object whose
keys are named: a list or a key PHP reads as an integer is refused. The
subclass's claim on such a set carries the operations its rule performs: `Add`
for the rows the join inserts and `Remove` for the ones the delete removes, and
`Update` as well when the rule folds a given row into a row the survivor already
has, adding up a quantity for instance. A claim without `Update` refuses such a
fold, so the join fails on every poll and the merge never ends. An exception
from the join or the delete rolls its step back whole, and one from the read
sends no frame. Files of the registry are not touched (*Merge*, step 6).

**The participant result carrier and step.** The framework mounts
`Hilos::$db->accountOperationResults` over `hilos_account_operation_result`, with
at most one immutable result row per assignment. `owner_agent_id` stores the
assigned owner's full agent id and partitions the table through `_setVia`; the
unique assignment key enforces the one-result limit, and the assignment foreign
key cascades on delete. The row stores `assignment_id`, `owner_agent_id`,
`payload_json` and signed epoch-millisecond `created_at`. The table is
classified `PURGE` under anonymization and is not journaled. Its typed readers
find a result by assignment or return an `AccountOperationResults` collection
for an operation and stage, ordered by result id.
Assignments and results use typed readonly payloads over a shared JSON-object
base. The base preserves submitted JSON and compares payloads semantically;
`AccountOperationOwnerAddress::agentId()` provides the full agent id used by the
result set.

The entity mapping is in
`framework/backend/Database/Entity/Item/AccountOperationResult.php`, and the
typed collection reader is in
`framework/backend/Database/View/Collection/AccountOperationResults.php`.
`AccountOperationResultsActions::record()` requires an open transaction and the
participant's `Add` right over its own result set. A semantically equal JSON
payload returns the saved result; a different payload for that assignment is
refused.
The action lives in
`framework/backend/Database/Actions/Collection/AccountOperationResultsActions.php`.
`AccountOperationParticipantStep::run()` in
`framework/backend/Auth/AccountOperation/AccountOperationParticipantStep.php`
checks the current agent, assignment and assigned owner. It returns an
existing result first, then refuses new work for a finished or changed stage;
otherwise it commits the participant callback's work and the result in one
primary-database transaction. A work failure is preserved and rolls back.
An equal saved result or a duplicate insert encountered while recording may
return a peer's winner, only after that transaction and its work roll back.
The mechanism has typed refusals for a missing participant, unknown or foreign assignment, and closed
stage, all exposed as a `ValidationException`. It adds no signal or route;
the erasure coordinator correlates the answers (HIL-1950). HIL-1952 wired the generic step into the live production erasure path: its
production participants — the person's agent, the sessions holder, the data
export agent, the legal agent, the users library and the delivery channels —
have live callers from HIL-1952 (see *The erasure start* below); the due sweep
and force-purge now use these steps instead of the old holder-wide flow.

**The participant result claim.** A participant using the generic step
declares `HilosDbContext::accountOperationResults` with
`[TruthSourceOperation::Add]` in `OWNS_DB_SET`. Its
`ownedDbSetKey(HilosDbContext::accountOperationResults)` returns its full
`getId()`. It lists `HilosDbContext::accountOperationAssignments` and
`HilosDbContext::accountOperations` in `READS_DB`; the set claim already
supplies reader interest for results, so repeating
`HilosDbContext::accountOperationResults` in `READS_DB` duplicates that interest
and refuses the start. `AbstractUserAgent::ownedDbSetKey()` returns the person's id
for its set collections, so a participant based on it branches on the results
collection and uses the full agent id there, preserving the person id for its
other sets. The person's agent is the first production participant
(HIL-1939): `AbstractUserAgent` declares that claim, widened with `Remove` for
the one capture its final deletes (HIL-1942, *The erasure final*), and those two
readers, and its `ownedDbSetKey()` returns `getId()` (`hilos_user:<id>`) for the
results and the person's id for every other set. The sessions holder is the
second (HIL-1943): `AbstractSessionsLibraryAgent` declares the same claim, and
its `ownedDbSetKey()` returns `getId()` for the results and the base's answer
for any other collection. Its `READS_DB` does not grow, because it holds
`accountOperations` and `accountOperationAssignments` whole. The data export
agent is the third (HIL-1946): `AbstractDataExportAgent` declares the same
claim, and its `ownedDbSetKey()` returns `getId()` (`hilos_data_export`) for the
results and the base's answer for any other collection. It holds neither of
the two, so its `READS_DB` does grow by `accountOperationAssignments` and
`accountOperations`. The legal agent is the fourth (HIL-1947):
`AbstractHilosLegalAgent` declares the same claim, and its `ownedDbSetKey()`
returns `getId()` (`hilos_legal`) for the results and the base's answer for any
other collection. It holds neither of the two either, so its `READS_DB` grows by
`accountOperationAssignments` and `accountOperations`; it does not grow by
`accountDeletions`, which every process reads. The users library is the fifth
(HIL-1944): `AbstractUsersLibraryAgent` declares the same claim, and its
`ownedDbSetKey()` returns `getId()` (`hilos_users_library`) for the results and
the base's answer for any other collection. It holds neither of the two either,
so its `READS_DB` grows by `accountOperationAssignments` and
`accountOperations`. The delivery channel agents (HIL-1945) declare the same
result claim. `AbstractDeliveryChannelAgent::ownedDbSetKey()` uses its full
`getId()` as the results key and the base's answer for other collections. Its
`READS_DB` grows by `accountOperationAssignments` and `accountOperations`.
`ChatAgent` (HIL-1949) declares the same result claim. Its
`ChatAgent::ownedDbSetKey()` returns `getId()` (`chat`) for results and the
base's answer for other collections. Its `READS_DB` grows by
`accountOperationAssignments` and `accountOperations`; it does not grow by
`accountDeletions`, which every process reads. Its erasure step is prepared,
on the live HIL-1952 path.
The common carriers themselves add no participant grant.

**The erasure census.** The plan of an erasure is a census taken from the
circle and the activated tables, and it is written as ordinary assignments.
The readers are in the ORM: `UserMerges::erasureCircleOf()` returns the
`AccountOperationCircle` of a root account (a breadth-first walk over
`foldedInto()` that remembers what it has visited, then reversed: folded
descendants before their survivors, the root last; `AccountErasureOpening`
calls it when it opens an erasure), `AccountOperations::openErasureCovering()`
returns the open erasure operation whose circle holds an account,
`AccountOperationAssignments::forOperation()` reads the assignments of every
stage of an operation, and `NotificationDeliveries::recipientIdentitiesAfter()`
and `UserVerifications::anyForUserOrIdentifiers()` read the rows of a person
that the census must ask about. The catalog is in
`framework/backend/Auth/AccountOperation/`: an
`AccountErasureParticipant` has a `key()`, the `collections()` it covers and
`expectationsFor(int $userId)`. `AccountErasureFixedOwner` answers for a
registered owner: with the owner registered it expects that owner at
`new AccountOperationOwnerAddress($ownerType, $perPerson ? (string)$userId : null)`;
with no owner and rows the person has, it expects a wait; otherwise nothing.
`AccountErasureDeliveryParticipant` reads the person's delivery rows, resolves
the owner of each through `DeliveryOwnerResolver::forDelivery()` (HIL-1893) and
expects `delivery:<channel>` owners, and rows with no owner become one wait per
channel. `AccountErasureParticipantCatalog::framework()` is a test-only entry
holding the framework's own participants (production code reads
`installation()`): `personal` (its sixteen collections, the `HILOS_USER` owner,
one per person; in `installation()` the collections the project's directory
names in `personal()` follow the sixteen), `users_library` (`verifications`,
`HILOS_USERS_LIBRARY`),
`sessions_holder` (`sessions` and `accessLogEntries`, `HILOS_SESSIONS_LIBRARY`),
`delivery` (`notificationDeliveries`), `data_export` (`dataExports`,
`HILOS_DATA_EXPORT`) and `legal_export` (`legalAcceptanceExports`,
`HILOS_LEGAL`). A repeated key or collection, or a covered collection that is
also in `EXEMPT`, is an `InvalidTopologyException`.
`EXEMPT` names each mounted framework collection that owes no participant and
the reason (`AccountErasureExemption`): `accountDeletions` is a `Trace`;
`accountOperations`, `accountOperationAssignments` and
`accountOperationResults` are `ServiceRecord`;
`secondFactorTrustRestrictions` is `InstallationPolicy` (the policy of HIL-1900
gives no participant, and the trusts it limits are the personal owner's);
`files` is `ReferenceNullified`; `registrationReservations` and `authBlocks` are
`OutsideAccount`; and `fileVariants`, `settings`, `verifierCircle`,
`oauthProviders`, `languages`, `languageNames`, `countries`, `countryNames`,
`locales`, `i18nReflows`, `probeValues` and `moderationRules` are
`NotPersonal`. Fourteen more are mounted only under a feature. Under
`CHANGE_LOG`, `changeLogTables` and `changeLogFields` are `NotPersonal`, and
`changeLogReceipts`, `changeLogEntries`, `changeLogChanges` and
`changeLogValues` are `Trace`: the journal is not a store of personal data, and
a receipt keeps an erased person's number by design
([change-log.md](change-log.md)). Under `STATUS`, `statusIncidents`,
`statusIncidentUpdates`, `statusWorks`, `statusWorkComponents`,
`statusMaintenancePeriods`, `statusSilencePeriods` and `statusObservedComponents`
are `NotPersonal`, since no Status table has an account column, and
`statusSubscribers` is `OutsideAccount`: a subscriber is an address, not an
account. With every feature on, the 22 covered and the 34 exempt collections are
the 56 mounted framework collections, and a unit check holds that equality.

`AccountErasureCensus::of($circle, $catalog)` returns the
`AccountOperationExpectation` list, by the accounts of the circle in its order
and, for each, by the participants in catalog order. A participant is activated
when any of its collections is mounted and its table is in the schema; one
whose tables the installation never created is not asked. Activation reads no
rows and needs no right to read them; a row question — an owner's `hasRowsOf`,
the deliveries — does, and the holder must be allowed to read what it reads. An expectation is
recorded as an assignment of the operation, with its `user_id` the account of
the circle and its stage the stage the operation is at (`preparing` when it is
opened). The keys and the payload are literal: an expected owner is
`expect:<participantKey>:<owner agent id>:<userId>`, a wait is
`wait:<participantKey>:<userId>`, both carry the payload
`{"participant":"<participantKey>"}`, and a wait is addressed to the coordinator,
`new AccountOperationOwnerAddress(HilosAgentType::HILOS_SESSIONS_LIBRARY)`.
There are three cases per participant and account: an owner is registered
(`expect:`), the table has rows and no owner (`wait:`), or there is nothing to
erase and nothing is written. A wait stays on the coordinator and is lifted on
reading: `AccountOperationExpectedSet::of($operationId)` returns `expected()`, the
`expect:` assignments, and `waiting()`, the `wait:` assignments with no
`expect:` of the same participant and account; the participant is read from the
payload, and any other key, such as the stage assignments of HIL-1950, is
ignored. A wait is therefore never deleted.

`AccountErasureOpening::open($deletion, $catalog)` runs in one primary-database
transaction and returns an `AccountErasureOpeningResult` (`operation`,
`deferredByOperationId`, `recorded`). The request's existing operation is
returned before either deferral, and only missing composition is added. A
request that is no longer live is a `ValidationException` ("The deletion
request is no longer live."). When any account of the circle is already in the
circle of another open erasure, the opening is deferred: nothing is written and
the result carries the covering operation's id. It is also deferred when an
open merge names any account in the erasure circle, even before that merge has
a preparation result. Otherwise the operation and its whole census are written
in that one transaction. `AccountErasureOpening::reconcile($operation, $catalog)`
takes an open erasure only ("Only an open erasure can be reconciled."), runs in
its own transaction and appends what is missing at the operation's current
stage; it deletes nothing and does not touch the circle. A wait counts as lifted
when the same participant and account has an expected owner, including one
written in the same pass. Both write what is missing through
`AccountOperationComposition::recordMissing($operation, $expectations)`, inside
the caller's transaction: a key already stored at any stage is skipped, and so
is a wait whose participant and account already have an expected owner. The
merge writes its project composition through the same call (below), and both
censuses ask `AccountOperationActivation::anyActivated()` whether a participant
is activated.

The due sweep and force-purge call the opening, and with it `erasureCircleOf()`, on the live path (HIL-1952). Reconciliation runs when the holder enters an open erasure, at the
holder's first tick and in its hourly passes (*The erasure recovery*; live from HIL-1952), after a `RESTRICT` refusal of a final, by
`AccountErasureFinal::refused()` (*The erasure final*), and after the
start, before notifications are deleted, by `AccountErasureCapture::recordAfterStart()`
through the coordinator. A failed or refused participant remains open for the
next recovery pass.

A project declares the owners of its own collections in one directory: its
facade points `ACCOUNT_DATA_OWNER_DIRECTORY` at a subclass of
`AccountDataOwnerDirectory`, whose `owners()` lists `AccountDataOwner` records
(the key, the collections, the owner's agent type, one per person or one for
everybody, and whether an account still has rows) and whose `exempt()` names
the project collections the erasure leaves alone, each with its
`AccountErasureExemption`. Its third answer, `personal()`, lists the project
collections its person's agent holds. They are no owner: that agent's type is the
framework's `HILOS_USER`, which no project owner may take. They are a part of
the `personal` participant, which the agent deletes as above. The default
directory declares nothing. `AccountErasureParticipantCatalog::installation()`
takes the framework's participants, the `personal` one ending with the
collections of `personal()`, then each owner as an
`AccountErasureProjectParticipant` in directory order, with the project's
exemptions. The whole directory is checked before any of its three answers is
given (`declaredOwners()`, `declaredExemptions()`, `declaredPersonal()`): a
malformed directory, `personal()` included (not a list, an element that is not a
collection key, a collection named twice), or an owner, an exemption or a
collection of the person's agent overlapping the framework or each other, is an
`InvalidTopologyException` naming every mistake. Naming a collection in
`personal()` and not deleting it in `eraseProjectChildren()` is the project's
mistake, and no startup gate catches it: telling whether a subclass overrides a
method would take Reflection, which production code does not use
([../code-style/reflection.md](../code-style/reflection.md)). The person's row
catches it instead: the final deletes that row last, and a row left behind that
holds the person under `RESTRICT` refuses the final (*The erasure final*).
A project participant counts as registered when the application registers its
agent type with the indexing the owner declares and the registered class implements
`AccountErasureProjectOwner`: then the census expects the owner for every
account; otherwise it waits for an account with rows and expects nothing for
one without. A project adds erasure participants only through its directory.
The daemon refuses to start over a mounted collection that no participant covers
and no exemption names, and over a collection the directory names (an owner's,
one of the person's agent or an exempt one) that this installation does not
mount (`AccountErasureCoverageGuard`, right after the set ownership gate); it is
silent without `AUTH`. The erasure opening and coordinator in the sessions
library, `ForcePurgeReply`, and that startup coverage gate read
`installation()`. The census adds no claim, table, migration, signal, route or
runtime state.

The merge reads the same directory for the project's part of a merge. An owner
is registered for the merge when it is declared one for everybody, the
application registers its agent type without an index, and the registered class
implements `AccountMergeProjectOwner`; whether that class also erases its part
has no bearing. A per-person owner is never registered for the merge: a row
passing from the loser's set into the survivor's is a write across two sets,
which `DbWriteGuard` allows only the owner of the whole table.
`AccountMergeProjectCensus::of($loserUserId)` asks every activated owner, in
directory order, about the loser alone, through an
`AccountMergeProjectParticipant`: a registered owner is expected,
`expect:<key>:<owner agent id>:<loserUserId>` addressed to its agent type with
no index (so the agent id is the bare type), rows or not; an owner that is not registered is waited for,
`wait:<key>:<loserUserId>`, while the loser still has rows, and nothing is
written once it has none. The survivor is never asked, and a malformed
directory is the census's `InvalidTopologyException` as it is. The census asks
the owners alone and does not read `personal()`. The person's agent's project
sets have a part of their own: two go duties, the loser's `personal_give` and
the survivor's `personal_receive`, written only while
`AccountMergePersonalStep::isActive()` holds, that is, while `personal()` names
at least one collection this installation carries and the class registered
under `hilos_user` implements `AccountMergePersonalJoin` (*The person's agent's
claims*; *Merge*, step 6). Without that contract on the subclass the loser's
rows of those sets stay with the loser, as before the part existed.
`AccountMergeOpening::open()` writes that composition after the two
preparations, in the same transaction, so a failing census rolls back the
operation and its preparations with it; a repeat writes only what is missing.
`AccountMergeOpening::reconcile($operation)` takes an open merge only ("Only an
open merge can be reconciled."), runs in its own transaction and appends what is
missing at the operation's current stage; it deletes nothing, and a wait whose
owner became registered is lifted on reading, not removed. The composition is
read back with `AccountOperationExpectedSet::of($operationId)`: `expected()` the
expected owners, `waiting()` the open waits; the preparations and the give
obligation carry neither prefix and are not part of it. So an owner the merge
needs and cannot ask stays an open wait rather than a quiet end, and nobody
moves its rows for it. The owner's part is
`AccountMergeProjectOwner::mergeAccountPart(AccountMergePart)` — operation,
survivor and loser as the operation records them — which moves its own rows of
the loser to the survivor and answers the rows moved per family it names.
`AccountMergeProjectStep::run($owner, $assignmentId)` carries it as the generic
participant step: before any work it refuses, with nothing written, an
assignment not of a merge ("An account merge part must belong to a merge."),
one not naming the merge's loser ("An account merge part must name the loser of
its merge.") and one before the loser's give result ("An account merge part
waits for the loser to give its rows away."); then the move and the result
`{"rows":{"<family>":<n>,…}}` (`{"rows":{}}` when nothing moved) commit in one
transaction. The prepared coordinator gives a project owner its work only after
the loser's fact, with `user_id` the loser and the owner address of the composition's
`expect:` assignment, and the owner agent calls
`AccountMergeProjectStep::run($this, $assignmentId)` in its own frame; the key
and immutable stage remain those of the original expectation, including an
initial `preparing` expectation. HIL-1998 supplies the participant's narrow
stage-carry gate; holder delivery and answer correlation use the same
`AccountMergeProjectStep::isCarriedAssignment()` judgment. The coordinator's
`AccountMergeEnd::advance()` check reconciles the open go composition through
`AccountMergeOpening::reconcile()` before selecting ready work: during preparation
the loser may have written new rows to an owner that is not registered.
The live administrator and CLI paths use this coordinator since HIL-1999.
The merge ends only with an empty `waiting()`
and a strict owner-bound result for every original `expected()` through the
prepared `AccountMergeEnd`. It is reconciled at the start and in the holder's
recovery passes.

**The erasure start.** The first write of an erasure is a conditional write on
the deletion request, and the person's agent is its writer (HIL-1939).
`hilos_account_deletion.started_at` (`Database/Entity/Item/AccountDeletion.php`)
holds the moment the request's erasure began. `start()` on the Object item,
reached as `$request->actions->start()` (`AccountDeletionActions::start()`), is
one conditional `UPDATE`: it stamps the column only on a request that is not
canceled, not carried out and not begun, and answers true only to the caller
that wrote. `cancel()` carries the same condition and also requires
`started_at IS NULL`, so of the start and "Keep my account" exactly one wins, in
either order: a cancel after the start answers false, and the start wins once,
so a repeated start answers false too. `complete()`, `expireGrace()` and
`isLive()` did not change, and a begun request stays live until its own end.
`startedOf(int $userId)` on the Object and View collections returns the live
request of a person whose erasure has begun. It reads SQL on purpose (marked
`// fresh-sql:`): a start committed by the person's agent in another worker
must close the account before its sync frame arrives. The price is one more
indexed read of `hilos_account_deletion` (`idx_account_deletion_user`) in every
`AddressablePerson::require()`.

The agent's step is `AccountErasureStart`
(`Auth/AccountOperation/AccountErasureStart.php`). `run($assignmentId)` is the
participant step of the root account's agent, run through the generic step
above: the operation must be an erasure, the assignment its root account's, and
the operation must name a deletion request of that account, otherwise a
`ValidationException`. Inside the step's transaction it marks that request begun
and records the answer as the result, `{"start":"started"}` or
`{"start":"lost"}` (`AccountErasureStartResult`, which also reads a saved
payload back and refuses any other shape); a repeat returns the saved result and
starts nothing again. `startOwnRequest($userId)` is the first write of the part
of an account folded into the root, inside that part's own transaction: it
begins the account's live request, answers false and writes nothing when the
account has none (no request is created), and raises a `DatabaseException`
outside a transaction. That account's part goes on whatever the answer, because
its circle was fixed before. Neither sends a frame: the assignment's frame, its
key and its stage are not this class's, and the live erasure coordinator calls them as part of each account's own start (HIL-1952).

**The closed account.** From the begun request, access closes at the person's
agent, at the sessions holder and, for the person's data copy and for an
administrator's order of the consent records, at the data export agent and the
legal agent. `AddressablePerson::require()` has a third
refusal after "no such person" and "folded": a `ValidationException` carrying
`AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE` ("This account
is being deleted."). A folded account whose erasure has begun is refused in the
words of the merge, and the check still has no general way around it. The
person's agent follows the state of its person (the docblock of
`AbstractUserAgent`): open, every frame is served; erasure begun, the ordinary
frames are refused in the words of `require()`, the service frames and the steps
of the person's own operation are served, and the agent lives until its last
write; folded, the service frames are served and the agent stops after each;
its own row deleted, the agent stops. The service frames skip the check: the
legal hold forgotten, the trust restrictions applied, a dead push endpoint
marked gone and a push endpoint released to another person. The data export
agent refuses an order in the same words before it asks for any confirmation,
publishes no build and answers a download with 403
([data-export.md](data-export.md)). The legal agent refuses an administrator's
new order of the consent records in the same words, right after the
confirmation and before it changes anything. It does not check a download: the
sessions holder closes the administrator's sessions. Delivery channels do not
begin an attempt for a recipient whose erasure has started, and an administrator
retry of that delivery is refused in the channel's own words.
ChatAgent also refuses to write a message for a person whose erasure has
started: it checks after an approved verdict before handing attachments to the
files registry, then checks the registry response before writing. The connected
tab sees the unavailable state. ChatAgent is the sole message writer and runs
each handler to completion; the holder's post-commit sign-out frame is not
durable enough to close this door.

The sessions holder asks `refuseErasingSignIn()` before every
`refuseBlockedSignIn()`, so before the second-factor gate, on the five places
where a proof ends: `grantSessionToUser()` (a grant that says the factor was
just shown included, because the code may have been typed after the start),
`grantOAuthTrip()` with no trip and with a live tab, `applyHeldOAuthGrant()` and
`settleChangedPassword()`. It is a door of its own and not a kind of block: it
holds in a project that enforces no block, binds no session, credits no
confirmation, tells the throttle nothing and raises no "Access closed" card, and
a second-factor wait the proof arrived on is released. The submit is answered
with `AuthFlowOutcome::CODE_ACCOUNT_ERASING` and the surface goes back to the
address field; a provider trip ends with `OAuthResultSignalData::REASON_ACCOUNT_ERASING`;
the words are those of `ERASING_ACCOUNT_REFUSED_MESSAGE` through `AuthMessages`.
A recovered password still converges and ends the account's other sessions.
`returningBlockedUserId()` gives an erasing account back to nobody,
`assertImpersonationAllowed()` refuses a takeover in the erasing words after the
merged-account check, and `regrantTrust()` writes no trust, because `require()`
comes first there. An ordinary invalid passkey proof reads as `AuthMessages::INVALID_PASSKEY`.
When the agent refuses a passkey use because erasure has started, the users
library preserves that reason and marks the refusal separately, so the browser
receives the erasing-account refusal without granting a session. A tab that reconnects
(`resolveSession`) is not checked here; ending the sessions of an account being
erased is the sessions holder's own step, `eraseAccountSessionsPart()` is live from HIL-1952.

**The data export part.** `AbstractDataExportAgent::runErasurePart()` is the
data export agent's participant step, run through
`AccountOperationParticipantStep::run()` with every refusal of that step and
its repeat. Inside the step's transaction, before any work and in this order,
an assignment of another operation is refused ("A data export erasure part must
belong to an erasure.") and one that names no account is refused ("A data
export erasure part must name its account."), each a `ValidationException` that
writes nothing. The work deletes the person's copy order, whatever its state,
and records `{"orders":1}`, or `{"orders":0}` when no order is saved, in the
same transaction; `AccountErasureDataExportResult` writes and reads exactly that
shape and refuses any other with an `InvalidFormatException`. No file is touched
inside the transaction. After the commit, and only when this call did the work,
the agent runs its own existing sweep (`forgetUser()`): the
expired orders, then every file of its directory that no ready order keeps,
then the state to the person's tabs. A failed removal is logged by the agent
and not repeated; an orphaned file is left to the hourly sweep or to the
agent's start, and an agent that has not started has no directory open and
leaves the sweep to that start. A repeated call returns the saved result with
no work and no sweep. The step is live from HIL-1952; the data export agent owns the database deletion and its existing post-commit file sweep. The price: the export agent's start waits for two more reader
collections; an order and a download each run one more indexed read, and a
build three more, because it reads the begun and the completed erasure before
it builds and again before publication, where it read only the completed one.

**The legal export part.** `LegalAcceptancesExports::eraseAccountPart()` is the
legal agent's participant step, run through
`AccountOperationParticipantStep::run()` with every refusal of that step and its
repeat. Inside the step's transaction, before any work and in this order, an
assignment of another operation is refused ("A legal export erasure part must
belong to an erasure."), one that names no account is refused ("A legal export
erasure part must name its account.") and one whose account still has
acceptances is refused ("A legal export erasure part waits for the account's
acceptances to go."), each a `ValidationException` that writes nothing. The
last refusal is the precondition: the part is assigned after the result of the
person's own part, whose deletion of the acceptances is HIL-1941's, so that no
file built after this step lists them; the order of the stages is HIL-1950's. The
work deletes the account's own order, whatever its state, and every ready and
failed export of any administrator, because which person is in which file is
not known, and records `{"ownOrders":<0|1>,"sharedExports":<n>}` in the same
transaction; a failure rolls the deletions and the result back together.
`LegalAcceptancesExportErasureResult` writes and reads exactly that shape and
refuses any other with an `InvalidFormatException`. No file is touched inside
the transaction. After the commit, and only when this call did the work,
`restartBuildAndTell()` first drops the file being built, runs the expiry sweep
and sends updated state to each affected administrator and the account's tabs.
The agent then clears toast sessions and removes the stored files for the
deleted orders. Failures after the commit are logged and not retried; an
orphaned file remains for the agent's start or hourly sweep. A repeated call
returns the saved result with no work and no tail. The step is live from
HIL-1952; the legal agent owns the database deletion and this post-commit order.
The price: the legal agent's start
waits for two more reader collections, and an order of an administrator pays
one more read, of whether their erasure has begun.

**The users library part.** `AbstractUsersLibraryAgent::runErasurePart()` is the
users library's participant step, run through
`AccountOperationParticipantStep::run()` with every refusal of that step and
its repeat. Inside the step's transaction, before any work and in this order,
an assignment of another operation is refused ("A users library erasure part
must belong to an erasure."), one that names no account is refused ("A users
library erasure part must name its account."), and one whose account has no
personal capture recorded yet is refused ("A users library erasure part waits
for the personal capture of its account."), each a `ValidationException` that
writes nothing. The work deletes the codes on the addresses of that account's
capture (`AccountErasurePersonalCaptureResult::of()->addresses`), whatever user
id they carry or none, then the codes carrying the account's user id, and
records how many rows both deleted as `{"verifications":<n>}` in the same
transaction; `AccountErasureVerificationsResult` writes and reads exactly that
shape and refuses any other with an `InvalidFormatException`. No address enters
the result. A repeated call returns the saved result and deletes nothing. The step is live from HIL-1952; the users library owns the verification deletion and its result, and the holder's borrowed erasure `Remove` is retired. The price: the library's start waits for
two more reader collections.

**The erasure captures.** What a later owner of the erasure needs from a row an
earlier owner deletes is written down before that row goes, in the participant
records and nowhere else (HIL-1940). The coordinator hands the captures out with
`AccountErasureCapture::recordAfterStart($operation, $catalog)`
(`Auth/AccountOperation/AccountErasureCapture.php`) once the root request has
started: it reconciles the census, then in a second transaction records every
capture still missing at the operation's current stage. It refuses, with a
`ValidationException`, an operation that is not an open erasure ("Only an open
erasure captures its anchors.") and one whose request has not started ("An
erasure captures its anchors only after its start."). A repeat records only what
is missing. A personal capture is answered by a step at the stage it was recorded at — the generic step
refuses `AccountOperationStepRefusal::ClosedStage` at any other — and a repeated
`recordAfterStart()` does not write its key again, so the coordinator does not
move the stage while a `capture:personal` assignment has no result.

- *The personal capture.* The assignment key is
  `capture:personal:<owner agent id>:<userId>`, that is
  `capture:personal:hilos_user:<id>:<id>` (`AccountErasureCapture::personalKey()`);
  the owner is `new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId)`,
  `user_id` is the account of the circle, and the payload is exactly
  `{"participant":"personal"}` (`AccountErasureParticipantCatalog::PERSONAL`). The
  person's agent answers it with `captureForErasure($assignmentId)`
  (`AccountErasurePersonalCapture`) through the generic participant step, once
  the account has closed. The result is exactly `{"addresses":[…],"project":{…}}`
  (`AccountErasurePersonalCaptureResult`): `addresses` is the identifier of
  every way in the person has, each once, in `sort(SORT_STRING)` order;
  `project` is a JSON object even when empty, and holds what the project's
  `erasureCaptureProjectPart($userId)` answers inside the same transaction — a
  throw there rolls the whole capture back. A repeat answers the saved capture.
  Its refusals are `ValidationException`s that no person sees: "An account
  erasure capture must belong to an erasure.", "An account erasure capture must
  answer its own capture assignment.", "An account erasure capture waits for
  the account to close."; a saved result of any other shape is an
  `InvalidFormatException`, "An account erasure capture must be
  {"addresses":[…],"project":{…}}.". `AccountErasurePersonalCaptureResult::of($operationId, $userId)`
  reads it back, and answers null while the agent has recorded none.
- *The delivery capture.* The assignment key is
  `capture:delivery:<channel>:<owner agent id>:<userId>:<first notification id>`;
  the owner is the channel's or the shard's owner that
  `DeliveryOwnerResolver::forDelivery()` names, as
  `new AccountOperationOwnerAddress($owner->type, $owner->index)`; `user_id` is
  the account, and the payload is exactly
  `{"participant":"delivery:<channel>","notificationIds":[…]}`, the numbers never
  empty, each once, ascending (`AccountErasureDeliveryCapture`). Any other shape
  is an `InvalidFormatException`, "An account erasure delivery capture must be
  {"participant":"delivery:<channel>","notificationIds":[…]}.". Each call
  records a portion for whatever delivery no portion names yet, so a
  notification that arrives after the start is caught by the next portion.
- *The chat's part.* In the chat `project` is `{"renameEvents":[…]}`, the
  event ids ascending, possibly `[]` (`ChatErasureRenameFeed`,
  `demo/chat/backend/Auth/ChatErasureRenameFeed.php`). The chat's person agent
  reads the ids of the feed events its rename journal names, then takes the
  journal off them in the capture's transaction
  (`UserRenamesActions::unlinkForErasure()`, a `DatabaseException` "A rename
  feed unlink for an erasure must run inside its participant transaction"
  outside one); the journal rows and the events stay.
  `ChatErasureRenameFeed::eventIdsOf()` reads the ids back, and raises an
  `InvalidFormatException`, "A chat erasure capture must carry
  {"renameEvents":[…]}.", for a capture without them. The chat's users library
  writes no feed line, and asks for no link, for a person whose erasure has
  begun (`afterUserRenamed()`), which narrows the race of a late link.

A key starting with `capture:` is not part of the census:
`AccountOperationExpectedSet` counts only `expect:` and `wait:`. No assignment,
no other result and no log copies an address: the personal capture is the one
record that holds them, and the person's final transaction deletes it
(*The erasure final*; live in the HIL-1952 erasure path).

The readers and their predicates:

- the coordinator calls `recordAfterStart()` right after the start it won,
  sends the person's agent the frame of its `capture:personal` assignment, which
  calls `captureForErasure()`, and calls `recordAfterStart()` again on the
  deleting owner's refusal "deliveries not captured"; the coordinator owns the
  key and stage of each work assignment;
- the person's agent, in `eraseAccountPersonalPart()`, deletes nothing while
  `AccountErasurePersonalCaptureResult::of()` is null or
  `uncapturedNotificationIdsOf()` names a notification of the person, read in
  the same transaction, for which its process reads `notificationDeliveries`;
  it answers with `AccountErasureUncapturedException`, whose numbers are empty
  for the addresses (live in the HIL-1952 erasure path);
- the users library takes the addresses from
  `AccountErasurePersonalCaptureResult::of()->addresses`, refuses while that is
  null, and writes only numbers, never an address, into its own result
  (live in the HIL-1952 erasure path);
- each channel or shard reads every capture portion for its channel and account,
  gets the notification ids from `notificationIdsOf()`, and deletes only rows
  it owns under the current route. It refuses if any of its own rows for that
  account remains uncaptured (live in the HIL-1952 erasure path);
- `ChatAgent` takes the event ids from
  `ChatErasureRenameFeed::eventIdsOf(AccountErasurePersonalCaptureResult::of(…))`
  for every account of the circle, and refuses while one is null. The read and
  refusal are live in `ChatAgent::eraseAccountPart()` from HIL-1952.

The price: the names in the room's "renamed" lines disappear at the capture,
because a line reads them from the journal row linked to it; the lines
themselves go at the chat's step, and while the erasure waits they stand without
names. A link frame after the capture is served as before; an event it links
that the capture did not keep stays a line without names, which is accepted, and
a repeated link onto a captured event is harmless (the argument of HIL-1897).
The capture and its coordinator path run in the live erasure (HIL-1952).

**The erasure final.** The last move of the person's agent in the erasure of its
account (HIL-1942), after which the agent stops. The coordinator's side is
`AccountErasureFinal` (`Auth/AccountOperation/AccountErasureFinal.php`), the
agent's step `AccountErasurePersonalFinal::run()`, entered by
`AbstractUserAgent::finishAccountErasure()`.

- *The assignment.* `AccountErasureFinal::recordMissing($operation)` writes, for
  every account of an open erasure's circle whose final is stored at no stage,
  one assignment at the current stage, in circle order; a repeat writes only
  what is missing. The key is `final:personal:<owner agent id>:<userId>`
  (`AccountErasureFinal::personalKey()`, `final:personal:hilos_user:7:7` for the
  account 7), the owner is
  `new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$userId)`,
  `user_id` is the account of the circle, and the payload is
  `{"participant":"personal"}` (`AccountErasureParticipantCatalog::PERSONAL`),
  as in the census. The key is no part of the census, like a `capture:` key.
- *What is refused before any work, with nothing written.* A call on an
  assignment that is not of an erasure, is not this person's final
  (`personalKey()`) or names another account is a `ValidationException`; the
  caller, the assignment and the stage are judged by the generic step
  (`AccountOperationParticipantStep`), a closed stage included.
- *The gate.* Read from the database in the step's transaction
  (`AccountErasureFinal::missingPartsOf()`): every `expect:` part of the account
  has a result, no `wait:` of the account is left unlifted, and no account is
  folded into this one (`userMerges->foldedInto()`). Otherwise the agent answers
  `AccountErasureFinalWaitsException`, whose `participants` name the parts and
  `foldedUserIds` the folded accounts, and nothing is deleted. No person reads
  its words; the coordinator tells the wait by its type and its two lists.
- *The transaction.* One local transaction, in this order: the deletion request
  is completed (at the operation's root the request the operation began, whose
  `complete()` must hold; at a folded account the live request, if it has one),
  then the account's own `userMerges` row, its rename journal
  (`deleteByUser()`), its personal capture result
  (`AccountOperationResultsActions::deleteForAssignment()`) and its `users` row.
  The step records the result in the same transaction: exactly
  `{"request":<id>}` for the request this final completed and
  `{"request":null}` for an account with none (`AccountErasureFinalResult`).
  `completed_at` of a request is written only together with the deletion.
- *The refusal.* `RESTRICT` rolls the whole final back, the request included,
  so nothing is half-deleted. Only the database's "row is referenced" refusal
  (1451) is the final's refusal: `run()` asks it for the referencing table
  (`ForeignKeyConstraintException::referencingTable()`), keeps the name only
  when `Schema::getTable()` knows it (null otherwise) and throws
  `AccountErasureFinalRefusedException`, which carries the account and the table
  and never the engine's text, which stays on the previous exception for the
  log. A refused child insert (1452) and every other failure are ordinary
  failures of the step. The agent runs on, and the repeat is the same
  assignment.
- *The stop.* After a committed final, and after the saved result of an earlier
  attempt, `finishAccountErasure()` calls `selfStop()`; a refusal or a wait
  leaves the agent running. The stop on the deleted row stays as the second
  line.
- *The safe reason.* The coordinator answers the refusal with
  `AccountErasureFinal::refused()`: it reconciles the census first, in its own
  transaction, so that a part which appeared since the opening — the owner of
  the row that refused — is expected as well, and then keeps the reason in a
  second one. The column `hilos_account_operation.final_refusals` is NULL or an
  object `{"<userId>": "<table>" | null}`; the operation's `finalRefusals`
  reads it as a map, NULL as empty, and
  `AccountOperationActions::noteFinalRefusal()` writes it, replacing the key of
  the account on a repeat. A reason is the number of the account and the name of
  a table, never the engine's text. It stands until a repeat of the final
  passes: `refusalsOf()` lists the accounts of the circle with a reason and no
  result of their final, in circle order, and the card's erasure block carries
  them (*Erasure Cutover And Observation*).
- *The end.* `AccountErasureFinal::end()` checks `hasFinal()` of every account
  of the circle and, only when each has its result, finishes the operation as
  completed, in one transaction; otherwise it writes nothing.
  `missingPartsOf()` gates the final. The card's `parts[].done` reads whether
  that assignment has a persisted result.

All of it is live in HIL-1952. The coordinator hands out finals and their
frames, answers waits and refusals, and ends the operation. The personal agent
uses its production `Remove` claims for its `users` row, rename journal and
merge row; HIL-1952 retired the holder's borrowed erasure `Remove` rights.

**Creators.** `AbstractUsersLibraryAgent::OWNS_DB`
(`Auth/Library/AbstractUsersLibraryAgent.php`) creates `users`,
`identities`, `passkeyCredentials`, `secondFactors`,
`secondFactorBackupCodes`, `secondFactorResets` and `accountDeletions`,
holds `verifications` and `registrationReservations`, and creates
`legalAcceptances` with `Add` only; the person's agent drops the unfinished
enrolment and the old backup-code set before a new one (HIL-1903, HIL-1904).
`AbstractOAuthAgent` (`Auth/OAuth/Agent/AbstractOAuthAgent.php`)
creates `identities` for a linked provider account.

**The notification side.** `NotificationDelivery`
(`Database/Entity/Item/NotificationDelivery.php`) points softly at its
notification — `notification_id` with no key, so the two are pruned apart —
and is cut by that column (`_setVia`), so its set tree reaches the person
through the notification and a delivery whose notification is gone is in
nobody's set; `last_error` is personal data (an address, a token, a body) and
is masked under anonymization. `AbstractDeliveryChannelAgent`
(`Notification/Delivery/AbstractDeliveryChannelAgent.php`) claims the journal
for `Update` alone and edits the attempt it runs; after a start or a freeze it
adopts the pending rows of its channel (`adoptPendingRows()`, HIL-1135) —
which only happens where the channel is registered at all.
`AbstractNotificationsLibraryAgent`
(`Notification/Library/AbstractNotificationsLibraryAgent.php`) creates
notifications and deliveries and claims only `Add` on deliveries. Delivery
channel agents own `Update` and `Remove` for their saved channel/shard rows,
including the reset of a failed row an admin retries and bounded-page retention
of terminal rows older than the retention; the row guard checks both operations
against the saved delivery identity and route. An admin retry reaches the owner
as a signal: the library reads the saved identity, finds the owner and passes the
request on, and the owner resets the row, queues it and answers the page. The
library also creates push subscriptions (`subscribe()`, `Add` alone): it
routes an endpoint by its fresh row, and a foreign one goes to the former owner's
release before the new row is created.
`NotificationDispatcher` (`Notification/Delivery/NotificationDispatcher.php`)
reads the channel's enabled setting only to decide whether to dispatch. The restore
path (`Notification/Delivery/RestoredDeliveries.php`) settles every pending
row as failed with a raw `UPDATE`, in a process with no agent.

**Whole-table statements at the holder.**
`SecondFactorTrustsActions::capLiveUntil()` and `deleteAll()` once applied the
administrator's trust term to every person's trust at once, from the sessions
holder; both are gone (HIL-1908), as is the switch-off's use of them (HIL-1909).
Each person's agent cuts or erases that person's trusts on the sweep's frame.

**Test-only legal hold.** `LegalAcceptancesActions::forgetLaterThan()`
(`Database/Actions/Collection/LegalAcceptancesActions.php`) removes later
acceptances through the person's personal agent. After validating
`test:legal:hold`, the users library first creates the held acceptance if
missing in its own transaction without publishing, then delegates the removal.
On success it rereads the document's rows and refuses a missing held acceptance
or any later declared acceptance with a request to retry. Only a satisfied
postcondition permits publication and the final standing reply. A refusal or
failed finish publishes nothing explicitly and keeps the prepared acceptance;
the next attempt starts from the rows left behind.

**The chat.** `ChatAgent::OWNS_DB` holds the four room tables whole and only
reads the rename journal to find linked users during history cleanup. Each
journal's personal owner unlinks its own rows on ChatAgent's request. The
chat's `UsersLibraryAgent` (`Agents/Hilos/UsersLibraryAgent.php`) also claims `events` and
`eventUserRegistrations` by its kind — adding and removing — to write the
"registered" line and the rename's feed line from its own process. The chat's
`SessionsLibraryAgent` moves the loser's authorship in the merge
(`rePointAuthor()`). In the erasure, `ChatAgent::eraseAccountPart()` deletes
attachments, messages, registration events and feed events in its own
participant step. It does not read the feed events from the rename journal:
`UserRename.event_id` (`Database/Entity/Item/UserRename.php`) is a unique key
onto the room's event, and once the person's agent has taken its journal off
them nothing there knows which event told of the person. The person's agent
keeps their ids in its erasure capture in that same transaction
(`ChatErasureRenameFeed`), and the chat's part reads them from there.

**Neighbouring rules.** `afterCommit()` releases what waited for the commit
and is not a durable queue: nothing of it survives a restart
([transactions.md](../orm/transactions.md#what-waits-for-the-commit-and-what-does-not)).
[work-in-portions.md](../signals/work-in-portions.md) names `renamePerson()`
and the sessions holder's erasure part, `eraseAccountSessionsPart()`, as
examples of one owner's related writes in one transaction, and says a step
after the commit is not retried; the erasure keeps both rules inside one owner
and adds the boundary between owners.

**What the target protocol replaces.** Said in one place so that a leaf does
not keep one of them by habit:

1. **One transaction across other owners' tables** — the retired holder and
   chat-holder grants no longer let one participant write another owner's rows.
   Each owner writes its own rows in its own transaction; between owners there
   is a recorded fact and a signal.
2. **Taking another owner's set as a transfer source** — both account owners
   check their own rows and reservations, and the loser sends a saved copy of
   its own rows to the survivor. No participant reads another owner's business
   set to build the transfer.
3. **Stopping before the operation is finished** — a merge row no longer
   triggers an immediate `selfStop()`. The loser finishes its cleanup after
   receipt and then stops; after a failure it rises only to finish.
4. **Proving the end by foreign keys alone** — `RESTRICT` holds the person row
   only against hard keys. The end is proved by the recorded result of every
   required participant, the soft references and the service copies included,
   and the final `DELETE` of the person row is the last check, not the proof.

HIL-1952 retired the borrowed declarations used only by erasure. HIL-1999
retired the remaining borrowed merge declarations and their executable debt.
The merge owners now perform those writes in their own transactions.

## Operation And Participant Records

The protocol rests on records, not on messages: a message is how a participant
learns that there is work, and a record is what makes the work owed. The
operation, assignment and participant-result records are backed by shared
framework ORM carriers. HIL-1952 connected the erasure participants and
HIL-1999 connected the merge participants to the live path. The detailed
[erasure](#erasure) and [merge](#merge) norms are recorded here. Other physical contracts remain with their
implementing leaves under the gate (*Scope And Status*).

**The coordinator and its operation record.** The sessions holder coordinates
both operations, as it holds authorization today: it is the process that sees
the sign-in, the request falling due and the administrator's merge command,
and it stays where a sign-in exists. For each operation it keeps one working
record of its own — the kind, erasure or merge; the account ids and, for an
erasure, the circle of merged accounts, immutable once written; for a merge
the chosen password fate and second-factor fate; the stage the operation has
reached; and the participants it expects, each by a durable address — the
agent type and index, or the library's or channel's type and shard — never by
a process id or a node name, which do not survive the restart the record
exists for. In the protocol, the holder is the single changing writer of this
working record, lawful under *Scope And Status*: it is not a claim over
anybody's business table, and holding it gives the holder no write into a
person's rows.
For both erasure and merge, `Hilos::$db->accountOperations`,
`accountOperationAssignments` and `accountOperationResults` are mounted
framework ORM chains in `framework/backend/Database/Context/HilosDbContext.php`.
`AccountOperation` stores either the immutable erasure request, root and
ordered circle, or the merge pair, fates and supplied protection flags,
together with kind, current stage and optional outcome. Its initial
`preparing` stage records a plan; it does not mean the erasure or merge has
started. An `AccountOperationAssignment` is an immutable plan entry with a
stable owner address. `AbstractSessionsLibraryAgent` is the sole creator and
changing writer of operations (`Add`/`Update`) and the sole creator of
assignments (`Add`); see its `OWNS_DB` declaration in
`framework/backend/Auth/Library/AbstractSessionsLibraryAgent.php`. Assignments
have no update or remove action.

The operation and assignment record actions return exact repeats and reject
conflicting contents. `recordMerge()` returns the same open merge for an exact
repeat and refuses a conflicting pair or choice. `AccountMergeOpening::open()`
records that operation, its survivor and loser preparation assignments and the
project's expected composition (`AccountMergeProjectCensus`) in one
transaction; `AccountMergeOpening::reconcile()` appends to an open merge what
that census expects and it does not carry yet. No live caller invokes the merge
opener or the reconciliation, and the common
`recordErasure()` path does not replace the due and force-purge paths.
`advance(expectedStage, nextStage)` is a low-level
compare-and-set; `finish(expectedStage, outcome)` conditionally closes the
operation with the coordinator-supplied outcome. These actions persist facts
but do not validate business transitions, write the request owner's start, or
prove participant work. `openPage(AccountOperationKind, ...)` and
`openForOwner(AccountOperationKind, ...)` return bounded pages ordered by
`id ASC` (limit 1–1000), and the required kind keeps erasure and merge reads
separate. `openForOwner()` can read open assignments without a registered or
live owner. The entities, actions, readers and opening API are in
`framework/backend/Database/Entity/Item/AccountOperation.php`,
`framework/backend/Database/Entity/Item/AccountOperationAssignment.php`,
`framework/backend/Database/Entity/Item/AccountOperationResult.php`,
`framework/backend/Database/Actions/Collection/AccountOperationsActions.php`,
`framework/backend/Database/Actions/Collection/AccountOperationAssignmentsActions.php`,
`framework/backend/Database/Actions/Collection/AccountOperationResultsActions.php`,
`framework/backend/Database/Actions/Item/AccountOperationActions.php`,
`framework/backend/Database/Object/Collection/AccountOperations.php`,
`framework/backend/Database/Object/Collection/AccountOperationAssignments.php`,
`framework/backend/Database/Object/Collection/AccountOperationResults.php`, and
`framework/backend/Auth/AccountOperation/AccountMergeOpening.php`.

For merge, it records the immutable pair, password and second-factor fates
and protection flags shown by the browser, with the current stage and expected
participants at stable addresses. Finding the same work again creates no
second operation.
The merge extends the common durable erasure basis without a second operation
journal or participant catalog. Its initial operation and assignments are
stored on the common `AccountOperation*` carriers.

**The participants' records.** A participant is every owner the operation
needs: the agent of each account in the circle, the users library, each
channel, each export executor, the holder itself for its sessions and its
access log, `ChatAgent`, and whatever owner a project declares for its own
rows. Each writes two kinds of record of its own. A *preparation* is what it
has committed to or captured before anything irreversible happens, and it may
include the owner taking off its own links that the next owner's work cannot do
without: in a merge the reservation of its account, in an erasure the personal
agent's capture — the current addresses of the ways in and the project's part,
in the chat the event ids of the rename feed whose links the same transaction
takes off — read while the rows that carry them still exist (*The erasure
captures* above). A *result* is the statement that its part is done and what
it did, enough to make a repeated ask answer the same without doing the work
again. A participant writes its work and its result in ONE transaction of its
own: the rows deleted or re-pointed and the result that says so stand or fall
together, so a result present is the proof that the work is done
([transactions.md](../orm/transactions.md)). The coordinator may create a
participant's initial *assignment* — "this part of this operation is yours",
with what the part needs — and never edits a result a participant recorded.
The two kinds keep the two writers apart: the assignment is the coordinator's
statement, the result the participant's, and neither rewrites the other's.
For erasure, the participant result carrier and generic step are built: the
participant's work and result commit or roll back together in one local
transaction, a repeat returns the saved outcome, and the coordinator does not
edit it. HIL-1952 connected erasure participants to this step; HIL-1999
connected merge participants. Each preparation remains its participant's own
record.
For merge, each personal owner records its own reservation and captured
identity as preparation. `AccountMergeRelease::run()` records that owner's
immutable release result for its `ReleaseLoser` or `ReleaseSurvivor` assignment
without changing a saved preparation result. The personal assignment match
recognizes both release duties, dispatched by the live coordinator.
The prepared `AccountMergeGiving::run()` commits the loser's business
work, nonsecret give result and separate private hand-over copy together; see
[The Prepared Loser Give](#the-prepared-loser-give). The survivor commits its
own work with its exact `go`/`receive` receipt in the prepared
`AccountMergeReceiving::run()`; see [The Prepared Survivor Receipt](#the-prepared-survivor-receipt).
These remain the
participants' records, never results rewritten by the coordinator.

**One operation, one stage, one answer.** Every assignment, frame and result
names the operation and the stage it belongs to. A result that names another
operation, a stage already closed or an operation the coordinator does not
know advances nothing: it is answered with what the record already holds, or
refused, and never applied to the operation that happens to be open now. The
record of the end is a fact of its own, written after the required results are
in, and distinct from the fact of the start; a failure between the two leaves
an unfinished operation, not a rollback and not a cancellation — once started,
both operations are finished (question 1).
For erasure, assignments and answers are correlated by operation and stage;
duplicate, old, closed or foreign answers cannot advance the current operation.
After start, only forward completion remains; no SQL transaction spans frames.
For merge, prepare, go, rows, receipt, cancel, cleanup and end are logical
steps; the physical `AccountMergeStage` values remain `preparing`, `go` and
`cancelling`. The prepared coordinator correlates answers by the immutable
operation, assignment, stage, canonical sender and saved owner-bound result.
Only an original project expectation may cross from preparing into current go
through the existing `isCarriedAssignment()` gate; old personal prepare and
release answers receive no exception. Duplicate, old, closed or foreign answers
cannot advance another operation or repeat its business work. No transaction
spans frames, and the coordinator builds no second admission fence.

**Why these records are rows in `Hilos::$db`.** The operation and the
obligations have to outlive the death of any process, a restart of every node
and the restore of a backup: an owner that was asleep when the signal came
must find its obligation when it starts, and an installation that comes back
from an archive must find the operations that were under way. By
[framework-first.md](../framework-first.md) the question is what the tree
already has. `AccountDeletion` and `UserMerge` are the nearest — a request
with its two endings, a tombstone with its moment: a trace and a tombstone,
and nothing of a started phase, of a set of confirmations or of a stored
result (*Current Implementation*). `Database::afterCommit()` is deferred
sending, not durability: a closure held for a commit dies with the process
([transactions.md](../orm/transactions.md#what-waits-for-the-commit-and-what-does-not)),
and the frames it releases are stored nowhere. A runtime collection is the
live memory of the cluster and does not survive its restart; it is also
outside the frame the owner set for this page (the database only). So the
records are durable rows, read and written through the ordinary collections,
item actions and collection actions
([db-collection.md](../orm/db-collection.md#choosing-where-new-logic-belongs)),
each with the one owner [truth-source.md](truth-source.md) gives a collection
— and not a cursor in an agent's memory, not a new runtime overlay, not a
repository or a query of their own. Whether a record is a row of a table of
its own or a column beside an existing row, and what it is keyed by, is the
leaf's; what this page fixes is that it exists, what it holds and who writes
it. Every table that carries such a record is one an archive carries, so each
needs its anonymization verdict when it is added
([backup-anonymization.md](backup-anonymization.md#adding-a-table-or-a-column)):
the preparations and results hold addresses, ids and, in a merge, the rows
given away.

The shared operation, assignment and result records live in the
framework-owned tables `hilos_account_operation`,
`hilos_account_operation_assignment` and `hilos_account_operation_result`.
Ordinary backup preserves these rows; anonymized restore PURGEs all three
tables. Their moments use signed epoch milliseconds. The request ID,
root/circle user IDs and assignment user ID are soft references;
`operation_id` on an assignment has a cascading foreign key to its operation.
Each Entity declares whole-table `PURGE` and `_journaled = false`; the mappings
are in `framework/backend/Database/Entity/Item/AccountOperation.php`,
`framework/backend/Database/Entity/Item/AccountOperationAssignment.php` and
`framework/backend/Database/Entity/Item/AccountOperationResult.php`. The
participant result has an owner set and assignment key as described above.
Its business payload and the participants that use it remain with their
implementing leaves. This document chooses none of those payloads.

These carriers already have their whole-table `PURGE` verdicts and backup
coverage.
The loser's private copy has that coverage too: `hilos_account_merge_copy`
is a separate, non-journaled `PURGE` carrier, including its password hashes,
factor secrets, addresses and backup codes. Its physical columns are
`assignment_id`, `owner_agent_id`, `payload_json` and `created_at`; the assignment
is its primary key and a cascading foreign key. Only metadata enters the
Entity/Object mapping. The DB-only `payload_json` is completed at birth inside
the participant transaction and stays out of ORM hydration, ordinary
serialization and `DB_SYNC`. Real collected-registry coverage, compatibility
and anonymization checks cover this carrier; neither classification nor
backup coverage waits for the live switch or later recovery evidence.

## Erasure

The order below is one operation over a circle of accounts. "The agent" is the
agent of the account whose step is described; a circle has one agent per
account, and they share no transaction. The operation is the live due and
force-purge path from HIL-1952; each participant commits its own work and result
in its local transaction. The completed cutover is described in
[Erasure Cutover And Observation](#erasure-cutover-and-observation).

1. **The request falls due; preparation is not start.** The users library
   creates the request and the agent may cancel it (HIL-1409). The holder's
   minute sweep exists today. For the split path, the holder fixes the circle
   from the merge relationships before breaking any link, with folded leaves
   before their survivors, and derives the required owners from the
   installation's activated tables (live in the HIL-1952 erasure path;
   see *The erasure census*). The
   implemented operation and assignment `record()` actions can persist that
   plan at stable owner addresses in the logical *preparing* stage; an exact
   repeat returns the same records and a conflict is refused. The stored stage
   means only that the plan exists: recording it does not start erasure or
   change the person's account. The due sweep and force-purge use this live owner-local path from HIL-1952.
2. **The start is won or lost at the request's owner.** The holder hands the
   start to the personal agent. The agent conditionally records start against
   cancel: a cancel that won leaves the account intact and starts no
   participant's erasure. A later cancel is refused.
   A folded account with a live request has its owner start that request as
   the first step of its own part; no request is created for an account that
   has none. The start result commits with that participant's work, in the
   step's transaction. When start loses to cancel, the coordinator closes its
   operation as *not started*. Start is distinct from completion. HIL-1936
   supplies the operation stage/outcome carrier; it does not record this
   request-owner start. HIL-1939 prepared the start/cancel carrier and participant
   step (*The erasure start*), which the live path uses from HIL-1952.
   `completed_at` records the personal owner's final transaction after required
   results and deletion of its person row.
3. **Access closes; the same agent finishes.** The started fact closes ordinary
   sign-in, actions and late ordinary frames (*The closed account*; the fact is
   written by the personal owner when its start transaction commits). The
   personal agent, including that of a folded
   account, accepts only the internal continuation of its own operation and
   stops after its own part: the stop follows its last own write, the final of
   step 7, and is made by the agent itself (*The erasure final*; live in HIL-1952). This finishing mode does not turn
   `AddressablePerson::require()` into a general bypass for a missing or folded
   account. The sessions holder's own
   participant step, `AbstractSessionsLibraryAgent::eraseAccountSessionsPart()`,
   releases all four `RESTRICT` session references, signs out
   the affected sessions, clears second-factor waits and lowers closed-access
   cards, and records `{"sessions":<n>,"accessLog":<m>}` in the same
   transaction; the tabs are told only after the commit. Session rows stay for
   the ordinary sweep; the holder remains their writer
   ([Session Ownership](entity-libraries.md#session-ownership))
   (live in the HIL-1952 erasure path). The visible progress and refusals have
   their separate owners under [observation](#erasure-cutover-and-observation).
4. **The participants are derived, not assumed.** Required participants are
   derived from activated tables before destructive work. A table *never activated*
   in the installation owes no participant because no rows can exist. An
   activated table with its owner contributes that owner. Existing rows with
   no registered owner leave visible, open work: the operation waits, cannot
   count the part done and cannot appoint a fallback writer (live from HIL-1952). Projects declare their own participants
   under the same distinction; there is no fixed "twenty tables, always"
   list. A project names the owners of its tables, and the tables it leaves
   out with a reason, in its `AccountDataOwnerDirectory`, and the daemon
   refuses to start over a mounted table that no participant covers and no
   exemption names (`AccountErasureCoverageGuard`); the census over those
   owners is live in the HIL-1952 erasure path.
5. **Capture before an anchor goes.** The receiving obligations must carry
   what their owners need without reading a row already gone (*The erasure
   captures*):

   - Once its account has closed, the personal owner captures the current
     identity addresses from its own set, with the project's part, into its
     own result before it deletes identities. The users library reads those
     addresses from the personal owner's capture; its assignment does not
     copy them.
   - After the start, the coordinator records portions of capture
     assignments to the channels, each naming the notifications whose
     deliveries are that owner's, before the personal owner deletes
     notifications. The channel can then find its deliveries without a join
     to a vanished anchor.
   - The chat's personal owner reads the rename-feed event ids while its
     journal still names them, takes the journal off them in the same
     transaction and keeps the ids in its capture. `ChatAgent` reads them
     from the personal owner's capture; chat does not become a journal
     writer.

   Each preparation remains its participant's own record. No soft link may
   disappear before its next owner has a saved obligation over it, and the
   predicates say so: the ways in go only while the personal capture is
   present, and a notification only while no delivery of it is uncaptured.
   All of it is live in the HIL-1952 erasure path.
6. **Each owner deletes its own children and confirms.** The generic participant
   step that commits each part's business work and result in one local
   transaction is built; these business participants remain to be connected:

   - The personal owner (`eraseAccountPersonalPart()`) waits with
     `AccountErasureUncapturedException` until its addresses are captured and
     every delivery of its notifications is named by a portion. Then it deletes
     device keys before identities; its second factors, backup codes, resets,
     settings and trusts; step-ups and legal acceptances; and, where the project
     activated them, notifications, preferences, push subscriptions and its
     photo row. It follows FK order, records
     `{"rows":{"hilos_user_photo":1},"files":[<id>]}` — `{"rows":{},"files":[]}`
     without a photo or its table — in the same transaction, and hands the
     photo file to the existing files path after commit (live in the HIL-1952 erasure path).
   - The users library deletes verifications by user id and by the
     addresses of the personal owner's capture, including codes with no user
     id or another user id on those addresses (live in the HIL-1952 erasure path).
   - Each delivery channel/shard deletes its own deliveries by its capture
     assignments and records `{"deliveries":<n>}`. The step is prepared, with
     live since HIL-1952. Its started-recipient guard is
     built: it does not begin an attempt for a recipient whose erasure has
     started, and an administrator retry is refused in the channel's own words.
     The live personal-owner start produces the started fact (HIL-1952). Ordinary
     channel rights, disabled-owner duties, retention and retry retain their
     separate HIL-1893/HIL-1894/HIL-1902/HIL-1910 boundaries in
     [Ordinary Transitions](#ordinary-transitions).
   - The data export owner deletes its own DB order of the person with its
     result, keeping the existing publication checks and post-commit file
     path: `AbstractDataExportAgent::runErasurePart()` records
     `{"orders":<n>}` in the same transaction and sweeps the files after the
     commit (*The data export part*; live in the HIL-1952 erasure path).
   - The legal export owner deletes the person's DB orders with its result.
     Shared exports retain their existing fate: ready and failed exports
     leave with their files, and an export being built starts again. Disk
     cleanup remains outside the DB guarantee:
     `LegalAcceptancesExports::eraseAccountPart()` deletes the account's order
     and every ready and failed export and records
     `{"ownOrders":<0|1>,"sharedExports":<n>}` in the same transaction, and the
     files, the restart of the build and the sweep follow the commit (*The
     legal export part*; live in the HIL-1952 erasure path).
     The part waits for the account's acceptances to go, so it is assigned
     after the personal owner's result (HIL-1941; the order of the stages is
     HIL-1950's), and an administrator whose erasure has begun orders no new
     export.
   - The sessions holder deletes access log entries as its own confirmed
     part, in the same step and transaction as the sessions, and remains the
     owner of its working rows: `eraseAccountSessionsPart()` records
     `{"sessions":<n>,"accessLog":<m>}` (live in the HIL-1952 erasure path).
   - Each declared project owner deletes its own rows with its result: the
     class registered under the owner's type implements
     `AccountErasureProjectOwner`, and calls `AccountErasureProjectStep::run()`
     in its own frame with its assignment, which asks it to erase its part of
     that account inside the step's transaction and records its answer as the
     result in the same transaction. The payload is the rows it deleted per
     family it names and the registry files they pointed at
     (`{"rows":{…},"files":[…]}`); the files leave after the commit. This is
     live in the HIL-1952 erasure path. In chat,
     `ChatAgent` deletes the person's messages across every room, attachment
     links, registration details and feed events in one transaction with its
     result. It takes the event ids from the capture of the personal owner,
     which took the journal off them in the same transaction; the users
     library stays the creator of registration/feed events. Attachment file
     removal remains after commit, and other people's rows mentioning the
     erased person stay under their existing `SET NULL` rule. The part runs
     through `ChatAgent::eraseAccountPart()` from HIL-1952.
7. **The person row goes last, under `RESTRICT`.** After all required results
   for that account — holder, library, channels, export and project owners —
   and when no wait for an owner stands and no account is folded into it, the
   personal owner deletes its merge row, `userRenames`, its own capture — the
   one record holding the addresses (the owner's decision of 2026-11-08) — and
   `users`, and completes its existing request, in one final local transaction
   (*The erasure final*). `RESTRICT` is the last
   safeguard, not the proof of completion: refusal rolls back only that final
   transaction and leaves the operation open, and the coordinator keeps a safe
   reason on it — the account's number and the name of the table that still
   references it, never the engine's text. A committed
   `completed_at` means the actual end, never that erasure was queued. The
   request remains the trace it is today; an account without a request gains
   none, and its final's result says so (`{"request":null}`). The final is
   built, live in the HIL-1952 erasure path.
8. **The circle finishes leaves first.** The fixed circle puts every folded
   account before its survivor (live in the HIL-1952 erasure path). Each folded
   account's owner removes its own `userMerges` row while the survivor still
   exists, so erasure does not rely on the survivor key's `SET NULL`: the row
   goes first in the folded account's final, and the survivor's final waits
   while any account is still folded into it (*The erasure final*; live in the HIL-1952 erasure path). Stage assignments and answers carry the
   same operation and stage throughout; duplicate, old or foreign answers
   advance nothing. Once started, the circle is completed forward without a
   transaction across frames.
9. **The end follows the last result.** Each personal agent makes its last
   own write before stopping, its final; the coordinator records the end of the
   circle only after the final of every account of the circle
   (`AccountErasureFinal::end()`; live in the HIL-1952 erasure path).
   Coordinator start and subsequent passes over open work, and participant
   start or an operation frame, select the unfinished obligations from DB and
   continue them; a lost frame needs no new external event. There is no saved
   cursor and no DB poll on every tick: the holder's pass keeps only a position
   in memory, a new holder starts it again from the facts, and a tick with no
   pass running reads nothing (*The erasure recovery*; built, and the due and force-purge paths open erasures from HIL-1952).
   Existing post-commit file removals and export sweeps retain their present limits:
   failures are logged, with no automatic retry or new external guarantee
   ([Related Records](../signals/work-in-portions.md#related-records)).

### Erasure Cutover And Observation

**The live switch.** HIL-1952 moved both the due sweep and force-purge to the
owner-local erasure path, removed the old erasure calls and borrowed rights, and
kept rights still needed by merge. A committed start leaves an unfinished
operation durable for recovery; later participant refusals do not roll back
earlier owners or move the date back. No toggle or exception was added.

The following behavior is live on that path:

- The existing `test:account:force-purge` triggers the live owners and holds
  its reply until the request's operation closes, for at most
  `CommandChannelWindows::AGENT_WAIT_SECONDS`. The reply's `outcome` is
  `completed` with `rowsErased`, `starting` while the root's start is
  unanswered, or `erasing` with the `erasure` block below; an operation closed
  as `NotStarted` keeps the earlier refusal "The deletion of user N was canceled
  before it could be carried out". Running the command again watches the same
  operation: an open one continues, and an erased request answers `completed`
  from its saved results without new work. The CLI prints "Erased" only for
  `completed`; an unfinished erasure prints the stage it reached and exits 6,
  and a lost reply says the outcome is unknown. The CLI writes no DB row and
  introduces no universal clock.
- The card's first `page_response` and existing `hilos_account_standing_state`
  frame carry `accountErasure`: `null`, or `{startedAt, parts:
  [{participant, owner, userId, done}], waits: [{participant, userId}],
  refusals: [{userId, table}]}` (HIL-1954, HIL-1942). The standing shows
  `erasing` after `merged` and before `blocked`.
  Both carriers use the same viewer map: ids follow `users.id`, the other
  progress fields are not personal, and no names travel in the block. Existing
  admin view mode enables no mutation. The delivery is live from HIL-1952;
  `done` reflects whether the assignment has a persisted result.
  `refusals` is the safe reason of the finals the database refused: the
  accounts of the circle with a reason kept on the operation and no result of
  their final yet, in circle order, `[]` when there are none
  (`AccountErasureFinal::refusalsOf()`, read by the block's resolver; *The
  erasure final*). The viewer sees the account's number and the name of the
  table that still references it, never the engine's text. The coordinator
  writes a reason when it answers a final refusal.
- The people list shows the progress as a bar of work under the row of every
  account of the circle (HIL-1955): a row bar under the key
  `hilos-account-erasure` whose detail is the card's block above, counted by
  the parts that are done. The rows, their order, the count and Apply stay as
  they were, and the people set gains no synthetic operation row. The card
  shows the same facts as its block (HIL-1954). Both read durable erasure facts
  and stay live from HIL-1952.
- Vue's list shows "Being deleted" beside the account's name and a caption
  above its row bar (HIL-1956), live from HIL-1952. Vue's card shows
  the "Being deleted" badge, the line "This account is being deleted." under the
  header and the erasure's progress — the parts, the owners awaited and the
  refusals — and offers no action (HIL-1957); after the end it is the live 404.
  It follows the live start (HIL-1952). These display leaves do not implement
  actions.
- Every "Keep my account" of the person — the profile window, the standing
  strip and the freeze screen — is the one action
  `hilos_account_deletion_cancel`. Before start it keeps the grace and the
  conditional cancel/start race. After start it is refused with "This account
  is being deleted." — before the hop, or at the person's agent when the start
  overtakes the cancel on the hop: the request stays begun, no
  `hilos_account_deletion_state` is fanned, and the browser says the sentence
  in the window's error line or as a toast. The refusal is built and stays
  live from HIL-1952 (HIL-1939, HIL-1958).
- "Cancel deletion" on the administrator's card is the action
  `hilos_user_deletion_set` with `scheduled` false. Before start it keeps the
  conditional cancel/start race. After start it is refused with "This account
  is being deleted." — before the hop, or at the person's agent when the start
  overtakes the cancel on the hop: the request stays begun and no
  `hilos_account_deletion_state` is fanned. A window opened before the start
  says the sentence in its error line, and the arriving `erasing` standing
  closes it (HIL-1957). The refusal is live from HIL-1952
  (HIL-1939, HIL-1959).
- The existing merge refuses when any affected account is in the circle of an
  open erasure operation, from preparation on (owner's decision 2026-11-12,
  HIL-1938); this prepares no new merge, transfer right or receipt protocol
  (`AbstractSessionsLibraryAgent::assertMergeable()`, HIL-1960).
- React's list and card show what Vue's do (HIL-1961): the badge, the caption
  above the bar, the notice under the card and the progress section, with no
  action to press; a window open when `erasing` arrives closes, and one whose
  action is in flight stays and shows the server's refusal. Angular is built: its
  list and card show the same states as Vue (HIL-1956, HIL-1957), and modals
  show server refusals as they are; live from HIL-1952. Their interviews
  choose the appearance of missing states; this document declares no design.

**Evidence belongs with the behavior.** Each business leaf carries its own
  unit/integration checks and clears only the markers its behavior makes true.
The live path has restriction integration and failover evidence for 4/19; cold
person 39 remains quarantined as F-015. HIL-1963 aligns the remaining applicable
behavioral e2e. Binance scenarios 58–60 record lost, duplicate and stale frames,
one personal host loss and move, real personal idle, holder restart, disabled
email, two negative erasure journeys and partial FULL import/resume; the exact
proof boundaries are in [Recovery](#recovery). The database-free
`test:account:erasure-probe` supplies bounded capture and replay for existing
account-operation frames on a local worker. Neither evidence leaf inherits
another leaf's business tests or marker removal.

## Merge

"The loser" is the account folded in and its agent, "the survivor" the account
that absorbs it and its agent. The password fate and the second-factor fate
keep their present meanings and refusals
([second-factor.md](second-factor.md#account-merge)); what changes is where
each check runs and who writes. The eight steps retain the research order;
each marker names the leaf preparing that capability. HIL-1999 connected the
protocol to the live administrator and `account:merge` paths at the
[single cutover](#merge-cutover-and-observation).

1. **The request.** The administrator's window or `account:merge` names the
   two accounts, the fates and, from the window, the two protection flags it
   showed. The holder judges what it judges today without raising anybody —
   the two ids differ, both exist, neither is folded, and a password fate is
   named when both accounts have a password. The split path preserves those
   refusals. The protected `openAccountMerge()` entry refuses an account occupied
   by another open merge and preserves the current open-erasure preflight.
   An exact open repeat with the same pair and choice drives the existing id
   before preflight, so a committed give is continued rather than reopened.
   `AccountMergeOpening::open()` records the immutable pair, fates and expected
   flags, stage *preparing*, both initial assignments at stable addresses and the project
   owners' expected composition about the loser in one transaction. An exact
   repeat creates no second operation and writes only what is missing;
   `AccountMergeOpening::reconcile()` appends what the project census expects
   and the open merge does not carry yet. This records the
   initial plan; accepting it does not end the merge.
2. **Both owners prepare.** The holder asks the loser's agent and the
   survivor's agent to prepare. Each, in a transaction of its own, checks
   against its own rows what the lock and the re-read check today — whether it
   holds a confirmed authenticator and whether that matches the flag the
   browser showed, whether a live removal of its factor stands where the fate
   forbids one — refuses in today's words when the check fails, and otherwise
   conditionally records its own reservation. A changed flag still says
   "Second-factor protection changed. Review the accounts and choose again."
   Competing merge and erasure cannot share the account: an erasure whose
   circle names an account of an open merge is not opened until that merge
   finishes (`AccountErasureOpening::open()` defers it, HIL-1988). Preparation captures
   enough identity of its previous password and factor, or their absence, to
   apply the chosen fate later. It changes no business row and demotes no
   password before the fact. The owner step checks its own rows and records its
   result without locking or rereading another owner's set. The coordinator
   dispatches both preparations
   and waits for both exact saved results before deciding go or cancellation.
   While reserved, changes of password, passkeys, factor, backup codes,
   reset and wait are refused, including late service frames and affected
   policy entrances for trust, term and switch-off. Ordinary content that
   touches none of these stays available; the reservation is no general
   account block. Existing personal mutation doors, early library doors and deferred
   trust-policy application are guarded (built, HIL-1989). The shared verdict delegates
   reservation to `AccountMergePreparation::heldBy()` and sign-in blocking to the
   reserved survivor's `mergeFact()`. Ordinary proof keeps its spend, replay and
   attempt checks before the fact, without canceling a reserved reset. Opportunistic
   password rehash and address verification skip reserved protection; a late merge
   refusal can continue only after a fresh sign-in check. Ordinary identity and passkey
   additions hand verified final material to the personal owner, which rechecks the live
   session and reservation and commits each addition atomically. Enrollment replacement,
   backup-code replacement and reset requests are also created by the personal owner after
   addressability and reservation checks; row changes and secret writes commit together,
   and the done answer follows the commit. A reset notice is sent after commit. A failed
   transaction rolls back the replacement. New sign-in grants check the saved merge fact
   after proof and before binding; the final check also runs for OAuth no-trip, live, held
   and resumed paths. A saved give fact refuses survivor sign-in through receipt even
   if DB sync was missed. Trust is optional after login and has no holder-side creation
   fallback. These ordinary doors and deferred trust-policy application are active.
   HIL-1999 connected the live administrator and CLI callers to the coordinator
   and its dispatch.
   This supplies reservation behavior for the ordinary
   HIL-1903/HIL-1904/HIL-1907/HIL-1908/HIL-1909 paths, not a replacement for
   those ownership moves.
3. **Cancellation, before the fact only.** `AccountMergeCancellation::request()`
   enters `cancelling` only for an open merge whose current locking read finds
   no `stage='go', key='give'` result. In one primary transaction it holds the
   operation row exclusively, reads that result with a locking read, and records
   exactly two release assignments, one for each personal owner. Repeats keep
   the same assignments. The fact is the loser's saved result, not a sent frame
   or a cached value.

   For a new release, each owner handles only its own assignment through
   `AccountMergeRelease::run()`. It checks the current open cancelling
   operation, absence of the give result, and the assignment's owner, side and
   empty payload, then records `{"release":"released"}` with its participant
   result under a shared lock on the same operation row. That lock lasts through
   the participant transaction. The existing
   `framework/backend/Database/Object/Collection/AccountOperations.php#fresh`
   shared-read gate inside a participant transaction is the give-side boundary.
   `AccountMergeGiving::run()` uses `AccountMergeTransfer::run()` to hold it
   across the actual business transfer, copy and result recording. The
   cancellation gate covers operation metadata and does not lock or reread
   personal business rows.

   `AccountMergeCancellation::advance()` waits for both durable release results,
   including the owner that never reserved, and only then finishes as
   *not started*. Either owner may release independently; a committed release
   survives a later failure, and neither release changes a preparation result.
   An exact saved preparation or release repeat returns its original result; a
   new preparation after that side's release is refused. If the current give
   result exists, request and advance return too late and do not add release
   results or change the operation; after the fact the merge path moves only
   forward.

   The prepared `cancelAccountMerge()` entry calls the existing request gate
   and drives release or forward duties according to its durable verdict.
   Before calling the existing `AccountMergeCancellation::advance()`, the
   coordinator validates both release assignments and their strict result
   payloads and exact owner bindings. It never records either owner's result.
   HIL-1999 connected the live merge to these durable cancellation and forward-work gates.
4. **The fact: the loser gives its rows away.** With both preparations
   recorded, the coordinator hands the loser's agent the go. In ONE
   transaction of its own under the [transfer right](#the-transfer-right),
   the loser applies the password fate to **its own previous password only**:
   demote it or delete it under today's unverified/address-collision rules
   when the fate does not keep it. It never edits the survivor's previous
   password; the survivor applies its own captured password during receipt.
   The loser re-points its identities
   and passkeys with their anchors and existing ids; a way in already held by
   the survivor means deleting the loser's colliding row, as today. It moves
   its confirmed factor and backup codes with spent status preserved when
   the fate transfers its protection, and deletes them otherwise. It deletes
   unfinished enrollment, resets, settings and its own trusts. The same
   transaction writes the tombstone — merge row first, its own block second,
   under today's unverified/address-collision rules — with its nonsecret
   `AccountMergeGiveResult` and a
   separate full *hand-over copy* of actual rows, anchors and effective wait.
   The prepared `AccountMergeGiving::run()` commits or rolls back all of them
   together. The copy carries rows, not just ids that would require reading
   the survivor's set. From that commit the loser is folded:
   `AddressablePerson::require()` refuses it and the candidates table hears
   the fact through the person row. This is the owner step; the live merge
   coordinator dispatches it since HIL-1999.
5. **Admission into memory and receipt are separate results.**
   `AccountMergeGiving::copy()` reads the loser's saved hand-over copy without
   reading the survivor's set. `handover()` pairs it with the original birth
   revisions from the saved give result, never today's fence revisions; after
   copy removal it returns null. After commit the source sends that saved copy
   and original admission directly to the canonical survivor through
   `HILOS_USER_ACCOUNT_MERGE_HANDOVER`; the holder handles only nonsecret proofs.
   `AccountMergeAdmission::apply()` preflights the whole batch at the canonical
   survivor, then hydrates mapped snapshots in ORM memory without a second
   business `UPDATE` or reread. Its [durable fence](#durable-merge-admission)
   prevents stale `DB_SYNC` and old hand-over frames from rolling back data,
   ownership, deletion or derived effects independently of receipt and copy
   retention. The prepared receive handler calls this API; admission writes no
   receipt and does not implement startup recovery.
   In its own transaction the survivor applies the password fate to **its
   own previous password**, identified by preparation. It must not find that
   previous row again through `findPasswordByUser()` after a second `PASSWORD`
   has arrived: the lookup may now select the transferred row. When the fate
   does not keep that password, it demotes or deletes it by today's rules.
   It writes the longer effective wait and clears pending shortening when the
   loser's protection transfers, and deletes its own trusts when the loser
   had a confirmed factor, under the existing policy. These writes commit
   with its *receipt* for the operation and stage. A duplicate or old frame
   for that same completed step returns the stored receipt and applies
   nothing, including after completion and copy removal. New work for a closed
   or foreign operation is refused. The prepared `AccountMergeReceiving::run()`
   and the personal owner's `receiveForMerge()` implement this step. A sleeping
   survivor reads its current own set at start through `resumeMergeReceipt()`
   and confirms from an existing obligation, without another hand-over frame.
   The live coordinator produces receive duties since HIL-1999. Receipt
   protects this survivor's Receiving step: frame
   delivery or a `Done` notice alone proves no receipt and no operation end.
6. **The other owners move their own.** The sessions holder's prepared
   `go/sessions` duty, `mergeAccountSessionsPart()`, requires the exact give.
   It re-points the loser's access log and ends sessions touching the loser in
   the same participant transaction that records its strict
   `{"sessions":<n>,"accessLog":<m>}` result. A saved result is checked and
   replayed before current-stage or business reads; session notifications leave
   only after commit. The live coordinator produces this duty after give.
   Projects declare merge participants
   through the HIL-1948 directory: an owner registered for the merge moves its
   own rows of the loser in `AccountMergeProjectStep`, and a missing required
   owner stays an open wait of the composition with no foreign fallback writer
   — nobody moves its rows for it. The live coordinator dispatches these
   assignments after give. The prepared end
   refuses an open wait. In chat,
   `ChatAgent` re-points authorship of the loser's messages across every room in one transaction with its
   result; other message and attachment fates stay as they are, and merge
   neither becomes erasure nor writes the rename journal. The prepared
   `ChatAgent::mergeAccountPart()` runs through the existing
   `chat_account_operation_step` ask/answer entry, selecting the step by the
   assignment operation's durable kind. HIL-1999 connected it to the live caller.
   Other loser content retains its present
   fate under the project participant contract: an owner moves only what the
   project's merge moves today, and every other row stays with the loser
   (`AccountMergeProjectOwner::mergeAccountPart()`, reached by live dispatch).
   The rows of the person's agent's own project sets move in a part of their
   own, only where `AccountMergePersonalStep::isActive()` holds; elsewhere they
   stay with the loser. The coordinator writes the loser's `personal_give` and the
   survivor's `personal_receive` with the other go duties. The part depends on
   neither the framework's receive nor the sessions nor cleanup, and
   `personal_give` is ready as soon as the loser's give is saved. Each ask of it
   answers its saved result first. With no result of the survivor's yet, the
   loser's agent reads its rows of the sets, and only reads, and the framework
   sends them in one frame, `HILOS_USER_ACCOUNT_MERGE_PERSONAL_HANDOVER`, to the
   survivor's agent outside any transaction; the answer is `Waits` and nothing is
   written, and every ask before the survivor answers sends the frame again. A
   send that fails is a line in the log and fails nothing. The survivor's agent
   takes the frame only from the loser's canonical agent, for a go merge where
   its person is the survivor, on its own `personal_receive`, with the loser's
   give saved; any other frame is refused and writes nothing. A saved result
   answers first, so a repeated frame joins nothing twice, even after the merge
   ended; new work exists only in open go. In ONE participant transaction the
   project joins the rows into its own sets by its own rule and the step records
   `{"rows":{<family>:<n>}}`, then answers `Done`; a coordinator ask without a
   frame answers the saved result or `Waits`, since rows come only by the frame.
   That `Done` wakes the next step, the ask of `personal_give`: with the
   survivor's result saved, the loser's agent deletes its own rows of the sets
   and records its result in one transaction, and the end check follows. The
   delete leaves the registry's files alone, because the survivor may have taken
   their references.
   A throw from the join rolls the survivor's transaction back whole, saves no
   result and answers `Failed` (`Refused` for a `ValidationException`); the
   survivor's sign-in and its receive do not depend on it, and the next ask of
   `personal_give` sends the frame again. A throw from the delete rolls back,
   leaves the loser's rows and saves no result, and a repeat deletes once. A lost
   frame leaves the loser's rows whole, and the asks that sent it repeat it: the
   coordinator's reentry and the loser's owner-start resume. No poll runs behind
   any of them. Until the delete, the loser's rows lie beside the joined ones; the
   folded account cannot sign in, and a foreign key from such a row to a shared
   row, `RESTRICT` for instance, holds that row until the delete, so the survivor
   joins rows that still exist. Cancellation ends at the give, so the part, which
   stands after it, is never cancelled. Cleanup waits for the receipt alone, so
   the loser's agent may stop before it deletes; `personal_give` is part of the
   folded loser's own continuation beside `prepare_loser`, give and cleanup, and
   the next ask raises the agent again, with ordinary access still closed.
   Chat's late-publication guard already applies to the retained live merge:
   after a committed merge it refuses the author's pending text or document
   through the existing draft/unavailable outcome, without publishing as the
   survivor. It reads `userMerges->isMergedInDatabase()` freshly, before sign-out
   or DB_SYNC is consumed; a tombstone with a null survivor also closes the
   door. Preparing alone leaves sending open. The erasure `startedOf()` guard
   stays, and a registry reply's unbound file ids follow the existing
   `removeUnlinkedPublicationFiles()` path. HIL-1999 connected the live sender
   and project hook and retired the holder's borrowed message-update right.
7. **The restriction lifts on the receipt.** The survivor's reservation ends
   only when the chosen protection is applied and its exact receipt recorded.
   `AccountMergePreparation::heldBy()` recognizes only that survivor's canonical
   `go`/`receive` assignment and strict result; the loser's reservation remains.
   Until both hold, sign-in through a
   transferred way in is refused, as is password sign-in on the survivor
   during the temporary two-`PASSWORD` state. The saved give fact is checked
   at the final session grant, including OAuth no-trip, live, held and resumed
   paths; the refusal remains through the survivor's receipt. Changes to
   ways in and protection are closed by the active reservation guards on the
   live merge path since HIL-1999. When the loser's factor first protects the survivor, the holder's
   prepared `go/survivor_sessions` duty, `finishMergeSurvivorSessions()`,
   requires the exact receipt and ends the survivor's sessions only when the
   saved give says the loser's factor was retained. It commits the same strict
   result with its work, or zero counts when no sessions end, and publishes the
   second-factor profile update only after commit (HIL-1995). The live
   coordinator produces this duty after receipt since HIL-1999.
8. **Cleanup precedes stop and end.** After receipt, the loser alone deletes
   its hand-over copy and records strict `{"cleanup":"cleaned"}` in one
   participant transaction. The prepared source requests stop only after that
   transaction commits or its exact saved cleanup replays. Cleanup and
   `survivor_sessions` become ready after receipt independently of outstanding
   project or holder results; the copy can disappear while those results are
   still pending. See
   [The prepared loser cleanup and finishing](#the-prepared-loser-cleanup-and-finishing).
   The coordinator records end only after receipt, every required participant
   result and the absence of the copy. The prepared `AccountMergeEnd` /
   `advanceMergeEnd()` and the holder's metadata-only copy-read interest implement
   this gate; see [The prepared merge end](#the-prepared-merge-end). The folded
   loser accepts only continuation of its own operation — copy replay, the
   personal give of a project part, and cleanup — with no ordinary access
   reopened;
   `AddressablePerson::require()` gains no general bypass. The prepared source
   lifecycle and cleanup dispatch implement this boundary. The copy's password
   hashes, factor secrets, addresses and backup codes already have their
   carrier's `PURGE` verdict and backup coverage, including its DB-only payload.
   Cleanup deletes that carrier without retaining a service copy of its secrets.
   Coordinator passes, participant start and operation frames find unfinished
   obligations in DB: repeat copy/result, sleeping-survivor confirmation,
   cleanup and end. There is no DB poll on every tick; the holder keeps its
   pass cursor only in memory. HIL-1999 removed the immediate `selfStop()` on
   a merge row; cleanup requests the loser's stop after its committed or replayed result.

### The Prepared Merge Coordinator

`framework/backend/Auth/AccountOperation/AccountMergeCoordinator.php` selects
work from committed DB facts through `begin()`, `afterAnswer()`, `cancel()`
and `refusalOf()`. Only the canonical sessions holder may drive transitions,
outside an application transaction. The protected holder entries are
`openAccountMerge()`, `driveAccountMerge()` and `cancelAccountMerge()` in
`framework/backend/Auth/Library/AbstractSessionsLibraryAgent.php`.
They provide the live backend path. HIL-1999 connected the administrator action
and `account:merge` and removed the former merge calls, borrowed grants and
executable ownership debt. A saved completed pair answers from that operation;
a `not_started` outcome permits a new start. Existing `UserMerge` tombstones
are not adopted as operation records.

Preparing waits for both exact canonical personal assignments and strict
`AccountMergePreparationResult` proofs. `refusalOf()` chooses the first refusal
in enum order across both saved results, including `PasswordFateRequired` when
both captured passwords exist and the immutable choice names no fate. Delivery
order cannot choose the refusal. Only two reserved results and an allowed
password fate permit the conditional transition to go. That transition and the
five fixed go assignments, seven where the person's agent has a project part
(*Merge*, step 6), commit together; reentry restores a missing duty
without rewriting an existing assignment. Refusal enters the existing no-give
cancellation gate and requires both strict owner-bound releases before
`not_started`. Explicit cancellation uses that same gate; a saved give yields
`TooLate` and the holder drives forward work.

`go` remains one physical stage. Its duties become ready in waves:

1. Before the source fact, only the loser's `give` is ready.
2. After the exact give, `receive`, holder `sessions` and original project
   expectations are ready independently, and so is the loser's `personal_give`
   where the project has a personal part. The survivor's `personal_receive` is
   never ready by itself: only the loser's frame brings it rows, and its `Done`
   wakes the next ask of `personal_give`. Preparing project assignments retain
   their id, stage, owner and payload and run through HIL-1998's stage-carry gate.
3. After the exact receipt, `survivor_sessions` and source `cleanup` are also
   ready, independently of other outstanding results. Cleanup need not wait for
   project work or either holder result.
4. The existing HIL-1998 `AccountMergeEnd::advance()` gate reconciles the
   composition and waits for all canonical proofs, no open wait and physical
   copy absence. A cleanup result or receipt alone cannot close the operation.

The holder's `afterAccountOperationAnswer()` checks operation, assignment,
immutable stage and canonical transport sender before the coordinator sees an
answer. Sender comparison uses `SignalSource::describe()` for the agent source
(`agent/type#index`, or the unindexed form), including holder work run inline.
The result's `owner_agent_id` uses `assignment->owner->agentId()` (`type:index`):
it is a different representation of the same canonical owner. `Done` is useful
only with the exact saved assignment/owner proof and strict duty payload.
The only stage mismatch admitted is the original project expectation accepted
by `AccountMergeProjectStep::isCarriedAssignment()`. Old personal prepare or
release, foreign coordinates, missing proofs and closed operations do not gain
an exception. The coordinator writes no other owner's result.

`askAccountOperation()` uses a queue confined to the current handler. It runs
the holder's own duties inline, feeds their answers through the same source
judge, and sends other duties through each registered owner's existing step
signal. A missing owner or empty signal leaves the expectation or duty open;
there is no fallback writer. This queue is not a durable pending store.
After a lost frame, explicit `driveAccountMerge()` of the same operation id
derives ready missing work from DB again. `begin()` may request at most one
saved give replay before receipt so the source can resend its original copy.
`afterAnswer()` selects direct successors without that replay and starts no
retry loop. The read-only `owedTo()` selects open Merge assignments addressed
to the exact owner type and index, checks the fresh operation against the same
readiness gates as `begin()`, and returns duties in assignment id order. Its
only missing-result exception is a saved canonical give before receipt, so an
owner start can resend the original copy; it writes nothing, sends no frames,
and reads no secret copy. `AccountOperationAsks::resume()` combines those Merge
duties with Erasure duties, while the holder's shared first/hourly pass drives
both operation kinds. These paths add no timer, per-tick DB poll, second
recovery driver or placement mechanism.

The coordinator integration checks exercise registered real owners, DB work,
serialized `SignalDTO` frames and router hydration in a controlled in-process
exchange. They cover reordered, duplicate, lost and late frames, explicit
drive, result/end rollback and post-commit send faults. They do not establish
real-peer, restart or backup guarantees; those remain HIL-2013.

### The Prepared Loser Give

`framework/backend/Auth/AccountOperation/AccountMergeGiving.php#run` checks
its immutable loser `go`/`give` assignment and executor, then returns the exact
saved `AccountOperationResult`, validated by `AccountMergeGiveResult`, before
`AddressablePerson::require()`, planning or business collection reads. Replay
therefore works after the loser folds, a stage change, completion or reconnect.
A malformed saved fact refuses the call. New work requires the current open
`go` metadata and both reserved preparations. The existing transfer step
rechecks fresh metadata under the shared operation lock through the participant
transaction; cancellation uses
the exclusive boundary on that same row. Neither path locks personal business
sets.

The loser plans its own four transferable collections in id order from the
captured password and confirmed-factor anchors. It applies its own fates and
source-only cleanup, writes `userMerges` before its own block, records the
private copy, and records the nonsecret result before commit. Rollback restores
the same ORM objects, membership and clean baselines and releases no source
reactions or sync frames; committed announcements preserve merge-row-before-block
order. Accepted passkey, TOTP and backup-code proof before the fact contributes
its actual counter, step and spent state to the copy. This opens no reserved
protection mutation and writes no survivor password, settings or trusts.

`AccountMergeGiveResult` stores `give="given"`, the four transferred id lists
under `rows`, the transferred identity count `identitiesMoved` and the actual
`passwordKept` and `secondFactorKept` outcomes in `accountOperationResults`.
Its required `effectiveWaitDays` is a nonnegative integer when the chosen fate
is `BOTH`, and null otherwise. This nonsecret value equals the private copy's
source effective wait, captured before source settings deletion; saved replay
never recalculates it from current settings or policy.
It contains no secret copy. The complete typed `AccountMergeHandoverCopy` is
stored separately in
`accountMergeCopies`, keyed by the give assignment. The personal owner has
`Add` and `Remove` over only its own set, keyed by its full agent id; it has no
copy `Update`. `AccountMergeCopiesActions::record()` requires the
participant transaction and canonical assignment, owner and copy anchors.

`AccountMergeGiving::copy()` checks the saved fact before reading the private
carrier. An absent copy returns `null` without erasing the durable give fact.
`AccountMergeCopy::readForMerge()` checks the canonical loser owner, assignment,
operation and exact saved manifest before returning a fresh typed copy from the
DB-only payload. Incomplete or malformed storage refuses the read; the copy is
never reconstructed from survivor rows. The generic account-operation answer
confirms the committed result without carrying either result payload or copy.

`AbstractUserAgent::giveForMerge()` and the existing assignment match run
this step when the live coordinator sends it. HIL-1999 connected the
administrator and CLI callers to this path. Admission, receipt, and source
cleanup are live parts of the same operation. HIL-1999 also removed the old
immediate stop on a merge row; the source stop follows its cleanup result.

### The Prepared Source-To-Survivor Handover

After `giveForMerge()` commits or replays, the source's `sendMergeHandover()`
reads only its own saved copy through `AccountMergeGiving::handover()`, paired
with the original give's birth revisions. It sends
`HILOS_USER_ACCOUNT_MERGE_HANDOVER` directly to the canonical survivor. A send
failure preserves the committed give and its generic `Done`; explicit drive
can request the saved give again before receipt. Missing copy is never rebuilt
from the survivor, and neither a send failure nor copy absence becomes receipt.

`AccountMergeHandoverSignalData` has exact root fields
`{operationId, stage, assignmentId, ownerIndex, handover}`, with exactly
`{copy, admission}` inside `handover`. Its typed copy and admission retain
their own serializers and cross-field validation. The outer `assignmentId`
addresses the survivor's receive; the copy carries the distinct source give
identity. The inherited personal-agent signal declaration uses this exact DTO
and indexes by `ownerIndex`. Generic ask/answer payloads and declarative router
logic carry no new secret fields; worker and peer transport are unchanged.

`AbstractUserAgent::handleMergeHandover()` requires the canonical source sender,
survivor address, immutable pair, exact receive and saved give with its original
admission before calling `receiveForMerge()`. The existing saved-first receive
path returns an exact receipt without admission, copy or business reads; even
after cleanup, end or a later merge, replay does not hydrate old secrets into
memory. New work uses the existing admission and receipt transaction, and a
pending current context answers `Waits`. The survivor answers on the unchanged
generic `HILOS_ACCOUNT_OPERATION_ANSWER`; the holder sees only the correlated
notice and nonsecret durable proof, never the hand-over secrets.

### The Prepared Loser Cleanup And Finishing

`AccountMergeDuty::Cleanup` addresses the loser's immutable `go`/`cleanup`
assignment with an empty payload. `AccountMergeCleanup::run()` checks its
canonical person, pair and full executor address, then validates and returns
the exact saved result through `AccountMergeCleanupResult` before current-stage,
copy or business reads. Its payload is exactly `{"cleanup":"cleaned"}`. This repeat
works in a cold context, after copy removal, stage closure or completion;
a foreign assignment or malformed saved result refuses the call.

New cleanup requires current open `go` metadata, the exact saved source give
and the exact survivor receive receipt. Without receipt,
`AccountMergeCleanupWaitsException` maps through `AccountOperationAsks` to the
existing `Waits` answer and writes nothing. The source deletes its give copy
through the mounted item's `AccountMergeCopyActions::deleteAfterReceipt()`.
That action checks the canonical give, result, receipt and executor under the
source's own-set `Remove`; it neither reads the private payload nor grants a
write to another source's set. The existing `accountMergeCopies` mount binds
these item actions. The source's own-set claim also supplies its copy read
interest; this cleanup installs no holder copy reader.

`accountMergeCopies->hasStored()` checks current locked metadata through the
mounted carrier connection, independently of a held absence or an older
snapshot. Cleanup requires physical absence before recording its result.
Copy deletion and result recording share one participant transaction: a result
insert or commit failure rolls back the copy, result and ORM memory together,
without publishing their changes. Cleanup retains the give, preparations,
receipt and durable fences; it leaves no separate service copy of the secrets.

`AbstractUserAgent::cleanupForMerge()` and the existing assignment handler
dispatch this work. The entry clears its local finishing pin and calls
`selfStop()` only after committed cleanup or validated saved replay. A wait,
refusal or failed transaction leaves it running. Stop uses the ordinary
lifecycle, retaining truth-source rights through `onStop()` and releasing
them afterward.

`AccountMergeFinishing::forLoser()` restores the source context from its exact
saved give, both reserved preparation results and their immutable manifest
and protection choices. `accountOperationAssignments->mergeGiveOfLoser()`
finds the latest saved source give in current mounted metadata, including
finished operations, while retaining held Object identity. Context lookup is
independent of copy retention and cleanup or end; a conflicting tombstone or
proof refuses it. These DB records remain authoritative. The personal agent's
local operation id and failed-lookup flag only pin the instance against idle
stop while cleanup is pending or context lookup is unresolved.

`onStart()` resolves that context before ordinary trust materialization and
skips it even for a cleaned source. `hasWorkInFlight()` reads only the local
pin and performs no DB poll; another addressed frame can retry a failed lookup.
Prepared sources accept only their exact own-operation continuation:
loser preparation replay, give replay, the personal give of a project part and
cleanup. Ordinary access remains
closed, and late legal-hold, trust-restriction and push service writes refuse
without writing. A cleaned source has no idle pin; after its merge completes,
the continuation gate also permits its own erasure. Legacy sources without a
saved give do not receive an immediate stop from the merge-row write; cleanup
owns the live source stop.
There is no general `AddressablePerson` bypass or worker lifecycle rewrite.

These entries are connected to the live coordinator and source hand-over
transport from HIL-1999. The shared holder pass and
owner-start resume drive this prepared work; the complete cluster/backup
journey is HIL-2013.

### The Prepared Survivor Receipt

`AccountMergeDuty::Receive` addresses a separate survivor `go`/`receive`
assignment with an empty payload. `AbstractUserAgent::receiveForMerge()` and
the existing personal assignment match call `AccountMergeReceiving::run()`.
Pending current memory becomes the existing correlated `Waits` answer, without
adding fields to the answer DTO. The exact nonsecret result is
`{"receive":"received"}`, read by `AccountMergeReceiveResult::forOperation()`
only from that operation's canonical assignment and full owner id.

The entry validates caller and assignment before returning a saved receipt.
Saved replay precedes addressability, admission, copy and business reads, and
survives stage completion, copy removal and a later operation. New work checks
the current open merge, both exact reserved preparations, the saved source
assignment/result, chosen outcomes and captured password/factor anchors.
Warm receipt uses held mapped Objects and freshly checked durable fences;
neither held rows without applied progress nor a partial batch suffice. An
optional original handover uses `AccountMergeAdmission::apply()` inside the
same participant transaction; pending or rejected admission records no receipt.
Transferred rows retain their fields without a second business `UPDATE` or
business reread.

The survivor settles only its own captured previous password. For `BOTH`, it
writes the maximum of the saved source effective wait and its current own
effective wait, clearing pending shortening without changing app-code misses
or lock state. Other fates do not write settings. A confirmed factor in the
loser's preparation deletes survivor trusts under the existing policy,
independently of which factor fate was chosen. These own writes and the exact
receipt run in one `AccountOperationParticipantStep` transaction. Rollback
restores DB rows, held Object identity and baselines, derived caches, password
hash memory, settings, trust membership and applied progress, and releases no
committed source reactions or sync announcements.

`AbstractUserAgent::resumeMergeReceipt()` calls `AccountMergeReceiving::resume()`
for an existing receive obligation. At `onStart()`, a finite own assignment scan
attempts these obligations before trust-policy materialization, logging a safe
pending diagnostic on failure. The same path handles a new owner in the same
worker with stale held Objects and settings. `accountMergeFences->receiptSnapshot()`
reads only the survivor's current Entity-mapped rows, through each mounted
Entity's row connection, in autocommit before the participant transaction; an
already open transaction is refused rather than using its repeatable-read
snapshot. Fence reads bracket that projection. Inside the one participant
transaction, `hydrateReceiptSnapshot()` rechecks all expected fences before
hydration and own writes; a changed fence leaves the obligation pending.
This local startup path needs no new handover and never hydrates secrets from
the original copy. Exact receipt releases only this survivor's reservation.

The entries and local startup scan are live backend work connected to the
source hand-over transport and coordinator obligation production. HIL-1999
connected the admin/CLI callers and retired the former writes and claims. The holder's
two session duties are implemented as prepared work in HIL-1995; loser copy
cleanup and source finishing are prepared in HIL-1998. The shared holder pass
and owner-start resume recover these duties; cluster or backup recovery
evidence remains HIL-2013.

### The Prepared Merge End

`AccountMergeProjectStep::isCarriedAssignment()` admits only an original
`preparing` project expectation into fresh unfinished `go`. It matches the full
key, owner, loser and payload against the registered project directory, and
requires the canonical source `go/give` with its exact owner-bound strict
`AccountMergeGiveResult`, including the saved effective wait. A wait, personal
duty, old erasure stage, terminal operation or malformed proof has no such
admission. Storage failures do not grant it. `AccountOperationParticipantStep`
uses this predicate only for its stage mismatch; ownership, saved-first replay,
transactional work/result and collision rollback keep their existing order.
The project entry validates before that preflight and again under the shared
operation gate, and checks exact saved or recovered result bindings. A canonical
current-go expectation uses the same checks. No second go expectation or mapping
is created: id, owner, stage and result remain those of the opening's assignment.

`AccountMergeEnd::advance()` is a prepared API for the canonical sessions holder,
exposed by `advanceMergeEnd()`. It enters outside an application transaction,
reads the fresh merge pair, and reconciles an open go composition in a separate
transaction. Its end transaction then holds the fresh operation gate and requires
both reserved canonical preparations, source give, survivor receive, both holder
session duties and source cleanup, even when a duty's producer has not yet run.
Every result must belong to that exact assignment and owner and pass its strict
parser; `survivor_sessions.accessLog` is zero. The expected set must have no open
wait and each original project expectation, including preparing expectations,
must have its own strict rows-moved result. Zero rows is a recorded result;
an absent owner or unanswered lifted wait is no substitute. Reconcile makes a
newly registered owner obligatory before that judgment. Where
`AccountMergePersonalStep::isActive()` holds, the end also requires both
personal results, the loser's `personal_give` and the survivor's
`personal_receive`, each on its exact assignment and owner with the same strict
`{"rows":{…}}` payload; one of them missing waits, and without the part neither
is asked for. A saved receive without the give's deletion therefore does not end
the merge.

After those facts, `accountMergeCopies->hasStored(giveId)` must report physical
absence through its current locked metadata reader. The holder declares only
`READS_DB` interest in copies: it neither reads their payload nor removes them.
The end's proofs and conditional `finish(go, Completed)` share one transaction;
a missing fact waits without finishing, and malformed or foreign contracts
refuse. Rollback releases no terminal announcement. Confirmed Completed replay
keeps timestamps and participant work unchanged and still requires copy absence.
NotStarted, cancelling and erasure do not become Completed. End retains the
preparations, give, receipt, cleanup, project results and admission fences.

These entries send no frames and perform no other owner's work. The live
coordinator supplies delivery and holder answer correlation and calls this end
gate from reentry and accepted answers. HIL-1999 connected the admin/CLI paths;
the shared holder pass and owner-start resume recover these duties. Cluster
and backup journeys remain HIL-2013. Erasure keeps its distinct boundary.

### Durable Merge Admission

`hilos_account_merge_fence` is primary-database latest metadata for rows actually
transferred by the prepared give: `identities`, `passkeyCredentials`,
`secondFactors` and `secondFactorBackupCodes`. Its composite key is
`(collection_key, row_id)`; the ORM offset is `collectionKey:decimalRowId`.
Each row holds the last transfer's operation, assignment and owner, a positive
revision and a deletion flag. It contains no business snapshot, secret, hash,
code, address or moment, and is not a history of mutations. The owner index is
`(owner_user_id, collection_key, row_id)`. The full Entity/Object/View/actions
chain is mounted under `accountMergeFences`, required by AUTH and readable
process-wide without preload. Its owner set is `owner_user_id`; personal agents
have Add/Update, with no Remove. It is not journaled.

The active captured-row transfer capability is the sole door that can birth a
fence for the target or move its owner: first transfer starts at revision 1,
later transfers advance the existing revision. This grants no ordinary right
to write another person's set. The business write, fence, copy, tombstone and
give result commit together. The result's `AccountMergeAdmissionManifest`
records exact original `{id, revision}` pairs for all four collections. Replay
uses those saved stamps even after a later write or transfer; the private copy
JSON keeps its existing shape.

For a fenced row, ordinary mapped, targeted raw, conditional proof and delete
writes check fresh metadata and the current owner's grant before business SQL.
An actual mutation advances revision through optimistic comparison in that same
primary transaction. Failure rolls back business state, metadata, applied
progress and announcements; a no-op or unsuccessful proof does not advance.
The last transfer coordinates remain unchanged on an ordinary write, including
after its operation closes. Delete retains a tombstone. Untransferred rows
remain unstamped; this is no general versioning scheme.

Stamped `DB_SYNC_CREATED`, `DB_SYNC_UPDATED` and `DB_SYNC_DELETED` carry nullable
`admission`: operation, assignment, owner, revision and a full Entity-mapped
snapshot (null only for delete). An unmapped update still has `row=[]`, with a
full mapped snapshot alongside it. Every accepted snapshot invalidates derived
unmapped values, including when an earlier unmapped frame was skipped. Mapped
moments keep signed milliseconds; TOTP steps are not moments. Passkey
`user_handle` is bytes inside PHP and strict Base64Url in stamped wire
`snapshot`, `row` and `previous`; DB-only secrets are absent from admission and
public passkey serialization still omits public key and handle. Worker/peer
wrappers and `withEmitter()` preserve the stamp and actual writing agent;
receipt ids are not invented. Signal names, routes and peer version are unchanged.

`DbSyncAdmission::check()` reads exact current durable coordinates before
business lookup or effects. Missing stamps on protected keys, wrong coordinates,
writer, revision, deletion state and already applied facts are refused. The
source writer proves its original give revision from the nonsecret saved result,
without reading the copy. `DbSyncApplyResult` separates acceptance from memory
change: a valid uncached fact still reaches interested owner/browser consumers;
rejected and self-echo frames publish no business `SourceChange` or consumer
effects. Workers gate fan-out on acceptance. The master only transports these
four collections and fence frames; it does no admission DB read or business
hydration. Other collections retain their ordinary path.

Hand-over admission additionally checks the canonical survivor, open matching
operation, exact go/give assignment, both reserved preparations and the saved
give manifest. It preflights every row before any memory effect, using mapped
columns only. Its four whole-batch outcomes are `admitted`, `already-current`,
`pending-current` and `rejected`; an empty valid batch is admitted without
inventing progress. If metadata is newer than the copy and memory does not yet
hold that current state, the result is `pending-current`, even in a cold context.
It neither labels the old copy current nor rereads the survivor's business set.
The caller must obtain current state through its ordinary delivery/startup path;
the prepared coordinator does not replace that path. Automatic operation
recovery uses the shared holder pass and owner-start resume described above;
real cluster and backup evidence remains HIL-2013. Mapped hydration and
the shared fence Object's local applied revision roll back together in an owned
or joined transaction. Fresh metadata reads preserve that local progress but do
not use it to choose the durable revision.

The price is an indexed current metadata read for each protected-collection
admission/write check and a metadata write per fenced physical mutation; source-writer
validation also reads the saved result. `fresh()` bypasses stale and
negative ORM mirrors, using a metadata-only `LOCK IN SHARE MODE` inside an open
transaction and an autocommit read otherwise. No new business lock, business
reread, shared secret cache or master I/O is introduced. Local applied progress
lives only in the mounted fence Object and resets with its context.

There are no foreign keys to business rows, copies, operations or people: those
coordinates are soft references. Metadata and tombstones survive business,
account and operation deletion, receipt and copy cleanup, without resetting age.
FULL backup includes them through its unrestricted primary dump. The mounted
Entity declares whole-table PII PURGE, collected and checked by the normal
coverage/compatibility gates for anonymized restore. A full database reset or
restore follows the normal generation/rehydrate boundary; this does not promise
compatibility for pre-restore frames. Prepared source cleanup removes the secret
copy while retaining the fences. Complete backup merge recovery remains
HIL-2013.

### The Transfer Right

Today a row moved from one set to another writes into two sets, and the set
door refuses it; only the holder of the whole table passes
(`TruthSourceKeys::coversRow()`, `DbWriteGuard::guardSetWrite()`;
[A Claim Over A Set](truth-source.md#a-claim-over-a-set)). That refusal is
right for an ordinary `Update`, and the target keeps it. What the loser's
agent needs in step 4 is narrower than any width the registry has: the right
to give, under one operation.

The right is judged by the operation, not by the class. Its source is the
owner of the set the rows leave; its target the survivor the operation record
names; its reach the collections and rows that operation allows; its moment
the stage the record is at. The door that judges it checks the old set key and
the new one against the record, the live right of the executor asking — the
loser's agent of this operation, not any agent that holds a set — and the
readiness of both sides, which is the two preparations. It is the right to
give and nothing more: not a right of the source to edit the survivor's rows
afterwards, not `ALL` over the table, not a new `INSERT` under a new id — the
ids are the point of the owner's decision. A transfer already performed under
the same operation answers with the stored result and moves nothing twice. It
is no general bypass of the write guard, and it changes neither `_setVia` nor
the width of any claim. The door is built over HIL-1892's strict guard;
ordinary `Update` still cannot write a foreign set.

Implemented in HIL-1991, the narrow entry is
`AccountMergeTransfer::run()`. It accepts `int $assignmentId`,
`AccountMergeTransferRows $rows`, and `Closure $work`; it returns
`AccountOperationResult`.
`AccountMergeTransferRows` is a final readonly value object with four explicit
`list<int>` selections: `identities`, `passkeyCredentials`, `secondFactors`,
and `secondFactorBackupCodes`. Each list accepts positive integers only and is
normalized to ascending unique ids; empty lists are valid. `$work` is a
synchronous `Closure(AccountMergeTransfer): AccountOperationResultPayload`,
invoked once inside the participant transaction. For a selected row, item
actions expose `transferForMerge(AccountMergeTransfer $transfer): void` on
`IdentityActions`, `PasskeyCredentialActions`, `SecondFactorActions`, and
`SecondFactorBackupCodeActions`. The action takes no collection or target. The
right creates no `ALL`, `OWNS_DB_ROWS`, or ambient claim.

The entry requires the loser's `go/give` assignment and its canonical owner as
the current executor; source and target come only from the operation. It
requires the exact reserved `prepare_survivor` and `prepare_loser` assignments
and owner results for those accounts. It reads the operation fresh before the
callback. Inside the participant's single `PRIMARY` transaction, it reads it
fresh again under the existing metadata shared lock, held through the result's
commit or rollback.
A repeated step returns its stored result without running the callback or
transferring rows again, including after the operation finishes.

Admission names only clean, existing rows in the loser's set. It checks the
live exact source-set `Update` grant at admission and on every row-action use;
it captures the selected Object identities and passkey identity anchors, not
secrets. Each transfer changes only `user_id` from loser to survivor, keeping
row ids and anchors. A passkey follows only after its captured identity is
persisted in the survivor's set. A factor must be confirmed and present in the
loser's preparation snapshot. Factors and backup codes are admitted only when
`secondFactorFate === SecondFactorFate::BOTH`; backup codes also require a
nonempty confirmed-factor snapshot. On any other fate, both factor and
backup-code lists must be empty. The synchronous callback joins the transaction
and must not open or end a transaction or yield. The right closes in `finally`
and on both commit and rollback. `syncForMerge()` uses the common
ORM save path, including its memory rollback and commit-only announcements;
ordinary `sync()` and `Update` keep their existing refusal. HIL-1999 connected
the administrator merge and `account:merge` to this operation path and retired
the former merge writes and borrowed grants.

### Merge Cutover And Observation

**One live switch.** HIL-1999 connected the normal administrator merge and
`account:merge` to the owner protocol after HIL-1964 and readiness of
HIL-1987–HIL-1998 and HIL-2000–HIL-2011. It removed the old merge path,
borrowed merge grants and comments, merge entries in HIL-1892's executable
debt catalog, and the immediate `selfStop()` on a merge row. It checked the
resulting writer map; business and recovery behavior was implemented by the
owner leaves. No new toggle or exception was added. The merge's open-erasure guard
(HIL-1960) belongs to
[Erasure Cutover And Observation](#erasure-cutover-and-observation);
the live merge keeps that guard.

The live entrances and observations are:

- `account:merge` uses the daemon channel and distinguishes preparing, wait,
  refusal and end with the selected fates. The command sends start or
  observe, holds an open merge until the caller's deadline, prints an unknown
  outcome when the channel fails, and exits 6 for an open snapshot. Waiting
  does not block the event loop. A disconnected channel means neither
  cancellation nor success, and success is printed only at durable end. A
  saved completed pair answers from its operation; a `not_started` pair may
  start a new operation.
- The first `page_response` and existing live standing frame carry the
  allowlisted merge observation, including its waits and safe refusal; the
  viewer projection hides its withheld personal fields. HIL-1999 connected the
  live production callers and retired the former merge writers. See
  [Account Standing](account-standing.md#the-wire).
- The list, card and candidates expose the durable reservation and operation
  facts through the lazy typed core (HIL-2004); occupied or folded accounts
  are never offered as available by that data model. See
  [Merge observation data](#merge-observation-data).
- Vue's list shows the merge progress caption and its unfinished states, its card
  shows them. Its merge window observes the recorded operation through the
  shared core window, including live waits, refusal and end, with the server's
  privacy projection. Closing it releases observation and does not cancel the
  operation. The separate draft uses the candidates' availability verdict and
  invalidates password and protection choices independently when their shown
  summaries change. Its tracked action receives operation acceptance, while
  the window observes the final outcome.
- Starting from the card preserves the chosen fates, shown flags, the
  `merge_accounts` step-up and existing refusal words. Acceptance is not end;
  the start answers with accepted `{operationId}`, and the window
  observes that operation. Success requires `end` with `completed`. A lost
  reply means neither success nor cancellation: the choices remain available,
  and observation resumes only for the exact new operation matching the request.
- Vue's observation window offers `Cancel merge`, sending
  `hilos_user_merge_cancel` with only `{operationId}`. The sessions holder
  permits cancellation only before the loser's give fact. A successful reply
  accepts the request; it is not the end. The window closes with `Merge canceled`
  only when the live operation reaches `not_started` without a business refusal.
  A request refusal stays in the window beside the live progress. Closing the
  window is not cancellation. The browser cancellation route is live through
  the sessions holder.
- React and Angular SDK views carry the corresponding states and actions.
  File Gallery Manager and Online Testing verify live card updates after
  `account:merge` (HIL-2012). Browser-initiated merges are disabled in both
  demos. This canon declares no design for the appearance of missing states.

### Merge observation data

The people list and merge candidates carry
`sources.merge.accountMergeAvailability: {reserved: boolean, available: boolean}`
in their inline `merge` slot, never in the normalized `users` entity. A reserved
candidate remains in the table with `available: false`; missing or merged
candidates leave through the existing removal path. Reservation and release
read the canonical held operation: an attempted release is not freedom until
its saved releases make it so. The five metadata sources are
`accountOperations`, `accountOperationAssignments`, `accountOperationResults`,
`accountDeletions` and `userMerges`.

The card's one `page_response.data.accountMergeAvailability` and the existing
`hilos_account_standing_state.accountMergeAvailability` carry the same nullable
block alongside standing, erasure and merge progress. Each delivery checks the
current viewer and page gate. The audience compares the whole card picture;
an availability-only change sends one replacement, equal pictures are quiet,
and a deleted card gets no reviving frame. A live availability read failure
sends unknown (`null`), logs safely once per failure episode and recovers on a
source repair. Subscription memory stores only the process-local last picture;
restart reads the mounted DB facts again, with no new RT truth row.

`AccountMergeProgressResolver::open()` supplies a `hilos-account-merge` row bar
under each existing side of an open operation. The audience composes the one
bar per row with erasure taking priority. Replacement or operation end sends
`ended: true` for the previous key; parts reaching their count alone is not
business end. The card may retain the durable terminal outcome after the row
bar ends. Snapshot and live deliveries share these readers.

The lazy `@hilos/core/admin/users` barrel exports
`HilosAccountMergeAvailability`, `readHilosAccountMergeAvailability()`,
`HILOS_USER_MERGE_AVAILABILITY_SECTION` and `HILOS_USERS_MERGE_PROGRESS_KEY`.
Missing, hidden, malformed or contradictory (`reserved: true, available: true`)
blocks read as `null`, without coercion. `HilosUserRow` and
`HilosMergeCandidateRow.mergeAvailability` read only their inline merge slot;
`HilosUserStanding.mergeAvailability` reads the first page section and then
matching-user standing frames. A late frame with no current detail row or for
another user is ignored; restart takes the fresh page scope and dispose clears
the values. The eager signal schema keeps this field optional and unknown and
imports no lazy admin model.

`createHilosUsersTable().merges` maps only readable merge-key bars through the
existing strict `readHilosAccountMerge()` parser, preserving its privacy
projection. `createHilosMergeCandidates().selectableIds` includes only live
rows whose availability is explicitly true, excluding unknown, unavailable,
pending removals and placeholders before Apply. The candidate actions column
includes the availability field in its rendered reads, so value deltas update
that set immediately. Existing current/filtered/focused rows, session slots and
the controller engine retain their behavior. These APIs provide the display
data used by the live merge observations; they introduce no view or mutation.
Real multi-node and backup evidence remains HIL-2013.

**Evidence belongs with the behavior.** Merge leaves use real owner, DB and
applicator integration and component states, without new clocks or real-time
waits. Each carries its own business checks; HIL-1999 connected the live
administrator and CLI paths. Applicable behavioral e2e is aligned after the
switch. Cluster and backup evidence covers
death after commit, lost receipt, duplicate/old frames, sleeping or moved
owners and the absence of a second effect
(not in the code yet — HIL-2013). Neither evidence leaf inherits another
leaf's business tests or marker removal.

## Writer Map

The 20 framework collections and the 4 chat collections the sessions holder
writes today, and beside them `secondFactorTrustRestrictions`, which the
settings library alone creates and the holder only reads, each with the
current expanded operations and calls, the creator, the single owner after
creation, the work handed over, its cost, and the ordinary transition or
retained terminal debt. HIL-1999 retired the remaining borrowed merge writes;
the map describes the resulting current writers. Collection keys are the
`HilosDbContext` and `ChatDbContext` names; "holder" is the sessions holder
(`AbstractSessionsLibraryAgent`, and in the chat its subclass), "agent" is the
person's agent (`AbstractUserAgent`), "users library" is
`AbstractUsersLibraryAgent`. A personal target means the indexed owner of
that person's rows, not a full-table writer beside the set owners. A writer in
the second column that the target column does not name loses that write; the operation-level records the
coordinator and the participants keep are outside these rows, below.

| Collection | Writes today, by the code | Creator today | Target: single owner of changes after creation | Handed to that owner | Dependencies and price | Transition or retained terminal debt |
|---|---|---|---|---|---|---|
| `identities` | users library `Add` (new-account registration); personal agent `Add`/`Update`/`Remove` over its set (password change, reset and rehash, address verify, email change, unlink) and personal erasure participant `Remove` (`deleteForUser()`); `UserTestSeedCommand`, test-only | users library for new accounts; personal owner for ordinary additions | the agent: every edit of a way in and of recovery, the transfer of its own rows to the survivor under the transfer right, the deletion in the erasure | the loser owner gives its own rows; the personal owner handles erasure removal; the sessions holder has no borrowed write to another's set | device keys restrict the delete of their anchor, so they go first; the current addresses are captured for the codes before the ways in go. Price: the transfer right by operation (the guard refuses a set claim moving a row today); one local transaction at the loser with its recorded result; a transferred way in signs nobody in until the survivor's protection is applied | No ordinary move remains. Current addresses in the personal owner's capture: live in the HIL-1952 erasure path. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Previous password identity capture at merge preparation is built; dispatched by the prepared coordinator. Protection mutation guards and ordinary identity creation handovers built (HIL-1989). Loser transfer, own-password fate and collision deletion are committed by the live owner give. Memory admission and durable stale-frame fence are built as a prepared API, with original give stamps carried directly from source to survivor by the prepared hand-over transport. Survivor settlement of its own captured previous password commits with its strict receive receipt in the prepared owner step; local startup can resume an existing obligation without a handover. Live transfer, admission and receipt are connected through HIL-1999; the old holder writer and its debt were retired. |
| `passkeyCredentials` | users library `Add` for new accounts; personal agent `Add`/`Update`/`Remove` (the counter on use, the unlink with its identity) and personal erasure participant `Remove` | users library for new accounts; personal owner for ordinary additions | the agent, use, transfer and deletion included; the row hangs on its identity anchor (`identity_id`, with `user_id` as the short path moved with the anchor, HIL-1132) | the owner's merge transfer, the erasure's `deleteForUser()` | moves in the same local transaction as its identity, the short path rewritten with the anchor; deleted before the identities in the erasure. Price: none beyond the identities' portion | Ordinary credential creation and its identity anchor commit together at the personal owner (HIL-1989). No ordinary move remains. Keys before identities in the personal erasure part: live in the HIL-1952 erasure path. The old erasure writer was retired in HIL-1952. Transfer with existing ids and identity anchors is built in the prepared give, dispatched by the prepared coordinator. Memory admission and durable stale-frame fence are built as a prepared API, with original give stamps carried directly from source to survivor by the prepared hand-over transport. HIL-1999 connected the live transfer and receipt path and retired the borrowed holder write. |
| `secondFactors` | personal agent `Add`/`Update`/`Remove` (enrollment replacement, confirmation, step, removal, reset completion) | personal agent for ordinary enrollment creation | the agent creates and owns every ordinary edit and deletion; the agent also transfers or deletes its rows during merge | no ordinary creation handover; the owner replaces unfinished enrollment in one transaction; the merge transfer or deletion runs at the personal owner; the personal erasure part calls `deleteForUser()` | the chosen protection is applied at the survivor before a transferred way in may sign in (question 2); pending-removal refusals become preparation refusals. Price: the five locks and re-reads become a prepare-and-confirm exchange between two agents, each with a local transaction and a recorded result | Atomic owner enrollment replacement is implemented (HIL-1989); failure rolls back both removal and creation, and the done answer carries only committed material. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Previous factor identity capture and protection checks at preparation are built; dispatched by the prepared coordinator. Protection mutation guards are active. Loser transfer or deletion by fate commits with its result in the prepared give, dispatched by the prepared coordinator. HIL-1999 connected the live owner transfer and receipt and retired the borrowed holder writes. |
| `secondFactorBackupCodes` | personal agent `Add`/`Update`/`Remove` (replacement, conditional spend and deletion) | personal agent for ordinary code sets | the agent creates and owns each ordinary set, spending, transfer and deletion; the agent also transfers or deletes its rows during merge | no ordinary creation handover; the owner deletes the prior set and creates its replacement in one transaction; the merge transfer or deletion runs at the personal owner; the personal erasure part calls `deleteForUser()` | travels with the factor in the same portion. Price: inside the second-factor portion | Atomic owner code-set replacement is implemented (HIL-1989); failure rolls back and preserves the earlier set, and the done answer carries display codes only after commit. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Loser transfer with spent status or deletion by fate commits with its result in the prepared give, dispatched by the prepared coordinator. HIL-1999 connected the live owner transfer and receipt and retired the borrowed holder writes. |
| `secondFactorResets` | personal agent `Add`/`Update`/`Remove` (request, cancel, completion and reminder) | personal agent for ordinary reset requests | the agent creates, changes and removes requests; `SecondFactorResetSweeper` (`Auth/SecondFactor/SecondFactorResetSweeper.php`) only finds and hands work over | the reset request and cancel token are created together by the owner; the library's due/reminder frames remain separate; merge and erasure deletion run at the owner; erasure runs at the personal owner | the merge's pending-removal checks become preparation refusals at the two agents. Price: none new | Reset creation is owner-guarded and atomic (HIL-1989); its notice follows commit, and rollback leaves no new request. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Pending-removal refusals at preparation are built; dispatched by the prepared coordinator. Reservation guards for existing mutations and late service frames are active. Loser reset deletion commits with its result in the prepared give, dispatched by the prepared coordinator. HIL-1999 connected the live owner deletion and retired the borrowed holder write. |
| `secondFactorSettings` | agent `Add`/`Update`/`Remove` (its own one-to-one row; `Remove` in its erasure part) | the agent on first edit; the personal owner creates missing settings on its first edit | the agent, its own row included; when the loser's protection transfers, the merged wait — the longer of the two — is written by the survivor's agent from the fact it receives, never by the holder from the loser's set | the loser's settings transfer/deletion and the survivor's effective-wait update at receipt; erasure deletion | the loser's wait is part of the transferred result. Price: one more value in the frame; the holder's whole claim goes | No ordinary move remains. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Loser settings deletion and the same immutable nonsecret effective wait in give and private copy are built in the prepared give, dispatched by the prepared coordinator. Prepared survivor receipt writes the longer saved-source/current-own wait for BOTH and clears pending shortening in the same participant transaction; other fates leave settings untouched. HIL-1999 connected the live owner transfer and receipt and retired the borrowed holder writes. |
| `secondFactorTrusts` | personal agent `Add`/`Update`/`Remove` (grant or renew, revocation and saved-restriction application) | personal agent for every ordinary trust grant | the personal agent owns creation and every later ordinary write; the personal owner also deletes trust rows during merge and erasure | the holder asks the person's agent to grant or renew after proof; the saved term and switch-off still arrive per person; the merge owner deletes trust rows under the saved protection policy; erasure runs at the personal owner | `grantOrRenew()` atomically handles an absent or standing pair and applies saved restrictions. The holder has no ordinary `Add`, insert or re-grant fallback. Price: the administrator's term reaches every person with a trust row through the sweep and agent (including an agent that applies it at start), where it used to be one statement | Saved policy implemented (HIL-1900), with the person's agent able to apply it; missed-work recovery implemented (HIL-1901); renewal by the personal owner implemented (HIL-1907); cap/zero term implemented (HIL-1908); switch-off removal implemented (HIL-1909). Owner grant-or-renew, including absent-pair creation, is implemented (HIL-1989); an optional trust refusal does not undo a successful sign-in. Reservation guards retain deferred trust-policy debt. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. Loser trust deletion is built in the prepared give, dispatched by the prepared coordinator. Prepared survivor receipt deletes its own trusts when the loser preparation captured a confirmed factor, in the same participant transaction under the existing policy; connected to prepared dispatch and receipt; HIL-1999 connected owner-side trust handling to the live caller and retired the borrowed holder write. |
| `secondFactorTrustRestrictions` | settings library `Add` (`record()`, in the transaction of the save that gave the row birth); no one holds `Update` or `Remove`; the holder and the person's agent only read it (`READS_DB`) | the settings library | no later write: a row is not changed after it is written, and it holds no personal data | nothing | the settings library creates it beside its own write, so a setting is not saved without the restriction it owes; the gate and every application read it from the table, not from memory. Price: one row per shortening or switch-off | Implemented (HIL-1900). The application it feeds is the trust row's: missed-work recovery implemented (HIL-1901), cap/zero term implemented (HIL-1908), switch-off removal implemented (HIL-1909). |
| `stepUps` | agent `Add`/`Update`/`Remove` (`record()`, `deleteExpiredForUser()`); erasure participant `Remove` | the agent | the agent, its own confirmation, its extension, its spending and clearing, and the erasure included | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion | No ordinary move remains. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `legalAcceptances` | users library `Add` (`accept()`); personal owner's agent `Remove` for test-only `forgetLaterThan()` called by `test:legal:hold`; personal erasure participant `Remove` (`deleteForUser()`) | users library | the library creates immutable acceptances; the personal owner removes later acceptances for a test hold and handles their deletion in erasure as its target. This does not make recorded acceptances mutable or permit deleting another person's consent | the test command's forget as a frame to the personal owner; the erasure's `deleteForUser()` | the legal agent reads these rows for its exports, and its erasure step follows the person's part. Price: one more hop in a test path | Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `notifications` | notifications library `Add`; agent `Update`/`Remove` (`markAllReadForUser()`, one statement over its set); erasure participant `Remove`; `NotificationTestSeedCommand`, test-only | notifications library | the library creates, the agent edits and deletes | the erasure's `deleteForUser()` | the deliveries point softly at the notification and are cut by it: before the agent deletes the notifications, the obligation of each channel over its soft delivery links is recorded, so no recipient is lost once the notification is gone. Price: one recorded obligation per channel ahead of this step, and one more ordering constraint in the erasure | No ordinary move remains. Delivery portions captured after the start: live in the HIL-1952 erasure path. Personal erasure after those obligations: live in the HIL-1952 erasure path. The old erasure writer was retired in HIL-1952. |
| `notificationDeliveries` | notifications library `Add`: creation only; channel agent `Update`/`Remove` (status, attempts and error of its attempt; `resetForRetry()` for an admin retry; `adoptPendingRows()` after start or freeze; retention of its terminal rows; the erasure part of its own rows (`runErasurePart()`)); erasure participant `Remove` (`deleteForRecipient()`); `RestoredDeliveries::settlePending()`, a raw `UPDATE` at restore with no agent; `NotificationTestSeedCommand`, test-only | notifications library | the library creates; the channel or shard changes its own rows, resets a failed row an admin retries and removes its terminal rows through retention. A switched-off channel keeps its journal and retention, and its agent stays available for retention and erasure; no shared sweeper is introduced (question 4) | retry, prune, the erasure's `deleteForRecipient()` | the `NotificationChannelShard` row guard constrains channel `Update` and `Remove` by fresh saved delivery identity and current route. The pipeline captures delivery ids, recovers bounded pages and reduces the saved routing key at the current pool width; legacy `NULL` keys use the notification id, even for an orphan. This checks each operation without re-cutting the Entity's `_setVia` away from the person's axis. Price: a retention and an erasure portion per channel; a narrowing of the channel's claim without a new width; the restore path's settle stays a raw write in a process with no agent and is named here rather than hidden | Channel/shard row checks implemented (HIL-1893); channel/shard retention implemented (HIL-1902); disabled-owner duties; owner retry implemented (HIL-1910). Channel/shard erasure with result and the started-recipient send guard built (HIL-1945); live since HIL-1952. The old erasure writer was retired in HIL-1952. |
| `notificationPreferences` | agent `Add`/`Update`/`Remove` (`setChannel()`); erasure participant `Remove` | the agent (a row is born only by the person's own choice) | the agent creates its own setting and writes and deletes it | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion | No ordinary move remains. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `pushSubscriptions` | notifications library `Add` (`subscribe()` inserts only for an absent endpoint and refuses collisions); agent `Update`/`Remove` (`renewOwned()`, `markGoneOwned()`, `releaseOwned()`, `unsubscribeOwned()`, `removeOwned()`); erasure participant `Remove` | notifications library (a new subscription) | the library creates new subscriptions and nothing else; the personal agent renews its own device, handles dead-endpoint reports, removals and the release of an endpoint a later person takes over | renewal of a person's existing device; the transport's dead-endpoint report; unsubscribe/remove; the release of a foreign endpoint; the erasure's `deleteForUser()` | the subscription row names its person, so the owner is known from the row; the push channel reads the devices and stays a reader; a persisted `revision` distinguishes registrations even across identical `last_seen_at` timestamps, and `renewOwned()` refreshes the saved row and diff baseline, clears its gone state, then increments that revision. A foreign endpoint takes two service frames: `hilos_user_push_release` from the library to the former owner and `hilos_user_push_release_done` back. `releaseOwned()` rereads the row by id and deletes it only when `user_id` and `endpoint` still match, and answers "released" either way; a failed write or guard refuses the press instead. The library then resumes the same press and creates the row under a new id. Price: own-device renewal is split between creator-only library `Add` and personal-owner `Update`; a foreign endpoint costs one more hop and a window with no server row, and its id may change; a refusal by the former owner is explicit, never another person's delete; a dead endpoint becomes a frame per recipient | Own-device renewal implemented (HIL-1905). Foreign-endpoint release by the former owner and creation at the library implemented (HIL-1906). Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `dataExports` | export agent `Add`/`Update`/`Remove` (`order()`, build, publish, forget); erasure participant `Remove` (`runErasurePart()`: `ofUser()->delete()`), then its post-commit file sweep | export agent | the existing export executor, the cancellation and clean-up of the erased person's DB order included; the holder only hands over | the erasure's delete of the order; the forget frame becomes the obligation | the builder's check of the retained completed-erasure row stays ([data-export.md](data-export.md)). Price: the executor is a participant with a recorded result instead of a borrowed delete plus a fire-and-forget frame | No ordinary move remains. Owner DB erasure with result is live (HIL-1952): `runErasurePart()` deletes the order and records `{"orders":<n>}`, the files leave after the commit through the existing sweep, and the publication checks also refuse an erasure that has begun (HIL-1946), on the live HIL-1952 path. The old erasure writer was retired in HIL-1952. |
| `legalAcceptanceExports` | legal agent `Add`/`Update`/`Remove` (`order()` and the build); erasure participant `Remove` (`LegalAcceptancesExports::eraseAccountPart()`: the account's order and every ready and failed export), then its post-commit file cleanup | legal agent | the existing legal export executor, the orders to clean included; the clean-up of shared files touched by the person's acceptance — every ready and failed export removed with its file, the one being built started again — is not lost and not declared implemented by the new mechanism | the erasure's delete of the order; the forget frame becomes the obligation | a project may register no legal agent: the participant list says whether the table was never activated or holds data with no owner. Price: a participant with a recorded result; the file removal stays post-commit | No ordinary move remains. Owner DB erasure with result and existing shared-export fate is live (HIL-1952): `eraseAccountPart()` deletes the account's order and every ready and failed export and records `{"ownOrders":<0|1>,"sharedExports":<n>}`, the files, the restart of the build and the sweep follow the commit, and an administrator whose erasure has begun orders no new export (HIL-1947), on the live HIL-1952 path. The old erasure writer was retired in HIL-1952. |
| `userPhotos` | agent `Add`/`Update`/`Remove` (`put()`, `deleteForUser()`); erasure participant `Remove`, the file id joining the post-commit removal | the agent (born of the first photo) | the agent writes and deletes its row; the files library owns the registry row, not the photo row; no direct unlink of the disk inside the personal transaction | the erasure's `deleteForUser()` | the file id goes to `Hilos::$files->remove()` after the commit, as today ([files-registry.md](files-registry.md#removing)). Price: the file removal stays a post-commit step without automatic retry | No ordinary move remains. Personal child erasure with result is live (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `userRenames` | agent `Add` (with the name, `renamePerson()`); agent `Update` (`linkEvent()` on a frame after the users library's `afterUserRenamed()` creates the feed event); agent `Update` (`unlinkEventsOf()` on ChatAgent's history-clear ask); erasure participant `Remove` (`deleteByUser()`; rows where the person was only the author stay, their author set null) | the agent | the agent of the renamed person, the link and unlink of the room event and the deletion of its journal included. The users library and `ChatAgent` hand it the change and do not write | the feed link after the event is written; the unlink before the room's history goes; the erasure's `deleteByUser()` | `UserRename.event_id` is a unique key onto the room's event. The HIL-1897 history-clear path obtains the unlink answer from the personal owner before deleting the event. Deleting the event clears the database column with `ON DELETE SET NULL`; readers may retain the deleted event id in memory, but no one reads it: feed lookup follows a live event, while history-clear and erasure selections read the table. A link frame received after unlink and before deletion is allowed, with no separate protection needed in HIL-1897. Price: two hand-overs between `ChatAgent` and the agent and an ordering obligation in the erasure | Owner feed link; owner-confirmed unlink before event deletion (implemented in HIL-1897). Event ids kept in the personal owner's capture, whose transaction takes the journal off them (`unlinkForErasure()`); no feed line for a person whose erasure has begun: live in the HIL-1952 erasure path. Final personal deletion after required results is built: the person's agent's final calls `deleteByUser()` after the merge row, live in the HIL-1952 erasure path; the final uses the personal agent's production `Remove` claim (HIL-1952). The old erasure writer was retired in HIL-1952. |
| `accountDeletions` | users library `Add` (`request()`, the admin card's `scheduleFor()`); personal agent `Update`/`Remove` (`cancel()`, `start()` and `complete()`, conditional) | users library | the library creates; the agent cancels, starts and finishes. The conditional race of cancel against due stands, and "completed" is written only after the end; the trace of the request after the person is gone is allowed | the erasure's `complete()` | the started phase is the Entity's `started_at`, stamped by one conditional `UPDATE` that cancel also checks (HIL-1939); the minute sweep stays where sign-in exists, opens the operation and has the personal agent start it (HIL-1952). Price: one conditional write more per request, one more indexed read in every `AddressablePerson::require()`, and the request's owner runs on in a finishing mode | No ordinary move remains. The personal owner conditionally starts against cancel; its start and final are live in HIL-1952. The final uses the owner's production `Remove`, and the holder's borrowed erasure rights are retired. |
| `users` | users library `Add` (registration and first administrator); agent `Update` by row (name, flag, block, theme, time zone); personal agent `Remove` (the erasure final); the chat's `Hilos::OWNS_DB`, laid by `TestOnlyCommand` for its seed commands, test-only | users library | The library creates; the agent changes and deletes its own row in the terminal operation (the loser's block, the erased row's final delete). The holder coordinates and is not a second `Update` or `Remove` | the loser's `setBlock()`, the erasure's `delete()` | every child with a hard key is gone before the row can go, and the row's deletion is what stops the agent today: the stop must follow the agent's own last write. Price: the agent's row claim gains `Remove` for one operation, and a finishing mode after the account is closed | Personal finishing mode and the final are live from HIL-1952. The person's agent refuses ordinary frames and stops after its final write; its final uses production `Remove` for its own row. The old erasure writer was retired in HIL-1952. The loser writes its block with the merge row and strict give result in its owner transaction (HIL-1999); the holder's borrowed `Update` was retired. |
| `verifications` | users library `Add`/`Update`/`Remove` (issue, spend, sweep; `deleteForUserOfType()` on a password change); erasure participant `Remove` (`deleteForIdentifiers()` on the current addresses, `deleteForUser()`) | users library | the users library stays the only owner — codes are outside the person's set (P-163) — and receives an obligation to erase by user id and by the current addresses, which it reads from the personal owner's capture. Runtime send attempts are not in this census | the erasure's `deleteForIdentifiers()` and `deleteForUser()` | the addresses are captured before the identities go, as `erasePerson()` does today; the obligation does not copy them, the library reads them from the personal owner's capture. Price: one obligation and one result at the library per erased account | No ordinary move remains. Library erasure by user id and captured addresses with result: live in the HIL-1952 erasure path. The old erasure writer was retired in HIL-1952. |
| `ChatDbContext::eventMessages` | `ChatAgent` `Add`/`Update`/`Remove` (`addMessage()` from the room page in its process, `deleteAll()`) | `ChatAgent` | `ChatAgent` itself moves the author or deletes the erased person's messages of every room in one transaction of its own, on the signal | `rePointAuthor()`, `deleteByAuthor()` | the attachments' file ids go to the files path after the commit. Price: one obligation and one result at `ChatAgent` per operation | No ordinary move remains. ChatAgent erasure part built (eraseAccountPart()); live since HIL-1952. The old erasure writer was retired in HIL-1952. The directory's `chat` owner implements `AccountMergeProjectOwner`. Its `mergeAccountPart()` transfers authorship across all rooms in one transaction with its result; the existing `chat_account_operation_step` entry dispatches by durable operation kind, and the live coordinator sends it after give (HIL-1999). HIL-1999 removed the holder's borrowed message `Update`. |
| `ChatDbContext::eventAttachments` | `ChatAgent` `Add`/`Update`/`Remove` (created with the message) | `ChatAgent` | `ChatAgent` deletes the DB links with the messages and hands the file ids to the current files path after the commit | `deleteForMessages()` | none beyond the messages' portion. Price: the file removal stays post-commit, with no new guarantee | No ordinary move remains. ChatAgent erasure part built (eraseAccountPart()); live since HIL-1952. The old erasure writer was retired in HIL-1952. |
| `ChatDbContext::events` | `ChatAgent` `Add`/`Update`/`Remove` (started, stopped, cleared, messages; `deleteAll()`, `deleteByIds()`); chat users library `Add` only (explicit declaration) (`addUserRegistered()`, `addUserRenamed()` — a second creator the first census did not list, present at the base too) | `ChatAgent`; the chat users library for the registered line and the feed line | `ChatAgent` deletes the events of its messages, registrations and rename feed with their links; the chat users library's creation of feed lines stays creation alone and grows no edit; the personal rename journal is not written here | `deleteByIds()` in the erasure | the event ids come from the capture of the person's agent, whose transaction took the journal off them (*The erasure captures*); `ChatAgent` obtains nothing from the agent. Price: the ordering with the rename journal, above | The users library remains Add-only; journal link and unlink are the separate userRenames row above. ChatAgent erasure part built (eraseAccountPart()); live since HIL-1952. The old erasure writer was retired in HIL-1952. |
| `ChatDbContext::eventUserRegistrations` | `ChatAgent` `Add`/`Update`/`Remove` (history cleanup calls `Remove`); chat users library `Add` only (explicit declaration) (created at registration) | the chat users library | `ChatAgent` deletes its registration events of the erased person | `deleteByTarget()` | none. Price: inside `ChatAgent`'s portion | The users library remains Add-only. ChatAgent erasure part built (eraseAccountPart()); live since HIL-1952. The old erasure writer was retired in HIL-1952. |

**Outside the 24.** These are not among the "20 tables others own", and the
protocol must not lose them at its boundary or when control of the person row
moves.

| Collection or record | Current writer and operations | Target writer | Creator and hand-over boundary |
|---|---|---|---|
| `sessions` | holder `Add`/`Update`/`Remove`: the anonymous row on the handshake, the bind at sign-in, the revert at sign-out, the merge's `go/sessions` and `go/survivor_sessions` duties and the erasure participant, the blocked notices released, the sweep `hilos_sessions_expire`; `SessionTestExpireCommand`, test-only | the holder, as today — a session has no instance owner ([Session Ownership](entity-libraries.md#session-ownership), HIL-1403). In the protocol the holder is a participant for its own rows: it releases every reference of a session to the person — the four `RESTRICT` columns, the waits, the cards — as its own step with a recorded result, before the person row can go | Created by the holder; `Add`/`Update`/`Remove` remain there. Release with an atomic participant result is live from HIL-1952: `eraseAccountSessionsPart()` records `{"sessions":<n>,"accessLog":<m>}` and tells the tabs after the commit (HIL-1943), on the live HIL-1952 path. For merge, live `go/sessions` (`mergeAccountSessionsPart()`) requires the exact give, transfers the loser's access log and ends its sessions; prepared `go/survivor_sessions` (`finishMergeSurvivorSessions()`) requires the exact receipt and ends survivor sessions only when the saved give kept the loser's factor. Each commits its own strict `AccountMergeSessionsResult` (`sessions`, `accessLog`) with its work, checks and replays saved results before current-stage or business reads, and publishes session/profile notifications only after commit (HIL-1995). The live coordinator dispatches these duties after give and receipt (HIL-1999). Its own release work is a live erasure participant from HIL-1952; merge release work is live from HIL-1999. |
| `accessLogEntries` | holder `Add`/`Update`/`Remove`: `record()`, the hourly sweep, `rePointToUser()` in the merge, `deleteForUser()` in the erasure | the same owner; it moves and deletes its own rows itself as a participant, on the signal | Created by the holder; `Add`/`Update`/`Remove` remain there. Erasure with an atomic participant result is live from HIL-1952: `deleteForUser()` runs in the holder's `eraseAccountSessionsPart()` and its count is the result's `accessLog` (HIL-1943), on the live HIL-1952 path. Live merge transfer uses `rePointToUser()` in `mergeAccountSessionsPart()` and commits with the holder's own result; a repeat returns its original counts without transferring again (HIL-1995). Its own delete is a live erasure participant from HIL-1952; HIL-1999 connected the live merge transfer and retired any borrowed holder write. No foreign writer is added. |
| `userMerges` | personal agent `Add` for the prepared give and `Remove` for erasure | changes and deletion go with the personal life cycle to the personal owner: the loser's agent writes its own tombstone with its block in the transaction that gives its rows away, and the erasure of a folded account deletes its merge row with its own rows, leaves before survivors so the survivor's `SET NULL` does not run | The loser's personal owner creates the merge tombstone. Leaf-first personal erasure of the merge row is built: the folded account's final deletes it (`deleteForUser()`) before its own row, and the survivor's final waits while an account is folded into it, live in the HIL-1952 erasure path. Loser tombstone creation, merge row before its own block, commits with the prepared give result and private copy; dispatched by the prepared coordinator. Erasure deletion follows the personal life cycle in HIL-1952; HIL-1999 connected tombstone creation to the live owner give and retired the holder's merge rights. |
| the operation record | The `AccountDeletion` request and `UserMerge` tombstone remain domain rows. Shared `AccountOperation*` carriers hold both erasure and merge operations, assignments and results; the due sweep, force-purge, browser merge and `account:merge` use them. | The sessions holder creates and advances operations and initial assignments; assignments are immutable. Each participant owns its result for erasure and merge. | `AbstractSessionsLibraryAgent::OWNS_DB` declares operations `[Add, Update]` and assignments `[Add]`. The record actions preserve exact repeats and reject conflicts; `AccountMergeOpening::open()` atomically stores a merge, its two initial assignments and the project's expected composition, and `AccountMergeOpening::reconcile()` appends what is missing. Both are used by the live coordinator path. `openAccountMerge()`, `driveAccountMerge()` and `cancelAccountMerge()` provide protected entries. `AccountMergeCoordinator` derives waves from strict durable proofs and calls the existing cancellation and end gates; participants alone write their own results. The shared holder pass and owner-start resume recover open duties from these DB facts; the live callers use this path from HIL-1999; real peer/failover/restore evidence remains HIL-2013. The common tables, whole-table `PURGE` verdicts and migration are documented above. Prepared holder answer correlation and end after receipt, all canonical results, no wait and physical copy absence are connected. Erasure switched in HIL-1952 and merge switched in HIL-1999. |
| the participants' preparations and results | The immutable result carrier and generic atomic step exist. The person's agent (HIL-1939), the sessions holder (HIL-1943), the data export agent (HIL-1946), the legal agent (HIL-1947), the users library (HIL-1944), the delivery channel agents (HIL-1945) and ChatAgent (HIL-1949) declare the result claim, and their steps are live in the HIL-1952 erasure path. | Each participant owns its preparation or result, written in the same transaction as its work; the coordinator may create the initial assignment and never edits a recorded result. These durable records use `Hilos::$db` collections and actions, not agent memory or a runtime overlay. The merge's give result is a nonsecret manifest and outcomes; its full secret-bearing hand-over copy is a separate `accountMergeCopies` row with a bounded life, kept until the survivor's confirmation and then deleted by its one writer through the live cleanup entry (HIL-1998). The personal capture of an erasure is a result with personal data too, the one place the person's addresses are kept; the final transaction of the person's agent deletes it, with the person's own `Remove` over its set of results (`deleteForAssignment()`). The final is live in the HIL-1952 erasure path and uses the personal agent's production `Remove` claims over its own rows; see *The erasure captures* and *The erasure final*. The final's own result, exactly `{"request":<id>|null}`, is recorded in the same transaction (`AccountErasureFinalResult`). | Participant results are stored in `hilos_account_operation_result`, at most one immutable JSON payload per assignment. `owner_agent_id` is the full participant agent id and the result set key; the assignment foreign key cascades. The table is `PURGE` under anonymization and is not journaled. `record()` requires the participant transaction and its `Add` set claim, returns an exact semantic repeat, and rejects a changed result. `AccountOperationParticipantStep::run()` checks the caller, assignment and owner, returns a saved result before new work, then runs work and records its result in one transaction; an equal-result refusal or duplicate insert may return the committed winner only after that transaction rolls back. `forOperationStage()` returns a typed `AccountOperationResults` collection in result-id order. The production participant claims are the person's agent's (HIL-1939), the sessions holder's (HIL-1943), the data export agent's (HIL-1946), the legal agent's (HIL-1947), the users library's (HIL-1944), the delivery channel agents' (HIL-1945) and ChatAgent's (HIL-1949); the live erasure coordinator consumes their results from HIL-1952. Merge reservations are recorded by each personal owner and retained as immutable preparation results after release; `AccountMergeRelease::run()` records that owner's `{"release":"released"}` result without releasing the other side. Both release results are required before `AccountMergeCancellation::advance()` can finish `not_started`, including when an owner never reserved. The live coordinator sends both release duties and checks both exact saved bindings before cancellation advances; the personal match executes each owner's own release. Loser work, strict `AccountMergeGiveResult` and separate private copy commit together in `AccountMergeGiving::run()`; its saved replay precedes addressability and business reads, and the live coordinator dispatches give through the existing personal assignment match. Survivor own writes and strict go/receive receipt commit together in `AccountMergeReceiving`, with saved-first replay, warm memory admission and current-own startup resume. HIL-1999 connected live callers to dispatch and direct hand-over. The holder's prepared `go/sessions` and `go/survivor_sessions` entries commit their own strict `AccountMergeSessionsResult` with their work; the first requires the exact give, the second the exact receipt and the saved give's loser-factor outcome. They replay saved results before current-stage or business reads and publish session/profile notifications only after commit (HIL-1995); the live coordinator produces these duties from HIL-1999. Project participants are built — `AccountMergeProjectStep` with its `{"rows":{…}}` result — dispatched after give by the live coordinator with the original assignment, including preparing stage; HIL-1999 connected the live caller; chat's `mergeAccountPart()` uses the existing assignment ask/answer entry and records exactly `{"rows":{"messages":<n>}}` in the same transaction. The live `cleanupForMerge()` entry deletes only the loser's copy after the exact receipt and requests stop only after its cleanup result commits or replays (HIL-1998); the existing assignment handler dispatches cleanup, and the source restores its finishing context from saved give and preparation proofs (HIL-1998). HIL-1999 connected the live merge to these records. Live erasure uses these results from HIL-1952. |
| `accountMergeCopies` | personal agent `Add` in its prepared give transaction and own-set `Remove` after receipt; no `Update` | the loser alone owns the private copy, keyed by assignment and partitioned by its full agent id | `hilos_account_merge_copy` is a non-journaled `PURGE` carrier with a cascading assignment foreign key. Only metadata is mapped or synced; its DB-only payload is read through the canonical owner/assignment/manifest guard. Missing copy returns `null` without changing the fact; corrupt storage refuses and is never rebuilt from the survivor. `deleteAfterReceipt()` checks the exact source give/result and receipt during open go, then deletes through the owning item without reading its private payload. `AccountMergeCleanup::run()` records strict `{"cleanup":"cleaned"}` in that same participant transaction; `hasStored()` checks current locked metadata through the mounted row connection, independently of the local mirror or snapshot. Saved cleanup replays before current-stage or copy reads. `AccountMergeFinishing::forLoser()` resolves the latest canonical saved source give, including finished operations, through `accountOperationAssignments->mergeGiveOfLoser()`. The current metadata lookup uses mounted carrier bindings and retains held Object identity. The personal agent restores its idle pin before ordinary startup trust work, closes late service writes, accepts its own saved continuation and cleanup, and stops only after successful cleanup; an unresolved lookup closes ordinary work and retains the instance without polling from idle checks. Cleaned sources keep no idle pin and can serve a later own erasure after merge completion. Assignment dispatch and finishing are live through the coordinator waves and direct source-to-survivor transport; HIL-1999 connected the live callers. Recovery uses the shared holder pass and owner-start resume. |

**Deltas against the first census.** The census of the research base listed
the four chat tables as "`ChatAgent` owns, the holder borrows"; the chat users
library's creator claim on `events` and `eventUserRegistrations` was not in it
and is in the map now. The raw `UPDATE` of the restore path over the delivery
journal and the test-only writers (`UserTestSeedCommand`,
`NotificationTestSeedCommand`, `SessionTestExpireCommand`, the chat's
`Hilos::OWNS_DB` under `TestOnlyCommand`) were not in it either; they write
with the daemon down or in a test process and stand outside the protocol, and
they are named so that the map is not read as complete without them. The
fresh check expands the declarations rather than inferring rights from
calls: the notifications library's delivery `BY_KIND` is all three operations.
The chat users library explicitly declares only `Add` for `events` and
`eventUserRegistrations`; its unused `Remove` was removed. ChatAgent's history-
cleanup claims and the holder's terminal grants remain as listed. The closed
catalog records the exact remaining claims.
Trigger-written journals and the scoped receipt lifecycle are outside this
ordinary-owner protocol and remain governed by [truth-source.md](truth-source.md).

## Recovery

Recovery is what makes the protocol safe to run without one transaction: at
every point below something has committed and something has not, and the table
says who finds the remainder and why doing it again cannot harm. The table
retains the research cases for both protocols; their implementing boundaries
are named below. Four rules hold the table together.

- **Unfinished work is selected from the facts.** An owner that starts — after
  a stop, a death, an idle sleep or a move to another node — reads its own
  records and the assignments addressed to it and finishes what is open; the
  coordinator reads its operation records and asks again for every result it
  does not hold. No cursor, no position in a walk and no memory of a class
  survives, and none is added
  ([After A Failure](../signals/work-in-portions.md#after-a-failure)). The
  read happens at start and when a frame of the operation arrives, not on
  every tick: the database is not polled for ordinary reading.
  Erasure's selection and continuation from DB are built (HIL-1951; *The
  erasure recovery*); the due and force-purge paths open erasures from HIL-1952.
- **The initiative is the coordinator's record and the owner's start, not the
  next frame.** A signal hastens; it is not what makes the work owed. The
  coordinator's record says which results are missing and the holder asks for
  them again when it starts and when it passes over its open records; a
  participant's start finds its assignments. "Another frame will come
  eventually" is not a mechanism.
  Erasure recovery progresses after a lost frame without a new external
  event: the holder's pass and an owner's start are built (HIL-1951), and
  the due and force-purge paths open erasures from HIL-1952.
- **A participant's atomic result is not exactly-once for the operation.** Each
  step is done once or not at all at its owner; the operation as a whole may
  ask twice, hear nothing or hear late, and every step is written so that the
  second ask answers with the stored result. The result and the clean-up of a
  hand-over copy have one writer each, and a repeat of either is the same
  answer again.
  Erasure's generic participant step commits work and result in one local
  transaction and answers a repeat from the saved result. The person's agent
  and delivery channel/shard erasure parts are live from HIL-1952; a channel
  result records `{"deliveries":<n>}`. The coordinator
  accepts an answer only for the matching operation and stage (HIL-1950).
- **A closed account stays closed.** No internal continuation, no late frame
  and no misaddressed answer opens the ordinary actions of an account whose
  start fact is recorded: the finishing mode takes the continuation of its own
  operation and refuses the rest, a missing or folded account in today's words
  and an account whose erasure has begun in its own ("This account is being
  deleted."). The agent's finishing mode and the sessions holder's refusal of
  sign-in are live from HIL-1952 (*The closed account*).

The last column is a requirement on the screens the leaves will draw — the
people list, the admin card, the merge window; no screen is built by this
page.

| Failure point | What is left in the database | Who continues | Why the repeat is safe | What a person sees |
|---|---|---|---|---|
| Before the start, or during preparation: the record exists, the erasure's started fact or the merge's fact is not written | the operation record at *preparing*; in a merge none, one or two preparations | the coordinator, at its start or its next pass: it asks the owners to prepare again, or closes the record as *not started* when a cancel or a refusal is recorded | nothing of the person's has changed; a preparation written twice is the same reservation; a request's start is a conditional write that wins once | an administrator sees the merge as *preparing* and may cancel; the person's account works, except that a reserved account refuses changes of its ways in and protection |
| After the start, before the signal reached a participant | the started fact and the coordinator's record; the assignments it wrote; no results | the coordinator, which re-sends every assignment without a result; a participant that starts and finds its assignment | an assignment has no effect until its owner acts, and sending it twice creates no second obligation, because it names the operation and stage | sign-in refused; *erasure in progress* with every participant listed as expected |
| An owner committed its part and died before answering | the owner's result, written in the same transaction as its work | the coordinator asks the owner again; the owner, raised in finishing mode, reads its result and answers with it | the work and the result stood or fell together, so a result present means the work is done and the repeat only answers | the participant is listed as expected until the answer arrives; nothing of the person's comes back |
| The answer was lost between the owner and the coordinator | the owner's result; the coordinator's record without it | the coordinator asks again at its start or its next pass over open records; the owner answers from its result | the result is read, not redone; the coordinator records it once, and a second copy of the same answer changes nothing | as the row above; the delay is a wait, not a reopening |
| The loser's fact committed and the frame never reached the survivor | the re-pointed rows in the survivor's set, the tombstone, the loser's result with its hand-over copy; no confirmation | the loser's agent, raised to finish, sends the copy again; the survivor, when it starts first, reads its set and the obligation and confirms from them | the copy is read from the loser's result and never from the survivor's set, so the frame is the same frame; the confirmation is written once per operation and stage | the loser is folded and refused; the survivor's ways in and protection stay restricted until the confirmation; a sign-in through a transferred way in waits |
| The survivor applied the rows and died before confirming, or an old frame arrives again | the survivor's own writes — the wait, the trusts — with its confirmation in one transaction, or none of them | the survivor, at start, reads its set and the obligation; the loser's agent sends again until the confirmation is recorded | retained durable metadata rejects stale DB_SYNC and hand-over rows independently of receipt or copy retention; a cold or behind context returns pending-current rather than applying an old copy; the confirmation names the operation and stage, so a frame already confirmed answers with it and a frame of a closed operation is refused | the restriction holds until the confirmation, then lifts |
| An owner dies, stops idle or moves to another node in the middle | its results so far; its open assignments; its grants gone while it is down ([agent-lifecycle.md](agent-lifecycle.md#when-a-worker-dies)) | the owner, placed and started again by the next frame or by the coordinator's ask, on whichever node the placement names; it reads its records in its start and finishes | the address in the record is the agent's type and index, not a process or a node, so the new incarnation is the same owner; the old copy is stopped before the new one starts ([daemon-lifecycle.md](daemon-lifecycle.md#node-health-and-failover-hil-183)) | a longer wait; the operation stays open and names the participant |
| A required owner is missing: a table with rows and no agent registered for it | the rows; the assignment the coordinator wrote; no result | nobody, until the project registers the owner again; there is no fallback writer, and taking away the last owner of rows that stay is a separate decision (question 4), not an administrator's act and not a way to close the obligation | no fallback writer takes the rows, so no row gets two writers and nothing is deleted by a process that does not own it (question 4) | an explicit open obligation, *waiting for an owner of …*; the account stays closed |
| A child with a hard key was forgotten and no participant deleted it | the child row; the agent's final transaction rolled back, the person row and the journal still there; on the operation, the account's number and the referencing table's name, never the engine's text (`final_refusals`) | the coordinator, which reconciles the census after the refusal and keeps the reason (`AccountErasureFinal::refused()`), and the agent, which repeats the final on the same assignment once the owner of the row is a participant and has its result | `RESTRICT` refused the delete and the agent's transaction rolled back, so the request is not marked carried out and nothing is half-deleted; the next refusal of the same account replaces its reason, and a passed final ends it; live in the HIL-1952 erasure path | the operation stays open at the final step, and the card's erasure block names the refusal in `refusals`; no account comes back |
| A soft reference stayed behind: a delivery whose notification is gone, a mention set null | the delivery rows under the channel's assignment; the rows meant to stay — the request, a rename whose author is set null, a merge whose survivor is set null | the channel, from the recipient and the ids in its assignment, recorded before the notifications went | a delete by recorded ids is the same delete twice; the rows meant to stay are part of no obligation | the channel is listed as expected until its result; the trace rows carry the deleted person's number, as the journal's receipts already do ([change-log.md](change-log.md#one-scheduled-attempt-across-parts)) |
| The channel is switched off | its journal rows and its retention term; the assignment to delete the erased recipient's rows | the channel's own agent, which stays available for retention and erasure (question 4); the dispatcher only stops creating new deliveries | switching off writes nothing and deletes nothing; when the started fact exists, the built guard skips the send attempt and refuses an administrator retry in the channel's own words | the channel erasure part records `{"deliveries":<n>}`; live in the HIL-1952 erasure path. History and term unchanged |
| A backup is restored with an operation unfinished | whatever had committed when the archive was taken: a started fact, some results, a tombstone with its copy, rows half moved | the coordinator and every owner, at their start after the restore, exactly as after a death: open records are selected from the facts and finished | every step repeats from a recorded result or a conditional write; the archive carries the records because they are rows — which is why they are rows | the account is as closed as it was at the archive's moment, and the operation finishes; a restore into a lesser environment classifies the records with the rest ([backup-anonymization.md](backup-anonymization.md#core-rule)) |

The restore path has one raw write today, `RestoredDeliveries::settlePending()`,
in a process with no agent: it settles pending deliveries as failed and is named
in the *Writer Map*. Under the protocol an erased recipient's rows are the
channel's obligation whatever that settle did, and the settle is no step of the
operation.

In the live path (HIL-1952), a missing owner leaves an open obligation without
a fallback. A final `RESTRICT` refusal rolls back only the personal owner's
final transaction and leaves the operation unfinished, with the account's
number and the referencing table's name kept on it (*The erasure final*). The
card's first page reply and live updates carry the wait (HIL-1954) and safe
refusal in `refusals`. The active Binance scenario 59 verifies that an
unregistered delivery owner remains open after one recovery pass, with no
finished result; it also adds a late identity and observes the safe
`{userId: hilos_identity}` refusal, rollback of the personal final transaction,
and no final result.

Binance scenario 58 opens one erasure circle and suppresses its start ask.
Ordinary recovery sends the debt again. Live duplicate ask/answer envelopes
preserve the exact result id, owner, payload and creation moment. Before its
single root-host kill, SQL proves personal commit and identity removal while
mail result is absent, delivery is pending and the operation remains open;
the folded account has already finalized. The root starts on the surviving
slave, while the same holder stays alive on another node. Completion retains
the personal result and neighbour facts; registered disabled email completes
its own erasure duty. A stale saved ask from the live holder has an observed
participant answer and leaves results and effects unchanged.

Binance scenario 60 archives one partial circle with only the root's personal
and final duties missing. The folded final is committed, its capture is gone,
and the root capture remains. FULL capture and digest verification leave the
complete ordered operation/assignment/result snapshot unchanged. The same
root then really stops idle with personal/final still absent. One holder
restart, proved by fresh stop/start lifecycle lines even in the same worker,
finishes the original intent without releasing the old probe or adding a new
purge. Hot FULL import removes a post-archive marker and ordinary startup/resume
completes the archived intent. Archived NON-CAPTURE results, including the
folded final, keep their exact id, owner, payload and creation moment; assignments
are exact, personal/final results are rebuilt after restore, both finalized
captures are absent, and the folded account and neighbour remain as expected.
These are fresh pointed cluster proofs, not an exhaustive missing-owner or
restrictive-child catalog; local participant integration retains that scope.

For merge, repeating preparation returns the owner's saved reservation and
captured identity. After a preparation refusal or authorized cancellation,
`AccountMergeCancellation::request()` enters `cancelling` only before the
current `stage='go', key='give'` result; it records separate durable release
assignments, and `advance()` finishes *not started* only after both owners'
results. A partial release leaves the original preparation result intact and
no longer holds that side, while the other owner remains pending.
The prepared coordinator dispatches and correlates these duties, checking both
exact release bindings before the existing advance gate. Explicit drive of the
same operation id recovers ready missing duties after a lost frame and may ask
for one saved give replay before receipt. Answers alone start no resend loop.
The holder pass discovers open Erasure and Merge operations on a new holder’s
first tick and each hour after, in ascending operation id order, ten per tick.
A full portion continues on the next tick; the cursor is local to the
holder, and a new holder starts from the first operation. Owner start discovers
ready duties for both kinds in assignment id order. The pass uses one attempt
receipt, and a quiet tick does not read the database. Neither path adds a
per-tick DB poll or a second recovery driver or cron. A sleeping survivor has
the finite own-start receipt resume described below. Row admission and its durable fence
against stale `DB_SYNC`/hand-over are built as a prepared API, separate from receipt;
`pending-current` leaves a cold or behind context waiting for current state.
The prepared survivor owner step commits its own password, wait and trust
writes with its exact receive receipt. Its finite own startup scan resumes an
existing obligation from current mapped rows, including a new owner in the same
worker, without a new handover. Prepared source cleanup commits copy deletion
and its result together;
a failed transaction restores both, and a lost answer can replay the saved
cleanup without reopening ordinary access. The source restores its finishing
context from DB before ordinary startup work. Prepared holder end requires the
canonical receipt, every required owner result and current copy absence.
Duplicate, old and foreign replies
are judged by operation and stage, never by whichever operation happens to
be current; only the original project expectation gets the existing carried
assignment exception. These paths are live from HIL-1999; cluster and backup
evidence covers their failures and the absence of a second effect
(not in the code yet — HIL-2013).

## Guarantees And Cost

**What is guaranteed.**

- *Access closes at the start of the irreversible step.* The erasure's started
  fact and the merge's fact are the logical closing points: from each, sign-in
  and ordinary actions are refused. Rows of the person may lie in the database
  until each owner has processed them or has started; they are not an
  available account and are not shown as one.
- *The end is proved by results, not by keys.* An erasure is complete when
  every required owner's result is recorded — the soft references and the
  service copies among them — and the final `DELETE` of the person row has
  passed `RESTRICT`. A merge is complete when the survivor's confirmation and
  the other owners' results are recorded and the hand-over copy is gone.
- *A failure leaves an operation, not half a person.* It is visible as an
  unfinished operation with the participants still expected and the refusal, if
  there was one. The account does not come back, and the operation does not
  complete by timeout.
- *A merge keeps ids and references.* Rows are re-pointed, never recreated; the
  chosen protection is applied at the survivor before a transferred way in
  signs anybody in; after a partial commit the operation is recovered forward,
  never rolled back as a whole.
- *The closing point is logical, not instantaneous everywhere.* A node learns
  of the fact by the sync frame and the assignment; between the commit and
  that arrival another node may still hold the old picture. No instant,
  cluster-wide memory is promised: a protocol between owners is what replaces
  the one transaction, and it has a flight time.
- *The backup window stays.* An archive taken between the start and the end
  carries part of the person's rows, and the operation records beside them;
  the owner accepted the window (2026-10-18). The records let the operation be
  finished after a restore, and a future anonymization classifies them with the
  rest.

**What is not guaranteed, and said so.**

- Exactly-once across owners: each owner's step is atomic, and the operation is
  repeated safely.
- A bound on the delay after a failure: the wait lasts until the operation is
  recovered, and no number is promised — the hundred milliseconds of the
  proposal are an expectation of the ordinary case.
- Files on disk, outside services, runtime collections and frames in flight:
  the post-commit steps that remove files and run the export agents' sweeps are
  the ones the tree has, logged and not retried; this page attributes no retry to them
  and gives them no new guarantee.

HIL-1952 connected the prepared closure, finishing mode, participant steps,
final, coordinator and DB-based recovery to the live due and force-purge paths.
Each owner's transaction commits its work with its result; a later refusal does
not undo earlier owners, and the durable operation remains unfinished for
recovery. A start transaction rollback restores its date and result while the
operation intent remains; a refusal after a committed start does not move the
date back. HIL-1963 aligns the remaining applicable e2e. Binance scenarios 58–60
record the erasure cluster and backup evidence in [Recovery](#recovery).
No timing bound, common rollback after start, exactly-once claim or stronger
file guarantee is added.

For merge, preparation and cancel-before-fact release are built without
changing business rows. The cancellation request takes an exclusive operation
metadata lock and reads the current give result; a participant takes the shared
lock on that same row through its transaction. Both owners' durable release
results are required to finish *not started*, while a released side's saved
preparation remains unchanged. The live coordinator validates both exact
release bindings before advancing and never writes another owner's result.
HIL-1999 connected the live merge path. After a committed give result,
the cancel path returns too late and only forward work remains. The loser's
transfer, tombstone and private copy commit with the nonsecret result in the
prepared `AccountMergeGiving::run()`; replay reads only the saved fact and copy.
Prepared admission protects memory against stale frames using retained latest
metadata and original give stamps, independently of receipt, cleanup and a later
merge; `pending-current` does not reread business rows or confirm receipt.
The survivor owner applies its own chosen protection with its exact receive
receipt in one participant transaction; saved replay and local startup resume
are connected to the live coordinator and direct hand-over from HIL-1999. The
holder's `go/sessions` and `go/survivor_sessions` entries commit their
own strict session and access-log counts atomically after exact give and receipt
respectively, then publish session/profile notifications after commit (HIL-1995).
The live coordinator produces them in the give and receipt waves. Declared
project owners use `AccountMergeProjectStep`,
including chat's author transfer and exact message count through its existing
assignment entry. Their original preparing assignments run after saved give
through HIL-1998's carried gate, retaining the same immutable coordinates.
Prepared loser cleanup commits copy deletion with its strict result before
requesting stop; exact saved replay needs no copy or business reads. Source
startup restores finishing from immutable proofs, and pending cleanup or an
unresolved lookup prevents idle stop. Prepared holder end requires canonical
receipt, all fixed duties and original project results, and current copy absence;
its conditional finish and proofs share one transaction.
Correlation prevents an unrelated answer advancing the operation. Explicit
drive derives missing ready work from DB; the handler queue is not a pending
store and accepted answers start no retry loop. The shared first/hourly holder
pass and owner-start resume continue open duties from DB, as described under
The erasure recovery. The live merge uses this recovery path; real cluster and
backup evidence remains HIL-2013. The backup window, safe repeat rather than
exactly-once, unbounded waiting and existing external/file limits remain.

**The price, per piece.** Named in local transactions, confirmations and
messages, copies kept and the start of each owner; no measurement and no
money, because none was taken.

| Piece | Local transactions | Confirmations and messages | Copies | At start |
|---|---|---|---|---|
| The durable operation and the obligations | one write of the record per stage change at the coordinator; one assignment per participant | one ask and one answer per participant, repeated after a failure | none | the coordinator reads its open records; every participant reads its open assignments |
| The erasure of one account | one per participant, where today there is one in all; two at the agent, the start and the final delete | one round per participant, plus the captures before the anchors; a circle multiplies by its accounts | the preparations: addresses and ids | each agent of the circle raised in finishing mode once |
| The merge | one preparation each side, one fact at the loser, one confirmation at the survivor, one per other owner | prepare, go, the frame with the rows, the confirmation, the end | the hand-over copy, until the confirmation | both agents read their sets and obligations when they start |
| The channel's retention and erasure | one per channel per operation; one per prune at the channel | one per channel | none | the channel's pick-up at start reads its open assignments beside the pending rows |
| The trust term and the switch-off | one per person with a trust row, where there used to be one statement — the highest price in the *Writer Map* | one frame per person, repeated for an agent that is asleep at its start | none | the agent applies the term it finds at start |
| The strict guard | none at run time beyond the check at the door | none | none | the topology and the door refuse what they refuse today, plus the operation's check |

The price the owner weighed and took is the first row: a durable record and a
round of confirmations where today one `COMMIT` ends everything. The other rows
follow from it.

## Cutting Inputs

The numbered pieces below preserve the historical research input to the cut
of HIL-1674. They are not today's execution order: the approved
[three phases](#three-phases) and [ordinary transitions](#ordinary-transitions)
now name the leaves. The detailed [erasure norms](#erasure) and
[merge norms](#merge), with their implementing addresses, are recorded above.
HIL-1952 completed the live erasure switch; HIL-1999 completed the live merge switch.
Each historical piece rests on an answer already given, has an input and an
output result, and leans on the framework's existing contracts. Units
and integration tests go with each piece's code leaf — there is no separate
receiver of tests — and a check of real failures between nodes is planned after
the piece whose failure it checks, not proven by this document.

1. **The durable operation and the obligations.** Rests on question 1. Input:
   the holder's sweep and merge command as they are. Output: the operation
   record, the assignments and the results as rows, with the owners named
   here, and the end recorded on results. Needs: the ORM's collections, actions
   and transactions; the truth-source registry as it is. Price: the first row
   of the table above. Checked by: a restart between any two steps finishes
   the operation from the records.
2. **The narrow transfer and the confirmed receipt.** Rests on the merge
   decision and question 2. Input: a reservation on both sides. Output: the
   loser's fact and hand-over copy in one transaction under the transfer right,
   the survivor's confirmation with its fence, the copy's deletion. Needs:
   piece 1; the set width and its door as built. Price: the merge row above.
   Checked by: a frame sent twice and a frame of a closed operation change
   nothing; a sender dead after the commit is finished from its copy.
3. **Ordinary and mass edits brought to the single owner.** Rests on the answer
   about libraries. Input: the writes the *Writer Map* names as lost by their
   present writer — the library's `resetForRetry()`, the push `subscribe()` of
   an existing endpoint and `markGone()`, the library's `Remove` of an
   unfinished enrolment, the test-only `forgetLaterThan()`, the trust term and
   the switch-off. Output: each as a frame to the owner and a write there.
   Needs: the frame-to-the-agent shape of HIL-1404. Price: a hop per edit; the
   trust term's sweep. Checked by: the borrowed claim for each goes, and the
   write is refused anywhere else.
4. **The channel's journal, retention, retry and the switched-off channel.**
   Rests on the delivery-status resolution and question 4. Input: the
   channel's `Update` claim and the library's prune and requeue. Output: the
   channel owns retry, prune and the erasure's delete of its rows; a
   switched-off channel keeps its agent for them; the pick-up at start skips a
   started erasure. Needs: the shard split by channel and recipient as built.
   Price: a retention and an erasure portion per channel. Checked by: a
   switched-off channel still prunes and erases; no second writer meets a row
   when the pool changes.
5. **The split erasure with every owner.** Rests on questions 1 and 3 and the
   circle decision. Input: pieces 1 and 3. Output: the nine steps of *Erasure*,
   the derivation of participants, the captures before anchors, the finishing
   mode of the agent, the holder's borrowed `Remove` claims gone. Price: the
   erasure row above. Checked by: a forgotten child refuses the final step
   loudly; a missing owner is an open obligation; the request completes only
   after the row is gone.
6. **The split merge with the protection kept.** Rests on question 2 and the
   merge decision. Input: pieces 1 and 2. Output: the eight steps of *Merge*,
   the reservation and its refusals, the lock and the re-read gone, the
   holder's borrowed merge claims gone. Price: the merge row above. Checked
   by: the refusals of today's merge are given at preparation in the same
   words; a transferred way in signs nobody in before the confirmation.
7. **The chat owner and the rename links.** Rests on the chat message decision
   and the one `ChatAgent`. Input: the chat holder's four borrowed claims and
   the chat's history-clear unlink from the journal. Output: `ChatAgent` as a
   participant of both operations, the event ids recorded before the journal
   goes, the unlink of a cleared history handed to the person's agent. Needs:
   pieces 1 and 5. Price: two hand-overs and an ordering obligation. Checked
   by: a cleared history leaves the journal rows with their links taken off
   through the owner; an erasure leaves no event of the person.
8. **The strict guard and the borrowed debts taken away.** Rests on the Core
   Rule of [truth-source.md](truth-source.md). Input: each real move of pieces
   3–7 as it lands. Output: the borrowed claim and its comment removed in the
   same commit as the move, the guard refusing what the move made unnecessary,
   and no borrowed claim left standing for a move that has landed. Price: none
   at run time. Checked by: the declared claims equal the writers of this page.

The research originally placed pieces 1 and 2 first, 3 and 4 beside them,
and 5–7 after 1. The approved cut separates ordinary writes first, then erasure,
then merge; use *Three Phases*, not that historical proposal, for execution.
HIL-1892's strict DB judge and closed executable debt catalog are implemented.
Its transition vertices cover changes to DB claims or partition proofs; each
real consumer move removes its active key and passed states in the same commit.
The existing restriction integration and failover 4/19 are recorded; cold
person 39 remains quarantined as F-015. HIL-1963 owns remaining e2e alignment.
Binance scenarios 58–60 record the erasure cluster and backup evidence in
[Recovery](#recovery). Each leaf clears only markers its behavior makes true.
