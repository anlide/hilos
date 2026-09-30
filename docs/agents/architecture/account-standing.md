# Account Standing

Read this before reading or changing an account's standing — a block, a
scheduled deletion, a freeze after a lapsed legal acceptance — before closing a
page or an action to a frozen person or opening one as an exit, or before
drawing the standing in the shell or in the admin (HIL-945).

## The Verdict

Three independent facts go in, and one of them is shown:

| Fact | Where it comes from |
|---|---|
| blocked | `hilos_user.block`, an administrator's decision, stored ([people-table.md](people-table.md); HIL-289) |
| deletion scheduled | the live request in `hilos_account_deletion` and its `effective_at` ([account-deletion.md](account-deletion.md)) |
| frozen | not stored: computed from the person's acceptance records ([legal-documents.md](legal-documents.md)) |

`AccountStanding` names each fact — `blocked`, `frozen`, `deletionEffectiveAt`
(epoch milliseconds or `null`), `lapsed` (one `{document, deadline}` per document
past its deadline) — and one `shown` (`AccountStandingKind`): the fact that takes
the most away, in this order: `blocked` → `frozen` → `deletion_scheduled` →
`none`. None of the three replaces another. A blocked person with a scheduled
deletion is shown blocked and keeps the deletion; lifting the block of a person
who did not accept the new revision leaves them frozen, with nothing to restore.

## The Freeze Is Computed

A person is frozen when at least one declared document is `lapsed` for them
(`LegalStandingResolver::standingOf()` over their acceptance records, on the
server date `LegalStandingResolver::today()`) **and**
`legal.refusal_after_deadline` is `freeze`. It is the same computation and the
same setting as the third count of the `/hilos/legal` root, so the count and the
verdicts agree.

- Nothing sweeps: no cron, no backfill. A revision published with a past
  effective date freezes the people it catches as soon as the node runs with the
  new catalog; a deadline that arrives is caught by the change of the server
  date, not by a timer.
- Under `remind` nobody is frozen. `lapsed` still lists the documents whatever
  the setting says; the admin card shows them as "Past deadline — reminders
  only".
- A person with no acceptance of a declared revision (`none`) is neither lapsed
  nor frozen, exactly as the legal tallies leave them out. What becomes of such
  people — a project moving onto Hilos — is proposal P-448.
- A catalog that fails validation (`LegalException`) lapses nobody: what there
  is to accept is unknown, and closing the product to everyone for the author's
  mistake is not the answer. The fault is shown in red on `/hilos/legal`. A
  project without a catalog declares no documents and freezes nobody.
- Only the person's own acceptance lifts a freeze. An administrator has nothing
  to press, and no exemption is stored.

## One Place Of Composition

[`AccountStandingResolver`](../../../framework/backend/Users/AccountStandingResolver.php)
is the only place a standing is composed. Every reader asks it — the session
frame, the page gate, the admin card and the people list:

- `of($userId)` — the verdict; `isFrozen($userId)` — its `frozen`;
- `lapsedUserIds($document)` — the people past one document's deadline under
  any setting, judged per held revision as `LegalTally` judges it (one
  `LegalAcceptances::heldByUser()` query);
- `compose()` — the fold of the three facts, and the only place the order lives.

Do not read the block, the deletion request or the acceptances beside it to
decide a standing: a second composition is a second verdict that can disagree.

### Memory by epoch

The gate asks on every delivery, so each process remembers the verdict per
person and the lapsed list per document. The memory belongs to one **epoch**:
the server date together with the refusal setting. A new epoch starts empty,
which is how a deadline that has just passed and a setting just changed reach
every verdict without anybody announcing them.

Within an epoch,
[`AccountStandingChangeSubscriber`](../../../framework/backend/Users/AccountStandingChangeSubscriber.php)
(on the source-change bus, subscribed in `Hilos.php`) drops what a write made
stale:

- `users` — only a change that carries `block`, and any insert, delete or clear.
  The row is written on every visit; dropping on each write would empty the
  memory faster than the gate reads it.
