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
acceptances belong to their consuming flows.

## Recording an acceptance

Use the users library's public `legalAcceptanceCommands()` factory. Its
[`LegalAcceptanceCommands`](../../../framework/backend/Auth/Library/Command/LegalAcceptanceCommands.php)
is the single orchestration entry for registration and re-consent:

- `record($userId, $revisionIdsByDocument)` participates in the caller's
  transaction. It neither starts nor commits one, and publishes no state.
- `accept($userId, $revisionIdsByDocument)` owns a transaction for the entire
  document-to-revision map, rolls back on a Hilos failure, then publishes state.
- `publishState($userId)` sends the committed projection to
  `hilos_legal_agreements:<userId>`. A caller of `record()` publishes only after
  its outer transaction commits.

Transactions are not nested. Registration uses `record()` inside its existing
transaction; it must not call `accept()` there. Both paths record the exact
named declared revisions, never silently substitute the newest ones. An
undeclared document or revision is a programming error at this internal entry.

`hilos_legal_acceptance` stores `user_id`, `document`, `revision_id` and the
server's `accepted_at`, with an auto-increment `id` and a unique key over the
person/document/revision. Repeating a recorded acceptance returns its original
row and timestamp. There are no item actions: a stored acceptance is immutable.
The users library owns the collection; the sessions library borrows removal
for account erasure. Erasure removes all of the person's acceptance rows.
Merging accounts leaves those rows with the account that gave the acceptance.

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

The chat profile root includes the same lightweight agreement state for its
summary. Each personal surface joins the server-addressed group after its
subscription answer; the state signal updates the person's open views. On the
agreements page, a changed held or current revision asks for a fresh answer of
that same page subscription. The previous state/text pair stays together until
that complete answer arrives, so a lightweight group frame cannot relabel old
clauses as the text of a newly accepted revision. The profile summary and the
history need only lightweight state and update directly from the group.
Records naming undeclared revisions are omitted from the public projection.
A project with no catalog receives `documents: []`.

The two profile routes are `/profile/agreements` and
`/profile/agreements/history`. This feature supplies no browser action to
accept a revision; registration and re-consent call the command entry above.
