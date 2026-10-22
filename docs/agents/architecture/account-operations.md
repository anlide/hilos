# Account Operations: Erasure And Merge Across Owners

Read this before designing or cutting the split erasure and merge of an
account — the two operations that today run as one transaction at the sessions
holder across tables other agents own — before giving a library, a channel or
the sessions holder a write it would hold only for one of those operations, and
before deciding who the single writer of a person's row is after that row has
been created.

**This document is the result of a research leaf (HIL-1675), approved by the
owner, and not a description of code already built.** Nothing in it is callable
today: there is no new API, no new right, no new table, no new frame. The
erasure and the merge that run in the tree are the ones described in
[The Erasure](account-deletion.md#the-erasure),
[A Merged Account](people-table.md#a-merged-account) and
[Operations Over Many Instances](instance-owners.md#operations-over-many-instances),
and an executor working on today's code follows those pages. What this page
adds is the agreed target, the facts of the present that the target replaces,
and the single writer of every table the two operations touch — so that the
leaves which build it do not choose again between the product options the
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
inputs of the future cut of the epic HIL-1674. The owner decides the cut at an
interview; this page does not create or edit tickets.

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

**Status of the words below.** Every record, participant result and transfer
right named here is a logical model in text. The exact schema, wire names and
contracts belong to the code leaves that will be cut from HIL-1674, each at its
own interview; the contract gate is unchanged by this page. For the same reason
no sentence here carries the marker
`(not in the code yet — HIL-<n>)`: [rule-authoring.md](../rule-authoring.md),
*A Rule Written Ahead Of Its Code*, wants the marker to name the leaf that
lands the behavior, and those leaves do not exist yet. When they are cut, each
takes its norms and its markers into the rules it changes. Until then the
borrowed claims the sessions holder and the chat's sessions holder declare for
the erasure and the merge stay in the code with the comments they carry, and
the existing markers of other leaves are not touched.

**Where the facts come from.** The present-tense statements under *Current
Implementation* and in the *Writer Map* were read off the tree at the research
base `1d579875e` and checked again on the head this page was written at; the
sources that carry the erasure, the merge, the claims and the guards did not
change between the two. They are facts about the code, not an approved
contract; where the code and this page disagree later, the code is the fact
and the page is the one to correct.

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

Facts of the tree, read at the research base and checked at the head of this
page. Paths are under `framework/backend/` and `demo/chat/backend/`.

**The sessions holder's claims.** `AbstractSessionsLibraryAgent::OWNS_DB`
(`Auth/Library/AbstractSessionsLibraryAgent.php`) names twenty-three
collections. Four are its own whole: `sessions`, `secondFactorTrusts`,
`userMerges`, `accessLogEntries`; one is held whole for the merge's upsert and
the erasure's delete, `secondFactorSettings`; `users` carries `Add`, `Update`
and `Remove` for the first administrator, the loser's block and the erased
row. The other seventeen are borrowed — `Update` and `Remove`, or `Remove`
alone, each with a comment naming the erasure or the merge and pointing at
[instance-owners.md](instance-owners.md#operations-over-many-instances). The
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
`effective_at`, `canceled_at` and `completed_at` and nothing of a started
phase; its `user_id` deliberately has no foreign key, so the row survives the
person as the trace of the request, and under anonymization it is purged.

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

**The stop.** `AbstractUserAgent` (`Users/Agent/AbstractUserAgent.php`) stops
itself the moment its person row is deleted (`onSignalDbSyncDeleted()`) or a
merge row keyed by its person appears (`onSignalDbSyncCreated()`, HIL-1410).
Both are immediate: the agent is gone before anything of its own could run.
Under the target protocol the loser's agent is the one that gives its rows
away and the erased person's agent is the one that deletes its part, so this
stop is in the wrong place by construction: either the agent's own work is
done before the final fact, or the agent runs on in a mode that only finishes
the operation (question 3). `AddressablePerson::require()`
(`Users/AddressablePerson.php`) refuses a missing or folded person before the
hop and the agent repeats the check; the service continuation of a future
operation must not become a general way around that refusal.

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
`Update` alone; `OWNS_DB_SET` holds fourteen child sets, borrowed except the
four the owner brings into being (`secondFactorSettings` with `Add` and
`Update`, `stepUps`, `notificationPreferences` and `userPhotos` whole) and the
rename journal with `Add` alone. `renamePerson()` is the real atomic example
the target follows: the name and its journal row in one transaction of one
owner, and what follows the commit is the library's, on the answer.

**Creators.** `AbstractUsersLibraryAgent::OWNS_DB`
(`Auth/Library/AbstractUsersLibraryAgent.php`) creates `users`,
`identities`, `passkeyCredentials`, `secondFactorResets` and
`accountDeletions`, creates and removes `secondFactors` and
`secondFactorBackupCodes`, holds `verifications`,
`registrationReservations` and `legalAcceptances` whole and `userRenames` for
`Update`. `AbstractOAuthAgent` (`Auth/OAuth/Agent/AbstractOAuthAgent.php`)
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
notifications, creates and removes deliveries — the removal is
`DeliveryLogPruner::prune()` (`Notification/DeliveryLogPruner.php`), a raw
batched `DELETE` of terminal rows older than the retention — and creates and
updates push subscriptions (`subscribe()`, `markGone()`).
`NotificationDispatcher` (`Notification/Delivery/NotificationDispatcher.php`)
reads the channel's enabled setting only to decide whether to dispatch, and
its `requeue()` resets a failed row for retry under the library. The restore
path (`Notification/Delivery/RestoredDeliveries.php`) settles every pending
row as failed with a raw `UPDATE`, in a process with no agent.

**Whole-table statements at the holder.**
`SecondFactorTrustsActions::capLiveUntil()` and `deleteAll()`
(`Database/Actions/Collection/SecondFactorTrustsActions.php`) apply the
administrator's trust term and the switch-off of the second factor to every
person's trust at once, from the sessions holder.
`LegalAcceptancesActions::forgetLaterThan()`
(`Database/Actions/Collection/LegalAcceptancesActions.php`) is a test-only
removal, called by `test:legal:hold`
(`Auth/Library/Command/LegalAcceptanceCommands.php`) under the users library.

**The chat.** `ChatAgent::OWNS_DB` holds the four room tables whole and the
rename journal for `Update`, borrowed to take the room reference off a journal
row when the room's history is cleared (`EventsActions::deleteAll()` →
`userRenames->actions->unlinkEvents()`,
`Database/Actions/Collection/EventsActions.php`). The chat's `UsersLibraryAgent`
(`Agents/Hilos/UsersLibraryAgent.php`) also claims `events` and
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

The borrowed declarations named above really stay in the code until the leaves
that carry out each move land; none is removed by this page.

## Operation And Participant Records

The protocol rests on records, not on messages: a message is how a participant
learns that there is work, and a record is what makes the work owed. Every
record below is a logical model — what it holds, who writes it and when — and
not a table, an Entity or a frame; those belong to the leaves cut from
HIL-1674 (*Scope And Status*).

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
exists for. The holder is the single changing writer of this record. The
record is a working row of an executor, lawful under *Scope And Status*: it is
not a claim over anybody's business table, and holding it gives the holder no
write into a person's rows.

**The participants' records.** A participant is every owner the operation
needs: the agent of each account in the circle, the users library, each
channel, each export executor, the holder itself for its sessions and its
access log, `ChatAgent`, and whatever owner a project declares for its own
rows. Each writes two kinds of record of its own. A *preparation* is what it
has committed to or captured before anything irreversible happens: in a merge
the reservation of its account, in an erasure the current addresses of the
ways in or the event ids of the rename feed, read while the rows that carry
them still exist. A *result* is the statement that its part is done and what
it did, enough to make a repeated ask answer the same without doing the work
again. A participant writes its work and its result in ONE transaction of its
own: the rows deleted or re-pointed and the result that says so stand or fall
together, so a result present is the proof that the work is done
([transactions.md](../orm/transactions.md)). The coordinator may create a
participant's initial *assignment* — "this part of this operation is yours",
with what the part needs — and never edits a result a participant recorded.
The two kinds keep the two writers apart: the assignment is the coordinator's
statement, the result the participant's, and neither rewrites the other's.

**One operation, one stage, one answer.** Every assignment, frame and result
names the operation and the stage it belongs to. A result that names another
operation, a stage already closed or an operation the coordinator does not
know advances nothing: it is answered with what the record already holds, or
refused, and never applied to the operation that happens to be open now. The
record of the end is a fact of its own, written after the required results are
in, and distinct from the fact of the start; a failure between the two leaves
an unfinished operation, not a rollback and not a cancellation — once started,
both operations are finished (question 1).

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
needs its per-column verdict when it is added
([backup-anonymization.md](backup-anonymization.md#adding-a-table-or-a-column)):
the preparations and results hold addresses, ids and, in a merge, the rows
given away.

## Erasure

The order below is one operation over a circle of accounts. "The agent" is the
agent of the account whose step is described; a circle has one agent per
account, and they share no transaction.

1. **The request falls due.** The users library created the request; the agent
   may have canceled it (HIL-1409). Once a minute, where a sign-in exists, the
   holder finds the requests whose moment has come — the sweep it runs today —
   and for each gathers the circle from the merge table, deepest first as
   `erasureOrderOf()` does, derives the participants from the tables that are
   active and the owners that are registered in this installation (step 4),
   and writes its operation record: kind, root account, circle, participants,
   stage *preparing*. Nothing of the person's has changed yet. A second sweep
   that finds the same request finds the record and writes nothing.
2. **The start is won or lost at the request's owner.** The holder hands the
   start to the agent of the account the request names. The agent writes the
   started fact into its request by a conditional write — the race against
   "Keep my account" that the two endings run today, carried over whole — and
   records its result in the same transaction. A cancel that won leaves the
   request canceled and the operation closed as *not started*, nothing
   touched and no participant signaled; a cancel that comes a moment later
   loses and is answered as the refusal it is. From the started fact on, the
   operation is finished and never undone (question 1). A folded account in
   the circle with a live request of its own has its agent mark that request
   started the same way, as the first step of its own part; no request is
   created for an account that has none.
3. **Access closes.** The started fact is what every gate asks that today
   asks "is this account folded": sign-in is refused, ordinary actions and
   frames to the agent are refused in the words a folded account is refused
   in, the people list says *erasure in progress* and names the participants
   still expected, and nothing of it opens again because a part is slow
   (question 3). The agent runs on in a finishing mode that takes only the
   internal continuation of this operation; `AddressablePerson::require()`
   keeps refusing the ordinary hop, and the continuation is not a way around
   it. The holder, as a participant for its own rows, releases every reference
   of a session to the person — signs the sessions out, clears the waits on
   the second factor, lowers the closed-access cards; the four `RESTRICT`
   columns of the session row are among them
   ([Session Ownership](entity-libraries.md#session-ownership)) — in a
   transaction of its own, with its result. The session rows themselves stay
   for the sweep, as today.
4. **The participants are derived, not assumed.** The list is not "the twenty
   tables, always". For each table the record says which of three states it is
   in. *Never activated* in this project: no rows can exist and no participant
   is owed. *Activated and owned*: the owner is a participant. *Activated,
   with rows, and no owner registered* — the legal export table in a project
   without a legal agent, the journal of a channel the project no longer
   registers: this is visible unfinished work. The operation waits on it, says
   so, and does not count it done. No fallback writer takes the rows
   (question 4): a missing owner leaves an explicit open obligation, not a
   quiet success and not a second writer.
5. **What is captured before an anchor goes.** Three links live only in rows
   the erasure deletes, and each is recorded durably before its anchor is
   touched, so that no owner ever has to read a row that is already gone:
   - the current addresses of the ways in, which the users library needs to
     delete the codes on them (`deleteForIdentifiers()` today): the agent
     captures them from its own set into its preparation before it deletes
     its identities, and the users library's assignment carries them with the
     account id;
   - the recipient and the notification ids of the deliveries, which point
     softly at their notification and are in nobody's set once it is gone
     (*Current Implementation*): each channel's assignment is recorded with
     them before the agent deletes the notifications, so the channel deletes
     its journal rows by the ids it was handed and not by a join that no
     longer answers;
   - the event ids of the rename feed, which only the rename journal knows
     (`UserRenames::eventIdsByUser()`): `ChatAgent` reads them off the journal
     and records them in its preparation before the agent deletes the journal
     rows.

   The rule behind the three: a link is never lost before the next owner has
   recorded its obligation over it. A foreign key never catches a soft link,
   so the record is the only safeguard there is.
6. **Each owner deletes its own, children first, and confirms.** Within one
   account the order is children before parents, as the hard keys demand and
   as `erasePerson()` orders its statements today. The agent deletes the
   device keys before the ways in, the addresses already captured; then its
   second-factor rows, step-up confirmations and legal acceptances — its set,
   though the library created them; its merge row when it is a folded account;
   its notifications, after the channels' assignments are recorded; its
   preferences, push subscriptions and photo row, the photo's file id kept for
   the step after the commit. The users library deletes the codes by user id
   and by the captured addresses. Each channel deletes the deliveries of the
   recipient — the agent of the delivery journal, switched off or not
   (question 4). The export executor and the legal executor delete the
   person's orders and, after their commit, the files, as today; the legal one
   also starts the shared exports again, as
   [account-deletion.md](account-deletion.md#the-erasure) requires. The holder
   deletes the person's access log entries; the trust rows go with the agent's
   set. `ChatAgent` deletes the messages with their attachment links, the
   registration events and the feed events of the person in every room, in
   one transaction of its own — the owner's reading of 2026-10-04 — by the
   event ids it recorded; the attachments' file ids join the files path after
   its commit. A project names its own participants for the rows that
   `applyAccountErasure()` deletes for it today under borrowed claims; where
   the person is only mentioned in somebody else's row, the mention goes by
   the existing `SET NULL` key and the row stays. Every participant records
   its result with its work.
7. **The parent row goes last, under `RESTRICT`.** When every required result
   of the account is recorded — the holder's, the library's, each channel's,
   each executor's, the project's — the agent, in one transaction of its own,
   deletes the person's rename journal rows, deletes its own person row and
   marks the request carried out. `RESTRICT` on every hard key is the last
   check and not the proof: a child somebody forgot refuses the delete, the
   agent's transaction rolls back, and the operation stays open at this step
   with the refusal visible. `completed_at` then means that the account is
   gone, not that its erasure was queued. The request row survives the person
   as the trace it is today, with no key onto the deleted row.
8. **The circle, folded accounts first.** Steps 2–7 run for each account of
   the circle, every folded account before the account it was folded into, so
   that each merge row is deleted by its own account's agent while the
   survivor still exists and the merge table's `SET NULL` never runs — the
   order [people-table.md](people-table.md#a-merged-account) relies on. The
   survivor's row is the last row of the operation. The agent of a folded
   account is raised for this part alone, through the internal continuation,
   and refuses everything else.
9. **The end.** The coordinator records the end of the operation when the
   last result is in. Each agent stops after its confirmed part — the stop
   that fires today on the deleted row, moved after the agent's own last write
   (*What the target protocol replaces*, item 3) — and does not rise again for
   this account. The steps after a commit that cannot be rolled back — the
   files library's removal, the export agents' forget frames — stay after the
   commit of the participant that names them, logged and not retried, as
   [work-in-portions.md](../signals/work-in-portions.md#related-records) says
   of them today. This page gives them no new guarantee.

## Merge

"The loser" is the account folded in and its agent, "the survivor" the account
that absorbs it and its agent. The password fate and the second-factor fate
keep their present meanings and refusals
([second-factor.md](second-factor.md#account-merge)); what changes is where
each check runs and who writes.

1. **The request.** The administrator's window or `account:merge` names the
   two accounts, the fates and, from the window, the two protection flags it
   showed. The holder judges what it judges today without raising anybody —
   the two ids differ, both exist, neither is folded, and a password fate is
   named when both accounts have a password — and refuses at once; what passes
   becomes the operation record: kind, survivor, loser, fates, the browser's
   expected flags, stage *preparing*. An account already named by an open
   operation — this merge, another merge, an erasure — is refused here: one
   operation per account at a time, and a reservation is never shared.
2. **Both owners prepare.** The holder asks the loser's agent and the
   survivor's agent to prepare. Each, in a transaction of its own, checks
   against its own rows what the lock and the re-read check today — whether it
   holds a confirmed authenticator and whether that matches the flag the
   browser showed, whether a live removal of its factor stands where the fate
   forbids one — refuses in today's words when the check fails, and otherwise
   records its preparation: the account is reserved for this operation, and
   from this moment the agent refuses every change of its ways in and of its
   protection — password, passkeys, authenticator, backup codes, reset, wait —
   with a refusal naming the merge under way (question 2). Ordinary content
   edits that touch none of these are not closed by the reservation. The
   reservation is a conditional write on the agent's own record, so two
   operations cannot both hold it; that is what keeps a competing merge or
   erasure of the same account out. Nothing is locked and nothing is re-read
   from the database: the check runs at the owner of the rows, on its own
   copy, and that is the whole replacement of `lockForMerge()` and
   `reHydrateCollection()`.
3. **Cancellation, before the fact only.** Until the loser's fact is recorded
   the operation may be called off — by the administrator, by the refusal of
   either preparation — and each agent releases its own reservation with a
   result that says so; the record closes as *not started*. After the fact
   nothing is canceled: not by a timer and not by a late refusal. A timer
   after the fact was rejected because what it would undo is already
   committed on one side.
4. **The fact: the loser gives its rows away.** With both preparations
   recorded, the coordinator hands the loser's agent the go. In ONE
   transaction of its own, under the transfer right below, the loser's agent
   re-points its ways in to the survivor by the password fate and the device
   keys with their anchors; re-points its confirmed authenticator and its
   backup codes, spent status kept, when the fate keeps the loser's
   protection, and deletes them otherwise; deletes its unfinished enrolment,
   its resets, its settings row and its trusts — the same rows with the same
   ids, so every reference from another table stays whole. In the same
   transaction it writes its tombstone, the merge row first and its own block
   flag second, in the order `foldAccount()` keeps today and for the same
   reason, and records its result: the fact, and the *hand-over copy* — the
   rows as they were given, the loser's effective wait among them, enough to
   send the survivor a frame carrying the rows without ever reading the
   survivor's set. The ids of the rows alone would not do: a frame "with the
   rows" that had only ids would have to read them out of another's set. From
   this commit the loser is folded: `AddressablePerson::require()` refuses it,
   the merge-candidates table hears of it by the person row, and the holder
   ends the loser's sessions as its own participant step, after the commit as
   today.
5. **The survivor takes the rows into memory.** The loser's agent sends the
   survivor's agent the frame carrying the rows. The survivor applies them to
   its memory — no second business `UPDATE` of the rows to fill it; they are
   already in its set in the database — and, in a transaction of its own,
   writes what is its own to write: the merged wait, the longer of the two,
   into its own settings row; its own trusts deleted when the loser held a
   confirmed authenticator, as today
   ([second-factor.md](second-factor.md#account-merge)); and its *confirmation*
   of receipt for this operation and stage. The confirmation is the fence: a
   frame for an operation and stage already confirmed answers with the stored
   confirmation and applies nothing; a frame for a closed or unknown operation
   is refused; a frame older than the state the survivor already holds does
   not roll it back. An agent that was asleep reads its own set at start — the
   rows are there — and confirms from the obligation it finds, not from a
   frame it never saw.
6. **The other owners move their own.** On the fact, `ChatAgent` re-points the
   authorship of the loser's messages (`rePointAuthor()` today) in one
   transaction of its own; the holder re-points the access log entries as the
   owner of that table; a project's declared owners do what
   `applyAccountMerge()` does for them today — each with a result. Nothing
   else of the loser's content changes its fate: what the present merge rules
   leave where it is stays where it is.
7. **The restriction lifts on the confirmation.** The survivor's reservation
   ends when its confirmation is recorded and the chosen protection is applied
   in its rows: only then does a sign-in through a transferred way in pass,
   and only then may the survivor change its ways in or its factor again. The
   holder ends the survivor's sessions when the loser's authenticator first
   protects it, as today, after that confirmation and not before
   (question 2).
8. **The end, and the copy's life.** The coordinator records the end on the
   survivor's confirmation and the other owners' results. The loser's agent,
   the only writer of its hand-over copy, deletes the copy once the
   confirmation is recorded, and then stops — after its confirmed part, as
   question 3 reads. The copy holds what the rows hold — password hashes,
   authenticator secrets, addresses, backup codes — so it is personal data
   with a bounded life: classified under the anonymization verdict of its
   table, kept only until the confirmation, cleaned by its one writer. The
   protocol leaves no permanent copy of a folded account's secrets in a
   service table. A loser's agent that died after the commit is raised only to
   finish — to send the copy again and to delete it — and refuses everything
   else.

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
the width of any claim.

The exact interface of that door, and of the metadata the record hands it, is
a code leaf's, at its own interview. This page has no standing to declare it
built: the door does not exist in the tree today.

## Writer Map

The 20 framework collections and the 4 chat collections the sessions holder
writes today, each with the writers the code has now, the creator, the single
owner of changes after creation the owner's decisions give it, what is handed
to that owner, and the dependencies and the price of the hand-over. "Target"
is the agreed design, not a right anybody holds today. Collection keys are the
`HilosDbContext` and `ChatDbContext` names; "holder" is the sessions holder
(`AbstractSessionsLibraryAgent`, and in the chat its subclass), "agent" is the
person's agent (`AbstractUserAgent`), "users library" is
`AbstractUsersLibraryAgent`. A writer in the second column that the target
column does not name loses that write; the operation-level records the
coordinator and the participants keep are outside these 24, below.

| Collection | Writes today, by the code | Creator today | Target: single owner of changes after creation | Handed to that owner | Dependencies and price |
|---|---|---|---|---|---|
| `identities` | users library `Add` (registration, a password set right after the insert); OAuth agent `Add` (a linked provider account); agent `Update`/`Remove` over its set (password change, reset and rehash, address verify, email change, unlink); holder `Update` in the merge (`rePointToUser()`) and `Remove` in the erasure (`deleteForUser()`); `UserTestSeedCommand`, test-only | users library; OAuth agent for provider links | the agent: every edit of a way in and of recovery, the transfer of its own rows to the survivor under the transfer right, the deletion in the erasure | the merge's `rePointToUser()`, the erasure's `deleteForUser()`; the holder loses `Update` and `Remove` over another's set | device keys restrict the delete of their anchor, so they go first; the current addresses are captured for the codes before the ways in go. Price: the transfer right by operation (the guard refuses a set claim moving a row today); one local transaction at the loser with its recorded result; a transferred way in signs nobody in until the survivor's protection is applied |
| `passkeyCredentials` | users library `Add`; agent `Update`/`Remove` (the counter on use, the unlink with its identity); holder `Update` in the merge (`rePointToUser()`) and `Remove` in the erasure | users library | the agent, use, transfer and deletion included; the row hangs on its identity anchor (`identity_id`, with `user_id` as the short path moved with the anchor, HIL-1132) | the merge's `rePointToUser()`, the erasure's `deleteForUser()` | moves in the same local transaction as its identity, the short path rewritten with the anchor; deleted before the identities in the erasure. Price: none beyond the identities' portion |
| `secondFactors` | users library `Add` and `Remove` (`startEnrolment()` replaces an unfinished enrolment); agent `Update`/`Remove` (confirm, step, remove, a reset fallen due); holder in the merge (`rePointConfirmedToUser()`, the unconfirmed one deleted, or `deleteForUser()` by the chosen fate) and `Remove` in the erasure | users library | the agent: every later edit and deletion; the removal of an unfinished enrolment goes to it too, so that the library keeps creation alone and no second `Remove` | the merge's re-point or delete by the chosen fate, the erasure's `deleteForUser()` | the chosen protection is applied at the survivor before a transferred way in may sign in (question 2); the pending-removal refusals of today become refusals at preparation. Price: the five locks and re-reads become a prepare-and-confirm exchange between two agents, each with a local transaction and a recorded result |
| `secondFactorBackupCodes` | users library `Add` and `Remove` (`issueSet()` replaces the old set); agent `Update`/`Remove` (a code spent by conditional write); holder in the merge (`rePointToUser()` or `deleteForUser()`) and `Remove` in the erasure | users library | the agent; spending, transfer and deletion keep the chosen second-factor policy | the merge's re-point or delete, the erasure's `deleteForUser()` | travels with the factor in the same portion. Price: inside the second-factor portion |
| `secondFactorResets` | users library `Add` (`request()`); agent `Update`/`Remove` (cancel, complete, due and reminder on the sweeper's frame); holder `Remove` in the merge and in the erasure | users library | the agent; `SecondFactorResetSweeper` (`Auth/SecondFactor/SecondFactorResetSweeper.php`) only finds and hands over and writes no row of it | the merge's and the erasure's `deleteForUser()` | the merge's "pending removal" checks become preparation refusals at the two agents. Price: none new |
| `secondFactorSettings` | agent `Add`/`Update` (its own one-to-one row); holder `ALL` (the merge's `setResetWait()` on the survivor, `deleteForUser()` on the loser; the erasure's `deleteForUser()`) | the agent (born of its first edit) | the agent, its own row included; the merged wait — the longer of the two — is written by the survivor's agent from the fact it receives, never by the holder from the loser's set | the merge's `setResetWait()` and `deleteForUser()`, the erasure's `deleteForUser()` | the loser's wait is part of the transferred result. Price: one more value in the frame; the holder's whole claim goes |
| `secondFactorTrusts` | holder `ALL` (`trust()` on the way in; `capLiveUntil()` and `deleteAll()` for the administrator's term and the switch-off; the merge's and the erasure's `deleteForUser()`); agent `Update`/`Remove` (`deleteForUser()`, `deleteForUserExceptSession()`, `deleteForPair()`) | the holder, on the way in — this stays | the agent for every later write; the creation on the way in gives the holder no right to extend or delete an existing row. The installation-wide `capLiveUntil()` and `deleteAll()` become hand-overs to each personal owner, not a hidden second writer | the term and the switch-off as a hand-over per person; the merge's and the erasure's `deleteForUser()` | the holder keeps `Add` beside the agent's set claim — a declared shape, not a collision. Price: the highest of the map — the administrator's term reaches every person with a trust row through its agent (a sweep that finds them and hands each a frame; an agent that is asleep applies it at start), where today it is one statement |
| `stepUps` | agent `ALL` (`record()`, `deleteExpiredForUser()`); holder `Remove` in the erasure | the agent | the agent, its own confirmation, its extension, its spending and clearing, and the erasure included | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion |
| `legalAcceptances` | users library `ALL` (`accept()`; the test-only `forgetLaterThan()` of `test:legal:hold`); holder `Remove` in the erasure | users library | the library creates an immutable acceptance; the personal owner of the set deletes, in the erasure. Even `test:legal:hold` passes the owner — not a reason to break the immutability of recorded acceptances or to erase another person's consent | the erasure's `deleteForUser()`; the test command's forget as a frame to the agent | the legal agent reads these rows for its exports, and the erasure's forget frame to it stays. Price: one more hop in a test path |
| `notifications` | notifications library `Add`; agent `Update`/`Remove` (`markAllReadForUser()`, one statement over its set); holder `Remove` in the erasure; `NotificationTestSeedCommand`, test-only | notifications library | the library creates, the agent edits and deletes | the erasure's `deleteForUser()` | the deliveries point softly at the notification and are cut by it: before the agent deletes the notifications, the obligation of each channel over its soft delivery links is recorded, so no recipient is lost once the notification is gone. Price: one recorded obligation per channel ahead of this step, and one more ordering constraint in the erasure |
| `notificationDeliveries` | notifications library `Add` (dispatch) and `Remove` (`DeliveryLogPruner`, raw batched `DELETE`), and `resetForRetry()` on `requeue()`; channel agent `Update` (status, attempts, error of the attempt it runs; `adoptPendingRows()` after start or freeze); holder `Remove` in the erasure (`deleteForRecipient()`); `RestoredDeliveries::settlePending()`, a raw `UPDATE` at restore with no agent; `NotificationTestSeedCommand`, test-only | notifications library | the library creates; from then on the single channel, or shard of a channel, executes, changes status, retries and deletes its own rows. The library keeps no `resetForRetry()` and no prune; the holder keeps no erasure `Remove`. A switched-off channel keeps its journal and its retention, its agent stays available for retention and erasure, and no shared sweeper is introduced (question 4) | retry, prune, the erasure's `deleteForRecipient()` | the present whole-table `Update` of every channel is no proof that two channels never meet on a row: the research describes a stable split by channel and shard (`AbstractDeliveryChannel::shardKeyFor()`, the recipient by default) and a future guard that checks it — as a check of the operation, not by re-cutting the Entity's `_setVia` away from the person's axis; when the pool changes, no row ever gets two writers. A delivery whose notification is gone is in nobody's set, so the recipient is recorded in the channel's obligation first. Price: a retention and an erasure portion per channel; a narrowing of the channel's claim without a new width; the restore path's settle stays a raw write in a process with no agent and is named here rather than hidden |
| `notificationPreferences` | agent `ALL` (`setChannel()`); holder `Remove` in the erasure | the agent (a row is born only by the person's own choice) | the agent creates its own setting and writes and deletes it | the erasure's `deleteForUser()` | none. Price: inside the agent's erasure portion |
| `pushSubscriptions` | notifications library `Add` and `Update` (`subscribe()` of a device, `markGone()` on the transport's word); agent `Update`/`Remove` (`unsubscribeOwned()`, `removeOwned()`); holder `Remove` in the erasure | notifications library (a new subscription) | the library creates a new subscription; thereafter the agent — a device subscribing again and the transport's report of a dead endpoint go to it, not to a library `Update` | `subscribe()` of an existing endpoint, `markGone()`, the erasure's `deleteForUser()` | the subscription row names its person, so the owner is known from the row; the push channel reads the devices and stays a reader. Price: the upsert of today splits into creation at the library and an edit at the agent; a dead endpoint becomes a frame per recipient |
| `dataExports` | export agent `ALL` (`order()`, build, publish, forget); holder `Remove` in the erasure (`ofUser()->delete()`), then the post-commit `DataExportNotifier::forgetUser()` | export agent | the existing export executor, the cancellation and clean-up of the erased person's DB order included; the holder only hands over | the erasure's delete of the order; the forget frame becomes the obligation | the builder's check of the retained completed-erasure row stays ([data-export.md](data-export.md)). Price: the executor is a participant with a recorded result instead of a borrowed delete plus a fire-and-forget frame |
| `legalAcceptanceExports` | legal agent `ALL` (`order()` and the build); holder `Remove` in the erasure (`ofUser()->delete()`), then the post-commit `LegalAcceptancesExportNotifier::forgetUser()` | legal agent | the existing legal export executor, the orders to clean included; the clean-up of shared files touched by the person's acceptance — every ready and failed export removed with its file, the one being built started again — is not lost and not declared implemented by the new mechanism | the erasure's delete of the order; the forget frame becomes the obligation | a project may register no legal agent: the participant list says whether the table was never activated or holds data with no owner. Price: a participant with a recorded result; the file removal stays post-commit |
| `userPhotos` | agent `ALL` (`put()`, `deleteForUser()`); holder `Remove` in the erasure, the file id joining the post-commit removal | the agent (born of the first photo) | the agent writes and deletes its row; the files library owns the registry row, not the photo row; no direct unlink of the disk inside the personal transaction | the erasure's `deleteForUser()` | the file id goes to `Hilos::$files->remove()` after the commit, as today ([files-registry.md](files-registry.md#removing)). Price: the file removal stays a post-commit step without automatic retry |
| `userRenames` | agent `Add` (with the name, `renamePerson()`); users library `Update` (the feed link written by the chat's `afterUserRenamed()`); `ChatAgent` `Update`, borrowed (`unlinkEvents()` on a cleared history); holder `Remove` in the erasure (`deleteByUser()`; rows where the person was only the author stay, their author set null) | the agent | the agent of the renamed person, the link and unlink of the room event and the deletion of its journal included. The users library and `ChatAgent` hand it the change and do not write | the feed link after the event is written; the unlink before the room's history goes; the erasure's `deleteByUser()` | `UserRename.event_id` is a unique key onto the room's event, and the chat reads the ids off the journal before deleting the events: the links are kept or handed over first and the unlink obtained before the events go — the key is `SET NULL` and would only clear the link in the database, behind the memory of the journal's readers, which is why the chat clears it through the object today — not a foreign `Update` around the key, and not a journal deleted before the only event ids were read. Price: two hand-overs between `ChatAgent` and the agent and an ordering obligation in the erasure |
| `accountDeletions` | users library `Add` (`request()`, the admin card's `scheduleFor()`); agent `Update`/`Remove` (`cancel()`, conditional); holder `Update` (`complete()`, conditional) | users library | the library creates; the agent cancels, starts and finishes. The conditional race of cancel against due stands, and "completed" is written only after the end; the trace of the request after the person is gone is allowed | the erasure's `complete()` | the started phase is a logical record the Entity does not carry today (its leaf decides the schema); the minute sweep that finds due requests stays where sign-in exists and hands the start to the agent instead of erasing. Price: one conditional write more per request, and the request's owner runs on in a finishing mode |
| `users` | users library `Add` (registration); agent `Update` by row (name, flag, block, theme, time zone); holder `Add`, `Update`, `Remove` (the first administrator, the loser's block, the erased row); the chat's `Hilos::OWNS_DB`, laid by `TestOnlyCommand` for its seed commands, test-only | users library; the holder for the first administrator | the library creates — the first administrator comes through this path too; the agent changes and deletes its own row in the terminal operation (the loser's block, the erased row's final delete). The holder coordinates and is not a second `Update` or `Remove` | the first administrator's mint, the loser's `setBlock()`, the erasure's `delete()` | every child with a hard key is gone before the row can go, and the row's deletion is what stops the agent today: the stop must follow the agent's own last write. Price: the agent's row claim gains `Remove` for one operation, and a finishing mode after the account is closed |
| `verifications` | users library `ALL` (issue, spend, sweep; `deleteForUserOfType()` on a password change); holder `Remove` in the erasure (`deleteForIdentifiers()` on the current addresses, `deleteForUser()`) | users library | the users library stays the only owner — codes are outside the person's set (P-163) — and receives an obligation to erase by user id and by the saved current addresses. Runtime send attempts are not in this census | the erasure's `deleteForIdentifiers()` and `deleteForUser()` | the addresses are captured before the identities go, as `erasePerson()` does today, and travel in the obligation. Price: one obligation and one result at the library per erased account |
| `ChatDbContext::eventMessages` | `ChatAgent` by kind (`addMessage()` from the room page in its process, `deleteAll()`); chat holder `Update`/`Remove`, borrowed (`rePointAuthor()` in the merge, `deleteByAuthor()` in the erasure) | `ChatAgent` | `ChatAgent` itself moves the author or deletes the erased person's messages of every room in one transaction of its own, on the signal | `rePointAuthor()`, `deleteByAuthor()` | the attachments' file ids go to the files path after the commit. Price: one obligation and one result at `ChatAgent` per operation |
| `ChatDbContext::eventAttachments` | `ChatAgent` by kind (created with the message); chat holder `Remove`, borrowed (`deleteForMessages()`) | `ChatAgent` | `ChatAgent` deletes the DB links with the messages and hands the file ids to the current files path after the commit | `deleteForMessages()` | none beyond the messages' portion. Price: the file removal stays post-commit, with no new guarantee |
| `ChatDbContext::events` | `ChatAgent` by kind (started, stopped, cleared, messages; `deleteAll()`, `deleteByIds()`); chat users library by kind (`addUserRegistered()`, `addUserRenamed()` — a second creator the first census did not list, present at the base too); chat holder `Remove`, borrowed (`deleteByIds()` of message, registration and feed events) | `ChatAgent`; the chat users library for the registered line and the feed line | `ChatAgent` deletes the events of its messages, registrations and rename feed with their links; the chat users library's creation of feed lines stays creation alone and grows no edit; the personal rename journal is not written here | `deleteByIds()` in the erasure | the event ids are read off the journal before the deletion and the unlink is obtained from the person's agent. Price: the ordering with the rename journal, above |
| `ChatDbContext::eventUserRegistrations` | `ChatAgent` by kind; chat users library by kind (created at registration); chat holder `Remove`, borrowed (`deleteByTarget()`) | the chat users library | `ChatAgent` deletes its registration events of the erased person | `deleteByTarget()` | none. Price: inside `ChatAgent`'s portion |

**Outside the 24.** These are not among the "20 tables others own", and the
protocol must not lose them at its boundary or when control of the person row
moves.

| Collection or record | Writes today | Target |
|---|---|---|
| `sessions` | holder, whole: the anonymous row on the handshake, the bind at sign-in, the revert at sign-out, `killUserSessions()` in the merge and the erasure, the blocked notices released, the sweep `hilos_sessions_expire`; `SessionTestExpireCommand`, test-only | the holder, as today — a session has no instance owner ([Session Ownership](entity-libraries.md#session-ownership), HIL-1403). In the protocol the holder is a participant for its own rows: it releases every reference of a session to the person — the four `RESTRICT` columns, the waits, the cards — as its own step with a recorded result, before the person row can go |
| `accessLogEntries` | holder `ALL`: `record()`, the hourly sweep, `rePointToUser()` in the merge, `deleteForUser()` in the erasure | the same owner; it moves and deletes its own rows itself as a participant, on the signal |
| `userMerges` | holder `ALL`: `add()` in `foldAccount()`, `deleteForUser()` in the erasure | changes and deletion go with the personal life cycle to the personal owner: the loser's agent writes its own tombstone with its block in the transaction that gives its rows away, and the erasure of a folded account deletes its merge row with its own rows, leaves before survivors so the survivor's `SET NULL` does not run |
| the operation record | does not exist; `AccountDeletion` and `UserMerge` carry a trace and a tombstone, no started phase, no set of confirmations, no stored result | the coordinator's — the sessions holder for authorization and the course of the operation — and its single changing writer: the kind, the immutable ids and circle, the chosen password and second-factor fates, the stage and the expected participants. A working record of the holder, not a claim over anybody's business table |
| the participants' preparations and results | do not exist | each participant's own, written in the same transaction as its work; the coordinator may create the initial assignment and never edits a recorded result. A durable record in `Hilos::$db` through the ordinary collections and actions, not the agent's memory and not a runtime overlay — it has to survive a restart and a restored backup. A result that carries rows given away — the merge's hand-over copy at the loser — is personal data with a bounded life: kept until the survivor's confirmation, then deleted by its one writer |

**Deltas against the first census.** The census of the research base listed
the four chat tables as "`ChatAgent` owns, the holder borrows"; the chat users
library's creator claim on `events` and `eventUserRegistrations` was not in it
and is in the map now. The raw `UPDATE` of the restore path over the delivery
journal and the test-only writers (`UserTestSeedCommand`,
`NotificationTestSeedCommand`, `SessionTestExpireCommand`, the chat's
`Hilos::OWNS_DB` under `TestOnlyCommand`) were not in it either; they write
with the daemon down or in a test process and stand outside the protocol, and
they are named so that the map is not read as complete without them. The
commits between the research base and the head this page was written at
touched none of the claims or call sites above.

## Recovery

Recovery is what makes the protocol safe to run without one transaction: at
every point below something has committed and something has not, and the table
says who finds the remainder and why doing it again cannot harm. Four rules
hold the table together.

- **Unfinished work is selected from the facts.** An owner that starts — after
  a stop, a death, an idle sleep or a move to another node — reads its own
  records and the assignments addressed to it and finishes what is open; the
  coordinator reads its operation records and asks again for every result it
  does not hold. No cursor, no position in a walk and no memory of a class
  survives, and none is added
  ([After A Failure](../signals/work-in-portions.md#after-a-failure)). The
  read happens at start and when a frame of the operation arrives, not on
  every tick: the database is not polled for ordinary reading.
- **The initiative is the coordinator's record and the owner's start, not the
  next frame.** A signal hastens; it is not what makes the work owed. The
  coordinator's record says which results are missing and the holder asks for
  them again when it starts and when it passes over its open records; a
  participant's start finds its assignments. "Another frame will come
  eventually" is not a mechanism.
- **A participant's atomic result is not exactly-once for the operation.** Each
  step is done once or not at all at its owner; the operation as a whole may
  ask twice, hear nothing or hear late, and every step is written so that the
  second ask answers with the stored result. The result and the clean-up of a
  hand-over copy have one writer each, and a repeat of either is the same
  answer again.
- **A closed account stays closed.** No internal continuation, no late frame
  and no misaddressed answer opens the ordinary actions of an account whose
  start fact is recorded: the finishing mode takes the continuation of its own
  operation and refuses the rest in today's words.

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
| The survivor applied the rows and died before confirming, or an old frame arrives again | the survivor's own writes — the wait, the trusts — with its confirmation in one transaction, or none of them | the survivor, at start, reads its set and the obligation; the loser's agent sends again until the confirmation is recorded | the wait is a value and is written again the same; the confirmation names the operation and stage, so a frame already confirmed answers with it, a frame of a closed operation is refused, and a late frame rolls no newer state back | the restriction holds until the confirmation, then lifts |
| An owner dies, stops idle or moves to another node in the middle | its results so far; its open assignments; its grants gone while it is down ([agent-lifecycle.md](agent-lifecycle.md#when-a-worker-dies)) | the owner, placed and started again by the next frame or by the coordinator's ask, on whichever node the placement names; it reads its records in its start and finishes | the address in the record is the agent's type and index, not a process or a node, so the new incarnation is the same owner; the old copy is stopped before the new one starts ([daemon-lifecycle.md](daemon-lifecycle.md#node-health-and-failover-hil-183)) | a longer wait; the operation stays open and names the participant |
| A required owner is missing: a table with rows and no agent registered for it | the rows; the assignment the coordinator wrote; no result | nobody, until the project registers the owner again; there is no fallback writer, and taking away the last owner of rows that stay is a separate decision (question 4), not an administrator's act and not a way to close the obligation | no fallback writer takes the rows, so no row gets two writers and nothing is deleted by a process that does not own it (question 4) | an explicit open obligation, *waiting for an owner of …*; the account stays closed |
| A child with a hard key was forgotten and no participant deleted it | the child row; the agent's final transaction rolled back, the person row and the journal still there | the agent, which retries the final step once the owner of the row has been added to the participants | `RESTRICT` refused the delete and the agent's transaction rolled back, so the request is not marked carried out and nothing is half-deleted | the operation stays open at the final step with the refusal named; no account comes back |
| A soft reference stayed behind: a delivery whose notification is gone, a mention set null | the delivery rows under the channel's assignment; the rows meant to stay — the request, a rename whose author is set null, a merge whose survivor is set null | the channel, from the recipient and the ids in its assignment, recorded before the notifications went | a delete by recorded ids is the same delete twice; the rows meant to stay are part of no obligation | the channel is listed as expected until its result; the trace rows carry the deleted person's number, as the journal's receipts already do ([change-log.md](change-log.md#one-scheduled-attempt-across-parts)) |
| The channel is switched off | its journal rows and its retention term; the assignment to delete the erased recipient's rows | the channel's own agent, which stays available for retention and erasure (question 4); the dispatcher only stops creating new deliveries | switching off writes nothing and deletes nothing; the agent's pick-up of pending rows at start skips a recipient whose erasure has started and sends nothing to a closed account | the channel is still a participant and completes; history and term unchanged |
| A backup is restored with an operation unfinished | whatever had committed when the archive was taken: a started fact, some results, a tombstone with its copy, rows half moved | the coordinator and every owner, at their start after the restore, exactly as after a death: open records are selected from the facts and finished | every step repeats from a recorded result or a conditional write; the archive carries the records because they are rows — which is why they are rows | the account is as closed as it was at the archive's moment, and the operation finishes; a restore into a lesser environment classifies the records with the rest ([backup-anonymization.md](backup-anonymization.md#core-rule)) |

The restore path has one raw write today, `RestoredDeliveries::settlePending()`,
in a process with no agent: it settles pending deliveries as failed and is named
in the *Writer Map*. Under the protocol an erased recipient's rows are the
channel's obligation whatever that settle did, and the settle is no step of the
operation.

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

**The price, per piece.** Named in local transactions, confirmations and
messages, copies kept and the start of each owner; no measurement and no
money, because none was taken.

| Piece | Local transactions | Confirmations and messages | Copies | At start |
|---|---|---|---|---|
| The durable operation and the obligations | one write of the record per stage change at the coordinator; one assignment per participant | one ask and one answer per participant, repeated after a failure | none | the coordinator reads its open records; every participant reads its open assignments |
| The erasure of one account | one per participant, where today there is one in all; two at the agent, the start and the final delete | one round per participant, plus the captures before the anchors; a circle multiplies by its accounts | the preparations: addresses and ids | each agent of the circle raised in finishing mode once |
| The merge | one preparation each side, one fact at the loser, one confirmation at the survivor, one per other owner | prepare, go, the frame with the rows, the confirmation, the end | the hand-over copy, until the confirmation | both agents read their sets and obligations when they start |
| The channel's retention and erasure | one per channel per operation; one per prune at the channel | one per channel | none | the channel's pick-up at start reads its open assignments beside the pending rows |
| The trust term and the switch-off | one per person with a trust row, where today there is one statement — the highest price in the *Writer Map* | one frame per person, repeated for an agent that is asleep at its start | none | the agent applies the term it finds at start |
| The strict guard | none at run time beyond the check at the door | none | none | the topology and the door refuse what they refuse today, plus the operation's check |

The price the owner weighed and took is the first row: a durable record and a
round of confirmations where today one `COMMIT` ends everything. The other rows
follow from it.

## Cutting Inputs

The boundaries below are the semantic pieces the owner may cut HIL-1674 into
at an interview; this page proposes no ticket, names no key and edits no epic.
Each piece rests on an answer already given, has an input and an output result,
leans on contracts the tree already has, and can be checked on its own. Units
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
   `ChatAgent`'s borrowed `Update` on the journal. Output: `ChatAgent` as a
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

Pieces 1 and 2 come first by dependency; 3 and 4 can run beside them; 5, 6 and
7 need 1; 8 follows every real move and is no leaf of its own before them. The
owner cuts; this is the input to that cut.