- `accountDeletions` and `legalAcceptances` — the verdict of the person named by
  `user_id` in the row or in its previous values. A change that does not name the
  person (a deletion request's update carries only the columns that moved), and a
  clear, drop every verdict.

Any drop takes the lapsed lists with it. For those writes to reach every
process where the gate stands, `accountDeletions` and `legalAcceptances` are
declared in `HilosDbContext::processWideReadCollections()`, beside `users`.

## The Guard

`PageAccessGate::verdict()` decides in this order: a `PUBLIC` page allows; a
viewer of the admin view mode views; an anonymous session is refused 401; **a
frozen person is refused with `PageAccountFrozenException` (403
`account_frozen`) unless the page declares `OPEN_WHILE_FROZEN`**; an `ADMIN`
page refuses a non-admin 403 `forbidden`; anything else allows
([page-access-control.md](page-access-control.md)).

- After 401: an anonymous visitor is asked to sign in, not told about a freeze.
- Before 403: a frozen administrator hears that they are frozen, not that they
  lack a right — and does not reach the admin.
- The person judged is the one the session acts as: under impersonation, the
  represented person, whose eyes the administrator sees the product through.
- A viewer of the admin view mode never reaches the step: a viewer changes
  nothing, and the page is shown to them without an account.
- Being the gate, it holds on the subscription, its update, every delivery, the
  page's actions (as `ActionAccountFrozenException`, 403 `account_frozen`) and
  the re-decision of an open page.
- A `PUBLIC` page is not refused: a guest sees it too. What a frozen person sees
  there is the shell's freeze screen (HIL-500).

**Actions.** `PageSignalRouter::assertActionAuthorized()` refuses a frozen person
every action its host lists in `AUTH_ACTIONS` — page or agent — with
`ActionAccountFrozenException`, unless the host lists the action in
`FROZEN_EXIT_ACTIONS` or the host is a page open while frozen. That is what closes
the chat's message on its public main page. The `action_error` frame carries the
code whether the action was tracked or not, so the shell can tell a freeze from a
failure either way.

### Exits are declared at their place

| Exit | Declared on |
|---|---|
| `/profile/data` — the person's data, with its actions | `AbstractHilosProfileDataPage::OPEN_WHILE_FROZEN` |
| `/profile/agreements` | `AbstractHilosProfileAgreementsPage::OPEN_WHILE_FROZEN` |
| `/profile/agreements/history` | `AbstractHilosProfileAgreementsHistoryPage::OPEN_WHILE_FROZEN` |
| `hilos_account_deletion_cancel` — calling off one's own deletion | `AbstractUsersLibraryAgent::FROZEN_EXIT_ACTIONS` |
| `hilos_data_export_order` — ordering a copy of one's data | `AbstractDataExportAgent::FROZEN_EXIT_ACTIONS` |

`FrozenExitRegistryTest` pins that composition exactly. The data copy order is
outside `AUTH_ACTIONS` today, so nothing closes it yet; the declaration keeps it
open if it joins.

Open without a declaration, because no list closes them: the actions outside
every `AUTH_ACTIONS` — sign-out (`hilos_logout`), dismissing the "Access closed"
card (`hilos_dismiss_account_blocked`), the step-up start and confirmation
(`hilos_step_up_start`, `hilos_step_up_confirm`) — and the four public footer
pages (About, Terms, Privacy, License). The acceptance action that lifts a freeze
is HIL-500's, and is declared an exit there.

- Declare an exit on the class that owns the page or the action, next to it:
  `OPEN_WHILE_FROZEN` for a page (all its actions go with it), the owner's
  `FROZEN_EXIT_ACTIONS` for one action. Keep no list of exits anywhere else.
- A freeze takes away using the product and nothing more. Add an exit only for
  leaving, calling off one's own deletion, one's own data, and reading or
  accepting one's agreements; everything else stays closed.
- A new page for signed-in people or administrators, and a new name in any
  `AUTH_ACTIONS`, is closed to a frozen person with no code of its own.
- The frontend mirrors the open pages in `HILOS_FROZEN_OPEN_PAGES`: a page that
  becomes an exit changes there too, in the same commit.

## The Wire

One shape everywhere: `{shown, blocked, frozen, deletionEffectiveAt, lapsed:
[{document, deadline}]}` (`AccountStanding::toArray()`); nobody sends it back.

- **Session.** `accountStanding` on every `hilos_session_state` frame
  (`SessionStateSignalData`) and in the `data` section of the handshake response
  (`HandshakeResponseSignalData`), `null` for an anonymous session. It is stamped
  at the one door each of them leaves through — the sessions library's
  `publishSessionState()` and `AbstractAgent::sendHandshakeResponse()` — the way
  the "Access closed" card is, so no builder of a frame passes it.
- **Card.** The page answer of `/hilos/user/{userId}` carries `accountStanding`
  (`AbstractHilosUserPage::ACCOUNT_STANDING`) of the person on the card; after
  that, `hilos_account_standing_state` (WS_USER, `{userId, accountStanding}`,
  `AccountStandingStateSignalData`) brings each change.
- **Refusal.** `account_frozen` with status 403, as `errorCode` of
  `subscription_page_error` and of `action_error`. It is not a 401 and opens no
  sign-in.
- **People list.** The filter `lapsed` (`AbstractHilosUsersTable::FILTER_LAPSED`,
  `terms` or `privacy`) keeps the people `lapsedUserIds()` names; the address is
  `/hilos/users/{lapsed?}`, and the third count of the `/hilos/legal` root links
  there when it is above zero.

## Who Keeps Open Surfaces In Step

Nobody announces a standing: a block, a deletion request and an acceptance are
written by whoever writes them, and a deadline passes on its own. Every such
change drops the resolver's memory (above), so the holders compare, on their own
tick, what they sent with what the resolver answers now; an unchanged standing
costs an array comparison.

- **The sessions library** — `republishChangedStanding()` on its tick, over the
  people with a live connection on the node, `STANDING_CHECKS_PER_TICK` of them
  a tick. A changed standing sends a state frame to every session of the person,
  including the ones where an administrator represents them — the impersonation
  strip turns with it. A freeze that set in or lifted also calls
  `PageAccessReassessment::forUser()`, so the person's open pages are re-decided
  without a reload. A person seen for the first time is recorded, not told: the
  frame that brought their tab carried the standing.
- **`AccountStandingAudience`**, ticked by the index agent
  (`AbstractHilosIndexAgent::onTick()`), for the admin surfaces that show other
  people. A card whose person's standing moved gets `hilos_account_standing_state`
  through `AbstractHilosUserPage::standingFrame()`, which asks the gate for that
  connection again and passes the view mode's bridge. A people window narrowed by
  `lapsed` is sent again (`sendTableWindow`) when the people past that document's
  deadline change. It is process-local, like the subscriptions it follows, and
  forgets a subscriber on unsubscribe and on connection close.

## Who Draws What

The core binds the session's standing once, in `bootHilos`
(`session/accountStanding.ts`); each SDK's shell only reads it.

- **Deletion strip** (`hilosDeletionStrip`) — the session's own standing is
  `deletion_scheduled` and there is no takeover. "Your account will be deleted on
  {date} — {days} left" and "Keep my account" (`keepMyAccount()`, the tracked
  `hilos_account_deletion_cancel`). `HilosLayout` draws it right after the
  impersonation strip and before the project's banner slot; the days are counted
  again once a minute from the moment the strip appears.
