<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Settings;

use Hilos\Database\Settings\SettingsAccessor;

/**
 * Chat demo settings accessor bound to the project settings catalog.
 */
final class ChatSettingsAccessor extends SettingsAccessor
{
    /**
     * Returns the chat demo settings catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by setting key
     */
    protected function getCatalog(): array
    {
        return SettingsCatalog::getCatalog();
    }
}
