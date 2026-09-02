<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Preset;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * Declares the preset group of one admin section (HIL-762).
 *
 * Static, like {@see CatalogProviderInterface} beside it and for the same reason: a group is a
 * declaration of the installation rather than an object anybody owns, and the page that serves it
 * names the provider class instead of holding an instance of it.
 */
interface SettingPresetGroupProviderInterface
{
    /**
     * Returns the preset group this provider declares.
     *
     * @return SettingPresetGroup Group with its selection key and its presets in card order
     */
    public static function presetGroup(): SettingPresetGroup;
}