- **Impersonation strip** — `alert-{tone}` by the represented person's shown
  standing (`hilosStandingTone()`): blocked → `danger`, frozen → `info`,
  otherwise `warning`.
- **Avatar mark** (`hilosSessionAvatarMark`) — under a takeover, the people icon
  in the same tone; for one's own scheduled deletion, the trash in `warning`;
  otherwise none. Each demo passes it to the `HilosAvatar` of its header.
- **Admin card** — one badge beside the presence for the shown standing
  (`hilosStandingBadge()`: "Blocked", "Frozen", "Deletion scheduled"), and the
  frozen row between the block and the deletion rows, with no button
  (`hilosUserFrozenRow()`).
- A block and a freeze get no strip and no mark. A block puts the "Access
  closed" card in place of the shell (HIL-289). The freeze screen in place of the
  content, with its window and the acceptance, is HIL-500's; it reads the
  standing from the session, the pages it leaves open from
  `HILOS_FROZEN_OPEN_PAGES` and the refusal by `ACCOUNT_FROZEN_ERROR_CODE`. Until
  it lands, a refused page is drawn by the ordinary error page of its 403 status.

## Validation

- The composition, the memory and the epoch:
  `framework/tests/Unit/Users/AccountStandingResolverTest.php`.
- The exits: `framework/tests/Unit/FrozenExitRegistryTest.php`.
- The gate, the actions, the exits and the frozen cases over the real router,
  the wire and the holder's tick:
  `framework/tests/Integration/AccountStandingIntegrationTest.php`.
- The card and the lapsed list: `demo/chat/tests/Integration/AccountStandingCardTest.php`.
- No demo catalog carries a second substantial revision, so a browser cannot
  reach a freeze; chat's e2e covers the deletion strip and the mark
  (`account-deletion.spec.ts`), the card's badge and the takeover tone
  (`users.spec.ts`), and the lapsed count without a link (`legal-admin.spec.ts`).
  When a catalog receives a second substantial revision (HIL-500), a test puts a person
  on the former revision with the `test:legal:hold` command; its path is guarded by
  `AccountStandingIntegrationTest`.
