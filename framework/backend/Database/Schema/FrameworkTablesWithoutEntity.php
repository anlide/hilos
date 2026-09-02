<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Migration;

/**
 * FrameworkTablesWithoutEntity - the framework's own tables that live outside the ORM.
 *
 * The schema-to-Entity direction of {@see EntitySchemaAudit::auditTableCoverage()} calls
 * a live table with no Entity behind it a finding, and a project cannot be asked to
 * account for the framework's own: it did not migrate them, and the audit would then
 * fail on every project that adopts a new framework subsystem. So the framework names
 * its own here, and a project adds its own through the audit's parameter.
 *
 * Three groups, each outside the ORM for the same reason - they are written by code
 * that runs before or beneath an Entity, so an Entity would buy nothing:
 *
 * - **`migration`** is created by {@see Migration} itself, before any
 *   migration has run and therefore before any Entity's table exists.
 * - **The change-log tables** record what changed in the other tables; a row of theirs
 *   is written by the log, not loaded as a domain object.
 * - **The analytics tables** are append-only facts and their dictionaries, written in
 *   bulk on the hot path.
 *
 * Entity classes for the last two groups are wanted and belong to HIL-352 and HIL-351;
 * until those land, the tables are declared here rather than passing unnoticed.
 *
 * Names are spelled out one by one, never matched by a `hilos_analytics_*` prefix: a
 * prefix rule would also wave through a table nobody meant to ship, which is exactly
 * the silence this audit exists to break.
 *
 * The same tables carry the framework's personal-data verdict for the same reason a
 * table with an Entity carries it on the Entity: the verdict belongs where the table is
 * declared, so a column added to one of these is classified in the file it was added to.
 */
final class FrameworkTablesWithoutEntity implements TablesWithoutEntityProvider
{
    /**
     * Returns the framework tables that no Entity maps, in the form the audit subtracts.
     *
     * Derived from {@see self::pii()} rather than listed a second time: a table declared
     * in one of the two lists and forgotten in the other would be either an unclassified
     * table or an audit finding, and both are silent until a restore or an audit runs.
     *
     * @return list<string> Table names, unordered
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /**
     * Returns the personal-data verdict of the framework's tables outside the ORM.
     *
     * Kept in step with the DDL the framework ships:
     * {@see Migration::initialize()} for `migration`, and the
     * `create_hilos_analytics.sql` / `create_hilos_change_log.sql` stubs for the rest.
     * A table a project does not create is simply never seen by the gates, so the
     * framework may classify everything it ships.
     *
     * Analytics is classified per column, not per table. Purging it whole is not
     * available: its name and dictionary tables are RESTRICT parents of the fact tables,
     * and the compatibility gate refuses a purge held back by such a key. So the
     * identifying columns are named one by one - tokens hashed, addresses nulled, free
     * text masked - and the two tables whose payload is a NOT NULL `json` column are
     * purged instead, which their all-SET NULL incoming keys allow.
     *
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Table name to its
     *     per-column strategies, or to {@see AnonymizationStrategy::PURGE} for a table emptied whole
     */
    public static function pii(): array
    {
        return [
            'migration' => [],

            'hilos_change_log' => [
                'old_value' => AnonymizationStrategy::MASK,
                'new_value' => AnonymizationStrategy::MASK,
            ],
            'hilos_change_log_table' => [],
            'hilos_change_log_field' => [],
            'hilos_change_log_value' => [
                'old_value' => AnonymizationStrategy::MASK,
                'new_value' => AnonymizationStrategy::MASK,
            ],

            // `sha1_hash` beside each of these two values is deliberately left alone:
            // it carries a UNIQUE deduplication index, and masking it would collapse
            // every row onto one value. A hash out of step with its text is the
            // cheaper of the two damages.
            'hilos_analytics_user_agent' => ['value' => AnonymizationStrategy::MASK],
            'hilos_analytics_accept_language' => ['value' => AnonymizationStrategy::MASK],
            'hilos_analytics_page' => [],
            // Both this and `hilos_analytics_payload_json` hold a NOT NULL `json` column,
            // which the mask is not valid JSON for; every key pointing at them is
            // ON DELETE SET NULL, so emptying them costs the facts their payload and
            // nothing else.
            'hilos_analytics_page_params' => AnonymizationStrategy::PURGE,
            'hilos_analytics_action_name' => [],
            'hilos_analytics_signal_name' => [],
            'hilos_analytics_cron_name' => [],
            'hilos_analytics_payload_json' => AnonymizationStrategy::PURGE,
            'hilos_analytics_browser_session' => [
                'session_token' => AnonymizationStrategy::HASH,
                'user_identity_value' => AnonymizationStrategy::HASH,
            ],
            'hilos_analytics_browser_session_user_agent_change' => [],
            'hilos_analytics_browser_session_accept_language_change' => [],
            'hilos_analytics_ws_connection' => [
                'accept_key' => AnonymizationStrategy::HASH,
                'opened_ipv4' => AnonymizationStrategy::NULLIFY,
                'opened_ipv6' => AnonymizationStrategy::NULLIFY,
            ],
            'hilos_analytics_ws_connection_ipv4_change' => [
                'old_ipv4' => AnonymizationStrategy::NULLIFY,
                'new_ipv4' => AnonymizationStrategy::NULLIFY,
            ],
            'hilos_analytics_ws_connection_ipv6_change' => [
                'old_ipv6' => AnonymizationStrategy::NULLIFY,
                'new_ipv6' => AnonymizationStrategy::NULLIFY,
            ],
            'hilos_analytics_page_session' => [],
            'hilos_analytics_worker_session' => [],
            'hilos_analytics_agent_session' => [],
            'hilos_analytics_user_action' => [],
            'hilos_analytics_agent_user_action' => [],
            'hilos_analytics_agent_system_signal' => [],
            'hilos_analytics_agent_cron_signal' => [],
            'hilos_analytics_worker_system_signal' => [],
            'hilos_analytics_api_request' => [],
            'hilos_analytics_api_agent_action' => [],
        ];
    }

