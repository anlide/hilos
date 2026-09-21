<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingKeyInCatalogException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Test-only: seed a TRUE orphan settings row for a key that is NOT in the catalog.
 *
 * Unlike the scaffold {@see SettingOverrideTestCreateCommand} (which writes a catalog-key
 * override), this writes a row whose key is absent from the catalog, so it reads back as
 * valueSource=orphan — the state an e2e fixture needs but the normal UI never leaves
 * behind. Pairs with {@see OrphanTestDeleteCommand}.
 */
final class OrphanTestCreateCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Settings rows this command writes, claimed for it by its runner */
    public const array OWNS_DB = [HilosDbContext::settings => TruthSourceOperation::BY_KIND];

    public function getName(): string
    {
        return CliCommands::ORPHAN_TEST_CREATE;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'seeds a settings row from composer test:db-prepare, before the stand\'s daemon starts',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: create a true orphan settings row for a non-catalog key';
    }

    public function getHelp(): string
    {
        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Writes a persisted orphan settings row for a non-catalog key, so a test can set up a
state the normal UI does not leave behind. Refuses a catalog key (that would be an
override, not an orphan) and refuses if a row for the key already exists. Restore the
baseline with the matching delete command.

Usage:
  php cli.php test:orphan:create <key> <type> [value]

  <type> is one of: string, integer, float, boolean
  [value] is optional and, when given, must parse as <type>

Examples:
  php cli.php test:orphan:create not_in_catalog string hello
  php cli.php test:orphan:create stray_flag boolean true
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: key, type, [value]
     * @return int Exit code (0 on success)
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws CreateNotAllowedException When the truth source rejects settings collection creation
     * @throws DatabaseException When the settings write fails
     * @throws DuplicateIdException When the created setting id already exists in the collection
     * @throws DuplicateValueException When a setting row for the key already exists
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     * @throws LogicException When the settings object collection entity class is not configured
     * @throws SettingInvalidValueException When the value is null
     * @throws SettingKeyInCatalogException When the key is in the catalog (would be an override)
     * @throws SettingTypeMismatchException When the type is not a supported setting type
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the settings collection has an unsupported lazy strategy
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: a positional argument the operator may omit; the usage hint below rejects it
        $key = $args[0] ?? '';
        // external-boundary: a positional argument the operator may omit; the usage hint below rejects it
        $type = $args[1] ?? '';
        if ($key === '' || $type === '') {
            echo "Usage: {$this->getName()} <key> <type> [value]  (type: string|integer|float|boolean)\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        if (!self::isSupportedType($type)) {
            echo "Invalid type '{$type}'. Expected one of: string, integer, float, boolean.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $value = null;
        if (array_key_exists(2, $args)) {
            if (!self::valueMatchesType($type, $args[2])) {
                echo "Value '{$args[2]}' is not a valid {$type}.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $value = self::convertValue($type, $args[2]);
        }

        Hilos::$db->settings->actions->addOrphan($key, $type, $value, Hilos::$setting->catalog());
        echo "Orphan setting created for key '{$key}' (type {$type}).\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Whether a type string is one of the four supported setting types.
     *
     * @param string $type Type string to check
     * @return bool True when supported
     */
    private static function isSupportedType(string $type): bool
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_STRING,
            SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::TYPE_FLOAT,
            SettingsCatalogConstants::TYPE_BOOLEAN => true,
            default => false,
        };
    }

    /**
     * Whether a raw CLI value parses as the given type, by the same rules SettingValue reads with.
     *
     * @param string $type Supported setting type
     * @param string $raw Raw value argument
     * @return bool True when the value is valid for the type
     */
    private static function valueMatchesType(string $type, string $raw): bool
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => preg_match('/^-?\d+$/', trim($raw)) === 1,
            SettingsCatalogConstants::TYPE_FLOAT => is_numeric(trim($raw)),
            SettingsCatalogConstants::TYPE_BOOLEAN => in_array(strtolower(trim($raw)), ['1', 'true', '0', 'false'], true),
            default => true,
        };
    }

    /**
     * Converts a validated raw CLI value to its typed PHP value.
     *
     * @param string $type Supported setting type (value already passed valueMatchesType)
     * @param string $raw Raw value argument
     * @return string|int|float|bool Typed value
     */
    private static function convertValue(string $type, string $raw): string|int|float|bool
    {
        return match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => (int)trim($raw),
            SettingsCatalogConstants::TYPE_FLOAT => (float)trim($raw),
            SettingsCatalogConstants::TYPE_BOOLEAN => in_array(strtolower(trim($raw)), ['1', 'true'], true),
            default => $raw,
        };
    }
}
