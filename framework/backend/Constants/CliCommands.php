<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * CliCommands - CLI command name constants.
 *
 * Defines all available CLI command names used throughout the framework.
 * Centralized command name management prevents typos and ensures consistency.
 */
final class CliCommands
{
    /** @var string Command: Show daemon status */
    public const string DAEMON_STATUS = 'daemon:status';

    /** @var string Command: Monitor daemon in real-time */
    public const string DAEMON_MONITOR = 'daemon:monitor';

    /** @var string Command: Ping the daemon over the command channel */
    public const string DAEMON_PING = 'daemon:ping';

    /** @var string Command: List the cluster nodes the daemon knows about */
    public const string CLUSTER_NODES = 'cluster:nodes';

    /** @var string Command: Reload cluster config and re-announce the local node */
    public const string CLUSTER_RELOAD = 'cluster:reload';

    /** @var string Command: Inspect the daemon's cluster/consensus/placement state (test-only) */
    public const string CLUSTER_TEST_INSPECT = 'cluster:test:inspect';

    /** @var string Command: Show help information */
    public const string HELP = 'help';

    /** @var string Command: Apply pending migrations */
    public const string MIGRATION_UP = 'db:migration:up';

    /** @var string Command: Rollback migrations */
    public const string MIGRATION_DOWN = 'db:migration:down';

    /** @var string Command: Show migration status */
    public const string MIGRATION_STATUS = 'db:migration:status';

    /** @var string Command: Retry failed migration */
    public const string MIGRATION_RETRY = 'db:migration:retry';

    /** @var string Command: Apply database seeds */
    public const string SEED_APPLY = 'db:seed:apply';

    /** @var string Command: Show database schema status */
    public const string DB_SCHEMA_STATUS = 'db:schema:status';

    /** @var string Command: Wait for MySQL to become ready */
    public const string DB_WAIT = 'db:wait';

    /** @var string Command: Reset test database (DROP, migrate, seed) */
    public const string DB_TEST_RESET = 'db:test:reset';

    /** @var string Command: Expire an active auth verification challenge (test-only) */
    public const string VERIFICATION_TEST_EXPIRE = 'verification:test:expire';

    /** @var string Command: Verify stored backup archives against their recorded checksums */
    public const string BACKUP_VERIFY = 'backup:verify';

    /** @var string Command: Age a stored backup's sidecar createdAt into the past (test-only) */
    public const string BACKUP_TEST_AGE = 'test:backup:age';

    /** @var string Command: Force a backup retention prune through the live agent (test-only) */
    public const string BACKUP_TEST_PRUNE = 'test:backup:prune';

    /** @var string Command: Force a scheduled backup through the live agent (test-only) */
    public const string BACKUP_TEST_RUN_SCHEDULE = 'test:backup:run-schedule';

    /** @var string Command: Force-close a live WebSocket connection by acceptKey (test-only) */
    public const string CONNECTION_TEST_DROP = 'connection:test:drop';

    /** @var string Command: Resolve an LLM profile and optionally probe its endpoint */
    public const string LLM_PING = 'llm:ping';

    /** @var string Command: Bulk-seed N fixture users with password identities (test-only) */
    public const string USER_TEST_SEED = 'test:user:seed';
}
