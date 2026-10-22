# Architecture: Moderation

Read this before declaring moderation kinds, starting checks, changing the
moderator or its rules, or activating the Moderation section.

This is the target architecture of HIL-1569, written ahead of its code. Each
pending rule names the leaf that implements it; that leaf clears its markers
in the same commit, following [../rule-authoring.md](../rule-authoring.md),
*A Rule Written Ahead Of Its Code*. *Check Kinds* names the PHP classes that
declare kinds, and *Rules Per Kind* the ones that store and write rules;
elsewhere, names identify approved kinds, profiles and addresses and do not
prescribe future PHP classes or payloads.

## Core Rule

Moderation is a framework feature, `HilosFeature::MODERATION`. Declare a check
kind in the framework directory, extended by the project when it has kinds of
its own; startup checks that directory as *Check Kinds* describes. The feature
enables the moderation mechanism itself, the moderator and its checks.
(not in the code yet — HIL-1625)

Send every check through one door to one moderator per cluster. Keep checking
separate from applying: the verdict goes to the agent declared by the kind;
writing the subject remains its owner's work.
(not in the code yet — HIL-1625)

## Check Kinds

A kind declares its stable key, display name, input type (`TEXT`, `IMAGE` or
`TEXT_WITH_FILES`), refusal reasons, built-in rules and verdict recipient
agent; in the code it is a
[ModerationKind](../../../framework/backend/Moderation/ModerationKind.php) with
a `ModerationInput`. The `hilos_` prefix is reserved for the framework.

The framework declares `hilos_profile_name` for text and `hilos_profile_photo`
for an image. The photo kind is published only while `PROFILE_PHOTO` is
enabled: the directory always declares it, and leaves it out only after the
whole declaration has been judged, so a bad photo entry is refused even in a
project without profile photos. `MODERATION` does not enable profile photos.

Chat adds `chat_message` for text with attachment information and
`chat_message_picture` for an individual image; the verdicts of both go to the
chat agent.

The framework directory is
[ModerationKindDirectory](../../../framework/backend/Moderation/ModerationKindDirectory.php).
Extend it by retaining the parent's entries and appending the project's kinds
in `kinds()`, then point the Hilos facade's `MODERATION_KIND_DIRECTORY` at the
subclass, as
[StepUpOperationDirectory](../../../framework/backend/Auth/StepUp/StepUpOperationDirectory.php)
and [ChatStepUpOperationDirectory](../../../demo/chat/backend/Auth/ChatStepUpOperationDirectory.php)
do for operations. Chat's
[ChatModerationKindDirectory](../../../demo/chat/backend/Moderation/ChatModerationKindDirectory.php):

```php
protected static function kinds(): array
{
    return [
        ...parent::kinds(),
        new ModerationKind(
            ChatModerationKindKey::MESSAGE,
            'Chat message',
            ModerationInput::TEXT_WITH_FILES,
            ['insult', 'threat', 'hate_speech', 'sexual', 'spam'],
            ['Default policy: allow benign messages.', /* … */],
            AgentType::CHAT,
        ),
        new ModerationKind(ChatModerationKindKey::MESSAGE_PICTURE, /* … */),
    ];
}
```

The declaration is a list in the order of the administration screen, not a
map: a key typed twice reaches the duplicate check instead of silently
replacing the first entry. Read a kind as `Hilos::moderationKinds()[$key]` —
the published kinds keyed by kind key, read afresh from constants on every
call; there is no separate find or get, and a key the set does not hold is the
caller's to refuse by its own contract.

Refuse startup for a declaration that is not a list of kinds, a duplicate
kind, a project declaration using `hilos_`, a framework kind dropped or
changed, an unregistered recipient agent, or an invalid directory class. Do
not silently drop or replace a bad declaration. The check runs while
`MODERATION` is declared, and also whenever the facade points
`MODERATION_KIND_DIRECTORY` at a directory of its own; the framework default
with the feature off owes no recipients. A recipient is judged only on the
published kinds, so the photo kind's recipient is judged only with
`PROFILE_PHOTO`.

## Rules Per Kind

Store custom rules in `hilos_moderation_rule`, addressing the kind by a string
key, not an ENUM. A kind's custom rules replace its built-in rules **whole**;
they are not appended. With no custom rows, including after the last one is
deleted, the built-in rules apply again.

