# Architecture: Moderation

Read this before declaring moderation kinds, starting checks, changing the
moderator or its rules, or activating the Moderation section.

This is the target architecture of HIL-1569, written ahead of its code and now
partly in it: the kinds, the rules table and its writer, the check queue, the
moderator with its models and settings, the profile photo flow (HIL-1623 to
HIL-1625), and the session-held own-name flow are in the code. Each rule still
ahead of its code names the leaf that implements it; that leaf clears its
markers in the same commit, following
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
subclass. Chat's
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

Most initiating agents open a check for the connection that submitted it; the
verdict goes to the kind's declared agent. The standard own-name flow is the
scoped exception after admission: the sessions library owns one current rename
attempt per browser session, while its moderation work still uses this shared
door and the kind's declared recipient. The moderator reads and judges checks;
it does not own the subject or write the name.

The moderation door is the runtime collection `hilosModerationChecks`, which
`ModerationFeature` mounts in every process: rows of
[HilosModerationCheck](../../../framework/backend/Runtime/State/Item/HilosModerationCheck.php),
read as `Hilos::$rt->hilosModerationChecks` and found by
`find($acceptKey, $kind)`. A row remains keyed `acceptKey|kind`. Ordinary
connection-bound checks carry `acceptKey`, `userId`, `kind`, `requestId`,
`verdictTo`, `text`, `clientUploadId`, `clientUploadIds`, `imageCopy`,
`startedAt` and `deadlineAt`; a name check also carries its optional
`sessionTokenHash`. `clientUploadIds` is an ordered list of upload ids for
`TEXT_WITH_FILES`, without paths or bytes; `imageCopy` asks an `IMAGE` check to
use a rendered copy. These fields are part of the immutable attempt snapshot.
Thus the original connection still gives a name check its queue slot and
compatibility-status destination, but the accepted session attempt and its
`requestId` identify the
work after that socket disappears. A name check and a photo check from the
same connection still have separate queue slots. The session holder, not this
queue, serializes concurrent name requests from tabs of the same session.

Open a `TEXT` or `TEXT_WITH_FILES` check with
`Hilos::$rt->hilosModerationChecks->actions->open($acceptKey, $userId, $kind, text: …)`;
the latter may carry ordered upload ids in `clientUploadIds`, including an empty
list. Open an `IMAGE` check with `clientUploadId:` naming a complete upload on
the same connection, and set `imageCopy` only when the caller requests a
rendered image copy. Ordinary calls draw a fresh `requestId` and fix
`deadlineAt` from `moderation.check_timeout_sec` when opened; an initiator may
also pass a future epoch-millisecond deadline without a session token, and no
stage may extend it. An expired explicit deadline is refused and the initiator
ends the check as unavailable. For an admitted own-name attempt,
`ProfileNameCommands` passes the session holder's `sessionTokenHash`, the same
`requestId`, and its already-fixed `deadlineAt`; reconnecting does not extend
that deadline. `open()` refuses a project without `MODERATION`
(`InvalidStateException`); a person id that is not positive, a kind the project
does not publish or an input the kind does not look at
(`InvalidArgumentException`); and a connection already waiting on that kind
(`DuplicateValueException`). Input-shape mismatches, including a non-list or
duplicate upload id, are `InvalidArgumentException` refusals.

The two optional arguments are appended after the existing `open()` arguments,
so positional callers keep their order. `TEXT` requires non-null text,
`clientUploadId: null`, an empty `clientUploadIds` list and `imageCopy: false`.
`TEXT_WITH_FILES` requires non-null text (which may be empty), no singular upload
id, an ordered list of distinct non-empty string ids (which may be empty), and
`imageCopy: false`. `IMAGE` requires null text, a non-empty singular upload id
and an empty `clientUploadIds` list; `imageCopy` may be either boolean value.
The connection-bound caller does not choose `requestId`; a supplied request id
without `sessionTokenHash` is refused. The session-bound own-name path keeps its
existing request id and attempt checks.

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

