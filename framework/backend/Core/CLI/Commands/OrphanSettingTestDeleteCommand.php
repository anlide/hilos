<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;

/**
 * SCAFFOLD/EXAMPLE of the test-only command mechanism (see docs/agents/cli/commands.md).
 *
 * Deletes the orphan settings row written by {@see OrphanSettingTestCreateCommand}, returning
 * the catalog key to its baseline (no DB row). The pair shows how a test sets up and tears down
 * a state through test-only CLI commands instead of idempotency hacks in the test itself.
 */
final class OrphanSettingTestDeleteCommand extends TestOnlyCommand
{
    /** @var string Catalog key whose example orphan row is removed */
    private const string ORPHAN_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    /** @var string Truth-source id this CLI writer registers under (no agent runs in the CLI) */
    private const string TRUTH_SOURCE_ID = 'test-cli';

    public function getName(): string
    {
        return CliCommands::ORPHAN_SETTING_TEST_DELETE;
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
        return 'Test-only: delete the example orphan settings row';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Deletes the example orphan settings row (if present), restoring the catalog key to its
baseline. Pairs with the matching create command.
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
        // The settings collection needs a registered writer; the CLI has no agent, so the command
        // registers itself as the truth source before mutating (only reachable on the test-only path).
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TRUTH_SOURCE_ID);
        if (isset(Hilos::$db->settings[self::ORPHAN_KEY])) {
            Hilos::$db->settings[self::ORPHAN_KEY]->actions->delete();
            echo "Orphan setting deleted for key '" . self::ORPHAN_KEY . "'.\n";

            return ExitCode::SUCCESS;
        }

        echo "No orphan setting for key '" . self::ORPHAN_KEY . "' — nothing to delete.\n";

        return ExitCode::SUCCESS;
    }
}
