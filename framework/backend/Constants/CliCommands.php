<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * CliCommands - CLI command name constants.
 *
 * Defines all available CLI command names used throughout the framework.
 * Centralized command name management prevents typos and ensures consistency.
 */
class CliCommands
{
    /** @var string Command: Show daemon status */
    public const string DAEMON_STATUS = 'daemon:status';

    /** @var string Command: Monitor daemon in real-time */
    public const string DAEMON_MONITOR = 'daemon:monitor';

    /** @var string Command: Show help information */
    public const string HELP = 'help';

    /** @var string Command: Apply pending migrations */
    public const string MIGRATION_UP = 'migration:up';

    /** @var string Command: Rollback migrations */
    public const string MIGRATION_DOWN = 'migration:down';

    /** @var string Command: Show migration status */
    public const string MIGRATION_STATUS = 'migration:status';

    /** @var string Command: Retry failed migration */
    public const string MIGRATION_RETRY = 'migration:retry';

    /** @var string Command: Apply database seeds */
    public const string SEED_APPLY = 'seed:apply';

    /** @var string Command: Show database schema status */
    public const string DB_SCHEMA_STATUS = 'db:schema:status';

    /** @var string Command: Compare Entity files with database schema */
    public const string DB_ENTITY_DIFF = 'db:entity:diff';

    /** @var string Command: Fix Entity files to match database schema */
    public const string DB_ENTITY_FIX = 'db:entity:fix';

    /** @var string Command: Fix Object files to match Entity files */
    public const string DB_OBJECT_FIX = 'db:object:fix';

    /** @var string Command: Wait for MySQL to become ready */
    public const string DB_WAIT = 'db:wait';

    /** @var string Command: Reset test database (DROP, migrate, seed) */
    public const string DB_TEST_RESET = 'db:test:reset';
}