    /**
     * Returns the columns of those tables looked at and found to hold nothing personal.
     *
     * Spelled out column by column rather than derived as "everything not named above":
     * a column added by a migration and left out of both lists is the case the whole
     * per-column verdict exists to catch, and a derived list would swallow it silently.
     * A surrogate key, a foreign key to another framework table and a timestamp of the
     * framework's own writing say nothing about a person on their own; what an analytics
     * fact knows about one it knows through the dictionary rows named in {@see self::pii()}.
     *
     * The two purged tables name none: no row of them survives a restore.
     *
     * @return array<string, list<string>> Table name to its non-personal columns
     */
    public static function piiNotPersonal(): array
    {
        return [
            'migration' => ['index', 'failed'],

            'hilos_change_log' => [
                'id',
                'batch_id',
                'table_id',
                'record_id',
                'field_id',
                'mutation_type',
                'user_id',
                'created_at',
            ],
            'hilos_change_log_table' => ['id', 'name'],
            'hilos_change_log_field' => ['id', 'table_id', 'name'],
            'hilos_change_log_value' => ['id', 'log_id'],

            'hilos_analytics_user_agent' => ['id', 'sha1_hash', 'created_ts'],
            'hilos_analytics_accept_language' => ['id', 'sha1_hash', 'created_ts'],
            'hilos_analytics_page' => ['id', 'page_name', 'created_ts'],
            'hilos_analytics_page_params' => [],
            'hilos_analytics_action_name' => ['id', 'name', 'created_ts'],
            'hilos_analytics_signal_name' => ['id', 'name', 'created_ts'],
            'hilos_analytics_cron_name' => ['id', 'name', 'created_ts'],
            'hilos_analytics_payload_json' => [],
            // `user_identity_type` names the kind of identity the value beside it holds -
            // "user id", "session" - and is the same word for everybody who signed in
            // that way.
            'hilos_analytics_browser_session' => [
                'id',
                'user_identity_type',
                'current_user_agent_id',
                'current_accept_language_id',
                'first_seen_ts',
                'last_seen_ts',
            ],
            'hilos_analytics_browser_session_user_agent_change' => [
                'id',
                'browser_session_id',
                'old_user_agent_id',
                'new_user_agent_id',
                'changed_ts',
            ],
            'hilos_analytics_browser_session_accept_language_change' => [
                'id',
                'browser_session_id',
                'old_accept_language_id',
                'new_accept_language_id',
                'changed_ts',
            ],
            'hilos_analytics_ws_connection' => ['id', 'browser_session_id', 'opened_ts', 'closed_ts'],
            'hilos_analytics_ws_connection_ipv4_change' => ['id', 'ws_connection_id', 'changed_ts'],
            'hilos_analytics_ws_connection_ipv6_change' => ['id', 'ws_connection_id', 'changed_ts'],
            'hilos_analytics_page_session' => [
                'id',
                'ws_connection_id',
                'page_id',
                'page_params_id',
                'opened_ts',
                'closed_ts',
            ],
            'hilos_analytics_worker_session' => [
                'id',
                'worker_index',
                'is_monopolistic',
                'started_ts',
                'stopped_ts',
            ],
            'hilos_analytics_agent_session' => [
                'id',
                'worker_session_id',
                'agent_type',
                'agent_index',
                'started_ts',
                'stopped_ts',
            ],
            'hilos_analytics_user_action' => [
                'id',
                'ws_connection_id',
                'page_session_id',
                'action_name_id',
                'payload_json_id',
                'created_ts',
            ],
            'hilos_analytics_agent_user_action' => [
                'id',
                'agent_session_id',
                'user_action_id',
                'signal_name_id',
                'payload_json_id',
                'created_ts',
            ],
            'hilos_analytics_agent_system_signal' => [
                'id',
                'agent_session_id',
                'signal_name_id',
                'payload_json_id',
                'created_ts',
            ],
            'hilos_analytics_agent_cron_signal' => [
                'id',
                'agent_session_id',
                'cron_name_id',
                'payload_json_id',
                'created_ts',
            ],
            'hilos_analytics_worker_system_signal' => [
                'id',
                'worker_session_id',
                'signal_name_id',
                'payload_json_id',
                'created_ts',
            ],
            // `path` is the route a request took, not who took it; the caller is known
            // through `browser_session_id`.
            'hilos_analytics_api_request' => [
                'id',
                'browser_session_id',
                'method',
                'path',
                'params_json_id',
                'status_code',
                'duration_ms',
                'started_ts',
                'finished_ts',
            ],
            'hilos_analytics_api_agent_action' => [
                'id',
                'api_request_id',
                'agent_session_id',
                'signal_name_id',
                'payload_json_id',
                'created_ts',
            ],
        ];
    }
}
