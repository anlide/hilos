<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;

/**
 * SCAFFOLD/EXAMPLE of the test-only command mechanism (see docs/agents/cli/commands.md).
 *
 * Deletes the override row written by {@see SettingOverrideTestCreateCommand}, returning
 * the catalog key to its baseline (no DB row). The pair shows how a test sets up and tears down
 * a state through test-only CLI commands instead of idempotency hacks in the test itself.
 */
final class SettingOverrideTestDeleteCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Settings rows this command deletes, claimed for it by its runner */
    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::BY_KIND];

    /** @var string Catalog key whose example override row is removed */
    private const string OVERRIDDEN_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    public function getName(): string
    {
        return CliCommands::SETTING_OVERRIDE_TEST_DELETE;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'deletes the cataloged row its own create seeded, in the same daemon-less window that wrote it',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: delete the example catalog key\'s override row';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Deletes the example catalog key's override row (if present), returning the key to its
catalog default. Pairs with the matching create command.
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws DatabaseException When the settings delete fails
     */
    protected function run(array $options, array $args): int
    {
        if (isset(Hilos::$db->settings[self::OVERRIDDEN_KEY])) {
            Hilos::$db->settings[self::OVERRIDDEN_KEY]->actions->delete();
            echo "Override deleted for catalog key '" . self::OVERRIDDEN_KEY . "'.\n";

            return ExitCode::SUCCESS;
        }

        echo "No override for catalog key '" . self::OVERRIDDEN_KEY . "' — nothing to delete.\n";

        return ExitCode::SUCCESS;
    }
}
