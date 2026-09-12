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
 * Writes an override row for a catalog key, so a test can set up a state the normal UI
 * does not leave behind. Pairs with {@see SettingOverrideTestDeleteCommand}, which restores the
 * baseline. The value of this specific command is to demonstrate the mechanism, not a real
 * feature.
 */
final class SettingOverrideTestCreateCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Settings rows this command writes, claimed for it by its runner */
    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::BY_KIND];

    /** @var string Catalog key the example override row is written for */
    private const string OVERRIDDEN_KEY = SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING;

    /** @var string Override the example row carries — a row without a value does not exist */
    private const string OVERRIDE_VALUE = 'scaffold example override';

    public function getName(): string
    {
        return CliCommands::SETTING_OVERRIDE_TEST_CREATE;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'writes the scaffold\'s example override by hand on a stand, in the same daemon-less window '
            . 'its delete half undoes it in',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: write the example catalog key\'s override row';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Writes an override row for the example catalog key so a test can set up that state.
Remove it again with the matching delete command.
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
        Hilos::$db->settings->actions->add(self::OVERRIDDEN_KEY, self::OVERRIDE_VALUE, Hilos::$setting->catalog());
        echo "Override written for catalog key '" . self::OVERRIDDEN_KEY . "'.\n";

        return ExitCode::SUCCESS;
    }
}