The compatibility status for a check is
`HilosSignalConstants::HILOS_MODERATION_CHECK`, carrying
[ModerationCheckSignalData](../../../framework/backend/Moderation/DTO/ModerationCheckSignalData.php)
(`kind`, `checking`): a frame to the originating connection, saying whether
that check is still running. For an own-name check it is only a compatibility
indicator while that socket is present; `checking: false` ends moderation, not
the rename. The authoritative name outcome is the session's complete
`hilos_profile_name_state` snapshot described below.

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
the checks whose deadline has not passed, it takes the oldest by `startedAt`,
then by row id. Ordinary checks are eligible only while their connection still
carries the person who asked. A session-bound `hilos_profile_name` check may
continue without that socket; `ProfileNameCommands` rechecks its current,
non-stale session attempt before applying any verdict. Right
before asking it reads the kind, the kind's effective rules and the person's
current name, and resolves the kind's profile. The policy goes as the system
message — the kind's title, the two answers the model may give, and the rules —
and the person's id and name with the text, or with the upload's picture as the
user message. For `TEXT_WITH_FILES`, the user message adds one metadata line per
ordered upload id with its filename, detected MIME type (or declared type) and
declared size; the moderator does not read non-image file bytes, and attachment
names, types and sizes are not grounds for refusing the text. A missing,
incomplete or stale upload makes the check `service_unavailable`. For `IMAGE`,
the upload must be complete on the same connection. When `imageCopy` is true,
the moderator lazily renders a 512-by-512 JPEG with `CONTAIN` and sends that
copy; when it is false, the profile-photo check keeps its existing source path.
A local image-render failure returns `unreadable`, not a model reason; that
value is neither a kind reason nor accepted by the model-output parser. Other
file, model, deadline or parsing failures return `service_unavailable`. The
framework handles the declared input shape and does not know project kind keys.