Read the rules a check of one kind applies as
`Hilos::$db->moderationRules->effectiveForKind($kind)`: the kind's own rule
texts in id order, or its `defaultRules` while it has none. The kind is looked
up in `Hilos::moderationKinds()` whether or not `MODERATION` is on, so an
unknown kind, and the photo kind without `PROFILE_PHOTO`, is an
`InvalidArgumentException`; a database failure propagates rather than turning
into the built-in rules. The list is computed on every call and kept nowhere.

The table has `id` (`INT UNSIGNED` auto-increment primary key), `kind`
(`VARCHAR(64)`, ASCII binary) and `rule` (`TEXT`), with an index on `kind` and
no foreign key, ENUM or unique key; its reference DDL is
[create_hilos_moderation_rule.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_moderation_rule.sql),
the framework schema unit `moderation_rule`. `MODERATION` requires the table,
so a project receives it through `db:framework:pull` with the feature. The ORM
chain `ModerationRule`/`ModerationRules` is mounted as `moderationRules` with
`LAZY_STRATEGY_NONE`, loaded whole on first read.

Change rules only through their single writer,
[ModerationRulesLibraryAgent](../../../framework/backend/Moderation/Library/ModerationRulesLibraryAgent.php)
under `HilosAgentType::HILOS_MODERATION_RULES_LIBRARY`; neither a page nor the
moderator writes SQL to change them. The library claims `moderationRules`
whole with every operation in `OWNS_DB` — no owner per kind or per rule — and
its daemon is a monopolistic cluster singleton the project registers with
`AgentPlacement::POLICY`; `MODERATION` requires it as a shared agent. Its
doors are the collection action `create($kind, $rule)`, which refuses a kind
the directory does not publish with `ValidationException`, and the item actions
`update($rule)` and `delete()`; any other agent is refused at all three, and a
rule's kind never changes. `create` and `update` refuse blank text and text
over the Object's `MAX_RULE_BYTES` with `ValidationException`, and store any
other text as given.

