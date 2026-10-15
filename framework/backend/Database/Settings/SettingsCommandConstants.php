<?php

declare(strict_types=1);

namespace Hilos\Database\Settings;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\SettingsResetCommand;
use Hilos\Core\CLI\Commands\SettingsSetCommand;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;

/**
 * SettingsCommandConstants - the wire vocabulary of settings CLI commands (HIL-1514).
 *
 * The CLI commands ({@see SettingsSetCommand} and {@see SettingsResetCommand}) build the request
 * and print the reply, and the settings library ({@see SettingsLibraryAgent}) reads the request
 * and writes the row, so both sides name the keys from here.
 */
final class SettingsCommandConstants
{
    /** @var string Request key: setting key */
    public const string FIELD_KEY = 'key';

    /** @var string Request key: setting value */
    public const string FIELD_VALUE = 'value';
}
