<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingNotInCatalogException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;

/**
 * SCAFFOLD/EXAMPLE of the test-only command mechanism (see docs/agents/cli/commands.md).
 *
 * Writes a settings row for a catalog key, so a test can set up a state the normal UI
 * does not leave behind. Pairs with {@see OrphanSettingTestDeleteCommand}, which restores the
 * baseline. The value of this specific command is to demonstrate the mechanism, not a real
 * feature.
 */
final class OrphanSettingTestCreateCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Settings rows this command writes, claimed for it by its runner */
    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::BY_KIND];

    /** @var string Catalog key the example row is written for */
    private const string ORPHAN_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    /** @var string Override the example row carries — a row without a value does not exist */
    private const string ORPHAN_VALUE = 'scaffold example override';

    public function getName(): string
    {
        return CliCommands::ORPHAN_SETTING_TEST_CREATE;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'seeds a cataloged settings row from composer test:db-prepare, before the stand\'s daemon starts',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: write the example settings row';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Writes a settings row with a custom value for the example catalog key so a test can set
up that state. Restore the baseline with the matching delete command.
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws SettingNotInCatalogException When the example key is not in the settings catalog
     * @throws SettingValueRefusedException When the example key declares a catalog rule the value fails
     * @throws DatabaseException When the settings write fails
     */
    protected function run(array $options, array $args): int
    {
        Hilos::$db->settings->actions->add(self::ORPHAN_KEY, self::ORPHAN_VALUE, Hilos::$setting->catalog());
        echo "Setting created for key '" . self::ORPHAN_KEY . "'.\n";

        return ExitCode::SUCCESS;
    }
}
