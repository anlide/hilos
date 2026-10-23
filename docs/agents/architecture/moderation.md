# Architecture: Moderation

Read this before declaring moderation kinds, starting checks, changing the
moderator or its rules, or activating the Moderation section.

This is the target architecture of HIL-1569, written ahead of its code and now
partly in it: the kinds, the rules table and its writer, the check queue, the
moderator with its models and settings, and the profile photo flow (HIL-1623 to
HIL-1625) are in the code. Each pending rule names the leaf that implements it;
that leaf clears its markers in the same commit, following
[../rule-authoring.md](../rule-authoring.md), *A Rule Written Ahead Of Its
Code*. *Check Kinds* names the PHP classes that declare kinds, *Rules Per Kind*
the ones that store and write rules, and *One Door And One Moderator*, *Models
And Settings* and *Switching It On* the check queue, its signals, the moderator
and its settings; elsewhere, names identify approved kinds and addresses and do
not prescribe future PHP classes or payloads.

## Core Rule

Moderation is a framework feature, `HilosFeature::MODERATION`. Declare a check
kind in the framework directory, extended by the project when it has kinds of
its own; startup checks that directory as *Check Kinds* describes. The feature
enables the moderation mechanism itself, the moderator and its checks.

Send every check through one door to one moderator per cluster. Keep checking
separate from applying: the verdict goes to the agent declared by the kind;
writing the subject remains its owner's work.

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
the new table. Chat carries the table through the framework install pair
`101_framework_install_moderation_rule`, the pair `db:framework:pull` writes
with the feature.

## One Door And One Moderator

The initiating agent opens a check row with the kind and the connection; the
verdict goes to the kind's declared agent. Do not make the moderator a second
owner of the subject.

The door is the runtime collection `hilosModerationChecks`, which
`ModerationFeature` mounts in every process: rows of
[HilosModerationCheck](../../../framework/backend/Runtime/State/Item/HilosModerationCheck.php),
read as `Hilos::$rt->hilosModerationChecks` and found by
`find($acceptKey, $kind)`. A row is keyed `acceptKey|kind`, so a connection
waits on one check of a kind at a time, and carries `acceptKey`, `userId`,
`kind`, `requestId`, `verdictTo`, `text`, `clientUploadId`, `startedAt` and
`deadlineAt`. Open one with
`Hilos::$rt->hilosModerationChecks->actions->open($acceptKey, $userId, $kind, text: …)`
for a `TEXT` kind, or with `clientUploadId:` naming a complete upload on the
same connection for an `IMAGE` kind. `open()` refuses a project without
`MODERATION` (`InvalidStateException`); a person id that is not positive, a
kind the project does not publish or an input the kind does not look at
(`InvalidArgumentException`); a `TEXT_WITH_FILES` kind (`ValidationException`)
until the moderator can ask about text with files, which is HIL-1627's; and a
connection already waiting on that kind (`DuplicateValueException`). It draws
a fresh `requestId`, stamps `startedAt` and fixes `deadlineAt` from
`moderation.check_timeout_sec` as it reads at that moment.

The collection is cut into sets by `verdictTo` (`SET_VIA`), which the row takes
from the kind and not from the caller: an initiator writes only the checks
whose verdicts come to it, and the moderator only reads them. A row is never
edited. Changed content is a new attempt: `$check->actions->forget()`, then
`open()` again under a new request id; `forget()` is also how the initiator
ends a check once its verdict is applied or its wait is given up.

The verdict is `HilosSignalConstants::HILOS_MODERATION_VERDICT`, carrying
[ModerationVerdictSignalData](../../../framework/backend/Moderation/DTO/ModerationVerdictSignalData.php):
`acceptKey`, `kind`, `requestId`, `allow` and `reason`. No agent declares it in
`AGENT_SIGNALS`: the signal router reads the kind out of the payload and
addresses the kind's `verdictTo`, and a kind the project does not publish is
not routed. The reason is `ok` (`ModerationReason::OK`), a reason the kind
declares, or `service_unavailable` (`ModerationReason::SERVICE_UNAVAILABLE`),
which permits nothing and says nothing about the content. A verdict applies
only while the row still holds the request id it names.

The connection's side of a check is
`HilosSignalConstants::HILOS_MODERATION_CHECK`, carrying
[ModerationCheckSignalData](../../../framework/backend/Moderation/DTO/ModerationCheckSignalData.php)
(`kind`, `checking`): the initiator's frame to one connection, saying whether
it is waiting on a check of that kind.

The initiator sends that frame, and the subject's owner applies the accepted
result; for the profile photo both are the people library's, which opens the
check, tells the connection `checking: true`, and on the verdict removes the
row, tells it `checking: false` and publishes or refuses
([profile-photo.md](profile-photo.md)).

The cluster's single moderator serves one request at a time, oldest first. A
changed or removed check row cancels the old request. A model that does not
answer yields **unavailable**, never permission to bypass the check.

