<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsAccessor;

/**
 * Settings over the framework's method-set fragment, with the persisted layer scripted.
 *
 * Carries the real catalog entry, so the key exists and its rule is the real one; only
 * what a stored row would say is replaced.
 */
final class AuthMethodTestSettings extends SettingsAccessor
{
    /** Stored switched-off list, or null for no stored row. */
    public static ?string $disabled = null;

    public function __construct()
    {
        parent::__construct(AuthMethodSettingsCatalog::class);
    }

    /**
     * Returns the scripted stored list for the method key, or the catalog default otherwise.
     *
     * @param string $key Setting key
     * @return mixed Scripted stored value, or the resolved catalog default
     * @throws DatabaseException When the default's persisted lookup fails
     * @throws SettingException When the key or its default is invalid
     */
    public function effectiveValueFor(string $key): mixed
    {
        if ($key === AuthMethodSettings::DISABLED_KEY && self::$disabled !== null) {
            return self::$disabled;
        }

        return parent::effectiveValueFor($key);
    }
}
