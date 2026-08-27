<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingKeyInCatalogException;
use Hilos\Hilos;

/**
 * Test-only: delete a TRUE orphan settings row for a key that is NOT in the catalog.
 *
 * Symmetric tear-down for {@see CreateOrphanCommand}: removes the persisted orphan row
 * for a non-catalog key, restoring the baseline. Refuses a catalog key (its row would be
 * an override, not an orphan) and refuses if no row for the key exists, so a fixture's
 * setup/cleanup mismatch surfaces instead of silently passing.
 */
final class DeleteOrphanCommand extends TestOnlyCommand
{
    /** @var string Truth-source id this CLI writer registers under (no agent runs in the CLI) */
    private const string TRUTH_SOURCE_ID = 'test-cli';

    public function getName(): string
    {
        return ChatCliCommands::DELETE_ORPHAN;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            "temporary until HIL-729 moves chat's CLI into the framework: deletes the seeded settings row straight from the table",
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: delete a true orphan settings row for a non-catalog key';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Deletes the persisted orphan settings row for a non-catalog key, restoring the baseline.
Refuses a catalog key (that would be an override, not an orphan) and refuses if no row for
the key exists. Pairs with the matching create command.

Usage:
  php cli.php test:orphan:delete <key>

Examples:
  php cli.php test:orphan:delete not_in_catalog
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: key
     * @return int Exit code (0 on success)
     * @throws SettingKeyInCatalogException When the key is in the catalog (its row is an override)
     * @throws ItemNotFoundForDeleteException When no orphan row for the key exists
     * @throws DatabaseException When the settings delete fails
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: a positional argument the operator may omit; the usage hint below rejects it
        $key = $args[0] ?? '';
        if ($key === '') {
            echo "Usage: {$this->getName()} <key>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        // The settings collection needs a registered writer; the CLI has no agent, so the command
        // registers itself as the truth source before mutating (only reachable on the test-only path).
        TruthSourceRegistry::register(HilosDbContext::settings, true, self::TRUTH_SOURCE_ID);
        Hilos::$db->settings->actions->deleteOrphan($key, Hilos::$setting->catalog());
        echo "Orphan setting deleted for key '{$key}'.\n";

        return ExitCode::SUCCESS;
    }
}
