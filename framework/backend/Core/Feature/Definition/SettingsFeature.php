<?php

declare(strict_types=1);

namespace Hilos\Core\Feature\Definition;

use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Pages\AbstractHilosSettingsPage;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * Admin settings page over the framework settings table.
 *
 * The catalog is required because the page renders exactly what the project's settings
 * catalog declares: with the framework stub still in place the feature is on and the page
 * is empty, which is the half-activated state this registry exists to reject.
 */
final class SettingsFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Settings feature case
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::SETTINGS;
    }

    /**
     * @return FeatureRequirements Settings page, its table binding, the settings catalog and the settings table
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [AbstractHilosSettingsPage::class],
            requiredTables: [HilosSettingsTable::class],
            requiredPageTables: [AbstractHilosSettingsPage::class => HilosSettingsTable::class],
            requiredCatalogConstant: 'SETTINGS_CATALOG',
            requiredDbTables: [Setting::_table],
        );
    }
}
