<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\SettingsResetCommand;
use Hilos\Core\CLI\Commands\SettingsSetCommand;
use Hilos\Core\CLI\Commands\SettingsTestWriteCommand;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;

/**
 * SettingsCommandConstants - the wire vocabulary of settings CLI commands (HIL-1514).
 *
 * The CLI commands ({@see SettingsSetCommand} and {@see SettingsResetCommand}) build the request
 * and print the reply, and the settings library ({@see SettingsLibraryAgent}) reads the request
 * and writes the row, so both sides name the keys from here.
 *
 * The test-only batch ({@see SettingsTestWriteCommand}, HIL-1856) carries a list of the same
 * key and value pairs under {@see self::FIELD_WRITES}, and its reply hands each pair back with the
 * moment the library accepted it.
 */
final class SettingsCommandConstants
{
    /** @var string Request key: setting key */
    public const string FIELD_KEY = 'key';

    /** @var string Request key: setting value */
    public const string FIELD_VALUE = 'value';

    /** @var string Request and reply key: the list of key and value pairs of a test settings write */
    public const string FIELD_WRITES = 'writes';

    /** @var string Reply key: epoch milliseconds at which the library accepted one pair of a test settings write */
    public const string FIELD_ACCEPTED_AT = 'acceptedAt';
}
