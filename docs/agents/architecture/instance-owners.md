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
methods and passkey credentials, sign-in included (HIL-1405), and of the person's
second factor, a removal whose delay elapsed included (HIL-1406). The other edits
move to it in the leaves named in [Where The Pieces Land](#where-the-pieces-land)
(not in the code yet — HIL-1407, HIL-1408, HIL-1409).

Choose the writer before adding a write path. A new surface does not become
another writer merely because it already runs in a library or a page agent.
Until a named move lands, the existing library remains the writer; a marker
does not make the future agent callable today.

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
passkey credentials (HIL-1405) and the second factor (HIL-1406); the rest are still
ahead (not in the code yet — HIL-1407, HIL-1408, HIL-1409).

The library keeps operations over the set: create, erase, merge, sweep expired
rows and find. See [The Unit: One Entity, One Library](entity-libraries.md#the-unit-one-entity-one-library).
A merge moves rows between sets; the write mechanism grants that move only to
the holder of the whole table, because a claim over one set cannot cover both
the origin and destination of that move. See [A Claim Over A Set](truth-source.md#a-claim-over-a-set).
The sessions holder's existing erasure and merge are the declared shapes below.

**Executors keep their working rows.** A delivery status, a personal data export
and an administrator's export of acceptances stay with the executor that writes
them. These are declared exceptions, with one writer for the work and no race
between content editors:

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
(HIL-1404); the other edits are still ahead
(not in the code yet — HIL-1407, HIL-1408, HIL-1409).
Whether a session itself needs an instance owner is open in HIL-1403. Until
that answer, this rule neither puts the session in the person's set nor rules
it out as a future decision; it does not change the current Entity declaration.

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
  claims that set **with `Add`** and nothing else (HIL-1404).
  The person's one row of second-factor settings (`hilos_second_factor_setting`,
  keyed by the person) is born by the owner's first edit of it — a wait chosen, a
  wrong code counted — so the agent claims that set with `Add` and `Update`
  (owner's decision, 2026-10-09, HIL-1406). It is a one-to-one extension of the
  person's own row, not a new member of the set; where a set holds many rows, the
  library still creates them.

Raising the instance owner is cheap enough to use as the write path. Do not
bypass the hop by writing one person's content from the library to avoid
starting an agent (owner's decision, 2026-10-04): a sign-in after an idle spell
waits for the person's agent to rise when it has something to write (HIL-1405).
The remaining content write paths still move
(not in the code yet — HIL-1407, HIL-1408, HIL-1409).

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
  and `hilos_user_second_factor_unlock` (HIL-1406), each indexed by `userId`; each
  has a `_done` answer declared on its coordinator.
- **A browser action that ends after the agent's answer is resumed by the
  coordinator** (HIL-1405). The coordinator defers the action's answer when it
  sends the ask; on the answer it takes the action name and the request id back
  out of the ask, puts the asking connection back on the execution frame, runs
  its continuation, and answers the browser as the dispatcher would — nothing
  when the continuation handed the answer on (a sign-in hands it to the
  sessions holder), the success ack otherwise - carrying the reply the
  continuation returned, such as new backup codes (HIL-1406) - a failure for a
  refusal. The continuation reads the session again off the connection; no token
  rides in the ask.
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
  creation it completes (HIL-1405).

## Memory

In phase 2 the person's agent does not keep its set in memory: it reads on
demand. Its row and set claims are built (HIL-630).

The target is for the owner to hold the set in memory and serve as the reader's
source of truth, with the profile served by its owner's agent. That memory and
profile move belong to a separate leaf
(not in the code yet — HIL-1281).

## Operations Over Many Instances

These are declared shapes, not debts waiting for an instance owner:

- **Account erasure** is one transaction at the sessions holder over the whole
  circle of merged accounts. It removes the person's rows and child data
  together; see [The Erasure](account-deletion.md#the-erasure) (HIL-302, HIL-1202).
- **Account merge** is one transaction there too: it transfers sign-in methods,
  passkey credentials and the access log, blocks the losing account and records
  the merge. See [A Merged Account](people-table.md#a-merged-account).
- **Chat room rows during a person's erasure or merge** stay in that operation's
  transaction: messages, attachments, events and registration events. Erasure
  must remove the person's messages atomically, whoever owns the room (owner's
  decision at the split, 2026-10-04).
- **Unlinking the rename journal while clearing chat history** is a sweep. The
  chat agent has the borrowed write needed to clear the room reference.

After an erasure or a merge commits, on any node, the agent of each erased
person and the agent of the folded account stop themselves: the people row is
gone, or a merge row has appeared under that account's id. An agent that was
not up does not rise. The survivor's agent is not touched.

Creating the first administrator is creation, so it remains a library operation;
deleting the erased person's row is part of the erasure transaction, and
blocking the losing account is part of the merge transaction. None of them is
an ordinary edit of a living person's content. The administrator flag and block
edits outside those set operations are the person's agent's (HIL-1404). The
sessions holder's claim on `hilos_user` cannot tell the merge's edit from an
ordinary one; that is the accepted price of keeping the merge in one transaction
(owner's decision at the split, 2026-10-04).

The borrowed claims for these operations carry an ordinary code comment: name
the erasure, merge or sweep, say why it writes here, and point to this section.
Do not put `TODO` or a future leaf key on a deliberately retained shape. A real
move keeps a `TODO` naming the leaf that will remove the borrowed write.

## Anti-Patterns

- **A library writes ordinary content edits of one owned instance.** Send the
  edit to the instance owner. For the name, the administrator flag and the block
  this is done (HIL-1404), for the sign-in methods and passkey credentials
  (HIL-1405) and for the second factor (HIL-1406); for the person's other content
  it is the current arrangement the moves replace, not evidence that the rule is
  already implemented (not in the code yet — HIL-1407, HIL-1408, HIL-1409).
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
| HIL-1407 | Step-up confirmations and browser trust (not in the code yet — HIL-1407). |
| HIL-1408 | Notification marks, channel preferences and push unsubscribe (not in the code yet — HIL-1408). |
| HIL-1409 | Account-deletion requests and profile photos (not in the code yet — HIL-1409). |
| HIL-1410 | Erasure and merge stop the affected instance agents (built). |
| HIL-1411 | The code agent and sessions holder stop writing the people library's sign-in tables (not in the code yet — HIL-1411). Verification codes are outside the person's set and belong to that library (P-163, 2026-08-30). |
| HIL-1412 | Cluster behavior of the person's agent (not in the code yet — HIL-1412). |
| HIL-1403 | Decide whether a session has an instance owner; the answer remains open. |
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
