# Instance Owners

Read this before adding an entity whose content several surfaces or people edit,
writing an edit into one person's content (or any instance with an owner), or
giving a library another write into one instance.

Three figures stand beside each other: the [library](entity-libraries.md) answers
for the entity's set, the instance owner writes one instance's content, and the
[table agent](table-agents.md) holds the surface a viewer reads over either one.
This document specifies the approach, not a description of code already built.
An unbuilt part carries the leaf that lands it; the ownership mechanism itself
is in [truth-source.md](truth-source.md).

## Core Rule

A top-level entity around which many interactions gather gets an agent per
instance. That agent is the sole writer of ordinary content edits made by the
person or an administrator: its own row and every row whose set tree ends at
that instance. For a person, the indexed agent, its row and set claims, and
its idle lifetime are built (HIL-630). The name, the administrator flag and the
block are written by it (HIL-1404), and so are the edits of the person's sign-in
methods and passkey credentials, sign-in included (HIL-1405), of the person's
second factor, a removal whose delay elapsed included (HIL-1406), the
person's step-up confirmations and the trust taken from the person's browsers
(HIL-1407), the notification marks, channel preferences and push unsubscribe
(HIL-1408), and calling off a deletion request and the profile photo
(HIL-1409).

Choose both the creator and the single writer after creation before adding a
DB write path. The library may create an entity with all its children; later
`Update` and `Remove` belong to the row's owner, including a sweep's write.
An executor keeps its working rows as described below. A partial claim gives
no second writer permission. Until each named move lands, the current writers
and their required grants remain; a marker makes no future door callable.

## Does A New Entity Need One

Ask both questions; both answers must be yes:

1. **Is it the root of a set tree, itself in nobody's set?** Its Entity declares
   `_setVia = Entity::SET_STANDALONE` and `_setRoot = true`. See
   [Whose set the table is part of](../orm/entity.md#whose-set-the-table-is-part-of).
2. **Does more than one surface or actor edit its content?** The person, an
   administrator and background work may all reach the same instance. Count
   independent content edits, not every internal step of the one writer.

The current roots give these answers:

| Root | Decision | Why |
|---|---|---|
| Person — `framework/backend/Database/Entity/Item/User.php` | Yes (agent figure built in HIL-630) | The person's own and administrative edits converge on the same row and child sets. |
| File — `framework/backend/Database/Entity/Item/File.php` | No | The [files registry](files-registry.md) has one owner of the table. |
| Chat bot — `demo/chat/backend/Database/Entity/Item/Bot.php` | No | One screen edits it through the library. `BotAgent` is indexed by bot but owns only its own RT status row (`BotAgent::OWNS_RT_ROWS`); it reads the bot's DB row. |
| Chat room — `demo/chat/backend/Database/Entity/Item/Event.php` | Candidate, not decided | Apply the same test. There is no leaf assigning it an instance owner; `ChatAgent` holds the room tables whole today. |

An indexed agent is not by that fact an instance owner: the bot is the worked
counterexample. This decision needs judgment and has no guard. A source scan
can find the two Entity constants, but cannot decide the second question.

## What The Owner Writes, And What It Does Not

The owner writes ordinary edits of one instance's content, whether the person
or an administrator requested them. For the person the name, the administrator
flag and the block have moved (HIL-1404), and so have the sign-in methods and
passkey credentials (HIL-1405), the second factor (HIL-1406), the step-up
confirmations and the trust taken from the person's browsers (HIL-1407), the
notification marks, channel preferences and push unsubscribe (HIL-1408), calling
off a deletion request and the profile photo (HIL-1409), and the theme choice
(HIL-1427).

The library reads the set, creates new members and coordinates work on members
it does not own. Finding an expired row does not make it the writer of that
row. A library with no personal owner beside it may itself be the only writer,
as with `settings`; see [entity-libraries.md](entity-libraries.md).

The current set guard grants a cross-set move only to a whole-table claimant,
because one set claim cannot cover both origin and destination. This is the
mechanism today, not a license to add such a claimant. The agreed terminal
direction is each owner deleting its own rows in its own transaction, and
the loser giving rows away under a narrow
[operation right](account-operations.md#the-transfer-right), judged against
the operation and both preparations without a new claim width or `_setVia`
change (not in the code yet — HIL-1991). The old erasure
remains live until HIL-1952 (not in the code yet — HIL-1952), and the old merge
until HIL-1999 (not in the code yet — HIL-1999); preparation stays inert.

**Executors keep their working rows.** A delivery status, a personal data export
and an administrator's export of acceptances stay with the executor that writes
them. These are their own writing responsibilities, with one writer for the
work; belonging to a person's data does not assign the same row two owners:

- `AbstractDeliveryChannelAgent` edits the delivery attempt it is running
  (`framework/backend/Notification/Delivery/AbstractDeliveryChannelAgent.php`).
- `AbstractDataExportAgent` owns personal data copies
  (`framework/backend/DataExport/AbstractDataExportAgent.php`).
- `AbstractHilosLegalAgent` owns administrators' exports of acceptance records
  (`framework/backend/Core/Agent/Hilos/AbstractHilosLegalAgent.php`).

Do not raise the recipient's agent for each delivery-status change. Belonging
to a person's data does not turn an executor's bookkeeping into a content edit.
Erasing that data with the account is a separate set operation, described below.

A sweeper may find what is due itself; an ordinary content edit still goes to
the instance owner. In particular, the second-factor reset sweeper
(`framework/backend/Auth/SecondFactor/SecondFactorResetSweeper.php`) only finds:
it hands a reset whose delay has elapsed, and one owing its daily reminder, to the
person's agent as a frame (HIL-1406). The agent writes both by conditional writes
- the reset marked carried out and the factor taken out in one transaction, the
reminder marked only once a day - so a frame sent again by the next tick does
nothing, and the library mails on the agent's answer.

The sessions holder's responsibility is authorization. The person's other
ordinary content edits go to the person's own agent, with erasure and merge
remaining the declared set operations below (owner's decision, 2026-10-04). It
judges the administrator flag and the block and the person's agent writes them
(HIL-1404). One person's step-up confirmation and one person's browser trust
taken away are the person's agent's too (HIL-1407): the holder hands the data-copy
credit of a refused blocked sign-in and the trust of the sessions it ended to the
agent by frame, and the agent takes the trust away itself with the second factor,
a changed or recovered password and the block. The holder currently creates
or extends trust on the way in and applies the term to every person's trust at
once.
Creation stays there; renewal of an existing pair moves to the person
(not in the code yet — HIL-1907). A saved policy is retained for application as
a trust restriction (HIL-1900), which the personal owner applies on a service
frame; missed work is found by the sessions holder's sweep, which sends that
frame, and by the owner's own start, which applies the saved restrictions
without one (HIL-1901). The personal owner applies the cap
(not in the code yet — HIL-1908) and switch-off removal
(not in the code yet — HIL-1909). Erasure and merge remain the terminal debts
below. Notification marks, channel preferences and push unsubscribe are the
person's agent's too (HIL-1408).
A session has no instance owner today: it stays standalone and the sessions
holder, whose responsibility is authorization, stays its writer; an agent per
session keyed by the stable `session.id` is an acceptable later design and
not a ruled-out one (owner's decision, 2026-10-11, HIL-1403). The decision
neither puts the session in the person's set nor changes the Entity
declaration; the two shapes, the reason and the price of each are in
[Session Ownership](entity-libraries.md#session-ownership).

## How It Is Declared

The person's agent declares the index, idle window, row claim and child-set
claims (HIL-630), and takes the frames of the edits that have moved to it
(HIL-1404). Pages addressed to it remain later work:

- The agent index is the instance id. The first frame addressed to it raises
  it, and `AgentRegistryKey::IDLE_TIMEOUT` lets it stop when idle. Follow
  [Idle stop](agent-lifecycle.md#idle-stop-an-agent-that-lives-as-long-as-it-is-spoken-to):
  the idle window, no live subscriber and no work in flight all matter.
- Address signals by that index using
  [Indexed agent signals](../signals/routing.md#indexed-agent-signals).
- A page served by the owner names `SUBSCRIPTION_AGENT_INDEX`; see
  [Per-instance page subscriptions](../signals/routing.md#per-instance-page-subscriptions).
- Claim the person's own row by key in `OWNS_DB_ROWS`. `hilos_user` declares
  `SET_STANDALONE`, so it offers no set by which to claim that row.
- Claim the child set in `OWNS_DB_SET`, with its key returned by
  `ownedDbSetKey()`, **without `Add`**. That is a borrowed claim beside the
  libraries that create the rows. The exact widths, operations, reads and
  startup refusals belong to [A Claim Over A Set](truth-source.md#a-claim-over-a-set).
  The one exception is a row the owner writes in the same transaction as its
  own row: the person's rename journal row is added with the name, so the agent
  claims that set with `Add`; it also claims `Update` to link the live chat feed
  event on a project frame (HIL-1404, HIL-1896). Clearing chat history obtains
  that owner's confirmed unlink before deleting the events.
  The person's one row of second-factor settings (`hilos_second_factor_setting`,
  keyed by the person) is born by the owner's first edit of it — a wait chosen, a
  wrong code counted — so the agent claims that set with `Add` and `Update`
  (owner's decision, 2026-10-09, HIL-1406). It is a one-to-one extension of the
  person's own row, not a new member of the set; where a set holds many rows, the
  library still creates them - with two exceptions. A step-up confirmation is the
  mark of the person's own proof in one browser: the owner inserts or extends it
  and clears the person's expired ones in one write, so it claims that set whole
  (owner's decision, 2026-10-09, HIL-1407). A row that says a channel is off is
  born only by the person's own choice, so the notification preferences are
  claimed whole, creation included (owner's decision, 2026-10-10, HIL-1408).
  The person's one photo row (`hilos_user_photo`, keyed by the person) is born
  the same way, by the first photo the person sets, so the agent claims that set
  whole (owner's decision, 2026-10-10, HIL-1409). A deletion request is a member
  of a set of many — the history of a person's requests — so the users library
  still creates it.

Raising the instance owner is cheap enough to use as the write path. Do not
bypass the hop by writing one person's content from the library to avoid
starting an agent (owner's decision, 2026-10-04): a sign-in after an idle spell
waits for the person's agent to rise when it has something to write (HIL-1405).

An edit that moved travels in one shape, and the next ones follow it (HIL-1404):

- **The coordinator judges, the owner writes, the coordinator finishes.** The
  coordinator is whoever judged the edit before it moved — the users library
  for the name, the sessions holder for the administrator flag and the block. It
  checks exactly what it checked before and refuses at once, without raising the
  agent. A check that reads what the hop writes is the owner's, made in the turn
  that writes: proving a second-factor code takes a step, burns a code or counts
  a miss, and "is this the last app" is asked where the app is deleted (HIL-1406). What passes becomes a frame to the agent; the agent writes and answers;
  the coordinator does what follows the write — tells the tabs, ends the
  sessions, tells the renamed person, runs the project's hook, binds the session
  of `admin:create` — and answers whoever waits. What follows reads the written
  row, so it can only come after the answer.
- **The frame carries everything the coordinator needs to finish**, and the
  agent sends it back untouched inside its answer, so the coordinator holds no
  state between the hops and a restart in between loses no addressee. The
  frames to the agent are `hilos_user_rename`, `hilos_user_admin_write`,
  `hilos_user_admin_command` and `hilos_user_block_write` (HIL-1404), and for the
  sign-in methods and passkeys `hilos_user_password_rehash`,
  `hilos_user_address_verify`, `hilos_user_passkey_use`,
  `hilos_user_password_reset`, `hilos_user_password_change`,
  `hilos_user_email_change` and `hilos_user_identity_unlink` (HIL-1405), and for
  the second factor `hilos_user_second_factor_prove`,
  `hilos_user_second_factor_enroll_confirm`, `hilos_user_second_factor_remove`,
  `hilos_user_second_factor_reset_cancel`, `hilos_user_second_factor_wait_write`,
  `hilos_user_second_factor_reset_due`, `hilos_user_second_factor_reset_remind`
  and `hilos_user_second_factor_unlock` (HIL-1406), and for the step-up
  confirmations and the browser trust `hilos_user_step_up_record` from the users
  library, `hilos_user_step_up_credit` and `hilos_user_browser_trust_revoke` from
  the sessions holder (HIL-1407), and from the users library
  `hilos_user_account_deletion_cancel`, `hilos_user_photo_put` and
  `hilos_user_photo_remove` (HIL-1409), each indexed by `userId`; each has a
  `_done` answer declared on its coordinator. A proof that is itself the owner's
  write carries the confirmation in the same frame: `hilos_user_passkey_use` and
  `hilos_user_second_factor_prove` name the operation and the browser's token
  hash, and the agent records the confirmation in the turn that writes the proof
  (HIL-1407). A frame the coordinator does not wait on - the credit, the trust of
  ended sessions - is a plain one: its answer is only logged on a refusal.
- **A browser action that ends after the agent's answer is resumed by the
  coordinator** (HIL-1405). The coordinator defers the action's answer when it
  sends the ask; on the answer it takes the action name and the request id back
  out of the ask, puts the asking connection back on the execution frame, runs
  its continuation, and answers the browser as the dispatcher would — nothing
  when the continuation handed the answer on (a sign-in hands it to the
  sessions holder), the success ack otherwise - carrying the reply the
  continuation returned, such as new backup codes (HIL-1406) - a failure for a
  refusal. The continuation reads the session again off the connection; no token
  rides in the ask. Setting a photo does not resume a browser action:
  `hilos_profile_photo_set` is answered when the upload is submitted (HIL-1409).
- **A secret travels as its hash.** A new password is hashed in the process the
  browser sent it to, and the frame carries the hash; the password itself never
  crosses a process (HIL-1405).
- **A frame born of a press in a browser is a handover ask**
  (`HandoverAskInterface`), so the agent's write is stamped with whoever pressed
  the button. An operator's command has no connection to stamp, and its frame is
  a plain one carrying the command's correlation id.
- **The agent always answers**, a refusal and a wiring refusal included: the
  coordinator continues only on the answer, and a card or a parked command is
  waiting on it.
- **Before the hop, `AddressablePerson::require`.** A missing person and a
  folded account are refused in the words they were refused in before, and the
  agent is not raised. The agent repeats the same check for the race in which
  the hop left before the erasure or the merge arrived.
- **The test-only legal hold is the only exception to that gate.** Its frame
  lets the personal owner remove retained acceptance rows for a folded account
  without `AddressablePerson::require`; the agent stops after answering that
  folded account's frame. This is no general bypass for folded accounts.
- **A check the hop splits is held across it.** The sessions holder counts a
  removal of rights, or a block of an administrator, that it has sent and the
  agent has not yet answered as done, so two removals crossing each other cannot
  both pass "the last active administrator". The mark lives in the holder's
  memory and is conservative when lost: it can only refuse a removal that would
  have left one. A passkey's counter is the same case (HIL-1405): the users
  library checks the assertion and the counter, and the agent checks the counter
  again against the stored one as it writes, so of two assertions a cloned key
  sends with one counter only the first is recorded.
- **Creation writes the row whole.** A library that may only create writes a new
  row with everything it is born with — a password's secret and its verified
  mark, a passkey's first counter — and edits nothing afterwards: the secret,
  which no insert can carry, is written right after the insert and judged as the
  creation it completes (HIL-1405). It does not cover deleting an earlier
  enrollment or backup-code set, extending an existing trust pair, or
  upserting a known push endpoint. Those are changes to already-owned rows,
  with the [ordinary moves](account-operations.md#ordinary-transitions)
  addressed separately.

## Memory

In phase 2 the person's agent does not keep its set in memory: it reads on
demand. Its row and set claims are built (HIL-630).

The target is for the owner to hold the set in memory and serve as the reader's
source of truth, with the profile served by its owner's agent. That memory and
profile move belong to a separate leaf
(not in the code yet — HIL-1281).

## Operations Over Many Instances

The following are today's retained terminal paths, not a standing exception
for any sweep, erasure or merge:

- **Account erasure:** `eraseAccount()` and `applyAccountErasure()` run one
  transaction at the sessions holder over the whole merged circle, including
  chat messages, attachments, events and registration events. The due sweep
  and `test:account:force-purge` remain its live producers until the switch to
  owner-local work (not in the code yet — HIL-1952).
- **Account merge:** `mergeAccounts()` and `applyAccountMerge()` run one
  transaction there too. Ways in, second factor, chat authorship, the loser's
  block and the tombstone keep their current writers until the merge cutover
  (not in the code yet — HIL-1999).
- **Stops:** today a deleted person row or a merge row stops the affected
  personal agent; an agent not running does not rise, and the survivor is not
  stopped. Erasure moves this stop after the owner's confirmed part at its
  cutover (not in the code yet — HIL-1952). Merge prepares a loser stop only
  after its copy is cleaned following receipt (not in the code yet — HIL-1998).
  The old immediate stop is removed only at the merge cutover
  (not in the code yet — HIL-1999).

Ordinary cross-owner writes are separate, finite transitions. The chat agent's
history-clear request calls `unlinkEventsOf()` through the journal's personal
owner, whose answer precedes event deletion.
The users library hands its journal link to that same owner after creating the
feed event (HIL-1896). Creating the first administrator moved from
`mintAdminUser()` at the sessions holder to the users library in HIL-1898; the
holder still authorizes and binds the session.
Trust renewal, the administrator's term and switch-off have the ordinary
addresses above. The complete [Writer Map](account-operations.md#writer-map)
and [ordinary transitions](account-operations.md#ordinary-transitions) cover
the other rows and the exact current operations.

The three phases have distinct end points: ordinary moves leave only the
named erasure/merge debts; HIL-1952 switches erasure and leaves only merge
debt; HIL-1999 switches merge and removes that remainder. The detailed
[erasure canon](account-operations.md#erasure) records the personal finishing
mode: the same agent, including a folded account's, closes ordinary actions
and late frames, accepts only continuation of its own operation and finishes
its own part (not in the code yet — HIL-1939). Its final deletion of the
journal and person and completion of its request follow the required results;
its stop follows its last own write (not in the code yet — HIL-1942).
Assignments and answers belong to one operation and stage
(not in the code yet — HIL-1950), and start/recovery finds open obligations
from DB (not in the code yet — HIL-1951).

The detailed [merge canon](account-operations.md#merge) gives the folded loser
a finishing mode: it accepts only continuation of its own operation, copy
replay and cleanup, and stops after cleanup following receipt. Ordinary access
stays closed and `AddressablePerson::require()` gains no general bypass
(not in the code yet — HIL-1998). Recovery raises it only to complete those
durable obligations, including after a lost frame or a move
(not in the code yet — HIL-2002). This mode replaces the current immediate
stop only at live merge cutover (not in the code yet — HIL-1999).

Preparatory erasure code is inert: a new handler does not authorize finishing
old requests by the new path. Today's stops, producers and necessary rights
remain until live erasure switches (not in the code yet — HIL-1952).

Keep the grants, comments and debt entries needed by the old path until its
actual switch. In the commit that enables a move, its implementing leaf removes
the obsolete foreign right, comment, debt entry and its markers. If erasure
and merge share a right, erasure's switch keeps what merge still needs. Do not
add another foreign writer under the name of a set operation or sweep.

## Anti-Patterns

- **A library writes ordinary content edits of one owned instance.** Send the
  edit to the instance owner. For the name, the administrator flag and the block
  this is done (HIL-1404), for the sign-in methods and passkey credentials
  (HIL-1405), for the second factor (HIL-1406) and for the step-up confirmations
  and the browser trust (HIL-1407), and for the notification marks, channel
  preferences and push unsubscribe (HIL-1408), and for calling off a deletion
  request and the profile photo (HIL-1409).
- **The owner writes only its own row and merely reads its children.** Own the
  child set as well, with the executor exceptions above. Owning the children
  was the owner's explicit decision of 2026-09-17; the person's figure carries
  it (HIL-630).
- **The owner writes every row in its set literally, including executor work.**
  Keep the executor's one writer. Routing each delivery-status change through
  the recipient's agent would raise it for every attempt; that shape was
  rejected on 2026-10-04.
- **A reader warms a projection from the database before each snapshot instead
  of asking the owner.** Avoid making that the ownership model. This workaround
  was accepted but discouraged on HIL-410 (2026-08-20); the profile gets the
  owner-held memory in HIL-1281 (not in the code yet — HIL-1281).
- **One monolithic library holds all entities.** Keep the idea of a library,
  not hleb's monolithic `Library` shape. Follow
  [One Entity, One Library](entity-libraries.md#the-unit-one-entity-one-library).

## Where The Pieces Land

| Leaf | Piece |
|---|---|
| HIL-630 | The person's agent as a figure: its row and set, raised on demand, asleep when idle (built). |
| HIL-1404 | Name, administrator flag and block edits (built). |
| HIL-1405 | Sign-in methods and passkey credentials, including sign-in (built). |
| HIL-1406 | Second factor, including a reset whose delay elapsed (built). |
| HIL-1407 | Step-up confirmations and browser trust taken away (built). |
| HIL-1408 | Notification marks, channel preferences and push unsubscribe (built). |
| HIL-1409 | Account-deletion requests and profile photos (built). |
| HIL-1410 | Erasure and merge stop the affected instance agents (built). |
| HIL-1411 | The people library is the only full owner of codes and registration holds (built). The code agent writes neither table; the sessions holder retains only borrowed `Remove` on codes for account erasure. Verification codes are outside the person's set (P-163, 2026-08-30). |
| HIL-1412 | Cluster behavior of the person's agent (built). |
| HIL-1403 | A session has no instance owner today: standalone, written by the sessions holder; an agent per session keyed by its stable id is acceptable later (decided 2026-10-11, [Session Ownership](entity-libraries.md#session-ownership)). |
| HIL-1281 | Profile on the owner's agent and memory as the reader's truth (not in the code yet — HIL-1281). |

The verification attempt guard is a separate decision after HIL-1411; no leaf
removes it yet. See [verification-codes.md](verification-codes.md). There is no
leaf for a chat room owner either: its candidacy above is not an assignment.

## Related

- [entity-libraries.md](entity-libraries.md) — operations over the entity's set.
- [table-agents.md](table-agents.md) — the reader's surface beside either owner.
- [truth-source.md](truth-source.md) — declarations, widths and write guards.
- [agent-lifecycle.md](agent-lifecycle.md) — startup, idle stop and identity.
- [people-table.md](people-table.md) — the person's own row and account merge.
- [account-deletion.md](account-deletion.md) — the erasure transaction.
- [../orm/entity.md](../orm/entity.md) — whose set a row belongs to.
- [account-operations.md](account-operations.md) — the approved research design
  that splits the erasure and the merge across owners; not code.