A check carrying `uploadSnapshots` and `uploadHoldId` additionally waits for
positive [owner hold marks](uploads.md#consumer-holds) on every original
attachment. A partial, mismatched, incomplete, stale or expired group is not
eligible and does not block another ready check. Upload creation, update and
deletion wake queue selection. The moderator rechecks the entire snapshot and
its exact token/deadline before constructing input and before answering;
image bytes come from the matching snapshot's temporary name. Earlier image
stages remain part of that group. The initial fixed deadline includes admission
waiting; the initiator's existing deadline sweep also ends unconfirmed checks
as unavailable. Empty snapshots preserve the previous input path, including
profile photos with `imageCopy: false`.

The model answers one JSON object with `allow` and `reason`
([ModerationDecision](../../../framework/backend/Moderation/ModerationDecision.php));
no model, no file, no rules, no person, a failed or timed-out request, or an
answer that is not a verdict ends as `service_unavailable`. A check whose row
is removed or replaced while it is with the model is dropped without a
verdict; once a verdict has gone out, the moderator waits for its row to leave,
no longer than the row's deadline.

Until approval, the previously published photo remains visible.

Closing a dialog does not itself cancel a check. For ordinary checks, the
initiator sweeps its checks once a second: it removes one whose connection
ended or now carries somebody else, with no publication and no frame, and ends
one whose deadline passed as `service_unavailable`. A verdict for an expired
check ends it the same way. For a session-bound own-name check, the initiator
compares the queue row with the current session attempt; a known orphan is
removed, while a stale RT source is unknown and is not treated as absence. Its
fixed checking deadline ends as unavailable. Once approval advances the rename
to `applying`, moderation has finished: there is no new TTL, and the operation
waits for the existing person owner's reply.

## Models And Settings

Use `hilos.moderation.text` (`ModerationLlmConstants::PROFILE_TEXT`) for `TEXT`
and `TEXT_WITH_FILES`, and `hilos.moderation.image` (`PROFILE_IMAGE`) for
`IMAGE`. Both profiles belong to the framework and use the ordinary
[LLM router](llm-routing.md).

`TEXT_WITH_FILES` is a metadata-only text check. Each image attachment is a
separate `IMAGE` check; callers request a rendered JPEG copy with `imageCopy`
when they need that behavior.

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

A person's own name uses `hilos_profile_name`; `CHANGE_NAME` step-up and the
existing action's other admission checks remain in the framework. The action
[`ProfileNameCommands::set()`](../../../framework/backend/Auth/Library/Command/ProfileNameCommands.php)
checks the acting socket, standing, impersonation, addressability, trimmed
name bounds and confirmation before sending a validated request to the
sessions library. That library revalidates the current session and person, then
serializes attempts by the hash of the session token in its owned
`hilosProfileNameChanges` RT collection, whose row is
[`HilosProfileNameChange`](../../../framework/backend/Runtime/State/Item/HilosProfileNameChange.php).
It publishes one fresh `requestId` and a complete
`hilos_profile_name_state` snapshot to that session on admission, handshake,
phase change and cleanup. A second tab in the same session joins a checking or
applying attempt; another browser session has its own attempt. A checked
same-name no-op also publishes terminal `done`, rather than waiting for a name
echo that cannot occur.

The accepted attempt has `checking`, `applying`, `refused` or `done` phase. With
moderation enabled, its checking deadline is fixed from
`moderation.check_timeout_sec` at admission; a reconnect does not extend it.
The session attempt's
`requestId` and deadline are copied into its `hilos_profile_name` queue row.
Approval removes that check and asks the existing person owner to rename;
without moderation, the session holder moves directly to `applying`. The
sessions library accepts only a matching, current transition. It keeps
`refused` and `done` until the next accepted attempt or session cleanup, then
replaces the one row rather than keeping a history. End of session or change of
person removes the row and publishes an empty snapshot. The continue signal is
a wake-up: source events and the current RT attempt decide whether a phase
runs.

During `checking` the accepted request survives closing the dialog, a missing
acting socket and F5. An unavailable or content refusal remains in session
state when that socket is gone. The model verdict does not write the name: the
existing [`AbstractUserAgent`](../../../framework/backend/Users/Agent/AbstractUserAgent.php)
writes the name and rename journal in one transaction, and
[`AbstractUsersLibraryAgent`](../../../framework/backend/Auth/Library/AbstractUsersLibraryAgent.php)
reports `done` or `refused` only after the owner reply and completion hook.
An administrator's rename is not moderated. A stale or repeated verdict and a
late owner reply cannot complete a newer `requestId`.

A content refusal carries the server sentence from
[`ProfileNameRefusal`](../../../framework/backend/Users/ProfileNameRefusal.php)
and best-effort sends `Your new name was not accepted`; notification failure
does not replace the outcome. An unknown, incomplete or contradictory verdict,
an unavailable check or an expired deadline ends as `Names cannot be checked
right now.` without a notification. A refusal
before admission can arrive as `action_error`; after admission, the terminal
refusal is retained in session state and can be shown after the original socket
is gone.

`hilos_moderation_check` remains a compatibility indicator for the originating
socket; `checking: false` means only that the check ended. The authoritative
outcome is the full session `hilos_profile_name_state`, never that indicator.
The three standard profile SDKs use the shared
[core rename binding](../../../framework/frontend/core/src/profile/profileRename.ts):
they show a persistent `Checking your name…` status during checking, leave the
window closable while the operation continues, and restore that tab's open
draft after F5 without resending. The tab-local checkpoint and the generic
edit-window exception are described in
[conflict-resolution.md](../frontend/conflict-resolution.md). Tasks and polls
use the same binding; their moderation activation remains separate.

## Chat Is A Consumer

`MainPage` keeps its existing submission validation, records the composer on
the `Connection`, and asks `ChatAgent` to start moderation. `ChatAgent` opens a
`chat_message` `TEXT_WITH_FILES` check with the message text and ordered upload
ids. The framework moderator sees attachment metadata only. After that check is
approved, `ChatAgent` opens a separate `chat_message_picture` `IMAGE` check for
each image upload in attachment order, setting `imageCopy: true`. Each stage
gets a fresh `requestId`; the chain stops on its first refusal. Every stage
carries the deadline fixed by the first check; opening the next image does not
extend it.

`ChatAgent` receives the shared verdict and owns the chat outcome and file
publication. It publishes no message until every check is approved; with files,
it waits for the registry's publication reply, writes the event, then clears the
composer and marks the files bound. `MainPage` does not receive the verdict or
publication reply. The six existing composer fields on
`Connection` remain the browser-facing draft and status. The attempt queue and
current stage are in the shared `hilosModerationChecks` RT collection, not in a
session-held composer.

A content refusal uses the existing public reason and may send the existing
best-effort message-rejected notification. A renderer refusal (`unreadable`)
identifies the image but sends no notification; `service_unavailable` is not a
content refusal, does not identify an image, and sends no notification. The
chat-owned moderator, prompt-piece table and chat moderation profiles have
been retired; chat declares kinds and consumes the framework mechanism.

## The Admin Section And Activation

Activate the framework mechanism by the
[admin feature recipe](admin-feature-scaffold.md#moderation--the-moderation-section).
The framework supplies two browser tables, registered by Chat in `Hilos::TABLES`
and mounted through `ChatTableContext`:

- `HilosModerationKindsTable` (`hilosModerationKinds`) exposes each published
  kind by string row key `kind` in slot `kind`, with `kind`, `title`, `owner`
  (`framework` or `project`), `input` (`text`, `image` or `text_with_files`),
  `model`, nonnegative `ownRuleCount`, `profile: string`,
  `reasons: list<string>` and `defaultRules: list<string>`. Rows follow
  directory order by default. The count covers persisted rules of that kind;
  zero means its built-in rules apply, and built-in rules are not database rows.
  `model` uses
  `ModerationSettingsCatalog::IMAGE_MODEL` for `image` input and
  `ModerationSettingsCatalog::TEXT_MODEL` otherwise. Its wire field uses
  `settingFrom` for that model setting and is `HiddenValue` to a viewer denied
  that setting. `defaultRules` comes from `ModerationKind::defaultRules` in
  declaration order and is present even when the kind has own rules. It and
  every other field, including `profile` and `reasons`, is `notPersonal`.
- `HilosModerationRulesTable` (`hilosModerationRules`) uses the `kind` window
  filter and exposes persisted rules for a published string kind by positive
  integer row key `id` in slot `rule`, with `id`, `kind` and `rule`. Its default
  order is `id` ascending.

The kinds table observes the `moderationRules` and `settings` sources; the rules
table observes `moderationRules`. Rows use the existing `page_response.windows`
and `table_window` shapes. Source-change fan-out keeps open windows current
through `table_viewport_delta`, `table_viewport_append`,
`table_viewport_announce` and `table_viewport_count`, including kind counts and
model changes across every kind using that profile. The kinds table's optional
exact `kind` filter returns one published kind; an unknown or non-string value
returns no rows, and `query()` and `containsRow()` use the same filtered set.
Without the filter, the root keeps its directory order, 25-row window and
existing columns. The shared source-change, `page_response` and `table_window`
mechanisms remain unchanged. The earlier read-only tables and windows needed no
signal names or DTOs of their own; Rules now has the Add handover described
below.

The section has these pages, with Vue/chat as their first consumer:

- `/hilos/moderation`, page key `hilos_moderation`: the kinds table
  (`AbstractHilosModerationPage` / `HilosModerationPage`).
- `/hilos/moderation/{kind}`, page key `hilos_moderation_kind` and signal
  `subscription_page_hilos_moderation_kind`: the kind's read-only Overview
  (`AbstractHilosModerationKindPage` / Chat's `ModerationKindPage`). Its
  required string route param is looked up in the code-declared kind directory;
  an unknown kind answers 404. The page inherits the `ADMIN` access gate and
  viewer behavior, declares no `DB_EXISTS` requirement or actions, and repeats
  the full directory check and page response for direct, repeated, updated and
  reconnected subscriptions.
- `/hilos/moderation/{kind}/rules`, page key `hilos_moderation_kind_rules` and
  signal `subscription_page_hilos_moderation_kind_rules`: the kind's own-rules
  page (`AbstractHilosModerationKindRulesPage` / Chat's
  `ModerationKindRulesPage`). It uses the same required string `kind` route
  param and `HilosModerationKindSubscribeParams`; an unknown kind answers 404.
  It inherits the `ADMIN` access gate and viewer behavior and declares no
  `DB_EXISTS` requirement. Its tracked Add action is a write and is refused to
  a viewer before the page forwards it.

The Rules page's browser action `hilos_moderation_rule_add` uses
`HilosModerationRuleAddActionDTO` with `kind` and raw `rule`. After the page's
`ADMIN` gate and its published-kind check, `AbstractHilosModerationKindRulesPage`
uses `HandoverGatekeeperTrait` to forward a `ModerationRuleAddSignalData`
(`HandoverAskInterface`) over `moderation_rule_add` to the single
`ModerationRulesLibraryAgent`. The library calls
`Hilos::$db->moderationRules->actions->create($kind, $rule)`, then returns a
`HandoverAnswerSignalData` over the page-owned
`hilos_moderation_rule_write_done` signal. The ask carries the initiating
action and `requestId`; the gatekeeper answers that tracked action with its
normal `::success` or `::fail`. Success follows the completed write and carries
the page's success sentence for the single success toast. The refusal stays
with the open form. This path uses the existing handover and source-change
fan-out: it adds no refresh request or second row store.

The Rules page's browser action `hilos_moderation_rule_update` uses
`HilosModerationRuleUpdateActionDTO` with `id`, `kind`, raw `rule` and raw
`expectedRule`; the positive `id` captures one row, and `kind` is its published
catalog key. After the same `ADMIN`, standing and freeze gates and
published-kind check, the page uses `HandoverGatekeeperTrait` to forward a
`ModerationRuleUpdateSignalData` over `moderation_rule_update` to the single
`ModerationRulesLibraryAgent`. The ordinary action envelope carries its
`requestId`. The library rechecks the published kind, reads exactly
`Hilos::$db->moderationRules[$id]`, verifies that the row exists, belongs to the
kind and still has the exact `expectedRule`, then calls that item's
`actions->update($rule)`. A missing row, kind mismatch or stale text is refused;
a missing ID is never replaced by another row, and no version or ABA history is
kept. The existing ORM stores accepted text as given and rejects text that is
blank after trimming or over the TEXT column's byte limit; its ownership and
persistence checks still apply. The existing correlated
`HandoverAnswerSignalData` returns success only after the write, with
`Rule in "<title>" saved.` for one local success toast. A refusal or timeout
stays in the open form with its draft and no failure toast or automatic retry;
a ready-answer transport failure does not replay the write.

Before answering either kind page, `onSubscribeBeforeResponse()` consumes the
connection's reported table windows and installs the canonical metadata
viewport. `hilosModerationKinds` is filtered by the route's `kind`, limited to
one row and renders all nine fields. Each first `page_response` carries that
page's identity and head with the selected kind metadata. The first Rules
`page_response` also carries its own-rules window:
`hilosModerationRules` is filtered to the route's `kind`, with a cold window of
25 rows and `id` ascending by default.
Only the Rules page restores a reported or held rules descriptor, and only for
that same kind, including its search, sort, anchor, `pageIndex` and rendered
fields. The core
`createHilosModerationKind(context, kind, page = HilosPages.MODERATION_KIND)`
binds the metadata viewport for either kind page and leaves client
`initialFilter` unset, so the canonical first window is accepted without a
second fetch. `createHilosModerationRules(context, kind, view?)` owns the Rules
controller and scope checks, confines viewport sends and accepted rows to that
page and kind, and disposes before another page subscribes; an optional
`view.openAdd` supplies its main action. A later Vue unmount can safely dispose
it again. The Rules view has one own-rules viewport table and shows
`defaultRules` as plain built-in text only when the overall
`ownRuleCount === 0`; an empty search of own rules does not show the built-ins.
The Add form captures the current kind and title for `Add rule · <title>`,
starts with an empty raw-text area and keeps the draft verbatim; trimming only
guards against submitting blank text. A dirty draft asks before discard. While
the write is busy, the form is locked and user close or cancel requests are
refused. A tracked success closes it; the refusal row stays in the fixed error
area above the fields, with its diagnostic details available there. Page or
kind changes isolate the replacement form from a late reply to the previous
one. In admin view mode, a viewer may open and edit the local draft, but submit
is blocked in the browser and the server's `ADMIN` gate refuses the page action
before it reaches the writer.

The first own rule makes `ownRuleCount` nonzero and replaces the built-ins as a
whole, so the built-in panel disappears. The next moderation check after an Add,
Edit or Delete reads fresh `effectiveForKind()` rules as before. Existing
source-change fan-out and the table's Apply behavior remain unchanged.

Edit is available only on an own rule, in the same Rules actions column as
Delete. `createHilosModerationRuleEdit(context, kind, rules)` opens only a fresh
focused row in the current Rules scope and kind, and captures its `id`, `kind`
and title. It uses `createHilosRowEdit` with `hilosTableRowEditSource` and one
editable field, the raw `rule` text. The shared row-edit session owns the
baseline, draft and incoming value: an untouched incoming value updates the
field with a notice, an identical value is not a conflict, and differing edits
offer Keep mine or Take theirs before the baseline advances. The update's
`expectedRule` comes from the focused live row inside the save callback after
the session has checked its verdict; the callback's row argument is the opening
snapshot.

The modal is titled `Edit rule · <title>` and contains one Rule field. Blank,
unchanged, conflicted or gone rows are not sent. If the row disappears, the
draft stays with a Deleted notice; dirty state is measured from the accepted
baseline and the shared discard confirmation applies. While saving, the
session rejects another submit, the UI disables the field and Cancel, and the
busy modal refuses user close; the standard delayed busy indicator remains in
use. Action errors stay above the field, and the edit notice and conflict
choices stay below it. In view mode, a viewer may open the form, edit a local
draft and choose a conflict, but browser submission is blocked and the server
`ADMIN` gate rejects a forged write.
Changing the route or kind, or disposing the page, releases focus and isolates
a late result from a later opening. Ordinary source-change fan-out supplies
live values; other viewers use the existing table Apply for moves and removals.
Edit adds no optimistic patch, subscription, refresh request or row store.

The core `HilosModerationRulesContext` extends the existing
`HilosModerationContext` with the `actions` lifecycle used by the Add and Edit
factories; the base context stays sufficient for the root and Overview. Chat
passes its existing `actions` singleton through `hilosModerationContext`. The
Add and Edit APIs are exported from the lazy core moderation section, and Vue's
`HilosModerationKindRulesPage` from the lazy Vue moderation section; the edit
modal has no separate public Vue export. The core Add/Edit APIs and the Vue
page remain out of compatibility-root and base exports. React's lazy
`/admin/moderation` section exports three page components and their `Props`
types; its Add, Edit and Delete forms remain internal to the Rules page. React's
compatibility root exports only `HilosModerationPage` from moderation. The Vue
kind Overview continues to receive the project's base context through Chat's
lazy `ModerationKind.vue` wrapper. The Rules view receives the extended context
through Chat's lazy `Rules.vue` wrapper.

Delete is available from the Rules row action and opens one confirmation dialog
for the selected rule. The dialog keeps the displayed rule text as its
confirmation snapshot and enables submission only while the focused row still
has the same id, kind and exact text. If the row disappears or changes, the
dialog stays readable, reports the condition and refuses submission. The Rules
page hands the typed request to the rules library, whose writer checks the
published kind, id, matching kind and exact expected text before deleting that
item. The existing write-done reply carries the correlated success or refusal;
the page reports success only after deletion completes. In view mode the dialog
can be inspected and canceled, while both the browser submission guard and the
server `ADMIN` gate refuse mutation. Existing source-change fan-out updates the
table and related views; after success, focus returns through the existing
table or page fallback if the removed row action no longer exists.

Apply [admin-features.md](admin-features.md) and
[admin-view-mode.md](admin-view-mode.md) to each page, table and action: the
viewer may look but may not mutate. Edit and Delete remain responsible for
that behavior on their own surfaces.

Tasks mounts the React section's root, kind Overview and Rules pages through
three lazy wrappers. The section presents the framework's Profile name and
Profile photo kinds; Add, Edit and Delete remain internal to its Rules page.
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
queue and its signals, the moderator, its profiles and settings, the profile
photo flow (HIL-1623–HIL-1625), the own-name check (HIL-1626), and Chat as a
consumer (HIL-1627). Also built are the kinds/rules browser tables registered
by Chat (HIL-1628), the Vue Rules Add, Edit and Delete flows with focused e2e
coverage, and the React/tasks mount with three lazy pages and internal Rules
forms.

**Not built yet:** the Angular/polls SDK mount (HIL-1637); broader moderation
e2e coverage and cluster coverage (HIL-1638/HIL-1639); enabling profile photos
in tasks/polls (HIL-1644/HIL-1645).

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
