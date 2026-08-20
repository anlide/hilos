<?php

declare(strict_types=1);

namespace Hilos\Database\Schema;

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
 */
final class FrameworkTablesWithoutEntity
{
    /**
     * Returns the framework tables that no Entity maps, in the form the audit subtracts.
     *
     * Kept in step with the DDL the framework ships:
     * {@see Migration::initialize()} for `migration`, and the
     * `create_hilos_analytics.sql` / `create_hilos_change_log.sql` stubs for the rest.
     * A table a project does not create is simply never seen by the audit, so the
     * framework may declare everything it ships.
     *
     * @return list<string> Table names, unordered
     */
    public static function tables(): array
    {
        return [
            'migration',

            'hilos_change_log',
            'hilos_change_log_table',
            'hilos_change_log_field',
            'hilos_change_log_value',

            'hilos_analytics_user_agent',
            'hilos_analytics_accept_language',
            'hilos_analytics_page',
            'hilos_analytics_page_params',
            'hilos_analytics_action_name',
            'hilos_analytics_signal_name',
            'hilos_analytics_cron_name',
            'hilos_analytics_payload_json',
            'hilos_analytics_browser_session',
            'hilos_analytics_browser_session_user_agent_change',
            'hilos_analytics_browser_session_accept_language_change',
            'hilos_analytics_ws_connection',
            'hilos_analytics_ws_connection_ipv4_change',
            'hilos_analytics_ws_connection_ipv6_change',
            'hilos_analytics_page_session',
            'hilos_analytics_worker_session',
            'hilos_analytics_agent_session',
            'hilos_analytics_user_action',
            'hilos_analytics_agent_user_action',
            'hilos_analytics_agent_system_signal',
            'hilos_analytics_agent_cron_signal',
            'hilos_analytics_worker_system_signal',
            'hilos_analytics_api_request',
            'hilos_analytics_api_agent_action',
        ];
    }
}