The moderator is
[ModerationAgent](../../../framework/backend/Moderation/ModerationAgent.php)
under `HilosAgentType::HILOS_MODERATOR`, whose
[ModerationAgentDaemon](../../../framework/backend/Moderation/ModerationAgentDaemon.php)
runs it as a monopolistic cluster singleton. It owns and writes nothing: it
reads the checks, the uploads, the rules, the settings and the people. Among
the checks whose deadline has not passed and whose connection still carries the
person who asked, it takes the oldest by `startedAt`, then by row id. Right
before asking it reads the kind, the kind's effective rules and the person's
current name, and resolves the kind's profile. The policy goes as the system
message — the kind's title, the two answers the model may give, and the rules —
and the person's id and name with the text, or with the upload's picture read
from the cluster's temporary directory, as the user message. The model answers
one JSON object with `allow` and `reason`
([ModerationDecision](../../../framework/backend/Moderation/ModerationDecision.php));
no model, no file, no rules, no person, a failed or timed-out request, or an
answer that is not a verdict ends as `service_unavailable`. A check whose row
is removed or replaced while it is with the model is dropped without a
verdict; once a verdict has gone out, the moderator waits for its row to leave,
no longer than the row's deadline.

Until approval, the previously published photo remains visible.

Closing a dialog does not itself cancel the check. The initiator sweeps its
checks once a second: it removes a check whose connection ended or now carries
somebody else, with no publication and no frame, and ends a check whose
deadline passed as `service_unavailable`. A verdict that arrives for an expired
check ends it the same way.

## Models And Settings

Use `hilos.moderation.text` (`ModerationLlmConstants::PROFILE_TEXT`) for `TEXT`
and `TEXT_WITH_FILES`, and `hilos.moderation.image` (`PROFILE_IMAGE`) for
`IMAGE`. Both profiles belong to the framework and use the ordinary
[LLM router](llm-routing.md).

Attachment information in a text check does not make it a vision check of all
the attached pictures. (not in the code yet — HIL-1627)

Both models are controlled by framework settings, with environment values as
defaults and secrets kept in the environment. Each role has four keys of its
own in
[ModerationSettingsCatalog](../../../framework/backend/Moderation/ModerationSettingsCatalog.php):
`moderation.text.provider`, `moderation.text.model`, `moderation.text.url` and
`moderation.text.timeout_sec`, and the same four under `moderation.image.*`.
Each defaults to its env value, `HILOS_MODERATION_TEXT_*` or
`HILOS_MODERATION_IMAGE_*` (`PROVIDER`, `MODEL`, `URL`, `TIMEOUT_SEC`); with
neither, a role runs `ModerationLlmConstants::DEFAULT_TEXT_MODEL` or
`DEFAULT_IMAGE_MODEL` on the local provider. An empty URL keeps the address the
environment resolved. A role switched to `external` takes `LLM_EXTERNAL_URL`
and `LLM_EXTERNAL_API_KEY` from the environment, and without the key every
check of that role ends `service_unavailable` until the setting is put right.
`moderation.check_timeout_sec` (default 90) is how long a whole check may wait.
How the settings lie over the environment is the *Resolution* section of
[llm-routing.md](llm-routing.md).

Read effective rules and settings for the next check, not once at startup: a
change takes effect without a restart. The moderator reads the rules and
resolves the profile right before each check; a check already with the model
finishes on the profile it started with, and the client is rebuilt only when
the profile differs.

## Switching It On

A project switches the mechanism on by declaring `HilosFeature::MODERATION`.
[ModerationFeature](../../../framework/backend/Core/Feature/Definition/ModerationFeature.php)
then requires the moderator pair `HilosAgentType::HILOS_MODERATOR`, which
exists for this feature alone; the shared rules library
`HILOS_MODERATION_RULES_LIBRARY` and settings library `HILOS_SETTINGS_LIBRARY`;
`ModerationSettingsCatalog` folded into the project's settings catalog; and the
`hilos_moderation_rule` table. Startup refuses a missing agent or fragment, and
a moderator registered without the feature; the table is the per-demo test's
to check. The feature mounts `hilosModerationChecks` itself. Nothing about
images is required: an image kind needs uploads, which `PROFILE_PHOTO` brings,
and a project that moderates text alone runs the moderator without them. The
verdict recipients follow from the kind directory, as *Check Kinds* says.

```php
ModerationAgent::AGENT_TYPE => [
    AgentRegistryKey::WORKER => ModerationAgent::class,
    AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
    AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
],
```

Chat is the first project to declare `MODERATION`: its `FEATURES` lists it,
its `AGENTS` registers the moderator pair beside the rules library pair, and
its settings catalog folds in `ModerationSettingsCatalog`.

## Profile Photo And Own Name

With `MODERATION`, [profile photos](profile-photo.md) use `hilos_profile_photo`
and the common check door. Without moderation, a valid uploaded photo
publishes immediately. The existing photo storage and publication path remain
the photo mechanism's.

The former project-specific `PROFILE_PHOTO_CHECKER` seam was removed in
HIL-1625.

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

- Do not build a project moderator; declare a kind and use the common door.
- Do not add a project photo checker; use the moderation feature's photo kind.
- Do not append custom rules to built-ins; custom rows replace the entire
  built-in set.
- Do not add an LLM container or bypass the router on a stand; use the existing
  `/model` resident as [stand-services.md](../stand-services.md) prescribes.

## Non-Goals And Contract Gate

There is no verdict journal and no per-kind on/off switch in this design.

**Built so far:** the kinds directory, the rules table and its library, the check
queue and its signals, the moderator, its profiles and settings, and the profile
photo flow (HIL-1623–HIL-1625).

**Not built yet:** the display-name check (HIL-1626); chat as a consumer
(HIL-1627); the section's tables and its activation (HIL-1628); pages, actions,
SDK mounts and demo stands (HIL-1629–HIL-1637); e2e and cluster coverage
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
