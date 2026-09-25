<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * The setting that narrows the protected-operation directory (HIL-495).
 */
final class StepUpSettings
{
    /** Setting key holding the operations an administrator switched off. */
    public const string DISABLED_KEY = 'auth.step_up.disabled';

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
        if (Hilos::$setting === null || !isset(Hilos::$setting[self::DISABLED_KEY])) {
            return [];
        }

        return self::parse(Hilos::$setting[self::DISABLED_KEY]->string());
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
        return Hilos::stepUpOperationDirectoryClass()::has($key)
            && !in_array($key, self::disabledKeys(), true);
    }
}
