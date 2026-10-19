# Architecture: Moderation

Read this before declaring moderation kinds, starting checks, changing the
moderator or its rules, or activating the Moderation section.

This is the target architecture of HIL-1569, written ahead of its code. Each
pending rule names the leaf that implements it; that leaf clears its markers
in the same commit, following [../rule-authoring.md](../rule-authoring.md),
*A Rule Written Ahead Of Its Code*. Names below identify approved kinds,
profiles and addresses; they do not prescribe future PHP classes or payloads.

## Core Rule

Moderation is a framework mechanism enabled by `HilosFeature::MODERATION`.
Declare a check kind in the framework directory, extended by the project when
it has kinds of its own. (not in the code yet — HIL-1623)

Send every check through one door to one moderator per cluster. Keep checking
separate from applying: the verdict goes to the agent declared by the kind;
writing the subject remains its owner's work.
(not in the code yet — HIL-1625)

## Check Kinds

A kind declares its stable key, display name, input type (`TEXT`, `IMAGE` or
`TEXT_WITH_FILES`), refusal reasons, built-in rules and verdict recipient
agent. The `hilos_` prefix is reserved for the framework.
(not in the code yet — HIL-1623)

The framework declares `hilos_profile_name` for text and `hilos_profile_photo`
for an image. Declare the photo kind only when `PROFILE_PHOTO` is enabled;
`MODERATION` does not enable profile photos.
(not in the code yet — HIL-1623)

Chat adds `chat_message` for text with attachment information and
`chat_message_picture` for an individual image.
(not in the code yet — HIL-1623)

Extend the directory by retaining the parent's entries and appending the
project's kinds, as the existing
[StepUpOperationDirectory](../../../framework/backend/Auth/StepUp/StepUpOperationDirectory.php)
and [ChatStepUpOperationDirectory](../../../demo/chat/backend/Auth/ChatStepUpOperationDirectory.php)
do for operations. This is an inheritance sketch, not a callable moderation
API; HIL-1623 chooses the class and method names:

```text
Framework directory
  hilos_profile_name       TEXT
  hilos_profile_photo      IMAGE, only with PROFILE_PHOTO
Project directory extends Framework directory
  retain parent entries
  append chat_message          TEXT_WITH_FILES
  append chat_message_picture  IMAGE
```

The moderation directory follows that shape.
(not in the code yet — HIL-1623)

Refuse startup for a duplicate kind, a project declaration using `hilos_`, an
unregistered recipient agent, or an invalid directory class. Do not silently
drop or replace a bad declaration. (not in the code yet — HIL-1623)

## Rules Per Kind

Store custom rules in `hilos_moderation_rule`, addressing the kind by a string
key, not an ENUM. A kind's custom rules replace its built-in rules **whole**;
they are not appended. With no custom rows, including after the last one is
deleted, the built-in rules apply again.
(not in the code yet — HIL-1624)

Use the rules' single designated writer and the normal framework write path;
neither a page nor the moderator writes SQL to change them. HIL-1624 defines
the complete schema, writer identity, anonymization verdict and change-log
policy; this document does not choose those contracts.
(not in the code yet — HIL-1624)

On a fresh chat seed, the three current message rules become custom rules for
`chat_message`, and the three name rules become custom rules for
`hilos_profile_name`. Profile photos and message pictures use their built-in
rules. Do not migrate old rule rows into the new table.
(not in the code yet — HIL-1624)

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
  built-in set. (not in the code yet — HIL-1624)
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