Every column is judged not personal (`_pii = []`; `_piiNotPersonal` lists
`id`, `kind` and `rule`): a rule is an administrator's policy text, not data
about a person. The Entity is `_journaled`: where the table exists, the change
log keeps `id` as the record key and `kind` and `rule` as values; see
[change-log.md](change-log.md#what-an-entity-declares).

On a fresh chat seed,
[006_framework_moderation_rules.sql](../../../demo/chat/backend/Database/Migration/Seed/006_framework_moderation_rules.sql)
makes the three current message rules custom rules for `chat_message`, and the
three name rules custom rules for `hilos_profile_name`. Profile photos and
message pictures use their built-in rules. Do not migrate old rule rows into
the new table. Chat carries the table before it declares `MODERATION` through
the framework install pair `101_framework_install_moderation_rule`, the same
pair `db:framework:pull` writes with the feature.

## One Door And One Moderator

The initiating agent opens a logical check row with the kind and a link to the
initiator and connection. The connection learns that a check is in progress;
the verdict goes to the kind's declared agent, and the subject's owner applies
the accepted result. Do not make the moderator a second owner of the subject.
(not in the code yet — HIL-1625)

The cluster's single moderator serves one request at a time, oldest first. A
changed or removed check row cancels the old request. A model that does not
answer yields **unavailable**, never permission to bypass the check. Until
approval, the previously published value remains visible.
(not in the code yet — HIL-1625)

Closing a dialog does not itself cancel the check. Ending the connection
removes its wait according to the check lifecycle. HIL-1625 owns that lifecycle,
the timeout, correlation and exact row fields and signals; do not infer a
second contract from this logical description.
(not in the code yet — HIL-1625)

## Models And Settings

Use `hilos.moderation.text` for `TEXT` and `TEXT_WITH_FILES`, and
`hilos.moderation.image` for `IMAGE`. Both profiles belong to the framework and
use the ordinary [LLM router](llm-routing.md). Attachment information in a text
check does not make it a vision check of all the attached pictures.
(not in the code yet — HIL-1625)

Both models are controlled by framework settings, with environment values as
defaults and secrets kept in the environment. Read effective rules and settings
for the next check, not once at startup: a change takes effect without a
restart. HIL-1625 chooses the env and settings constants.
(not in the code yet — HIL-1625)

## Profile Photo And Own Name

With `MODERATION`, [profile photos](profile-photo.md) use `hilos_profile_photo`
and the common check door; the project-specific `PROFILE_PHOTO_CHECKER` seam
goes away. Without moderation, a valid uploaded photo publishes immediately.
The existing photo storage and publication path remain the photo mechanism's.
(not in the code yet — HIL-1625)

A person's own name uses `hilos_profile_name`; the standard `CHANGE_NAME`
step-up operation moves into the framework. Until approval, even the person
requesting the change sees their previous name. A content refusal carries the
server's words and a notification; unavailability produces no notification.
Without `MODERATION`, the name applies immediately after the ordinary
confirmation. An administrator's rename is not moderated.
(not in the code yet — HIL-1626)

The existing name dialog receives the framework wiring and the progress line
`Checking your name…`; its demo integration belongs to HIL-1635.
(not in the code yet — HIL-1635)

## Chat Is A Consumer

Check the text and attachment information through `chat_message` first. Then
check each picture separately through `chat_message_picture`, using vision.
The first refusal stops the remaining checks; a picture refusal identifies
which picture failed. The composer's states belong to chat.
(not in the code yet — HIL-1627)

HIL-1627 removes chat's own moderator, rules table and moderation profiles.
Until then those parts of the existing demo are transitional, not a pattern
for a new project's moderation mechanism.
(not in the code yet — HIL-1627)

## The Admin Section And Activation

Activate the framework mechanism by the
[admin feature recipe](admin-feature-scaffold.md#moderation--the-moderation-section).
The framework supplies the kinds table and the rules table.
(not in the code yet — HIL-1628)

The section has these pages, with Vue/chat as their first consumer:

- `/hilos/moderation`, page key `hilos_moderation`: the kinds table.
  (not in the code yet — HIL-1629)
- `/hilos/moderation/{kind}`: the kind's Overview, beside its Rules tab;
  an unknown kind answers 404. (not in the code yet — HIL-1630)
- `/hilos/moderation/{kind}/rules`: custom rules or their built-in fallback;
  an unknown kind answers 404. (not in the code yet — HIL-1631)

The rule actions and their dialog validation belong to separate leaves:

- Add a custom rule. (not in the code yet — HIL-1632)
- Edit a custom rule. (not in the code yet — HIL-1633)
- Delete a custom rule. (not in the code yet — HIL-1634)

Apply [admin-features.md](admin-features.md) and
[admin-view-mode.md](admin-view-mode.md) to each page, table and action: the
viewer may look but may not mutate. The leaves above owe that behavior with
their own surface; this document adds no separate UI or payload specification.

Mount the React SDK section in tasks. (not in the code yet — HIL-1636)
Mount the Angular SDK section in polls. (not in the code yet — HIL-1637)
Profile photos in tasks are separate work. (not in the code yet — HIL-1644)
Profile photos in polls are separate work. (not in the code yet — HIL-1645)

## Anti-Patterns

- Do not build a project moderator; declare a kind and use the common door
  once its mechanism lands. (not in the code yet — HIL-1625)
- Do not add a project photo checker; use the moderation feature's photo kind
  once the photo path moves. (not in the code yet — HIL-1625)
- Do not append custom rules to built-ins; custom rows replace the entire
  built-in set.
- Do not add an LLM container or bypass the router on a stand; use the existing
  `/model` resident as [stand-services.md](../stand-services.md) prescribes.

## Non-Goals And Contract Gate

There is no verdict journal and no per-kind on/off switch in this design.

**Not in this leaf:** PHP/TS/SQL implementation, actual DB/RT shapes, DTOs,
signals, routes, migrations or seeds (HIL-1623–HIL-1628); pages, actions, SDK
mounts and demo stands (HIL-1629–HIL-1637); e2e and cluster coverage
(HIL-1638/HIL-1639); enabling profile photos in tasks/polls
(HIL-1644/HIL-1645).

This document does not replace the root `agents.md` contract gate. Each
implementation leaf must obtain its own exact approval before changing DB
entity or migration shape, RT item fields, signal/DTO contracts or declarative
routes. The logical row and address descriptions here do not approve those
implementation contracts.

## Validation

For this canon and its wrapper, run
`composer run test:framework:unit -- --filter AgentDocGuardTest`, then
`composer ai:install` and `composer ai:install:check` as
[ai-tools.md](../ai-tools.md) prescribes. Implementation leaves choose their
checks by [testing.md](../testing.md); this documentation change needs no e2e
or cluster run.
