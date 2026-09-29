<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp\Fixtures;

use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\Settings\SettingsAccessor;

/**
 * Step-up settings accessor with scripted persisted disabled and enabled lists.
 */
final class StepUpTestSettings extends SettingsAccessor
{
    public static ?string $disabled = null;

    public static ?string $enabled = null;

    public function __construct()
    {
        parent::__construct(StepUpSettingsCatalog::class);
    }

    /**
     * @param string $key Setting key
     * @return mixed Scripted value or catalog default
     * @throws DatabaseException When the default cannot be read
     * @throws SettingException When the key or value is invalid
     */
    public function effectiveValueFor(string $key): mixed
    {
        if ($key === StepUpSettings::DISABLED_KEY && self::$disabled !== null) {
            return self::$disabled;
        }
        if ($key === StepUpSettings::ENABLED_KEY && self::$enabled !== null) {
            return self::$enabled;
        }

        return parent::effectiveValueFor($key);
    }
}
