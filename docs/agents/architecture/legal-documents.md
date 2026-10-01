# Legal Documents

Read this before declaring a legal revision, reading a person's coverage or
recording what they accepted. The framework's model lives in
[`Legal/`](../../../framework/backend/Legal/LegalCatalogResolver.php); the
installation binds its `LEGAL_CATALOG` provider on its Hilos facade.

A document (`terms` or `privacy`) declares revisions in order. Each revision
adopts one version of that document's Hilos standard set and may replace its
clauses with project deviations. Composition preserves the adopted set's order
and keeps the standard beside each deviation. Published revisions and their
text files remain available: a stored acceptance names exactly one of them.
`LegalCatalogResolver` validates the entire catalog on first access; an absent
catalog publishes no documents.

## Current text and deadlines

The last declared revision is current as soon as it is published. Its
`effectiveOn` is a deadline for people holding earlier revisions, not a switch
that postpones the current text. An editorial revision takes effect on its
publication date and preserves existing coverage. A substantial revision opens
a new acceptance requirement with its own deadline.

Declarations refuse an effective date before publication, an editorial revision
whose effective date differs from publication, and publication dates that move
backward in declaration order. The earlier structural checks still hold,
including the significance floor when adopting a newer standard set.

`LegalStandingResolver::standingOf()` takes the server calendar date explicitly.
Its held revision is the latest declared revision among the person's recorded
acceptances; an unknown revision id is ignored. Declaration order, not the
acceptance timestamp, decides which revision is held.

| State | Meaning |
|---|---|
| `none` | No acceptance names a currently declared revision. |
| `covered` | No substantial revision follows the held revision. Editorial changes preserve coverage. |
| `window` | Substantial revisions follow it, and today precedes the nearest of their effective dates. |
| `lapsed` | Today is on or after that nearest date. |

