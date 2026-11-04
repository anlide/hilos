# Moment Storage: Signed Epoch Milliseconds

Read this before storing a moment, planning its schema/writer/reader migration,
or deciding a project-specific storage representation.

## Scope And Status

**This is the approved migration design of HIL-1804 for HIL-1803, not a
description of migrations already built.** The owner settled the decisions on
2026-11-03. This document publishes those decisions, the complete source census
and the proposed migration subjects. It adds no schema, DB or RT item field,
signal, DTO, route, metadata declaration, callable API or guard.

The research base is `2cf51c6428a59f93da25068a4527b99ff21e8259`. The sources
were checked again on 2026-11-04 at
`110a4677ad2ef823dcf38a6c3d0d7771f4191ddd`.
**Factual correction, 2026-11-04:** the original census omitted the existing
`hilos_status_incident_update.at` moment. Its
[stub](../../../framework/backend/Database/Migration/Stub/create_hilos_status_incident_update.sql)
already declared it at the research base; this is an inventory correction,
not a schema change between research and publication. The corrected census is
**133 moment columns in 72 physical tables**. This is a source inventory, not
a live schema audit. Coordinates below describe that checked tree.

Today's storage is mixed. [Time zones](time-zones.md),
[Entity schema consistency](../orm/entity.md#schema-consistency) and
[migration delivery](../orm/migrations.md) describe the existing mechanisms.
The first proposed subjects below connect the target norm to those canonical
instructions and their skill routes, then implement its enforcement. Skills
remain routes to the canon, not a second copy of the rule.

Following [rules written ahead of code](../rule-authoring.md#a-rule-written-ahead-of-its-code),
the eventual implementation leaves take their exact contracts and markers when
they are cut. These subjects are not created tickets. HIL-1804 does not own
their implementation, so it supplies neither invented leaf keys nor markers
claiming that its own code will perform the migrations.

## Approved Decisions

- **An absolute moment is a signed `BIGINT` containing Unix epoch
  milliseconds, without a time zone.** The type and unit were settled in
  HIL-1803/P-627 and are not reopened here. A negative moment is not invalid
  merely because it precedes the epoch. Keep nullable moments nullable;
  absence is `null`, never a replacement zero.
- **Keep the existing names.** This includes `_at`, `_ts`, `_until`, `_since`,
  `_from`, `last_activity` and `timestamp`. There is no bulk rename and no new
  `_ms` suffix. Follow [cross-layer field names](../code-style/cross-layer-field-names.md):
  the concept keeps its words from storage through PHP, payload and TypeScript.
- **The application normally writes the moment through `TimeHelper::nowMs()`.**
  Remove `DEFAULT CURRENT_TIMESTAMP` and `ON UPDATE CURRENT_TIMESTAMP` from
  migrated moment columns. Journal triggers remain the exception to the
  application writer; they also write milliseconds. Their monthly partition
  boundaries become numeric milliseconds.
- **A calendar date stays `DATE`; a duration carries its unit in its name.**
  Neither becomes an absolute moment. Primary keys, foreign keys, counters,
  sizes and durations do not change sign along with a moment beside them.
- **Introduce the norm and agent instructions before moving tables.** State
  the temporary mixed state explicitly. Every migration owns its complete
  path and removes its own named transition debt.
- **Enforce the physical type and obvious direct writer errors.** The future
  guard must distinguish signed `BIGINT` from the broader integer family and
  reject direct seconds or SQL datetime writes to a moment, such as `time()`
  where `TimeHelper::nowMs()` is required. A temporary named inventory may
  cover existing violations; new violations are forbidden. The owner accepted
  the cost of the detector and its tests.
- **Stand data may be recreated.** There is no production installation whose
  old data must survive this migration. Test users, messages, settings and
  history may be lost and populated again. Neither a preserving conversion
  nor backward compatibility for old values is required. This approval is a
  policy for the implementation leaves; this research performs no reset.

The guard does not prove the unit of an arbitrary `int` through every variable,
method and calculation. This decision does not ban every `time()` or `date()`
call. How a column declares that it is a moment, how a project declares a
different format and how those declarations are checked remain exact contracts
for the foundation interview. No new enum, Entity metadata, runtime wrapper or
value object is silently approved by this report.

## Scope And Project Opt-Out

The standard is **mandatory for a framework-owned column**, including that
column in a project's inherited Entity chain. A project cannot change the
framework field's representation by subclassing it.

For a project's own moment columns the standard is also **mandatory by
default**. Another representation is allowed only by an explicit project
choice. The project handles **all consequences** of that choice, including its
writers, readers, replication, payloads, exports and checks. This does not make
the rule a recommendation or oblige the framework to supply an adapter for
other formats. The precise declaration and automatic checking of an opt-out
belong to the future implementation contract.

All existing demos migrate. Their old storage types are existing work to move,
not evidence of an explicit opt-out.

The HIL-1528 wire norm and time-zone behavior remain unchanged. The standard
storage/replication path is entirely numeric:

```text
DB signed BIGINT (epoch ms) -> Entity int -> DB_SYNC number -> Entity int
```

The rule covers both `row` and `previous` values in DB_SYNC; there is no raw
DB_SYNC exception. Browser and inter-process moment formats continue to follow
[A Moment On The Wire](time-zones.md#a-moment-on-the-wire). Display zones, cron
calendars and retention boundaries are not redesigned by the storage change.

## Schema Delivery And Data Recreation

HIL-1518/HIL-1519 already supplied the immutable catalog. Use its existing
delivery, documented in [framework migrations](../orm/migrations.md#framework-tables-in-a-project-hil-1326)
and the [Change format](../../../framework/backend/Database/Migration/Change/README.md):

1. Update the current framework stub pair and append a new immutable framework
   `Change` part for every affected schema unit, with its dependencies and
   resulting hashes. Keep Entity metadata and generated journal trigger files
   consistent with the schema.
2. Deliver that delta through `db:framework:pull` to the units selected by each
   project, then apply the resulting files through its ordinary migration
   track. Do not hand-copy the same framework delta into several projects.
3. Give project-owned tables their own project migrations. Account separately
   for the three bootstrap moments: migration initialization owns their DDL
   and they have no stub pair.

[`FrameworkSchemaCatalog::buildUnits()`](../../../framework/backend/Database/Migration/FrameworkSchemaCatalog.php)
at line 155 already lists the bootstrap units (lines 158–161) and the aggregate
analytics and change-log units. Bootstrap-owned change parts use the existing
catalog contract with null stub hashes; they do not need a second delivery
system. Their initialization DDL and existing-table rollout must agree.

Recreating data does not make schema history mutable. Keep genesis, published
change groups and old project SQL untouched. The current stub is the current
shape, while the appended change records how that shape is delivered.

## Inventory

The census counts a framework table once, regardless of how many demos install
it. `chat.event`, `polls.guest` and `tasks.guest` qualify project tables to keep
the two physical `guest` tables distinct; these are not literal database names.

| Source class | Moment columns | Current storage |
|---|---:|---|
| Framework stubs, 29 tables with TIMESTAMP columns | 66 | `TIMESTAMP` |
| Four journal tables | 4 | `DATETIME(6)` |
| Analytics aggregate stub | 35 | `BIGINT UNSIGNED`, already ms |
| Five Status tables | 21 | `BIGINT UNSIGNED`, already ms |
| Identity password change | 1 | `BIGINT UNSIGNED`, already ms |
| Bootstrap DDL outside stubs | 3 | Two `DATETIME`, one `INT UNSIGNED` |
| Project-owned event and guests | 3 | One `DATETIME`, two `TIMESTAMP` |
| **Total** | **133** | **72 physical tables** |

Every row below targets **signed `BIGINT` epoch milliseconds**, retaining the
column's existing null semantics. `:n` after a type identifies the line in its
linked source. Stub links are under
`framework/backend/Database/Migration/Stub/`. Project rows distinguish the
physical SQL declaration from the Entity's broader `PhpType::DATETIME` mapping.

| Subject | Physical table | Columns: current physical type and source line | Target | Source |
|---|---|---|---|---|
| `people` | `hilos_access_log` | `occurred_at` TIMESTAMP:27 | signed BIGINT ms | [Stub/create_hilos_access_log.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_access_log.sql) |
| `people` | `hilos_account_deletion` | `requested_at` TIMESTAMP:21; `effective_at` TIMESTAMP:22; `canceled_at` TIMESTAMP:23; `completed_at` TIMESTAMP:24 | signed BIGINT ms | [Stub/create_hilos_account_deletion.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_account_deletion.sql) |
| `analytics` | `hilos_analytics_user_agent` | `created_ts` BIGINT UNSIGNED:8 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_accept_language` | `created_ts` BIGINT UNSIGNED:17 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_page` | `created_ts` BIGINT UNSIGNED:25 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_page_params` | `created_ts` BIGINT UNSIGNED:34 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_action_name` | `created_ts` BIGINT UNSIGNED:42 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_signal_name` | `created_ts` BIGINT UNSIGNED:50 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_cron_name` | `created_ts` BIGINT UNSIGNED:58 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_payload_json` | `created_ts` BIGINT UNSIGNED:67 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_browser_session` | `first_seen_ts` BIGINT UNSIGNED:79; `last_seen_ts` BIGINT UNSIGNED:80 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_browser_session_alias` | `created_ts` BIGINT UNSIGNED:95 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_browser_session_user_agent_change` | `changed_ts` BIGINT UNSIGNED:105 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_browser_session_accept_language_change` | `changed_ts` BIGINT UNSIGNED:120 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_ws_connection` | `opened_ts` BIGINT UNSIGNED:136; `closed_ts` BIGINT UNSIGNED:137 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_ws_connection_ipv4_change` | `changed_ts` BIGINT UNSIGNED:150 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_ws_connection_ipv6_change` | `changed_ts` BIGINT UNSIGNED:161 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_page_session` | `opened_ts` BIGINT UNSIGNED:173; `closed_ts` BIGINT UNSIGNED:174 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_person_event` | `created_ts` BIGINT UNSIGNED:197 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_worker_session` | `started_ts` BIGINT UNSIGNED:216; `stopped_ts` BIGINT UNSIGNED:217 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_agent_session` | `started_ts` BIGINT UNSIGNED:230; `stopped_ts` BIGINT UNSIGNED:231 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_user_action` | `created_ts` BIGINT UNSIGNED:247 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_agent_user_action` | `created_ts` BIGINT UNSIGNED:267 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_agent_system_signal` | `created_ts` BIGINT UNSIGNED:285 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_agent_cron_signal` | `created_ts` BIGINT UNSIGNED:300 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_worker_system_signal` | `created_ts` BIGINT UNSIGNED:315 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_api_request` | `started_ts` BIGINT UNSIGNED:334; `finished_ts` BIGINT UNSIGNED:335 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_api_agent_action` | `created_ts` BIGINT UNSIGNED:352 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_journal_file` | `loaded_ts` BIGINT UNSIGNED:370 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `analytics` | `hilos_analytics_loss` | `from_ts` BIGINT UNSIGNED:381; `to_ts` BIGINT UNSIGNED:382 | signed BIGINT ms | [Stub/create_hilos_analytics.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_analytics.sql) |
| `auth` | `hilos_auth_block` | `blocked_until` TIMESTAMP:23; `updated_at` TIMESTAMP:24 | signed BIGINT ms | [Stub/create_hilos_auth_block.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_auth_block.sql) |
| `journal` | `hilos_change_log_receipt` | `created_at` DATETIME(6):24 | signed BIGINT ms | [Stub/create_hilos_change_log.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_change_log.sql) |
| `journal` | `hilos_change_log` | `created_at` DATETIME(6):44 | signed BIGINT ms | [Stub/create_hilos_change_log.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_change_log.sql) |
| `journal` | `hilos_change_log_change` | `created_at` DATETIME(6):61 | signed BIGINT ms | [Stub/create_hilos_change_log.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_change_log.sql) |
| `journal` | `hilos_change_log_value` | `created_at` DATETIME(6):79 | signed BIGINT ms | [Stub/create_hilos_change_log.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_change_log.sql) |
| `exports_legal` | `hilos_data_export` | `requested_at` TIMESTAMP:11; `finished_at` TIMESTAMP:12; `expires_at` TIMESTAMP:13 | signed BIGINT ms | [Stub/create_hilos_data_export.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_data_export.sql) |
| `files` | `hilos_file` | `created_at` TIMESTAMP:33 | signed BIGINT ms | [Stub/create_hilos_file.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_file.sql) |
| `files` | `hilos_file_variant` | `created_at` TIMESTAMP:17 | signed BIGINT ms | [Stub/create_hilos_file_variant.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_file_variant.sql) |
| `auth` | `hilos_identity` | `password_changed_at` BIGINT UNSIGNED:28; `created_at` TIMESTAMP:30; `updated_at` TIMESTAMP:31 | signed BIGINT ms | [Stub/create_hilos_identity.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_identity.sql) |
| `exports_legal` | `hilos_legal_acceptance` | `accepted_at` TIMESTAMP:11 | signed BIGINT ms | [Stub/create_hilos_legal_acceptance.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_legal_acceptance.sql) |
| `exports_legal` | `hilos_legal_acceptance_export` | `requested_at` TIMESTAMP:14; `finished_at` TIMESTAMP:15; `expires_at` TIMESTAMP:16 | signed BIGINT ms | [Stub/create_hilos_legal_acceptance_export.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_legal_acceptance_export.sql) |
| `notifications` | `hilos_notification` | `read_at` TIMESTAMP:25; `created_at` TIMESTAMP:26 | signed BIGINT ms | [Stub/create_hilos_notification.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_notification.sql) |
| `notifications` | `hilos_notification_delivery` | `created_at` TIMESTAMP:24; `updated_at` TIMESTAMP:25; `delivered_at` TIMESTAMP:26 | signed BIGINT ms | [Stub/create_hilos_notification_delivery.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_notification_delivery.sql) |
| `notifications` | `hilos_notification_preference` | `created_at` TIMESTAMP:27; `updated_at` TIMESTAMP:28 | signed BIGINT ms | [Stub/create_hilos_notification_preference.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_notification_preference.sql) |
| `auth` | `hilos_oauth_provider` | `created_at` TIMESTAMP:25; `updated_at` TIMESTAMP:26 | signed BIGINT ms | [Stub/create_hilos_oauth_provider.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_oauth_provider.sql) |
| `auth` | `hilos_passkey_credential` | `last_used_at` TIMESTAMP:45; `created_at` TIMESTAMP:46 | signed BIGINT ms | [Stub/create_hilos_passkey_credential.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_passkey_credential.sql) |
| `notifications` | `hilos_push_subscription` | `gone_at` TIMESTAMP:28; `created_at` TIMESTAMP:29; `last_seen_at` TIMESTAMP:30 | signed BIGINT ms | [Stub/create_hilos_push_subscription.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_push_subscription.sql) |
| `auth` | `hilos_registration_reservation` | `code_accepted_at` TIMESTAMP:46; `expires_at` TIMESTAMP:48; `created_at` TIMESTAMP:49 | signed BIGINT ms | [Stub/create_hilos_registration_reservation.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_registration_reservation.sql) |
| `second_factor` | `hilos_second_factor` | `confirmed_at` TIMESTAMP:31; `last_used_at` TIMESTAMP:32; `created_at` TIMESTAMP:33 | signed BIGINT ms | [Stub/create_hilos_second_factor.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_second_factor.sql) |
| `second_factor` | `hilos_second_factor_backup_code` | `used_at` TIMESTAMP:23; `created_at` TIMESTAMP:24 | signed BIGINT ms | [Stub/create_hilos_second_factor_backup_code.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_second_factor_backup_code.sql) |
| `second_factor` | `hilos_second_factor_reset` | `requested_at` TIMESTAMP:21; `effective_at` TIMESTAMP:22; `notified_at` TIMESTAMP:24; `canceled_at` TIMESTAMP:25; `completed_at` TIMESTAMP:26 | signed BIGINT ms | [Stub/create_hilos_second_factor_reset.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_second_factor_reset.sql) |
| `second_factor` | `hilos_second_factor_setting` | `pending_reset_wait_from` TIMESTAMP:26; `app_code_misses_from` TIMESTAMP:28; `app_code_locked_until` TIMESTAMP:30; `updated_at` TIMESTAMP:31 | signed BIGINT ms | [Stub/create_hilos_second_factor_setting.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_second_factor_setting.sql) |
| `second_factor` | `hilos_second_factor_trust` | `trusted_until` TIMESTAMP:18; `created_at` TIMESTAMP:19 | signed BIGINT ms | [Stub/create_hilos_second_factor_trust.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_second_factor_trust.sql) |
| `auth` | `hilos_session` | `created_at` TIMESTAMP:105; `last_seen_at` TIMESTAMP:106; `expires_at` TIMESTAMP:107; `pending_registration_since` TIMESTAMP:109; `pending_second_factor_until` TIMESTAMP:113 | signed BIGINT ms | [Stub/create_hilos_session.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_session.sql) |
| `status` | `hilos_status_incident` | `detected_at` BIGINT UNSIGNED:9; `publish_at` BIGINT UNSIGNED:10; `published_at` BIGINT UNSIGNED:11; `suppressed_at` BIGINT UNSIGNED:12; `resolved_at` BIGINT UNSIGNED:13 | signed BIGINT ms | [Stub/create_hilos_status_incident.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_status_incident.sql) |
| `status` | `hilos_status_incident_update` | `at` BIGINT UNSIGNED:8 | signed BIGINT ms | [Stub/create_hilos_status_incident_update.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_status_incident_update.sql) |
| `status` | `hilos_status_maintenance_period` | `started_at` BIGINT UNSIGNED:9; `last_observed_at` BIGINT UNSIGNED:10; `ended_at` BIGINT UNSIGNED:11; `completion_observed_at` BIGINT UNSIGNED:12 | signed BIGINT ms | [Stub/create_hilos_status_maintenance_period.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_status_maintenance_period.sql) |
| `status` | `hilos_status_subscriber` | `subscribed_at` BIGINT UNSIGNED:4; `confirmed_at` BIGINT UNSIGNED:6; `confirmation_expires_at` BIGINT UNSIGNED:8 | signed BIGINT ms | [Stub/create_hilos_status_subscriber.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_status_subscriber.sql) |
| `status` | `hilos_status_work` | `starts_at` BIGINT UNSIGNED:6; `ends_at` BIGINT UNSIGNED:7; `created_at` BIGINT UNSIGNED:8; `updated_at` BIGINT UNSIGNED:9; `canceled_at` BIGINT UNSIGNED:10; `consumed_at` BIGINT UNSIGNED:11; `expired_at` BIGINT UNSIGNED:12; `last_mail_queued_at` BIGINT UNSIGNED:14 | signed BIGINT ms | [Stub/create_hilos_status_work.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_status_work.sql) |
| `auth` | `hilos_step_up` | `confirmed_until` TIMESTAMP:13; `created_at` TIMESTAMP:14 | signed BIGINT ms | [Stub/create_hilos_step_up.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_step_up.sql) |
| `people` | `hilos_user` | `last_activity` TIMESTAMP:11 | signed BIGINT ms | [Stub/create_hilos_user.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_user.sql) |
| `people` | `hilos_user_merge` | `merged_at` TIMESTAMP:16 | signed BIGINT ms | [Stub/create_hilos_user_merge.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_user_merge.sql) |
| `people` | `hilos_user_photo` | `set_at` TIMESTAMP:8 | signed BIGINT ms | [Stub/create_hilos_user_photo.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_user_photo.sql) |
| `people` | `hilos_user_rename` | `renamed_at` TIMESTAMP:18 | signed BIGINT ms | [Stub/create_hilos_user_rename.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_user_rename.sql) |
| `auth` | `hilos_user_verification` | `expires_at` TIMESTAMP:54; `consumed_at` TIMESTAMP:55; `created_at` TIMESTAMP:56 | signed BIGINT ms | [Stub/create_hilos_user_verification.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_user_verification.sql) |
| `auth` | `hilos_verifier_circle` | `created_at` TIMESTAMP:31 | signed BIGINT ms | [Stub/create_hilos_verifier_circle.sql](../../../framework/backend/Database/Migration/Stub/create_hilos_verifier_circle.sql) |
| `foundation` | `hilos_migration_claim` | `claimed_at` DATETIME:139 | signed BIGINT ms | [Database/Migration.php](../../../framework/backend/Database/Migration.php) |
| `foundation` | `hilos_admin_view_mode_latch` | `closed_at` INT UNSIGNED:149 | signed BIGINT ms | [Database/Migration.php](../../../framework/backend/Database/Migration.php) |
| `foundation` | `hilos_database_marker` | `written_at` DATETIME:159 | signed BIGINT ms | [Database/Migration.php](../../../framework/backend/Database/Migration.php) |
| `project` | `chat.event` | `timestamp` DATETIME:28 | signed BIGINT ms | [SQL](../../../demo/chat/backend/Database/Migration/Schema/007_remove_unused_tables_and_fields.sql); [Entity mapping:40](../../../demo/chat/backend/Database/Entity/Item/Event.php) |
| `project` | `polls.guest` | `created_at` TIMESTAMP:26 | signed BIGINT ms | [SQL](../../../demo/polls/backend/Database/Migration/Schema/009_create_guest.sql); [Entity mapping:46](../../../demo/polls/backend/Database/Entity/Item/Guest.php) |
| `project` | `tasks.guest` | `created_at` TIMESTAMP:26 | signed BIGINT ms | [SQL](../../../demo/tasks/backend/Database/Migration/Schema/009_create_guest.sql); [Entity mapping:47](../../../demo/tasks/backend/Database/Entity/Item/Guest.php) |

### What The Count Excludes

[`Migration::initialize()`](../../../framework/backend/Database/Migration.php)
creates `migration` at lines 128–132 with `index` and `failed`; it has no
moment. The three moments in that file are counted separately above.

Historical SQL alone does not establish a live column:

- Chat's [migration 007](../../../demo/chat/backend/Database/Migration/Schema/007_remove_unused_tables_and_fields.sql)
  drops `message` at line 18. Messages now refer to `event.timestamp` through
  `event_message`; do not migrate the removed `message.timestamp` again.
- Chat's [migration 010](../../../demo/chat/backend/Database/Migration/Schema/010_rename_bot_moderator_id_columns.sql)
  removes `created_at` and `updated_at` from both `bot` and `moderator` at
  lines 10–16. [Migration 011](../../../demo/chat/backend/Database/Migration/Schema/011_moderator_to_prompt_pieces.sql)
  drops `moderator` at line 8. The live bot has no moment columns.
- Former project user, session and rename tables have graduated to the
  framework. Count their current framework sources once, rather than adding
  obsolete project definitions. The live project `guest.created_at` columns
  in Polls and Tasks remain in the census.

The journal's accepted target precision is milliseconds, replacing the present
`DATETIME(6)` precision. The decision to recreate stand data removes the need
to preserve the old microseconds. Equal milliseconds still require tie-breakers.

## Boundaries Of Each Migration

Move each field through its entire path together: physical schema, Entity,
Object and View, every writer, SQL predicates and affected indexes, runtime and
browser adapters, keyset anchors, exports, seed data, test-only commands and the
checks that prove that field's behavior. A writer in another domain directory
still belongs to the migration of the field it writes. In particular, second
factor code writing a session deadline is part of the session-field migration.
There is no final leaf that receives everybody else's missing tests.

### Physical Type And Bootstrap Readers

[`PhpType::forMysqlType()`](../../../framework/backend/Database/PhpType.php)
at lines 53–69 maps integer families to `INTEGER` and datetime families to
`DATETIME`. It proves neither signed `BIGINT` nor milliseconds. The existing
[`EntitySchemaAudit`](../../../framework/backend/Database/Schema/EntitySchemaAudit.php)
is therefore not already the promised storage guard.

The bootstrap path includes
[`MigrationClaim`](../../../framework/backend/Database/MigrationClaim.php)
(`NOW()` writes at lines 59 and 77, row read at 125–130),
[`DatabaseMarker`](../../../framework/backend/Database/DatabaseMarker.php)
(`NOW()` write at 85, read at 118–130, restore write at 145), and
[`AdminViewModeLatchTable`](../../../framework/backend/AdminViewMode/AdminViewModeLatchTable.php)
(read at 47–58, write at 79). Their row objects and callers must agree with the
new units; changing the DDL alone leaves their readers or writers behind.

### Writers And Temporary SQL Helpers

[`TimeHelper`](../../../framework/backend/Utils/Helpers/TimeHelper.php) has
`getSqlDateTime()` at line 73, `nowMs()` at 88 and `sqlToMs()` at 106.
[`SecondFactorTrusts`](../../../framework/backend/Database/Object/Collection/SecondFactorTrusts.php)
still writes a string at line 88. Keep old helpers available while an unmoved
table still needs them, then remove them after their last consumers migrate.

For DB-defaulted columns, including identity and OAuth provider timestamps,
enumerate every application writer, even for fields the ORM does not currently
read. Removing the DEFAULT without adding the writer leaves an incomplete
migration. The current ownership distinction is documented in
[DB-level defaults](../orm/entity.md#who-owns-the-value-of-a-column-with-a-db-level-default).

### Notification Keyset Windows

[`HilosNotificationDeliveriesTable`](../../../framework/backend/Tables/Communications/HilosNotificationDeliveriesTable.php)
adapts SQL and millisecond anchors in `anchorToSql()` and `anchorToMs()`
(lines 836 and 869). The first currently requires whole-second multiples of
1000 and writes SQL strings; the second converts SQL strings to milliseconds.
Move both directions, row projection and the query together, including nullable
moments. A numeric row DTO alone does not fix pagination over a string boundary.

### Exports And Their File Zone

[`DataExportTime`](../../../framework/backend/DataExport/DataExportTime.php)
already provides `iso(?string, DateTimeZone)` at line 27,
`isoMs(int, DateTimeZone)` at 39, `date(string, DateTimeZone)` at 49 and the
private `moment(int, DateTimeZone)` at 59. The millisecond path already handles
`-1` ms and a `.123` fractional second; the existing
[`DataExportTimeTest`](../../../framework/tests/Unit/DataExportTimeTest.php)
records those cases. Do not multiply an already numeric ms source by 1000.

Move each section to the ms path with its source. Preserve null explicitly
where `isoMs()` takes a non-null integer. Other sections whose sources remain
SQL strings keep their appropriate reader until they migrate; this is a staged
migration of different sources, not compatibility with old values of one field.
File-name date readers also move with their source.

[`FrameworkDataExportSections`](../../../framework/backend/DataExport/FrameworkDataExportSections.php)
reads fields from several domains, so its individual sections belong to those
fields' migrations, not all to the exports subject.
[`LegalAcceptancesCsv`](../../../framework/backend/Legal/Export/LegalAcceptancesCsv.php)
reads `acceptedAt` through `DataExportTime::iso()` at line 76. Chat's
[`DataExportAgent`](../../../demo/chat/backend/Agents/Hilos/DataExportAgent.php)
reads `sentAt` and `registeredAt` from the same event source at lines 49 and 66.

HIL-1579, HIL-1586 and HIL-1528 are completed prerequisites. Retain the finished
file's IANA-zone snapshot and agreement between its name and contents. Retain
the existing wire behavior and remove obsolete SQL-to-ms projections with each
source. The earlier question of waiting for HIL-1579 is closed.

### Status Incident Update Timeline

The existing `status` subject includes `hilos_status_incident_update.at`,
already epoch milliseconds and `NOT NULL`. Its
[`StatusIncidentUpdatesActions::append()`](../../../framework/backend/Database/Actions/Collection/StatusIncidentUpdatesActions.php)
documents the unit at line 35 and writes the field at 73.
[`StatusAgent`](../../../framework/backend/Status/StatusAgent.php) supplies
`TimeHelper::nowMs()` for team updates (lines 1083–1100) and the transition's
`$nowMs` for automatic updates (1212–1221). Include both writers and their
validation with this field's migration.

The integer passes through the
[`Entity`](../../../framework/backend/Database/Entity/Item/StatusIncidentUpdate.php),
[`Object`](../../../framework/backend/Database/Object/Item/StatusIncidentUpdate.php)
and [`View`](../../../framework/backend/Database/View/Item/StatusIncidentUpdate.php).
The [timeline index](../../../framework/backend/Database/Migration/Stub/create_hilos_status_incident_update.sql)
at line 11 is `(incident_id, at, id)`.
[`Object StatusIncidentUpdates::timelinePortion()`](../../../framework/backend/Database/Object/Collection/StatusIncidentUpdates.php)
at lines 41–65 reads an anchor's `at`, compares `(at, id)` and orders both
descending. Keep that keyset and its `id` tie-breaker together; equal
milliseconds do not identify a unique update.

[`StatusIncidentUpdateData`](../../../framework/backend/Status/DTO/StatusIncidentUpdateData.php)
reads and emits the same integer (lines 38 and 56–59), and
[`StatusIncidentData`](../../../framework/backend/Status/DTO/StatusIncidentData.php)
sorts the browser timeline by descending `(at, id)` at lines 73–74.
[`StatusPublicSnapshotBuilder`](../../../framework/backend/Status/StatusPublicSnapshotBuilder.php)
reads timeline portions at lines 202–213;
[`StatusPublicUpdate`](../../../framework/backend/Status/StatusPublicUpdate.php)
carries `at` into the public file (lines 30–45 and 65–67). The subscriber
notice also takes the update's `at` in `StatusAgent` at lines 1236–1238 as
[`StatusSubscriberNotice::eventAt`](../../../framework/backend/Status/StatusSubscriberNotice.php).
Close these readers, writers, projections and ordering checks in the same
`status` migration. SQL/API implementation remains future work; the outage
machine, UI and public formats are not redesigned here.

### Journal Writers, Partitions And Ordering

[`JournalTriggerRenderer`](../../../framework/backend/Database/ChangeLog/JournalTriggerRenderer.php)
declares `v_created_at DATETIME(6)` at line 108 and sets `UTC_TIMESTAMP(6)` at
114. [`ChangeLogPartitions`](../../../framework/backend/Database/ChangeLog/ChangeLogPartitions.php)
reads the UTC month at 73, checks the partition expression at 110 and builds
date-string boundaries at 151–152. Schema, receipt writers, generated trigger
files and stamps, startup/restore checks, partition boundaries, readers and
their anchors must converge in one migration.

Use millisecond partition bounds while retaining the journal's calendar
meaning. Preserve ordering tie-breakers: neither a second nor a millisecond
is unique. The exact SQL expression for writing ms and the affected index and
trigger contracts belong to that future leaf's interview.

### Project Event Payload And Readers

Chat's [`Object Event`](../../../demo/chat/backend/Database/Object/Item/Event.php)
still casts `timestamp` to string at line 60 and serializes it at 75;
[`View Event`](../../../demo/chat/backend/Database/View/Item/Event.php)
exposes the string at lines 30 and 59.
[`types/Event.ts`](../../../demo/chat/frontend/src/types/Event.ts)
declares a string at 26 and calls `readString()` at 79;
[`EventItem.ts`](../../../demo/chat/frontend/src/views/Main/types/lists/EventItem.ts)
expects the stored string at lines 28–29. The project migration must declare
its payload and TypeScript reader adaptation at its own contract interview.
It cannot promise a SQL-only edit while feeding these readers integers. This
does not require a new protocol or screen redesign, and this research changes
no browser contract.

## Proposed Migration Cut And Order

These are **13 proposed subjects**, identified only by local keys. They are
input to a separate decomposition of HIL-1803, not created Jira leaves or
permission to create them. The owner's estimate of roughly ten tasks was not
a fixed limit. Concrete cards and dependencies require their own approval.

`rule` precedes `foundation`. Every domain migration follows `foundation`;
`closure` follows all migrations. Refine actual field/writer dependencies at
decomposition, including the auth session contract used by second factor.
Sharing a file alone does not impose a serial queue on unrelated domains.

| Subject | Columns | Result and principal sources | Order and closure |
|---|---:|---|---|
| `rule` | — | Connect the standard and transition state to `docs/agents/architecture/time-zones.md`, `docs/agents/orm/entity.md`, `skills/hilos-orm/SKILL.md`, `skills/hilos-code-style-php/SKILL.md` and `skills/hilos-signals/SKILL.md`. | First. Publish the norm and routes before tables move; do not claim that the guard exists. |
| `foundation` | 3 | Necessary ORM/time contracts and guard: `framework/backend/Database/PhpType.php`, `Utils/Helpers/TimeHelper.php`, `Database/Schema/EntitySchemaAudit.php`; bootstrap DDL in `Database/Migration.php`, with `MigrationClaim.php`, `DatabaseMarker.php` and `AdminViewMode/AdminViewModeLatchTable.php`. | After `rule`. Settle moment/opt-out declarations at this leaf's interview. Establish named old debt, assigned to the actual migration leaves. Close all three bootstrap readers and writers. |
| `auth` | 23 | Session, identity, auth block, verification, registration reservation, OAuth provider, passkey credential, step-up and verifier circle. Entry points: `framework/backend/Database/Object/Collection/Sessions.php`, `UserVerifications.php` and `framework/backend/Auth/Registration/RegistrationReservationService.php`. | After `foundation`. Close the full field path, TTL arithmetic, queries/indexes, projection, cursors, export sections and test commands. Include session-field writers in second factor code. |
| `second_factor` | 16 | The five second-factor tables; `framework/backend/Database/Object/Collection/SecondFactorTrusts.php` and `Database/Migration/Stub/create_hilos_second_factor_setting.sql`. | After `foundation`, coordinated with the auth session-field contract. Move deadline arithmetic and notifications without changing their time-zone policy. |
| `notifications` | 10 | Notification, preference, delivery and push subscription; `framework/backend/Tables/Communications/HilosNotificationDeliveriesTable.php` and `framework/backend/Notification/DeliveryLogPruner.php`. | After `foundation`. Initial/live reads, keyset anchors, nullable moments, channel writers and relevant export sections move together. Remove SQL/ms adapters only for migrated sources. |
| `people` | 9 | User, rename, merge, photo, access log and account deletion; their stubs and `framework/backend/DataExport/FrameworkDataExportSections.php`. | After `foundation`. Include people-agent and library writers, test commands and exports. Preserve access rights, time zones and retention/deadline policies. |
| `exports_legal` | 7 | Legal acceptance, data-export queue and legal-acceptance-export queue; `framework/backend/DataExport/DataExportTime.php`, `FrameworkDataExportSections.php` and `framework/backend/Legal/Export/LegalAcceptancesCsv.php`. | After `foundation`. Preserve the file's IANA-zone snapshot, name/content agreement, absence and ms precision. Do not feed new integers into SQL readers of other unmoved sections. |
| `files` | 2 | File and variant creation moments; `framework/backend/Database/Migration/Stub/create_hilos_file.sql` and `create_hilos_file_variant.sql`. | After `foundation`. Include creation, readers, cleanup and projections. Sizes, durations and access rights retain their meaning. |
| `analytics` | 35 | Already-ms moments in `framework/backend/Database/Migration/Stub/create_hilos_analytics.sql`; `framework/backend/Core/Analytics/AnalyticsPersonExportEvent.php` is an export entry point. | After `foundation`. Remove UNSIGNED from moments and update migration sources. Preserve `*_ts` names, ms units, IDs/FKs and counters. Do not redesign cubes, rebuilding or event formats. |
| `status` | 21 | Already-ms incident, incident-update, subscriber, work and maintenance-period moments; their five stubs and `framework/backend/Status/StatusAgent.php`. | After `foundation`. Remove UNSIGNED from moments and update migration sources. Close the [incident-update timeline](#status-incident-update-timeline), including writers, readers, index, keyset anchors and tie-breakers. Preserve the outage machine, subscriptions, public static schema and display formats. |
| `journal` | 4 | `framework/backend/Database/ChangeLog/JournalTriggerRenderer.php`, `ChangeLogPartitions.php` and `Database/Migration/Stub/create_hilos_change_log.sql`. | After `foundation`. Close schema, receipts, triggers/files/stamps, startup/restore, partitions, readers and anchors together. Preserve tie-breakers; settle exact SQL and index contracts at interview. |
| `project` | 3 | Chat `backend/Database/{Entity,Object,View}/Item/Event.php`, `backend/Agents/Hilos/DataExportAgent.php`, `frontend/src/types/Event.ts`, `frontend/src/views/Main/types/lists/EventItem.ts`; Polls/Tasks `backend/Database/Entity/Item/Guest.php`. | After `foundation`. Project SQL, the whole ORM chain, writers, seeds, readers and exports belong here. Declare the chat payload/TS adaptation explicitly. Exclude removed message and bot moments. |
| `closure` | — | Residual SQL-time helpers in `framework/backend/Utils/Helpers/TimeHelper.php` and `framework/backend/DataExport/DataExportTime.php`; transition text in `docs/agents/architecture/time-zones.md` and `docs/agents/code-style/automated-checks.md`. | After every migration. Remove actual remaining adapters and transition debt. Check the whole map for signed BIGINT and old moment writers without an explicit project opt-out. Each preceding leaf supplies its own coverage and pays its own debt. |

Paths in this table abbreviated after a full framework or demo prefix stay
under that prefix. The inventory supplies every physical field; the entry
points name important consumers, not an exhaustive file allowlist.

## Remaining Implementation Contracts And Neighbors

The approved target does not preapprove the exact Entity/RT field declarations,
DTOs, signal constants, routes, indexes, guard metadata or helper API of any
future migration. Each leaf presents its actual affected contracts at its own
interview under the [repository contract gate](../../../agents.md#contract-approval-gate-hard-stop).
This research's list of changed implementation contract surfaces is empty.

The next decomposition starts with the settled decisions, all 133 assigned
columns, the 13 subjects and the critical reader/writer seams above. It checks
the then-current tree and explicit cross-domain dependencies; it does not
reopen the owner's type, unit, naming, project-default or data-loss decisions.

**Neighbor update, 2026-11-04:** HIL-1793's approved result is now published in
[calculated-results.md](calculated-results.md). It owns invalidation/reset and
the warning when the project's zone changes, including the accepted loss of
OLAP history; a reader's zone is separate. This storage research neither takes
over that policy nor changes cubes or zones. Read that result again before a
future leaf changes the shared time-zone canon. The census sources themselves
are unchanged since the research base; the checked export-agent changes concern
cron-rule origin declarations, not moment storage or file-zone behavior.

## Validation Of This Research

Completeness was checked against all current framework up-stubs: every one of
their 127 moment declarations matches a unique `(table, column, physical type,
source, line)` entry above. The 66 TIMESTAMP fields occupy 29 tables; the 57
unsigned BIGINT moments split into 35 analytics, 21 Status and one identity
field. The remaining four stub moments are the journal's DATETIME(6) fields.
The three bootstrap DDL fields and their writers were read separately. The
demo Entity mappings and project migration chains establish the other three
physical fields; obsolete historical declarations were excluded. Every entry
has exactly one proposed migration subject and the subject totals sum to 133.

For this documentation publication, check the approved decisions, source
coordinates, local Markdown references and `git diff --check`, then run the
existing repository-root target through the normal Docker test runner:

```bash
composer run test:framework:unit -- --filter AgentDocGuardTest
```

No new test is needed for this report. Schema/integration/e2e checks, resets
and full cross-demo runs belong to the implementation changes they exercise;
each future leaf selects its own checks under [testing.md](../testing.md).
