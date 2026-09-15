<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\Settings\SettingsAccessor;
use Throwable;

/**
 * Settings accessor whose stored values are scripted by the test.
 *
 * Carries the real log catalog, so the keys exist and their defaults are the environment ones;
 * only the persisted layer is replaced. A value that is a Throwable is thrown instead of returned,
 * which is how a failing database read is staged.
 */
final class LogSettingsResolverTestAccessor extends SettingsAccessor
{
    /** @var array<string, mixed> Persisted values by key; a Throwable is thrown on read */
    public static array $values = [];

    /**
     * Returns the scripted value for a key, or the catalog default when none is scripted.
     *
     * @param string $key Setting key
     * @return mixed Scripted persisted value, or the resolved catalog default
     * @throws Throwable When the scripted value is a throwable staging a failing read
     */
    public function effectiveValueFor(string $key): mixed
    {
        $value = self::$values[$key] ?? null;
        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value ?? parent::effectiveValueFor($key);
    }
}
