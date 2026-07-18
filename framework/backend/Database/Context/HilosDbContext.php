<?php

declare(strict_types=1);

namespace Hilos\Database\Context;

use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\Identities as DbCollectionIdentities;
use Hilos\Database\View\Collection\Settings as DbCollectionSettings;
use Hilos\Database\Actions\Collection\SettingsActions;
use Hilos\Database\Actions\Item\SettingActions;

/**
 * HilosDbContext - Framework database context with Hilos-level collections.
 *
 * Extends DbContext to add framework-owned collections (settings, identities).
 * Projects extend this class and add their own collections in configure();
 * calling parent::configure() gives them the framework-owned collections.
 *
 * @property-read DbCollectionSettings $settings
 * @property-read DbCollectionIdentities $identities
 */
abstract class HilosDbContext extends DbContext
{
    public const string settings = 'settings';
    public const string setting = 'setting';
    public const string identities = 'identities';
    public const string identity = 'identity';

    /**
     * Configures Hilos-level collections (settings, identities).
     *
     * Identities load by key (per-user / per-(type,identifier) lookups), never
     * as a full set, so registering the collection stays inert for projects
     * that do not activate the hilos_identity table.
     *
     * @throws ObjectCollectionNotFoundException When a framework object collection is missing
     */
    public function configure(): void
    {
        $this->_objectCollections[self::settings] = ObjectSettings::initDB(Objects::LAZY_STRATEGY_NONE);
        $this->setRepresent(self::settings, DbCollectionSettings::class, SettingsActions::class, SettingActions::class);

        $this->_objectCollections[self::identities] = ObjectIdentities::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::identities, DbCollectionIdentities::class);
    }
}
