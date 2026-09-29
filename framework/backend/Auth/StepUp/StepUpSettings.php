<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;

/**
 * The settings that move declared operations away from their declared position (HIL-495, HIL-1275).
 *
 * Each operation is declared on or off by default ({@see StepUpOperation::$enabledByDefault}),
 * and each position has its own list of departures from it: the operations an administrator
 * switched off among those declared on, and the operations switched on among those declared
 * off. One list of switched-off operations cannot say both: a setting's value is its stored row
 * when there is one and the catalog default otherwise ({@see SettingsAccessor::effectiveValueFor()}),
 * and the row appears with the first switch. An operation declared off and added to the directory
 * after that switch would be missing from the stored list and so read as on.
 */
final class StepUpSettings
{
    /** Setting key holding the operations an administrator switched off among those declared on. */
    public const string DISABLED_KEY = 'auth.step_up.disabled';

    /** Setting key holding the operations an administrator switched on among those declared off (HIL-1275). */
    public const string ENABLED_KEY = 'auth.step_up.enabled';

    /** Separator between operation keys in the stored value. */
    private const string SEPARATOR = ',';

    /**
     * @param string $value Stored comma-separated operation keys
     * @return list<string> Distinct non-empty keys in stored order
     */
    public static function parse(string $value): array
    {
        $keys = [];
        foreach (explode(self::SEPARATOR, $value) as $part) {
            $key = trim($part);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys Operation keys to store
     * @return string Comma-separated operation keys
     */
    public static function format(array $keys): string
    {
        return implode(self::SEPARATOR, $keys);
    }

    /**
     * @return list<string> Stored switched-off operation keys
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function disabledKeys(): array
    {
        return self::storedKeys(self::DISABLED_KEY);
    }

    /**
     * @return list<string> Stored switched-on operation keys among those declared off
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function enabledKeys(): array
    {
        return self::storedKeys(self::ENABLED_KEY);
    }

    /**
     * @param string $key Declared operation key
     * @return bool Whether the operation is protected
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     * @throws InvalidArgumentException When a declared operation key is malformed
     */
    public static function isEnabled(string $key): bool
    {
        $directory = Hilos::stepUpOperationDirectoryClass();
        if (!$directory::has($key)) {
            return false;
        }

        return $directory::get($key)->enabledByDefault
            ? !in_array($key, self::disabledKeys(), true)
            : in_array($key, self::enabledKeys(), true);
    }

    /**
     * The list a switch of this operation writes: the departures from its declared position.
     *
     * @param string $operationKey Declared operation key
     * @return string {@see self::DISABLED_KEY} for an operation declared on, {@see self::ENABLED_KEY} for one declared off
     * @throws InvalidArgumentException When the project declares no such operation
     */
    public static function listKeyFor(string $operationKey): string
    {
        return Hilos::stepUpOperationDirectoryClass()::get($operationKey)->enabledByDefault
            ? self::DISABLED_KEY
            : self::ENABLED_KEY;
    }

    /**
     * @param string $settingKey One of the two list setting keys
     * @return list<string> Stored operation keys of that list
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    private static function storedKeys(string $settingKey): array
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[$settingKey])) {
            return [];
        }

        return self::parse(Hilos::$setting[$settingKey]->string());
    }
}