The deadline is the minimum outstanding substantial effective date. Publishing
another revision does not extend an earlier deadline. This calculation reports
coverage only; enforcement, re-consent, and the treatment of accounts without
acceptances belong to their consuming flows. The enforcement — the freeze of a
person past a deadline — is [account-standing.md](account-standing.md); the
screen that asks the person to decide is [Re-consent](#re-consent) below.

## Recording an acceptance

Use the users library's public `legalAcceptanceCommands()` factory. Its
[`LegalAcceptanceCommands`](../../../framework/backend/Auth/Library/Command/LegalAcceptanceCommands.php)
is the single orchestration entry for registration and re-consent:

- `record($userId, $revisionIdsByDocument)` participates in the caller's
  transaction. It neither starts nor commits one, and publishes no state.
- `accept($userId, $revisionIdsByDocument)` owns a transaction for the entire
  document-to-revision map, rolls back on a Hilos failure, then publishes state.
- `acceptCurrent($acceptKey, $revisionIdsByDocument)` is re-consent's entry: it
  checks that the named revisions are the ones in force and calls `accept()`
  ([Re-consent](#re-consent)).
- `publishState($userId)` sends the committed projection to
  `hilos_legal_agreements:<userId>`. A caller of `record()` publishes only after
  its outer transaction commits.

The framework never nests a transaction
([../orm/transactions.md](../orm/transactions.md)). Registration uses `record()`
inside its existing transaction; it must not call `accept()` there. Both paths record the exact
named declared revisions, never silently substitute the newest ones. An
undeclared document or revision is a programming error at this internal entry.

`hilos_legal_acceptance` stores `user_id`, `document`, `revision_id` and the
server's `accepted_at`, with an auto-increment `id` and a unique key over the
person/document/revision. Repeating a recorded acceptance returns its original
row and timestamp. There are no item actions: a stored acceptance is immutable.
The users library owns the collection; the sessions library borrows removal
for account erasure. Erasure removes all of the person's acceptance rows.
The only other removal is test-only: `test:legal:hold <userId> <document> <revisionId>` (HIL-324)
forgets acceptances of revisions declared after the named one, and records it, so the test
holds the person on the former revision; window, lapse, and freeze follow by calculation.
Merging accounts leaves those rows with the account that gave the acceptance.

## Consent at registration

The public `hilos_legal_consent` action on the users library accepts `{}` and
replies with `{form, documents}`. `form` is `checkbox` or `line`, read from
`legal.consent_form`. Each document supplies its `document` key, current
`revision` and composed `clauses` in the shared `LegalWire` shapes. The last
declared revision is current even when its acceptance deadline is in the future.
The action is a read and is not an authentication or secret-guessing door.

The first email, phone or magic-link send carries `acceptedRevisions`, a map
from each declared document to the exact revision shown. Both actions of the
addressless passkey door carry it too. An unknown address is checked before
it is reserved or a code is minted; an existing account needs no consent to
sign in. An installation with registration but no documents refuses registration.

| Refusal | Surface behavior |
|---|---|
| `consent_required` | Return to consent, without an error sentence |
| `consent_revised` | Reload current documents, clear the checkbox and explain that the terms changed |
| `terms_unpublished` | Explain that the project has not published terms; creating an account stays unavailable |

The phone owner reports these same codes on the closing send-progress row and
finishes the operation before minting or delivering a code.

`hilos_registration_reservation.accepted_revisions` stores the map while the
address is held. A re-send at a live hold omits the map and reuses that hold's
acceptance. A renewal after the hold died is the tab's first send again and
carries the map the tab holds: from its own consent step, or from
`acceptedRevisions` on the pending registration step in the handshake or
session-state frame, read off the live hold. A renewal without a map returns
to consent. Another browser's map cannot authorize
a first send. A proof landing without its own live hold can use the newest
live hold carrying acceptance on that address, ordered by `created_at DESC,
id DESC`, before any other hold is removed. Without acceptance, landing
returns to consent and creates no account.

Immediately after `createUser()`, the landing calls `record()` in that same
transaction. A failure rolls back both account and acceptances. A revision
published after the first send does not replace what the person accepted;
re-consent owns the subsequent gap. Registration publishes no personal
agreement state because the new person has no profile subscribers yet.

All three SDK shells use `HilosLegalConsent` for the live step and the admin
preview on `/hilos/legal/{documentKey}`. They fold the standard across both
documents and show the deviations with their standard statements. Full text
comes from the same answer and opens inside the card. Each entry loads again;
a reconnect refreshes the revisions while keeping the form choice of this
visit. Replies from an abandoned step or closed preview are ignored. The
checkbox is never preselected and is not rendered before its text arrives.

## Re-consent

A person holding a revision a substantial one has followed decides on it on
one screen (HIL-500): a document whose standing is `window` or `lapsed`. A
person with no acceptance (`none`) is never asked; an editorial revision keeps
coverage and so never raises the screen. Both documents that moved share one
screen, a section each, and one acceptance takes them all.

**What a tab knows without asking.** The account standing carries `window` —
`[{document, deadline}]`, the documents whose deadline is still ahead, whatever
the refusal setting says — beside `lapsed` of the same shape
([account-standing.md](account-standing.md)). The shell decides the rest from
those two lists and the freeze flag: something is due while a document is in
its window or lapsed and the person is not frozen, not blocked and not under a
takeover; lapsed and not frozen is the `remind` setting at work.

**When the screen stands.**

- The window rises by itself on a sign-in in this tab only: the session moving
  from nobody to a person after the tab's first frame. The first frame of a tab
  opened on a live sign-in is where counting starts, a takeover starting or
  stopping is no entrance, and nothing that changes mid-work raises it. A
  registration accepts the revisions in force and so never has anything due.
- A yellow document icon sits right after the shell's user region while
  something is due; it names the days left to the nearest deadline ("The terms
  have changed — 9 days left to decide", whole days rounded up and never below
  one) or asks to review when only lapsed documents wait, and opens the window.
- Frozen, the same screen stands in the content's place on every page but those
  the freeze leaves open (`HILOS_FROZEN_OPEN_PAGES`), after the "Access closed"
  card and before the content.

**The three buttons.** Accept records the revisions in force of every document
shown. Later closes the window and records nothing — the icon stays. "I do not
accept" records nothing either: it opens a step saying what follows (under
`freeze` the day the product closes, under `remind` that nothing changes) with
the ways to the data copy and to account deletion. A refusal is recorded
nowhere, and silence is never acceptance. The freeze screen offers Accept only,
with the exits as actions: the data copy, "Keep my account" while a deletion is
scheduled, and sign-out. Under a takeover the window and the icon are absent,
the freeze screen stands without Accept and "Keep my account", and says that
only the person can accept.

**The three actions** — all users-library `AGENT_ACTIONS`:

| Action | Payload → reply | Reach |
|---|---|---|
| `hilos_legal_reconsent` | `{}` → `{refusal, documents: [{document, standing, deadline, held, current, changes, clauses}]}` — every document in its window or lapsed, in declaration order | signed in (`AUTH_ACTIONS`), open while frozen (`FROZEN_EXIT_ACTIONS`) |
| `hilos_legal_accept` | `{acceptedRevisions: {document: revisionId}}` → tracked reply without a body | signed in, open while frozen |
| `hilos_legal_reconsent_preview` | `{document}` → `{document, standing, deadline, held, current, changes, clauses}` | public, like the consent read |

`LegalReconsentProjector` builds both replies: `held`, `current` and `changes`
are the `LegalWire` revision and change shapes, `clauses` the composed clauses
of the revision in force. A faulty catalog answers with no documents, the verdict
the standing gives it.

`LegalAcceptanceCommands::acceptCurrent()` refuses under impersonation
(`StepUpMessages::IMPERSONATED` — an acceptance in someone else's hands is a
forged record of consent), for an empty set, for a document the installation
does not declare, and for a revision that is no longer the one in force
(`AuthMessages::CONSENT_REVISED`: the screen reads its content again and asks
once more). Nothing is written until every document passes. No signal of its
own follows: the acceptance's write drops the remembered verdict in every
process, and the sessions library's tick sends the new frame and re-decides the
open pages when the freeze moved ([account-standing.md](account-standing.md)).

**The preview.** The document page of the admin section opens the same screen
read-only, as a holder of the previous revision sees it today: the standing
(`window`, `lapsed`, or `covered` when the revision in force is editorial) and
the changes previous → in force. The first revision has nothing before it: the
standing and `held` are null, and the preview shows a line instead of the
screen.

## Comparison and provenance

`LegalRevisionComparison::between()` compares an earlier declaration with a
later declaration of the same document. Reversed or equal revisions are
refused. It compares effective text, statement, source and deviation direction;
a standard changing under an unchanged deviation does not change the effective
clause. Results are changed, added or removed clauses only, in the newer set's
order, followed by removed clauses in the older set's order. Each result keeps
both effective sides and the standard statement that names the clause.

Revision provenance is `first`, `standard` when its adopted set version rose,
or `project` otherwise. `LegalWire` serializes revision metadata, composed
clauses and differences consistently for all consumers.

## Profile delivery

The authenticated agreements page supplies lightweight `legalAgreements` and
its `legalAgreementTexts` in the subscription response. The text section holds
the current text, a distinct held text when needed, and their differences.
The authenticated revision-history page supplies `legalAgreements` plus
`legalRevisions`; its text and predecessor comparison are read-only action
replies when a person opens a dialog. All three SDK layers use the same core
schemas and reader; a closed or superseded dialog ignores a late reply.

The profile root (`AbstractHilosProfilePage`) includes the same lightweight
agreement state for its summary. Each personal surface joins the server-addressed group after its
subscription answer; the state signal updates the person's open views. On the
agreements page, a changed held or current revision asks for a fresh answer of
that same page subscription. The previous state/text pair stays together until
that complete answer arrives, so a lightweight group frame cannot relabel old
clauses as the text of a newly accepted revision. The profile summary and the
history need only lightweight state and update directly from the group.
Records naming undeclared revisions are omitted from the public projection.
A project with no catalog receives `documents: []`.

The two profile routes are `/profile/agreements` and
`/profile/agreements/history`. The profile supplies no browser action to
accept a revision; registration and re-consent call the command entry above.

## Admin section

The `Legal` section under Access & identity is closed by `ADMIN`. Activate it by
registering its five pages and tables and a project subclass of
`AbstractHilosLegalAgent`; there is no `HilosFeature` case for this section.
Chat, tasks and polls provide these bindings. The framework owns the pages,
projections, settings rules and all three SDK views. The project supplies person
names and name searches to `AbstractHilosLegalAcceptancesTable`.

| Route | Contents |
|---|---|
| `/hilos/legal` | Documents, coverage counts and four catalog checks |
| `/hilos/legal/{documentKey}` | Adopted standard set, newer framework set, project deviations and revision history |
| `/hilos/legal/{documentKey}/{revisionId}` | Exact text, predecessor comparison and acceptance count |
| `/hilos/legal/acceptances` | Immutable acceptance records, document/revision filters and person search |
| `/hilos/legal/settings` | The two legal settings, edited in modals |

The section writes no document or acceptance. Texts stay in code and a revision
is published by deployment. The revision view reuses `HilosLegalRevisionText`
and `HilosLegalChanges`, the same components as the personal agreement surfaces.
A recorded revision missing from the catalog still opens, with its count and an
explanation in place of text. A key with neither declaration nor records is
refused with `PageResourceNotFoundException` (404 / `not_found`). The revision detail narrows `hilosLegalRevisions` by both `document` and
`revision`: its count must not depend on whether its row fits in the first
history window. An already-open historical revision reports zero when its last
record is erased, and distinguishes a refused count window from a loading one.

A `LegalException` from the catalog becomes `legalCatalogRefusal` on the root,
document and revision pages. Their declaration tables answer empty windows;
the page shows the refusal. Acceptance history and settings remain usable.
A project without a catalog has no declared documents and no checks to show.

### Counts and checks

`LegalTally` folds two SQL histograms per document: distinct people by their
latest accepted **declared** revision, and acceptance records by revision.
`heldCounts()` selects declaration rank, not the timestamp of acceptance.
`LegalStandingResolver::standingOf()` depends on that held revision alone, so
PHP needs one entry per revision rather than one per person or acceptance.

- `covered` includes the current revision and editorially equivalent holdings.
- `window` counts holdings with an outstanding substantial revision whose
  nearest deadline is still ahead.
- `lapsed` counts holdings whose deadline has arrived. Its label is `Frozen`
  for `legal.refusal_after_deadline=freeze`, or `Past deadline` for `remind`.
  Above zero it links to the people list narrowed to that document,
  `/hilos/users/{documentKey}`; zero is plain text.
- A revision's `heldCount` counts people whose latest declared acceptance is
  that revision. For an undeclared revision it counts its records; the unique
  person/document/revision key makes that a count of people too.
- `acceptedCount` is the number of records for the exact revision, regardless
  of later acceptances. People with no recorded acceptance are outside these
  tallies, and the freeze does not reach them either (proposal P-448;
  [account-standing.md](account-standing.md)).

The four checks identify accepted revisions missing from code, substantial
non-first revisions with zero-length windows, newer framework standard sets,
and declared project deviations. A document with no deviations carries the
reminder that consent will say there are no differences. A deviation pointing
at a missing standard clause is a catalog refusal, not a fifth check.

The section runs on its own cluster singleton, `hilos_legal`, started by its
first page subscription. Its daemon proxy requires a monopolistic worker:
whole-history SQL must not occupy the shared administration worker's tick.
The agent reads acceptance records and owns no DB or RT collection. The users
library remains their writer.

`LegalAcceptanceChangeSubscriber` invalidates `LegalAdminAudience` on local and
remote acceptance changes. That process-local audience shares histograms and
projections across windows, coalesces changes until its next tick and resends
subscribed aggregate windows. When `LegalStandingResolver::today()` changes,
it folds the cached histograms again without SQL. An acceptance-only audience
does not read the coverage histograms; a section with no viewers does no work.
Settings rows use the ordinary source-change fan-out, including the root's
refusal-policy label.

### Acceptance windows and filter vocabulary

Acceptance windows come from SQL in `accepted_at DESC, id DESC` order. Only
the timestamp is sortable; reversing it also reverses the primary-key tie.
Keep both indexes with this declaration: `idx_legal_acceptance_accepted_at`
on `(accepted_at)`, and `idx_legal_acceptance_document_revision` on
`(document, revision_id, accepted_at)` for an exact document/revision window.
InnoDB supplies the primary-key suffix. The latter index also serves the
prefix read of distinct recorded document/revision pairs.

Do not build the filters from the current window: its 25 rows cannot name
all historical revisions. `revisionsOnRecord()` supplies the complete recorded
pairs independently of catalog validity. The catalog adds declared documents
with no records and orders their recorded revisions newest first; unknown
revisions follow in descending id order. Undeclared document keys follow the
catalog's documents in key order.

The page sends `HilosLegalAcceptanceFiltersSignalData` on
`subscription_page_hilos_legal_acceptances` before its `page_response`, through
the same subscription, without a client catalog request. The connection retains
that early frame for a view mounted later. The audience sends the vocabulary
again after an acceptance change only when its fingerprint differs. Answering
a new subscriber does not consume a broadcast owed to existing viewers. A date
change does not reread or resend filter options. Every vocabulary push rechecks
the current page access level and browser guards; a past subscription is not
authority to keep receiving data after rights are revoked.

Both a history row and an option carry `declared`: `true` for a declaration,
`false` for a record missing from code, `null` when the catalog refused and the
answer is unknown. Only `false` draws the missing-code mark. Changing the
document filter clears the revision filter in the same viewport request.
Rows, facet counts and pending changes otherwise follow the shared viewport
protocol; there is no legal-specific mutation path.

### Settings

| Key | Values | Default |
|---|---|---|
| `legal.consent_form` | `checkbox`, `line` | `checkbox` |
| `legal.refusal_after_deadline` | `freeze`, `remind` | `freeze` |

Merge `LegalSettingsCatalog::getCatalog()` into the project's setting catalog.
A stored row is needed only when a value is edited; `LegalSettings` reads the
catalog default otherwise. `legal_setting_set` stays on the admin page and
hands the write to the settings library; the library validates the value and
returns `hilos_legal_setting_write_done`. The page refuses unrelated keys.

Every view uses `createHilosLegalSettingEdit` over the focused setting row and
the shared `rowEdit` merge. A save stays unavailable while unchanged, in flight,
conflicting or deleted. Incoming changes update a pristine draft, and a refused
write keeps the modal and draft with its inline error. Closing follows the
tracked settings-owner reply; the table value follows the DB source. Preview
controls are disabled illustrations, not a second registration flow.

The consent-form value is consumed by registration. The refusal policy is
consumed by access enforcement, which freezes a person past a deadline under
`freeze` ([account-standing.md](account-standing.md)), and by this section, which
names the lapsed count with it. Re-consent — the freeze screen and its
acceptance — consumes it too ([Re-consent](#re-consent)). Neither setting
changes a revision's text or effective date.
