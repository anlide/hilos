# ORM: Entity References

Read this before choosing an internal entity reference, an action target, or the
identity held by a browser row or an open edit session.

## Core Rule

Carry the target model's declared primary key through internal references and
actions. A display or business code is an attribute; it does not identify the
particular persisted row a caller captured. This does not require a surrogate
integer key: a model with a declared string or composite primary key keeps that
key and its documented type.

Resolve an external URL, environment value, provider identifier or catalog code
at the entrance that accepts it. After that entrance has captured a concrete
entity, downstream data carries its primary ID. If that ID disappears, refuse
the operation; do not resolve its former code to a replacement.

## Workflow

1. List each reference: its owner, target model, declared primary key and ID
   type. Include modal targets, nested projections, choices and hidden filters.
2. List each external boundary that resolves a code. Name where resolution
   ends and which ID the downstream path carries.
3. List every code retained as an attribute or catalog key, with the reason it
   is not a reference to a persisted entity.
4. Follow each target through serialize → parse → handler → item action. Keep
   the wire type strict and the nullable key explicit; do not coerce a numeric
   string, an empty string, zero or a boolean into an integer ID.
5. Check existence and ownership coordinates before writing. For a child that
   already exists, capture its own ID as well as its parents. A parent pair
   alone also names a child created later at that pair.
6. Exercise delete/recreate with the same code, parent pair and text: the old
   request must refuse and leave the replacement intact; a fresh request with
   the new ID must succeed. In a live editor, a replacement must not inherit
   the old baseline, expected text or confirmation.

## Preferred Shape

An absent child carries `null` as its own ID and still carries its parent IDs.
A synthetic table row uses the IDs of its anchors as its surface key; it does
not acquire an entity `id` merely because it needs a row key. A locale pair can
therefore remain after its locale is deleted, with `localeId: null`.

For a name projection, take the ID and text from the same typed View row. An
empty string is a saved value; an absent row has a null ID and null text.
Creating a correction captures the specific base name's ID, so a new base at
the same coordinates cannot silently inherit a pending creation.

Use the existing collection offset for the declared key, following
[accessor contracts](accessor-contracts.md). Integer and string offsets can
have different meanings on the same collection; parse a decimal surface key
before passing it to a collection whose string offset means a business code.

## Exceptions

- A code supplied while creating an entity is its new attribute.
- An enum or value without its own entity is a value, not an entity reference.
- A lookup in a built-in catalog selects a definition, not a persisted row.
- External addresses keep their public code contract and resolve it at entry.

These exceptions do not authorize a mutation to find a replacement by code
once a concrete row was captured.

## Validation

Review the inventory and the entire path above. A global grep for `Code` cannot
classify the meaning of an arbitrary field and must not become a semantic style
guard. Scoped executable contracts can enumerate a subsystem's real actions
and projections: i18n's `I18nActionIdContractTest` checks all 27 registered
actions against exact parsed/serialized payloads, including the two creation
exceptions, and `I18nProjectionIdContractTest` checks its four row serializers.
A new unclassified action fails that inventory check. These tests do not prove
that every reference elsewhere in the framework follows this rule.

Changes to persisted or runtime row shapes, signal DTOs or routes still follow
the contract approval gate in [agents.md](../../../agents.md).
