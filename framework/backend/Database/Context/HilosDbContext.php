<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Item\SettingActions;

/**
 * HilosDbContext - Framework database context with Hilos-level collections.
 *
 * Extends DbContext to add settings collection.
 * Projects extend this class and add their own collections in configure().
 *
 * @property-read DbCollectionSettings $settings
 */
abstract class HilosDbContext extends DbContext
{
    public const string settings = 'settings';
    public const string setting = 'setting';

    /**
     * Configures Hilos-level collections (settings).
     *
     * @throws ObjectCollectionNotFoundException When the settings object collection is missing
     */
    public function configure(): void
    {
        $this->_objectCollections[self::settings] = ObjectSettings::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->setRepresent(self::settings, DbCollectionSettings::class, SettingsActions::class, SettingActions::class);
    }
}
