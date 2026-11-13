# Account Operations: Erasure And Merge Across Owners

Read this before designing or cutting the split erasure and merge of an
account — the two operations that today run as one transaction at the sessions
holder across tables other agents own — before giving a library, a channel or
the sessions holder a write it would hold only for one of those operations, and
before deciding who the single writer of a person's row is after that row has
been created.

**This document preserves the approved research of HIL-1675 and now records
the three implementation phases agreed in HIL-1888, HIL-1889 and HIL-1890.**
The detailed erasure and merge norms are recorded here, separately from the
leaves that prepare their mechanisms and their single live switches. Neither
terminal protocol is code.
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
steps — the files library's removal, the export agents' forget frames — stay
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
The erasure norms below name the leaves that prepare each capability separately
from HIL-1952, which enables it on the live path. The [merge norms](#merge)
likewise separate preparation from the single live switch HIL-1999. Operation,
assignment, preparation, result, receipt and copy name logical obligations,
authorship and order. HIL-1936 and HIL-1937 introduced the erasure operation,
assignment and result storage. HIL-1987 gives them common `AccountOperation*`
carriers for erasure and merge, and adds the inert merge opening record and
its initial assignments. It does not add a second operation journal or
participant catalog. These storage APIs do not connect participant business
work or the live merge path; the implementing leaves still settle those
contracts.
No reservation field, receipt field, admission metadata, partition, frame or
business stage beyond the initial `preparing` storage value is declared as an
API here. The contract gate stands unchanged.
A preparation leaf does not activate a terminal path or retire its old writer.

**Where the facts come from.** The historical census was read at research base
`1d579875e`. HIL-1891's documentation base is `a48d6239b`; the current claims,
expanded operations and call sites below were checked again at `f6a5f2e8f`
on 2026-11-07. Merge facts were rechecked at `e473fd55c` on 2026-11-08:
`mergeAccounts()` and `foldAccount()`, password settlement and collision,
the immediate stop on a merge row, the set guard, DB sync application, the CLI
and tracked action, and chat's project seam. The historical base is kept as
evidence, not presented as a fresh audit. Current implementation facts are
not an approved future contract: where later code differs, update the facts
and clear only the markers whose behavior actually landed.

## Three Phases

| Phase | Boundary and remaining writers | Documentation and live switch |
|---|---|---|
| 3.1 — ordinary writes (HIL-1888) | One changing writer after creation for each DB row; ordinary overlaps are exhausted by the named moves below. Only the explicitly named old erasure and merge paths remain afterward. RT ownership, new DB widths and a re-cut of `_setVia` are outside the phase. | HIL-1891 records the norm; HIL-1892 defines the strict judge and executable closed debt list; HIL-1893–HIL-1910 land the ordinary moves. HIL-1911 aligns applicable e2e and HIL-1912 supplies cluster evidence after their code prerequisites; neither clears another leaf's business markers. |
| 3.2 — erasure (HIL-1889) | Preparation is inert. The old due sweep and force-purge remain the only live producers, with their grants, comments and debt. Switching live erasure removes erasure debt and keeps the rights still needed for merge (not in the code yet — HIL-1952). | HIL-1935 records the detailed [erasure norms](#erasure) here; HIL-1936–HIL-1951 prepare the records and participants. The [cutover requirements](#erasure-cutover-and-observation) name the guards, CLI and three frontends that must be ready for HIL-1952. |
| 3.3 — merge (HIL-1890) | Preparation is inert. The old merge remains the only normal producer until its switch removes the remaining merge debt (not in the code yet — HIL-1999). | The detailed [merge norms](#merge) are recorded here; HIL-1987–HIL-1998 and HIL-2000–HIL-2011 prepare the mechanisms and surrounding behavior. HIL-1999 alone activates the path and retires the old merge writer under the [cutover requirements](#merge-cutover-and-observation). |

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
| HIL-1900 (implemented) | The holder applies the trust term from the current settings apply request. | The settings library records a trust restriction (`hilos_second_factor_trust_restriction`: the save moment and a term of 0–365 days) in the transaction of the save that shortened the term or turned the factor off while forgetting trusts, and its frame to the holder carries that moment. Each trust keeps the moment it was last granted, and the sign-in gate reads the restrictions from the table, so a restriction ends a trust before any owner has applied it. The person's agent applies them on `hilos_user_second_factor_trust_restrict`; the sessions holder's sweep sends that frame and the owner's start applies the saved restrictions without it (HIL-1901), and the switch-off and the term send it through the sweep's await request (HIL-1909, HIL-1908). |
| HIL-1901 (implemented) | The holder's apply call changes trusts installation-wide; there is no owner recovery of a missed policy hand-over. | The sessions holder sweeps once a minute from a fresh database query for people whose trusts violate a saved restriction, sends each the frame above in portions of at most 100 in flight and hears `hilos_user_second_factor_trust_restrict_done`; a new pass starts unasked only when none has run or an hour has passed. The personal owner applies every saved restriction when it starts, so a sleeping owner applies what it owes without a frame. The sweep's state is the holder's memory and adds nothing to the database; the switch-off and the term go through the same sweep (HIL-1909, HIL-1908). |
| HIL-1902 (implemented) | Notifications library `Remove`: its former raw batched deletion of delivery rows. | The channel/shard owns retention of its terminal delivery rows through bounded pages; pending rows are not pruned and no general cleaner becomes their writer. The library keeps `Add` alone; retry moved to the channel/shard in HIL-1910. |
| HIL-1903 (implemented) | Users library `Remove` beside `Add`: `startEnrolment()` removed an unfinished enrollment. | Every start of an enrollment asks the personal owner to drop the person's unfinished enrollments (`hilos_user_second_factor_enroll_drop`); the owner deletes them and answers (`hilos_user_second_factor_enroll_drop_done`); the library resumes the same press and creates the new enrollment with its initial secret. A refusal by the owner refuses the press in its words and writes nothing; an answer that finds an unfinished enrollment standing again - a start pressed at the same time created it - refuses its press with `SETUP_EXPIRED`. The library keeps `Add` alone. |
| HIL-1904 (implemented) | Users library `Remove` beside `Add`: `issueSet()` deleted old backup codes. | Every issue of a backup-code set asks the personal owner to drop the person's whole set (`hilos_user_second_factor_codes_drop`); the owner deletes it in its own transaction and answers (`hilos_user_second_factor_codes_drop_done`); the library resumes the same press and creates the new set. A refusal by the owner refuses the press in its words and writes nothing; an answer that finds a set standing again - a press at the same time created it - answers its press with the unused codes of that set and creates nothing; a failure after the drop fails the press in the general words and fans the person's section again. The library keeps `Add` alone. |
| HIL-1905 (implemented) | Notifications library `Update`: `subscribe()` upserted the person's known device. | The personal owner renews an existing device by its captured subscription id; the library creates new subscriptions. A foreign endpoint is released by its former owner (HIL-1906). |
| HIL-1906 (implemented) | Notifications library `Update`: a foreign endpoint was temporarily rebound in place, its `user_id` changed to the new person. | G1-A: the library asks the former personal owner to release the endpoint (`hilos_user_push_release`); the owner deletes its own row and answers (`hilos_user_push_release_done`); the library resumes the same press and creates a new row for the new person, so the id may change. A refusal by the former owner refuses the press in its words and writes nothing. The library keeps `Add` alone. |
| HIL-1907 (implemented) | Sessions holder `Add`/`Update`: `trust()` created or extended a session/person pair. | New trust creation on entry stays at the holder, and its `trust()` leaves a standing pair as it is: the holder asks the personal owner to renew it (`hilos_user_browser_trust_renew`); the owner extends it to the confirmation in its own transaction, the stored restrictions applied with it, and answers (`hilos_user_browser_trust_renew_done`) whether the pair stands. A pair the owner no longer found is written again by the holder only while the session is still the person's, the person has a confirmed app, the policy gives days and the password has not changed since the confirmation; a refusal is logged, not repeated. The holder's `Update` for the term went with HIL-1908. |
| HIL-1908 (implemented) | Sessions holder `Update`/`Remove`: `capLiveUntil()` or `deleteAll()` for the saved term. | Personal owners apply the cap or zero-term removal using the saved policy; no earlier expiry is extended. The settings library's frame carries `restrictionId` (the restriction the shortening wrote; on a repeat of the same term the latest one; with none, the answer is success at once and no frame); the holder writes nothing, waits on `SecondFactorTrustRestrictionSweep::await(restrictionId)` and answers by the result (0 still owed is success, more than 0 a refusal sentence; the term itself stands). `capLiveUntil()`, `deleteAll()` and the holder's `Update` are gone; the holder keeps `Add` and `Remove` (HIL-1952, HIL-1999). Price: the same as the switch-off — with 100 or more people holding a trust, a save in the middle of a running pass or a silent owner, the answer comes after the browser's 30 s and the administrator sees "The action timed out." while the term already holds (owner's decision, 13.11.2026). |
| HIL-1909 (implemented) | Sessions holder `Remove`: `forgetTrusts` called `deleteAll()` on installation switch-off. | Each personal owner removes its trusts when the switch-off policy requires it: the settings library records the (S, 0) restriction and its frame to the holder carries `restrictionId`; the holder lets the code waits go, waits on `SecondFactorTrustRestrictionSweep::await(restrictionId)` and answers by the result (0 still owed is success, more than 0 a refusal sentence; the switch-off itself stands). Price: the answer waits for the owners; with more than 100 people holding a trust, or a silent owner, it comes after the browser's 30 s and the console's 15 s, and the administrator sees the earlier "no answer" while the switch-off is already in force. |
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
way in) and `Remove` (the merge and the erasure) since HIL-1908; one is held whole for the merge's upsert and
the erasure's delete, `secondFactorSettings`; `users` carries `Update` and `Remove` for the
loser's block and the erased row; first-administrator creation is no longer a
holder write. The other seventeen are borrowed — `Update` and `Remove`, or `Remove`
alone, each with a comment naming the erasure or the merge and pointing at
[instance-owners.md](instance-owners.md#operations-over-many-instances). Beside
them `OWNS_DB_SET` holds one set claim since HIL-1943: `accountOperationResults`
with `Add`, the holder's own results as a participant (*The participant result
claim* below). The
chat's `SessionsLibraryAgent` (`Agents/Hilos/SessionsLibraryAgent.php`) adds
four more borrowed claims over the room tables for the same two operations.

**The erasure.** Once a minute the holder sweeps the due requests
(`sweepAccountDeletions()`) and runs `eraseAccount()` for each, in ONE
transaction: the request is marked carried out first by a conditional write —
a cancel that won leaves everything untouched — then the circle is gathered
(`erasureOrderOf()`, deepest first), every live request of a folded account is
marked carried out too, and `erasePerson()` runs for each account in that
order. Inside it the holder deletes device keys, captures the current
addresses, deletes the codes on them and the ways in, then the codes by
person, the five second-factor tables, the confirmations, the legal
acceptances, the access log and the merge row; `forgetNotificationsOf()`
removes deliveries, notifications, preferences and push subscriptions where
each table is active; `forgetExportsOf()` removes the person's export orders;
`releaseSessionsOf()` signs the person out, releases factor waits and lowers
the closed-access cards; the photo row goes where its table is active; the
project's `applyAccountErasure()` seam deletes the project's rows; the rename
journal and the person row go last. The request's `complete()` therefore means
the whole circle succeeded. After the commit, outside it: forget frames to the
export agents and one `Hilos::$files->remove()` with the named file ids, each
failure logged and not retried. `AccountDeletion`
(`Database/Entity/Item/AccountDeletion.php`) carries `requested_at`,
`effective_at`, `started_at` (the conditional start of *The erasure start*, which
this sweep does not write), `canceled_at` and `completed_at`; its `user_id`
deliberately has no foreign key, so the row survives the person as the trace of
the request, and under anonymization it is purged.

**The merge.** `mergeAccounts()` judges both accounts (`assertMergeable()`)
and the password choice, then opens one transaction: five `lockForMerge()`
calls over the second-factor tables, a `reHydrateCollection()` of each
("source frames may still be in transit"), the check of the browser's
expected protection (`Second-factor protection changed. Review the accounts
and choose again.`), the transfer — `identities->rePointToUser()`,
`passkeyCredentials->rePointToUser()`,
`accessLogEntries->actions->rePointToUser()`, the confirmed factor and the
backup codes re-pointed or deleted by the chosen fate, the survivor's wait
written with `setResetWait()`, the loser's resets, settings and trusts deleted
— the project's `applyAccountMerge()` seam, and `foldAccount()`: the merge row
first, the loser's block flag second. After the commit the loser's sessions
are ended, and the survivor's too when the loser's protection was kept.
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
project's expected composition (see the project owners below). It has no
live caller: the existing merge remains the only live merge producer. The
kind-required `openPage()` and `openForOwner()` keep erasure and merge reads
separate, and `AccountOperation::mergeFact()` reads the loser's go/give result;
the coordinator does not write that result.

The operation table keeps `open_loser_user_id` as an ordinary nullable `INT`
in `_columns` and `_types`, with a UNIQUE index. `recordMerge()` sets it, and
`finish()` clears it in the same UPDATE that records the finish. The current
FULL restore structure replay cannot model generated columns, so the
open-merge key remains a stored column. One immutable Change creates the three
new tables and a second drops the three former erasure tables; the changes do
not use `RENAME`. The historical
`account_erasure_*` schema-unit keys stay in place because immutable Change and
install references already name them; the restore mechanism is unchanged.

**The stop.** `AbstractUserAgent` (`Users/Agent/AbstractUserAgent.php`) stops
itself the moment its person row is deleted (`onSignalDbSyncDeleted()`) or a
merge row keyed by its person appears (`onSignalDbSyncCreated()`, HIL-1410).
Both are immediate: the agent is gone before anything of its own could run.
Under the target protocol the loser's agent is the one that gives its rows
away and the erased person's agent is the one that deletes its part, so this
stop is in the wrong place by construction: either the agent's own work is
done before the final fact, or the agent runs on in a mode that only finishes
the operation (question 3). `AddressablePerson::require()`
(`Users/AddressablePerson.php`) refuses a missing, folded or erasing person
before the hop and the agent repeats the check; the service continuation of a
future operation must not become a general way around that refusal. The begun
request does not stop the agent: it goes on in the state *The closed account*
describes, and stops only on the two facts above.

**The write guard.** A claim over a set covers a write only when every set key
the write touches is its own (`TruthSourceKeys::coversRow()`,
`Core/TruthSource/TruthSourceKeys.php`); a row moved under another top writes
into two sets and asks the collection door, which only the holder of the whole
table passes (`DbWriteGuard::guardSetWrite()`,
`Core/TruthSource/DbWriteGuard.php`;
[A Claim Over A Set](truth-source.md#a-claim-over-a-set)). This is why the
merge is the holder's today and why the target needs a narrow transfer right
by operation, not a wider ordinary `Update`. A set claim with no `Add` is a
borrowed claim and waits for the state at start.

**The person's agent's claims.** `OWNS_DB_ROWS` holds its own `users` row with
`Update` alone; `OWNS_DB_SET` holds sixteen child sets, borrowed except the
four the owner brings into being (`secondFactorSettings` with `Add` and
`Update`, `stepUps`, `notificationPreferences` and `userPhotos` whole), the
rename journal with `Add` and `Update`, and the results of its own steps in an
account operation (`accountOperationResults`) with `Add` alone, keyed by the
agent's full id and not by the person's (HIL-1939; see *The participant result
claim*). `renamePerson()` is the real atomic example
the target follows: the name and its journal row in one transaction of one
owner, and what follows the commit is the library's, on the answer.

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
answer correlation remains HIL-1950. No live erasure path uses it yet: its
production participants — the person's agent, the sessions holder, the data
export agent, the legal agent, the users library and the delivery channels —
have no caller of their steps until HIL-1950/HIL-1952 (see *The erasure start*
below), and the due and force-purge paths remain on the old flow until HIL-1952.

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
(HIL-1939): `AbstractUserAgent` declares exactly that claim and those two
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
The common carriers themselves add no participant grant.

**The erasure census.** The plan of an erasure is a census taken from the
circle and the activated tables, and it is written as ordinary assignments.
The readers are in the ORM: `UserMerges::erasureCircleOf()` returns the
`AccountOperationCircle` of a root account (a breadth-first walk over
`foldedInto()` that remembers what it has visited, then reversed: folded
descendants before their survivors, the root last; the sessions library's
`erasureOrderOf()` calls it), `AccountOperations::openErasureCovering()`
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
channel. `AccountErasureParticipantCatalog::framework()` holds `personal` (its
sixteen collections, the `HILOS_USER` owner, one per person), `users_library`
(`verifications`, `HILOS_USERS_LIBRARY`), `sessions_holder` (`sessions` and
`accessLogEntries`, `HILOS_SESSIONS_LIBRARY`), `delivery`
(`notificationDeliveries`), `data_export` (`dataExports`, `HILOS_DATA_EXPORT`)
and `legal_export` (`legalAcceptanceExports`, `HILOS_LEGAL`). `with(...)`
returns a new catalog with more participants; a repeated key or collection, or
a collection that is also in `EXEMPT`, is an `InvalidTopologyException`.
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
`NotPersonal`. Thirteen more are mounted only under a feature. Under
`CHANGE_LOG`, `changeLogTables` and `changeLogFields` are `NotPersonal`, and
`changeLogReceipts`, `changeLogEntries`, `changeLogChanges` and
`changeLogValues` are `Trace`: the journal is not a store of personal data, and
a receipt keeps an erased person's number by design
([change-log.md](change-log.md)). Under `STATUS`, `statusIncidents`,
`statusIncidentUpdates`, `statusWorks`, `statusWorkComponents`,
`statusMaintenancePeriods` and `statusSilencePeriods` are `NotPersonal`, since
no Status table has an account column, and `statusSubscribers` is
`OutsideAccount`: a subscriber is an address, not an account. With every
feature on, the 22 covered and the 33 exempt collections are the 55 mounted
framework collections, and a unit check holds that equality.

`AccountErasureCensus::of($circle, $catalog)` returns the
`AccountOperationExpectation` list, by the accounts of the circle in its order
and, for each, by the participants in catalog order. A participant is activated
when any of its collections is mounted and its table is in the schema; one
whose tables the installation never created is not asked. An expectation is
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
`deferredByOperationId`, `recorded`). When the request already has its
operation, that operation comes back and only what is missing is added. A
request that is no longer live is a `ValidationException` ("The deletion
request is no longer live."). When any account of the circle is already in the
circle of another open erasure, the opening is deferred: nothing is written and
the result carries the covering operation's id. Otherwise the operation and its
whole census are written in that one transaction. `AccountErasureOpening::reconcile($operation, $catalog)`
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

Apart from `erasureCircleOf()`, which the sessions library's `erasureOrderOf()`
already calls, none of this has a live caller yet. The opening is called by the
cutover (not in the code yet — HIL-1952). The reconciliation is called at the
start and in the passes (not in the code yet — HIL-1951), after a `RESTRICT`
refusal (not in the code yet — HIL-1942) and after a stage's failure
(not in the code yet — HIL-1950), and after the start, before the notifications
are deleted, by `AccountErasureCapture::recordAfterStart()`, which is written and
waits for its caller (not in the code yet — HIL-1950).

A project declares the owners of its own collections in one directory: its
facade points `ACCOUNT_DATA_OWNER_DIRECTORY` at a subclass of
`AccountDataOwnerDirectory`, whose `owners()` lists `AccountDataOwner` records
(the key, the collections, the owner's agent type, one per person or one for
everybody, and whether an account still has rows) and whose `exempt()` names
the project collections the erasure leaves alone, each with its
`AccountErasureExemption`. The default directory declares nothing.
`AccountErasureParticipantCatalog::installation()` takes the framework's
participants, then each owner as an `AccountErasureProjectParticipant` in
directory order, with the project's exemptions; a malformed directory, or an
owner or exemption overlapping the framework or each other, is an
`InvalidTopologyException` naming every mistake. A project participant counts
as registered when the application registers its agent type with the indexing
the owner declares and the registered class implements
`AccountErasureProjectOwner`: then the census expects the owner for every
account; otherwise it waits for an account with rows and expects nothing for
one without. `with(...)` still adds a participant that is not an owner from
the directory. The daemon refuses to start over a mounted collection that no
participant covers and no exemption names, and over a collection the directory
names that this installation does not mount (`AccountErasureCoverageGuard`,
right after the set ownership gate); it is silent without `AUTH`. That gate is
the only live caller of `installation()`: the census over a project's owners
is prepared, with no live caller until HIL-1950/HIL-1952. The census adds no
claim, table, migration, signal, route or runtime state.

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
directory is the census's `InvalidTopologyException` as it is.
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
transaction. All of this is prepared, with no live caller until HIL-2000 and
HIL-1999. The coordinator gives a project owner its work only after the loser's
fact, with `user_id` the loser and the owner address of the composition's
`expect:` assignment, and the owner agent calls
`AccountMergeProjectStep::run($this, $assignmentId)` in its own frame; the key
and the stage of that assignment are chosen there
(not in the code yet — HIL-2000). Before it hands out that work the coordinator
calls `AccountMergeOpening::reconcile()`, because during the preparation the
loser may have written new rows to an owner that is not registered
(not in the code yet — HIL-2000). The merge ends only with an empty `waiting()`
and a result for every `expected()` (not in the code yet — HIL-1998), and it is
reconciled at the start and in the passes (not in the code yet — HIL-2002).

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
key and its stage are not this class's, and no production code calls either
method yet (the assignment: not in the code yet — HIL-1950; the live path: not
in the code yet — HIL-1952), so no account is closed by it today.

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
comes first there. A passkey is refused by the agent in the words of
`require()`, but the browser reads the general
`AuthMessages::INVALID_PASSKEY`, because the users library replaces the words of
a failed passkey proof (`AbstractUsersLibraryAgent`). A tab that reconnects
(`resolveSession`) is not checked here; ending the sessions of an account being
erased is the sessions holder's own step, `eraseAccountSessionsPart()` (prepared,
no live caller until HIL-1950/HIL-1952).

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
the agent runs the existing sweep the forget frame runs (`forgetUser()`): the
expired orders, then every file of its directory that no ready order keeps,
then the state to the person's tabs. A failed removal is logged by the agent
and not repeated; an orphaned file is left to the hourly sweep or to the
agent's start, and an agent that has not started has no directory open and
leaves the sweep to that start. A repeated call returns the saved result with
no work and no sweep. The step is prepared, with no live caller until
HIL-1950/HIL-1952; until HIL-1952 the old path stays: the sessions holder
deletes the order in its own transaction and sends the forget frame after the
commit. The price: the export agent's start waits for two more reader
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
the transaction. After the commit, and only when this call did the work, the
agent removes the file of each deleted order and forgets its toast sessions,
drops the file being built so that the build starts again from its first line,
runs the expiry sweep and sends the state to each administrator whose order
went and to the account's own tabs (`restartBuildAndTell()`, which the forget
frame runs as well). A failure after the commit is logged by the agent and not
repeated; a file no row keeps is left to the agent's start and to the hourly
sweep. A repeated call returns the saved result with no work and no tail. The
step is prepared, with no live caller until HIL-1950/HIL-1952; until HIL-1952
the old path stays: the sessions holder deletes the order in its own
transaction and sends the forget frame after the commit, and the legal agent
removes the finished files in `forget()`. The price: the legal agent's start
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
the result. A repeated call returns the saved result and deletes nothing. The
step is prepared, with no live caller until HIL-1950/HIL-1952; until HIL-1952
the old path stays too: the sessions holder deletes the codes in its own
erasure under its borrowed `Remove`. The price: the library's start waits for
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
move the stage while a `capture:personal` assignment has no result
(not in the code yet — HIL-1950).

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
(not in the code yet — HIL-1942).

The readers and their predicates, none of them written yet:

- the coordinator calls `recordAfterStart()` right after the start it won,
  sends the person's agent the frame of its `capture:personal` assignment, which
  calls `captureForErasure()`, and calls `recordAfterStart()` again on the
  deleting owner's refusal "deliveries not captured"; the key and stage of the
  work assignments are its own (not in the code yet — HIL-1950);
- the person's agent deletes the ways in only when
  `AccountErasurePersonalCaptureResult::of()` is not null, and a notification
  only when `uncapturedNotificationIdsOf()` does not name it in the same
  transaction, for which its process reads `notificationDeliveries`
  (not in the code yet — HIL-1941);
- the users library takes the addresses from
  `AccountErasurePersonalCaptureResult::of()->addresses`, refuses while that is
  null, and writes only numbers, never an address, into its own result
  (prepared, no live caller until HIL-1950/HIL-1952);
- each channel or shard reads every capture portion for its channel and account,
  gets the notification ids from `notificationIdsOf()`, and deletes only rows
  it owns under the current route. It refuses if any of its own rows for that
  account remains uncaptured (prepared, no live caller until
  HIL-1950/HIL-1952);
- `ChatAgent` takes the event ids from
  `ChatErasureRenameFeed::eventIdsOf(AccountErasurePersonalCaptureResult::of(…))`
  for every account of the circle, and refuses while one is null
  (not in the code yet — HIL-1949).

The price: the names in the room's "renamed" lines disappear at the capture,
because a line reads them from the journal row linked to it; the lines
themselves go at the chat's step, and while the erasure waits they stand without
names. A link frame after the capture is served as before; an event it links
that the capture did not keep stays a line without names, which is accepted, and
a repeated link onto a captured event is harmless (the argument of HIL-1897).
Everything above is prepared and has no live caller until the coordinator
drives the capture (HIL-1950), live at the switch (HIL-1952).

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
(`rePointAuthor()`) and in the erasure deletes attachments, messages,
registration events and feed events, reading the rename journal's event ids
(`UserRenames::eventIdsByUser()`, `Database/View/Collection/UserRenames.php`)
before the framework deletes the journal: `UserRename.event_id`
(`Database/Entity/Item/UserRename.php`) is a unique key onto the room's event,
and after the journal row is gone nothing knows which event told of the
person.

**Neighbouring rules.** `afterCommit()` releases what waited for the commit
and is not a durable queue: nothing of it survives a restart
([transactions.md](../orm/transactions.md#what-waits-for-the-commit-and-what-does-not)).
[work-in-portions.md](../signals/work-in-portions.md) names `renamePerson()`
as the example of related writes in one transaction, `eraseAccount()` as the
erasure's present implementation, and says a step after the commit is not
retried; the target protocol keeps both rules inside one owner and adds the
boundary between owners.

**What the target protocol replaces.** Said in one place so that a leaf does
not keep one of them by habit:

1. **One transaction across other owners' tables** — the holder's seventeen
   borrowed claims and the chat holder's four. Each owner writes its own rows in
   its own transaction; between owners there is a recorded fact and a signal.
2. **Locking and re-reading other owners' sets** — the five `lockForMerge()`
   and the `reHydrateCollection()` of the merge. The owners of both accounts
   are prepared and confirm their state; nobody takes a copy of another's rows
   from the database.
3. **Stopping before the operation is finished** — the immediate `selfStop()`
   on the deleted person row and on the merge row. The same agent finishes its
   part and then stops; after a failure it rises only to finish.
4. **Proving the end by foreign keys alone** — `RESTRICT` holds the person row
   only against hard keys. The end is proved by the recorded result of every
   required participant, the soft references and the service copies included,
   and the final `DELETE` of the person row is the last check, not the proof.

These borrowed declarations stay until the live terminal switches: erasure
at HIL-1952 (not in the code yet — HIL-1952), merge at HIL-1999
(not in the code yet — HIL-1999). Preparation alone removes none of them.

## Operation And Participant Records

The protocol rests on records, not on messages: a message is how a participant
learns that there is work, and a record is what makes the work owed. The
operation, assignment and participant-result records are backed by shared
framework ORM carriers; those storage APIs do not connect future participant
work to a live path. The detailed [erasure](#erasure) and [merge](#merge)
norms are recorded here. Other physical contracts remain with their
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
edit it. The step remains inert until a business participant is connected to
it. Each preparation remains its participant's own record.
For merge, each personal owner records its own reservation as preparation
(not in the code yet — HIL-1988) and its release result when called off before
the fact (not in the code yet — HIL-1990). The loser commits its business work
with its result and hand-over copy (not in the code yet — HIL-1992); the
survivor commits its own work with its receipt
(not in the code yet — HIL-1994). These remain the participants' records,
never results rewritten by the coordinator.

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
After start, only forward completion remains; no SQL transaction spans frames
(not in the code yet — HIL-1950).
For merge, prepare, go, rows, receipt, cancel, cleanup and end are logical
stages correlated by operation and stage over the HIL-1950 transport. A lost,
duplicate, old or foreign answer cannot advance another operation or repeat
its business work. No transaction spans frames, and the coordinator builds
no second admission fence (not in the code yet — HIL-2000).

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
The same obligation applies immediately to the loser's hand-over copy,
including its secrets and addresses
(not in the code yet — HIL-1992). Neither classification nor backup coverage
is deferred to the live switch or to the later recovery evidence.

## Erasure

The order below is one operation over a circle of accounts. "The agent" is the
agent of the account whose step is described; a circle has one agent per
account, and they share no transaction. Each marker names the leaf preparing
that capability. The prepared protocol becomes the live due and force-purge
path only at the [single cutover](#erasure-cutover-and-observation)
(not in the code yet — HIL-1952).

1. **The request falls due; preparation is not start.** The users library
   creates the request and the agent may cancel it (HIL-1409). The holder's
   minute sweep exists today. For the split path, the holder fixes the circle
   from the merge relationships before breaking any link, with folded leaves
   before their survivors, and derives the required owners from the
   installation's activated tables (prepared, no live caller until HIL-1950/HIL-1952;
   see *The erasure census*). The
   implemented operation and assignment `record()` actions can persist that
   plan at stable owner addresses in the logical *preparing* stage; an exact
   repeat returns the same records and a conflict is refused. The stored stage
   means only that the plan exists: recording it does not start erasure or
   change the person's account. The due and force-purge production paths still
   use the old flow until the live switch (not in the code yet — HIL-1952).
2. **The start is won or lost at the request's owner.** The holder hands the
   start to the personal agent. The agent conditionally records start against
   cancel: a cancel that won leaves the account intact and starts no
   participant's erasure. A later cancel is refused.
   A folded account with a live request has its owner start that request as
   the first step of its own part; no request is created for an account that
   has none. The start result commits with that participant's work, in the
   step's transaction. When start loses to cancel, the coordinator closes its
   operation as *not started* (not in the code yet — HIL-1950). Start is
   distinct from completion. HIL-1936 supplies an inert operation stage/outcome
   carrier; it does not record this request-owner start. HIL-1939 built the
   start/cancel carrier and uses the participant result carrier and step
   described above (*The erasure start*), with no live caller until
   HIL-1950/HIL-1952. Today's
   `completed_at` retains its meaning of a successful common erasure commit
   until HIL-1952.
3. **Access closes; the same agent finishes.** The started fact closes ordinary
   sign-in, actions and late ordinary frames (*The closed account*; the fact is
   written by no live path yet). The personal agent, including that of a folded
   account, accepts only the internal continuation of its own operation and
   stops after its own part (the stop after its last own write: not in the code
   yet — HIL-1942). This finishing mode does not turn
   `AddressablePerson::require()` into a general bypass for a missing or folded
   account. The sessions holder's own
   participant step, `AbstractSessionsLibraryAgent::eraseAccountSessionsPart()`,
   releases all four `RESTRICT` session references, signs out
   the affected sessions, clears second-factor waits and lowers closed-access
   cards, and records `{"sessions":<n>,"accessLog":<m>}` in the same
   transaction; the tabs are told only after the commit. Session rows stay for
   the ordinary sweep; the holder remains their writer
   ([Session Ownership](entity-libraries.md#session-ownership))
   (prepared, no live caller until HIL-1950/HIL-1952). The visible progress and refusals have
   their separate owners under [observation](#erasure-cutover-and-observation).
4. **The participants are derived, not assumed.** Required participants are
   derived from activated tables before destructive work. A table *never activated*
   in the installation owes no participant because no rows can exist. An
   activated table with its owner contributes that owner. Existing rows with
   no registered owner leave visible, open work: the operation waits, cannot
   count the part done and cannot appoint a fallback writer (prepared, no live
   caller until HIL-1950/HIL-1952). Projects declare their own participants
   under the same distinction; there is no fixed "twenty tables, always"
   list. A project names the owners of its tables, and the tables it leaves
   out with a reason, in its `AccountDataOwnerDirectory`, and the daemon
   refuses to start over a mounted table that no participant covers and no
   exemption names (`AccountErasureCoverageGuard`); the census over those
   owners is prepared, with no live caller until HIL-1950/HIL-1952.
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
   All of it is prepared, with no live caller until HIL-1950/HIL-1952.
6. **Each owner deletes its own children and confirms.** The generic participant
   step that commits each part's business work and result in one local
   transaction is built; these business participants remain to be connected:

   - The personal owner deletes device keys before identities, after the
     addresses are captured; its second factors, backup codes, resets,
     settings and trusts; step-ups and legal acceptances; its merge row if
     folded; notifications after delivery obligations are saved; preferences,
     push subscriptions and its photo row. It follows FK order and keeps the
     photo file id for removal after commit, through the existing files path
     (not in the code yet — HIL-1941).
   - The users library deletes verifications by user id and by the
     addresses of the personal owner's capture, including codes with no user
     id or another user id on those addresses (prepared, no live caller until
     HIL-1950/HIL-1952).
   - Each delivery channel/shard deletes its own deliveries by its capture
     assignments and records `{"deliveries":<n>}`. The step is prepared, with
     no live caller until HIL-1950/HIL-1952. Its started-recipient guard is
     built: it does not begin an attempt for a recipient whose erasure has
     started, and an administrator retry is refused in the channel's own words.
     No live path produces the started fact until HIL-1950/HIL-1952. Ordinary
     channel rights, disabled-owner duties, retention and retry retain their
     separate HIL-1893/HIL-1894/HIL-1902/HIL-1910 boundaries in
     [Ordinary Transitions](#ordinary-transitions).
   - The data export owner deletes its own DB order of the person with its
     result, keeping the existing publication checks and post-commit file
     path: `AbstractDataExportAgent::runErasurePart()` records
     `{"orders":<n>}` in the same transaction and sweeps the files after the
     commit (*The data export part*; prepared, no live caller until
     HIL-1950/HIL-1952).
   - The legal export owner deletes the person's DB orders with its result.
     Shared exports retain their existing fate: ready and failed exports
     leave with their files, and an export being built starts again. Disk
     cleanup remains outside the DB guarantee:
     `LegalAcceptancesExports::eraseAccountPart()` deletes the account's order
     and every ready and failed export and records
     `{"ownOrders":<0|1>,"sharedExports":<n>}` in the same transaction, and the
     files, the restart of the build and the sweep follow the commit (*The
     legal export part*; prepared, no live caller until HIL-1950/HIL-1952).
     The part waits for the account's acceptances to go, so it is assigned
     after the personal owner's result (HIL-1941; the order of the stages is
     HIL-1950's), and an administrator whose erasure has begun orders no new
     export.
   - The sessions holder deletes access log entries as its own confirmed
     part, in the same step and transaction as the sessions, and remains the
     owner of its working rows: `eraseAccountSessionsPart()` records
     `{"sessions":<n>,"accessLog":<m>}` (prepared, no live caller until
     HIL-1950/HIL-1952).
   - Each declared project owner deletes its own rows with its result: the
     class registered under the owner's type implements
     `AccountErasureProjectOwner`, and calls `AccountErasureProjectStep::run()`
     in its own frame with its assignment, which asks it to erase its part of
     that account inside the step's transaction and records its answer as the
     result in the same transaction. The payload is the rows it deleted per
     family it names and the registry files they pointed at
     (`{"rows":{…},"files":[…]}`); the files leave after the commit. This is
     prepared, with no live caller until HIL-1950/HIL-1952. In chat,
     `ChatAgent` deletes the person's messages across every room, attachment
     links, registration details and feed events in one transaction with its
     result. It takes the event ids from the capture of the personal owner,
     which took the journal off them in the same transaction; the users
     library stays the creator of registration/feed events. Attachment file
     removal remains after commit, and other people's rows mentioning the
     erased person stay under their existing `SET NULL` rule
     (not in the code yet — HIL-1949).
7. **The person row goes last, under `RESTRICT`.** After all required results
   for that account — holder, library, channels, export and project owners —
   the personal owner deletes `userRenames`, its own capture — the one record
   holding the addresses (the owner's decision of 2026-11-08) — and `users`,
   and completes its existing request in one final local transaction. `RESTRICT` is the last
   safeguard, not the proof of completion: refusal rolls back only that final
   transaction and leaves the operation open with a safe reason. A committed
   `completed_at` means the actual end, never that erasure was queued. The
   request remains the trace it is today; an account without a request gains
   none (not in the code yet — HIL-1942).
8. **The circle finishes leaves first.** The fixed circle puts every folded
   account before its survivor (prepared, no live caller until
   HIL-1950/HIL-1952). Each folded
   account's owner removes its own `userMerges` row while the survivor still
   exists, so erasure does not rely on the survivor key's `SET NULL`
   (not in the code yet — HIL-1941). Stage assignments and answers carry the
   same operation and stage throughout; duplicate, old or foreign answers
   advance nothing. Once started, the circle is completed forward without a
   transaction across frames (not in the code yet — HIL-1950).
9. **The end follows the last result.** Each personal agent makes its last
   own write before stopping; the coordinator records the end of the circle
   only after the last required result (not in the code yet — HIL-1942).
   Coordinator start and subsequent passes over open work, and participant
   start or an operation frame, select the unfinished obligations from DB and
   continue them; a lost frame needs no new external event. There is no saved
   memory cursor or DB poll on every tick (not in the code yet — HIL-1951).
   Existing post-commit files and forget frames retain their present limits:
   failures are logged, with no automatic retry or new external guarantee
   ([Related Records](../signals/work-in-portions.md#related-records)).

### Erasure Cutover And Observation

**One live switch.** After HIL-1912 and readiness of the erasure guards,
participants, CLI and all three frontends, both due and force-purge switch
together. That commit removes the old erasure calls, grants, comments and
executable debt, and keeps rights required by the old merge
(not in the code yet — HIL-1952). Until then preparation is inert: a new
handler does not authorize finishing an old request by the new path. There is
no new toggle or exception. If a preparation leaf cannot remain inert under
HIL-1892's contract, it needs an explicit dependency after HIL-1912 and renewed
approval of the implementation map; this document grants no such exception.

The following leaves prepare the surrounding behavior; their presence alone
switches neither live erasure entry:

- The existing `test:account:force-purge` triggers the live owners and prints
  the actual outcome, distinguishing start, waiting and completion. The CLI
  writes no DB row and introduces no universal clock; the exact new response
  belongs to its implementing contract (not in the code yet — HIL-1953).
- The first `page_response` and live updates carry the started fact, progress,
  expected owners and safe refusal. Existing admin view mode exposes no
  personal data it withholds today and enables no mutation; exact wire fields
  belong to that leaf (not in the code yet — HIL-1954).
- The people list and card read durable erasure facts; ordinary rows keep
  Apply, and the people set gains no synthetic operation row
  (not in the code yet — HIL-1955).
- Vue's list shows progress (not in the code yet — HIL-1956). Vue's card shows
  waiting and refusal (not in the code yet — HIL-1957). These display leaves
  do not implement actions.
- The person's cancellation keeps the grace and cancel/start race before
  start and honestly refuses afterward (not in the code yet — HIL-1958).
  Administrator cancellation has the same boundary
  (not in the code yet — HIL-1959).
- The existing merge refuses when any affected account is in the circle of an
  open erasure operation, from preparation on (owner's decision 2026-11-12,
  HIL-1938); this prepares no new merge, transfer right or receipt protocol
  (not in the code yet — HIL-1960).
- React carries the corresponding states and actions
  (not in the code yet — HIL-1961). Angular carries them too
  (not in the code yet — HIL-1962). Their interviews choose the appearance of
  missing states; this document declares no design.

**Evidence belongs with the behavior.** Each business leaf carries its own
unit/integration checks and clears only the markers its behavior makes true.
Before cutover, HIL-1958/HIL-1959/HIL-1961/HIL-1962 use their own owner
integration with a controlled DB fact, component states and the existing early
cancel e2e, as applicable; they need neither production start nor new clocks or
real-time waits. HIL-1952 enables and checks the complete live path. Alignment
of the applicable already-written behavioral e2e after cutover is separate
(not in the code yet — HIL-1963). Cluster and backup evidence covers recovery,
duplicate/lost/old frames, an owner's sleep, death and move, missing owners and
`RESTRICT` refusal (not in the code yet — HIL-1964). Neither evidence leaf
inherits another leaf's business tests or marker removal.

## Merge

"The loser" is the account folded in and its agent, "the survivor" the account
that absorbs it and its agent. The password fate and the second-factor fate
keep their present meanings and refusals
([second-factor.md](second-factor.md#account-merge)); what changes is where
each check runs and who writes. The eight steps retain the research order;
each marker names the leaf preparing that capability. The prepared protocol
becomes the live administrator and `account:merge` path only at the
[single cutover](#merge-cutover-and-observation)
(not in the code yet — HIL-1999).

1. **The request.** The administrator's window or `account:merge` names the
   two accounts, the fates and, from the window, the two protection flags it
   showed. The holder judges what it judges today without raising anybody —
   the two ids differ, both exist, neither is folded, and a password fate is
   named when both accounts have a password. The split path preserves those
   refusals. Refusing an account occupied by an open operation remains future
   work (not in the code yet — HIL-2000). The `AccountMergeOpening::open()`
   scaffold records the immutable pair, fates and expected flags, stage
   *preparing*, both initial assignments at stable addresses and the project
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
   Competing merge and erasure cannot share the account. Preparation captures
   enough identity of its previous password and factor, or their absence, to
   apply the chosen fate later. It changes no business row and demotes no
   password before the fact. The owner checks its own copy; this replaces
   `lockForMerge()` and `reHydrateCollection()` without locks or rereads
   (not in the code yet — HIL-1988).
   While reserved, changes of password, passkeys, factor, backup codes,
   reset and wait are refused, including late service frames and affected
   policy entrances for trust, term and switch-off. Ordinary content that
   touches none of these stays available; the reservation is no general
   account block (not in the code yet — HIL-1989). This supplies reservation
   behavior for the ordinary HIL-1903/HIL-1904/HIL-1907/HIL-1908/HIL-1909
   paths, not a replacement for those ownership moves.
3. **Cancellation, before the fact only.** Until the loser's fact is recorded
   the operation may be called off — by the administrator, by the refusal of
   either preparation — and each agent releases its own reservation with a
   result that says so; only then does the coordinator close the operation as
   *not started*. The coordinator never releases another owner's reservation.
   After the fact there is only forward completion: no timer rollback, late
   cancellation or refusal undoes the transfer
   (not in the code yet — HIL-1990).
4. **The fact: the loser gives its rows away.** With both preparations
   recorded, the coordinator hands the loser's agent the go. In ONE
   transaction of its own under the [transfer right](#the-transfer-right),
   the loser applies the password fate to **its own previous password only**:
   demote it or delete it under today's unverified/address-collision rules
   when the fate does not keep it. It never edits the survivor's previous
   password. The old `settlePasswords()` helper changes both sides and is
   therefore not moved whole to the loser. The loser re-points its identities
   and passkeys with their anchors and existing ids; a way in already held by
   the survivor means deleting the loser's colliding row, as today. It moves
   its confirmed factor and backup codes with spent status preserved when
   the fate transfers its protection, and deletes them otherwise. It deletes
   unfinished enrollment, resets, settings and its own trusts. The same
   transaction writes the tombstone — merge row first, its own block second,
   as in `foldAccount()` — and its result with the full *hand-over copy* of
   actual rows, anchors and effective wait. Work and result commit or roll
   back together. The copy carries rows, not just ids that would require
   reading the survivor's set. From that commit the loser is folded:
   `AddressablePerson::require()` refuses it and the candidates table hears
   the fact through the person row (not in the code yet — HIL-1992).
5. **Admission into memory and receipt are separate results.** The loser's
   agent sends the survivor the rows from its own hand-over copy, never by
   reading the survivor's set (not in the code yet — HIL-1992).
   Admission puts those rows into ORM memory without a second business
   `UPDATE` or a reread. Its fence prevents stale `DB_SYNC` and old hand-over
   frames from rolling back data, ownership, deletion or derived effects,
   before and after receipt, copy cleanup and a later merge. Admission
   metadata is recoverable from durable facts, not static or class-local
   memory. The fence outlives the secret-bearing copy without keeping those
   secrets forever. This adds neither blocking DB/I/O to the master nor
   general versioning to every table; admission writes no receipt
   (not in the code yet — HIL-1993).
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
   nothing; a closed or foreign operation is refused. A sleeping survivor
   reads its own set at start and
   confirms from its obligation, not from a frame it never saw
   (not in the code yet — HIL-1994).
6. **The other owners move their own.** The holder transfers its own access
   log with an atomic result and ends the loser's sessions after the fact;
   a repeated ask does not transfer the journal twice
   (not in the code yet — HIL-1995). Projects declare merge participants
   through the HIL-1948 directory: an owner registered for the merge moves its
   own rows of the loser in `AccountMergeProjectStep`, and a missing required
   owner stays an open wait of the composition with no foreign fallback writer
   — nobody moves its rows for it. The seam is built, with no live caller until
   HIL-2000 and HIL-1999, and the merge does not end over an open wait
   (not in the code yet — HIL-1998). In chat, `ChatAgent` re-points authorship
   of the loser's messages across every room in one transaction with its
   result; other message and attachment fates stay as they are, and merge
   neither becomes erasure nor writes the rename journal
   (not in the code yet — HIL-1997). Other loser content retains its present
   fate under the project participant contract: an owner moves only what the
   project's merge moves today, and every other row stays with the loser
   (`AccountMergeProjectOwner::mergeAccountPart()`, built, with no live caller
   until HIL-2000 and HIL-1999). Today's `applyAccountMerge()` remains the
   only live project hook until cutover (not in the code yet — HIL-1999).
7. **The restriction lifts on the receipt.** The survivor's reservation ends
   only when the chosen protection is applied and its receipt recorded
   (not in the code yet — HIL-1994). Until both hold, sign-in through a
   transferred way in is refused, as is password sign-in on the survivor
   during the temporary two-`PASSWORD` state; changes of ways in and
   protection remain closed (not in the code yet — HIL-1989). When the loser's
   factor first protects the survivor, the holder ends the survivor's sessions
   after receipt, preserving today's session consequences without reworking
   HIL-1876 (not in the code yet — HIL-1995).
8. **Cleanup precedes stop and end.** After receipt, the loser alone deletes
   its hand-over copy, records cleanup and only then stops. The coordinator
   records end only after receipt, every required participant result and the
   absence of the copy. Failure between copy deletion, answer and end is
   finished forward. The folded loser accepts only continuation of its own
   operation — copy replay and cleanup — with no ordinary access reopened;
   `AddressablePerson::require()` gains no general bypass
   (not in the code yet — HIL-1998). The copy's password hashes, factor
   secrets, addresses and backup codes receive their PII verdict and backup
   coverage when the carrier is introduced (not in the code yet — HIL-1992).
   Cleanup leaves no permanent service copy of those secrets
   (not in the code yet — HIL-1998). Coordinator start and subsequent passes,
   participant start and operation frames find unfinished obligations in DB:
   repeat copy/result, sleeping-survivor confirmation, cleanup and end. There
   is no DB poll on every tick or remembered cursor
   (not in the code yet — HIL-2002). Today's immediate `selfStop()` on the
   merge row remains until the live switch (not in the code yet — HIL-1999).

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
the width of any claim (not in the code yet — HIL-1991). The door is built
over HIL-1892's strict guard; ordinary `Update` still cannot write a foreign set.

The exact interface of that door, and of the metadata the record hands it, is
a code leaf's, HIL-1991, at its own interview. This page has no standing to
declare it built: the door does not exist in the tree today.

### Merge Cutover And Observation

**One live switch.** After HIL-1964 and readiness of HIL-1987–HIL-1998 and
HIL-2000–HIL-2011, the normal administrator merge and `account:merge` switch
together. That commit removes the old calls, `lockForMerge()` and
`reHydrateCollection()`, borrowed merge grants and comments, merge entries in
HIL-1892's executable debt catalog, and the immediate `selfStop()` on a merge
row. It checks the resulting writer map; it does not design business or
recovery logic (not in the code yet — HIL-1999). Until then all preparation
is inert: a prepared handler neither activates a production path nor removes
a right still used by `mergeAccounts()`. No new toggle or exception is granted.
The old merge's started-erasure guard remains the separate HIL-1960 obligation
under [Erasure Cutover And Observation](#erasure-cutover-and-observation);
it does not build this protocol.

The following leaves prepare observation and actions; none switches the live
path on its own:

- `account:merge` uses the daemon channel and distinguishes preparing, wait,
  refusal and end with the selected fates. Waiting does not block the event
  loop. A disconnected channel means neither cancellation nor success, and
  success is printed only at durable end. Its exact response belongs to its
  own contract (not in the code yet — HIL-2001).
- The first `page_response` and live updates carry state, waiting and safe
  refusal. Admin view mode reveals no withheld personal data and opens no
  mutation (not in the code yet — HIL-2003).
- The list, card and candidates read the durable reservation/operation facts
  without presenting an occupied or folded account as available
  (not in the code yet — HIL-2004).
- Vue's list shows the states (not in the code yet — HIL-2005), its card
  shows them (not in the code yet — HIL-2006), and its merge window shows
  them (not in the code yet — HIL-2007). These display leaves implement no
  action.
- Starting from the card preserves the chosen fates, shown flags, the
  `merge_accounts` step-up and existing refusal words. Acceptance is not end;
  tracked success means durable end (not in the code yet — HIL-2008).
- Cancellation from the window is allowed only before the loser's fact;
  closing the window is not cancellation (not in the code yet — HIL-2009).
- React carries the corresponding states and actions
  (not in the code yet — HIL-2010). Angular carries them too
  (not in the code yet — HIL-2011). Their interviews and those of the Vue
  display leaves choose the appearance of missing states; this canon declares
  no design.

**Evidence belongs with the behavior.** Preparation leaves use real owner,
DB and applicator integration and component states while the production path
remains off, without new clocks or real-time waits. Each carries its own
business checks and clears only the markers it makes true; HIL-1999 checks
the live path at cutover. Applicable behavioral e2e is aligned after the
switch (not in the code yet — HIL-2012). Cluster and backup evidence covers
death after commit, lost receipt, duplicate/old frames, sleeping or moved
owners and the absence of a second effect
(not in the code yet — HIL-2013). Neither evidence leaf inherits another
leaf's business tests or marker removal.

## Writer Map

The 20 framework collections and the 4 chat collections the sessions holder
writes today, and beside them `secondFactorTrustRestrictions`, which the
settings library alone creates and the holder only reads, each with the
current expanded operations and calls, the
creator, the target writer after creation, the work handed over, its cost,
and the ordinary transition or retained terminal debt. "Target"
is the agreed design, not a right anybody holds today. Collection keys are the
`HilosDbContext` and `ChatDbContext` names; "holder" is the sessions holder
(`AbstractSessionsLibraryAgent`, and in the chat its subclass), "agent" is the
person's agent (`AbstractUserAgent`), "users library" is
`AbstractUsersLibraryAgent`. A personal target means the indexed owner of
that person's rows, not a full-table writer beside the set owners. A writer in
the second column that the target column does not name loses that write; the operation-level records the
coordinator and the participants keep are outside these rows, below.

| Collection | Writes today, by the code | Creator today | Target: single owner of changes after creation | Handed to that owner | Dependencies and price | Transition or retained terminal debt |
|---|---|---|---|---|---|---|
| `identities` | users library `Add` (registration, a password set right after the insert); OAuth agent `Add` (a linked provider account); agent `Update`/`Remove` over its set (password change, reset and rehash, address verify, email change, unlink); holder `Update`/`Remove` in the merge (`rePointToUser()`, including password fate) and `Remove` in the erasure (`deleteForUser()`); `UserTestSeedCommand`, test-only | users library; OAuth agent for provider links | the agent: every edit of a way in and of recovery, the transfer of its own rows to the survivor under the transfer right, the deletion in the erasure | the merge's `rePointToUser()`, the erasure's `deleteForUser()`; the holder loses `Update` and `Remove` over another's set | device keys restrict the delete of their anchor, so they go first; the current addresses are captured for the codes before the ways in go. Price: the transfer right by operation (the guard refuses a set claim moving a row today); one local transaction at the loser with its recorded result; a transferred way in signs nobody in until the survivor's protection is applied | No ordinary move remains. Current addresses in the personal owner's capture: prepared, no live caller until HIL-1950/HIL-1952. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Previous password identity captured (not in the code yet — HIL-1988). Reservation restrictions (not in the code yet — HIL-1989). Loser transfers its rows, settles only its own password and deletes its collisions (not in the code yet — HIL-1992). Memory admission and stale-frame fence (not in the code yet — HIL-1993). Survivor settles its own previous password with receipt (not in the code yet — HIL-1994). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `passkeyCredentials` | users library `Add`; agent `Update`/`Remove` (the counter on use, the unlink with its identity); holder `Update` in the merge (`rePointToUser()`) and `Remove` in the erasure | users library | the agent, use, transfer and deletion included; the row hangs on its identity anchor (`identity_id`, with `user_id` as the short path moved with the anchor, HIL-1132) | the merge's `rePointToUser()`, the erasure's `deleteForUser()` | moves in the same local transaction as its identity, the short path rewritten with the anchor; deleted before the identities in the erasure. Price: none beyond the identities' portion | No ordinary move remains. Keys before identities in the personal erasure part (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Transfer with existing ids and identity anchors (not in the code yet — HIL-1992). Memory admission and stale-frame fence (not in the code yet — HIL-1993). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactors` | users library `Add` (`startEnrolment()` only creates); agent `Update`/`Remove` (confirm, step, remove, a reset fallen due, `dropUnfinished()` before a new enrolment); holder `Update`/`Remove` in the merge (`rePointConfirmedToUser()`, the unconfirmed one deleted, or `deleteForUser()` by the chosen fate) and `Remove` in the erasure | users library | the agent: every later edit and deletion; the removal of an unfinished enrolment goes to it too, so that the library keeps creation alone and no second `Remove` | removal of the previous unfinished enrollment before replacement creation; the merge's re-point or delete by the chosen fate; the erasure's `deleteForUser()` | the chosen protection is applied at the survivor before a transferred way in may sign in (question 2); the pending-removal refusals of today become refusals at preparation. Price: the five locks and re-reads become a prepare-and-confirm exchange between two agents, each with a local transaction and a recorded result | Old enrollment removal by the personal owner before creation at the library implemented (HIL-1903). Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Previous factor identity and protection checks at preparation (not in the code yet — HIL-1988). Reservation restrictions (not in the code yet — HIL-1989). Loser transfers or deletes by fate with its result (not in the code yet — HIL-1992). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactorBackupCodes` | users library `Add` (`issueSet()` creates a set the agent emptied first); agent `Update`/`Remove` (a code spent by conditional write; the whole set dropped by `deleteForUser()` before a new one is issued); holder `Update`/`Remove` in the merge (`rePointToUser()` or `deleteForUser()`) and `Remove` in the erasure | users library | the agent; spending, transfer and deletion keep the chosen second-factor policy | removal of the previous backup-code set by the agent before replacement creation; the merge's re-point or delete; the erasure's `deleteForUser()` | travels with the factor in the same portion. Price: inside the second-factor portion | Old code-set removal by the personal owner before creation at the library implemented (HIL-1904). Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Loser transfers with spent status or deletes by fate with its result (not in the code yet — HIL-1992). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactorResets` | users library `Add` (`request()`); agent `Update`/`Remove` (cancel, complete, due and reminder on the sweeper's frame); holder `Remove` in the merge and in the erasure | users library | the agent; `SecondFactorResetSweeper` (`Auth/SecondFactor/SecondFactorResetSweeper.php`) only finds and hands over and writes no row of it | the merge's and the erasure's `deleteForUser()` | the merge's "pending removal" checks become preparation refusals at the two agents. Price: none new | No ordinary move remains. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Pending-removal refusals at preparation (not in the code yet — HIL-1988). Reservation restrictions, including late service frames (not in the code yet — HIL-1989). Loser deletes its resets with its result (not in the code yet — HIL-1992). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactorSettings` | agent `Add`/`Update` (its own one-to-one row); holder `Add`/`Update`/`Remove` (the merge's `setResetWait()` on the survivor, `deleteForUser()` on the loser; the erasure's `deleteForUser()`) | the agent on first edit; the holder if the merge upserts missing survivor settings | the agent, its own row included; when the loser's protection transfers, the merged wait — the longer of the two — is written by the survivor's agent from the fact it receives, never by the holder from the loser's set | the merge's `setResetWait()` and `deleteForUser()`, the erasure's `deleteForUser()` | the loser's wait is part of the transferred result. Price: one more value in the frame; the holder's whole claim goes | No ordinary move remains. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Loser deletes its settings and carries effective wait in its copy (not in the code yet — HIL-1992). Survivor writes the merged wait when protection transfers, with receipt (not in the code yet — HIL-1994). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactorTrusts` | holder `Add`/`Remove` (`trust()` only creates a pair on the way in and leaves a standing one as it is; the merge's `deleteForUser()` on the loser and also the survivor when the loser had a confirmed factor; the erasure's `deleteForUser()`); agent `Update`/`Remove` (`deleteForUser()`, `deleteForUserExceptSession()`, `deleteForPair()`, `renew()` of a standing pair on the holder's frame, and `applyRestrictions()` for the saved restrictions, on the sweep's frame, with a renewal and at the agent's own start) | the holder, on the way in — this stays | the agent for every later write; the creation on the way in gives the holder no right to extend or delete an existing row. The installation-wide `capLiveUntil()` and `deleteAll()` for the term were handed over to each personal owner and removed, not kept as a hidden second writer | renewal of an existing session/person pair; the saved term and switch-off as a hand-over per person, including missed policy work; the merge's and the erasure's `deleteForUser()` | the holder keeps `Add` beside the agent's set claim — a declared shape, not a collision. Price: the highest of the map — the administrator's term reaches every person with a trust row through its agent (a sweep that finds them and hands each a frame; an agent that is asleep applies it at start), where it used to be one statement | Saved policy implemented (HIL-1900), with the person's agent able to apply it; missed-work recovery implemented (HIL-1901); renewal by the personal owner implemented (HIL-1907); cap/zero term implemented (HIL-1908); switch-off removal implemented (HIL-1909). Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Reservation covers affected trust, term and switch-off policy entrances (not in the code yet — HIL-1989). Loser deletes its own trusts (not in the code yet — HIL-1992). Survivor deletes its own trusts under the existing policy with receipt (not in the code yet — HIL-1994). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `secondFactorTrustRestrictions` | settings library `Add` (`record()`, in the transaction of the save that gave the row birth); no one holds `Update` or `Remove`; the holder and the person's agent only read it (`READS_DB`) | the settings library | no later write: a row is not changed after it is written, and it holds no personal data | nothing | the settings library creates it beside its own write, so a setting is not saved without the restriction it owes; the gate and every application read it from the table, not from memory. Price: one row per shortening or switch-off | Implemented (HIL-1900). The application it feeds is the trust row's: missed-work recovery implemented (HIL-1901), cap/zero term implemented (HIL-1908), switch-off removal implemented (HIL-1909). |
| `stepUps` | agent `Add`/`Update`/`Remove` (`record()`, `deleteExpiredForUser()`); holder `Remove` in the erasure | the agent | the agent, its own confirmation, its extension, its spending and clearing, and the erasure included | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion | No ordinary move remains. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `legalAcceptances` | users library `Add` (`accept()`); personal owner's agent `Remove` for test-only `forgetLaterThan()` called by `test:legal:hold`; sessions holder `Remove` in the erasure | users library | the library creates immutable acceptances; the personal owner removes later acceptances for a test hold and handles their deletion in erasure as its target. Erasure remains at the holder until the live switch; this does not make recorded acceptances mutable or permit deleting another person's consent | the test command's forget as a frame to the personal owner; the erasure's `deleteForUser()` | the legal agent reads these rows for its exports, and the erasure's forget frame to it stays. Price: one more hop in a test path | Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `notifications` | notifications library `Add`; agent `Update`/`Remove` (`markAllReadForUser()`, one statement over its set); holder `Remove` in the erasure; `NotificationTestSeedCommand`, test-only | notifications library | the library creates, the agent edits and deletes | the erasure's `deleteForUser()` | the deliveries point softly at the notification and are cut by it: before the agent deletes the notifications, the obligation of each channel over its soft delivery links is recorded, so no recipient is lost once the notification is gone. Price: one recorded obligation per channel ahead of this step, and one more ordering constraint in the erasure | No ordinary move remains. Delivery portions captured after the start: prepared, no live caller until HIL-1950/HIL-1952. Personal erasure after those obligations (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `notificationDeliveries` | notifications library `Add`: creation only; channel agent `Update`/`Remove` (status, attempts and error of its attempt; `resetForRetry()` for an admin retry; `adoptPendingRows()` after start or freeze; retention of its terminal rows; the erasure part of its own rows (`runErasurePart()`)); holder `Remove` in the erasure (`deleteForRecipient()`); `RestoredDeliveries::settlePending()`, a raw `UPDATE` at restore with no agent; `NotificationTestSeedCommand`, test-only | notifications library | the library creates; the channel or shard changes its own rows, resets a failed row an admin retries and removes its terminal rows through retention. A switched-off channel keeps its journal and retention, and its agent stays available for retention and erasure; no shared sweeper is introduced (question 4) | retry, prune, the erasure's `deleteForRecipient()` | the `NotificationChannelShard` row guard constrains channel `Update` and `Remove` by fresh saved delivery identity and current route. The pipeline captures delivery ids, recovers bounded pages and reduces the saved routing key at the current pool width; legacy `NULL` keys use the notification id, even for an orphan. This checks each operation without re-cutting the Entity's `_setVia` away from the person's axis. Price: a retention and an erasure portion per channel; a narrowing of the channel's claim without a new width; the restore path's settle stays a raw write in a process with no agent and is named here rather than hidden | Channel/shard row checks implemented (HIL-1893); channel/shard retention implemented (HIL-1902); disabled-owner duties; owner retry implemented (HIL-1910). Channel/shard erasure with result and the started-recipient send guard built (HIL-1945), inert: no live caller until HIL-1950/HIL-1952. Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `notificationPreferences` | agent `Add`/`Update`/`Remove` (`setChannel()`); holder `Remove` in the erasure | the agent (a row is born only by the person's own choice) | the agent creates its own setting and writes and deletes it | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion | No ordinary move remains. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `pushSubscriptions` | notifications library `Add` (`subscribe()` inserts only for an absent endpoint and refuses collisions); agent `Update`/`Remove` (`renewOwned()`, `markGoneOwned()`, `releaseOwned()`, `unsubscribeOwned()`, `removeOwned()`); holder `Remove` in the erasure | notifications library (a new subscription) | the library creates new subscriptions and nothing else; the personal agent renews its own device, handles dead-endpoint reports, removals and the release of an endpoint a later person takes over | renewal of a person's existing device; the transport's dead-endpoint report; unsubscribe/remove; the release of a foreign endpoint; the erasure's `deleteForUser()` | the subscription row names its person, so the owner is known from the row; the push channel reads the devices and stays a reader; a persisted `revision` distinguishes registrations even across identical `last_seen_at` timestamps, and `renewOwned()` refreshes the saved row and diff baseline, clears its gone state, then increments that revision. A foreign endpoint takes two service frames: `hilos_user_push_release` from the library to the former owner and `hilos_user_push_release_done` back. `releaseOwned()` rereads the row by id and deletes it only when `user_id` and `endpoint` still match, and answers "released" either way; a failed write or guard refuses the press instead. The library then resumes the same press and creates the row under a new id. Price: own-device renewal is split between creator-only library `Add` and personal-owner `Update`; a foreign endpoint costs one more hop and a window with no server row, and its id may change; a refusal by the former owner is explicit, never another person's delete; a dead endpoint becomes a frame per recipient | Own-device renewal implemented (HIL-1905). Foreign-endpoint release by the former owner and creation at the library implemented (HIL-1906). Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `dataExports` | export agent `Add`/`Update`/`Remove` (`order()`, build, publish, forget); holder `Remove` in the erasure (`ofUser()->delete()`), then the post-commit `DataExportNotifier::forgetUser()` | export agent | the existing export executor, the cancellation and clean-up of the erased person's DB order included; the holder only hands over | the erasure's delete of the order; the forget frame becomes the obligation | the builder's check of the retained completed-erasure row stays ([data-export.md](data-export.md)). Price: the executor is a participant with a recorded result instead of a borrowed delete plus a fire-and-forget frame | No ordinary move remains. Owner DB erasure with result is prepared: `runErasurePart()` deletes the order and records `{"orders":<n>}`, the files leave after the commit through the existing sweep, and the publication checks also refuse an erasure that has begun (HIL-1946), with no live caller until HIL-1950/HIL-1952. Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `legalAcceptanceExports` | legal agent `Add`/`Update`/`Remove` (`order()` and the build); holder `Remove` in the erasure (`ofUser()->delete()`), then the post-commit `LegalAcceptancesExportNotifier::forgetUser()` | legal agent | the existing legal export executor, the orders to clean included; the clean-up of shared files touched by the person's acceptance — every ready and failed export removed with its file, the one being built started again — is not lost and not declared implemented by the new mechanism | the erasure's delete of the order; the forget frame becomes the obligation | a project may register no legal agent: the participant list says whether the table was never activated or holds data with no owner. Price: a participant with a recorded result; the file removal stays post-commit | No ordinary move remains. Owner DB erasure with result and existing shared-export fate is prepared: `eraseAccountPart()` deletes the account's order and every ready and failed export and records `{"ownOrders":<0|1>,"sharedExports":<n>}`, the files, the restart of the build and the sweep follow the commit, and an administrator whose erasure has begun orders no new export (HIL-1947), with no live caller until HIL-1950/HIL-1952. Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `userPhotos` | agent `Add`/`Update`/`Remove` (`put()`, `deleteForUser()`); holder `Remove` in the erasure, the file id joining the post-commit removal | the agent (born of the first photo) | the agent writes and deletes its row; the files library owns the registry row, not the photo row; no direct unlink of the disk inside the personal transaction | the erasure's `deleteForUser()` | the file id goes to `Hilos::$files->remove()` after the commit, as today ([files-registry.md](files-registry.md#removing)). Price: the file removal stays a post-commit step without automatic retry | No ordinary move remains. Personal child erasure with result (not in the code yet — HIL-1941). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `userRenames` | agent `Add` (with the name, `renamePerson()`); agent `Update` (`linkEvent()` on a frame after the users library's `afterUserRenamed()` creates the feed event); agent `Update` (`unlinkEventsOf()` on ChatAgent's history-clear ask); holder `Remove` in the erasure (`deleteByUser()`; rows where the person was only the author stay, their author set null) | the agent | the agent of the renamed person, the link and unlink of the room event and the deletion of its journal included. The users library and `ChatAgent` hand it the change and do not write | the feed link after the event is written; the unlink before the room's history goes; the erasure's `deleteByUser()` | `UserRename.event_id` is a unique key onto the room's event. The HIL-1897 history-clear path obtains the unlink answer from the personal owner before deleting the event. Deleting the event clears the database column with `ON DELETE SET NULL`; readers may retain the deleted event id in memory, but no one reads it: feed lookup follows a live event, while history-clear and erasure selections read the table. A link frame received after unlink and before deletion is allowed, with no separate protection needed in HIL-1897. Price: two hand-overs between `ChatAgent` and the agent and an ordering obligation in the erasure | Owner feed link; owner-confirmed unlink before event deletion (implemented in HIL-1897). Event ids kept in the personal owner's capture, whose transaction takes the journal off them (`unlinkForErasure()`); no feed line for a person whose erasure has begun: prepared, no live caller until HIL-1950/HIL-1952. Final personal deletion after required results (not in the code yet — HIL-1942). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `accountDeletions` | users library `Add` (`request()`, the admin card's `scheduleFor()`); agent `Update`/`Remove` (`cancel()` and `start()`, both conditional; nothing live calls `start()` yet); holder `Update` (`complete()`, conditional) | users library | the library creates; the agent cancels, starts and finishes. The conditional race of cancel against due stands, and "completed" is written only after the end; the trace of the request after the person is gone is allowed | the erasure's `complete()` | the started phase is the Entity's `started_at`, stamped by one conditional `UPDATE` that cancel also checks (HIL-1939); the minute sweep that finds due requests stays where sign-in exists and hands the start to the agent instead of erasing (not in the code yet — HIL-1952). Price: one conditional write more per request, one more indexed read in every `AddressablePerson::require()`, and the request's owner runs on in a finishing mode | No ordinary move remains. Conditional owner start against cancel is built (HIL-1939) and inert: no live caller until HIL-1950/HIL-1952. Owner completion only at the actual end (not in the code yet — HIL-1942). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `users` | users library `Add` (registration and first administrator); agent `Update` by row (name, flag, block, theme, time zone); holder `Update`, `Remove` (the loser's block, the erased row); the chat's `Hilos::OWNS_DB`, laid by `TestOnlyCommand` for its seed commands, test-only | users library | The library creates; the agent changes and deletes its own row in the terminal operation (the loser's block, the erased row's final delete). The holder coordinates and is not a second `Update` or `Remove` | the loser's `setBlock()`, the erasure's `delete()` | every child with a hard key is gone before the row can go, and the row's deletion is what stops the agent today: the stop must follow the agent's own last write. Price: the agent's row claim gains `Remove` for one operation, and a finishing mode after the account is closed | Personal finishing mode built: it refuses the ordinary frames and lives until its last write (HIL-1939), inert with no live caller until HIL-1950/HIL-1952. Final personal delete after required results (not in the code yet — HIL-1942). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Loser writes its own block after its merge row in the fact transaction (not in the code yet — HIL-1992). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `verifications` | users library `Add`/`Update`/`Remove` (issue, spend, sweep; `deleteForUserOfType()` on a password change); holder `Remove` in the erasure (`deleteForIdentifiers()` on the current addresses, `deleteForUser()`) | users library | the users library stays the only owner — codes are outside the person's set (P-163) — and receives an obligation to erase by user id and by the current addresses, which it reads from the personal owner's capture. Runtime send attempts are not in this census | the erasure's `deleteForIdentifiers()` and `deleteForUser()` | the addresses are captured before the identities go, as `erasePerson()` does today; the obligation does not copy them, the library reads them from the personal owner's capture. Price: one obligation and one result at the library per erased account | No ordinary move remains. Library erasure by user id and captured addresses with result: prepared, no live caller until HIL-1950/HIL-1952. Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `ChatDbContext::eventMessages` | `ChatAgent` `Add`/`Update`/`Remove` (`addMessage()` from the room page in its process, `deleteAll()`); chat holder `Update`/`Remove`, borrowed (`rePointAuthor()` in the merge, `deleteByAuthor()` in the erasure) | `ChatAgent` | `ChatAgent` itself moves the author or deletes the erased person's messages of every room in one transaction of its own, on the signal | `rePointAuthor()`, `deleteByAuthor()` | the attachments' file ids go to the files path after the commit. Price: one obligation and one result at `ChatAgent` per operation | No ordinary move remains. ChatAgent erasure in its one transaction with result (not in the code yet — HIL-1949). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). Project merge participant declaration is ready: the directory's `chat` owner serves the merge too, and the merge step is built (`AccountMergeProjectStep`), with no live caller until HIL-2000 and HIL-1999. ChatAgent transfers authorship across all rooms in one transaction with result (not in the code yet — HIL-1997). Old merge writer retires at its live switch (not in the code yet — HIL-1999). |
| `ChatDbContext::eventAttachments` | `ChatAgent` `Add`/`Update`/`Remove` (created with the message); chat holder `Remove`, borrowed (`deleteForMessages()`) | `ChatAgent` | `ChatAgent` deletes the DB links with the messages and hands the file ids to the current files path after the commit | `deleteForMessages()` | none beyond the messages' portion. Price: the file removal stays post-commit, with no new guarantee | No ordinary move remains. ChatAgent erasure in its one transaction with result (not in the code yet — HIL-1949). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `ChatDbContext::events` | `ChatAgent` `Add`/`Update`/`Remove` (started, stopped, cleared, messages; `deleteAll()`, `deleteByIds()`); chat users library `Add` only (explicit declaration) (`addUserRegistered()`, `addUserRenamed()` — a second creator the first census did not list, present at the base too); chat holder `Remove`, borrowed (`deleteByIds()` of message, registration and feed events) | `ChatAgent`; the chat users library for the registered line and the feed line | `ChatAgent` deletes the events of its messages, registrations and rename feed with their links; the chat users library's creation of feed lines stays creation alone and grows no edit; the personal rename journal is not written here | `deleteByIds()` in the erasure | the event ids come from the capture of the person's agent, whose transaction took the journal off them (*The erasure captures*); `ChatAgent` obtains nothing from the agent. Price: the ordering with the rename journal, above | The users library remains Add-only; journal link and unlink are the separate userRenames row above. ChatAgent erasure in its one transaction with result (not in the code yet — HIL-1949). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |
| `ChatDbContext::eventUserRegistrations` | `ChatAgent` `Add`/`Update`/`Remove` (history cleanup calls `Remove`); chat users library `Add` only (explicit declaration) (created at registration); chat holder `Remove`, borrowed (`deleteByTarget()`) | the chat users library | `ChatAgent` deletes its registration events of the erased person | `deleteByTarget()` | none. Price: inside `ChatAgent`'s portion | The users library remains Add-only. ChatAgent erasure in its one transaction with result (not in the code yet — HIL-1949). Old erasure writer retires at the live switch (not in the code yet — HIL-1952). |

**Outside the 24.** These are not among the "20 tables others own", and the
protocol must not lose them at its boundary or when control of the person row
moves.

| Collection or record | Current writer and operations | Target writer | Creator and hand-over boundary |
|---|---|---|---|
| `sessions` | holder `Add`/`Update`/`Remove`: the anonymous row on the handshake, the bind at sign-in, the revert at sign-out, `killUserSessions()` in the merge and the erasure, the blocked notices released, the sweep `hilos_sessions_expire`; `SessionTestExpireCommand`, test-only | the holder, as today — a session has no instance owner ([Session Ownership](entity-libraries.md#session-ownership), HIL-1403). In the protocol the holder is a participant for its own rows: it releases every reference of a session to the person — the four `RESTRICT` columns, the waits, the cards — as its own step with a recorded result, before the person row can go | Created by the holder; `Add`/`Update`/`Remove` remain there. Release with an atomic participant result is prepared: `eraseAccountSessionsPart()` records `{"sessions":<n>,"accessLog":<m>}` and tells the tabs after the commit (HIL-1943), with no live caller until HIL-1950/HIL-1952. For merge, the holder ends loser sessions after the fact and survivor sessions after receipt when the loser's factor first protects it (not in the code yet — HIL-1995). Its own release work becomes a participant step at the erasure switch (not in the code yet — HIL-1952) and merge switch (not in the code yet — HIL-1999); no ordinary writer move. |
| `accessLogEntries` | holder `Add`/`Update`/`Remove`: `record()`, the hourly sweep, `rePointToUser()` in the merge, `deleteForUser()` in the erasure | the same owner; it moves and deletes its own rows itself as a participant, on the signal | Created by the holder; `Add`/`Update`/`Remove` remain there. Erasure with an atomic participant result is prepared: `deleteForUser()` runs in the holder's `eraseAccountSessionsPart()` and its count is the result's `accessLog` (HIL-1943), with no live caller until HIL-1950/HIL-1952. Merge transfer commits with its own result; a repeat does not transfer twice (not in the code yet — HIL-1995). Its own delete/repoint become participant steps at erasure (not in the code yet — HIL-1952) and merge (not in the code yet — HIL-1999); no foreign writer is added. |
| `userMerges` | holder `Add`/`Update`/`Remove`: `add()` in `foldAccount()`, `deleteForUser()` in the erasure | changes and deletion go with the personal life cycle to the personal owner: the loser's agent writes its own tombstone with its block in the transaction that gives its rows away, and the erasure of a folded account deletes its merge row with its own rows, leaves before survivors so the survivor's `SET NULL` does not run | Created by the holder today. Leaf-first personal erasure of the merge row is prepared separately (not in the code yet — HIL-1941). Loser tombstone creation, merge row before its own block, commits with the transfer result (not in the code yet — HIL-1992). Erasure deletion moves with the personal life cycle (not in the code yet — HIL-1952); tombstone creation moves to the loser with live merge (not in the code yet — HIL-1999). Keep the old holder rights required by merge after erasure switches. |
| the operation record | The legacy `AccountDeletion` and `UserMerge` rows still carry the live request and tombstone. The common `AccountOperation*` carriers are built but inert on both live paths; the due erasure and force-purge paths and the existing merge remain the production producers. | The sessions holder owns operation creation/change and initial assignment creation; assignments are immutable. Each participant owns its future preparation and result records. | `AbstractSessionsLibraryAgent::OWNS_DB` declares operations `[Add, Update]` and assignments `[Add]`. The record actions preserve exact repeats and reject conflicts; `AccountMergeOpening::open()` atomically stores a merge, its two initial assignments and the project's expected composition, and `AccountMergeOpening::reconcile()` appends what is missing, but neither has a live caller. Stage advance/finish are low-level persistence only; they do not establish start, participant results, business transitions or live recovery/failover. The common tables, whole-table `PURGE` verdicts and migration are documented above. Merge stage correlation (not in the code yet — HIL-2000), merge end after receipt, results and copy absence (not in the code yet — HIL-1998), and the live merge and erasure switches (not in the code yet — HIL-1999/HIL-1952) remain future work. |
| the participants' preparations and results | The immutable result carrier and generic atomic step exist. The person's agent (HIL-1939), the sessions holder (HIL-1943), the data export agent (HIL-1946), the legal agent (HIL-1947) and the users library (HIL-1944) declare the result claim, and their steps are prepared with no live caller until HIL-1950/HIL-1952. | Each participant owns its preparation or result, written in the same transaction as its work; the coordinator may create the initial assignment and never edits a recorded result. These durable records use `Hilos::$db` collections and actions, not agent memory or a runtime overlay. A result carrying rows given away — the merge's hand-over copy at the loser — is personal data with a bounded life: kept until the survivor's confirmation, then deleted by its one writer. The personal capture of an erasure is a result with personal data too, the one place the person's addresses are kept; the final transaction of the person's agent deletes it (not in the code yet — HIL-1942), see *The erasure captures*. | Participant results are stored in `hilos_account_operation_result`, at most one immutable JSON payload per assignment. `owner_agent_id` is the full participant agent id and the result set key; the assignment foreign key cascades. The table is `PURGE` under anonymization and is not journaled. `record()` requires the participant transaction and its `Add` set claim, returns an exact semantic repeat, and rejects a changed result. `AccountOperationParticipantStep::run()` checks the caller, assignment and owner, returns a saved result before new work, then runs work and records its result in one transaction; an equal-result refusal or duplicate insert may return the committed winner only after that transaction rolls back. `forOperationStage()` returns a typed `AccountOperationResults` collection in result-id order. The production participant claims are the person's agent's (HIL-1939), the sessions holder's (HIL-1943), the data export agent's (HIL-1946), the legal agent's (HIL-1947) and the users library's (HIL-1944); no consumer of their results is wired until HIL-1950/HIL-1952. Merge reservations belong to each personal owner (not in the code yet — HIL-1988); each releases its own with result before the fact (not in the code yet — HIL-1990). Loser work/result and full copy (not in the code yet — HIL-1992); survivor work/receipt (not in the code yet — HIL-1994); holder work/result (not in the code yet — HIL-1995); project participants are built — `AccountMergeProjectStep` with its `{"rows":{…}}` result — with no live call; chat work/result (not in the code yet — HIL-1997). Only the loser cleans its copy after receipt and stops afterward (not in the code yet — HIL-1998). Live merge uses these records only at cutover (not in the code yet — HIL-1999). Live erasure uses them only at cutover (not in the code yet — HIL-1952). |

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
  Erasure's selection and continuation from DB are prepared by its recovery
  leaf (not in the code yet — HIL-1951).
- **The initiative is the coordinator's record and the owner's start, not the
  next frame.** A signal hastens; it is not what makes the work owed. The
  coordinator's record says which results are missing and the holder asks for
  them again when it starts and when it passes over its open records; a
  participant's start finds its assignments. "Another frame will come
  eventually" is not a mechanism.
  Erasure recovery must progress after a lost frame without a new external
  event (not in the code yet — HIL-1951).
- **A participant's atomic result is not exactly-once for the operation.** Each
  step is done once or not at all at its owner; the operation as a whole may
  ask twice, hear nothing or hear late, and every step is written so that the
  second ask answers with the stored result. The result and the clean-up of a
  hand-over copy have one writer each, and a repeat of either is the same
  answer again.
  Erasure's generic participant step commits work and result in one local
  transaction and answers a repeat from the saved result. The person's agent
  and the delivery channel/shard erasure parts are built but inert until live
  paths call them; a channel result records `{"deliveries":<n>}`. The coordinator
  accepts an answer only for the matching operation and stage
  (not in the code yet — HIL-1950).
- **A closed account stays closed.** No internal continuation, no late frame
  and no misaddressed answer opens the ordinary actions of an account whose
  start fact is recorded: the finishing mode takes the continuation of its own
  operation and refuses the rest, a missing or folded account in today's words
  and an account whose erasure has begun in its own ("This account is being
  deleted."). The agent's finishing mode and the sessions holder's refusal of
  sign-in are built (*The closed account*) and inert until the live path
  (HIL-1950/HIL-1952).

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
| The survivor applied the rows and died before confirming, or an old frame arrives again | the survivor's own writes — the wait, the trusts — with its confirmation in one transaction, or none of them | the survivor, at start, reads its set and the obligation; the loser's agent sends again until the confirmation is recorded | the admission fence rejects stale DB_SYNC and hand-over rows independently of receipt; the confirmation names the operation and stage, so a frame already confirmed answers with it and a frame of a closed operation is refused | the restriction holds until the confirmation, then lifts |
| An owner dies, stops idle or moves to another node in the middle | its results so far; its open assignments; its grants gone while it is down ([agent-lifecycle.md](agent-lifecycle.md#when-a-worker-dies)) | the owner, placed and started again by the next frame or by the coordinator's ask, on whichever node the placement names; it reads its records in its start and finishes | the address in the record is the agent's type and index, not a process or a node, so the new incarnation is the same owner; the old copy is stopped before the new one starts ([daemon-lifecycle.md](daemon-lifecycle.md#node-health-and-failover-hil-183)) | a longer wait; the operation stays open and names the participant |
| A required owner is missing: a table with rows and no agent registered for it | the rows; the assignment the coordinator wrote; no result | nobody, until the project registers the owner again; there is no fallback writer, and taking away the last owner of rows that stay is a separate decision (question 4), not an administrator's act and not a way to close the obligation | no fallback writer takes the rows, so no row gets two writers and nothing is deleted by a process that does not own it (question 4) | an explicit open obligation, *waiting for an owner of …*; the account stays closed |
| A child with a hard key was forgotten and no participant deleted it | the child row; the agent's final transaction rolled back, the person row and the journal still there | the agent, which retries the final step once the owner of the row has been added to the participants | `RESTRICT` refused the delete and the agent's transaction rolled back, so the request is not marked carried out and nothing is half-deleted | the operation stays open at the final step with the refusal named; no account comes back |
| A soft reference stayed behind: a delivery whose notification is gone, a mention set null | the delivery rows under the channel's assignment; the rows meant to stay — the request, a rename whose author is set null, a merge whose survivor is set null | the channel, from the recipient and the ids in its assignment, recorded before the notifications went | a delete by recorded ids is the same delete twice; the rows meant to stay are part of no obligation | the channel is listed as expected until its result; the trace rows carry the deleted person's number, as the journal's receipts already do ([change-log.md](change-log.md#one-scheduled-attempt-across-parts)) |
| The channel is switched off | its journal rows and its retention term; the assignment to delete the erased recipient's rows | the channel's own agent, which stays available for retention and erasure (question 4); the dispatcher only stops creating new deliveries | switching off writes nothing and deletes nothing; when the started fact exists, the built guard skips the send attempt and refuses an administrator retry in the channel's own words | the channel erasure part records `{"deliveries":<n>}`; prepared, with no live caller until HIL-1950/HIL-1952. History and term unchanged |
| A backup is restored with an operation unfinished | whatever had committed when the archive was taken: a started fact, some results, a tombstone with its copy, rows half moved | the coordinator and every owner, at their start after the restore, exactly as after a death: open records are selected from the facts and finished | every step repeats from a recorded result or a conditional write; the archive carries the records because they are rows — which is why they are rows | the account is as closed as it was at the archive's moment, and the operation finishes; a restore into a lesser environment classifies the records with the rest ([backup-anonymization.md](backup-anonymization.md#core-rule)) |

The restore path has one raw write today, `RestoredDeliveries::settlePending()`,
in a process with no agent: it settles pending deliveries as failed and is named
in the *Writer Map*. Under the protocol an erased recipient's rows are the
channel's obligation whatever that settle did, and the settle is no step of the
operation.

For erasure, missing owners leave an open obligation without a fallback
(prepared, no live caller until HIL-1950/HIL-1952); a final `RESTRICT` refusal rolls back only
the personal owner's final transaction and leaves the operation unfinished
(not in the code yet — HIL-1942). The first page reply and live updates must
carry that wait or safe refusal (not in the code yet — HIL-1954). These recovery
paths become live with the common erasure cutover
(not in the code yet — HIL-1952). Their cluster/backup proof includes lost,
duplicate and old frames, owner sleep/death/movement, missing owners and a
forgotten restrictive child (not in the code yet — HIL-1964).

For merge, preparation repeats from each owner's reservation and captured
identity (not in the code yet — HIL-1988); a refusal or cancellation before
the fact releases both owners' reservations before *not started*
(not in the code yet — HIL-1990). Lost frames after the fact and a sleeping
survivor are recovered from DB at coordinator start and subsequent passes,
participant start or an operation frame, without polling each tick
(not in the code yet — HIL-2002). Row admission and its fence against stale
`DB_SYNC`/hand-over remain separate from receipt
(not in the code yet — HIL-1993); the survivor's own password, wait and trust
writes commit with receipt (not in the code yet — HIL-1994). A failure between
copy deletion, answer and end is completed forward, with no ordinary access
reopened (not in the code yet — HIL-1998). Duplicate, old and foreign replies
are judged by operation and stage, never by whichever operation happens to
be current (not in the code yet — HIL-2000). These paths become live only at
the merge switch (not in the code yet — HIL-1999); cluster and backup evidence
covers their failures and the absence of a second effect
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
  the post-commit steps that remove files and send forget frames are the ones
  the tree has, logged and not retried; this page attributes no retry to them
  and gives them no new guarantee.

For erasure these are target guarantees with separate implementing owners:
HIL-1939 built the closure and the internal finishing mode (*The erasure
start*, *The closed account*), and HIL-1937 the generic step for atomic
participant work/result; both are inert until the live path. The live
participants, final personal delete and the coordinator's end after all
results remain with later leaves (not in the code yet — HIL-1942), as does
forward recovery from restored DB obligations
(not in the code yet — HIL-1951). They take effect on due/force-purge at the
single cutover (not in the code yet — HIL-1952), with failure and backup evidence
in its own leaf (not in the code yet — HIL-1964). No timing bound, common
rollback after start, exactly-once claim or stronger file guarantee is added.

For merge these are likewise target guarantees: preparation changes no
business rows (not in the code yet — HIL-1988), and cancellation before the
fact releases each owner's reservation while after it only completion
remains (not in the code yet — HIL-1990). The loser's transfer, tombstone and
copy commit with its result (not in the code yet — HIL-1992). Admission
protects memory against stale frames even after receipt, cleanup and a later
merge (not in the code yet — HIL-1993); the survivor applies its own chosen
protection with receipt (not in the code yet — HIL-1994). The holder confirms
its own work (not in the code yet — HIL-1995), as do declared project owners
(prepared in `AccountMergeProjectStep`, live at the merge switch) and chat's author transfer
(not in the code yet — HIL-1997). Cleanup by the loser precedes its stop and
the coordinator's end (not in the code yet — HIL-1998). Correlation prevents
an unrelated answer advancing the operation (not in the code yet — HIL-2000);
recovery continues from DB obligations (not in the code yet — HIL-2002).
These guarantees take effect at the one merge switch
(not in the code yet — HIL-1999), with cluster/backup evidence separately
(not in the code yet — HIL-2013). The backup window, safe repeat rather than
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
HIL-1952 and HIL-1999 own the live switches, not the preparation leaves.
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
HIL-1911/HIL-1912 verify behavior after the code leaves and do not inherit
another leaf's marker cleanup.
